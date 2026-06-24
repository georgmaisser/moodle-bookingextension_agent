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
use bookingextension_agent\local\wizard\course\skills\diagnose_access_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the course.diagnose_access skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\diagnose_access_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_access_skill_test extends advanced_testcase {
    /**
     * Metadata: read-only R0, course-scoped.
     */
    public function test_metadata(): void {
        $skill = new diagnose_access_skill();
        $this->assertSame('course.diagnose_access', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
    }

    /**
     * Self-diagnosis of an enrolled user passes and yields a checklist + preview.
     */
    public function test_self_diagnosis_enrolled(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($student);

        $skill = new diagnose_access_skill();
        $result = $skill->execute(['courseid' => (int)$course->id], $coursecontextid, (int)$student->id);

        $this->assertSame('executed', $result['status']);
        $this->assertTrue($result['diagnosis']['isselfdiagnosis']);
        $this->assertStringContainsString('Enrolled and active', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $coursecontextid, (int)$student->id);
        $this->assertIsArray($preview);
        $this->assertSame('diagnostic_checklist', $preview['type']);
    }

    /**
     * A student cannot diagnose another user's access (cross-user gate).
     */
    public function test_cross_user_gate_blocks_student(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($a->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($b->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($a);

        $result = (new diagnose_access_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id],
            $coursecontextid,
            (int)$a->id
        );
        $this->assertSame('error', $result['status']);
        $this->assertSame('permission_denied', $result['error_class']);
    }

    /**
     * An editing teacher may diagnose another user's access.
     */
    public function test_teacher_diagnoses_student(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $result = (new diagnose_access_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$student->id],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $result['status']);
        $this->assertFalse($result['diagnosis']['isselfdiagnosis']);
        $this->assertSame((int)$student->id, $result['diagnosis']['targetuserid']);
    }

    /**
     * A hidden activity is reported as not visible to the target user.
     */
    public function test_hidden_activity_not_visible(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Secret Page', 'visible' => 0]);
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $result = (new diagnose_access_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$student->id, 'activityquery' => 'Secret Page'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('NOT visible', $result['observation_full']);
    }

    /**
     * Not enrolled is reported as a blocker; course resolves from the ambient context too.
     */
    public function test_not_enrolled_from_ambient_context(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        // No courseid in input → resolved from the ambient course context.
        $result = (new diagnose_access_skill())->execute(
            ['userid' => (int)$outsider->id],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('Not enrolled', $result['observation_full']);
    }
}
