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

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.recall_memory.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recall_memory_task extends core_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.recall_memory';

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
        $schema = [
            'version' => 1,
            'description' => 'Recall previous user-only conversation memory for "last time", "last friday", or a '
                . 'date-window hint. User isolation is strict and userid is never accepted from input.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_recall_memory',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_recall_memory',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'description' => 'Retrieval mode: last_thread or date_window.',
                    'required' => true,
                ],
                'date_hint' => [
                    'type' => 'string',
                    'description' => 'Optional natural language date hint (e.g. "last friday") for date_window mode.',
                    'required' => false,
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional keyword/topic filter for message content or structured payload.',
                    'required' => false,
                ],
                'include_structured' => [
                    'type' => 'boolean',
                    'description' => 'Include decoded structured payloads in returned messages.',
                    'required' => false,
                ],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $mode = trim((string)($input['mode'] ?? ''));
        if (!in_array($mode, ['last_thread', 'date_window'], true)) {
            $errors[] = get_string('agent_booking_recall_memory_invalid_mode', 'bookingextension_agent');
        }
        if ($mode === 'date_window' && trim((string)($input['date_hint'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_recall_memory_date_hint_required', 'bookingextension_agent');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
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
                'id' => 'booking.recall_memory_last_time',
                'description' => 'User asks what was discussed previously.',
                'examples' => [
                    'what did we talk about last time',
                    'didn’t we talk about user x',
                ],
            ],
            [
                'id' => 'booking.recall_memory_date_window',
                'description' => 'User asks for memory in a date window.',
                'examples' => [
                    'what did we talk about last friday',
                    'show me that document again',
                ],
            ],
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
        $store = new conversation_store();
        $mode = trim((string)($input['mode'] ?? 'last_thread'));
        $query = trim((string)($input['query'] ?? ''));
        $includestructured = !empty($input['include_structured']);

        $threadid = 0;
        $fromtimestamp = null;
        $totimestamp = null;
        $messages = [];

        if ($mode === 'last_thread') {
            $thread = $store->get_last_thread_for_user($userid, $contextid);
            $threadid = (int)($thread->id ?? 0);
            if ($threadid > 0) {
                $messages = $store->get_user_messages_for_thread($userid, $threadid, null, null, $query);
            }
        } else {
            $datehint = trim((string)($input['date_hint'] ?? ''));
            $window = $this->resolve_date_window($userid, $datehint);
            if ($window === null) {
                return [
                    'status' => 'error',
                    'detail' => get_string('agent_booking_recall_memory_invalid_date_hint', 'bookingextension_agent'),
                    'resultid' => null,
                    'threadid' => null,
                    'from_timestamp' => null,
                    'to_timestamp' => null,
                    'messages' => [],
                    'memory_observation_text' => '',
                ];
            }

            $fromtimestamp = (int)$window['from_timestamp'];
            $totimestamp = (int)$window['to_timestamp'];
            $threadids = $store->get_user_threads_by_date_window($userid, $contextid, $fromtimestamp, $totimestamp);
            foreach ($threadids as $candidateid) {
                $threadmessages = $store->get_user_messages_for_thread(
                    $userid,
                    (int)$candidateid,
                    $fromtimestamp,
                    $totimestamp,
                    $query
                );
                if (!empty($threadmessages)) {
                    if ($threadid === 0) {
                        $threadid = (int)$candidateid;
                    }
                    $messages = array_merge($messages, $threadmessages);
                }
            }
        }

        if (empty($messages)) {
            return [
                'status' => 'executed',
                'detail' => get_string('agent_booking_recall_memory_no_memory_found', 'bookingextension_agent'),
                'resultid' => null,
                'threadid' => $threadid > 0 ? $threadid : null,
                'from_timestamp' => $fromtimestamp,
                'to_timestamp' => $totimestamp,
                'messages' => [],
                'observation_full' => '',
                'memory_observation_text' => '',
            ];
        }

        $normalizedmessages = [];
        foreach ($messages as $message) {
            $structured = null;
            if ($includestructured) {
                $decoded = json_decode((string)($message->structuredjson ?? ''), true);
                $structured = is_array($decoded) ? $decoded : null;
            }

            $normalizedmessages[] = [
                'role' => (string)($message->role ?? ''),
                'content' => (string)($message->content ?? ''),
                'time' => (int)($message->timecreated ?? 0),
                'structured' => $structured,
            ];
        }

        $observation = $this->build_memory_observation_text($normalizedmessages);

        return [
            'status' => 'executed',
            'detail' => get_string('agent_booking_recall_memory_summary', 'bookingextension_agent', count($normalizedmessages)),
            'resultid' => null,
            'threadid' => $threadid > 0 ? $threadid : null,
            'from_timestamp' => $fromtimestamp,
            'to_timestamp' => $totimestamp,
            'messages' => $normalizedmessages,
            'observation_full' => $observation,
            'memory_observation_text' => $observation,
        ];
    }

    /**
     * Resolve date window for natural language hints.
     *
     * @param int $userid
     * @param string $datehint
     * @return array<string,int>|null
     */
    private function resolve_date_window(int $userid, string $datehint): ?array {
        $normalized = \core_text::strtolower(trim($datehint));
        if ($normalized === '') {
            return null;
        }

        $timezone = $this->resolve_user_timezone($userid);
        $now = new \DateTimeImmutable('now', $timezone);

        if (
            preg_match('/\b(last|previous|letzten|letzter|letzte)\s+friday\b/u', $normalized)
            || preg_match('/\b(letzten|letzter|letzte)\s+freitag\b/u', $normalized)
        ) {
            $day = $now->modify('last friday');
            return [
                'from_timestamp' => $day->setTime(0, 0, 0)->getTimestamp(),
                'to_timestamp' => $day->setTime(23, 59, 59)->getTimestamp(),
            ];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            try {
                $day = new \DateTimeImmutable($normalized, $timezone);
                return [
                    'from_timestamp' => $day->setTime(0, 0, 0)->getTimestamp(),
                    'to_timestamp' => $day->setTime(23, 59, 59)->getTimestamp(),
                ];
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            $day = new \DateTimeImmutable($datehint, $timezone);
            return [
                'from_timestamp' => $day->setTime(0, 0, 0)->getTimestamp(),
                'to_timestamp' => $day->setTime(23, 59, 59)->getTimestamp(),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve timezone for a specific user.
     *
     * @param int $userid
     * @return \DateTimeZone
     */
    private function resolve_user_timezone(int $userid): \DateTimeZone {
        $timezonename = '';

        try {
            $user = \core_user::get_user($userid, 'id,timezone');
            $timezonename = trim((string)($user->timezone ?? ''));
        } catch (\Throwable $e) {
            $timezonename = '';
        }

        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = (string)(get_config('core', 'timezone') ?? '');
        }
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }

        try {
            return new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    /**
     * Build planner-friendly previous-message observation text.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return string
     */
    private function build_memory_observation_text(array $messages): string {
        $lines = ['[MEMORY_CONTEXT] Historical messages from earlier discussion, not the current turn.'];
        $userindex = 1;
        $assistantindex = 1;

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? '');
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($role === 'user') {
                $lines[] = '[USER_PREVIOUS ' . $userindex . '] ' . $content;
                $userindex++;
                continue;
            }

            if ($role === 'assistant') {
                $lines[] = '[ASSISTANT_PREVIOUS ' . $assistantindex . '] ' . $content;
                $assistantindex++;
            }
        }

        return implode("\n", $lines);
    }
}
