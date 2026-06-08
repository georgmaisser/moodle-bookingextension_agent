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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wbagent\core\skills;

use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface;

/**
 * Skill definition for core.get_current_user.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'core.get_current_user';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Get information about the current executor user.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for skill-authored wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'outputlang' => 'de',
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.get_current_user_request',
                'description' => 'User asks about their current account or profile information.',
                'examples' => [
                    'Who am I?',
                    'Show my profile',
                    'Zeige meinen Benutzernamen',
                ],
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'core.get_current_user',
                'triggers' => [
                    'who am i', 'show my profile', 'wer bin ich', 'zeige mein profil', 'my account',
                ],
                'guidance' => [
                    '- Use core.get_current_user as a FIRST STEP when the request refers to "me",',
                    '  "myself", "mich", or "meine Buchung" and you do not yet know the current userid.',
                    '- Execute this skill and wait for the observation; then pass the resolved userid',
                    '  to any follow-up skill that needs it (e.g. booking.book_users for self-booking).',
                    '- Only call this once per conversation turn unless the user explicitly asks again.',
                ],
            ],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $USER;

        $user = $USER;
        $userdata = $this->build_user_payload($user);
        $fullname = (string)($userdata['fullname'] ?? fullname($user));
        $email = (string)($userdata['email'] ?? $user->email);

        $outputlang = $this->get_output_language($input);
        $usermessage = $this->localized_string(
            'agent_booking_get_current_user_fallback',
            (object)['fullname' => $fullname, 'email' => $email],
            $outputlang
        );

        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_get_current_user_identified', null, $outputlang),
            'resultid' => (int)$user->id,
            'userid' => (int)$user->id,
            'email' => $email,
            'fullname' => $fullname,
            'user' => $userdata,
            'users' => [$userdata],
            'previewmode' => 'user_profile',
            'previewdata' => $userdata,
            'observation_full' => $this->build_user_observation_full([$userdata]),
            'usermessage' => $usermessage,
            'debugmessage' => $this->build_skill_debug_message(
                self::SKILL_NAME,
                $input,
                ['Resolved user: ' . $fullname . ' (id=' . $user->id . ')']
            ),
        ];
    }
}
