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

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use core_text;

/**
 * Builds and normalizes completed command history for runtime prompt context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completed_command_history_service {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Extract recently completed commands (task + executed input) from assistant state.
     *
     * @param array $messages
     * @return array<int,array<string,mixed>>
     */
    public function extract_from_messages(array $messages): array {
        $completed = [];
        $latestassistantpayload = null;
        $fallbackassistantpayload = null;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured) || empty($structured)) {
                continue;
            }

            if (!is_array($fallbackassistantpayload)) {
                $fallbackassistantpayload = $structured;
            }

            $loopresults = (array)($structured['loop_results'] ?? []);
            $results = (array)($structured['results'] ?? []);
            if (!empty($loopresults) || !empty($results)) {
                $latestassistantpayload = $structured;
                break;
            }
        }

        if (!is_array($latestassistantpayload)) {
            $latestassistantpayload = $fallbackassistantpayload;
        }

        if (!is_array($latestassistantpayload) || empty($latestassistantpayload)) {
            return [];
        }

        $results = (array)($latestassistantpayload['loop_results'] ?? []);
        if (empty($results)) {
            $results = (array)($latestassistantpayload['results'] ?? []);
        }

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = trim((string)($entry['status'] ?? ''));
            if ($status !== 'executed') {
                continue;
            }

            $task = trim((string)($entry['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $input = (array)($entry['executed_input'] ?? $entry['input'] ?? []);
            $compact = ['task' => $task];
            $normalizedinput = $this->normalize_input($input);
            if (!empty($normalizedinput)) {
                $compact['input'] = $normalizedinput;
            }
            $completed[] = $compact;
        }

        if (count($completed) > 12) {
            $completed = array_slice($completed, -12);
        }

        return $completed;
    }

    /**
     * Merge queue-sourced executed commands into completed command history.
     *
     * @param int $threadid
     * @param array<int,array<string,mixed>> $existing
     * @return array<int,array<string,mixed>>
     */
    public function merge_from_queue(int $threadid, array $existing): array {
        if ($threadid <= 0) {
            return $existing;
        }

        $manager = new queue_manager($this->store);
        $queueitems = $manager->get_queue_items($threadid);
        if (empty($queueitems)) {
            return $existing;
        }

        $queuecompleted = [];
        $seen = [];

        foreach ($queueitems as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int)($item['thread_id'] ?? 0) !== $threadid) {
                continue;
            }

            if (trim((string)($item['status'] ?? '')) !== 'succeeded') {
                continue;
            }

            $task = trim((string)($item['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $input = [];
            if (is_array($item['prepared_input'] ?? null)) {
                $input = (array)$item['prepared_input'];
            } else if (is_array($item['input'] ?? null)) {
                $input = (array)$item['input'];
            }

            $compact = ['task' => $task];
            $normalizedinput = $this->normalize_input($input);
            if (!empty($normalizedinput)) {
                $compact['input'] = $normalizedinput;
            }

            $signature = $this->build_signature($compact);
            if ($signature === '' || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $queuecompleted[] = $compact;
        }

        // Queue is authoritative for completed mutation history in the current thread.
        // Only if no succeeded queue items exist, fall back to message-derived evidence.
        $merged = !empty($queuecompleted) ? $queuecompleted : $existing;

        if (count($merged) > 12) {
            $merged = array_slice($merged, -12);
        }

        return $merged;
    }

    /**
     * Build a deterministic signature for completed command deduplication.
     *
     * @param array<string,mixed> $command
     * @return string
     */
    private function build_signature(array $command): string {
        $task = trim((string)($command['task'] ?? ''));
        if ($task === '') {
            return '';
        }

        $input = [];
        if (is_array($command['input'] ?? null)) {
            $input = (array)$command['input'];
        }

        ksort($input);
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        return hash('sha256', $task . '|' . $json);
    }

    /**
     * Normalize executed input for SYSTEM_RUNTIME.completed_commands.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    private function normalize_input(array $input): array {
        $dropkeys = [
            'confirmed',
            'outputlang',
            'lang',
            'user_lang',
            'sessiontoken',
            'sesskey',
        ];

        $normalized = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $dropkeys, true)) {
                continue;
            }

            $cleanvalue = $this->normalize_value($value);
            if ($cleanvalue === null) {
                continue;
            }

            $normalized[$key] = $cleanvalue;
        }

        return $normalized;
    }

    /**
     * Normalize one completed command value recursively.
     *
     * @param mixed $value
     * @return mixed|null
     */
    private function normalize_value($value) {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            return core_text::substr($trimmed, 0, 160);
        }

        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count >= 20) {
                    break;
                }

                $normalized = $this->normalize_value($v);
                if ($normalized === null) {
                    continue;
                }

                if (is_string($k)) {
                    $out[$k] = $normalized;
                } else {
                    $out[] = $normalized;
                }
                $count++;
            }

            return empty($out) ? null : $out;
        }

        return null;
    }
}
