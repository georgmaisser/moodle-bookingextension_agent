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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\question\skills\generate_questions_skill;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;

/**
 * Contract tests for the generate_questions core skill (deterministic parts).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wbagent\question\skills\generate_questions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generate_questions_skill_test extends advanced_testcase {
    /** Document block as injected by attachment_processor. */
    private const DOC_MESSAGE =
        "--- DOCUMENT: lecture.pdf ---\nMitochondria are the powerhouse of the cell.\n--- END DOCUMENT ---\n\nMake questions.";

    /**
     * Metadata reflects a mutating, course-scoped, capability-gated skill.
     */
    public function test_metadata(): void {
        $skill = new generate_questions_skill();
        $this->assertSame('question.generate_questions', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame(['moodle/question:add'], $skill->get_required_native_capabilities());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
    }

    /**
     * Structural validation accepts sane input and rejects bad count/qtypes.
     */
    public function test_check_structure(): void {
        $skill = new generate_questions_skill();
        $this->assertTrue($skill->check_structure([])['valid']);
        $this->assertTrue($skill->check_structure(['count' => 5, 'qtypes' => ['multichoice', 'truefalse']])['valid']);
        $this->assertFalse($skill->check_structure(['count' => 0])['valid']);
        $this->assertFalse($skill->check_structure(['count' => 9999])['valid']);
        $this->assertFalse($skill->check_structure(['qtypes' => ['essay']])['valid']);
    }

    /**
     * Preflight blocks only when neither an uploaded document nor inline content is available.
     */
    public function test_preflight_requires_a_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$contextid, $userid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $store->add_message((int)$thread->id, 'user', 'Please make questions.');

        $result = (new generate_questions_skill())->preflight([], $contextid, $userid)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_NO_SOURCE', $result['issue_codes']);
    }

    /**
     * Preflight passes with inline content and the capability present, without any uploaded document.
     */
    public function test_preflight_passes_with_inline_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', 'Make a question. Correct answer: Bretagne.');

        $input = ['content' => 'Where are we going on holiday this year? The correct answer is Bretagne.'];
        $result = (new generate_questions_skill())->preflight($input, $contextid, (int)$USER->id)->to_array();

        $this->assertSame('pass', $result['status']);
    }

    /**
     * Preflight blocks (Gate 2) when the user lacks moodle/question:add, even with a document.
     */
    public function test_preflight_requires_native_capability(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $contextid = (int)\context_module::instance($page->cmid)->id;

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$student->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$student->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result['issue_codes']);
    }

    /**
     * Preflight passes with a document and the capability present.
     */
    public function test_preflight_passes_with_document_and_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $userid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('pass', $result['status']);
    }

    /**
     * With more than one writable question-bank category, preflight asks where to create the questions.
     */
    public function test_preflight_asks_when_multiple_targets(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * Choosing one of the offered categories lets preflight pass and threads the id into prepared input.
     */
    public function test_preflight_passes_with_chosen_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $categoryids = $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $chosen = (int)$categoryids[0];
        $result = (new generate_questions_skill())->preflight(['target_categoryid' => $chosen], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $this->assertSame($chosen, (int)$result->preparedinput['target_categoryid']);
    }

    /**
     * Naming a category in plain text resolves to its id (the planner never knows the id).
     */
    public function test_preflight_resolves_target_by_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $categoryids = $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        // The add_writable_categories helper names them "Agent category 0", "Agent category 1".
        $result = (new generate_questions_skill())
            ->preflight(['target_category' => 'Agent category 1'], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $this->assertSame((int)$categoryids[1], (int)$result->preparedinput['target_categoryid']);
    }

    /**
     * An unknown category name re-asks with the full list rather than failing silently.
     */
    public function test_preflight_unknown_name_reasks(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())
            ->preflight(['target_category' => 'Does not exist'], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * A target id that is not one of the writable categories is rejected with a fresh clarification.
     */
    public function test_preflight_rejects_unknown_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())
            ->preflight(['target_categoryid' => 99999999], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * Create a run (page module) context plus its course, and return [contextid, course].
     *
     * @return array{0:int,1:\stdClass}
     */
    private function make_run_context_with_course(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return [(int)\context_module::instance($page->cmid)->id, $course];
    }

    /**
     * Add a question bank module to the course with $count writable categories; return their ids.
     *
     * @param \stdClass $course
     * @param int       $count
     * @return int[]
     */
    private function add_writable_categories(\stdClass $course, int $count): array {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $category = $questiongenerator->create_question_category([
                'contextid' => $bankcontext->id,
                'name' => 'Agent category ' . $i,
            ]);
            $ids[] = (int)$category->id;
        }
        return $ids;
    }

    /**
     * No questions / no bank context => no preview block.
     */
    public function test_get_result_preview_returns_null_without_questions(): void {
        $skill = new generate_questions_skill();
        $this->assertNull($skill->get_result_preview([], 1, 1));
        $this->assertNull($skill->get_result_preview(
            ['created_question_ids' => [], 'question_bank_contextid' => 0],
            1,
            1
        ));
    }

    /**
     * A created question is rendered inline (native question rendering) into the preview block.
     */
    public function test_get_result_preview_renders_questions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $bankcontext->id]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'name' => 'Powerhouse of the cell',
        ]);

        $entry = [
            'created_question_ids' => [(int)$question->id],
            'question_bank_contextid' => (int)$bankcontext->id,
            'question_bank_url' => 'https://example.com/question/edit.php?cmid=' . $qbank->cmid,
        ];

        $preview = (new generate_questions_skill())->get_result_preview($entry, (int)$bankcontext->id, (int)$USER->id);

        $this->assertIsArray($preview);
        $this->assertSame('generated_questions', $preview['type']);
        $this->assertNotEmpty($preview['html']);
        // Option A: render-time JS is shipped as a separate string for the client to execute.
        $this->assertArrayHasKey('js', $preview);
        $this->assertIsString($preview['js']);
        // Native question rendering wraps each question in a div.que.
        $this->assertStringContainsString('que ', $preview['html']);
        $this->assertStringContainsString('bookingextension_agent-question-preview', $preview['html']);
        $this->assertSame([(int)$question->id], $preview['payload']['question_ids']);
    }

    /**
     * Create a course + module context and return [contextid, current user id].
     *
     * @return array{0:int,1:int}
     */
    private function make_context_and_user(): array {
        global $USER;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return [(int)\context_module::instance($page->cmid)->id, (int)$USER->id];
    }
}
