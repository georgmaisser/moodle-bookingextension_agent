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

class core_get_user_grades_for_course_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_user_grades_for_course';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get user grades summary for one course.',
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
        if (!$this->can_access_user($userid, $targetid, $courseid) && !has_capability('moodle/grade:viewall', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_grades_permission_denied', null, $lang), 'resultid' => null];
        }

        $grades = [];
        $gradeitems = $DB->get_records('grade_items', ['courseid' => $courseid], 'sortorder ASC');
        foreach ($gradeitems as $item) {
            $record = $DB->get_record('grade_grades', ['itemid' => (int)$item->id, 'userid' => $targetid]);
            $grades[] = ['itemid' => (int)$item->id, 'itemname' => (string)$item->itemname, 'finalgrade' => $record ? (float)$record->finalgrade : null, 'rawgrade' => $record ? (float)$record->rawgrade : null, 'grademax' => (float)$item->grademax];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_user_grades_loaded', count($grades), $lang), 'resultid' => $targetid, 'courseid' => $courseid, 'userid' => $targetid, 'grades' => $grades, 'count' => count($grades)];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_user_grades_for_course_request',
            'description' => 'User asks for one user’s grades in a course.',
            'examples' => ['Show my grades in course 5', 'Zeige Noten von Max in Mathe', 'Get grades for user 12 in Biology 101'],
        ]];
    }
}
