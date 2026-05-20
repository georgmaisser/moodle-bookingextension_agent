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
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_update_calendar_event_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_update_calendar_event';

    public function __construct() {
        parent::__construct(false);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Update calendar event fields (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'eventid' => ['type' => 'integer', 'required' => true, 'description' => 'Calendar event id.'],
                'title' => ['type' => 'string', 'required' => false, 'description' => 'New title.'],
                'timestart' => ['type' => 'integer', 'required' => false, 'description' => 'New start timestamp.'],
                'timeend' => ['type' => 'integer', 'required' => false, 'description' => 'New end timestamp.'],
                'description' => ['type' => 'string', 'required' => false, 'description' => 'New description.'],
                'confirmed' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true after explicit confirmation.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        $issues = [];
        if (empty($input['eventid'])) {
            $errors[] = get_string('agent_booking_core_eventid_required', 'bookingextension_agent');
        }
        if (empty($input['confirmed'])) {
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_update_calendar_event', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => [], 'issues' => $issues];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);
        $eventid = (int)($input['eventid'] ?? 0);
        if ($eventid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_eventid_required', null, $lang), 'resultid' => null];
        }

        try {
            $event = calendar_event::load($eventid);
        } catch (\Throwable $e) {
            $event = null;
        }
        if (!$event) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_calendar_event_not_found', null, $lang), 'resultid' => null];
        }

        if ((int)$event->userid > 0 && (int)$event->userid !== $userid && !is_siteadmin($userid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_calendar_event_owner_denied', null, $lang), 'resultid' => null];
        }

        $payload = (object)['id' => $eventid];
        if (array_key_exists('title', $input)) {
            $payload->name = trim((string)$input['title']);
        }
        if (array_key_exists('description', $input)) {
            $payload->description = trim((string)$input['description']);
            $payload->format = FORMAT_HTML;
        }
        $start = array_key_exists('timestart', $input) ? (int)$input['timestart'] : (int)$event->timestart;
        $end = array_key_exists('timeend', $input) ? (int)$input['timeend'] : ((int)$event->timestart + (int)$event->timeduration);
        $payload->timestart = $start;
        $payload->timeduration = max(0, $end - $start);
        $event->update($payload, false);

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_calendar_event_updated', null, $lang), 'resultid' => $eventid, 'eventid' => $eventid];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_update_calendar_event_request',
            'description' => 'User asks to update an existing calendar event.',
            'examples' => ['Update event 10 title to Sprint', 'Ändere Kalendereintrag 22', 'Move event 15 to 14:00'],
        ]];
    }
}
