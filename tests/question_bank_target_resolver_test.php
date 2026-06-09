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
use bookingextension_agent\local\wbagent\services\questions\question_bank_target_resolver;
use bookingextension_agent\local\wbagent\services\questions\question_import_service;

/**
 * Tests for the course question bank target resolver.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wbagent\services\questions\question_bank_target_resolver
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_bank_target_resolver_test extends advanced_testcase {
    /**
     * The resolver returns the course's qbank module context and is idempotent.
     */
    public function test_resolves_course_question_bank_idempotently(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ambient = \context_module::instance($page->cmid);

        $resolver = new question_bank_target_resolver();
        $r1 = $resolver->resolve_for_context($ambient);

        $this->assertInstanceOf(\context_module::class, $r1['context']);
        $this->assertSame('qbank', $r1['cm']->modname);
        $this->assertSame((int)$course->id, (int)$r1['course']->id);

        $r2 = $resolver->resolve_for_context($ambient);
        $this->assertSame((int)$r1['context']->id, (int)$r2['context']->id);
    }

    /**
     * The resolved bank accepts a GIFT import end to end.
     */
    public function test_resolved_bank_accepts_import(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ambient = \context_module::instance($page->cmid);

        $target = (new question_bank_target_resolver())->resolve_for_context($ambient);
        $result = (new question_import_service())->import_gift(
            "::Sky:: The sky is blue. {TRUE}\n",
            $target['context'],
            $target['course']
        );

        $this->assertTrue($result['success'], $result['errors']);
        $this->assertSame(1, $result['imported']);
    }
}
