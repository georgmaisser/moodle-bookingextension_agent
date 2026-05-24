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

class core_list_grade_items_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_list_grade_items';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'List gradebook items for a course.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'includehidden' => ['type' => 'boolean', 'required' => false, 'description' => 'Include hidden grade items.'],
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
        global $DB;

        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        if ($courseid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_course_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_course::instance($courseid);
        if (!has_capability('moodle/grade:viewall', $context) && !has_capability('moodle/grade:view', $context)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_grades_permission_denied', null, $lang), 'resultid' => null];
        }

        $includehidden = !empty($input['includehidden']);
        $records = $DB->get_records('grade_items', ['courseid' => $courseid], 'sortorder ASC');
        $items = [];
        foreach ($records as $record) {
            if (!$includehidden && !empty($record->hidden)) {
                continue;
            }
            $items[] = ['id' => (int)$record->id, 'itemname' => (string)$record->itemname, 'itemtype' => (string)$record->itemtype, 'grademax' => (float)$record->grademax, 'grademin' => (float)$record->grademin, 'hidden' => (bool)$record->hidden];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_grade_items_loaded', count($items), $lang), 'resultid' => $courseid, 'courseid' => $courseid, 'gradeitems' => $items, 'count' => count($items)];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_list_grade_items_request',
            'description' => 'User asks for gradebook items of a course.',
            'examples' => ['List grade items in course 5', 'Zeige Bewertungsobjekte in Mathe', 'What gradebook items exist in Biology 101?'],
        ]];
    }
}
