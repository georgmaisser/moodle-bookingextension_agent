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

namespace bookingextension_agent\local\wbagent\core\tasks;

use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for core.search_users.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users_task extends core_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'core.search_users';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Search users and return resolved candidates with profile data, '
                . 'enrolled courses, roles, and profile URL. Use this first when a '
                . 'follow-up task needs a concrete user identity.',
            'readonly' => $this->is_read_only(),
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_users',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text for first name, last name, email or user id.',
                    'required' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of users to return (default 10).',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.search_users_request',
                'description' => 'User asks to find users by name, email or id.',
                'examples' => [
                    'Find users called John',
                    'Suche Benutzer nach E‑Mail',
                    'Find user with id 42',
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
                'id' => 'core.search_users',
                'triggers' => [
                    'find user', 'search user', 'suche benutzer', 'suche nutzer', 'finde benutzer',
                    'find users', 'search users', 'finde nutzer', 'user lookup',
                ],
                'guidance' => [
                    '- Use core.search_users as a FIRST STEP whenever you need to resolve a person by name,',
                    '  email fragment, or partial id before calling a mutating task (e.g. booking.book_users).',
                    '- This task already returns the matched user\'s enrolled courses and assigned roles,',
                    '  so use it before asking for course participation or permission context about a user.',
                    '- Execute this task and wait for the observation before proceeding to the next step.',
                    '- Return a short preview list of matching users including userid, fullname, profile URL,',
                    '  enrolled courses, and roles when available.',
                    '- If more than one user matches, ask the user to clarify which one they mean.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);
        if (empty($input['query']) || !is_string($input['query'])) {
            $errors[] = $this->localized_string('agent_booking_search_users_required_query', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $query = trim((string)($input['query'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_search_users_required_query', null, $outputlang),
                'resultid' => null,
            ];
        }

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $users = $this->search_user_candidates_for_preview($query, $limit);
        $payloadusers = [];
        foreach ($users as $candidate) {
            $candidateid = (int)($candidate['userid'] ?? 0);
            if ($candidateid <= 0) {
                continue;
            }

            $user = \core_user::get_user($candidateid, '*', MUST_EXIST);
            $payloadusers[] = $this->build_user_payload($user);
        }

        if (empty($users)) {
            $usermessage = $this->localized_string('agent_booking_search_users_no_results', null, $outputlang);
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'users' => [],
                'previewmode' => 'user_search',
                'previewdata' => ['query' => $query, 'users' => []],
                'observation_full' => 'Found 0 user(s).',
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $usermessage = $this->localized_string(
            'agent_booking_search_users_found',
            count($users),
            $outputlang
        );
        $previewids = array_values(array_map(static fn(array $u): int => (int)($u['userid'] ?? 0), $users));
        $debugextra = [
            'Results: ' . count($users),
            'Top user: ' . ((string)($users[0]['fullname'] ?? '') ?: (string)($users[0]['username'] ?? '')) . ' ',
            'Preview user ids: ' . implode(', ', $previewids),
        ];

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)($payloadusers[0]['userid'] ?? ($users[0]['userid'] ?? 0)),
            'users' => $payloadusers,
            'user' => $payloadusers[0] ?? [],
            'previewmode' => 'user_search',
            'previewdata' => ['query' => $query, 'users' => $payloadusers],
            'observation_full' => $this->build_user_observation_full($payloadusers),
            'previewuserids' => $previewids,
            'debugmessage' => $debugbase . "\n" . implode("\n", $debugextra),
        ];
    }
}
