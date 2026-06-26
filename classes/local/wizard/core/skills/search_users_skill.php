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

namespace bookingextension_agent\local\wizard\core\skills;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;

/**
 * Skill definition for core.search_users.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users_skill extends core_skill_base implements
    skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'core.search_users';

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
            'description' => 'Search users and return resolved candidates with profile data, '
                . 'enrolled courses, roles, and profile URL. Use this first when a '
                . 'follow-up skill needs a concrete user identity.',
            'readonly' => $this->is_read_only(),
            'governance' => [
                'mandatory_on_trigger' => false,
                'intent_triggers' => [
                    // German.
                    'finde nutzer', 'user suchen', 'person suchen',
                    // English.
                    'find user', 'look up person', 'search users',
                ],
            ],
            'fallback_skillcall_string_key' => 'ai_status_skillcall_booking_search_users',
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
     * Return example input for planner contract rendering.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'query' => 'max.mustermann',
            'limit' => 5,
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
                    '  email fragment, or partial id before calling a mutating skill (e.g. booking.book_users).',
                    '- This skill already returns the matched user\'s enrolled courses and assigned roles,',
                    '  so use it before asking for course participation or permission context about a user.',
                    '- Execute this skill and wait for the observation before proceeding to the next step.',
                    '- Return a short preview list of matching users including userid, fullname, profile URL,',
                    '  enrolled courses, and roles when available.',
                    '- If more than one user matches, ask the user to clarify which one they mean.',
                ],
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $input = self::normalize_query_input($input);
        $errors = [];
        $lang = $this->get_output_language($input);
        if (empty($input['query']) || !is_string($input['query'])) {
            $errors[] = $this->localized_string('agent_booking_search_users_required_query', null, $lang);
            $errors[] = $this->build_query_retry_hint();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
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
        $input = self::normalize_query_input($input);
        $query = trim((string)($input['query'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_search_users_required_query', null, $outputlang)
                    . ' ' . $this->build_query_retry_hint(),
                'resultid' => null,
            ];
        }

        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

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
            'observation_full' => $this->build_user_observation_full($payloadusers),
            'debugmessage' => $debugbase . "\n" . implode("\n", $debugextra),
        ];
    }

    /**
     * Normalize common aliases to canonical query for user search.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function normalize_query_input(array $input): array {
        if (!empty($input['query']) && is_scalar($input['query']) && trim((string)$input['query']) !== '') {
            $input['query'] = trim((string)$input['query']);
            return $input;
        }

        $aliases = [
            'userquery',
            'user',
            'username',
            'email',
            'mail',
            'fullname',
            'name',
            'searchterm',
        ];
        foreach ($aliases as $alias) {
            if (!array_key_exists($alias, $input) || !is_scalar($input[$alias])) {
                continue;
            }

            $value = trim((string)$input[$alias]);
            if ($value === '') {
                continue;
            }

            $input['query'] = $value;
            return $input;
        }

        foreach ($input as $key => $value) {
            if (!is_string($key) || in_array($key, ['outputlang', 'limit', 'contextid', 'cmid'], true)) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }

            $text = trim((string)$value);
            if ($text !== '') {
                $input['query'] = $text;
                return $input;
            }
        }

        return $input;
    }

    /**
     * Build a compact retry hint for missing user query input.
     *
     * @return string
     */
    private function build_query_retry_hint(): string {
        return 'Retry core.search_users once with input.query (or alias: userquery, user, username, email, name). '
            . 'Resend exactly one corrected skill_call for the same skill.';
    }

    /**
     * Return the preview descriptor for this skill.
     *
     * @return array
     */
    /**
     * Provide the user-search preview as ready-to-insert server-rendered HTML data.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $users = is_array($resultentry['users'] ?? null) ? (array)$resultentry['users'] : [];
        if (empty($users)) {
            return null;
        }

        $rows = \html_writer::tag(
            'tr',
            \html_writer::tag('th', s('Name')) . \html_writer::tag('th', s('Email')) . \html_writer::tag('th', s('ID'))
        );
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $name = (string)($user['fullname'] ?? $user['username'] ?? '');
            // Entity mentions are always linked (moodle_url-built profileurl from the
            // result payload) — never leave a user as plain text in agent output.
            $profileurl = trim((string)($user['profileurl'] ?? ''));
            $namecell = $profileurl !== ''
                ? \html_writer::link($profileurl, s($name))
                : s($name);
            $rows .= \html_writer::tag(
                'tr',
                \html_writer::tag('td', $namecell)
                . \html_writer::tag('td', s((string)($user['email'] ?? '')))
                . \html_writer::tag('td', s((string)($user['id'] ?? '')))
            );
        }

        $html = \html_writer::tag('table', $rows, ['class' => 'table table-sm booking-ai-preview-user-search']);

        return ['type' => 'user_search', 'html' => $html];
    }
}
