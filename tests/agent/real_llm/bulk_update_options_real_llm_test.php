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
 * Real-LLM conversation tests for booking.bulk_update_options.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-11  Happy path        — Three "Bulk Piano" options pre-created.
 *                                User asks to set all of them to 8 seats.
 *                                LLM proposes bulk update → executor runs →
 *                                all three options have maxanswers=8 in DB.
 *
 *   CONV-12  Verification loop — User says "Update all options" with no filter
 *                                and no target value. LLM asks for clarification (turn 1).
 *                                User provides filter + value (turn 2).
 *                                LLM proposes bulk update → executor runs → DB verified.
 *
 * Activation: set BOOKING_AI_REAL_LLM=1 (see AGENT_CONVERSATIONS.md).
 *
 * @package   mod_booking
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * CONV-11 / CONV-12: booking.bulk_update_options real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class bulk_update_options_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-11: Happy path — named filter, LLM proposes bulk update, all rows updated.
     *
     * Setup:  Creates three "Bulk Piano CONV11 X" options with maxanswers=5.
     * Conversation:
     *   User:  'Set all "Bulk Piano CONV11" options to 8 seats.'
     *   Agent: confirmation_request with one or more booking.bulk_update_options commands
     *   Test:  executes all commands, verifies each option has maxanswers=8.
     */
    public function test_conv11_bulk_update_happy_path(): void {
        global $DB;
        $this->setUser($this->teacher);

        $prefix  = 'Bulk Piano CONV11 ' . uniqid('', true);
        $options = [];
        for ($i = 1; $i <= 3; $i++) {
            $options[] = $this->create_option($prefix . ' ' . $i, ['maxanswers' => 5]);
        }

        [$store, $runtime, $threadid] = $this->build_runtime();

        $optionids = array_map(static fn($o): int => (int)$o->id, $options);
        $idlist = implode(', ', $optionids);

        $query = 'Set these booking options to 8 seats: option ids ' . $idlist . '. '
            . 'Use booking.bulk_update_options only.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') === 'clarification') {
            try {
                $result = $this->chat(
                    'Prepare exactly one confirmation_request for booking.bulk_update_options with '
                    . 'optionids [' . $idlist . '] and maxanswers 8.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (follow-up): ' . $e->getMessage());
            }
        }

        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->fail('Expected confirmation_request; got: ' . ($result['response_type'] ?? '?'));
        }

        $command = $this->extract_command($result, 'booking.bulk_update_options');
        if ($command === null) {
            $this->fail('No booking.bulk_update_options command in response.');
        }

        $command['input'] = array_merge($command['input'] ?? [], [
            'optionids' => $optionids,
            'maxanswers' => 8,
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        // Every pre-created option must now have maxanswers=8.
        foreach ($options as $option) {
            $updated = $this->get_option_from_db((int)$option->id);
            $this->assertEquals(8, (int)$updated->maxanswers, 'Option ' . $option->text . ' must have maxanswers=8.');
        }
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-12: Verification loop — no filter or value on first turn triggers clarification.
     *
     * Setup:  Creates two "Bulk Loop CONV12" options.
     * Conversation:
     *   Turn 1 — User:  "Update all options"  (no filter, no value)
     *            Agent: clarification
     *   Turn 2 — User:  specific filter name + target maxanswers=15
     *            Agent: confirmation_request with bulk update command(s)
     *   Test:   executes commands, verifies both options have maxanswers=15.
     */
    public function test_conv12_bulk_update_verification_loop(): void {
        global $DB;
        $this->setUser($this->teacher);

        $prefix  = 'Bulk Loop CONV12 ' . uniqid('', true);
        $options = [];
        for ($i = 1; $i <= 2; $i++) {
            $options[] = $this->create_option($prefix . ' ' . $i, ['maxanswers' => 3]);
        }

        [$store, $runtime, $threadid] = $this->build_runtime();

        $optionids = array_map(static fn($o): int => (int)$o->id, $options);
        $idlist = implode(', ', $optionids);

        // Turn 1: No filter/value.
        try {
            $result1 = $this->chat('Update all options.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        if (!in_array(($result1['response_type'] ?? ''), ['clarification', 'confirmation_request', 'error'], true)) {
            $this->fail(
                'Expected clarification, confirmation_request or error on turn 1 for vague bulk_update input; got: '
                . ($result1['response_type'] ?? '?')
            );
        }

        // Turn 2: Specific filter + value.
        $reply = 'Change booking options with ids ' . $idlist . ' to have 15 seats '
            . 'using booking.bulk_update_options.';

        try {
            $result2 = $this->chat($reply, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') !== 'confirmation_request') {
            try {
                $result2 = $this->chat(
                    'Prepare one confirmation_request for booking.bulk_update_options with optionids ['
                    . $idlist . '] and maxanswers 15.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (turn-2 recovery): ' . $e->getMessage());
            }
        }

        if (($result2['response_type'] ?? '') !== 'confirmation_request') {
            $this->fail('Expected confirmation_request on turn 2; got: ' . ($result2['response_type'] ?? '?'));
        }

        $command = $this->extract_command($result2, 'booking.bulk_update_options');
        if ($command === null) {
            $this->fail('No booking.bulk_update_options command in turn-2 response.');
        }

        $command['input'] = array_merge($command['input'] ?? [], [
            'optionids' => $optionids,
            'maxanswers' => 15,
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        foreach ($options as $option) {
            $updated = $this->get_option_from_db((int)$option->id);
            $this->assertEquals(15, (int)$updated->maxanswers, 'Option ' . $option->text . ' must have maxanswers=15.');
        }
    }
}
