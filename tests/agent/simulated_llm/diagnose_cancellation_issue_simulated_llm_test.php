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
 * Simulated-LLM conversation tests for booking.diagnose_cancellation_issue.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_simulated_llm_testcase.php');

/**
 * Deterministic diagnose-cancellation tests with scripted LLM output.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class diagnose_cancellation_issue_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated read-only loop for diagnose_cancellation_issue.
     */
    public function test_simulated_diagnose_cancellation_issue_loop_auto_executes(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Sim Diagnose Cancel ' . uniqid('', true), ['maxanswers' => 5]);

        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids' => [(int)$this->teacher->id],
        ]);

        $responses = [
            self::task_call_response(
                'booking.diagnose_cancellation_issue',
                [
                    'optionid' => (int)$option->id,
                    'userid' => (int)$this->teacher->id,
                ],
                'Diagnosing cancellation issue.'
            ),
            self::clarification_response('I diagnosed the cancellation issue.'),
        ];

        [$store, $runtime, $threadid] = $this->build_scripted_runtime($responses);
        $result = $this->chat('Diagnose cancellation issue request', $threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertNotEmpty($result['results'] ?? []);

        $taskresult = $this->extract_task_result($result, 'booking.diagnose_cancellation_issue');
        $this->assertNotNull($taskresult);
        $this->assertContains((string)($taskresult['status'] ?? ''), ['executed', 'error']);
    }
}
