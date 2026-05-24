<?php
// This file is part of Moodle - https://moodle.org/
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
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_search_course_enrolments_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_search_course_enrolments';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Search users enrolled in a course, optionally filtered by query.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'query' => ['type' => 'string', 'required' => false, 'description' => 'Optional filter for user name/email.'],
                'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Max matches (default 25).'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        $errors = [];
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        if ($courseid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_course_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!has_capability('moodle/course:viewparticipants', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_participants_permission_denied', null, $lang), 'resultid' => null];
        }

        $query = trim((string)($input['query'] ?? ''));
        $limit = max(1, (int)($input['limit'] ?? 25));
        $users = get_enrolled_users($context, '', 0, 'u.id,u.firstname,u.lastname,u.email', 'u.lastname,u.firstname');

        $items = [];
        foreach ($users as $user) {
            $full = fullname($user);
            $haystack = \core_text::strtolower($full . ' ' . (string)$user->email . ' ' . (string)$user->id);
            if ($query !== '' && strpos($haystack, \core_text::strtolower($query)) === false) {
                continue;
            }
            $items[] = ['id' => (int)$user->id, 'fullname' => $full, 'email' => (string)$user->email];
            if (count($items) >= $limit) {
                break;
            }
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_enrolment_search_loaded', count($items), $lang), 'resultid' => $courseid, 'courseid' => $courseid, 'users' => $items, 'count' => count($items)];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_search_course_enrolments_request',
            'description' => 'User asks to find enrolled users in a course.',
            'examples' => ['Find enrolled users named Anna in course 5', 'Suche eingeschriebene Nutzer im Kurs Mathe', 'Search enrolments for john@example.com'],
        ]];
    }
}
