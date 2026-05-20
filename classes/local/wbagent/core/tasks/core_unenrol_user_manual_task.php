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

class core_unenrol_user_manual_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_unenrol_user_manual';

    public function __construct() {
        parent::__construct(false);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Manually unenrol a user from a course (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'userquery' => ['type' => 'string', 'required' => true, 'description' => 'User id/name query.'],
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
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
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_unenrol', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => [], 'issues' => $issues];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        require_once($GLOBALS['CFG']->dirroot . '/enrol/lib.php');

        $lang = $this->get_output_language($input);
        $targetid = $this->resolve_userid($input, $userid);
        $courseid = $this->resolve_courseid($input);
        if ($targetid <= 0 || $courseid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_or_course_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!has_capability('enrol/manual:unenrol', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_unenrol_permission_denied', null, $lang), 'resultid' => null];
        }

        if (!is_enrolled($context, $targetid)) {
            return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_user_not_enrolled', null, $lang), 'resultid' => $targetid, 'wasenrolled' => false];
        }

        $plugin = enrol_get_plugin('manual');
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if (($instance->enrol ?? '') === 'manual') {
                $plugin->unenrol_user($instance, $targetid);
            }
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_user_unenrolled', null, $lang), 'resultid' => $targetid, 'userid' => $targetid, 'courseid' => $courseid, 'wasenrolled' => true];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_unenrol_user_manual_request',
            'description' => 'User asks to manually unenrol someone from a course.',
            'examples' => ['Unenrol user 12 from course 5', 'Schreibe Max aus Kurs Mathematik aus', 'Remove Jane from Biology 101'],
        ]];
    }
}
