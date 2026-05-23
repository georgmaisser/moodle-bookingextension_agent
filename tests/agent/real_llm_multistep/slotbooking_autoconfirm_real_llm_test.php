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
 * Real-LLM multistep slotbooking autoconfirm test.
 *
 * Uses the same interaction style as live JS:
 * - ai_send_message starts the flow
 * - when autoconfirm=1 (or confirmation-like response appears), ai_confirm_run is called
 * - loop is guarded; if we detect a repeated response cycle, the test is skipped
 *   and prints the full trace for debugging.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\external\ai_send_message;

/**
 * Live autoconfirm test for slotbooking creation.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class slotbooking_autoconfirm_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();

        // This test drives the webservice path directly (ai_send_message / ai_confirm_run)
        // and therefore should not rely on build_runtime()-based debug enforcement in tearDown.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Real flow: create slotbooking with weekday hourly slots for July.
     */
    public function test_slotbooking_autoconfirm_flow_with_loop_guard(): void {
        global $DB;

        $admin = get_admin();
        $this->setUser($admin);

        $beforeoptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id');
        $beforeids = array_map('intval', array_keys($beforeoptions));

        $prompt = 'i want to create a slotbooking: My tennis court should be bookable every weekday '
            . 'from 10 a.m. to 6 p.m., in 1-hour slots. Create the booking availability for July.';

        $trace = [];
        $seensignatures = [];
        $maxsteps = 5;
        $completed = false;

        // Keep real-LLM thread tracking consistent with other tests so tearDown
        // can validate generate_text debug entries.
        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$admin->id, (int)$this->booking->cmid, $threadid);

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$this->booking->cmid, $prompt, (int)$threadid);
        $threadid = (int)($response['threadid'] ?? $threadid);
        $this->assertGreaterThan(0, $threadid, 'Thread id must be present for slotbooking autoconfirm flow.');
        $trace[] = $this->build_trace_line('send', 0, $response);

        for ($i = 0; $i < $maxsteps; $i++) {
            $responsetype = (string)($response['response_type'] ?? '');
            $autoconfirm = (int)($response['autoconfirm'] ?? 0);

            if ($responsetype === 'sufficient' || $responsetype === 'execution_result') {
                $completed = true;
                break;
            }

            $signature = $this->build_loop_signature($response);
            $seensignatures[$signature] = (int)($seensignatures[$signature] ?? 0) + 1;
            if ($seensignatures[$signature] >= 3) {
                $this->fail(
                    'Loop detected (same response signature repeated >=3 times). Trace: ' . implode(' | ', $trace)
                );
            }

            if ($autoconfirm === 1) {
                $_POST['sesskey'] = sesskey();
                $confirm = ai_confirm_run::execute(
                    (int)$this->booking->cmid,
                    $threadid,
                    (string)($response['queueitemid'] ?? ''),
                    false
                );
                $trace[] = $this->build_trace_line('confirm', $i + 1, $confirm);

                if (empty($confirm['success'])) {
                    $this->fail('ai_confirm_run failed. Trace: ' . implode(' | ', $trace));
                }

                $response = $confirm;
                continue;
            }

            if (in_array($responsetype, ['confirmation_request', 'task_call', 'confirm_pending'], true)) {
                // Simulate UI "confirm + allow for this session" so follow-up
                // confirmation requests can switch to autoconfirm mode.
                $_POST['sesskey'] = sesskey();
                $confirm = ai_confirm_run::execute(
                    (int)$this->booking->cmid,
                    $threadid,
                    (string)($response['queueitemid'] ?? ''),
                    true
                );
                $trace[] = $this->build_trace_line('confirm_allow_session', $i + 1, $confirm);

                if (empty($confirm['success'])) {
                    $this->fail('ai_confirm_run (allow_session=true) failed. Trace: ' . implode(' | ', $trace));
                }

                $response = $confirm;
                continue;
            }

            if ($responsetype === 'clarification') {
                $_POST['sesskey'] = sesskey();
                $followup = ai_send_message::execute(
                    (int)$this->booking->cmid,
                    'Please proceed with exactly one slotbooking creation confirmation_request for a tennis court: '
                    . 'weekdays only, July availability, 10:00-18:00, 60-minute slots.',
                    $threadid
                );
                $trace[] = $this->build_trace_line('send', $i + 1, $followup);
                $response = $followup;
                continue;
            }

            if ($responsetype === 'error') {
                $this->fail('Flow ended in error. Trace: ' . implode(' | ', $trace));
            }

            $this->fail('Unexpected response_type=' . $responsetype . '. Trace: ' . implode(' | ', $trace));
        }

        if (!$completed) {
            $this->fail('Reached max steps without terminal completion. Trace: ' . implode(' | ', $trace));
        }

        $afteroptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
        $createdoptionids = [];
        foreach ($afteroptions as $option) {
            if (!in_array((int)$option->id, $beforeids, true)) {
                $createdoptionids[] = (int)$option->id;
            }
        }

        $this->assertNotEmpty($createdoptionids, 'Expected at least one newly created option. Trace: ' . implode(' | ', $trace));

        $foundslotconfig = false;
        foreach ($createdoptionids as $optionid) {
            $slotconfig = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', IGNORE_MISSING);
            if (!$slotconfig) {
                continue;
            }

            $foundslotconfig = true;
            $this->assertGreaterThan(0, (int)$slotconfig->slot_duration_minutes, 'slot_duration_minutes must be > 0.');
            $this->assertStringContainsString('1', (string)$slotconfig->days_of_week, 'days_of_week must include Monday.');
            $this->assertStringContainsString('5', (string)$slotconfig->days_of_week, 'days_of_week must include Friday.');
            break;
        }

        $this->assertTrue($foundslotconfig, 'Expected slot config for a newly created option. Trace: ' . implode(' | ', $trace));
    }

    /**
     * Compact trace line for send/confirm steps.
     *
     * @param string $phase
     * @param int $step
     * @param array $payload
     * @return string
     */
    private function build_trace_line(string $phase, int $step, array $payload): string {
        $responsetype = (string)($payload['response_type'] ?? '');
        $autoconfirm = (string)($payload['autoconfirm'] ?? 0);
        $message = trim((string)($payload['displaymessage'] ?? $payload['message'] ?? ''));
        $issuecodes = json_encode((array)($payload['issue_codes'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $phase . '[' . $step . ']'
            . ': type=' . $responsetype
            . ' autoconfirm=' . $autoconfirm
            . ' issue_codes=' . $issuecodes
            . ' msg=' . $message;
    }

    /**
     * Build a stable signature to detect repeating cycles in live responses.
     *
     * @param array $payload
     * @return string
     */
    private function build_loop_signature(array $payload): string {
        $responsetype = trim((string)($payload['response_type'] ?? ''));
        $message = trim((string)($payload['message'] ?? ''));
        $pendingcode = trim((string)($payload['pendingconfirmationcode'] ?? ''));
        $issuecodesjson = trim((string)($payload['issuecodesjson'] ?? '[]'));

        return hash(
            'sha256',
            $responsetype . '|' . $message . '|' . $pendingcode . '|' . $issuecodesjson
        );
    }
}
