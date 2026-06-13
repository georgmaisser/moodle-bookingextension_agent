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
use grade_item;
use bookingextension_agent\local\wbagent\course\skills\diagnose_grades_skill;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;

/**
 * Tests for the course.diagnose_grades skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wbagent\course\skills\diagnose_grades_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_grades_skill_test extends advanced_testcase {
    /**
     * Create a manual grade item with a grade for a user.
     *
     * @param int $courseid
     * @param int $userid
     * @param float $value
     * @param string $name
     * @return grade_item
     */
    private function make_grade(int $courseid, int $userid, float $value, string $name): grade_item {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = new grade_item([
            'courseid' => $courseid,
            'itemtype' => 'manual',
            'itemname' => $name,
            'grademax' => 100,
            'grademin' => 0,
        ], false);
        $item->insert();
        $item->update_final_grade($userid, $value, 'manual');
        return $item;
    }

    /**
     * Metadata: read-only R0, course-scoped.
     */
    public function test_metadata(): void {
        $skill = new diagnose_grades_skill();
        $this->assertSame('course.diagnose_grades', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
    }

    /**
     * Self-diagnosis surfaces the user's visible grade.
     */
    public function test_self_visible_grade(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->make_grade((int)$course->id, (int)$student->id, 80, 'Manual Item');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($student);

        $result = (new diagnose_grades_skill())->execute(['courseid' => (int)$course->id], $coursecontextid, (int)$student->id);
        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('Manual Item', $result['observation_full']);
        $this->assertStringContainsString('do NOT recompute', $result['observation_full']);
    }

    /**
     * A grade hidden from the student is not revealed on self-diagnosis.
     */
    public function test_self_hidden_grade_not_revealed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $item = $this->make_grade((int)$course->id, (int)$student->id, 80, 'Secret Item');
        $item->set_hidden(1, true);
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($student);

        $result = (new diagnose_grades_skill())->execute(
            ['courseid' => (int)$course->id, 'itemquery' => 'Secret Item'],
            $coursecontextid,
            (int)$student->id
        );
        $this->assertStringContainsString('hidden from the user', $result['observation_full']);
        // The actual value must not leak to the student.
        $this->assertStringNotContainsString('80.00', $result['observation_full']);
    }

    /**
     * Cross-user gate: a student cannot inspect another user's grades; a teacher can.
     */
    public function test_cross_user_gate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($a->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($b->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->make_grade((int)$course->id, (int)$b->id, 55, 'Manual Item');
        $coursecontextid = (int)context_course::instance($course->id)->id;

        $this->setUser($a);
        $blocked = (new diagnose_grades_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id], $coursecontextid, (int)$a->id);
        $this->assertSame('error', $blocked['status']);
        $this->assertSame('permission_denied', $blocked['error_class']);

        $this->setUser($teacher);
        $allowed = (new diagnose_grades_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id], $coursecontextid, (int)$teacher->id);
        $this->assertSame('executed', $allowed['status']);
    }

    /**
     * A hidden gradebook (showgrades off) is flagged.
     */
    public function test_gradebook_hidden_from_students(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['showgrades' => 0]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $result = (new diagnose_grades_skill())->execute(['courseid' => (int)$course->id], $coursecontextid, (int)$teacher->id);
        $this->assertStringContainsString('Gradebook hidden from students', $result['observation_full']);
    }
}
