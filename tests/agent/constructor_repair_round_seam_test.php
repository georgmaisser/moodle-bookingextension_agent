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
 * The construction repair round at the phase seam, not at the function boundary.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic seam tests for constructor_command_repair.
 *
 * c4e65e1 added a repair round for a constructor that asks the user although its skill declares no
 * required field at all (baseline run 16: AA-1, SCC-3, UQ-4, TSA-4, DMD-2). It never fired: the
 * condition reads CONSTRUCTION_INPUT_REQUIRED from the interpreted result, while
 * planner_phase_service stamps that code 41 lines AFTER the check. Run 17 confirmed it — not a
 * single rp=1 call in the whole corpus, although LR-2, TSA-4, UQ-1 and UQ-4 all match the pattern.
 *
 * constructor_command_repair_test covers the decision function and passes the issue code in by
 * hand, so it cannot see the ordering at all — it even pins the broken production state as an
 * expected false. These tests drive the real phase instead.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 * @covers \bookingextension_agent\local\wizard\services\constructor_command_repair
 */
final class constructor_repair_round_seam_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A bare question from a skill that requires nothing gets exactly one repair round, and the
     * repaired answer stages the command. mod_booking.update_option declares no required field.
     */
    public function test_bare_clarification_of_a_zero_required_skill_is_repaired(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Repair Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.update_option'),
            // The defect under test: a question with no command and no issue code.
            $this->constructor_clarification('Which option do you mean?'),
            // The repair round answers properly.
            $this->constructor_confirmation_request('mod_booking.update_option', [
                'optionquery' => 'Repair Target',
                'text' => 'Repaired Title',
            ]),
        ]);

        $result = $this->chat('Benenn die Option "Repair Target" auf "Repaired Title" um.', (int)$threadid, $store, $runtime);

        $this->assertCount(
            3,
            $this->scriptedplannerprompts,
            'One selector, one constructor and exactly one repair round must be consumed.'
        );
        $this->assertStringContainsString(
            'REPAIR ROUND',
            (string)($this->scriptedplannerprompts[2] ?? ''),
            'The third call must carry the repair instruction.'
        );
        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'The repaired construction must stage the command: ' . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertContains(
            'CONSTRUCTION_COMMAND_REPAIRED',
            (array)($result['issue_codes'] ?? []),
            'The repaired turn must be marked as such.'
        );
    }

    /**
     * The non-success path: if the repair round asks again, the question stands. It must not become
     * an error, and it must not burn a second repair round.
     */
    public function test_failed_repair_round_leaves_an_honest_question(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.update_option'),
            $this->constructor_clarification('Which option do you mean?'),
            $this->constructor_clarification('I still need to know which option.'),
        ]);

        $result = $this->chat('Benenn die Option um.', (int)$threadid, $store, $runtime);

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'A repair round that fails again leaves the question standing, never an error.'
        );
        $this->assertEmpty((array)($result['commands'] ?? []), 'No command may be staged.');
        $this->assertCount(
            3,
            $this->scriptedplannerprompts,
            'Exactly one repair round — the engine must not keep asking the model.'
        );
    }

    /**
     * A skill that really has required fields keeps its question: it may genuinely need one of them.
     * mod_booking.configure_booking_instance requires `action`.
     */
    public function test_clarification_of_a_skill_with_required_fields_is_not_repaired(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.configure_booking_instance'),
            $this->constructor_clarification('Should I read the settings or change them?'),
        ]);

        $result = $this->chat('Stell die Buchungsaktivität um.', (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertCount(
            2,
            $this->scriptedplannerprompts,
            'No repair round for a skill whose schema really requires input.'
        );
    }
}
