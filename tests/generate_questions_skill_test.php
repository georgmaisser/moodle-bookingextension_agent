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
use bookingextension_agent\local\wbagent\core\skills\generate_questions_skill;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;

/**
 * Contract tests for the generate_questions core skill (deterministic parts).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wbagent\core\skills\generate_questions_skill
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
        $this->assertSame('core.generate_questions', $skill->get_name());
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
     * Preflight blocks when no document has been uploaded into the conversation.
     */
    public function test_preflight_requires_a_document(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$contextid, $userid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $store->add_message((int)$thread->id, 'user', 'Please make questions.');

        $result = (new generate_questions_skill())->preflight([], $contextid, $userid)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_NO_DOCUMENT', $result['issue_codes']);
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
