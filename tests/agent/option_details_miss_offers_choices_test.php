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
 * An option name that get_option_details cannot resolve reaches the selector as choices, not as a repeated command.
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
 * Session suite SES10, scenario S12-3 (threads 32449, 32468, 32487), re-enacted with the planner output of the log:
 * the selector picks mod_booking.get_option_details and the constructor passes a name that no option of the activity
 * carries. The skill ran, answered "could not be resolved", the selector picked it again with the identical input and
 * the turn ended in LOOP_IDENTICAL_COMMAND_REPEATED with a wrong answer. With the option resolved before the skill
 * runs, the options of the activity reach the selector once as choices; it picks one, picks another skill, or the turn
 * ends as the question. The names below are test data only.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \mod_booking\local\wizard\options\skills\get_option_details_skill
 */
final class option_details_miss_offers_choices_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The user turn of S12-3. */
    private const PROMPT = 'Was steht in der Beschreibung vom Ersthelfer Grundkurs?';

    /** The skill under test. */
    private const SKILL = 'mod_booking.get_option_details';

    /** The constructor input of thread 32449. */
    private const MISS = ['optionquery' => 'Ersthelfer Grundkurs', 'requested_fields' => ['description']];

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
     * Number of commands that ran in a thread.
     *
     * @param int $threadid
     * @return int
     */
    private function executed_commands(int $threadid): int {
        global $DB;
        $executed = 0;
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid]) as $run) {
            $executed += count((array)json_decode((string)$run->commandsjson, true));
        }
        return $executed;
    }

    /**
     * The prompts the selector received, in order.
     *
     * @return string[]
     */
    private function selector_prompts(): array {
        return array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
        ));
    }

    /**
     * The options reach the selector once; the option picked from them runs exactly once and nothing is repeated.
     */
    public function test_the_options_reach_the_selector_and_the_pick_runs_once(): void {
        $pottery = (int)$this->create_option('Pottery Evening')->id;
        $this->create_option('Choir Rehearsal');
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call(self::SKILL), $this->selector_skill_call(self::SKILL)],
            [
                $this->constructor_skill_call(self::SKILL, self::MISS),
                $this->constructor_skill_call(self::SKILL, ['optionid' => $pottery, 'requested_fields' => ['description']]),
            ]
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $sequence = $this->scripted_phase_sequence();
        $codes = array_map('strval', (array)($result['issue_codes'] ?? []));
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), $sequence . ' ' . json_encode($codes));
        $this->assertSame(1, $this->executed_commands((int)$threadid), 'the miss does not run, the pick runs once');
        $this->assertNotContains('LOOP_IDENTICAL_COMMAND_REPEATED', $codes, 'no repeat loop: ' . $sequence);
        $this->assertSame('SCSC', substr($sequence, 0, 4), 'the re-plan starts at the selector');

        $prompts = $this->selector_prompts();
        $this->assertStringNotContainsString('CHOICES for', $prompts[0]);
        $this->assertStringContainsString('CHOICES for optionid', $prompts[1], 'the choices reach the selector');
        $this->assertStringContainsString('id=' . $pottery, $prompts[1]);
        $this->assertStringContainsString('Pottery Evening', $prompts[1]);
    }

    /**
     * The selector can leave the skill after the choices (the name was a course, not an option): the other skill runs
     * once and the turn ends with an answer.
     */
    public function test_another_skill_can_follow_the_choices(): void {
        $this->create_option('Pottery Evening');
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call(self::SKILL), $this->selector_skill_call('course.analyze_course_structure')],
            [
                $this->constructor_skill_call(self::SKILL, self::MISS),
                $this->constructor_skill_call('course.analyze_course_structure', ['courseid' => (int)$this->course->id]),
            ]
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $codes = array_map('strval', (array)($result['issue_codes'] ?? []));
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), $this->scripted_phase_sequence() . ' '
            . json_encode($codes));
        $this->assertSame(1, $this->executed_commands((int)$threadid), 'only the course skill ran');
        $this->assertNotContains('LOOP_IDENTICAL_COMMAND_REPEATED', $codes);
    }

    /**
     * Non-success path: nothing is picked and the constructor passes the same name again. The unresolved command
     * never runs, the turn does not end as an error, and the user text names neither a field nor an issue code.
     *
     * The engine counts the second, identical construction as a repeated command and ends the turn with an answer
     * from the observations, which hold the offered options. This test does not pin that ending.
     */
    public function test_no_pick_never_runs_and_is_no_error(): void {
        $this->create_option('Pottery Evening');
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call(self::SKILL)),
            array_fill(0, 4, $this->constructor_skill_call(self::SKILL, self::MISS))
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $codes = array_map('strval', (array)($result['issue_codes'] ?? []));
        $this->assertContains((string)($result['response_type'] ?? ''), ['clarification', 'sufficient'], json_encode($codes));
        $this->assertSame(0, $this->executed_commands((int)$threadid), 'the unresolved command never runs');
        $this->assertStringContainsString('CHOICES for optionid', $this->selector_prompts()[1]);
        $message = (string)($result['message'] ?? '');
        foreach (['optionid', 'optionquery', 'OPTION_RESOLUTION', 'PREFLIGHT_', 'RECOVERABLE_', 'LOOP_'] as $internal) {
            $this->assertStringNotContainsString($internal, $message, 'no field name and no issue code in the user text');
        }
    }

    /**
     * An option title that carries the name of a user of the site reaches the selector masked, like every observation.
     */
    public function test_the_choices_reach_the_selector_masked(): void {
        $name = fullname($this->student);
        $this->create_option('Consultation with ' . $name);
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call(self::SKILL)],
            [$this->constructor_skill_call(self::SKILL, self::MISS)]
        );

        $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $prompts = $this->selector_prompts();
        $this->assertGreaterThanOrEqual(2, count($prompts), $this->scripted_phase_sequence());
        $this->assertStringContainsString('CHOICES for optionid', $prompts[1]);
        $this->assertStringContainsString('Consultation with ANON_USER_', $prompts[1], 'the title is offered with a token');
        $this->assertStringNotContainsString($name, $prompts[1], 'the name is not sent in clear text');
    }
}
