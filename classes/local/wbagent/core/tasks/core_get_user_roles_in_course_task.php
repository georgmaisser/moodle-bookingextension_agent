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

class core_get_user_roles_in_course_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_user_roles_in_course';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get user role assignments in a course context.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'userquery' => ['type' => 'string', 'required' => false, 'description' => 'Optional target user query.'],
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
        global $DB;

        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        $targetid = $this->resolve_userid($input, $userid);
        if ($courseid <= 0 || $targetid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_or_course_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!$this->can_access_user($userid, $targetid, $courseid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_permission_denied', null, $lang), 'resultid' => null];
        }

        $assignments = get_user_roles($context, $targetid, true);
        $roles = [];
        foreach ($assignments as $assignment) {
            $role = $DB->get_record('role', ['id' => (int)$assignment->roleid], 'id,shortname,name');
            if (!$role) {
                continue;
            }
            $roles[] = ['id' => (int)$role->id, 'shortname' => (string)$role->shortname, 'name' => (string)$role->name, 'context' => format_string($context->get_context_name(false, true))];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_roles_loaded', count($roles), $lang), 'resultid' => $targetid, 'userid' => $targetid, 'courseid' => $courseid, 'roles' => $roles];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_user_roles_in_course_request',
            'description' => 'User asks for a user’s roles in a course.',
            'examples' => ['Which roles has user 7 in course 5?', 'Welche Rollen hat Max im Kurs?', 'Show my role in Biology 101'],
        ]];
    }
}
