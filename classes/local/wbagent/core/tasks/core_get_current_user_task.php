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
 * Task definition for booking.core_get_current_user.
 */
class core_get_current_user_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_current_user';

    public function __construct() {
        parent::__construct(true);
    }

    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get current user profile summary safely for authenticated or guest context.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        global $USER;

        $outputlang = $this->get_output_language($input);
        if (isguestuser() || empty($USER->id)) {
            return [
                'status' => 'executed',
                'detail' => $this->localized_string('agent_booking_core_current_user_guest', null, $outputlang),
                'resultid' => 0,
                'user' => ['id' => 0, 'isguest' => true],
                'usermessage' => $this->localized_string('agent_booking_core_current_user_guest', null, $outputlang),
            ];
        }

        $user = core_user::get_user((int)$USER->id, 'id,username,firstname,lastname,email,lang,timezone', MUST_EXIST);
        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_core_current_user_loaded', null, $outputlang),
            'resultid' => (int)$user->id,
            'userid' => (int)$user->id,
            'user' => [
                'id' => (int)$user->id,
                'username' => (string)$user->username,
                'fullname' => fullname($user),
                'email' => (string)$user->email,
                'lang' => (string)$user->lang,
                'timezone' => (string)$user->timezone,
                'isguest' => false,
            ],
            'usermessage' => $this->localized_string('agent_booking_core_current_user_loaded', null, $outputlang),
        ];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_current_user_request',
            'description' => 'User asks who they are in the current Moodle session.',
            'examples' => ['Who am I now?', 'Wer bin ich aktuell?', 'Show current user summary'],
        ]];
    }
}
