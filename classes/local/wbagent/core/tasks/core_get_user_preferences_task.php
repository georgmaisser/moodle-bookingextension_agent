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

use core_user;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.core_get_user_preferences.
 */
class core_get_user_preferences_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_user_preferences';

    public function __construct() {
        parent::__construct(true);
    }

    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get user preferences as structured key/value metadata list.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'userquery' => ['type' => 'string', 'required' => false, 'description' => 'Optional user id/name query.'],
                'prefkeys' => ['type' => 'array', 'required' => false, 'description' => 'Optional list of preference keys to filter.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        if (isset($input['prefkeys']) && !is_array($input['prefkeys'])) {
            $errors[] = get_string('agent_booking_core_prefkeys_invalid', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        global $DB;

        $outputlang = $this->get_output_language($input);
        $targetid = $this->resolve_userid($input, $userid);
        if ($targetid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_not_found', null, $outputlang), 'resultid' => null];
        }

        if (!$this->can_access_user($userid, $targetid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_permission_denied', null, $outputlang), 'resultid' => null];
        }

        $keysfilter = [];
        if (!empty($input['prefkeys']) && is_array($input['prefkeys'])) {
            $keysfilter = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $input['prefkeys'])));
        }

        $prefs = $DB->get_records('user_preferences', ['userid' => $targetid]);
        $items = [];
        foreach ($prefs as $pref) {
            $name = (string)$pref->name;
            if (!empty($keysfilter) && !in_array($name, $keysfilter, true)) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'value' => (string)$pref->value,
                'isplugin' => strpos($name, '_') !== false,
            ];
        }

        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_core_user_preferences_loaded', count($items), $outputlang),
            'resultid' => $targetid,
            'userid' => $targetid,
            'preferences' => $items,
            'count' => count($items),
            'usermessage' => $this->localized_string('agent_booking_core_user_preferences_loaded', count($items), $outputlang),
        ];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_user_preferences_request',
            'description' => 'User asks to inspect Moodle user preferences.',
            'examples' => ['Show my preferences', 'Zeige Präferenzen von Benutzer 12', 'List theme preference keys'],
        ]];
    }
}
