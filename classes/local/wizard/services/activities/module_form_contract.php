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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\activities;

use moodleform;
use stdClass;

/**
 * Uses a module's real mod_form as the single validation contract and as the source of field defaults.
 *
 * Two read-only operations, no DB writes:
 *  - validate(): build the form headless, read the form's required-element list and its custom validation()
 *    errors against the candidate data → the real, localized field errors / missing fields.
 *  - build_prepared_moduleinfo(): build the form headless, merge the skill's inputs over the form's own
 *    exported defaults → a complete $moduleinfo ready for add_moduleinfo() (run later, in execute).
 *
 * The form's exported values carry every default a module defines (display, numbering, forum type, …) so we
 * never hardcode per-module field tables. The only module-aware code is the thin mapping from the skill's
 * generic input (name / intro / settings{}) onto a module's form fields — and it lives here, in the skill's
 * own service, never in the engine. Headless mform construction is the documented brittleness: every call is
 * guarded; a module whose form refuses to build headless is reported, not fatal.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_form_contract {
    /**
     * Validate candidate input for a module type against its real mod_form (read-only).
     *
     * @param stdClass $course
     * @param string $modname
     * @param int $sectionnum An EXISTING section number (resolved beforehand; never created here).
     * @param string $name
     * @param string $intro
     * @param array<string,mixed> $settings
     * @return array{ok:bool,errors:array<string,string>,built:bool}
     *         errors keyed by field name; built=false means the form could not be built headless.
     */
    public function validate(
        stdClass $course,
        string $modname,
        int $sectionnum,
        string $name,
        string $intro,
        array $settings
    ): array {
        $restoreglobals = $this->push_build_globals($course);
        try {
            try {
                [$mform, $data] = $this->build_form($course, $modname, $sectionnum, $name, $intro, $settings);
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => [], 'built' => false];
            }

            $quickform = $this->quickform($mform);
            if ($quickform === null) {
                return ['ok' => false, 'errors' => [], 'built' => false];
            }

            $errors = $this->collect_form_errors($quickform, $mform, $data);
            return ['ok' => empty($errors), 'errors' => $errors, 'built' => true];
        } finally {
            $this->pop_build_globals($restoreglobals);
        }
    }

    /**
     * Build a complete $moduleinfo for add_moduleinfo(), merging the skill input over the form defaults.
     *
     * @param stdClass $course
     * @param string $modname
     * @param int $sectionnum An EXISTING section number.
     * @param string $name
     * @param string $intro
     * @param array<string,mixed> $settings
     * @return stdClass The prepared moduleinfo (not yet persisted).
     */
    public function build_prepared_moduleinfo(
        stdClass $course,
        string $modname,
        int $sectionnum,
        string $name,
        string $intro,
        array $settings
    ): stdClass {
        $restoreglobals = $this->push_build_globals($course);
        try {
            [$mform, $data] = $this->build_form($course, $modname, $sectionnum, $name, $intro, $settings);

            $moduleinfo = clone $data;

            // Start from the form's own exported values: that carries every field default the module defines
            // (display, numbering, forum type, …) so we never hardcode per-module field tables. Editor fields
            // are skipped and (re)built by apply_inputs so we preserve the scaffold's draft itemids.
            $quickform = $this->quickform($mform);
            if ($quickform !== null) {
                $this->merge_exported($moduleinfo, $quickform);
            }

            // Our explicit inputs win over exported defaults.
            $this->apply_inputs($moduleinfo, $modname, $name, $intro, $settings);

            // Hard invariants add_moduleinfo() relies on.
            $moduleinfo->modulename = $modname;
            $moduleinfo->section = $sectionnum;

            return $moduleinfo;
        } finally {
            $this->pop_build_globals($restoreglobals);
        }
    }

    /**
     * Validate a partial UPDATE of an existing activity against its real mod_form (read-only).
     *
     * @param stdClass $course
     * @param stdClass $cm Course module record (get_coursemodule_from_id).
     * @param array<string,mixed> $changes name/intro/visible/settings — only provided keys change.
     * @return array{ok:bool,errors:array<string,string>,built:bool}
     */
    public function validate_update(stdClass $course, stdClass $cm, array $changes): array {
        $restoreglobals = $this->push_build_globals($course);
        try {
            try {
                [$mform, $data] = $this->build_update_form($course, $cm, $changes);
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => [], 'built' => false];
            }
            $quickform = $this->quickform($mform);
            if ($quickform === null) {
                return ['ok' => false, 'errors' => [], 'built' => false];
            }
            $errors = $this->collect_form_errors($quickform, $mform, $data);
            return ['ok' => empty($errors), 'errors' => $errors, 'built' => true];
        } finally {
            $this->pop_build_globals($restoreglobals);
        }
    }

    /**
     * Build the $moduleinfo for update_moduleinfo(): existing instance values overlaid with the changes.
     *
     * @param stdClass $course
     * @param stdClass $cm
     * @param array<string,mixed> $changes
     * @return stdClass
     */
    public function build_prepared_update_moduleinfo(stdClass $course, stdClass $cm, array $changes): stdClass {
        $restoreglobals = $this->push_build_globals($course);
        try {
            [$mform, $data] = $this->build_update_form($course, $cm, $changes);
            $moduleinfo = clone $data;
            $quickform = $this->quickform($mform);
            if ($quickform !== null) {
                // Update: carry the existing editor content through (don't skip editors).
                $this->merge_exported($moduleinfo, $quickform, false);
            }
            $modname = (string)$data->modulename;
            $this->apply_inputs(
                $moduleinfo,
                $modname,
                (string)($changes['name'] ?? ''),
                (string)($changes['intro'] ?? ''),
                (array)($changes['settings'] ?? [])
            );
            if (array_key_exists('visible', $changes) && $changes['visible'] !== null) {
                $moduleinfo->visible = (int)$changes['visible'];
                $moduleinfo->visibleoncoursepage = 1;
            }
            // Invariants update_moduleinfo() relies on (kept from the existing-instance scaffold).
            $moduleinfo->coursemodule = (int)$data->coursemodule;
            $moduleinfo->instance = (int)$data->instance;
            $moduleinfo->module = (int)$data->module;
            $moduleinfo->modulename = $modname;
            $moduleinfo->course = (int)$course->id;
            $moduleinfo->section = (int)$data->section;
            return $moduleinfo;
        } finally {
            $this->pop_build_globals($restoreglobals);
        }
    }

    /**
     * Build a module's mod_form headless for an EXISTING instance (edit), seeded with current data + changes.
     *
     * @param stdClass $course
     * @param stdClass $cm
     * @param array<string,mixed> $changes
     * @return array{0:moodleform,1:stdClass}
     * @throws \Throwable When the form cannot be built headless.
     */
    private function build_update_form(stdClass $course, stdClass $cm, array $changes): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        // Existing instance data as form data — the core path the edit UI uses.
        [$cmrec, $context, $module, $data, $cw] = get_moduleinfo_data($cm, $course);
        unset($context, $module);
        $modname = (string)$data->modulename;

        $this->apply_inputs(
            $data,
            $modname,
            (string)($changes['name'] ?? ''),
            (string)($changes['intro'] ?? ''),
            (array)($changes['settings'] ?? [])
        );
        if (array_key_exists('visible', $changes) && $changes['visible'] !== null) {
            $data->visible = (int)$changes['visible'];
        }

        $modform = $CFG->dirroot . '/mod/' . $modname . '/mod_form.php';
        if (!file_exists($modform)) {
            throw new \coding_exception('No mod_form for module ' . $modname);
        }
        require_once($modform);
        $classname = 'mod_' . $modname . '_mod_form';
        if (!class_exists($classname)) {
            throw new \coding_exception('No mod_form class for module ' . $modname);
        }
        $mform = new $classname($data, $cw->section, $cmrec, $course);
        $mform->set_data($data);
        return [$mform, $data];
    }

    /**
     * Collect required-field + custom validation() errors from a built form (shared by create + update).
     *
     * @param \MoodleQuickForm $quickform
     * @param moodleform $mform
     * @param stdClass $data
     * @return array<string,string>
     */
    private function collect_form_errors(\MoodleQuickForm $quickform, moodleform $mform, stdClass $data): array {
        $exported = (array)$quickform->exportValues();
        $errors = [];
        foreach (array_unique(array_map('strval', (array)$quickform->_required)) as $element) {
            if ($element === '') {
                continue;
            }
            if ($this->value_is_empty($exported[$element] ?? ($data->{$element} ?? null))) {
                $errors[$element] = get_string('required');
            }
        }
        try {
            $validationinput = array_merge($this->element_defaults($quickform), $this->normalize_for_validation($exported));
            foreach ((array)$mform->validation($validationinput, []) as $field => $message) {
                $errors[(string)$field] = (string)$message;
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return $errors;
    }

    /**
     * Merge a form's exported values into a moduleinfo, skipping markers and editor fields (shared).
     *
     * @param stdClass $moduleinfo
     * @param \MoodleQuickForm $quickform
     * @return void
     */
    private function merge_exported(stdClass $moduleinfo, \MoodleQuickForm $quickform, bool $skipeditors = true): void {
        foreach ((array)$quickform->exportValues() as $key => $value) {
            if (!is_string($key) || $key === '' || strpos($key, '_qf__') === 0 || $key === 'mform_isexpanded_id_general') {
                continue;
            }
            // On CREATE the editor fields are (re)built by apply_inputs to preserve fresh draft itemids; on
            // UPDATE we must carry the form's existing editor content (with its prepared draft itemid) so an
            // unchanged editor field is not lost (e.g. mod_page's required "page" content on a rename).
            if ($skipeditors && in_array($key, ['introeditor', 'page'], true)) {
                continue;
            }
            $moduleinfo->{$key} = $value;
        }
    }

    /**
     * Build the module's mod_form headless and the scaffolded data object.
     *
     * @param stdClass $course
     * @param string $modname
     * @param int $sectionnum
     * @param string $name
     * @param string $intro
     * @param array<string,mixed> $settings
     * @return array{0:moodleform,1:stdClass}
     * @throws \Throwable When the form cannot be built headless.
     */
    private function build_form(
        stdClass $course,
        string $modname,
        int $sectionnum,
        string $name,
        string $intro,
        array $settings
    ): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        // Scaffold (completion defaults, introeditor draft, module id, section): the core path the UI uses.
        [$module, $context, $cw, $cm, $data] = prepare_new_moduleinfo_data($course, $modname, $sectionnum);
        unset($module, $context);

        $this->apply_inputs($data, $modname, $name, $intro, $settings);

        $modform = $CFG->dirroot . '/mod/' . $modname . '/mod_form.php';
        if (!file_exists($modform)) {
            throw new \coding_exception('No mod_form for module ' . $modname);
        }
        require_once($modform);

        $classname = 'mod_' . $modname . '_mod_form';
        if (!class_exists($classname)) {
            throw new \coding_exception('No mod_form class for module ' . $modname);
        }

        // Same construction the UI uses in /course/modedit.php.
        $mform = new $classname($data, $cw->section, $cm, $course);
        $mform->set_data($data);

        return [$mform, $data];
    }

    /**
     * Bind a fresh $PAGE (and thus $COURSE) to the target course for the duration of a mod_form build.
     *
     * Core mod_form definitions (standard_coursemodule_elements: section info, outcomes) read the GLOBAL
     * $COURSE, and the first $OUTPUT access during definition() initialises the theme, which resets
     * $COURSE to $PAGE->course. The UI avoids this because require_login()/$PAGE is already bound to the
     * course. Headless we reproduce that by swapping in a fresh page bound to our course, then restoring —
     * otherwise section info resolves on the site course and core emits "property visible on null".
     *
     * @param stdClass $course
     * @return array{page:mixed,course:mixed} State to restore.
     */
    private function push_build_globals(stdClass $course): array {
        global $PAGE, $COURSE;
        $state = ['page' => $PAGE, 'course' => $COURSE];
        try {
            // Assign the fresh page to the global FIRST: moodle_page::set_course() only updates the global
            // $COURSE when the page it runs on IS the global $PAGE.
            $PAGE = new \moodle_page();
            $PAGE->set_course($course);
        } catch (\Throwable $e) {
            // If a fresh page cannot be bound (unexpected), at least point $COURSE at the target.
            $COURSE = $course;
        }
        return $state;
    }

    /**
     * Restore the $PAGE/$COURSE globals saved by push_build_globals().
     *
     * @param array{page:mixed,course:mixed} $state
     * @return void
     */
    private function pop_build_globals(array $state): void {
        global $PAGE, $COURSE;
        $PAGE = $state['page'];
        $COURSE = $state['course'];
    }

    /**
     * Default value ('') for every named element the form declares.
     *
     * Lets validation() read any element key without undefined-key/null warnings; real exported values
     * are overlaid on top by the caller.
     *
     * @param \MoodleQuickForm $quickform
     * @return array<string,string>
     */
    private function element_defaults(\MoodleQuickForm $quickform): array {
        $defaults = [];
        foreach ((array)$quickform->_elements as $element) {
            if (!is_object($element) || !method_exists($element, 'getName')) {
                continue;
            }
            $name = (string)$element->getName();
            if ($name !== '') {
                $defaults[$name] = '';
            }
        }
        return $defaults;
    }

    /**
     * Coerce null scalar values to '' so core's raw trim()/json_decode() in validation() stay quiet.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function normalize_for_validation(array $values): array {
        foreach ($values as $key => $value) {
            if ($value === null) {
                $values[$key] = '';
            }
        }
        return $values;
    }

    /**
     * Return the underlying MoodleQuickForm via a single, contained reflection access.
     *
     * moodleform keeps its quickform in a protected $_form; there is no public getter. Reading it here
     * (and only here) is the boundary of the documented headless brittleness — guarded and never fatal.
     *
     * @param moodleform $mform
     * @return \MoodleQuickForm|null
     */
    private function quickform(moodleform $mform): ?\MoodleQuickForm {
        try {
            $property = new \ReflectionProperty(moodleform::class, '_form');
            $property->setAccessible(true);
            $quickform = $property->getValue($mform);
            return $quickform instanceof \MoodleQuickForm ? $quickform : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Map the skill's generic input (name / intro / settings) onto a module's form fields.
     *
     * @param stdClass $data
     * @param string $modname
     * @param string $name
     * @param string $intro
     * @param array<string,mixed> $settings
     * @return void
     */
    private function apply_inputs(stdClass $data, string $modname, string $name, string $intro, array $settings): void {
        $name = trim($name);
        $intro = trim($intro);

        // A label has no separate name field; its intro editor is the content. Fall back to the name as text.
        if ($modname === 'label') {
            $text = $intro !== '' ? $intro : $name;
            if ($text !== '') {
                $this->set_editor($data, 'introeditor', $text);
            }
            if ($name !== '') {
                $data->name = $name;
            }
        } else {
            if ($name !== '') {
                $data->name = $name;
            }
            if ($intro !== '') {
                $this->set_editor($data, 'introeditor', $intro);
            }
        }

        // Module-specific input → field mapping for the supported whitelist.
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $key = $this->normalize_setting_key($modname, $key);
            if ($key === 'page') {
                // The mod_page content editor.
                $this->set_editor($data, 'page', (string)$value);
                continue;
            }
            // Generic scalar passthrough (externalurl, display, type, …). Editors/arrays handled above.
            if (is_scalar($value) || $value === null) {
                $data->{$key} = $value;
            }
        }
    }

    /**
     * Normalize common user-facing setting aliases to the module's real field name.
     *
     * @param string $modname
     * @param string $key
     * @return string
     */
    private function normalize_setting_key(string $modname, string $key): string {
        $key = trim($key);
        if ($modname === 'url' && in_array($key, ['url', 'link', 'externalurl'], true)) {
            return 'externalurl';
        }
        if ($modname === 'page' && in_array($key, ['content', 'body', 'text', 'page'], true)) {
            return 'page';
        }
        return $key;
    }

    /**
     * Set an editor-style field ({text, format, itemid}) preserving any existing draft itemid.
     *
     * @param stdClass $data
     * @param string $field
     * @param string $text
     * @return void
     */
    private function set_editor(stdClass $data, string $field, string $text): void {
        $existing = (array)($data->{$field} ?? []);
        $data->{$field} = [
            'text' => $text,
            'format' => isset($existing['format']) ? (int)$existing['format'] : FORMAT_HTML,
            'itemid' => isset($existing['itemid']) ? (int)$existing['itemid'] : 0,
        ];
    }

    /**
     * Whether an exported form value counts as empty for required-field detection.
     *
     * @param mixed $value
     * @return bool
     */
    private function value_is_empty($value): bool {
        if (is_array($value)) {
            // Editor arrays: empty when the text is blank.
            if (array_key_exists('text', $value)) {
                return trim((string)$value['text']) === '';
            }
            return empty($value);
        }
        return trim((string)$value) === '';
    }
}
