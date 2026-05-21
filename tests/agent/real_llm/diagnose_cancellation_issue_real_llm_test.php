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
 * Real-LLM conversation tests for booking.diagnose_cancellation_issue.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-09  Happy path (loop auto-execute)
 *            — User is booked. diagnose_cancellation_issue is read-only: the loop
 *              auto-executes it and returns a clarification summary.
 *              Structured results surface in result['results'] (loop_results).
 *
 *   CONV-10  Verification loop
 *            — Turn 1: vague question → clarification (no results).
 *            — Turn 2: explicit option id → loop auto-executes diagnose →
 *              clarification with result['results'] containing the diagnosis.
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
 * CONV-09 / CONV-10: booking.diagnose_cancellation_issue real-LLM tests.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class diagnose_cancellation_issue_real_llm_test extends abstract_agent_testcase {
    /**
     * Setup function.
     * @return void
     *
     */
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }
    /**
     * CONV-09: Happy path — booked user, loop auto-executes diagnose, result in results.
     *
     * With run_loop(), diagnose_cancellation_issue is auto-executed inside the loop.
     * The caller receives clarification (LLM summary), not execution_result.
     * The diagnosis payload is surfaced via loop_results in result['results'].
     *
     * Setup:  Creates option, books teacher via executor.
     * Conversation:
     *   User:  "Can I cancel my booking for option id <Y>? Just diagnose."
     *   Agent: clarification (loop auto-executed diagnose_cancellation_issue)
     *   Test:  result['results'] contains diagnose result with reasons.
     */
    public function test_conv09_diagnose_cancellation_happy_path(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Cancel CONV09 ' . uniqid('', true), ['maxanswers' => 5]);

        $targetuser = $this->getDataGenerator()->create_user([
            'firstname' => 'Cancel',
            'lastname' => 'Case' . substr(sha1((string)microtime(true)), 0, 8),
            'email' => 'cancel.case.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($targetuser->id, $this->course->id, 'student');

        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$targetuser->id],
        ]);
        singleton_service::destroy_booking_answers((int)$option->id);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $username = trim(fullname($targetuser));

        $query = 'Diagnose cancellation issue with userid=' . (int)$targetuser->id
            . ' and optionid=' . (int)$option->id
            . '. Investigate only with booking.diagnose_cancellation_issue.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') === 'error') {
            try {
                $result = $this->chat(
                    'Diagnose cancellation issue with userid=' . (int)$targetuser->id
                    . ' and optionid=' . (int)$option->id
                    . ' using booking.diagnose_cancellation_issue only.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (recovery): ' . $e->getMessage());
            }
        }

        // With run_loop(), read-only tasks are auto-executed inside the loop.
        // The caller receives clarification (LLM summary), not execution_result.
        $this->assertSame(
            'clarification',
            $result['response_type'],
            'run_loop() must return clarification after auto-executing diagnose_cancellation_issue; '
                . 'got: ' . ($result['response_type'] ?? '?')
        );

        // The loop must have attached execution results.
        $this->assertNotEmpty(
            $result['results'] ?? [],
            'result[results] must be populated via loop_results after auto-executing diagnose_cancellation_issue'
        );

        $taskresult = $this->extract_task_result($result, 'booking.diagnose_cancellation_issue');
        $this->assertNotNull(
            $taskresult,
            'booking.diagnose_cancellation_issue must appear in result[results] (loop_results)'
        );

        $status = (string)($taskresult['status'] ?? '');
        $this->assertContains($status, ['executed', 'error']);
        if ($status === 'executed') {
            $this->assertNotEmpty(
                (array)($taskresult['diagnosis']['reasons'] ?? []),
                'Diagnosis must contain reasons (at least one cancellation condition evaluated).'
            );
        } else {
            $this->assertNotEmpty(
                trim((string)($taskresult['detail'] ?? '')),
                'Error diagnose result must provide a non-empty detail message.'
            );
        }
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-10: Verification loop — vague question → clarification → explicit ids → diagnosis.
     *
     * Setup:  Creates option, books teacher via executor.
     * Conversation:
     *   Turn 1 — User:  "Why can't the user cancel?"  (no user, no option)
     *            Agent: clarification (no results — no ids given, no tool called)
     *   Turn 2 — User:  "Diagnose cancellation for option id <Y>"
     *            Agent: clarification with result['results'] containing the diagnosis
     *   Test:   diagnosis contains reasons.
     */
    public function test_conv10_diagnose_cancellation_verification_loop(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Cancel CONV10 ' . uniqid('', true), ['maxanswers' => 5]);

        $targetuser = $this->getDataGenerator()->create_user([
            'firstname' => 'Cancel',
            'lastname' => 'Loop' . substr(sha1((string)microtime(true)), 0, 8),
            'email' => 'cancel.loop.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($targetuser->id, $this->course->id, 'student');

        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$targetuser->id],
        ]);
        singleton_service::destroy_booking_answers((int)$option->id);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $username = trim(fullname($targetuser));

        // Turn 1: Vague.
        try {
            $result1 = $this->chat("Why can't the user cancel?", $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);
        $this->assertSame(
            'clarification',
            $result1['response_type'],
            'Turn 1 must return clarification for a vague question; got: ' . ($result1['response_type'] ?? '?')
        );

        $this->assertNull(
            $this->extract_task_result($result1, 'booking.diagnose_cancellation_issue'),
            'Turn 1 must not already contain booking.diagnose_cancellation_issue without an option reference.'
        );

        // Turn 2: Provide option id.
        $reply = 'Diagnose cancellation issue with userid=' . (int)$targetuser->id
            . ' and optionid=' . (int)$option->id
            . '. Investigate only with booking.diagnose_cancellation_issue.';

        try {
            $result2 = $this->chat($reply, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') === 'error') {
            try {
                $result2 = $this->chat(
                    'Diagnose cancellation issue with userid=' . (int)$targetuser->id
                    . ' and optionid=' . (int)$option->id
                    . ' using booking.diagnose_cancellation_issue only.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (turn-2 direct recovery): ' . $e->getMessage());
            }
        }

        // Allow one recovery turn if the LLM still asks for clarification without running the tool.
        if (
            ($result2['response_type'] ?? '') === 'clarification'
            && empty($result2['results'] ?? [])
        ) {
            try {
                $result2 = $this->chat(
                    'Diagnose cancellation issue with userid=' . (int)$targetuser->id
                    . ' and optionid=' . (int)$option->id
                    . '. Use booking.diagnose_cancellation_issue only.',
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

        $taskresult = $this->extract_task_result($result2, 'booking.diagnose_cancellation_issue');
        $this->assertNotNull(
            $taskresult,
            'booking.diagnose_cancellation_issue must appear in result[results] (loop_results)'
        );

        $status = (string)($taskresult['status'] ?? '');
        $this->assertContains(
            $status,
            ['executed', 'error'],
            'Diagnose task must return executed or error. Detail: ' . (string)($taskresult['detail'] ?? '')
        );

        if ($status === 'executed') {
            $this->assertNotEmpty(
                (array)($taskresult['diagnosis']['reasons'] ?? []),
                'Diagnosis must contain reasons (at least one cancellation condition evaluated).'
            );
        } else {
            $this->assertNotEmpty(
                trim((string)($taskresult['detail'] ?? '')),
                'Error diagnose result must provide a non-empty detail message.'
            );
        }
    }
}
