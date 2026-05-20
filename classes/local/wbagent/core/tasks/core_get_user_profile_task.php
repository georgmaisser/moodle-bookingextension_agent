<?php
// This file is part of Moodle - http://moodle.org/
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
 * Task definition for booking.core_get_user_profile.
 *
 * @package    mod_booking
 */
class core_get_user_profile_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_user_profile';

    public function __construct() {
        parent::__construct(true);
    }

    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get a structured Moodle user profile for current or target user.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'userquery' => ['type' => 'string', 'required' => false, 'description' => 'Optional user id/name query.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $outputlang = $this->get_output_language($input);
        $targetid = $this->resolve_userid($input, $userid);
        if ($targetid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_not_found', null, $outputlang), 'resultid' => null];
        }

        if (!$this->can_access_user($userid, $targetid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_permission_denied', null, $outputlang), 'resultid' => null];
        }

        $user = core_user::get_user($targetid, '*', MUST_EXIST);
        require_once($GLOBALS['CFG']->dirroot . '/user/lib.php');
        $details = user_get_user_details($user);

        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_core_user_profile_loaded', null, $outputlang),
            'resultid' => (int)$user->id,
            'userid' => (int)$user->id,
            'profile' => [
                'id' => (int)$user->id,
                'username' => (string)$user->username,
                'fullname' => fullname($user),
                'email' => (string)$user->email,
                'lang' => (string)$user->lang,
                'timezone' => (string)$user->timezone,
                'details' => (array)$details,
            ],
            'usermessage' => $this->localized_string('agent_booking_core_user_profile_loaded', null, $outputlang),
        ];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_user_profile_request',
            'description' => 'User asks for a Moodle user profile.',
            'examples' => ['Show profile for user 5', 'Zeige mir mein Profil', 'Get details for Max Mustermann'],
        ]];
    }
}
