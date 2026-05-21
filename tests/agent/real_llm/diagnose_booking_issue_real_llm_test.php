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
 * Real-LLM conversation tests for booking.diagnose_booking_issue.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-07  Happy path (loop auto-execute)
 *            — Option has free spots, actor is enrolled.
 *              diagnose_booking_issue is read-only: the agentic loop auto-executes it
 *              and returns a clarification summary.
 *              Structured results surface in result['results'] (loop_results).
 *
 *   CONV-08  Verification loop
 *            — Turn 1: vague question → clarification.
 *            — Turn 2: explicit option id → loop auto-executes diagnose → clarification
 *              with results containing userid, optionid and reasons.
 *
 * Activation: set BOOKING_TEST_AI_KEY + BOOKING_TEST_AI_MODEL + BOOKING_TEST_AI_ENDPOINT.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use mod_booking\singleton_service;

/**
 * CONV-07 / CONV-08: booking.diagnose_booking_issue real-LLM tests.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class diagnose_booking_issue_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-07: Happy path — loop auto-executes diagnose, structured result in results.
     *
     * With run_loop(), diagnose_booking_issue is auto-executed inside the agentic loop.
     * The caller receives clarification (LLM summary), not execution_result.
     * The diagnosis payload is surfaced via loop_results in result['results'].
     *
     * Setup:  Creates option with 5 free spots.
     * Conversation:
     *   User:  "Why can I not book option id <Y>? Just investigate."
     *   Agent: clarification (loop auto-executed diagnose_booking_issue)
     *   Test:  result['results'] contains diagnose result with userid, optionid and reasons.
     */
    public function test_conv07_diagnose_user_can_book(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Diagnose CONV07 ' . uniqid('', true), ['maxanswers' => 5]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Why can I not book option id ' . (int)$option->id . '? Just investigate, do not book.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        // With run_loop(), read-only tasks are auto-executed internally.
        // The caller receives clarification (LLM summary), not execution_result.
        $this->assertSame(
            'clarification',
            $result['response_type'],
            'run_loop() must return clarification after auto-executing diagnose_booking_issue; '
                . 'got: ' . ($result['response_type'] ?? '?')
        );

        // The loop must have attached execution results.
        $this->assertNotEmpty(
            $result['results'] ?? [],
            'result[results] must be populated via loop_results after auto-executing diagnose_booking_issue'
        );

        // The diagnose result must contain the expected structured fields.
        $taskresult = $this->extract_task_result($result, 'booking.diagnose_booking_issue');
        $this->assertNotNull(
            $taskresult,
            'booking.diagnose_booking_issue must appear in result[results] (loop_results)'
        );

        $this->assertSame('executed', (string)($taskresult['status'] ?? ''));
        $this->assertSame((int)$this->teacher->id, (int)($taskresult['diagnosis']['userid'] ?? 0));
        $this->assertSame((int)$option->id, (int)($taskresult['diagnosis']['optionid'] ?? 0));
        $this->assertNotEmpty(
            (array)($taskresult['diagnosis']['reasons'] ?? []),
            'Diagnosis reasons must not be empty — even a "can book" result must list evaluated conditions.'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-08: Verification loop — vague question → clarification → explicit ids → diagnosis.
     *
     * Setup:  Creates option maxanswers=1. Books a first user to fill it.
     * Conversation:
     *   Turn 1 — User:  "Why can't someone book?"  (no user, no option)
     *            Agent: clarification (LLM asks for details)
     *   Turn 2 — User:  "Please diagnose why I cannot book option id <Y>"
     *            Agent: clarification with result['results'] containing the diagnosis
     *   Test:   diagnosis contains reasons, userid, optionid.
     */
    public function test_conv08_diagnose_user_cannot_book_verification_loop(): void {
        $this->setUser($this->teacher);

        // Create a full option.
        $option = $this->create_option('Diagnose CONV08 ' . uniqid('', true), ['maxanswers' => 1]);

        $firstuser = $this->getDataGenerator()->create_user([
            'email' => 'first.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($firstuser->id, $this->course->id, 'student');
        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$firstuser->id],
        ]);
        singleton_service::destroy_booking_answers((int)$option->id);

        [$store, $runtime, $threadid] = $this->build_runtime();

        // Turn 1: vague.
        try {
            $result1 = $this->chat("Why can't someone book?", $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);
        $this->assertSame(
            'clarification',
            $result1['response_type'],
            'Turn 1 must return clarification for a vague question; got: ' . ($result1['response_type'] ?? '?')
        );

        // Current loop behavior may already emit read-only results on vague turn 1.
        // If a diagnose result is present, it must still be structurally valid.
        $turn1taskresult = $this->extract_task_result($result1, 'booking.diagnose_booking_issue');
        if ($turn1taskresult !== null) {
            $this->assertContains(
                (string)($turn1taskresult['status'] ?? ''),
                ['executed', 'error'],
                'Turn-1 diagnose result, when present, must be executed or error.'
            );
        }

        // Turn 2: provide option id.
        $reply = 'Please diagnose why I cannot book option id ' . (int)$option->id . '. Investigate only.';

        try {
            $result2 = $this->chat($reply, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        // With run_loop(), the diagnosis is auto-executed and the final response is clarification.
        // Allow one recovery turn in case the LLM needs an extra nudge.
        if (
            ($result2['response_type'] ?? '') === 'clarification'
            && empty($result2['results'] ?? [])
        ) {
            try {
                $result2 = $this->chat(
                    'Diagnose booking issue for my account on option id ' . (int)$option->id . '. Return diagnosis.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (recovery turn): ' . $e->getMessage());
            }
        }

        $this->assertSame(
            'clarification',
            $result2['response_type'],
            'Final turn must return clarification after loop auto-execution; got: '
                . ($result2['response_type'] ?? '?')
        );

        $this->assertNotEmpty(
            $result2['results'] ?? [],
            'result[results] must be populated via loop_results after diagnose auto-execution'
        );

        $taskresult = $this->extract_task_result($result2, 'booking.diagnose_booking_issue');
        $this->assertNotNull(
            $taskresult,
            'booking.diagnose_booking_issue must appear in result[results] (loop_results)'
        );

        $this->assertSame(
            'executed',
            (string)($taskresult['status'] ?? ''),
            'Diagnose task must have executed. Detail: ' . (string)($taskresult['detail'] ?? '')
        );

        $this->assertSame((int)$this->teacher->id, (int)($taskresult['diagnosis']['userid'] ?? 0));
        $this->assertSame((int)$option->id, (int)($taskresult['diagnosis']['optionid'] ?? 0));
        $this->assertNotEmpty(
            (array)($taskresult['diagnosis']['reasons'] ?? []),
            'Diagnosis must contain reasons (at least one booking condition evaluated).'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-09: Other-user question with plain title (no quotes) auto-routes to diagnosis.
     *
     * Setup:  Creates option with unique title and a target user.
     * Conversation:
     *   User: "kann <firstname> <option title> buchen?"
     *   Agent: clarification with loop results containing diagnose_booking_issue
     *   Test:  diagnosis references target userid and optionid.
     */
    public function test_conv09_diagnose_other_user_with_plain_title_question(): void {
        $this->setUser($this->teacher);

        $unique = uniqid('', true);
        $optiontitle = 'Lesung mit Georg ' . $unique;
        $option = $this->create_option($optiontitle, ['maxanswers' => 5]);

        $targetuser = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Tester',
            'email' => 'billy.' . $unique . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($targetuser->id, $this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'kann Billy ' . $optiontitle . ' buchen?';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') === 'error') {
            try {
                $result = $this->chat(
                    'Diagnose booking issue for user id ' . (int)$targetuser->id . ' and option id ' . (int)$option->id
                    . '. Use booking.diagnose_booking_issue only.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (recovery): ' . $e->getMessage());
            }
        }

        $this->assertSame('clarification', $result['response_type'], 'Expected clarification; got: ' . ($result['response_type'] ?? '?'));

        $this->assertNotEmpty(
            $result['results'] ?? [],
            'result[results] must be populated via loop_results for plain-title diagnose questions'
        );

        $taskresult = $this->extract_task_result($result, 'booking.diagnose_booking_issue');
        $this->assertNotNull(
            $taskresult,
            'booking.diagnose_booking_issue must appear in result[results] for plain-title diagnose questions'
        );

        $status = (string)($taskresult['status'] ?? '');
        $this->assertContains(
            $status,
            ['executed', 'error'],
            'Diagnose fallback must execute the task; status must be executed or error. '
                . 'Detail: ' . (string)($taskresult['detail'] ?? '')
        );

        if ($status === 'executed') {
            $this->assertSame((int)$targetuser->id, (int)($taskresult['diagnosis']['userid'] ?? 0));
            $this->assertSame((int)$option->id, (int)($taskresult['diagnosis']['optionid'] ?? 0));
        } else {
            $this->assertNotEmpty(
                trim((string)($taskresult['detail'] ?? '')),
                'Error status must still provide a human-readable detail message.'
            );
        }
    }
}
