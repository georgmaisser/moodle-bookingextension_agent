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

class core_enrol_user_manual_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_enrol_user_manual';

    public function __construct() {
        parent::__construct(false);
    }

    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Manually enrol a user to a course using the manual enrol plugin (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'userquery' => ['type' => 'string', 'required' => true, 'description' => 'User id/name query.'],
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'role' => ['type' => 'string', 'required' => false, 'description' => 'Optional role shortname, default student.'],
                'confirmed' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true after explicit confirmation.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        $issues = [];
        if (trim((string)($input['userquery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_userquery_required', 'bookingextension_agent');
        }
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        if (empty($input['confirmed'])) {
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_enrol', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => [], 'issues' => $issues];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        require_once($GLOBALS['CFG']->dirroot . '/lib/enrollib.php');

        $lang = $this->get_output_language($input);
        $targetid = $this->resolve_userid($input, $userid);
        $courseid = $this->resolve_courseid($input);
        if ($targetid <= 0 || $courseid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_or_course_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!has_capability('enrol/manual:enrol', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_enrol_permission_denied', null, $lang), 'resultid' => null];
        }

        if (is_enrolled($context, $targetid)) {
            return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_user_already_enrolled', null, $lang), 'resultid' => $targetid, 'alreadyenrolled' => true];
        }

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_manual_enrol_missing', null, $lang), 'resultid' => null];
        }

        $instances = enrol_get_instances($courseid, true);
        $manualinstance = null;
        foreach ($instances as $instance) {
            if (($instance->enrol ?? '') === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }
        if (!$manualinstance) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_manual_enrol_instance_missing', null, $lang), 'resultid' => null];
        }

        $roleid = 5;
        if (!empty($input['role'])) {
            global $DB;
            $role = $DB->get_record('role', ['shortname' => trim((string)$input['role'])], 'id');
            if ($role) {
                $roleid = (int)$role->id;
            }
        }

        $plugin->enrol_user($manualinstance, $targetid, $roleid);
        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_user_enrolled', null, $lang), 'resultid' => $targetid, 'userid' => $targetid, 'courseid' => $courseid, 'alreadyenrolled' => false];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_enrol_user_manual_request',
            'description' => 'User asks to manually enrol someone into a course.',
            'examples' => ['Enrol user 12 in course 5', 'Schreibe Max in Kurs Mathematik ein', 'Manually add Jane to Biology 101'],
        ]];
    }
}
