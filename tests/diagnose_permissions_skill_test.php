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
use bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the core.diagnose_permissions skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_permissions_skill_test extends advanced_testcase {
    /**
     * Metadata: read-only R0.
     */
    public function test_metadata(): void {
        $skill = new diagnose_permissions_skill();
        $this->assertSame('core.diagnose_permissions', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
    }

    /**
     * Role mode: a student's role is listed at the course context (self-diagnosis).
     */
    public function test_role_mode_self(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($student);

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id],
            $coursecontextid,
            (int)$student->id
        );
        $this->assertSame('executed', $result['status']);
        $this->assertSame('roles', $result['diagnosis']['mode']);
        $this->assertStringContainsString('student', $result['observation_full']);
    }

    /**
     * Capability mode: teacher HAS manageactivities, student does NOT — with person-correct
     * verbs: second person for self checks ("You HAVE / do NOT have"), third person for
     * cross-user checks ("<Name> HAS / does NOT have").
     */
    public function test_capability_mode(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $skill = new diagnose_permissions_skill();

        $this->setUser($teacher);
        $teacherresult = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('capability', $teacherresult['diagnosis']['mode']);
        $this->assertStringContainsString(
            'You HAVE moodle/course:manageactivities',
            $teacherresult['observation_full']
        );

        $this->setUser($student);
        $studentresult = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$student->id
        );
        $this->assertStringContainsString(
            'You do NOT have moodle/course:manageactivities',
            $studentresult['observation_full']
        );

        // Cross-user check (third person) — as admin, holding moodle/role:review everywhere.
        $this->setAdminUser();
        global $USER;
        $adminresult = $skill->execute(
            [
                'courseid' => (int)$course->id,
                'userid' => (int)$student->id,
                'capability' => 'moodle/course:manageactivities',
            ],
            $coursecontextid,
            (int)$USER->id
        );
        $this->assertStringContainsString(
            fullname($student) . ' does NOT have moodle/course:manageactivities',
            $adminresult['observation_full']
        );
    }

    /**
     * Unknown capability returns suggestions rather than a hard failure.
     */
    public function test_unknown_capability_suggestions(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:managactivities'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('unknown_capability', $result['diagnosis']['mode']);
        $this->assertStringContainsString('Unknown capability', $result['observation_full']);
    }

    /**
     * Cross-user gate: a student cannot review another user's permissions.
     */
    public function test_cross_user_gate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($a->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($b->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($a);

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id],
            $coursecontextid,
            (int)$a->id
        );
        $this->assertSame('error', $result['status']);
        $this->assertSame('permission_denied', $result['error_class']);
    }
}
