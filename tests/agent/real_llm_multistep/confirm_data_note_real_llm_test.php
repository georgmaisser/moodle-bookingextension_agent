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
 * Real-LLM acceptance test for B1/P7: the confirmation card must carry the proposed data.
 *
 * A single, fully-specified create_option ("title X, 37 EUR, max 23, trainer Y") reaches a
 * confirmation card. The message the user confirms against must deterministically restate the
 * concrete values they asked for — today it is a generic "I will create a booking option" plus the
 * operating-context note (thread 591/1594: "I will create a new booking option. Note: this will be
 * carried out in: course 'ai' (ID 11)."), so the user confirms BLIND. build_proposed_data_note
 * (P1_P6 §P7) must append the field values (and cover the empty case).
 *
 * Gated DETERMINISTICALLY, not by value-presence: the LLM's free-form confirm line sometimes
 * restates the values and sometimes does not (thread 591 did not, other runs do), and the
 * anonymizer masks names — so "message contains the values" is a flaky/false-green signal. B1's
 * actual guarantee is that the ENGINE always appends a proposed-data block (build_proposed_data_note,
 * headed by agent_confirm_data_heading), independent of the LLM wording. The test therefore asserts
 * that heading is present in the user-facing confirm message. RED until B1/P7 lands (the string does
 * not exist yet). The DB is never mutated (assertion runs before any confirm).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Confirmation-card data block with a real LLM (B1/P7).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class confirm_data_note_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * The confirmation message of a fully-specified create_option must carry the proposed values.
     */
    public function test_option_confirmation_message_carries_the_proposed_field_values(): void {
        // A price category so "37 Euro" resolves to {default: 37} instead of clarifying.
        $this->gen->create_pricecategory((object)[
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standardpreis',
            'defaultvalue' => 37,
        ]);

        // A distinctively named trainer the model can resolve by name (surname is the assertion anchor).
        $trainer = $this->getDataGenerator()->create_user([
            'firstname' => 'Bartholomew',
            'lastname' => 'Quintenzio',
            'email' => 'bartholomew.quintenzio.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($trainer->id, $this->course->id, 'editingteacher');

        $this->setUser($this->teacher);
        $title = 'Yoga am Morgen ' . substr(sha1(uniqid('', true)), 0, 6);
        [$store, $runtime, $threadid] = $this->build_runtime();

        // The single test booking instance is the target (scope cascade auto-picks it); all data is
        // given, so a complete create must reach a confirmation card without a clarification.
        $result = $this->chat(
            'Erstelle eine Buchungsoption "' . $title . '" für 37 Euro, maximal 23 Teilnehmer, '
                . fullname($trainer) . ' als Trainer.',
            $threadid,
            $store,
            $runtime
        );

        if ((string)($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->dump_llm_debug((int)$threadid);
        }
        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'A fully-specified create_option must reach a confirmation card. Got: ' . $this->payload_text($result)
        );

        $message = (string)($result['message'] ?? '');
        $lang = (string)($result['lang'] ?? 'en');

        // B1/P7 gate — DETERMINISTIC, not value-presence. A value-presence check is unreliable here:
        // the LLM's free-form confirm line SOMETIMES restates the values (this run did; thread 591
        // did not), and the anonymizer masks the trainer name (ANON_USER_1_both). B1's guarantee is
        // that the engine ALWAYS appends a proposed-data block — regardless of the LLM wording — via
        // build_proposed_data_note, headed by the new agent_confirm_data_heading string. So the
        // reliable signal is that heading in the user-facing confirm message. string_exists() first,
        // so a missing string reads as "B1 not implemented" instead of a get_string debugging notice.
        $manager = get_string_manager();
        if (!$manager->string_exists('agent_confirm_data_heading', 'bookingextension_agent')) {
            $this->dump_llm_debug((int)$threadid);
            $this->fail(
                'B1/P7 not implemented: the proposed-data heading string agent_confirm_data_heading is '
                . 'missing. build_proposed_data_note must append a deterministic data block (heading + '
                . 'field rows) to the confirmation message. Full message: ' . $message
            );
        }

        $heading = get_string('agent_confirm_data_heading', 'bookingextension_agent', null, $lang);
        $this->assertStringContainsString(
            $heading,
            $message,
            'B1/P7: the confirmation message must carry the deterministic proposed-data block '
            . '(heading "' . $heading . '" + field rows), appended by the engine like the '
            . 'operating-context note — not left to the LLM. Full message: ' . $message
        );

        // Under that block the concrete values the user gave must appear (names are anonymized
        // upstream, so title/price/capacity only).
        foreach ([$title, '37', '23'] as $needle) {
            $this->assertStringContainsString(
                (string)$needle,
                $message,
                'B1/P7: the proposed-data block must carry the value "' . $needle . '". Message: ' . $message
            );
        }
    }

    /**
     * Dump the thread's LLM debug trail to STDERR to locate where a failing run derailed.
     *
     * @param int $threadid
     * @return void
     */
    private function dump_llm_debug(int $threadid): void {
        global $DB;
        foreach ($DB->get_records('bx_agent_ai_llm_debug', ['threadid' => $threadid], 'id ASC') as $row) {
            fwrite(STDERR, "\n[llmdebug " . $row->id . '] ' . $row->source . "\nRESPONSE: "
                . substr((string)$row->responsetext, 0, 500) . "\n");
        }
    }
}
