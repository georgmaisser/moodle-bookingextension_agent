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
 * A confirmation without commands gets one targeted repair round.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Tests for the command repair decision of the construction phase.
 *
 * Baseline run 15 (threads 4699, 4808, 4829, 4830): the constructor described the mutation completely and still
 * returned `"commands":[]`. The engine downgraded the turn to a clarification, so the user read "shall I do X?"
 * while no pending action existed and no confirm channel was open. The wording was never the problem — the
 * command envelope was missing — so the engine asks the constructor once more, with an explicit either/or, and
 * only downgrades when that round fails too.
 *
 * @covers \bookingextension_agent\local\wizard\services\constructor_command_repair
 */
final class constructor_command_repair_test extends \advanced_testcase {
    /**
     * A downgraded confirmation is repairable.
     */
    public function test_downgraded_confirmation_is_repairable(): void {
        $this->resetAfterTest();

        $this->assertTrue(constructor_command_repair::is_repairable([
            'response_type' => 'clarification',
            'issue_codes' => ['CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION', 'CONSTRUCTION_INPUT_REQUIRED'],
        ]));
    }

    /**
     * An ordinary clarification is NOT repaired: the constructor is asking for something it needs.
     */
    public function test_plain_clarification_is_not_repairable(): void {
        $this->resetAfterTest();

        $this->assertFalse(constructor_command_repair::is_repairable([
            'response_type' => 'clarification',
            'issue_codes' => ['CONSTRUCTION_INPUT_REQUIRED'],
        ]));
        $this->assertFalse(constructor_command_repair::is_repairable([
            'response_type' => 'clarification',
            'issue_codes' => [],
        ]));
    }

    /**
     * A turn that already carries commands is left alone.
     */
    public function test_successful_turn_is_not_repairable(): void {
        $this->resetAfterTest();

        $this->assertFalse(constructor_command_repair::is_repairable([
            'response_type' => 'confirmation_request',
            'commands' => [['skill' => 'demo.skill', 'version' => 1, 'input' => []]],
            'issue_codes' => [],
        ]));
        $this->assertFalse(constructor_command_repair::is_repairable([]));
    }

    /**
     * The repair instruction offers both ways out and names the selected skill, so the model does not have to
     * guess which skill to emit.
     */
    public function test_repair_instruction_offers_both_ways_out(): void {
        $this->resetAfterTest();

        $instruction = constructor_command_repair::instruction('mod_booking.update_option');

        $this->assertStringContainsString('mod_booking.update_option', $instruction);
        $this->assertStringContainsString('commands', $instruction);
        $this->assertStringContainsString('clarification', $instruction);
    }

    /**
     * The repaired answer is only taken when it actually carries a command for the selected skill.
     */
    public function test_repaired_result_is_only_accepted_with_a_matching_command(): void {
        $this->resetAfterTest();

        $good = ['response_type' => 'confirmation_request',
            'commands' => [['skill' => 'demo.skill', 'version' => 1, 'input' => ['a' => 1]]]];
        $wrongskill = ['response_type' => 'confirmation_request',
            'commands' => [['skill' => 'other.skill', 'version' => 1, 'input' => []]]];
        $stillempty = ['response_type' => 'clarification', 'commands' => []];

        $this->assertTrue(constructor_command_repair::accept($good, 'demo.skill'));
        $this->assertFalse(constructor_command_repair::accept($wrongskill, 'demo.skill'));
        $this->assertFalse(constructor_command_repair::accept($stillempty, 'demo.skill'));
    }
}
