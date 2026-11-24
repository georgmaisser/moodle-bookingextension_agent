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
use context_course;
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;
use bookingextension_agent\local\wizard\services\activities\quiz_question_service;
use bookingextension_agent\local\wizard\services\skill_input_schema_projection;

/**
 * The kind of question source the user chose while the source itself is still missing (input field questionsource).
 *
 * The quiz skills ask "which questions?" with three options. An answer like "make them up yourself" names an option
 * and no topic. The command had no place for that: it came back as {name, addquestions, count} and the skill asked
 * the same question again, word for word (2812: 30 of 40 recorded constructor calls on that answer; in end-to-end runs
 * 2 of 10 and 1 of 9 conversations ended there without a quiz). With the field the next question asks for the missing
 * source only.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\services\activities\quiz_question_service
 * @covers \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 * @covers \bookingextension_agent\local\wizard\course\skills\update_quiz_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_chosen_source_kind_test extends advanced_testcase {
    /** The question with the three options starts like this in course.add_quiz. */
    private const FULL_LEAD = 'Which questions should I add to the quiz?';

    /**
     * One category as the question bank resolver returns it.
     *
     * @return array[]
     */
    private static function categories(): array {
        return [['categoryid' => 7, 'categoryname' => 'Algebra', 'bankname' => 'Course bank', 'questioncount' => 12]];
    }

    /**
     * Course, editing teacher and a quiz in it, acting as the teacher on the course page.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:int}
     */
    private function quiz_course(): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Chapter 1 quiz']);
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);
        return [$course, $teacher, $quiz, (int)$coursecontext->id];
    }

    /**
     * Only the three declared kinds count; anything else is no choice.
     */
    public function test_only_declared_kinds_are_read(): void {
        $this->assertSame(['topic', 'material', 'category'], quiz_question_service::SOURCE_KINDS);
        $this->assertSame('topic', quiz_question_service::chosen_source_kind(['questionsource' => ' Topic ']));
        $this->assertSame('category', quiz_question_service::chosen_source_kind(['questionsource' => 'category']));
        $this->assertSame('', quiz_question_service::chosen_source_kind(['questionsource' => 'whatever']));
        $this->assertSame('', quiz_question_service::chosen_source_kind(['questionsource' => '']));
        $this->assertSame('', quiz_question_service::chosen_source_kind([]));
    }

    /**
     * "Make them up" without a topic: the question asks for the topic and does not offer the options again.
     */
    public function test_topic_asks_for_the_topic_only(): void {
        $content = quiz_question_service::build_source_clarification(self::categories(), self::FULL_LEAD, 'topic');

        $this->assertSame('On which topic should I make up the questions?', $content['message']);
        $this->assertSame([], $content['options']);
    }

    /**
     * "From a document" without the document: the question asks for the material only.
     */
    public function test_material_asks_for_the_material_only(): void {
        $content = quiz_question_service::build_source_clarification(self::categories(), self::FULL_LEAD, 'material');

        $this->assertStringStartsWith('Which material should the questions come from?', $content['message']);
        $this->assertStringContainsString('PDFs stored in the course', $content['message']);
        $this->assertStringNotContainsString('question category', $content['message']);
        $this->assertSame([], $content['options']);
    }

    /**
     * "From a category" without the category: the categories are listed, the other two options are not.
     */
    public function test_category_lists_the_categories_without_the_options(): void {
        $content = quiz_question_service::build_source_clarification(self::categories(), self::FULL_LEAD, 'category');

        $this->assertStringStartsWith('From which question category should I take the questions?', $content['message']);
        $this->assertStringContainsString('Course bank › Algebra (12 question(s))', $content['message']);
        $this->assertStringNotContainsString('make up questions on a topic', $content['message']);
        $this->assertStringNotContainsString('Tell me which option', $content['message']);
        $this->assertSame(
            [['categoryid' => 7, 'category' => 'Algebra', 'bank' => 'Course bank', 'questioncount' => 12]],
            $content['options']
        );
    }

    /**
     * A category was chosen where none exists: the reply says so and names the two sources that remain.
     */
    public function test_category_without_categories_says_so(): void {
        $content = quiz_question_service::build_source_clarification([], self::FULL_LEAD, 'category');

        $this->assertStringStartsWith('There is no question category here', $content['message']);
        $this->assertStringContainsString('topic you name', $content['message']);
        $this->assertSame([], $content['options']);
    }

    /**
     * Without a chosen kind the question is the one with the three options, unchanged.
     */
    public function test_no_kind_keeps_the_full_question(): void {
        $full = quiz_question_service::build_source_clarification(self::categories(), self::FULL_LEAD);

        $this->assertSame($full, quiz_question_service::build_source_clarification(self::categories(), self::FULL_LEAD, ''));
        $this->assertStringStartsWith(self::FULL_LEAD, $full['message']);
        $this->assertStringContainsString('Tell me which option', $full['message']);
    }

    /**
     * course.add_quiz: the same command with the chosen kind gets the topic question, not the options again; with the
     * topic it passes.
     */
    public function test_add_quiz_asks_for_the_topic_after_the_choice(): void {
        $this->resetAfterTest();
        [$course, $teacher, , $ctxid] = $this->quiz_course();
        $skill = new add_quiz_skill();
        $command = ['courseid' => (int)$course->id, 'name' => 'Graz Quiz', 'addquestions' => true, 'count' => 10];

        $first = $skill->preflight($command, $ctxid, (int)$teacher->id);
        $this->assertContains('ADD_QUIZ_QUESTION_SOURCE', $first->issuecodes);
        $this->assertStringContainsString('Tell me which option', (string)$first->issues[0]['message']);

        $second = $skill->preflight($command + ['questionsource' => 'topic'], $ctxid, (int)$teacher->id);
        $this->assertSame('hard_block', $second->status);
        $this->assertContains('ADD_QUIZ_QUESTION_SOURCE', $second->issuecodes);
        $this->assertSame('On which topic should I make up the questions?', (string)$second->issues[0]['message']);

        $third = $skill->preflight($command + ['questionsource' => 'topic', 'content' => 'Graz'], $ctxid, (int)$teacher->id);
        $this->assertNotContains('ADD_QUIZ_QUESTION_SOURCE', $third->issuecodes);
        $this->assertNotSame('hard_block', $third->status);
    }

    /**
     * A chosen kind alone says that questions are wanted: the quiz is not created empty without a word.
     */
    public function test_a_chosen_kind_alone_asks_instead_of_an_empty_quiz(): void {
        $this->resetAfterTest();
        [$course, $teacher, , $ctxid] = $this->quiz_course();

        $result = (new add_quiz_skill())->preflight(
            ['courseid' => (int)$course->id, 'name' => 'Graz Quiz', 'questionsource' => 'topic'],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertSame('hard_block', $result->status);
        $this->assertContains('ADD_QUIZ_QUESTION_SOURCE', $result->issuecodes);
    }

    /**
     * course.update_quiz asks the same narrowed question.
     */
    public function test_update_quiz_asks_for_the_topic_after_the_choice(): void {
        $this->resetAfterTest();
        [, $teacher, $quiz, $ctxid] = $this->quiz_course();
        $skill = new update_quiz_skill();
        $command = ['cmid' => (int)$quiz->cmid, 'addquestions' => true, 'count' => 10];

        $first = $skill->preflight($command, $ctxid, (int)$teacher->id);
        $this->assertContains('UPDATE_QUIZ_QUESTION_SOURCE', $first->issuecodes);
        $this->assertStringContainsString('Tell me which option', (string)$first->issues[0]['message']);

        $second = $skill->preflight($command + ['questionsource' => 'topic'], $ctxid, (int)$teacher->id);
        $this->assertContains('UPDATE_QUIZ_QUESTION_SOURCE', $second->issuecodes);
        $this->assertSame('On which topic should I make up the questions?', (string)$second->issues[0]['message']);
    }

    /**
     * A count with a chosen kind is no structural mismatch: the constructor has said that the source is still open,
     * so the engine's repair round has nothing to repair.
     */
    public function test_a_chosen_kind_needs_no_repair_round(): void {
        $command = ['name' => 'Graz Quiz', 'count' => 10];
        foreach ([new add_quiz_skill(), new update_quiz_skill()] as $skill) {
            $this->assertTrue($skill->check_structure($command + ['questionsource' => 'topic'])['valid']);
            $this->assertFalse($skill->check_structure($command + ['questionsource' => 'whatever'])['valid']);
        }
    }

    /**
     * Both skills show the field to the constructor with its three values and an uncut description (the projection
     * cuts a description at 160 characters).
     */
    public function test_both_skills_show_the_field_uncut(): void {
        foreach ([new add_quiz_skill(), new update_quiz_skill()] as $skill) {
            $line = '';
            foreach (skill_input_schema_projection::for_skill($skill) as $candidate) {
                if (str_starts_with($candidate, 'questionsource ')) {
                    $line = $candidate;
                }
            }
            $this->assertStringStartsWith('questionsource (string, optional, one of: topic, material, category): ', $line);
            $this->assertStringEndsWith('category = existing ones.', $line);
        }
    }
}
