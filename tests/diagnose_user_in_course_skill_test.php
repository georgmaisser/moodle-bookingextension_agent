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
     * @return array
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
     * userquery="me" resolves to the ACTING user instead of failing with "could not identify"
     * (regression: the current-user fallback was hardcoded to 0).
     */
    public function test_userquery_me_resolves_to_acting_user(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->build_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['userquery' => 'me'],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertSame('executed', $res['status']);
        $this->assertNotEmpty($res['checklist_rows']);
        $this->assertStringContainsString('Access diagnosis', $res['observation_full']);
        $this->assertStringNotContainsString('could not identify', (string)($res['detail'] ?? ''));
    }

    /**
     * At a course-less context (e.g. the dashboard / navbar) a non-enrolment aspect for a NAMED
     * person must NOT hard-fail with "which course?": it returns a course clarification
     * (status 'executed') listing the person's courses so a "for each course" request can fan out.
     * Regression guard: a hard error here marked an otherwise-successful multi-course turn as failed
     * and got its correct answer discarded.
     */
    public function test_missing_course_returns_clarification_not_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $student] = $this->build_course();
        $systemctxid = (int)\context_system::instance()->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'progress', 'userid' => (int)$student->id],
            $systemctxid,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $res['status']);
        $this->assertArrayHasKey('course_clarification', $res);
        $this->assertStringContainsString($course->fullname, $res['observation_full']);
        $this->assertStringContainsString('per course', $res['observation_full']);
        $this->assertTrue(!empty($res['observation_engine_static']));
    }

    /**
     * Course-less context, person with no enrolments: reports there is no course to check —
     * still a plain observation, never a poisoning hard error.
     */
    public function test_missing_course_no_enrolments_reports_none(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $loner = $this->getDataGenerator()->create_user();
        $systemctxid = (int)\context_system::instance()->id;

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'grades', 'userid' => (int)$loner->id],
            $systemctxid,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $res['status']);
        $this->assertStringContainsString('not enrolled in any course', $res['observation_full']);
    }

    /**
     * The no-course enrolment overview scopes to the courses the actor may access:
     * a teacher must not learn a target user's unrelated enrolments.
     */
    public function test_no_course_overview_scopes_to_actor_visible_courses(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $shared = $gen->create_course(['fullname' => 'Shared Course']);
        $unshared = $gen->create_course(['fullname' => 'Unshared Course XYZZY']);
        $teacher = $gen->create_user();
        $target = $gen->create_user();
        $gen->enrol_user($teacher->id, $shared->id, 'editingteacher');
        $gen->enrol_user($target->id, $shared->id, 'student');
        $gen->enrol_user($target->id, $unshared->id, 'student');

        $this->setUser($teacher);
        // No course named + a specific user → the no-course enrolment overview path.
        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'enrolment', 'userid' => (int)$target->id],
            (int)\context_system::instance()->id,
            (int)$teacher->id
        );

        $this->assertSame('executed', $res['status']);
        $courseids = array_map(
            static fn($c): int => (int)$c['courseid'],
            (array)($res['enrolment_overview']['courses'] ?? [])
        );
        $this->assertContains((int)$shared->id, $courseids, 'The teacher must see the course they share.');
        $this->assertNotContains(
            (int)$unshared->id,
            $courseids,
            'The teacher must NOT see the target user\'s unrelated course.'
        );
        $this->assertStringNotContainsString(
            'XYZZY',
            (string)$res['observation_full'],
            'The unrelated course name must not leak into the observation.'
        );
    }

    /**
     * A site admin (site-wide viewer) still sees the target user's full enrolment list.
     */
    public function test_no_course_overview_full_for_admin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        $target = $gen->create_user();
        $gen->enrol_user($target->id, $c1->id, 'student');
        $gen->enrol_user($target->id, $c2->id, 'student');

        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'enrolment', 'userid' => (int)$target->id],
            (int)\context_system::instance()->id,
            (int)get_admin()->id
        );

        $courseids = array_map(
            static fn($c): int => (int)$c['courseid'],
            (array)($res['enrolment_overview']['courses'] ?? [])
        );
        $this->assertContains((int)$c1->id, $courseids);
        $this->assertContains((int)$c2->id, $courseids);
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
     * @return array
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
     * Regression (thread 559): a spurious/non-matching activityquery on a progress diagnosis must NOT
     * collapse into a false "no completion-tracked activities" verdict when the course actually tracks
     * completion. The consolidated skill resolves the activity reference first (enumerate-then-reason):
     * a non-matching name returns the course inventory with an unambiguous activityid re-call instruction
     * — naming the real tracked activity — instead of ever reaching the diagnoser's no-activity branch.
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

        // A spurious / non-matching activity filter must not hide the real progress: the skill hands back
        // the inventory (naming the tracked activity), never the false "no completion-tracked activities".
        $res = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'progress', 'userid' => (int)$student->id, 'activityquery' => 'selflearning'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $res['status']);
        $obs = (string)$res['observation_full'];
        $this->assertStringNotContainsString('No completion-tracked activities', $obs);
        $this->assertStringContainsString('Final Quiz', $obs);
        $this->assertStringContainsString('do NOT conclude the activity does not exist', $obs);
        $this->assertTrue(!empty($res['observation_engine_static']));

        // Without a filter, the tracked quiz is reported course-wide by the progress diagnoser.
        $res2 = (new diagnose_user_in_course_skill())->execute(
            ['aspect' => 'progress', 'userid' => (int)$student->id],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertStringContainsString('Progress diagnosis', (string)$res2['observation_full']);
        $this->assertStringContainsString('Final Quiz', (string)$res2['observation_full']);
    }
}
