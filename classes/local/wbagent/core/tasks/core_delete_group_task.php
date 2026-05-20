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

class core_delete_group_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_delete_group';

    public function __construct() {
        parent::__construct(false);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Delete a course group (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'groupquery' => ['type' => 'string', 'required' => true, 'description' => 'Group id/name query.'],
                'confirmed' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true after explicit confirmation.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        $issues = [];
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        if (trim((string)($input['groupquery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_groupquery_required', 'bookingextension_agent');
        }
        if (empty($input['confirmed'])) {
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_delete_group', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
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

        $members = groups_get_members($groupid, 'u.id');
        if (!empty($members) && !has_capability('moodle/site:accessallgroups', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_group_protected', null, $lang), 'resultid' => null];
        }

        if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_group_not_found_or_ambiguous', null, $lang), 'resultid' => null];
        }

        groups_delete_group($groupid);
        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_group_deleted', null, $lang), 'resultid' => $groupid, 'groupid' => $groupid, 'courseid' => $courseid];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_delete_group_request',
            'description' => 'User asks to delete a group.',
            'examples' => ['Delete group 3 in course 5', 'Lösche Gruppe Alpha', 'Remove group Lab A from Biology'],
        ]];
    }
}
