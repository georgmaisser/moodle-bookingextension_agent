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
use grade_grade;
use grade_item;

/**
 * Readonly diagnosis skill: collect the facts behind a "wrong or missing grade" question.
 *
 * Deliberately a FACTS COLLECTOR, not a recalculation engine: re-implementing gradebook aggregation
 * correctly is a large, error-prone effort and a wrong explanation is worse than none. The skill gathers
 * the grade structure and the user's stored grades with their flags (hidden/locked/overridden/excluded,
 * needsupdate, showgrades) and tells the model to explain ONLY from those facts, not to recompute.
 *
 * Most sensitive data of the family: cross-user needs moodle/grade:viewall; self-diagnosis never reveals
 * a grade hidden from the user. R0/readonly → all guards live in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_grades_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'course.diagnose_grades';

    /** Hard cap on grade items reported (observation-size discipline). */
    private const MAX_ITEMS = 25;

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
            'description' => 'Collect the facts behind a "wrong or missing grade" question in a course: the grade '
                . 'items and a person\'s stored grades with their flags (hidden/locked/overridden/excluded, '
                . 'recalculation pending, gradebook shown to students). It does NOT recompute the gradebook — it '
                . 'reports stored facts for the model to explain. Use for "why is Maria\'s grade missing/wrong", '
                . '"warum sieht der Student seine Note nicht". NOT for access, enrolment, permissions or notifications.',
            'readonly' => true,
            'example_utterances' => [
                'the grade for the test isn\'t showing up',
                'why is this student\'s grade missing',
                'her final grade in the gradebook looks wrong',
                'the quiz mark isn\'t appearing for him',
                'why can\'t the student see their grade',
                'the course total grade is off',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or id of the person. "me"/"ich" or empty = the current user. '
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
                'itemquery' => [
                    'type' => 'string',
                    'description' => 'Optional: the name of a specific grade item / activity (e.g. "Quiz 3"). Leave '
                        . 'empty for an overview of all grade items.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['userquery', 'coursequery', 'itemquery'],
                'anchor_fields' => ['userquery', 'coursequery', 'itemquery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return ['userquery' => 'Maria Muster', 'itemquery' => 'Quiz 3'];
    }

    /**
     * Discovery triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.diagnose_grades_request',
                'description' => 'User asks why a grade is missing, not visible, or looks wrong for a person in a '
                    . 'course (gradebook facts). Not access/enrolment/permissions/notifications.',
                'examples' => [
                    'Warum sieht Maria ihre Note für Quiz 3 nicht?',
                    'Die Endnote von Tom stimmt nicht — woran kann das liegen?',
                    'Why is the grade for this assignment missing?',
                    'Warum fehlt die Note im Notenbuch?',
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
                'id' => 'course.diagnose_grades',
                'triggers' => [
                    'note', 'noten', 'notenbuch', 'grade', 'grades', 'gradebook', 'endnote', 'bewertung',
                    'note fehlt', 'note falsch', 'note nicht sichtbar', 'grade missing', 'grade wrong',
                    'why grade', 'warum note',
                ],
                'guidance' => [
                    '- course.diagnose_grades collects gradebook FACTS (read-only): item structure + the person\'s',
                    '  stored grades with flags (hidden/locked/overridden/excluded, needsupdate, showgrades).',
                    '- It does NOT recalculate. Explain the situation strictly from the listed facts; never compute a',
                    '  grade yourself.',
                    '- Name the activity via itemquery when the question is about one item. Not for access, enrolment,',
                    '  permissions or notifications.',
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
     * Run the grades diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

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

        // Step two: resolve the target user, defaulting to self.
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

        // 3) Gate (sensitive): cross-user needs grade:viewall; self needs to be able to see grades at all.
        $canviewall = has_capability('moodle/grade:viewall', $coursecontext, $userid);
        if (!$isself && !$canviewall) {
            return $this->error_result(get_string('nopermissions', 'error', 'moodle/grade:viewall'), 'permission_denied');
        }
        if ($isself && !$canviewall && !has_capability('moodle/grade:view', $coursecontext, $userid)) {
            return $this->error_result(get_string('nopermissions', 'error', 'moodle/grade:view'), 'permission_denied');
        }

        $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        if (!$targetuser || !empty($targetuser->deleted)) {
            return $this->error_result('That user no longer exists.', 'user_not_found');
        }

        $links = new diagnostic_link_builder();
        $rows = [];

        // 4) Course-level gradebook facts.
        if ((int)($course->showgrades ?? 1) === 0) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Gradebook hidden from students',
                'The course setting "show gradebook to students" is off.',
                $links->grade_setup($courseid)
            );
        } else {
            $rows[] = diagnostic_result_builder::row('ok', 'Gradebook shown to students', '', $links->grade_setup($courseid));
        }

        // 5) Grade items + the user's grade per item.
        $items = grade_item::fetch_all(['courseid' => $courseid]) ?: [];
        $items = $this->filter_items($items, trim((string)($input['itemquery'] ?? '')));
        if (empty($items)) {
            $rows[] = diagnostic_result_builder::row('warn', 'No matching grade item', 'No grade item matched the request in this course.');
        }

        $shown = 0;
        $needsupdate = false;
        foreach ($items as $item) {
            if ($shown >= self::MAX_ITEMS) {
                $rows[] = diagnostic_result_builder::row('warn', 'More grade items exist', 'Only the first ' . self::MAX_ITEMS
                    . ' are shown; name a specific item (itemquery) to narrow down.');
                break;
            }
            if (!empty($item->needsupdate)) {
                $needsupdate = true;
            }
            $rows[] = $this->item_row($item, (int)$targetuserid, $isself, $canviewall, $courseid, $links);
            $shown++;
        }

        if ($needsupdate) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Gradebook recalculation pending',
                'At least one item is flagged needsupdate — totals may be stale until recalculated.',
                $links->grade_setup($courseid)
            );
        }

        return $this->build_result($course, $courseid, $targetuser, $isself, $rows, $links, $userid);
    }

    /**
     * Filter grade items by an optional name query (fuzzy).
     *
     * @param array<int,grade_item> $items
     * @param string $itemquery
     * @return array<int,grade_item>
     */
    private function filter_items(array $items, string $itemquery): array {
        if ($itemquery === '') {
            return $items;
        }
        $needle = \core_text::strtolower($itemquery);
        $matches = [];
        foreach ($items as $item) {
            $name = \core_text::strtolower((string)$item->get_name());
            if ($name !== '' && str_contains($name, $needle)) {
                $matches[] = $item;
            }
        }
        return $matches;
    }

    /**
     * Build one checklist row for a grade item + the user's grade.
     *
     * @param grade_item $item
     * @param int $targetuserid
     * @param bool $isself
     * @param bool $canviewall
     * @param int $courseid
     * @param diagnostic_link_builder $links
     * @return array<string,mixed>
     */
    private function item_row(
        grade_item $item,
        int $targetuserid,
        bool $isself,
        bool $canviewall,
        int $courseid,
        diagnostic_link_builder $links
    ): array {
        $name = (string)$item->get_name();
        $url = $links->user_grade_report($courseid, $targetuserid);

        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $targetuserid]);
        if (!$grade || ($grade->finalgrade === null && $grade->rawgrade === null)) {
            return diagnostic_result_builder::row('warn', 'Item "' . $name . '": no grade recorded', 'No grade stored for this person yet.', $url);
        }

        // Respect hidden grades for a self-request without viewall.
        if ($grade->is_hidden() && !$canviewall) {
            return diagnostic_result_builder::row(
                'warn',
                'Item "' . $name . '": grade hidden from the user',
                'A grade exists but is hidden (not yet released or hidden by the teacher).',
                $url
            );
        }

        $flags = [];
        if ($grade->is_hidden()) {
            $flags[] = 'hidden';
        }
        if ($grade->is_locked()) {
            $flags[] = 'locked';
        }
        if ($grade->is_overridden()) {
            $flags[] = 'overridden';
        }
        if ($grade->is_excluded()) {
            $flags[] = 'excluded from aggregation';
        }

        $display = $this->format_grade($grade->finalgrade, $item);
        $finding = 'Grade: ' . $display;
        if (
            $grade->finalgrade !== null && $grade->rawgrade !== null
            && (float)$grade->finalgrade !== (float)$grade->rawgrade
        ) {
            $finding .= ' (raw ' . $this->format_grade($grade->rawgrade, $item) . ')';
        }
        if (!empty($flags)) {
            $finding .= ' [' . implode(', ', $flags) . ']';
        }

        $status = $grade->is_excluded() ? 'warn' : 'ok';
        return diagnostic_result_builder::row($status, 'Item "' . $name . '"', $finding, $url);
    }

    /**
     * Format a grade value for display (value/scale/text aware).
     *
     * @param float|null $value
     * @param grade_item $item
     * @return string
     */
    private function format_grade($value, grade_item $item): string {
        if ($value === null) {
            return '-';
        }
        try {
            return (string)grade_format_gradevalue((float)$value, $item, true);
        } catch (\Throwable $e) {
            return (string)round((float)$value, 2) . '/' . (string)round((float)$item->grademax, 2);
        }
    }

    /**
     * Assemble the result.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param array<int,array<string,mixed>> $rows
     * @param diagnostic_link_builder $links
     * @param int $actinguserid
     * @return array<string,mixed>
     */
    private function build_result(
        \stdClass $course,
        int $courseid,
        \stdClass $targetuser,
        bool $isself,
        array $rows,
        diagnostic_link_builder $links,
        int $actinguserid
    ): array {
        $subject = $isself ? 'you' : fullname($targetuser);
        $coursename = format_string($course->fullname);

        $lines = ['Grade diagnosis for ' . $subject . ' in course "' . $coursename . '" (id=' . $courseid . '):'];
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
        $lines[] = 'Note: these are stored gradebook FACTS. Explain the situation only from them; do NOT recompute '
            . 'or estimate any grade yourself.';

        $usermessage = 'Grade check for ' . $subject . ' in "' . $coursename . '" completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$targetuser->id,
            'diagnosis' => [
                'courseid' => $courseid,
                'targetuserid' => (int)$targetuser->id,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => 'Grade check: ' . $subject . ' · ' . $coursename,
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
        return diagnostic_result_builder::error_result($message, $errorclass, 'Grade diagnosis could not run: ');
    }
}
