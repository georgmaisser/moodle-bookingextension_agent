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

use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use context;
use context_course;

/**
 * Readonly diagnosis skill: explain why a person can (not) access a course or one of its activities.
 *
 * Aggregates the access-relevant facts Moodle already computes — course visibility, enrolment status,
 * roles, per-activity uservisible/availableinfo for the TARGET user, and group-mode membership — into a
 * checklist. It never re-implements the availability engine: $cm->availableinfo carries the human-readable,
 * already-permission-respecting reason (so a "do not show" condition stays hidden).
 *
 * R0/readonly: the engine skips preflight for readonly skills, so course/user resolution AND the cross-user
 * capability gate all live in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_access_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'course.diagnose_access';

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
     * Operates on a course (resolved from input/ambient inside execute, since R0 skips preflight).
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
            'description' => 'Explain why a person can or cannot ACCESS / SEE / OPEN a course or one of its '
                . 'activities (not: cannot BOOK — that is mod_booking.diagnose_booking_issue). Checks course '
                . 'visibility, enrolment (incl. suspended/expired), role, the activity\'s visibility for that user '
                . '(with the real "not available until …" reason), and group-mode membership. Use for "why can\'t '
                . 'Maria see the quiz", "warum kommt Tom nicht in den Kurs", "why is this activity greyed out".',
            'readonly' => true,
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or id of the person to check. "me"/"ich" = current user. Leave empty '
                        . 'to diagnose yourself. If a name is ambiguous, call core.search_users first and pass userid.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known. Takes precedence over userquery.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Course name to check, when not the current course. Resolve via course.search_courses '
                        . 'first if only the name is known. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Leave empty for the current course; never guess.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: the name of a specific activity the person cannot see/open (e.g. '
                        . '"Quiz 3"). Leave empty to get a course-wide access overview.',
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
        return ['userquery' => 'Maria Muster', 'activityquery' => 'Quiz 3'];
    }

    /**
     * Discovery triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.diagnose_access_request',
                'description' => 'User asks why a person can NOT access / see / open / reach a course or one of its '
                    . 'activities (visibility, enrolment, role, availability restriction, group access) — NOT why they '
                    . 'cannot book a booking option.',
                'examples' => [
                    'Warum sieht Maria das Quiz 3 nicht?',
                    'Tom kommt nicht in den Kurs "Mathematik" — warum?',
                    'Why is the assignment greyed out for this student?',
                    'Warum kann ich diese Aktivität nicht öffnen?',
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
                'id' => 'course.diagnose_access',
                'triggers' => [
                    'sieht nicht', 'kann nicht sehen', 'kann nicht öffnen', 'kommt nicht in den kurs',
                    'kein zugriff', 'zugriffsproblem', 'nicht verfügbar', 'ausgegraut', 'gesperrt',
                    'cannot see', 'can\'t see', 'cannot open', 'cannot access', 'no access', 'greyed out',
                    'not available', 'why can\'t', 'warum sieht', 'warum kann',
                ],
                'guidance' => [
                    '- course.diagnose_access explains ACCESS/visibility problems for a course or activity (read-only).',
                    '- Use it for "cannot see / open / access / reach", NOT for "cannot book" (that is',
                    '  mod_booking.diagnose_booking_issue) and NOT for grade questions (course.diagnose_grades).',
                    '- Identify the person via userquery/userid (default: the asking user). Name a specific activity',
                    '  via activityquery only when the question is about one activity; otherwise omit it.',
                    '- Answer strictly from the returned checklist findings; do not infer access rules yourself.',
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
     * Run the access diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid Ambient context.
     * @param int   $userid    Acting user.
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        // 1) Resolve the target course: explicit id > coursequery > ambient course.
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
            return $this->error_result(
                'Please tell me which course to check (by name), or open the course first.',
                'missing_course'
            );
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->error_result('That course could not be found.', 'course_not_found');
        }
        $coursecontext = context_course::instance($courseid);

        // 2) Resolve the target user: explicit id > userquery > self.
        $targetuserid = (int)($input['userid'] ?? 0);
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, $userid);
        }
        if ($targetuserid <= 0) {
            return $this->error_result(
                'I could not identify the person. Give a name, e-mail or id — or resolve it with core.search_users.',
                'user_unresolved'
            );
        }
        $isself = ($targetuserid === $userid);

        // 3) Cross-user gate (R0 → enforced here): inspecting another person's access/roles needs
        // role:review (held by editing teachers/managers, not by students — viewparticipants is too weak).
        if (!$isself && !has_capability('moodle/role:review', $coursecontext, $userid)) {
            return $this->error_result(
                get_string('nopermissions', 'error', 'moodle/role:review'),
                'permission_denied'
            );
        }

        // Full record so fullname() has every name field it expects (avoids a debugging() warning).
        $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        if (!$targetuser || !empty($targetuser->deleted)) {
            return $this->error_result('That user no longer exists.', 'user_not_found');
        }
        $targetname = fullname($targetuser);

        $links = new diagnostic_link_builder();
        $rows = [];

        // Check 1: course visibility.
        if ((int)$course->visible === 1) {
            $rows[] = diagnostic_result_builder::row('ok', 'Course is visible', format_string($course->fullname), $links->course($courseid));
        } else {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Course is hidden',
                'The course is set to hidden; only users with "view hidden courses" can enter.',
                $links->course($courseid)
            );
        }

        // Check 2: enrolment.
        $activeenrolled = is_enrolled($coursecontext, $targetuserid, '', true);
        $anyenrolled = is_enrolled($coursecontext, $targetuserid, '', false);
        if ($activeenrolled) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'Enrolled and active in the course',
                '',
                $links->if_capable($links->enrolled_users($courseid), 'moodle/course:enrolreview', $coursecontext, $userid)
            );
        } else if ($anyenrolled) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Enrolled but not active',
                'The enrolment is suspended or expired — a common "was enrolled once" cause. '
                    . 'Use course.diagnose_enrolment for the enrolment-method details.',
                $links->if_capable($links->enrol_instances($courseid), 'moodle/course:enrolreview', $coursecontext, $userid)
            );
        } else {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Not enrolled in the course',
                'No active or inactive enrolment found for this person.',
                $links->if_capable($links->enrol_instances($courseid), 'moodle/course:enrolreview', $coursecontext, $userid)
            );
        }

        // Check 3: role in the course.
        $roles = get_user_roles($coursecontext, $targetuserid, true);
        if (!empty($roles)) {
            $rolenames = array_values(array_unique(array_map(static fn($r): string => (string)$r->shortname, $roles)));
            $rows[] = diagnostic_result_builder::row('ok', 'Has a role in the course', implode(', ', $rolenames));
        } else {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No role in the course',
                'The person has no role here; depending on setup this can limit what they can do.'
            );
        }

        // Check 4: activity visibility for the TARGET user (course-wide overview or a named activity).
        $modinfo = get_fast_modinfo($course, $targetuserid);
        $activityquery = trim((string)($input['activityquery'] ?? ''));
        if ($activityquery !== '') {
            $rows[] = $this->activity_visibility_row($modinfo, $activityquery, $links);
        } else {
            $rows[] = $this->activity_overview_row($modinfo);
        }

        // Check 5: group mode + membership.
        $rows[] = $this->group_row($course, $courseid, $targetuserid, $links);

        return $this->build_result($course, $courseid, $coursecontext, $targetuserid, $targetname, $isself, $rows, $userid, $links);
    }

    /**
     * Build the row for a specific named activity's visibility.
     *
     * @param \course_modinfo $modinfo
     * @param string $activityquery
     * @param diagnostic_link_builder $links
     * @return array<string,mixed>
     */
    private function activity_visibility_row(
        \course_modinfo $modinfo,
        string $activityquery,
        diagnostic_link_builder $links
    ): array {
        $needle = \core_text::strtolower($activityquery);
        $matches = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (str_contains(\core_text::strtolower($cm->name), $needle)) {
                $matches[] = $cm;
            }
        }
        if (empty($matches)) {
            return diagnostic_result_builder::row(
                'warn',
                'Activity "' . $activityquery . '" not found',
                'No activity with that name in this course (for this user).'
            );
        }
        if (count($matches) > 1) {
            $names = array_map(static fn($cm): string => $cm->name, array_slice($matches, 0, 5));
            return diagnostic_result_builder::row(
                'warn',
                'Several activities match "' . $activityquery . '"',
                'Please be more specific: ' . implode('; ', $names)
            );
        }
        $cm = $matches[0];
        if ($cm->uservisible) {
            return diagnostic_result_builder::row(
                'ok',
                'Activity "' . $cm->name . '" is visible to the user',
                '',
                $links->activity($cm->modname, (int)$cm->id)
            );
        }
        $reason = trim(strip_tags((string)$cm->availableinfo));
        return diagnostic_result_builder::row(
            'fail',
            'Activity "' . $cm->name . '" is NOT visible to the user',
            $reason !== '' ? $reason : 'Hidden or restricted (no visible reason is shown for this user).',
            $links->activity($cm->modname, (int)$cm->id)
        );
    }

    /**
     * Build the course-wide activity-visibility overview row.
     *
     * @param \course_modinfo $modinfo
     * @return array<string,mixed>
     */
    private function activity_overview_row(\course_modinfo $modinfo): array {
        $total = 0;
        $hidden = 0;
        foreach ($modinfo->get_cms() as $cm) {
            $total++;
            if (!$cm->uservisible) {
                $hidden++;
            }
        }
        if ($total === 0) {
            return diagnostic_result_builder::row('warn', 'No activities in the course', '');
        }
        if ($hidden === 0) {
            return diagnostic_result_builder::row('ok', 'All activities are visible to the user', $total . ' activit(y/ies) checked');
        }
        return diagnostic_result_builder::row(
            'warn',
            $hidden . ' of ' . $total . ' activities not visible to the user',
            'Name the specific activity (activityquery) to see the exact reason.'
        );
    }

    /**
     * Build the group-mode + membership row.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param int $targetuserid
     * @param diagnostic_link_builder $links
     * @return array<string,mixed>
     */
    private function group_row(\stdClass $course, int $courseid, int $targetuserid, diagnostic_link_builder $links): array {
        $groupmode = (int)groups_get_course_groupmode($course);
        if ($groupmode === NOGROUPS) {
            return diagnostic_result_builder::row('ok', 'No group mode enforced', 'Group membership does not restrict access here.');
        }
        $usergroups = groups_get_user_groups($courseid, $targetuserid);
        $ingroup = !empty($usergroups[0]);
        $modelabel = $groupmode === SEPARATEGROUPS ? 'separate groups' : 'visible groups';
        if ($ingroup) {
            return diagnostic_result_builder::row('ok', 'Group mode: ' . $modelabel, 'The user is a member of at least one group.');
        }
        $status = $groupmode === SEPARATEGROUPS ? 'warn' : 'ok';
        return diagnostic_result_builder::row(
            $status,
            'Group mode: ' . $modelabel,
            'The user is in no group' . ($groupmode === SEPARATEGROUPS
            ? ' — with separate groups this can hide group-scoped content/people.' : '.')
        );
    }

    /**
     * Assemble the final skill result (observation + preview rows).
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param \context $coursecontext
     * @param int $targetuserid
     * @param string $targetname
     * @param bool $isself
     * @param array<int,array<string,mixed>> $rows
     * @param int $actinguserid
     * @param diagnostic_link_builder $links
     * @return array<string,mixed>
     */
    private function build_result(
        \stdClass $course,
        int $courseid,
        \context $coursecontext,
        int $targetuserid,
        string $targetname,
        bool $isself,
        array $rows,
        int $actinguserid,
        diagnostic_link_builder $links
    ): array {
        $subject = $isself ? 'you' : $targetname;
        $coursename = format_string($course->fullname);

        $lines = ['Access diagnosis for ' . $subject . ' in course "' . $coursename . '" (id=' . $courseid . '):'];
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
        $lines[] = 'Note: this is an automated access check. State only the findings above; do not infer access rules '
            . 'beyond them. A hidden availability condition deliberately shows no reason.';

        $usermessage = 'Access check for ' . $subject . ' in "' . $coursename . '" completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $targetuserid,
            'diagnosis' => [
                'courseid' => $courseid,
                'targetuserid' => $targetuserid,
                'isselfdiagnosis' => $isself,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => 'Access check: ' . $subject . ' · ' . $coursename,
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
        $title = (string)($resultentry['checklist_title'] ?? '');
        return (new diagnostic_checklist_preview())->render($rows, $title, [
            'courseid' => (int)($resultentry['diagnosis']['courseid'] ?? 0),
        ]);
    }


    /**
     * Build an error result (error-messaging contract: carries an error_class for the synchronizer).
     *
     * @param string $message
     * @param string $errorclass
     * @return array<string,mixed>
     */
    private function error_result(string $message, string $errorclass): array {
        return diagnostic_result_builder::error_result($message, $errorclass, 'Access diagnosis could not run: ');
    }
}
