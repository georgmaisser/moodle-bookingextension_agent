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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wbagent\core\tasks;

use context_course;
use bookingextension_agent\local\wbagent\base_task;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Shared helper base for core Moodle data tasks.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class core_task_base extends base_task {
    /**
     * Resolve preferred output language from task input.
     *
     * @param array $input
     * @return string
     */
    protected function get_output_language(array $input): string {
        foreach (['outputlang', 'user_lang', 'lang'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve a plugin-localised string in the requested language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    protected function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $lang = trim($lang);
        if ($lang === '') {
            return get_string($identifier, 'bookingextension_agent', $a);
        }

        return get_string_manager()->get_string($identifier, 'bookingextension_agent', $a, $lang);
    }

    /**
     * Build a compact deterministic debug message for task results.
     *
     * @param string $taskname
     * @param array $input
     * @param array $additionallines
     * @return string
     */
    protected function build_task_debug_message(string $taskname, array $input, array $additionallines = []): string {
        $lines = ['Task: ' . $taskname];

        if (!empty($input)) {
            $debugpairs = [];
            foreach ($input as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $debugpairs[] = $key . '=' . $this->stringify_debug_value($value);
            }
            if (!empty($debugpairs)) {
                $lines[] = 'Input: ' . implode(', ', $debugpairs);
            }
        }

        foreach ($additionallines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Add compact prompt metadata to a schema when the task does not declare it explicitly.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    protected function enrich_schema_with_prompt_meta(array $schema): array {
        if (!empty($schema['prompt_meta']) && is_array($schema['prompt_meta'])) {
            return $schema;
        }

        $properties = (array)($schema['properties'] ?? []);
        $requiredfields = [];
        foreach ($properties as $name => $spec) {
            if (!is_string($name) || !is_array($spec)) {
                continue;
            }
            if (!empty($spec['required'])) {
                $requiredfields[] = $name;
            }
        }

        $anchorfields = [];
        foreach (['query', 'question', 'optionquery', 'userquery', 'coursequery', 'groupquery'] as $field) {
            if (array_key_exists($field, $properties)) {
                $anchorfields[] = $field;
            }
        }

        $schema['prompt_meta'] = [
            'input_fields_for_prompt' => array_values($requiredfields),
            'anchor_fields' => array_values($anchorfields),
        ];

        return $schema;
    }

    /**
     * Convert an arbitrary value into a stable short debug string.
     *
     * @param mixed $value
     * @return string
     */
    private function stringify_debug_value($value): string {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '[complex]';
    }

    /**
     * Resolve user id from optional userquery.
     *
     * @param array $input
     * @param int $currentuserid
     * @return int
     */
    protected function resolve_userid(array $input, int $currentuserid): int {
        $query = trim((string)($input['userquery'] ?? ''));
        if ($query === '' || strtolower($query) === 'current' || strtolower($query) === 'me') {
            return $currentuserid;
        }

        if (ctype_digit($query)) {
            return (int)$query;
        }

        $resolved = \bookingextension_agent\local\wbagent\booking\booking_task_support::resolve_single_user($query);
        if (($resolved['status'] ?? '') === 'ok') {
            return (int)($resolved['userid'] ?? 0);
        }

        return 0;
    }

    /**
     * Resolve course id from optional coursequery.
     *
     * @param array $input
     * @return int
     */
    protected function resolve_courseid(array $input): int {
        $query = trim((string)($input['coursequery'] ?? ''));
        if ($query === '') {
            return 0;
        }

        if (ctype_digit($query)) {
            return (int)$query;
        }

        $matches = \bookingextension_agent\local\wbagent\booking\booking_task_support::search_course_candidates_for_preview(
            $query,
            2
        );
        if (count($matches) === 1) {
            return (int)($matches[0]['courseid'] ?? 0);
        }

        return 0;
    }

    /**
     * Resolve group id from optional groupquery.
     *
     * @param array $input
     * @param int $courseid
     * @return int
     */
    protected function resolve_groupid(array $input, int $courseid = 0): int {
        $groupquery = trim((string)($input['groupquery'] ?? ''));
        if ($groupquery === '') {
            return (int)($input['groupid'] ?? 0);
        }

        if (ctype_digit($groupquery)) {
            return (int)$groupquery;
        }

        if ($courseid <= 0) {
            return 0;
        }

        $groups = groups_get_all_groups($courseid) ?: [];
        $matches = [];
        foreach ($groups as $group) {
            $name = (string)($group->name ?? '');
            if (stripos($name, $groupquery) !== false) {
                $matches[] = (int)$group->id;
            }
        }

        return count($matches) === 1 ? $matches[0] : 0;
    }

    /**
     * Permission gate for accessing another user's data.
     *
     * @param int $actinguserid
     * @param int $targetuserid
     * @param int $courseid
     * @return bool
     */
    protected function can_access_user(int $actinguserid, int $targetuserid, int $courseid = 0): bool {
        if ($actinguserid === $targetuserid) {
            return true;
        }

        if (is_siteadmin($actinguserid)) {
            return true;
        }

        if ($courseid > 0) {
            $context = context_course::instance($courseid, IGNORE_MISSING);
            if ($context && has_capability('moodle/user:viewdetails', $context, $actinguserid)) {
                return true;
            }
        }

        return has_capability('moodle/user:viewdetails', \context_system::instance(), $actinguserid);
    }

    /**
     * Explicit preflight for core readonly tasks — validates structure and passes input unchanged.
     *
     * Core tasks are read-only. No domain writes are performed. This override makes the
     * preflight contract explicit for all concrete core tasks without requiring individual
     * overrides in each task file.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return preflight_result_v2::invalid($issues);
        }
        return preflight_result_v2::ok($input);
    }
}
