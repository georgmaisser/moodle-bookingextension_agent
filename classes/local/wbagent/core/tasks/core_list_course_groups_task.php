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

class core_list_course_groups_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_list_course_groups';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'List groups for a course including member counts.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'includehidden' => ['type' => 'boolean', 'required' => false, 'description' => 'Include hidden groups.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
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
        if (!has_capability('moodle/site:accessallgroups', $context) && !has_capability('moodle/course:viewparticipants', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_groups_permission_denied', null, $lang), 'resultid' => null];
        }

        $includehidden = !empty($input['includehidden']);
        $groups = groups_get_all_groups($courseid) ?: [];
        $items = [];
        foreach ($groups as $group) {
            if (!$includehidden && !empty($group->visibility) && (int)$group->visibility !== GROUPS_VISIBILITY_ALL) {
                continue;
            }
            $members = groups_get_members((int)$group->id, 'u.id');
            $items[] = ['id' => (int)$group->id, 'name' => (string)$group->name, 'membercount' => count($members), 'visibility' => (int)($group->visibility ?? 0)];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_groups_loaded', count($items), $lang), 'resultid' => $courseid, 'courseid' => $courseid, 'groups' => $items, 'count' => count($items)];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_list_course_groups_request',
            'description' => 'User asks for groups in a course.',
            'examples' => ['List groups in course 5', 'Zeige alle Gruppen im Kurs Mathe', 'What groups exist in Biology 101?'],
        ]];
    }
}
