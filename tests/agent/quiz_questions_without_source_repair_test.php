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
 * A quiz command that asks for questions without a source gets one repair round before the user is asked.
 *
 * Found on demo2 (threads 86 and 88, 2026-11-19) and measured on the VM on 2026-11-23: "a quiz with ten questions
 * about Graz" was built as {name, count} in 8 of 20 end-to-end runs. The skill then asked the user where the questions
 * should come from, although the request named the topic, and the turns after that question went wrong in 6 of the 20
 * runs (two questions in a row, a question rendered wrongly, no quiz). The command already shows the gap: a count,
 * no source, and no addquestions, the field with which the constructor says that the user named no source. That is a
 * structural mismatch of the command, so the engine's one construction retry (c419bff) carries the repair text. A
 * request that names no topic keeps its question: the repair text gives addquestions=true as the way out (replay of
 * the recorded constructor call, 15 of 15, no topic invented).
 *
 * Why this is not the repair round removed in d80f5a5: that one let the engine second-guess a constructor question by
 * the schema's required flags. Here the skill rejects its own command, the retry runs through the selector like every
 * framework retry, and a second mismatch ends as the question, never as an error.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Structural check of the quiz skills and the retry it triggers, via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 * @covers \bookingextension_agent\local\wizard\course\skills\update_quiz_skill
 * @covers \bookingextension_agent\local\wizard\services\activities\quiz_question_service
 */
final class quiz_questions_without_source_repair_test extends abstract_agent_testcase {
    use scripted_llm_trait;

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

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Commands of both quiz skills: which ones the structural check rejects and which it lets pass.
     *
     * @return array[]
     */
    public static function command_provider(): array {
        return [
            'count without a source' => [['count' => 10], false],
            'question types without a source' => [['qtypes' => ['multichoice']], false],
            'count, and the constructor says no source was named' => [['count' => 10, 'addquestions' => true], true],
            'count with a topic' => [['count' => 10, 'content' => 'Graz'], true],
            'count with the course PDFs' => [['count' => 10, 'usecoursepdfs' => true], true],
            'count with one resource' => [['count' => 10, 'resourcecmid' => 12], true],
            'count with a category' => [['count' => 5, 'category' => 'Bienenkunde'], true],
            'specific questions' => [['count' => 2, 'questionids' => [4, 5]], true],
            'no questions asked for' => [[], true],
            'only the question to ask the user' => [['addquestions' => true], true],
        ];
    }

    /**
     * add_quiz rejects a command that wants questions and names neither a source nor addquestions, with a repair text
     * for the planner and the question for the user, and without the code that would skip the retry.
     *
     * @dataProvider command_provider
     * @param array $questionfields
     * @param bool $valid
     */
    public function test_add_quiz_structural_check(array $questionfields, bool $valid): void {
        $this->assert_structural_check(new add_quiz_skill(), ['name' => 'Graz quiz'] + $questionfields, $valid);
    }

    /**
     * update_quiz applies the same check.
     *
     * @dataProvider command_provider
     * @param array $questionfields
     * @param bool $valid
     */
    public function test_update_quiz_structural_check(array $questionfields, bool $valid): void {
        $this->assert_structural_check(new update_quiz_skill(), ['activityquery' => 'Graz quiz'] + $questionfields, $valid);
    }

    /**
     * Generating from content without a count stays the question for the count (B4, 3ba8838): no retry.
     */
    public function test_content_without_count_still_asks_for_the_count(): void {
        $result = (new add_quiz_skill())->check_structure(['name' => 'Graz quiz', 'content' => 'Graz']);
        $this->assertFalse($result['valid']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
    }

    /**
     * The retry heals: the second construction carries the topic and the card is staged with it.
     */
    public function test_retry_with_the_topic_stages_the_card(): void {
        [$store, $runtime, $threadid] = $this->start_chat();
        $this->install_scripted_planner([
            $this->selector_skill_call('course.add_quiz'),
            $this->constructor_confirmation_request('course.add_quiz', ['name' => 'Quiz about Graz', 'count' => 10]),
            $this->selector_skill_call('course.add_quiz'),
            $this->constructor_confirmation_request(
                'course.add_quiz',
                ['name' => 'Quiz about Graz', 'count' => 10, 'content' => 'Graz']
            ),
        ]);

        $result = $this->chat('Create a quiz with ten questions about Graz.', (int)$threadid, $store, $runtime);

        $codes = json_encode($result['issue_codes'] ?? []);
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), $codes);
        $command = $this->extract_command($result, 'course.add_quiz');
        $this->assertNotNull($command);
        $plan = (array)($command['input']['plan'] ?? []);
        $this->assertSame('generate', (string)($plan['mode'] ?? ''));
        $this->assertSame('Graz', (string)($plan['content'] ?? ''));
        $this->assertSame(10, (int)($plan['count'] ?? 0));
        $this->assertCount(4, $this->scriptedplannerprompts, 'one retry round, not more');
        $retryprompts = implode("\n", array_slice($this->scriptedplannerprompts, 2));
        $this->assertStringContainsString('RETRY_HINT', $retryprompts);
        $this->assertStringContainsString('REPAIR: ', $retryprompts);
        $this->assertStringContainsString('addquestions=true', $retryprompts);
    }

    /**
     * No topic was named: the second construction says so with addquestions and the user gets the source question
     * of the skill, with its own code.
     */
    public function test_retry_without_a_topic_asks_the_source_question(): void {
        [$store, $runtime, $threadid] = $this->start_chat();
        $this->install_scripted_planner([
            $this->selector_skill_call('course.add_quiz'),
            $this->constructor_confirmation_request('course.add_quiz', ['name' => 'Final quiz', 'count' => 5]),
            $this->selector_skill_call('course.add_quiz'),
            $this->constructor_confirmation_request(
                'course.add_quiz',
                ['name' => 'Final quiz', 'count' => 5, 'addquestions' => true]
            ),
        ]);

        $result = $this->chat('Add a final quiz with 5 questions to the course.', (int)$threadid, $store, $runtime);

        $codes = json_encode($result['issue_codes'] ?? []);
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), $codes);
        $this->assertContains('ADD_QUIZ_QUESTION_SOURCE', (array)($result['issue_codes'] ?? []));
        $this->assertCount(4, $this->scriptedplannerprompts);
    }

    /**
     * The retry does not heal: the turn ends as the question to the user, not as an error, and nothing is staged.
     */
    public function test_a_second_mismatch_ends_as_the_question(): void {
        [$store, $runtime, $threadid] = $this->start_chat();
        $this->install_scripted_planner([
            $this->selector_skill_call('course.add_quiz'),
            $this->constructor_confirmation_request('course.add_quiz', ['name' => 'Quiz about Graz', 'count' => 10]),
            $this->selector_skill_call('course.add_quiz'),
            $this->constructor_confirmation_request('course.add_quiz', ['name' => 'Quiz about Graz', 'count' => 10]),
        ]);

        $result = $this->chat('Create a quiz with ten questions about Graz.', (int)$threadid, $store, $runtime);

        $codes = json_encode($result['issue_codes'] ?? []);
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), $codes);
        $this->assertNull($this->extract_command($result, 'course.add_quiz'));
        $this->assertStringContainsString('Which questions should I add', implode(' ', (array)($result['errors'] ?? [])));
        $this->assertCount(4, $this->scriptedplannerprompts, 'exactly one retry');
    }

    /**
     * Run the structural check and assert its outcome.
     *
     * @param object $skill
     * @param array $input
     * @param bool $valid
     */
    private function assert_structural_check(object $skill, array $input, bool $valid): void {
        $result = $skill->check_structure($input);
        $this->assertSame($valid, (bool)$result['valid'], json_encode($input));
        if ($valid) {
            return;
        }
        $this->assertNotContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []), 'the retry must fire');
        $repair = implode(' ', (array)($result['repair'] ?? []));
        $this->assertStringContainsString('content', $repair);
        $this->assertStringContainsString('addquestions=true', $repair);
        // With a one-step plan the retry kept the topic in the quiz name only (5 of 30 replay calls) until the text
        // said that a topic in the name is still the source; a name alone stays no topic (15 of 15).
        $this->assertStringContainsString('also when the quiz name already carries it', $repair);
        $this->assertStringContainsString('named no topic and gave no material', $repair);
        $this->assertStringContainsString('Which questions should I add', implode(' ', (array)($result['errors'] ?? [])));
    }

    /**
     * A logged-in teacher with a runtime and a thread.
     *
     * @return array
     */
    private function start_chat(): array {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        return $this->build_runtime();
    }
}
