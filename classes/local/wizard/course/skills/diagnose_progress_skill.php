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

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use completion_info;
use context;
use context_course;
use core_completion\cm_completion_details;

/**
 * Readonly diagnosis skill: collect the facts behind "why has this user not completed the course /
 * these activities?". A FACTS COLLECTOR (like course.diagnose_grades), not a completion engine: it
 * reports, per activity, the stored completion state and the unmet completion RULES (view / grade /
 * passing grade / activity-specific / manual), plus the course-completion criteria — and tells the
 * model to explain only from those facts, never to re-evaluate completion itself.
 *
 * Scope boundary: completion/progress only — NOT grades (course.diagnose_grades), NOT visibility or
 * availability (course.diagnose_access), NOT enrolment or permissions. R0/readonly → all guards live
 * in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_progress_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'course.diagnose_progress';

    /** Hard cap on activities reported (observation-size discipline). */
    private const MAX_ITEMS = 30;

    /**
     * Constructor. Read-only diagnosis (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Course-scoped.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }

    /**
     * Read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return true;
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Collect the facts behind a "why is the course/activity not completed" question for a '
                . 'person in a course: per activity the stored completion state and the UNMET completion rules '
                . '(must view / receive a grade / pass / activity-specific / manual), plus the course-completion '
                . 'criteria and whether the course is marked complete. It does NOT re-evaluate completion — it '
                . 'reports stored facts for the model to explain. Use for "why has Maria not completed the course", '
                . '"why is this activity not marked complete". NOT '
                . 'for grades, access/visibility, enrolment or permissions.',
            'readonly' => true,
            'example_utterances' => [
                'why hasn\'t Maria completed the course',
                'which activities does this student still need to finish',
                'why is this activity not marked as complete',
                'what completion criteria are still unmet for this learner',
                'the course shows as not completed for this user',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or id of the person. "me" or empty = the current user. '
                        . 'Resolve ambiguous names via core.search_users.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known. Takes precedence over userquery.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Course name when not the current course. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Leave empty for the current course; never guess.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: the name of a specific activity (e.g. "Quiz 3"). Leave empty for an '
                        . 'overview of all completion-tracked activities.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['userquery', 'coursequery', 'activityquery'],
                'anchor_fields' => ['userquery', 'coursequery', 'activityquery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return ['userquery' => 'Maria Jones'];
    }

    /**
     * Discovery triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.diagnose_progress_request',
                'description' => 'User asks about a person\'s progress/completion in a course: which activities are '
                    . 'done or still open, and why an activity or the course is not yet completed. Not grades, '
                    . 'access/visibility, enrolment or permissions.',
                'examples' => [
                    'Why has Maria not completed the course yet?',
                    'Which activities does Tom still have to finish?',
                    'Why is this activity not marked as complete?',
                    'How far is the student in the course — what is still open?',
                ],
            ],
        ];
    }

    /**
     * Contextual guidance.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.diagnose_progress',
                'triggers' => [
                    'progress', 'completion', 'completed', 'incomplete', 'not completed',
                    'open activities', 'still open', 'course completion',
                    'why not complete', 'activity not complete',
                ],
                'guidance' => [
                    '- course.diagnose_progress collects completion FACTS (read-only): per activity the stored',
                    '  completion state and the UNMET rules (view/grade/passing grade/activity-specific/manual),',
                    '  plus the course-completion criteria and whether the course is marked complete.',
                    '- It does NOT re-evaluate completion. Explain strictly from the listed facts; never decide',
                    '  completion yourself.',
                    '- Name the activity via activityquery when the question is about one item. Use',
                    '  course.diagnose_grades for grade details and course.diagnose_access for visibility/availability.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure).
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Run the progress/completion diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        // 1) Resolve course.
        $courseid = (int)($input['courseid'] ?? 0);
        if ($courseid <= 0) {
            $courseid = $this->resolve_courseid($input);
        }
        if ($courseid <= 0) {
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            $coursecontext = $context ? $context->get_course_context(false) : false;
            if ($coursecontext) {
                $courseid = (int)$coursecontext->instanceid;
            }
        }
        if ($courseid <= 0) {
            return $this->error_result('Please tell me which course (by name), or open the course first.', 'missing_course');
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->error_result('That course could not be found.', 'course_not_found');
        }
        $coursecontext = context_course::instance($courseid);

        // 2) Resolve the target user, defaulting to self.
        $targetuserid = (int)($input['userid'] ?? 0);
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, $userid);
        }
        if ($targetuserid <= 0) {
            return $this->error_result(
                'I could not identify the person. Give a name, e-mail or id — or resolve via core.search_users.',
                'user_unresolved'
            );
        }
        $isself = ($targetuserid === $userid);

        // 3) Gate: viewing another user's progress needs the activity-completion report capability; a
        // self-request only reports what the user already sees in their own progress.
        if (!$isself && !has_capability('report/progress:view', $coursecontext, $userid)) {
            return $this->error_result(get_string('nopermissions', 'error', 'report/progress:view'), 'permission_denied');
        }

        $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        if (!$targetuser || !empty($targetuser->deleted)) {
            return $this->error_result('That user no longer exists.', 'user_not_found');
        }

        $links = new diagnostic_link_builder();
        $rows = [];

        // 4) Completion enabled for the course?
        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Completion tracking disabled',
                'This course does not track completion, so there is no per-activity or course completion to report.',
                $links->completion_settings($courseid)
            );
            return $this->build_result($course, $courseid, $targetuser, $isself, $rows, 0, 0, null);
        }

        // The target's role may not be tracked for completion (e.g. teachers/managers).
        if (!$completion->is_tracked_user($targetuserid)) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Completion not tracked for this user',
                'Completion is only tracked for roles configured as tracked (usually students); the figures below '
                    . 'may therefore be empty for this person.',
                $links->course_completion_report($courseid)
            );
        }

        // 5) Per-activity completion (visibility = Moodle engine, computed for the target user).
        $activityquery = \core_text::strtolower(trim((string)($input['activityquery'] ?? '')));
        $modinfo = get_fast_modinfo($course, $targetuserid);
        $tracked = 0;
        $done = 0;
        $shown = 0;
        foreach ($modinfo->get_cms() as $cm) {
            if ((int)$cm->completion === COMPLETION_TRACKING_NONE) {
                continue; // No completion tracking on this activity.
            }
            $name = (string)$cm->get_formatted_name();
            if ($activityquery !== '' && !str_contains(\core_text::strtolower($name), $activityquery)) {
                continue;
            }

            // An activity the target cannot reach because of an access restriction is a completion
            // blocker too — surface it and point at the access diagnosis (no recompute of restrictions).
            if (!$cm->uservisible) {
                if ($cm->visible && !empty($cm->availableinfo)) {
                    $rows[] = diagnostic_result_builder::row(
                        'warn',
                        'Activity "' . $name . '": blocked by an access restriction',
                        'The user cannot access this activity yet (access conditions not met), so it cannot be '
                            . 'completed. Use course.diagnose_access for the restriction details.',
                        $links->activity($cm->modname, $cm->id)
                    );
                }
                continue;
            }

            $tracked++;
            if ($shown >= self::MAX_ITEMS) {
                continue; // Keep counting totals, but stop emitting rows (observation discipline).
            }
            $shown++;
            [$row, $iscomplete] = $this->activity_row($completion, $cm, $targetuserid, $name, $links);
            if ($iscomplete) {
                $done++;
            }
            $rows[] = $row;
        }

        if ($tracked > self::MAX_ITEMS) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'More activities exist',
                'Only the first ' . self::MAX_ITEMS . ' completion-tracked activities are shown; name a specific '
                    . 'activity (activityquery) to narrow down.'
            );
        }
        if ($tracked === 0) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No completion-tracked activities',
                'No visible activity in this course has completion tracking enabled.'
            );
        }

        // 6) Course-completion criteria + overall course completion.
        $this->append_course_completion_rows($completion, $targetuserid, $courseid, $links, $rows);

        return $this->build_result($course, $courseid, $targetuser, $isself, $rows, $tracked, $done, $links);
    }

    /**
     * Build one checklist row for an activity's completion state (+ the unmet rules behind it).
     *
     * @param completion_info $completion
     * @param \cm_info $cm
     * @param int $targetuserid
     * @param string $name
     * @param diagnostic_link_builder $links
     * @return array{0:array<string,mixed>,1:bool}  [row, iscomplete]
     */
    private function activity_row(
        completion_info $completion,
        \cm_info $cm,
        int $targetuserid,
        string $name,
        diagnostic_link_builder $links
    ): array {
        $url = $links->activity($cm->modname, $cm->id);
        $details = new cm_completion_details($completion, $cm, $targetuserid);
        $state = $details->get_overall_completion();

        $iscomplete = in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
        if ($iscomplete) {
            $finding = $state === COMPLETION_COMPLETE_PASS ? 'Completed (passing grade achieved).' : 'Completed.';
            return [diagnostic_result_builder::row('ok', 'Activity "' . $name . '"', $finding, $url), true];
        }

        if ($state === COMPLETION_COMPLETE_FAIL) {
            $finding = 'Marked complete but the required passing grade was NOT achieved — '
                . 'use course.diagnose_grades for the grade details.';
            return [diagnostic_result_builder::row('fail', 'Activity "' . $name . '"', $finding, $url), false];
        }

        // Incomplete: explain WHY from the configured rules.
        if ($details->is_manual()) {
            $finding = 'Not completed — this activity is marked complete manually by the user (self-marking).';
            return [diagnostic_result_builder::row('fail', 'Activity "' . $name . '"', $finding, $url), false];
        }

        $unmet = [];
        foreach ($details->get_details() as $detail) {
            $rstatus = (int)($detail->status ?? COMPLETION_INCOMPLETE);
            if (!in_array($rstatus, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                $unmet[] = trim((string)($detail->description ?? ''));
            }
        }
        $unmet = array_values(array_filter($unmet));
        $finding = empty($unmet)
            ? 'Not completed.'
            : 'Not completed — unmet: ' . implode('; ', $unmet) . '.';

        return [diagnostic_result_builder::row('fail', 'Activity "' . $name . '"', $finding, $url), false];
    }

    /**
     * Append the course-completion criteria rows and the overall course-completion row.
     *
     * @param completion_info $completion
     * @param int $targetuserid
     * @param int $courseid
     * @param diagnostic_link_builder $links
     * @param array<int,array<string,mixed>> $rows  (by reference)
     * @return void
     */
    private function append_course_completion_rows(
        completion_info $completion,
        int $targetuserid,
        int $courseid,
        diagnostic_link_builder $links,
        array &$rows
    ): void {
        $criteria = $completion->get_criteria();
        if (empty($criteria)) {
            return;
        }

        $url = $links->course_completion_report($courseid);
        $completions = $completion->get_completions($targetuserid);
        foreach ($completions as $cc) {
            try {
                $title = (string)$cc->get_criteria()->get_title();
                $met = (bool)$cc->is_complete();
            } catch (\Throwable $e) {
                continue;
            }
            $rows[] = diagnostic_result_builder::row(
                $met ? 'ok' : 'fail',
                'Course criterion: ' . $title,
                $met ? 'Met.' : 'Not met yet.',
                $url
            );
        }

        $coursecomplete = $completion->is_course_complete($targetuserid);
        $rows[] = diagnostic_result_builder::row(
            $coursecomplete ? 'ok' : 'fail',
            'Overall course completion',
            $coursecomplete ? 'The course is marked complete for this user.' : 'The course is not yet complete.',
            $url
        );
    }

    /**
     * Assemble the result.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param array<int,array<string,mixed>> $rows
     * @param int $tracked
     * @param int $done
     * @param diagnostic_link_builder|null $links
     * @return array<string,mixed>
     */
    private function build_result(
        \stdClass $course,
        int $courseid,
        \stdClass $targetuser,
        bool $isself,
        array $rows,
        int $tracked,
        int $done,
        ?diagnostic_link_builder $links
    ): array {
        $subject = $isself ? 'you' : fullname($targetuser);
        $coursename = format_string($course->fullname);

        $header = 'Progress diagnosis for ' . $subject . ' in course "' . $coursename . '" (id=' . $courseid . '):';
        if ($tracked > 0) {
            $header .= ' ' . $done . ' of ' . $tracked . ' completion-tracked activities complete.';
        }

        $lines = [$header];
        foreach ($rows as $r) {
            $glyph = diagnostic_result_builder::glyph((string)$r['status']);
            $line = $glyph . ' ' . $r['check'];
            if (trim((string)$r['finding']) !== '') {
                $line .= ' — ' . $r['finding'];
            }
            if (!empty($r['url'])) {
                $line .= ' (' . $r['url'] . ')';
            }
            $lines[] = $line;
        }
        $lines[] = 'Note: these are stored completion FACTS. Explain the situation only from them; do NOT re-evaluate '
            . 'completion or decide it yourself.';

        $usermessage = 'Progress check for ' . $subject . ' in "' . $coursename . '" completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$targetuser->id,
            'diagnosis' => [
                'courseid' => $courseid,
                'targetuserid' => (int)$targetuser->id,
                'tracked' => $tracked,
                'completed' => $done,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => 'Progress check: ' . $subject . ' · ' . $coursename,
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * Render the checklist preview.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $rows = (array)($resultentry['checklist_rows'] ?? []);
        if (empty($rows)) {
            return null;
        }
        return (new diagnostic_checklist_preview())->render(
            $rows,
            (string)($resultentry['checklist_title'] ?? ''),
            ['courseid' => (int)($resultentry['diagnosis']['courseid'] ?? 0)]
        );
    }

    /**
     * Build an error result.
     *
     * @param string $message
     * @param string $errorclass
     * @return array<string,mixed>
     */
    private function error_result(string $message, string $errorclass): array {
        return diagnostic_result_builder::error_result($message, $errorclass, 'Progress diagnosis could not run: ');
    }
}
