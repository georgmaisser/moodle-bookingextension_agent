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

class core_update_group_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_update_group';

    public function __construct() {
        parent::__construct(false);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Update a group name/description (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'groupquery' => ['type' => 'string', 'required' => true, 'description' => 'Group id/name query.'],
                'name' => ['type' => 'string', 'required' => false, 'description' => 'New group name.'],
                'description' => ['type' => 'string', 'required' => false, 'description' => 'New group description.'],
                'confirmed' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true after explicit confirmation.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        $errors = [];
        $issues = [];
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        if (trim((string)($input['groupquery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_groupquery_required', 'bookingextension_agent');
        }
        if (!array_key_exists('name', $input) && !array_key_exists('description', $input)) {
            $errors[] = get_string('agent_booking_core_group_update_fields_required', 'bookingextension_agent');
        }
        if (empty($input['confirmed'])) {
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_update_group', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => [], 'issues' => $issues];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        global $DB;

        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        if ($courseid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_course_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!has_capability('moodle/course:managegroups', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_groups_permission_denied', null, $lang), 'resultid' => null];
        }

        $groupid = $this->resolve_groupid($input, $courseid);
        if ($groupid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_group_not_found_or_ambiguous', null, $lang), 'resultid' => null];
        }

        $group = $DB->get_record('groups', ['id' => $groupid, 'courseid' => $courseid]);
        if (!$group) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_group_not_found_or_ambiguous', null, $lang), 'resultid' => null];
        }

        if (array_key_exists('name', $input) && trim((string)$input['name']) !== '') {
            $group->name = trim((string)$input['name']);
        }
        if (array_key_exists('description', $input)) {
            $group->description = trim((string)$input['description']);
            $group->descriptionformat = FORMAT_HTML;
        }
        groups_update_group($group);

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_group_updated', null, $lang), 'resultid' => $groupid, 'groupid' => $groupid, 'courseid' => $courseid, 'name' => (string)$group->name];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_update_group_request',
            'description' => 'User asks to update a group.',
            'examples' => ['Rename group 3 to Red Team', 'Benenne Gruppe Alpha um', 'Update description of group Lab A'],
        ]];
    }
}
