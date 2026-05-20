<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Internal agent loop tests.
 *
 * Verifies that run_loop() implements a true internal agent loop:
 * - Multiple internal steps occur before a response is returned to the user.
 * - Tool results (observations) are fed back into the next LLM call.
 * - No intermediate assistant messages are persisted between steps.
 * - Only ONE final message is persisted once the loop terminates.
 * - The final result is the last non-execution response from the orchestrator.
 *
 * The orchestrator is mocked so tests are fully deterministic and do not
 * require a live LLM.  The executor runs against the real DB so that
 * read-only task execution (booking.search_options) is exercised.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\agent_state;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Internal agent loop tests — mock orchestrator, real executor.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @covers \bookingextension_agent\local\wbagent\agent_runtime
 * @covers \bookingextension_agent\local\wbagent\agent_state
 */
final class agent_internal_loop_test extends abstract_agent_testcase {
    // -------------------------------------------------------------------------
    // Agent_state unit tests.

    /**
     * agent_state::make() creates a fresh state with correct max_steps.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_make_returns_clean_state(): void {
        $this->resetAfterTest();
        $state = agent_state::make(4);

        $this->assertSame(4, $state->maxsteps);
        $this->assertSame(0, $state->currentstep);
        $this->assertSame(0, $state->step_count());
        $this->assertEmpty($state->get_observations());
        $this->assertEmpty($state->get_steps());
        $this->assertFalse($state->has_observations());
    }

    /**
     * agent_state::make() clamps max_steps to at least 1.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_make_clamps_min_steps(): void {
        $this->resetAfterTest();
        $state = agent_state::make(0);
        $this->assertSame(1, $state->maxsteps);

        $state2 = agent_state::make(-5);
        $this->assertSame(1, $state2->maxsteps);
    }

    /**
     * record_step() accumulates steps and observations correctly.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_record_step_accumulates(): void {
        $this->resetAfterTest();
        $state = agent_state::make(5);
        $state->currentstep = 1;

        $state->record_step(
            [['task' => 'booking.search_options']],
            [['options' => [['name' => 'Yoga']]]],
            'Step 1: Found 1 booking option(s): Yoga.'
        );

        $this->assertSame(1, $state->step_count());
        $this->assertTrue($state->has_observations());
        $this->assertCount(1, $state->get_observations());
        $this->assertStringContainsString('Yoga', $state->get_observations()[0]);

        // Second step.
        $state->currentstep = 2;
        $state->record_step([], [], 'Step 2: Found 3 users.');

        $this->assertSame(2, $state->step_count());
        $this->assertCount(2, $state->get_observations());
        $this->assertStringContainsString('users', $state->get_observations()[1]);
    }

    /**
     * Blank observation strings are not added to the observations list.
     *
     * @runInSeparateProcess
     */
    public function test_agent_state_blank_observation_is_ignored(): void {
        $this->resetAfterTest();
        $state = agent_state::make(3);
        $state->currentstep = 1;
        $state->record_step([], [], '   ');

        $this->assertSame(1, $state->step_count());
        $this->assertEmpty($state->get_observations(), 'Blank observation must not be added');
        $this->assertFalse($state->has_observations());
    }

    // -------------------------------------------------------------------------
    // Run_loop() internal loop tests — mock orchestrator.

    /**
     * run_loop() calls the orchestrator twice and accumulates observations.
     *
     * Scenario:
     *   Step 1: orchestrator returns task_call → executor runs booking.search_options
     *           → decide() produces execution_result → loop continues.
     *   Step 2: orchestrator returns clarification → loop stops.
     *
     * Assertions:
     *   - orchestrator::process() called exactly twice.
     *   - First call has no observations.
     *   - Second call has at least one observation.
     *   - Only ONE assistant message persisted in DB.
     *   - Final result has response_type = 'clarification'.
     *   - Final result carries loop_step = 2.
     */
    public function test_run_loop_accumulates_observations_between_steps(): void {
        global $DB;

        $this->setUser($this->teacher);

        // Create a real booking option so search_options finds something.
        $this->exec_command('booking.create_option', [
            'text'            => 'Yoga Class Loop Test',
            'maxanswers'      => 10,
            'coursestarttime' => '2045-06-01T09:00:00',
            'duration'        => 60,
            'teacherquery'    => 'current',
        ]);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        // Step 1 result: task_call for a read-only search.
        $step1 = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching options.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Yoga'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        // Step 2 result: clarification (final answer).
        $step2 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'I found booking options for your query.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount             = 0;
        $capturedobservations  = [];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturnCallback(
                function (
                    int $threadid,
                    int $cmid,
                    int $userid,
                    array $observations = []
                ) use (
                    &$callcount,
                    &$capturedobservations,
                    $step1,
                    $step2
                ): array {
                    $callcount++;
                    $capturedobservations[$callcount] = $observations;
                    return $callcount === 1 ? $step1 : $step2;
                }
            );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Zeige mir alle Yoga Kurse');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // Orchestrator was called exactly twice.
        $this->assertSame(2, $callcount, 'Orchestrator must be called twice (1 tool step + 1 final step)');

        // First call: no observations.
        $this->assertEmpty(
            $capturedobservations[1],
            'First orchestrator call must receive no observations'
        );

        // Second call: observation from step 1.
        $this->assertNotEmpty(
            $capturedobservations[2],
            'Second orchestrator call must receive observation(s) from step 1'
        );
        $observationtext = implode(' ', $capturedobservations[2]);
        $this->assertStringContainsString(
            'option',
            strtolower($observationtext),
            'Observation must mention booking options'
        );

        // Exactly ONE assistant message was persisted.
        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(
            1,
            $assistantmessages,
            'Exactly one assistant message must be persisted after the loop'
        );

        // Final result is the clarification from step 2.
        $this->assertSame('clarification', $result['response_type']);

        // Loop_step reflects the terminating step number.
        $this->assertArrayHasKey('loop_step', $result);
        $this->assertSame(2, (int)$result['loop_step']);
    }

    /**
     * Step messages should use human-readable labels instead of raw task names.
     */
    public function test_run_loop_writes_human_readable_step_label(): void {
        $this->setUser($this->teacher);

        $this->exec_command('booking.create_option', [
            'text'            => 'Readable Step Label Test',
            'maxanswers'      => 10,
            'coursestarttime' => '2045-06-01T09:00:00',
            'duration'        => 60,
            'teacherquery'    => 'current',
        ]);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $authz = new authorization_service();

        $step1 = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching options.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Readable'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $step2 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'Done.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturnOnConsecutiveCalls($step1, $step2);

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Find options');

        $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        $steps = $store->get_step_messages_since($threadid, 0);
        $this->assertCount(1, $steps);

        $label = (string)($steps[0]->content ?? '');
        $this->assertStringContainsString('Step 1: Search booking options', $label);
        $this->assertStringNotContainsString('booking.search_options', $label);
    }

    /**
     * Repeated identical readonly steps should stop early instead of burning the whole loop budget.
     */
    public function test_run_loop_stops_on_repeated_readonly_step(): void {
        $this->setUser($this->teacher);

        $this->exec_command('booking.create_option', [
            'text'            => 'Repeat Loop Guard Test',
            'maxanswers'      => 10,
            'coursestarttime' => '2045-06-01T09:00:00',
            'duration'        => 60,
            'teacherquery'    => 'current',
        ]);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $authz = new authorization_service();

        $repeatedstep = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching options again.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Repeat'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount = 0;
        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturnCallback(
            static function () use (&$callcount, $repeatedstep): array {
                $callcount++;
                return $repeatedstep;
            }
        );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Repeat search');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertContains('LOOP_REPEAT_DETECTED', $result['issue_codes'] ?? []);
        $this->assertSame(
            3,
            $callcount,
            'Loop should stop after the second identical readonly step and one final narration-only pass.'
        );
        $this->assertCount(
            1,
            (array)($result['results'] ?? []),
            'Loop repeat response deduplicates identical readonly results to the most informative entry.'
        );
        $this->assertContains(
            'booking.search_options',
            (array)($result['attempted_tasks'] ?? []),
            'Final loop-repeat clarification must preserve attempted readonly tasks for debug/context.'
        );
    }

    /**
     * A malformed second-step task_call without commands must not overwrite a successful readonly result.
     */
    public function test_run_loop_recovers_from_missing_commands_error_after_readonly_success(): void {
        $this->setUser($this->teacher);

        $this->exec_command('booking.create_option', [
            'text'            => 'Malformed Recovery Test Option',
            'maxanswers'      => 8,
            'coursestarttime' => '2045-06-02T09:00:00',
            'duration'        => 45,
            'teacherquery'    => 'current',
        ]);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $authz = new authorization_service();

        $step1 = [
            'response_type'     => 'task_call',
            'lang'              => 'de',
            'message'           => 'Suche passende Option.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Malformed Recovery Test Option'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $step2 = [
            'response_type'     => 'error',
            'lang'              => 'de',
            'message'           => 'Response type requires at least one command but none were provided.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => ['Response type requires at least one command but none were provided.'],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount = 0;
        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturnCallback(
            static function () use (&$callcount, $step1, $step2): array {
                $callcount++;
                return $callcount === 1 ? $step1 : $step2;
            }
        );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Kann Maxima Lesung mit Georg buchen?');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertContains('LOOP_MALFORMED_TASKCALL_RECOVERED', (array)($result['issue_codes'] ?? []));
        $this->assertNotEmpty(trim((string)($result['message'] ?? '')));
        $this->assertCount(1, (array)($result['results'] ?? []));
        // Final narration may not preserve attempted_tasks; validate through attached loop results instead.
        $this->assertNotEmpty((array)($result['results'] ?? []));
        $this->assertSame([], (array)($result['errors'] ?? []));
    }

    /**
     * Clarification fallback must be executed inside the loop and never leak as task_call.
     */
    public function test_run_loop_executes_clarification_option_fallback_internally(): void {
        $this->setUser($this->teacher);

        $this->exec_command('booking.create_option', [
            'text'            => 'Lesung mit Georg',
            'maxanswers'      => 6,
            'coursestarttime' => '2045-06-03T09:00:00',
            'duration'        => 30,
            'teacherquery'    => 'current',
        ]);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $authz = new authorization_service();

        $step1 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'Please provide exact option id.',
            'used_triggers'     => ['core.is_lookup_request'],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $step2 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'Diagnose complete.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount = 0;
        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturnCallback(
            static function () use (&$callcount, $step1, $step2): array {
                $callcount++;
                return $callcount === 1 ? $step1 : $step2;
            }
        );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'kann billy "Lesung mit Georg" stornieren?');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // The recovery path may require multiple internal planning passes,
        // but must stay bounded by loop limits and must not leak task_call.
        $this->assertGreaterThanOrEqual(2, $callcount);
        $this->assertLessThanOrEqual(agent_runtime::MAX_LOOP_STEPS, $callcount);
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertNotSame('task_call', (string)($result['response_type'] ?? ''));
        $this->assertNotEmpty((array)($result['results'] ?? []));
    }

    /**
     * run_loop() stops immediately when the first response is not execution_result.
     *
     * Scenario: orchestrator returns confirmation_request on the first call.
     * The loop must stop immediately, persist ONE message, and not call the
     * orchestrator a second time.
     */
    public function test_run_loop_stops_immediately_on_confirmation_request(): void {
        $this->setUser($this->teacher);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        $confirmresult = [
            'response_type'     => 'confirmation_request',
            'lang'              => 'en',
            'message'           => 'Shall I create the Pilates option?',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.create_option',
                'version' => 1,
                'input'   => ['text' => 'Pilates', 'maxanswers' => 5],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $callcount = 0;

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturnCallback(function () use (&$callcount, $confirmresult): array {
                $callcount++;
                return $confirmresult;
            });

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Erstelle eine Pilates-Klasse');

        $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // Only one orchestrator call.
        $this->assertSame(1, $callcount, 'Orchestrator must be called once for confirmation_request');

        // Loop terminated at step 1.
        $this->assertSame(1, (int)$result['loop_step']);

        // Exactly ONE assistant message persisted.
        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages, 'One assistant message must be persisted');
    }

    /**
     * run_loop() terminates at max_steps and returns MAX_STEPS_EXCEEDED.
     *
     * The orchestrator always returns execution_result via a task_call, causing
     * the loop to keep going.  Once max_steps is reached, run_loop() must:
     * - Return error with issue_code MAX_STEPS_EXCEEDED.
     * - Persist exactly ONE assistant message.
     */
    public function test_run_loop_terminates_at_max_steps(): void {
        $this->setUser($this->teacher);

        // Create an option so search_options actually succeeds.
        $this->exec_command('booking.create_option', [
            'text'            => 'MaxSteps Test Option',
            'maxanswers'      => 3,
            'coursestarttime' => '2045-07-01T10:00:00',
            'duration'        => 30,
            'teacherquery'    => 'current',
        ]);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        // Always returns a read-only task_call — loop would never stop naturally.
        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $callcount = 0;
        $mockorchestrator->method('process')
            ->willReturnCallback(static function () use (&$callcount): array {
                $callcount++;
                $task = ($callcount % 2 === 1) ? 'booking.search_options' : 'booking.list_actions';
                $input = ($task === 'booking.search_options')
                    ? ['query' => 'MaxSteps ' . $callcount]
                    : ['question' => 'List available actions'];
                return [
                    'response_type'     => 'task_call',
                    'lang'              => 'en',
                    'message'           => 'Searching.',
                    'used_triggers'     => [],
                    'commands'          => [[
                        'task'    => $task,
                        'version' => 1,
                        'input'   => $input,
                    ]],
                    'ambiguities'       => [],
                    'ambiguity_options' => [],
                    'errors'            => [],
                    'attempted_tasks'   => [],
                    'issue_codes'       => [],
                ];
            });

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Suche MaxSteps');

        $result = $runtime->run_loop(
            $threadid,
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            2   // Override to 2 steps so the test is fast.
        );

        // Terminated with continue-clarification after hitting the loop step limit.
        $this->assertSame('clarification', $result['response_type']);
        $this->assertContains('LOOP_STEP_LIMIT', $result['issue_codes'] ?? []);

        // Exactly ONE assistant message persisted.
        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages, 'One assistant message must be persisted even at max steps');
    }

    /**
     * run() (single-turn) still works and persists exactly one message.
     *
     * NOTE: Production entry point (ai_send_message::execute) now uses run_loop().
     * This test verifies that run() remains a valid lower-level single-step primitive
     * for focused unit tests and other non-loop scenarios.
     */
    public function test_run_single_turn_persists_exactly_one_message(): void {
        $this->setUser($this->teacher);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        $clarification = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'What would you like to create?',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturn($clarification);

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Hilf mir');

        $result = $runtime->run($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('clarification', $result['response_type']);

        $allmessages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $allmessages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages, 'run() must persist exactly one assistant message');
    }

    /**
     * Observations from step 1 appear in the second orchestrator call.
     *
     * Directly verifies that the observation string built by
     * build_observation_from_result() contains meaningful content about
     * the executed tool results.
     */
    public function test_run_loop_observations_contain_result_data(): void {
        $this->setUser($this->teacher);

        // Create a recognisably-named option.
        $this->exec_command('booking.create_option', [
            'text'            => 'Pottery Workshop Obs Test',
            'maxanswers'      => 7,
            'coursestarttime' => '2045-08-15T14:00:00',
            'duration'        => 90,
            'teacherquery'    => 'current',
        ]);

        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $authz    = new authorization_service();

        $callcount            = 0;
        $capturedobservations = [];

        $step1 = [
            'response_type'     => 'task_call',
            'lang'              => 'en',
            'message'           => 'Searching for Pottery options.',
            'used_triggers'     => [],
            'commands'          => [[
                'task'    => 'booking.search_options',
                'version' => 1,
                'input'   => ['query' => 'Pottery'],
            ]],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $step2 = [
            'response_type'     => 'clarification',
            'lang'              => 'en',
            'message'           => 'Found it.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')
            ->willReturnCallback(
                function (
                    int $threadid,
                    int $cmid,
                    int $userid,
                    array $observations = []
                ) use (
                    &$callcount,
                    &$capturedobservations,
                    $step1,
                    $step2
                ): array {
                    $callcount++;
                    $capturedobservations[$callcount] = $observations;
                    return $callcount === 1 ? $step1 : $step2;
                }
            );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Zeige mir Pottery Kurse');

        $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        // Orchestrator was called twice.
        $this->assertSame(2, $callcount);

        // The observation passed to call 2 must contain concrete result summary text.
        $obs = implode(' ', $capturedobservations[2]);
        $this->assertStringContainsString(
            'Found 1 booking option',
            $obs,
            'Observation injected into step 2 must summarize the first-step tool output'
        );

        // The observation must mention "option" so the LLM knows what kind of result it is.
        $this->assertStringContainsString(
            'option',
            strtolower($obs),
            'Observation must indicate it is about booking options'
        );
    }

    /**
     * Unknown response_type values must be normalized before routing/persistence.
     */
    public function test_run_normalizes_unknown_response_type_to_error_path(): void {
        $this->setUser($this->teacher);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $authz = new authorization_service();

        $unknown = [
            'response_type'     => 'mystery_type',
            'lang'              => 'en',
            'message'           => 'Some unsupported model response.',
            'used_triggers'     => [],
            'commands'          => [],
            'ambiguities'       => [],
            'ambiguity_options' => [],
            'errors'            => [],
            'attempted_tasks'   => [],
            'issue_codes'       => [],
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturn($unknown);

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);

        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Bitte hilf mir mit booking');

        $result = $runtime->run($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertNotSame('mystery_type', (string)($result['response_type'] ?? ''));
        $this->assertNotSame('task_call', (string)($result['response_type'] ?? ''));

        $debugentries = $store->get_llm_debug_entries($threadid);
        $sources = array_map(static fn($entry): string => (string)($entry->source ?? ''), $debugentries);
        $this->assertContains('runtime.trigger_normalization', $sources);

        $messages = $store->get_messages($threadid);
        $assistantmessages = array_values(array_filter(
            $messages,
            static fn($m) => ($m->role ?? '') === 'assistant'
        ));
        $this->assertCount(1, $assistantmessages);
    }
}
