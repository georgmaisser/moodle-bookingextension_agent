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
use bookingextension_agent\local\wizard\course\skills\diagnose_user_in_course_skill;
use context_course;

/**
 * Tests for the consolidated course.diagnose_user_in_course skill (access/enrolment/progress/grades).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\course\skills\diagnose_user_in_course_skill
 */
final class diagnose_user_in_course_skill_test extends advanced_testcase {
    /**
     * Build a course with a teacher, a student and two quizzes + a page.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass}
     */
    private function build_course(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 2, 'enablecompletion' => 1]);
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->create_module('page', ['course' => $course->id, 'section' => 0, 'name' => 'Welcome Page']);
        $gen->create_module('quiz', ['course' => $course->id, 'section' => 1, 'name' => 'Quiz A']);
        $gen->create_module('quiz', ['course' => $course->id, 'section' => 1, 'name' => 'Quiz B']);
        rebuild_course_cache($course->id, true);
        return [$course, $teacher, $student];
    }

    /**
     * access aspect (default) produces an access checklist for the acting user.
     */
    public function test_access_aspect_default_self(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $res = (new diagnose_user_in_course_skill())->execute([], $ctxid, (int)$teacher->id);

        $this->assertSame('executed', $res['status']);
        $this->assertNotEmpty($res['checklist_rows']);
        $this->assertStringContainsString('Access diagnosis', $res['observation_full']);
    }

    /**
     * A unique activity name resolves and is diagnosed.
     */
    public function test_access_aspect_resolves_unique_activity(): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'access', 'userid' => (int)$student->id, 'activityquery' => 'Quiz A'],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertSame('executed', $res['status']);
        $this->assertStringContainsString('Quiz A', $res['observation_full']);
    }

    /**
     * Enumerate-then-reason: an ambiguous activity reference returns the inventory, never a guess.
     */
    public function test_ambiguous_activity_returns_inventory_observation(): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'access', 'userid' => (int)$student->id, 'activityquery' => 'quiz'],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertSame('executed', $res['status']);
        $this->assertStringContainsString('activityid=', $res['observation_full']);
        $this->assertStringContainsString('Quiz A', $res['observation_full']);
        $this->assertStringContainsString('Quiz B', $res['observation_full']);
        $this->assertStringContainsString('do NOT repeat the same activityquery', $res['observation_full']);
        $this->assertTrue(!empty($res['observation_engine_static']));
    }

    /**
     * A resolved activityid is honoured directly (the LLM's second-step pick).
     */
    public function test_activityid_is_honoured(): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;
        $modinfo = get_fast_modinfo($course);
        $quizcmid = 0;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'quiz' && $cm->name === 'Quiz B') {
                $quizcmid = (int)$cm->id;
            }
        }
        $this->assertGreaterThan(0, $quizcmid);

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'access', 'userid' => (int)$student->id, 'activityid' => $quizcmid],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $res['status']);
        $this->assertStringContainsString('Quiz B', $res['observation_full']);
    }

    /**
     * enrolment / progress / grades aspects each run for a teacher (gates satisfied).
     *
     * @dataProvider aspect_provider
     * @param string $aspect
     * @param string $expectedheader
     */
    public function test_other_aspects_run_for_teacher(string $aspect, string $expectedheader): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => $aspect, 'userid' => (int)$student->id],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertSame('executed', $res['status'], $aspect . ' should run for a teacher');
        $this->assertStringContainsString($expectedheader, $res['observation_full']);
    }

    /**
     * Aspect headers.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function aspect_provider(): array {
        return [
            'enrolment' => ['enrolment', 'Enrolment diagnosis'],
            'progress' => ['progress', 'Progress diagnosis'],
            'grades' => ['grades', 'Grades diagnosis'],
        ];
    }

    /**
     * Cross-user access by a student (no role:review) is denied.
     */
    public function test_cross_user_access_denied_for_student(): void {
        $this->resetAfterTest();
        [$course, $teacher, $student] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'access', 'userid' => (int)$teacher->id],
            $ctxid,
            (int)$student->id
        );

        $this->assertSame('error', $res['status']);
        $this->assertSame('permission_denied', $res['error_class']);
    }

    /**
     * Regression (thread 559): a non-matching/spurious activityquery on a progress diagnosis must NOT
     * be reported as "no completion-tracked activities" when the course actually tracks completion.
     */
    public function test_progress_nonmatching_activityquery_does_not_claim_no_tracking(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');
        $CFG->enablecompletion = 1;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        $gen->enrol_user((int)$student->id, (int)$course->id, 'student');
        $gen->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Final Quiz',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        rebuild_course_cache((int)$course->id, true);
        $ctxid = (int)context_course::instance($course->id)->id;

        // A spurious / non-matching activity filter must not hide the real progress.
        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'progress', 'userid' => (int)$student->id, 'activityquery' => 'selflearning'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $res['status']);
        $obs = (string)$res['observation_full'];
        $this->assertStringNotContainsString('No completion-tracked activities', $obs);
        $this->assertStringContainsString('No completion-tracked activity matches', $obs);
        $this->assertStringContainsString('Final Quiz', $obs);

        // Without a filter, the tracked quiz is reported course-wide.
        $res2 = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'progress', 'userid' => (int)$student->id],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertStringContainsString('Final Quiz', (string)$res2['observation_full']);
    }
}
