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
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Shared helper base for core Moodle data tasks.
 *
 * @package    bookingextension_agent
 */
abstract class core_task_base extends \bookingextension_agent\local\wbagent\booking\tasks\booking_task_base {
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

        $matches = \bookingextension_agent\local\wbagent\booking\booking_task_support::search_course_candidates_for_preview($query, 2);
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
     * @param int   $cmid
     * @param int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
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
