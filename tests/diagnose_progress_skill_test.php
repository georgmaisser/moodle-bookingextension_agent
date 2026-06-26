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
use completion_info;
use context_course;
use bookingextension_agent\local\wizard\course\skills\diagnose_progress_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the course.diagnose_progress skill (completion/progress diagnosis).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\diagnose_progress_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_progress_skill_test extends advanced_testcase {
    /**
     * Find the first checklist row whose "check" contains a substring.
     *
     * @param array $result
     * @param string $needle
     * @return array<string,mixed>|null
     */
    private function row_containing(array $result, string $needle): ?array {
        foreach ((array)($result['checklist_rows'] ?? []) as $row) {
            if (str_contains((string)($row['check'] ?? ''), $needle)) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Metadata reflects a read-only, course-scoped R0 diagnosis skill.
     */
    public function test_metadata(): void {
        $skill = new diagnose_progress_skill();
        $this->assertSame('course.diagnose_progress', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
    }

    /**
     * A course without completion tracking yields exactly one explanatory warn row, not a misleading
     * "everything open" report.
     */
    public function test_completion_disabled_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $ctxid = (int)context_course::instance($course->id)->id;

        $result = (new diagnose_progress_skill())->execute(['courseid' => (int)$course->id], $ctxid, (int)$student->id);

        $this->assertSame('executed', $result['status']);
        $this->assertCount(1, $result['checklist_rows']);
        $this->assertSame('warn', $result['checklist_rows'][0]['status']);
        $this->assertStringContainsString('Completion tracking disabled', $result['observation_full']);
    }

    /**
     * An automatic "must view" activity is reported as not completed (with the unmet rule) before the
     * user views it, and as completed after — self-diagnosis, no extra capability needed.
     */
    public function test_activity_view_completion_before_and_after(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $ctxid = (int)context_course::instance($course->id)->id;

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Intro Page',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
        ]);

        // Before viewing: not completed, and the unmet rule is surfaced.
        $before = (new diagnose_progress_skill())->execute(['courseid' => (int)$course->id], $ctxid, (int)$student->id);
        $this->assertSame('executed', $before['status']);
        $row = $this->row_containing($before, 'Intro Page');
        $this->assertNotNull($row);
        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('Not completed', $row['finding']);
        $this->assertSame(0, (int)$before['diagnosis']['completed']);
        $this->assertSame(1, (int)$before['diagnosis']['tracked']);

        // Mark the activity viewed → completed.
        $cm = get_fast_modinfo($course, (int)$student->id)->get_cm((int)$page->cmid);
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm, (int)$student->id);

        $after = (new diagnose_progress_skill())->execute(['courseid' => (int)$course->id], $ctxid, (int)$student->id);
        $rowafter = $this->row_containing($after, 'Intro Page');
        $this->assertNotNull($rowafter);
        $this->assertSame('ok', $rowafter['status']);
        $this->assertSame(1, (int)$after['diagnosis']['completed']);
    }

    /**
     * A manual-completion activity that the user has not ticked is reported as not completed with a
     * manual-marking explanation.
     */
    public function test_manual_completion_activity(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $ctxid = (int)context_course::instance($course->id)->id;

        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Manual Page',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $result = (new diagnose_progress_skill())->execute(['courseid' => (int)$course->id], $ctxid, (int)$student->id);
        $row = $this->row_containing($result, 'Manual Page');
        $this->assertNotNull($row);
        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('manually', $row['finding']);
    }

    /**
     * Cross-user gate: a student may not inspect a peer's progress; an editing teacher may.
     */
    public function test_cross_user_gate(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($a->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($b->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        // Student A inspecting student B → denied.
        $blocked = (new diagnose_progress_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id],
            $ctxid,
            (int)$a->id
        );
        $this->assertSame('error', $blocked['status']);
        $this->assertSame('permission_denied', $blocked['error_class']);

        // Teacher inspecting student B → allowed.
        $allowed = (new diagnose_progress_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $allowed['status']);
        $this->assertSame((int)$b->id, (int)$allowed['resultid']);
    }

    /**
     * activityquery narrows the report to a single matching activity.
     */
    public function test_activityquery_focus(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $ctxid = (int)context_course::instance($course->id)->id;

        foreach (['Alpha Task', 'Beta Task'] as $name) {
            $this->getDataGenerator()->create_module('page', [
                'course' => $course->id,
                'name' => $name,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
        }

        $result = (new diagnose_progress_skill())->execute(
            ['courseid' => (int)$course->id, 'activityquery' => 'Alpha'],
            $ctxid,
            (int)$student->id
        );
        $this->assertNotNull($this->row_containing($result, 'Alpha Task'));
        $this->assertNull($this->row_containing($result, 'Beta Task'));
    }
}
