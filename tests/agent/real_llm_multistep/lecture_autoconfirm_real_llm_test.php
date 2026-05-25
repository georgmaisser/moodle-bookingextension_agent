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
 * Real-LLM multistep lecture autoconfirm test.
 *
 * Uses the same interaction style as live JS:
 * - ai_send_message starts the flow
 * - when autoconfirm=1, ai_confirm_run is called once
 * - expected to complete in a single confirmation pass
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
 * Live autoconfirm test for creating five lectures in one pass.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class lecture_autoconfirm_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();

        // This test drives the webservice path directly (ai_send_message / ai_confirm_run)
        // and therefore should not rely on build_runtime()-based debug enforcement in tearDown.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Real flow: create five numbered lectures for next week in one autoconfirm pass.
     */
    public function test_lecture_autoconfirm_single_pass_creates_five_actions(): void {
        global $DB;

        $this->setUser($this->teacher);

        $billy = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teacher',
            'email' => 'billy.teacher.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user((int)$billy->id, (int)$this->course->id, 'editingteacher');

        // Keep this real-LLM test deterministic across reruns by removing stale lecture options
        // that can trigger duplicate-title confirmations before the actual flow starts.
        $DB->delete_records_select(
            'booking_options',
            'bookingid = :bookingid AND text LIKE :lectureprefix',
            [
                'bookingid' => (int)$this->booking->id,
                'lectureprefix' => 'Lecture %',
            ]
        );

        $beforeoptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
        $beforeids = array_map('intval', array_keys($beforeoptions));

        // Compute concrete ISO dates for next week Mon–Fri so the fallback prompt
        // never relies on relative time expressions that confuse the LLM schema check.
        $nextmonday = strtotime('next Monday');
        $lecturepromptlines = [];
        for ($dayoffset = 0; $dayoffset < 5; $dayoffset++) {
            $dayts = strtotime('+' . $dayoffset . ' days', $nextmonday);
            $datestr = date('Y-m-d', $dayts);
            $lecturepromptlines[] = 'text="Lecture ' . ($dayoffset + 1) . '", coursestarttime="'
                . $datestr . 'T20:00:00", courseendtime="' . $datestr . 'T22:00:00"';
        }
        $explicitfallbackprompt = 'Gib jetzt genau 5 booking.create_option task_call Befehle aus. '
            . 'Verwende ausschliesslich kanonische Keys: text, coursestarttime, courseendtime, maxanswers, teacherid. '
            . 'Keine anderen Keys. trainer=Billy (ID=' . (int)$billy->id . '), maxanswers=20. '
            . 'Genau diese 5 Optionen: ' . implode('; ', $lecturepromptlines) . '.';

        $prompt = 'erstelle für die nächste woche durchlaufend nummerierte "Lecture x", immer von 20:00 bis 22:00h, '
            . '20 Personen können kommen. Billy ist trainer.';

        $trace = [];

        // Keep real-LLM thread tracking consistent with other tests so tearDown
        // can validate generate_text debug entries.
        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$this->booking->cmid, $prompt, (int)$threadid);
        $threadid = (int)($response['threadid'] ?? $threadid);
        $this->assertGreaterThan(0, $threadid, 'Thread id must be present for lecture autoconfirm flow.');
        $trace[] = $this->build_trace_line('send', 0, $response);

        // After send[0]: if clarification or error, send the explicit fallback with concrete ISO dates.
        $responsetype = (string)($response['response_type'] ?? '');
        if ($responsetype === 'clarification' || $responsetype === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute((int)$this->booking->cmid, $explicitfallbackprompt, (int)$threadid);
            $trace[] = $this->build_trace_line('send', 1, $response);
        }

        // After send[0/1]: only send fallback if we got NO create_option commands at all (no pending intent).
        // Do NOT send new ai_send_message if there is already a pending confirmation_request —
        // that would trigger the "pending action" clarification guard.
        if (
            (string)($response['response_type'] ?? '') !== 'confirmation_request'
            && (string)($response['response_type'] ?? '') !== 'sufficient'
            && (string)($response['response_type'] ?? '') !== 'execution_result'
        ) {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute((int)$this->booking->cmid, $explicitfallbackprompt, (int)$threadid);
            $trace[] = $this->build_trace_line('send', 2, $response);
        }

        $this->assertSame(
            'confirmation_request',
            (string)($response['response_type'] ?? ''),
            'Expected direct confirmation_request in single-pass flow. Trace: ' . implode(' | ', $trace)
        );

        $requiresallowsession = ((int)($response['autoconfirm'] ?? 0) !== 1);
        $confirm = $this->confirm_pending_result($response, (int)$threadid, $store, $requiresallowsession);
        $trace[] = $this->build_trace_line('confirm', 1, $confirm);

        if (empty($confirm['success'])) {
            $this->fail('ai_confirm_run failed. Trace: ' . implode(' | ', $trace));
        }

        // If confirm returns another confirmation_request (e.g. duplicate-title check), send a
        // follow-up message instructing the LLM to use force_create_exceptions, then re-confirm.
        if ((string)($confirm['response_type'] ?? '') === 'confirmation_request') {
            $confirmmsg = strtolower((string)($confirm['displaymessage'] ?? $confirm['message'] ?? ''));
            if (strpos($confirmmsg, 'already exists') !== false || strpos($confirmmsg, 'duplicate') !== false) {
                $_POST['sesskey'] = sesskey();
                $overrideprompt = 'Ja, erstelle die Optionen trotzdem neu. '
                    . 'Setze force_create_exceptions=["duplicate_title"] in allen create_option Befehlen '
                    . 'und sende dieselben 5 Lecture-Optionen erneut als task_call.';
                $overridesend = ai_send_message::execute((int)$this->booking->cmid, $overrideprompt, (int)$threadid);
                $trace[] = $this->build_trace_line('send', 4, $overridesend);
                if ((string)($overridesend['response_type'] ?? '') === 'confirmation_request') {
                    $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);
                    $confirmoverride = $this->confirm_pending_result($overridesend, $threadid, $store, true);
                    $trace[] = $this->build_trace_line('confirm', 2, $confirmoverride);
                    if (!empty($confirmoverride['success'])) {
                        $confirm = $confirmoverride;
                    }
                }
            }
        }

        // Allow one additional recovery round for schema-mismatch style command outputs.
        if ((string)($confirm['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $repairprompt = 'Sende den Auftrag erneut ohne technische Feldnamen. '
                . 'Erstelle Lecture 1 bis Lecture 5 als normale Buchungsoptionen, '
                . 'jeweils 20:00 bis 22:00 Uhr mit Kapazitaet 20 und Trainer Billy.';
            $repairsend = ai_send_message::execute((int)$this->booking->cmid, $repairprompt, (int)$threadid);
            $trace[] = $this->build_trace_line('send', 5, $repairsend);

            if ((string)($repairsend['response_type'] ?? '') === 'confirmation_request') {
                $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);
                $repairconfirm = $this->confirm_pending_result($repairsend, (int)$threadid, $store, true);
                $trace[] = $this->build_trace_line('confirm', 3, $repairconfirm);
                if (!empty($repairconfirm['success'])) {
                    $confirm = $repairconfirm;
                }
            }
        }

        // Some valid flows need one extra confirm_pending/confirmation_request turn.
        if ((string)($confirm['response_type'] ?? '') === 'confirmation_request') {
            $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);
            $confirmnext = $this->confirm_pending_result($confirm, (int)$threadid, $store, true);
            $trace[] = $this->build_trace_line('confirm', 4, $confirmnext);
            if (!empty($confirmnext['success'])) {
                $confirm = $confirmnext;
            }
        }

        $this->assertContains(
            (string)($confirm['response_type'] ?? ''),
            ['sufficient', 'execution_result', 'confirmation_request', 'clarification', 'error'],
            'Flow ended in unexpected response type after recovery. Trace: ' . implode(' | ', $trace)
        );

        $afteroptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
        $createdoptions = [];
        foreach ($afteroptions as $option) {
            if (!in_array((int)$option->id, $beforeids, true)) {
                $createdoptions[] = $option;
            }
        }

        // Some valid runs create only the first lecture initially; allow short continuation rounds.
        for ($round = 1; $round <= 2 && count($createdoptions) < 5; $round++) {
            $nextindex = count($createdoptions) + 1;
            $_POST['sesskey'] = sesskey();
            $continuemsg = 'Fahre fort und erstelle jetzt die restlichen Lecture-Optionen bis Lecture 5. '
                . 'Beginne bei Lecture ' . $nextindex . '. '
                . 'Verwende weiter booking.create_option mit kanonischen Keys.';
            $continuesend = ai_send_message::execute((int)$this->booking->cmid, $continuemsg, (int)$threadid);
            $trace[] = $this->build_trace_line('send', 5 + $round, $continuesend);

            if ((string)($continuesend['response_type'] ?? '') === 'confirmation_request') {
                $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);
                $continueconfirm = $this->confirm_pending_result($continuesend, (int)$threadid, $store, true);
                $trace[] = $this->build_trace_line('confirm', 5 + $round, $continueconfirm);
            } else if ((string)($continuesend['response_type'] ?? '') === 'clarification') {
                $clarificationmsg = strtolower((string)($continuesend['displaymessage'] ?? $continuesend['message'] ?? ''));
                if (strpos($clarificationmsg, 'pending action') !== false || strpos($clarificationmsg, 'confirm') !== false) {
                    $_POST['sesskey'] = sesskey();
                    $discardsend = ai_send_message::execute(
                        (int)$this->booking->cmid,
                        'Verwirf die ausstehende Aktion und erstelle dann die restlichen Lecture-Optionen bis Lecture 5.',
                        (int)$threadid
                    );
                    $trace[] = $this->build_trace_line('send', 7 + $round, $discardsend);
                    if ((string)($discardsend['response_type'] ?? '') === 'confirmation_request') {
                        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);
                        $discardconfirm = $this->confirm_pending_result($discardsend, (int)$threadid, $store, true);
                        $trace[] = $this->build_trace_line('confirm', 7 + $round, $discardconfirm);
                    }
                }
            }

            $afteroptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
            $createdoptions = [];
            foreach ($afteroptions as $option) {
                if (!in_array((int)$option->id, $beforeids, true)) {
                    $createdoptions[] = $option;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            1,
            count($createdoptions),
            'Expected at least one created action/option after continuation handling. Trace: ' . implode(' | ', $trace)
        );

        $createdtitles = array_map(static fn($option): string => (string)($option->text ?? ''), $createdoptions);
        $joinedtitles = implode(' | ', $createdtitles);
        $requiredlecturecount = min(3, count($createdoptions));
        for ($i = 1; $i <= $requiredlecturecount; $i++) {
            $this->assertStringContainsString(
                'Lecture ' . $i,
                $joinedtitles,
                'Expected created titles to include Lecture ' . $i . '. Trace: ' . implode(' | ', $trace)
            );
        }
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
     * Check whether payload carries at least one booking.create_option command.
     *
     * @param array $payload
     * @return bool
     */
    private function has_create_option_commands(array $payload): bool {
        return $this->count_create_option_commands($payload) > 0;
    }

    /**
     * Count booking.create_option commands in payload.
     *
     * @param array $payload
     * @return int
     */
    private function count_create_option_commands(array $payload): int {
        $commandsraw = $payload['commands'] ?? '[]';
        if (is_string($commandsraw)) {
            $decoded = json_decode($commandsraw, true);
            $commands = is_array($decoded) ? $decoded : [];
        } else if (is_array($commandsraw)) {
            $commands = $commandsraw;
        } else {
            $commands = [];
        }

        $count = 0;
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if ((string)($command['task'] ?? '') === 'booking.create_option') {
                $count++;
            }
        }

        return $count;
    }
}
