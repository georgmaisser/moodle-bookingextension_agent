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
 * Readonly diagnosis skill: explain why (automatic) enrolment into a course did or did not happen.
 *
 * Inspects the course's enrolment methods (incl. disabled ones) — self, cohort, manual in detail, others by
 * name — their plugin/instance state, the relevant constraints (window, key, max participants, cohort
 * restriction/membership), and, when a person is named, that person's existing enrolment records
 * (active/suspended/expired). Site admins additionally see the health of enrolment-related scheduled tasks.
 *
 * R0/readonly: the engine skips preflight, so course/user resolution and the capability gate live in
 * execute(). Enrolment configuration is privileged knowledge, gated on moodle/course:enrolreview.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_enrolment_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'course.diagnose_enrolment';

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
            'description' => 'Explain why ENROLMENT into a course did or did not happen — especially failed '
                . 'automatic enrolment (self-enrolment, cohort sync). Inspects the course enrolment methods and '
                . 'their constraints (enabled? time window, enrolment key, max participants, cohort '
                . 'restriction/membership) and, for a named person, their current enrolment (active/suspended/'
                . 'expired). Use for "why was Maria not auto-enrolled", "why is Tom not enrolled in the course", '
                . '"cohort sync not working". NOT for booking, access/visibility, or grades.',
            'readonly' => true,
            'example_utterances' => [
                'this user isn\'t enrolled even though they should be',
                'why wasn\'t she auto-enrolled in the course',
                'the cohort sync didn\'t enrol these students',
                'his enrolment expired and he\'s no longer in the course',
                'self enrolment isn\'t working for this course',
                'why is the enrolment key not letting them in',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Optional: name, e-mail or id of the person whose enrolment to diagnose. Omit for '
                        . 'a course-wide enrolment-method overview. Resolve ambiguous names via core.search_users.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known. Takes precedence over userquery.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Course name when not the current course (resolve via course.search_courses if '
                        . 'only the name is known). Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Leave empty for the current course; never guess.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['userquery', 'coursequery'],
                'anchor_fields' => ['userquery', 'coursequery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return ['userquery' => 'Maria Muster'];
    }

    /**
     * Discovery triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.diagnose_enrolment_request',
                'description' => 'User asks why someone was (not) enrolled into a course, why automatic enrolment '
                    . '(self-enrolment / cohort sync) did not work, or about a course\'s enrolment methods.',
                'examples' => [
                    'Why was Maria not automatically enrolled in the course?',
                    'Tom is not in the course "Mathematics" — what is the reason?',
                    'Why did the cohort sync not enrol these users?',
                    'Which enrolment methods does this course have and are they active?',
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
                'id' => 'course.diagnose_enrolment',
                'triggers' => [
                    'not enrolled', 'not in the course', 'automatically enrolled', 'enrolment',
                    'enrolment method', 'self enrolment', 'cohort', 'cohort sync',
                    'auto-enrol', 'enrolment key',
                    'why not enrolled',
                ],
                'guidance' => [
                    '- course.diagnose_enrolment explains enrolment problems (read-only): which methods exist, whether',
                    '  they are enabled, their constraints, and a named person\'s current enrolment state.',
                    '- Use it for "not / not auto enrolled", NOT for "cannot see/open" (course.diagnose_access),',
                    '  "cannot book" (mod_booking.diagnose_booking_issue) or grades (course.diagnose_grades).',
                    '- Name the person via userquery/userid when the question is about one person; omit for a method',
                    '  overview. Answer strictly from the returned findings.',
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
     * Run the enrolment diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        // 1) Resolve the target course.
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
            // No course named: when a person is named, answer the enrolment-overview question
            // ("which courses is X enrolled in?") directly instead of dead-ending in a clarification.
            // This is still enrolment diagnosis and reuses the same user-courses payload (with course
            // links) that core.search_users already exposes, so it adds no new data surface.
            $overviewuserid = (int)($input['userid'] ?? 0);
            if ($overviewuserid <= 0 && trim((string)($input['userquery'] ?? '')) !== '') {
                $overviewuserid = $this->resolve_userid($input, 0);
            }
            if ($overviewuserid > 0) {
                $overviewuser = \core_user::get_user($overviewuserid, '*', IGNORE_MISSING);
                if ($overviewuser && empty($overviewuser->deleted)) {
                    return $this->enrolment_overview_result($overviewuser);
                }
            }
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

        // 2) Gate: enrolment configuration is privileged (teacher/manager).
        if (!has_capability('moodle/course:enrolreview', $coursecontext, $userid)) {
            return $this->error_result(
                get_string('nopermissions', 'error', 'moodle/course:enrolreview'),
                'permission_denied'
            );
        }

        // 3) Optional target user (only when explicitly named — no implicit self here).
        $targetuserid = (int)($input['userid'] ?? 0);
        if ($targetuserid <= 0 && trim((string)($input['userquery'] ?? '')) !== '') {
            $targetuserid = $this->resolve_userid($input, 0);
            if ($targetuserid <= 0) {
                return $this->error_result(
                    'I could not identify that person. Give a name, e-mail or id — or resolve via core.search_users.',
                    'user_unresolved'
                );
            }
        }
        $targetuser = null;
        if ($targetuserid > 0) {
            $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
            if (!$targetuser || !empty($targetuser->deleted)) {
                return $this->error_result('That user no longer exists.', 'user_not_found');
            }
        }

        $links = new diagnostic_link_builder();
        $rows = [];

        // 4) Enrolment methods.
        $instances = enrol_get_instances($courseid, false);
        if (empty($instances)) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'No enrolment methods on the course',
                'The course has no enrolment method configured at all.',
                $links->enrol_instances($courseid)
            );
        }
        foreach ($instances as $instance) {
            $rows[] = $this->analyse_instance($instance, $coursecontext, $targetuserid, $links, $courseid);
        }

        // 5) The person's existing enrolment records (when a person is named).
        if ($targetuserid > 0) {
            $rows[] = $this->existing_enrolment_row($courseid, $targetuserid, $coursecontext, $links);
        }

        // 6) Scheduled-task health for enrolment plugins (site admins only).
        if (is_siteadmin($userid)) {
            foreach ($this->enrolment_task_rows($links, $userid) as $taskrow) {
                $rows[] = $taskrow;
            }
        }

        return $this->build_result($course, $courseid, $targetuser, $rows);
    }

    /**
     * Analyse a single enrolment instance into one checklist row.
     *
     * @param \stdClass $instance
     * @param \context $coursecontext
     * @param int $targetuserid 0 = no specific person.
     * @param diagnostic_link_builder $links
     * @param int $courseid
     * @return array<string,mixed>
     */
    private function analyse_instance(
        \stdClass $instance,
        \context $coursecontext,
        int $targetuserid,
        diagnostic_link_builder $links,
        int $courseid
    ): array {
        $method = (string)$instance->enrol;
        $label = get_string('pluginname', 'enrol_' . $method);
        $url = $links->enrol_instances($courseid);

        // Disabled instance or site-disabled plugin are themselves the blocker.
        if ((int)$instance->status !== ENROL_INSTANCE_ENABLED) {
            return diagnostic_result_builder::row('fail', 'Method "' . $label . '" is disabled', 'This enrolment method instance is turned off.', $url);
        }
        if (!enrol_is_enabled($method)) {
            return diagnostic_result_builder::row(
                'fail',
                'Method "' . $label . '" plugin disabled site-wide',
                'The ' . $label . ' enrolment plugin is disabled for the whole site.',
                $url
            );
        }

        if ($method === 'self') {
            return $this->analyse_self($instance, $label, $targetuserid, $url);
        }
        if ($method === 'cohort') {
            return $this->analyse_cohort($instance, $label, $targetuserid, $url, $links);
        }
        if ($method === 'manual') {
            return diagnostic_result_builder::row(
                'ok',
                'Method "' . $label . '" is active',
                'Manual enrolment is available; teachers/managers add users by hand.',
                $url
            );
        }
        // Other methods: name + active only (v1).
        return diagnostic_result_builder::row('ok', 'Method "' . $label . '" is active', 'Not inspected in detail in this version.', $url);
    }

    /**
     * Analyse a self-enrolment instance.
     *
     * @param \stdClass $instance
     * @param string $label
     * @param int $targetuserid
     * @param \moodle_url $url
     * @return array<string,mixed>
     */
    private function analyse_self(\stdClass $instance, string $label, int $targetuserid, \moodle_url $url): array {
        global $DB;
        $notes = [];
        $status = 'ok';
        $now = time();

        if ((int)$instance->customint6 === 0) {
            $status = 'fail';
            $notes[] = 'new self-enrolments are not allowed';
        }
        if ((int)$instance->enrolstartdate > 0 && (int)$instance->enrolstartdate > $now) {
            $status = 'fail';
            $notes[] = 'enrolment window has not started yet';
        }
        if ((int)$instance->enrolenddate > 0 && (int)$instance->enrolenddate < $now) {
            $status = 'fail';
            $notes[] = 'enrolment window has ended';
        }
        if ((int)$instance->customint3 > 0) {
            $active = (int)$DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'status' => 0]);
            if ($active >= (int)$instance->customint3) {
                $status = 'fail';
                $notes[] = 'max participants reached (' . $active . '/' . (int)$instance->customint3 . ')';
            }
        }
        if (!empty($instance->password)) {
            $notes[] = 'an enrolment key is required';
        }
        if ((int)$instance->customint5 > 0) {
            $cohort = $DB->get_record('cohort', ['id' => (int)$instance->customint5], 'id, name, contextid', IGNORE_MISSING);
            $cohortname = $cohort
                ? format_string($cohort->name, true, ['context' => \context::instance_by_id($cohort->contextid)])
                : ('#' . (int)$instance->customint5);
            if ($targetuserid > 0 && !cohort_is_member((int)$instance->customint5, $targetuserid)) {
                $status = 'fail';
                $notes[] = 'restricted to members of cohort "' . $cohortname . '" — the person is NOT a member';
            } else {
                $notes[] = 'restricted to members of cohort "' . $cohortname . '"';
            }
        }

        $finding = empty($notes) ? 'Self enrolment is open.' : ucfirst(implode('; ', $notes)) . '.';
        return diagnostic_result_builder::row($status, 'Self enrolment "' . $label . '"', $finding, $url);
    }

    /**
     * Analyse a cohort-sync instance.
     *
     * @param \stdClass $instance
     * @param string $label
     * @param int $targetuserid
     * @param \moodle_url $url
     * @param diagnostic_link_builder $links
     * @return array<string,mixed>
     */
    private function analyse_cohort(
        \stdClass $instance,
        string $label,
        int $targetuserid,
        \moodle_url $url,
        diagnostic_link_builder $links
    ): array {
        global $DB;
        $cohortid = (int)$instance->customint1;
        $cohort = $cohortid > 0
            ? $DB->get_record('cohort', ['id' => $cohortid], 'id, name, contextid', IGNORE_MISSING)
            : null;
        $cohortname = $cohort
            ? format_string($cohort->name, true, ['context' => \context::instance_by_id($cohort->contextid)])
            : ('#' . $cohortid);

        if ($targetuserid > 0) {
            if ($cohortid > 0 && cohort_is_member($cohortid, $targetuserid)) {
                return diagnostic_result_builder::row(
                    'ok',
                    'Cohort sync via "' . $cohortname . '"',
                    'The person IS a member of this cohort, so cohort sync should enrol them.',
                    $url
                );
            }
            return diagnostic_result_builder::row(
                'fail',
                'Cohort sync via "' . $cohortname . '"',
                'The person is NOT a member of this cohort — cohort sync will not enrol them.',
                $url
            );
        }
        return diagnostic_result_builder::row(
            'ok',
            'Cohort sync via "' . $cohortname . '"',
            'Members of this cohort are auto-enrolled.',
            $url
        );
    }

    /**
     * Build the row describing a person's existing enrolment records in the course.
     *
     * @param int $courseid
     * @param int $targetuserid
     * @param \context $coursecontext
     * @param diagnostic_link_builder $links
     * @return array<string,mixed>
     */
    private function existing_enrolment_row(
        int $courseid,
        int $targetuserid,
        \context $coursecontext,
        diagnostic_link_builder $links
    ): array {
        global $DB;
        $now = time();
        $sql = "SELECT ue.id, ue.status, ue.timestart, ue.timeend, e.enrol
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid = :userid";
        $records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'userid' => $targetuserid]);

        if (empty($records)) {
            return diagnostic_result_builder::row(
                'fail',
                'No enrolment record for this person',
                'The person has no enrolment in this course (active, suspended or expired).',
                $links->user_profile($targetuserid, $courseid)
            );
        }

        $hasactive = false;
        $details = [];
        foreach ($records as $ue) {
            $method = (string)$ue->enrol;
            if ((int)$ue->status === 1) {
                $details[] = $method . ': suspended';
            } else if ((int)$ue->timeend > 0 && (int)$ue->timeend < $now) {
                $details[] = $method . ': expired';
            } else if ((int)$ue->timestart > $now) {
                $details[] = $method . ': not started yet';
            } else {
                $hasactive = true;
                $details[] = $method . ': active';
            }
        }

        $status = $hasactive ? 'ok' : 'fail';
        $check = $hasactive ? 'Person is currently enrolled (active)' : 'Person has only inactive enrolments';
        return diagnostic_result_builder::row($status, $check, implode('; ', $details), $links->user_profile($targetuserid, $courseid));
    }

    /**
     * Build rows for the health of enrolment-related scheduled tasks (admin-only).
     *
     * @param diagnostic_link_builder $links
     * @param int $userid
     * @return array<int,array<string,mixed>>
     */
    private function enrolment_task_rows(diagnostic_link_builder $links, int $userid): array {
        global $DB;
        $rows = [];
        $tasks = $DB->get_records('task_scheduled');
        $unhealthy = [];
        foreach ($tasks as $task) {
            if (strpos((string)$task->classname, 'enrol_') === false) {
                continue;
            }
            if ((int)$task->disabled === 1) {
                $unhealthy[] = $task->classname . ' (disabled)';
            } else if ((int)$task->faildelay > 0) {
                $unhealthy[] = $task->classname . ' (failing, faildelay=' . (int)$task->faildelay . ')';
            }
        }
        $tasklink = $links->if_admin($links->scheduled_tasks(), $userid);
        if (empty($unhealthy)) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'Enrolment scheduled tasks healthy',
                'No disabled or failing enrolment tasks (e.g. cohort sync).',
                $tasklink
            );
        } else {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Enrolment scheduled task problem',
                implode('; ', $unhealthy),
                $tasklink
            );
        }
        return $rows;
    }

    /**
     * Assemble the final result.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param \stdClass|null $targetuser
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function build_result(\stdClass $course, int $courseid, ?\stdClass $targetuser, array $rows): array {
        $coursename = format_string($course->fullname);
        $subject = $targetuser ? fullname($targetuser) : 'the course (overview)';

        $lines = ['Enrolment diagnosis for ' . $subject . ' in course "' . $coursename . '" (id=' . $courseid . '):'];
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
        $lines[] = 'Note: automated enrolment check. State only the findings above; do not infer enrolment rules '
            . 'beyond them.';

        $usermessage = 'Enrolment check for ' . $subject . ' in "' . $coursename . '" completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $targetuser ? (int)$targetuser->id : $courseid,
            'diagnosis' => [
                'courseid' => $courseid,
                'targetuserid' => $targetuser ? (int)$targetuser->id : 0,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => 'Enrolment check: ' . $subject . ' · ' . $coursename,
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * Build the enrolment overview for a named user when no course was given.
     *
     * Answers "which courses is this user enrolled in?" directly (with real course links) instead of
     * forcing a course-clarification. Reuses {@see core_skill_base::build_user_courses_payload()} so the
     * course list and links stay identical to core.search_users, and leaves it to the selector/synchronizer
     * to either answer from the overview or pivot to a course-specific diagnosis.
     *
     * @param \stdClass $targetuser
     * @return array<string,mixed>
     */
    private function enrolment_overview_result(\stdClass $targetuser): array {
        $targetuserid = (int)$targetuser->id;
        $courses = $this->build_user_courses_payload($targetuserid);
        $subject = fullname($targetuser);

        $usermessage = !empty($courses)
            ? ($subject . ' is enrolled in ' . count($courses) . ' course(s).')
            : ($subject . ' is not enrolled in any course.');

        $lines = [
            'No course was named — enrolment overview for ' . $subject . ' (id=' . $targetuserid . ').',
            'Enrolled courses (with links): ' . $this->format_course_observation($courses),
            'Note: no specific course was given. To diagnose why ' . $subject . ' is (not) enrolled in a '
                . 'particular course, name that course and re-run; otherwise answer from the overview above '
                . 'and use the course links.',
        ];

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $targetuserid,
            'enrolment_overview' => [
                'targetuserid' => $targetuserid,
                'courses' => $courses,
            ],
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
        return diagnostic_result_builder::error_result($message, $errorclass, 'Enrolment diagnosis could not run: ');
    }
}
