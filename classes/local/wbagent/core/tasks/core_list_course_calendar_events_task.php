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

class core_list_course_calendar_events_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_list_course_calendar_events';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'List course calendar events in optional date range.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'timestart' => ['type' => 'integer', 'required' => false, 'description' => 'Start unix timestamp filter.'],
                'timeend' => ['type' => 'integer', 'required' => false, 'description' => 'End unix timestamp filter.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        if (!empty($input['timestart']) && !empty($input['timeend']) && (int)$input['timestart'] > (int)$input['timeend']) {
            $errors[] = get_string('agent_booking_core_time_range_invalid', 'bookingextension_agent');
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

        $course = get_course($courseid);
        if (!can_access_course($course, $userid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_calendar_permission_denied', null, $lang), 'resultid' => null];
        }

        $events = $DB->get_records('event', ['courseid' => $courseid], 'timestart ASC');
        $start = !empty($input['timestart']) ? (int)$input['timestart'] : null;
        $end = !empty($input['timeend']) ? (int)$input['timeend'] : null;

        $items = [];
        foreach ($events as $event) {
            $timestart = (int)$event->timestart;
            if ($start !== null && $timestart < $start) {
                continue;
            }
            if ($end !== null && $timestart > $end) {
                continue;
            }
            $items[] = ['id' => (int)$event->id, 'name' => (string)$event->name, 'timestart' => $timestart, 'timeduration' => (int)$event->timeduration, 'timezone' => \core_date::get_user_timezone($userid)];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_calendar_events_loaded', count($items), $lang), 'resultid' => $courseid, 'courseid' => $courseid, 'events' => $items, 'count' => count($items)];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_list_course_calendar_events_request',
            'description' => 'User asks for course calendar events.',
            'examples' => ['List course calendar events for course 5', 'Zeige Kalendereinträge für Mathe', 'Course events in next 7 days'],
        ]];
    }
}
