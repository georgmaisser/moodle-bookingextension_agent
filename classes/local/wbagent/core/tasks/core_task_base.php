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
use context_system;
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

        if (strpos($query, '@') !== false) {
            $user = \core_user::get_user_by_email($query, 'id', null, IGNORE_MISSING);
            if ($user && !empty($user->id)) {
                return (int)$user->id;
            }
        }

        $matches = $this->search_user_candidates_for_preview($query, 2);
        if (count($matches) === 1) {
            return (int)($matches[0]['userid'] ?? 0);
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

        $matches = $this->search_course_candidates_for_preview($query, 2);
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

    /**
     * Build a normalized user payload with core Moodle user data.
     *
     * @param \stdClass $user
     * @return array<string,mixed>
     */
    protected function build_user_payload(\stdClass $user): array {
        global $CFG;

        if (empty($user->id)) {
            return [];
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $user = clone $user;
        if (function_exists('profile_load_data')) {
            profile_load_data($user);
        }

        $enrolledcourses = $this->build_user_courses_payload((int)$user->id);

        return [
            'userid' => (int)$user->id,
            'username' => (string)($user->username ?? ''),
            'fullname' => fullname($user),
            'firstname' => (string)($user->firstname ?? ''),
            'lastname' => (string)($user->lastname ?? ''),
            'email' => (string)($user->email ?? ''),
            'idnumber' => (string)($user->idnumber ?? ''),
            'institution' => (string)($user->institution ?? ''),
            'department' => (string)($user->department ?? ''),
            'city' => (string)($user->city ?? ''),
            'country' => (string)($user->country ?? ''),
            'address' => (string)($user->address ?? ''),
            'phone1' => (string)($user->phone1 ?? ''),
            'phone2' => (string)($user->phone2 ?? ''),
            'lang' => (string)($user->lang ?? ''),
            'timezone' => (string)($user->timezone ?? ''),
            'description' => (string)($user->description ?? ''),
            'descriptionformat' => (int)($user->descriptionformat ?? 0),
            'auth' => (string)($user->auth ?? ''),
            'confirmed' => (int)($user->confirmed ?? 0),
            'suspended' => (int)($user->suspended ?? 0),
            'deleted' => (int)($user->deleted ?? 0),
            'profileurl' => \core_user::get_profile_url($user)->out(false),
            'enrolledcourses' => $enrolledcourses,
            'roles' => $this->build_user_roles_payload((int)$user->id, $enrolledcourses),
            'customprofilefields' => $this->extract_custom_profile_fields($user),
        ];
    }

    /**
     * Build course enrolment payload for a user.
     *
     * @param int $userid
     * @return array<int,array<string,mixed>>
     */
    protected function build_user_courses_payload(int $userid): array {
        $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname, visible, category, sortorder');
        $payload = [];

        foreach ($courses as $course) {
            $courseid = (int)($course->id ?? 0);
            if ($courseid <= 0) {
                continue;
            }

            $coursepayload = [
                'courseid' => $courseid,
                'fullname' => (string)($course->fullname ?? ''),
                'shortname' => (string)($course->shortname ?? ''),
                'visible' => (int)($course->visible ?? 1),
                'category' => (int)($course->category ?? 0),
                'sortorder' => (int)($course->sortorder ?? 0),
                'roles' => [],
            ];

            $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
            if ($coursecontext) {
                foreach (get_user_roles($coursecontext, $userid, false, 'r.sortorder ASC') as $assignment) {
                    $coursepayload['roles'][] = [
                        'roleid' => (int)($assignment->roleid ?? 0),
                        'shortname' => (string)($assignment->shortname ?? ''),
                        'name' => (string)($assignment->name ?? ''),
                        'contextid' => (int)($assignment->contextid ?? 0),
                        'contextlevel' => (int)($assignment->contextlevel ?? 0),
                        'courseid' => $courseid,
                    ];
                }
            }

            $payload[] = $coursepayload;
        }

        return $payload;
    }

    /**
     * Build flattened role assignment payload for a user.
     *
     * @param int $userid
     * @param array<int,array<string,mixed>> $courses
     * @return array<int,array<string,mixed>>
     */
    protected function build_user_roles_payload(int $userid, array $courses = []): array {
        $payload = [];
        $seen = [];

        $appendassignments = static function (array $assignments, ?int $courseid = null) use (&$payload, &$seen): void {
            foreach ($assignments as $assignment) {
                $roleid = (int)($assignment->roleid ?? 0);
                $contextid = (int)($assignment->contextid ?? 0);
                $key = $contextid . ':' . $roleid;
                if ($roleid <= 0 || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $payload[] = [
                    'roleid' => $roleid,
                    'shortname' => (string)($assignment->shortname ?? ''),
                    'name' => (string)($assignment->name ?? ''),
                    'contextid' => $contextid,
                    'contextlevel' => (int)($assignment->contextlevel ?? 0),
                    'courseid' => $courseid,
                ];
            }
        };

        $appendassignments(get_user_roles(context_system::instance(), $userid, false, 'r.sortorder ASC'), null);

        foreach ($courses as $course) {
            $courseid = (int)($course['courseid'] ?? 0);
            if ($courseid <= 0) {
                continue;
            }

            $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
            if ($coursecontext) {
                $appendassignments(get_user_roles($coursecontext, $userid, false, 'r.sortorder ASC'), $courseid);
            }
        }

        return $payload;
    }

    /**
     * Extract loaded custom profile fields from a user object.
     *
     * @param \stdClass $user
     * @return array<string,mixed>
     */
    protected function extract_custom_profile_fields(\stdClass $user): array {
        $fields = [];
        foreach (get_object_vars($user) as $key => $value) {
            if (strpos((string)$key, 'profile_field_') !== 0) {
                continue;
            }

            $fields[substr((string)$key, strlen('profile_field_'))] = $value;
        }

        return $fields;
    }

    /**
     * Search user candidates using Moodle core APIs only.
     *
     * @param string $query
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    protected function search_user_candidates_for_preview(string $query, int $limit = 10): array {
        global $CFG;

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        require_once($CFG->libdir . '/datalib.php');

        if (preg_match('/^\d+$/', $query)) {
            $user = \core_user::get_user((int)$query, 'id, firstname, lastname, email', IGNORE_MISSING);
            if ($user && !empty($user->id)) {
                return [[
                    'userid' => (int)$user->id,
                    'firstname' => (string)($user->firstname ?? ''),
                    'lastname' => (string)($user->lastname ?? ''),
                    'email' => (string)($user->email ?? ''),
                ]];
            }
        }

        $result = search_users(0, 0, $query, 'lastname ASC, firstname ASC, id ASC');
        $normalized = [];
        foreach ((array)$result as $user) {
            $normalized[] = [
                'userid' => (int)($user->id ?? 0),
                'firstname' => (string)($user->firstname ?? ''),
                'lastname' => (string)($user->lastname ?? ''),
                'email' => (string)($user->email ?? ''),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Search course candidates using Moodle core APIs only.
     *
     * @param string $query Search text or numeric course id.
     * @param int $limit Maximum number of matches.
     * @return array<int,array<string,mixed>>
     */
    protected function search_course_candidates_for_preview(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if (preg_match('/^\d+$/', $query)) {
            $course = get_course((int)$query);
            if (!empty($course->id)) {
                $courseid = (int)$course->id;
                return [[
                    'courseid' => $courseid,
                    'fullname' => (string)($course->fullname ?? ''),
                    'shortname' => (string)($course->shortname ?? ''),
                    'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                    'activeenrolledcount' => $this->count_active_course_enrolments($courseid),
                ]];
            }
        }

        $courses = \core_course_category::search_courses(
            ['search' => $query],
            ['limit' => max(1, $limit), 'sort' => ['fullname' => 1]]
        );

        $normalized = [];
        foreach ($courses as $course) {
            $courseid = (int)($course->id ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            $normalized[] = [
                'courseid' => $courseid,
                'fullname' => (string)($course->fullname ?? ''),
                'shortname' => (string)($course->shortname ?? ''),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'activeenrolledcount' => $this->count_active_course_enrolments($courseid),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Count currently active enrolments for a course.
     *
     * @param int $courseid
     * @return int
     */
    protected function count_active_course_enrolments(int $courseid): int {
        if ($courseid <= 0) {
            return 0;
        }

        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }

        return (int)count_enrolled_users($context, '', 0, true);
    }

    /**
     * Build a full observation string for one or more normalized user payloads.
     *
     * @param array<int,array<string,mixed>> $users
     * @return string
     */
    protected function build_user_observation_full(array $users): string {
        $count = count($users);
        if ($count === 0) {
            return 'Found 0 user(s).';
        }

        $lines = ["Found {$count} user(s):"];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $identityparts = [
                'userid=' . $this->format_observation_scalar($user['userid'] ?? null),
                'username=' . $this->format_observation_scalar($user['username'] ?? null),
                'fullname=' . $this->format_observation_scalar($user['fullname'] ?? null),
                'firstname=' . $this->format_observation_scalar($user['firstname'] ?? null),
                'lastname=' . $this->format_observation_scalar($user['lastname'] ?? null),
                'email=' . $this->format_observation_scalar($user['email'] ?? null),
                'idnumber=' . $this->format_observation_scalar($user['idnumber'] ?? null),
                'institution=' . $this->format_observation_scalar($user['institution'] ?? null),
                'department=' . $this->format_observation_scalar($user['department'] ?? null),
                'city=' . $this->format_observation_scalar($user['city'] ?? null),
                'country=' . $this->format_observation_scalar($user['country'] ?? null),
                'address=' . $this->format_observation_scalar($user['address'] ?? null),
                'phone1=' . $this->format_observation_scalar($user['phone1'] ?? null),
                'phone2=' . $this->format_observation_scalar($user['phone2'] ?? null),
                'lang=' . $this->format_observation_scalar($user['lang'] ?? null),
                'timezone=' . $this->format_observation_scalar($user['timezone'] ?? null),
                'auth=' . $this->format_observation_scalar($user['auth'] ?? null),
                'confirmed=' . $this->format_observation_scalar($user['confirmed'] ?? null),
                'suspended=' . $this->format_observation_scalar($user['suspended'] ?? null),
                'deleted=' . $this->format_observation_scalar($user['deleted'] ?? null),
                'profile=' . $this->format_observation_scalar($user['profileurl'] ?? null),
            ];

            $description = trim((string)($user['description'] ?? ''));
            if ($description !== '') {
                $identityparts[] = 'description=' . $description;
            }

            $lines[] = '- user: ' . implode(', ', $identityparts);
            $lines[] = '  enrolledcourses=' . $this->format_course_observation((array)($user['enrolledcourses'] ?? []));
            $lines[] = '  roles=' . $this->format_role_observation((array)($user['roles'] ?? []));
            $lines[] = '  customprofilefields=' . $this->format_custom_profile_field_observation(
                (array)($user['customprofilefields'] ?? [])
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Format a scalar value for observation output.
     *
     * @param mixed $value
     * @return string
     */
    protected function format_observation_scalar($value): string {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = trim((string)$value);
        return $text === '' ? '-' : $text;
    }

    /**
     * Format enrolled course payloads for observation output.
     *
     * @param array<int,array<string,mixed>> $courses
     * @return string
     */
    protected function format_course_observation(array $courses): string {
        if (empty($courses)) {
            return '[]';
        }

        $parts = [];
        foreach ($courses as $course) {
            if (!is_array($course)) {
                continue;
            }
            $parts[] = '{courseid=' . $this->format_observation_scalar($course['courseid'] ?? null)
                . ', shortname=' . $this->format_observation_scalar($course['shortname'] ?? null)
                . ', fullname=' . $this->format_observation_scalar($course['fullname'] ?? null)
                . ', visible=' . $this->format_observation_scalar($course['visible'] ?? null)
                . ', category=' . $this->format_observation_scalar($course['category'] ?? null)
                . ', roles=' . $this->format_role_observation((array)($course['roles'] ?? []))
                . '}';
        }

        return '[' . implode('; ', $parts) . ']';
    }

    /**
     * Format role payloads for observation output.
     *
     * @param array<int,array<string,mixed>> $roles
     * @return string
     */
    protected function format_role_observation(array $roles): string {
        if (empty($roles)) {
            return '[]';
        }

        $parts = [];
        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            $parts[] = '{roleid=' . $this->format_observation_scalar($role['roleid'] ?? null)
                . ', shortname=' . $this->format_observation_scalar($role['shortname'] ?? null)
                . ', name=' . $this->format_observation_scalar($role['name'] ?? null)
                . ', contextid=' . $this->format_observation_scalar($role['contextid'] ?? null)
                . ', contextlevel=' . $this->format_observation_scalar($role['contextlevel'] ?? null)
                . ', courseid=' . $this->format_observation_scalar($role['courseid'] ?? null)
                . '}';
        }

        return '[' . implode('; ', $parts) . ']';
    }

    /**
     * Format custom profile fields for observation output.
     *
     * @param array<string,mixed> $fields
     * @return string
     */
    protected function format_custom_profile_field_observation(array $fields): string {
        if (empty($fields)) {
            return '[]';
        }

        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $key . '=' . $this->format_observation_scalar($value);
        }

        return '[' . implode('; ', $parts) . ']';
    }
}
