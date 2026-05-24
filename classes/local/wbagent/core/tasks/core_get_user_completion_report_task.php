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

class core_get_user_completion_report_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_user_completion_report';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get course-level completion overview for one user.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
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
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        require_once($GLOBALS['CFG']->libdir . '/completionlib.php');

        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        $targetid = $this->resolve_userid($input, $userid);
        if ($courseid <= 0 || $targetid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_or_course_not_found', null, $lang), 'resultid' => null];
        }

        if (!$this->can_access_user($userid, $targetid, $courseid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_permission_denied', null, $lang), 'resultid' => null];
        }

        $completion = new completion_info(get_course($courseid));
        $activities = $completion->get_activities();
        $items = [];
        $completed = 0;
        foreach ($activities as $cm) {
            if (!$completion->is_enabled($cm)) {
                continue;
            }
            $data = $completion->get_data($cm, false, $targetid);
            $state = (int)($data->completionstate ?? 0);
            $iscomplete = in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL], true);
            if ($iscomplete) {
                $completed++;
            }
            $items[] = ['cmid' => (int)$cm->id, 'name' => (string)$cm->name, 'state' => $state, 'iscomplete' => $iscomplete];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_completion_report_loaded', null, $lang), 'resultid' => $targetid, 'courseid' => $courseid, 'userid' => $targetid, 'total' => count($items), 'completed' => $completed, 'activities' => $items];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_user_completion_report_request',
            'description' => 'User asks for user completion report in a course.',
            'examples' => ['Completion report for me in course 5', 'Zeige Abschlussbericht für Max in Mathe', 'How far has Jane progressed in Biology 101?'],
        ]];
    }
}
