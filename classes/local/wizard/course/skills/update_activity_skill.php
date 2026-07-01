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

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\preflight_clarification;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\activities\activity_creation_service;
use bookingextension_agent\local\wizard\services\activities\activity_preview_renderer;
use bookingextension_agent\local\wizard\services\activities\module_catalog_service;
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use bookingextension_agent\local\wizard\services\activities\section_resolver_service;
use bookingextension_agent\local\wizard\services\activity_preview_builder;
use context;

/**
 * Generic skill: edit an existing activity in a course (course.update_activity).
 *
 * Partial update: changes only the fields the user provides (name / intro / visibility / module-specific
 * settings); everything else keeps its current value (sourced from the activity's real mod_form via
 * get_moduleinfo_data). The same headless mod_form is the validation contract, then update_moduleinfo()
 * applies the change. No engine changes — clarifications (which activity? bad field?) and the created
 * preview all travel over the existing generic channels. R2 (confirm before mutation).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_activity_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name. */
    public const SKILL_NAME = 'course.update_activity';

    /** How many update attempts before giving up (guards transient DB errors). */
    private const MAX_RETRIES = 2;

    /**
     * Constructor. Mutating skill (edits a course module) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Human-readable preview of the activity update (tier-3): target + changed fields.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return activity_preview_builder::update_activity_descriptor($input);
    }

    /**
     * Activities live in a course.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * The cross-context target is a course.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * Native capability required to edit an activity (Gate 2).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/course:manageactivities'];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Edit / change an existing activity or resource in a course — rename it, change its '
                . 'description, show or hide it, change a module-specific setting (e.g. a URL\'s link), or MOVE it '
                . 'to a different section/topic. Use for "rename the page to X", "hide the forum", "change the '
                . 'activity\'s URL", "hide the quiz", "move the label to section 2", "move the page one section down". '
                . 'Only the fields you give are changed. To CREATE a new activity use course.add_activity instead.',
            'readonly' => false,
            'example_utterances' => [
                'rename the Welcome page to Course intro',
                'hide the forum from students',
                'change the description of the folder',
                'make the link point to a new URL',
                'show the page that is currently hidden',
                'move the label to section 1',
                'move the page one section down',
            ],
            'properties' => [
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Which activity to edit, by its current name (e.g. "Welcome page"). The system '
                        . 'resolves it; if several match it asks. Omit only when editing the activity of the current '
                        . 'page or when cmid is given.',
                    'required' => false,
                ],
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id of the activity, when already known. Never guess.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New name/title. Leave empty to keep the current name.',
                    'required' => false,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'New description/intro text. Leave empty to keep the current description.',
                    'required' => false,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Set true to show the activity, false to hide it. Omit to keep current visibility.',
                    'required' => false,
                ],
                'settings' => [
                    'type' => 'object',
                    'description' => 'Module-specific fields to change, as an object. Example: for a URL '
                        . '{"externalurl":"https://…"}; for a Page {"content":"…"}. Only set what should change. '
                        . 'This is NOT for moving the activity — use "section" for that.',
                    'required' => false,
                ],
                'section' => [
                    'type' => 'integer',
                    'description' => 'Move the activity to this section/topic number (0-based, e.g. 0 = top). Use for '
                        . '"move it to section 2" or "one section down/up" (compute the target number). Omit to leave '
                        . 'it where it is. On the site front page everything stays in section 1.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Target a DIFFERENT course than the current one, ONLY when the user names one. '
                        . 'Resolve via course.search_courses first if only the name is known. Leave empty otherwise.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course, when already known. Leave empty for the current '
                        . 'course; never guess.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['activityquery', 'name', 'intro', 'visible', 'settings', 'section'],
                'anchor_fields' => ['activityquery', 'coursequery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['activityquery' => 'Welcome page', 'name' => 'Course introduction'];
    }

    /**
     * Message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.update_activity_request',
                'description' => 'User wants to change/edit an EXISTING activity or resource in a course: rename it, '
                    . 'change its description, show/hide it, change a module setting, or MOVE it to another '
                    . 'section/topic (e.g. "rename the page", "hide the forum", "change the URL", "move the label '
                    . 'to section 2", "move the page one section down"). Not creating a new one.',
            ],
        ];
    }

    /**
     * Contextual guidance.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.update_activity',
                'triggers' => [
                    'rename', 'hide', 'show', 'unhide',
                    'make visible', 'change the', 'edit the', 'update the activity',
                    'change the url', 'rename page', 'hide forum',
                    'move', 'verschieben', 'section', 'one section down', 'one section up',
                ],
                'guidance' => [
                    '- course.update_activity edits an EXISTING activity; to create a new one use course.add_activity.',
                    '- Identify the activity via activityquery (its current name) or cmid; if unclear the system asks.',
                    '- Set only the fields that should change (name, intro, visible, or settings{}); omit the rest —',
                    '  they keep their current value. Do NOT invent values.',
                    '- To MOVE the activity to another section/topic, set "section" to the target section number.',
                    '  For "one section down/up" compute the number from the current one. Never use settings{} to move.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure).
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if (isset($input['settings']) && $input['settings'] !== '' && !is_array($input['settings'])) {
            $errors[] = 'settings must be an object of module-specific fields.';
        }
        if (isset($input['section']) && $input['section'] !== '' && $input['section'] !== null) {
            if (!is_numeric($input['section']) || (int)$input['section'] < 0) {
                $errors[] = 'section must be a non-negative section number.';
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Deep validation: resolve course + target activity + the requested changes (read-only).
     *
     * @param array $input
     * @param int   $contextid Operating context (target course context when one was named).
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->clarify(
                'Editing activities works inside a course. Please open a course, or name one.',
                'UPDATE_ACTIVITY_NO_COURSE'
            );
        }
        if (!has_capability('moodle/course:manageactivities', $coursecontext, $userid)) {
            return $this->clarify(
                get_string('nopermissions', 'error', 'moodle/course:manageactivities'),
                'NO_NATIVE_CAPABILITY'
            );
        }
        $course = get_course($coursecontext->instanceid);

        // Resolve the target course module.
        $cmresolution = $this->resolve_target_cm($course, $context, $input);
        if (is_array($cmresolution)) {
            return $cmresolution;
        }
        $cm = $cmresolution;
        $modname = (string)$cm->modname;

        // Collect the requested field changes (name / intro / visibility / module settings).
        $changes = $this->collect_changes($input);

        // A section move is a course-structure operation, resolved separately from the mod_form fields.
        $sectionmove = $this->resolve_section_move($input, $course, $cm);
        if (is_array($sectionmove)) {
            return $sectionmove;
        }

        if (empty($changes) && $sectionmove === null) {
            return $this->clarify(
                'What should I change about "' . format_string($cm->name) . '"? (name, description, visibility, '
                    . 'a module setting, or move it to another section)',
                'UPDATE_ACTIVITY_NO_CHANGES'
            );
        }

        $cmrecord = get_coursemodule_from_id('', (int)$cm->id, (int)$course->id, false, IGNORE_MISSING);
        if (!$cmrecord) {
            return $this->clarify('That activity could not be loaded.', 'UPDATE_ACTIVITY_CM_GONE');
        }

        // Validate the field changes against the activity's real mod_form (only when there are any).
        if (!empty($changes)) {
            $validation = (new module_form_contract())->validate_update($course, $cmrecord, $changes);
            if (!$validation['ok'] && !empty($validation['errors'])) {
                return $this->clarify(
                    $this->format_field_errors($modname, $validation['errors']),
                    'UPDATE_ACTIVITY_FIELDS_INVALID'
                );
            }
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'modname' => $modname,
            'changes' => $changes,
            'section_move' => $sectionmove,
            'before' => [
                'name' => (string)$cm->name,
                'visible' => (int)$cm->visible,
                'section' => (int)$cm->sectionnum,
            ],
        ]);
    }

    /**
     * Apply the change, retrying once on a transient failure.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $cmid = (int)($preparedinput['cmid'] ?? 0);
        $changes = (array)($preparedinput['changes'] ?? []);
        $sectionmove = $preparedinput['section_move'] ?? null;
        $sectionmove = ($sectionmove === null) ? null : (int)$sectionmove;
        if ($courseid <= 0 || $cmid <= 0 || (empty($changes) && $sectionmove === null)) {
            return $this->build_error_result('Missing prepared activity or changes for the update.');
        }

        try {
            $course = get_course($courseid);
            $cmrecord = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
        } catch (\Throwable $e) {
            return $this->build_error_result('The activity to edit could not be loaded.');
        }

        $updater = new activity_creation_service();

        // 1) Field changes (name / intro / visibility / settings), retrying once on a transient failure.
        $updated = null;
        $attempts = 1;
        if (!empty($changes)) {
            $contract = new module_form_contract();
            $lasterror = '';
            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $moduleinfo = $contract->build_prepared_update_moduleinfo($course, $cmrecord, $changes);
                    $updated = $updater->update($cmrecord, $moduleinfo, $course);
                    $attempts = $attempt;
                    break;
                } catch (\Throwable $e) {
                    $lasterror = $e->getMessage();
                    $updated = null;
                }
            }
            if ($updated === null) {
                return $this->build_error_result(
                    'Could not update the activity after ' . self::MAX_RETRIES . ' attempt(s). Last error: ' . $lasterror
                );
            }
        }

        // 2) Section move (course-structure op) — re-read the cm so its current section id is fresh.
        $movedto = null;
        if ($sectionmove !== null) {
            try {
                $cmrecord = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
                $movedto = $updater->move_to_section($cmrecord, $sectionmove, $course);
            } catch (\Throwable $e) {
                return $this->build_error_result(
                    'Could not move the activity to section ' . $sectionmove . '. ' . $e->getMessage()
                );
            }
        }

        // Move-only update: synthesize the descriptor from the (moved) module.
        if ($updated === null) {
            $updated = $this->describe_current_module($course, $cmid);
        }

        return $this->build_success_result(
            $updated,
            $changes,
            (array)($preparedinput['before'] ?? []),
            $attempts,
            $movedto
        );
    }

    /**
     * Resolve a requested section move to a concrete target section number.
     *
     * @param array $input
     * @param \stdClass $course
     * @param \cm_info $cm Target module (carries its current section number).
     * @return int|array|null Target section number, a clarification array, or null when no move is needed.
     */
    private function resolve_section_move(array $input, \stdClass $course, \cm_info $cm) {
        $raw = $input['section'] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $target = (int)$raw;

        // Site front page: everything lives in section 1 (section 0 is not rendered there).
        if (section_resolver_service::is_site_front_page($course)) {
            $target = section_resolver_service::SITE_FRONT_PAGE_SECTION;
        }

        if ($target === (int)$cm->sectionnum) {
            // Already in the target section — nothing to move.
            return null;
        }
        if (!(new section_resolver_service())->section_exists($course, $target)) {
            return $this->clarify(
                'Section ' . $target . ' does not exist in this course.',
                'UPDATE_ACTIVITY_SECTION_INVALID'
            );
        }
        return $target;
    }

    /**
     * Build an update-result descriptor from the current state of a module (used for move-only updates).
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return array
     */
    private function describe_current_module(\stdClass $course, int $cmid): array {
        $coursecontextid = (int)\context_course::instance($course->id)->id;
        try {
            $cm = get_fast_modinfo($course)->get_cm($cmid);
        } catch (\Throwable $e) {
            return ['cmid' => $cmid, 'modname' => '', 'name' => '', 'url' => '', 'coursecontextid' => $coursecontextid];
        }
        $url = ($cm->url instanceof \moodle_url)
            ? $cm->url->out(false)
            : (new \moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false);
        return [
            'cmid' => $cmid,
            'modname' => (string)$cm->modname,
            'name' => (string)$cm->name,
            'url' => $url,
            'coursecontextid' => $coursecontextid,
        ];
    }

    /**
     * Render the updated activity inline for the preview pane.
     *
     * @param array $resultentry
     * @param int   $contextid
     * @param int   $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $cmid = (int)($resultentry['updated_cmid'] ?? 0);
        $courseid = (int)($resultentry['updated_courseid'] ?? 0);
        if ($cmid <= 0 || $courseid <= 0) {
            return null;
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return null;
        }
        $html = (new activity_preview_renderer())->render(
            $course,
            $cmid,
            (string)($resultentry['updated_modname'] ?? ''),
            (string)($resultentry['updated_name'] ?? ''),
            (string)($resultentry['activity_url'] ?? '')
        );
        if (trim($html) === '') {
            return null;
        }
        return [
            'type' => 'updated_activity',
            'html' => $html,
            'payload' => ['cmid' => $cmid, 'activity_url' => (string)($resultentry['activity_url'] ?? '')],
        ];
    }

    /**
     * Resolve the target course module: cmid > activityquery (name) > ambient module context.
     *
     * @param \stdClass $course
     * @param context|false $context Ambient context.
     * @param array $input
     * @return \cm_info|array
     */
    private function resolve_target_cm(\stdClass $course, $context, array $input) {
        $catalog = new module_catalog_service();
        $modinfo = get_fast_modinfo($course);

        // 1) Explicit cmid.
        $cmid = (int)($input['cmid'] ?? 0);
        if ($cmid > 0) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\Throwable $e) {
                return $this->clarify('I could not find an activity with that id in this course.', 'UPDATE_ACTIVITY_CM_NOT_FOUND');
            }
            if (!$catalog->is_whitelisted($cm->modname)) {
                return $this->clarify(
                    'Editing "' . $cm->modname . '" activities is not supported yet.',
                    'UPDATE_ACTIVITY_UNSUPPORTED'
                );
            }
            return $cm;
        }

        // 2) By name.
        $query = trim((string)($input['activityquery'] ?? ''));
        if ($query !== '') {
            $needle = \core_text::strtolower($query);
            $matches = [];
            foreach ($modinfo->get_cms() as $cm) {
                if (!$catalog->is_whitelisted($cm->modname)) {
                    continue;
                }
                if (str_contains(\core_text::strtolower($cm->name), $needle)) {
                    $matches[] = $cm;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                return $this->build_activity_clarification(
                    $matches,
                    'More than one activity matches "' . $query . '". Which one?'
                );
            }
            return $this->clarify(
                'I could not find an editable activity called "' . $query . '" in this course.',
                'UPDATE_ACTIVITY_NOT_FOUND'
            );
        }

        // 3) Ambient module context (editing the activity of the current page).
        if ($context && (int)$context->contextlevel === CONTEXT_MODULE) {
            try {
                $cm = $modinfo->get_cm((int)$context->instanceid);
                if ($catalog->is_whitelisted($cm->modname)) {
                    return $cm;
                }
            } catch (\Throwable $e) {
                // Fall through to the clarification.
                unset($e);
            }
        }

        return $this->clarify(
            'Which activity should I edit? Name it (e.g. "the Welcome page").',
            'UPDATE_ACTIVITY_TARGET_REQUIRED'
        );
    }

    /**
     * Collect the requested changes from input (only provided, meaningful fields).
     *
     * @param array $input
     * @return array
     */
    private function collect_changes(array $input): array {
        $changes = [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name !== '') {
            $changes['name'] = $name;
        }
        $intro = trim((string)($input['intro'] ?? ''));
        if ($intro !== '') {
            $changes['intro'] = $intro;
        }
        if (is_array($input['settings'] ?? null) && !empty($input['settings'])) {
            $changes['settings'] = (array)$input['settings'];
        }
        if (array_key_exists('visible', $input) && $input['visible'] !== '' && $input['visible'] !== null) {
            $changes['visible'] = $this->parse_visible($input['visible']);
        }
        return $changes;
    }

    /**
     * Parse a visibility input into 1 (show) / 0 (hide).
     *
     * @param mixed $value
     * @return int
     */
    private function parse_visible($value): int {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $v = \core_text::strtolower(trim((string)$value));
        if (in_array($v, ['0', 'false', 'no', 'hide', 'hidden'], true)) {
            return 0;
        }
        return 1;
    }

    /**
     * Build a needs_clarification listing candidate activities.
     *
     * @param \cm_info[] $cms
     * @param string $lead
     * @return array
     */
    private function build_activity_clarification(array $cms, string $lead): array {
        $lines = [$lead, ''];
        $options = [];
        foreach ($cms as $cm) {
            $lines[] = '- ' . $cm->name . ' (' . $cm->modname . ') [cmid ' . (int)$cm->id . ']';
            $options[] = ['cmid' => (int)$cm->id, 'name' => $cm->name, 'modname' => $cm->modname];
        }
        $lines[] = '';
        $lines[] = 'Reply with the activity name and I will continue.';
        return $this->clarify(implode("\n", $lines), 'UPDATE_ACTIVITY_AMBIGUOUS', $options);
    }

    /**
     * Format real mod_form field errors into a clarification message.
     *
     * @param string $modname
     * @param array $errors
     * @return string
     */
    private function format_field_errors(string $modname, array $errors): string {
        $lines = ['That change is not valid for the ' . $modname . ' activity:', ''];
        foreach ($errors as $field => $message) {
            $lines[] = '- ' . $field . ': ' . $message;
        }
        return implode("\n", $lines);
    }


    /**
     * Build the success result payload (with a human-readable before/after).
     *
     * @param array $updated
     * @param array $changes
     * @param array $before
     * @param int $attempts
     * @param int|null $movedto Target section number when the activity was moved, else null.
     * @return array
     */
    private function build_success_result(
        array $updated,
        array $changes,
        array $before,
        int $attempts,
        ?int $movedto = null
    ): array {
        $cmid = (int)($updated['cmid'] ?? 0);
        $modname = (string)($updated['modname'] ?? '');
        $name = (string)($updated['name'] ?? '');
        $url = (string)($updated['url'] ?? '');
        $courseid = 0;
        if (!empty($updated['coursecontextid'])) {
            $cc = \context::instance_by_id((int)$updated['coursecontextid'], IGNORE_MISSING);
            $courseid = $cc ? (int)$cc->instanceid : 0;
        }

        $changed = $this->describe_changes($changes, $before, $movedto);
        $message = 'Updated the activity "' . $name . '" (' . $modname . '). Changed: ' . $changed . '.';

        $observation = implode("\n", [
            'Updated course module cmid=' . $cmid . ' modname=' . $modname . ' (after ' . $attempts . ' attempt(s)).',
            'Changes: ' . $changed,
            'Activity URL: ' . $url,
        ]);

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ($url !== '' ? ' ' . $url : ''),
            'resultid' => null,
            'updated_cmid' => $cmid,
            'updated_courseid' => $courseid,
            'updated_modname' => $modname,
            'updated_name' => $name,
            'activity_url' => $url,
            'affected_scope_summary' => $changed,
            'observation_full' => $observation,
        ];
    }

    /**
     * Build a short human-readable description of the changes (old → new where known).
     *
     * @param array $changes
     * @param array $before
     * @param int|null $movedto Target section number when the activity was moved, else null.
     * @return string
     */
    private function describe_changes(array $changes, array $before, ?int $movedto = null): string {
        $parts = [];
        if (isset($changes['name'])) {
            $parts[] = 'name "' . (string)($before['name'] ?? '') . '" → "' . (string)$changes['name'] . '"';
        }
        if (isset($changes['intro'])) {
            $parts[] = 'description updated';
        }
        if (array_key_exists('visible', $changes)) {
            $was = (int)($before['visible'] ?? 1) === 1 ? 'shown' : 'hidden';
            $now = (int)$changes['visible'] === 1 ? 'shown' : 'hidden';
            $parts[] = 'visibility ' . $was . ' → ' . $now;
        }
        if (isset($changes['settings'])) {
            $keys = array_keys((array)$changes['settings']);
            $parts[] = 'settings (' . implode(', ', $keys) . ')';
        }
        if ($movedto !== null) {
            $parts[] = 'moved from section ' . (int)($before['section'] ?? 0) . ' to section ' . $movedto;
        }
        return empty($parts) ? 'nothing' : implode('; ', $parts);
    }

    /**
     * Build an error result payload.
     *
     * @param string $message
     * @return array
     */
    private function build_error_result(string $message): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
        ];
    }
}
