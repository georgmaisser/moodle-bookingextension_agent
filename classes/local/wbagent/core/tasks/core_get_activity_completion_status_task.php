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

use completion_info;
use context_course;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_get_activity_completion_status_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_activity_completion_status';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get completion status for one activity and user.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'cmid' => ['type' => 'integer', 'required' => true, 'description' => 'Course module id.'],
                'userquery' => ['type' => 'string', 'required' => false, 'description' => 'Optional target user query.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        $errors = [];
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        if (empty($input['cmid'])) {
            $errors[] = get_string('agent_booking_core_cmid_required', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        require_once($GLOBALS['CFG']->libdir . '/completionlib.php');

        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        $targetid = $this->resolve_userid($input, $userid);
        $targetcmid = (int)($input['cmid'] ?? 0);
        if ($courseid <= 0 || $targetcmid <= 0 || $targetid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_completion_invalid_reference', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!$this->can_access_user($userid, $targetid, $courseid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_permission_denied', null, $lang), 'resultid' => null];
        }

        $cm = get_coursemodule_from_id('', $targetcmid, $courseid, false, IGNORE_MISSING);
        if (!$cm) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_module_not_found', null, $lang), 'resultid' => null];
        }

        $completion = new completion_info(get_course($courseid));
        if (!$completion->is_enabled($cm)) {
            return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_completion_disabled', null, $lang), 'resultid' => $targetcmid, 'completionenabled' => false, 'state' => null];
        }

        $data = $completion->get_data($cm, false, $targetid);
        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_completion_loaded', null, $lang), 'resultid' => $targetcmid, 'completionenabled' => true, 'courseid' => $courseid, 'cmid' => $targetcmid, 'userid' => $targetid, 'state' => ['completionstate' => (int)($data->completionstate ?? 0), 'viewed' => (int)($data->viewed ?? 0), 'timemodified' => (int)($data->timemodified ?? 0)]];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_activity_completion_status_request',
            'description' => 'User asks for completion state of an activity.',
            'examples' => ['Completion status for cmid 42', 'Ist Aktivität 42 abgeschlossen?', 'Show completion for user Max and activity 12'],
        ]];
    }
}
