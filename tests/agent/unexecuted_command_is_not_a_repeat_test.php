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
 * A command that preflight stopped never ran, so constructing it again is not a repeated command.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Thread 9914 (test VM, 2026-11-19): a read-only skill offered the existing options because the named one matched
 * nothing; nothing ran. The planner constructed the same input again and the engine answered with
 * LOOP_IDENTICAL_COMMAND_REPEATED ("the same command already ran in this turn"), spent its hint round on it, and
 * ended the turn after three selector and three constructor calls. The engine remembered a command when it was handed
 * on, before preflight could stop it. Only a command that ran is remembered now: the second construction meets the
 * preflight again, whose one re-plan is used up, and the turn ends as the question with the choices.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\agent_state
 */
final class unexecuted_command_is_not_a_repeat_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Provider and capabilities.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
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
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Read-only skills that offer the options of the activity when the named option matches nothing.
     *
     * @return array<string,array{0:string,1:array}>
     */
    public static function skills_that_offer_choices(): array {
        return [
            'diagnose_waitinglist' => ['mod_booking.diagnose_waitinglist', ['optionquery' => 'Harbour Walk']],
            'diagnose_booking_issue' => [
                'mod_booking.diagnose_booking_issue',
                ['question' => 'Why am I not on the list?', 'optionquery' => 'Harbour Walk'],
            ],
        ];
    }

    /**
     * The same unresolved input twice: the turn ends as the question after one re-plan, and no repeat is reported.
     *
     * @dataProvider skills_that_offer_choices
     * @param string $skill
     * @param array $input
     */
    public function test_the_second_construction_ends_as_the_question(string $skill, array $input): void {
        global $DB;
        $this->create_option('Pottery Evening');
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call($skill)),
            array_fill(0, 4, $this->constructor_skill_call($skill, $input))
        );

        $result = $this->chat('Was ist mit dem Harbour Walk?', (int)$threadid, $store, $runtime);

        $sequence = $this->scripted_phase_sequence();
        $codes = array_map('strval', (array)($result['issue_codes'] ?? []));
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), $sequence . ' ' . json_encode($codes));
        $this->assertContains('PREFLIGHT_CHOICES_OFFERED', $codes, 'the choices stand as the answer');
        $this->assertNotContains('LOOP_IDENTICAL_COMMAND_REPEATED', $codes, 'nothing ran, so nothing was repeated');
        $this->assertSame('SCSC', $sequence, 'one re-plan with the choices, then the question');
        $executed = 0;
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]) as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        $this->assertSame(0, $executed, 'the unresolved command never runs');
    }
}
