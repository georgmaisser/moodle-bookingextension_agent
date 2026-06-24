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
use bookingextension_agent\local\wizard\course\skills\diagnose_enrolment_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the course.diagnose_enrolment skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\diagnose_enrolment_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_enrolment_skill_test extends advanced_testcase {
    /**
     * Metadata: read-only R0, course-scoped.
     */
    public function test_metadata(): void {
        $skill = new diagnose_enrolment_skill();
        $this->assertSame('course.diagnose_enrolment', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
    }

    /**
     * A user without enrolreview cannot run the enrolment diagnosis.
     */
    public function test_gate_blocks_non_reviewer(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($student);

        $result = (new diagnose_enrolment_skill())->execute(['courseid' => (int)$course->id], $coursecontextid, (int)$student->id);
        $this->assertSame('error', $result['status']);
        $this->assertSame('permission_denied', $result['error_class']);
    }

    /**
     * A teacher gets a method overview with at least one enrolment method row.
     */
    public function test_method_overview(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $result = (new diagnose_enrolment_skill())->execute(['courseid' => (int)$course->id], $coursecontextid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['diagnosis']['checklist']);
        $this->assertSame(0, $result['diagnosis']['targetuserid']);
    }

    /**
     * Existing-enrolment reporting: active vs no record.
     */
    public function test_existing_enrolment_states(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $enrolled = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $skill = new diagnose_enrolment_skill();

        $active = $skill->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$enrolled->id],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $active['status']);
        $this->assertStringContainsString('currently enrolled (active)', $active['observation_full']);

        $none = $skill->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$outsider->id],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertStringContainsString('No enrolment record', $none['observation_full']);
    }

    /**
     * Cohort sync: non-membership is flagged as the blocker; membership is OK.
     */
    public function test_cohort_membership(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Ensure the cohort enrolment plugin is enabled site-wide.
        $enabled = explode(',', $CFG->enrol_plugins_enabled);
        if (!in_array('cohort', $enabled, true)) {
            $enabled[] = 'cohort';
            set_config('enrol_plugins_enabled', implode(',', $enabled));
        }

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $member = $this->getDataGenerator()->create_user();
        $nonmember = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $member->id);

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $cohortplugin = enrol_get_plugin('cohort');
        $cohortplugin->add_instance($course, ['customint1' => $cohort->id, 'roleid' => $studentrole->id]);

        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);
        $skill = new diagnose_enrolment_skill();

        $memberresult = $skill->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$member->id],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertStringContainsString('IS a member', $memberresult['observation_full']);

        $nonresult = $skill->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$nonmember->id],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertStringContainsString('NOT a member', $nonresult['observation_full']);
    }
}
