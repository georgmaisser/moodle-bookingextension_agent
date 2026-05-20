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

use calendar_event;
use context_course;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_create_calendar_event_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_create_calendar_event';

    public function __construct() {
        parent::__construct(false);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Create a calendar event (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'title' => ['type' => 'string', 'required' => true, 'description' => 'Event title.'],
                'timestart' => ['type' => 'integer', 'required' => true, 'description' => 'Event start timestamp.'],
                'timeend' => ['type' => 'integer', 'required' => true, 'description' => 'Event end timestamp.'],
                'coursequery' => ['type' => 'string', 'required' => false, 'description' => 'Optional course context for course event.'],
                'description' => ['type' => 'string', 'required' => false, 'description' => 'Event description.'],
                'confirmed' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true after explicit confirmation.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        $issues = [];
        if (trim((string)($input['title'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_calendar_title_required', 'bookingextension_agent');
        }
        if (empty($input['timestart']) || empty($input['timeend'])) {
            $errors[] = get_string('agent_booking_core_calendar_time_required', 'bookingextension_agent');
        }
        if (!empty($input['timestart']) && !empty($input['timeend']) && (int)$input['timestart'] >= (int)$input['timeend']) {
            $errors[] = get_string('agent_booking_core_time_range_invalid', 'bookingextension_agent');
        }
        if (empty($input['confirmed'])) {
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_create_calendar_event', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => [], 'issues' => $issues];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        $event = (object)[
            'name' => trim((string)$input['title']),
            'description' => trim((string)($input['description'] ?? '')),
            'format' => FORMAT_HTML,
            'timestart' => (int)$input['timestart'],
            'timeduration' => max(0, (int)$input['timeend'] - (int)$input['timestart']),
            'userid' => 0,
            'courseid' => 0,
            'eventtype' => 'user',
            'type' => CALENDAR_EVENT_TYPE_STANDARD,
        ];

        if ($courseid > 0) {
            $context = context_course::instance($courseid);
            if (!has_capability('moodle/calendar:manageentries', $context)) {
                return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_calendar_permission_denied', null, $lang), 'resultid' => null];
            }
            $event->courseid = $courseid;
            $event->eventtype = 'course';
        } else {
            $event->userid = $userid;
        }

        $created = calendar_event::create($event, false);
        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_calendar_event_created', null, $lang), 'resultid' => (int)$created->id, 'eventid' => (int)$created->id];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_create_calendar_event_request',
            'description' => 'User asks to create a calendar event.',
            'examples' => ['Create calendar event tomorrow 10-11', 'Erstelle Kalendereintrag für Mittwoch', 'Add course event for Biology 101'],
        ]];
    }
}
