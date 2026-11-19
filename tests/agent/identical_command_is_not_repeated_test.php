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
 * A command that already ran in this turn with the same input is not run again.
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
 * F77 (baseline run 31, thread 9469, DP-2): after core.diagnose_permissions had run, the selector re-issued the
 * identical command {userid: 7164} five times in a row, each time receiving the identical observation, before it
 * answered. Nothing in the engine compared a new command with what the turn had already executed. The state
 * knows every executed command; a command whose skill and input already ran in this turn is a loop, not a plan:
 * the turn ends as a synthesis from what exists - the command never runs a second time.
 *
 * Until 2026-11-19 the planner first got one retry hint and the turn ended on the second repeat. Session suite SES10
 * (Wunderbyte-GmbH/Wunderbyte-GmbH#2787, threads 32442 ff.): the hint changed nothing in 18 of 18 loops; over all runs
 * on the test VM since 2026-08-31 the same command followed the hint in 186 of 304 turns and 'sufficient' in 69. The
 * hint round cost a selector and a constructor call, so the first identical repeat ends the turn now.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class identical_command_is_not_repeated_test extends abstract_agent_testcase {
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
     * The identical command runs once; the turn still ends with an answer.
     */
    public function test_the_same_command_runs_once(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('wizard.list_memories'),
            $this->constructor_skill_call('wizard.list_memories', []),
            // Thread 9469: the planner asks for exactly the same thing again.
            $this->selector_skill_call('wizard.list_memories'),
            $this->constructor_skill_call('wizard.list_memories', []),
            // The synthesis answers from the observation that exists.
            $this->planner_sufficient('Du hast keine gespeicherten Fakten.'),
        ]);

        $result = $this->chat('Welche Fakten hast du dir über mich gemerkt?', (int)$threadid, $store, $runtime);

        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]);
        $executed = 0;
        foreach ($runs as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        $this->assertSame(1, $executed, 'the identical command ran exactly once');
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertContains(
            'LOOP_IDENTICAL_COMMAND_REPEATED',
            array_map('strval', (array)($result['issue_codes'] ?? [])),
            'the loop is named by its state, not hidden'
        );
    }

    /**
     * Thread 32442 (SES10, S5-2) re-enacted: the course structure was read completely, the selector picked the same
     * skill and the constructor built the same input. The turn ends there - two selector and two constructor calls,
     * no hint round, one run.
     */
    public function test_the_first_identical_repeat_ends_the_turn(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $skill = 'course.analyze_course_structure';
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call($skill)),
            array_fill(0, 4, $this->constructor_skill_call($skill, []))
        );

        $result = $this->chat('Wie ist dieser Kurs aufgebaut?', (int)$threadid, $store, $runtime);

        $codes = array_map('strval', (array)($result['issue_codes'] ?? []));
        $this->assertSame('SCSC', $this->scripted_phase_sequence(), 'no hint round after the repeat');
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($codes));
        $this->assertContains('LOOP_IDENTICAL_COMMAND_REPEATED', $codes, 'the loop is named by its state');
        $executed = 0;
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]) as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        $this->assertSame(1, $executed, 'the command ran exactly once');
        foreach ($this->scriptedplannerprompts as $prompt) {
            $this->assertStringNotContainsString('already ran in this turn', $prompt, 'the hint is gone');
        }
    }

    /**
     * Other contract breaches keep their one hint round: an unreadable selection is asked for once more.
     */
    public function test_a_parse_error_still_gets_its_hint_round(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            ['this is not json', $this->planner_sufficient('Hallo.')],
            []
        );

        $this->chat('Hallo', (int)$threadid, $store, $runtime);

        $this->assertSame('SS', $this->scripted_phase_sequence(), 'one retry after the parse error');
        $this->assertStringContainsString('The previous output was not valid JSON', (string)$this->scriptedplannerprompts[1]);
    }

    /**
     * A different input is a different command and runs.
     */
    public function test_a_changed_input_still_runs(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Quiet Target');
        $this->create_option('Loud Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.get_option_details'),
            $this->constructor_skill_call('mod_booking.get_option_details', ['optionquery' => 'Quiet Target']),
            $this->selector_skill_call('mod_booking.get_option_details'),
            $this->constructor_skill_call('mod_booking.get_option_details', ['optionquery' => 'Loud Target']),
            $this->planner_sufficient('Beide Optionen sind da.'),
        ]);

        $this->chat('Zeig mir Quiet Target und Loud Target.', (int)$threadid, $store, $runtime);

        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]);
        $executed = 0;
        foreach ($runs as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        $this->assertSame(2, $executed, 'two different inputs are two commands');
    }
}
