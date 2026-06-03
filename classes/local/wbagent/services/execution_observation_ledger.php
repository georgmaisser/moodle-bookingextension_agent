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

/**
 * Persistent execution observation ledger for cross-step planner context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\result_payload_summarizer;

/**
 * Stores canonical task observations from all execution sources.
 */
class execution_observation_ledger {
    /** Metadata key for persisted execution observations. */
    private const META_KEY = '_execution_observations_v1';

    /** Maximum number of persisted observation entries per thread. */
    private const MAX_ENTRIES = 100;

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
     * Append canonical observation entries derived from execution results.
     *
     * @param int $threadid
     * @param array<int,mixed> $results
     * @param array<string,mixed> $meta
     * @return void
     */
    public function append_from_results(int $threadid, array $results, array $meta = []): void {
        if ($threadid <= 0 || empty($results)) {
            return;
        }

        $entries = $this->read_entries($threadid);
        $commands = is_array($meta['commands'] ?? null) ? (array)$meta['commands'] : [];
        $queueitemids = array_values(array_map('strval', (array)($meta['queue_item_ids'] ?? [])));
        $runid = max(0, (int)($meta['run_id'] ?? 0));
        $source = trim((string)($meta['source'] ?? 'unknown'));
        if ($source === '') {
            $source = 'unknown';
        }
        $now = time();

        foreach ($results as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $observationfull = trim((string)($entry['observation_full'] ?? ''));
            $observationcanonical = $observationfull;
            if ($observationcanonical === '') {
                $observationcanonical = trim((string)result_payload_summarizer::describe_result_for_state($entry));
            }
            if ($observationcanonical === '') {
                continue;
            }

            $command = is_array($commands[$idx] ?? null) ? (array)$commands[$idx] : [];
            $task = trim((string)($entry['task'] ?? $command['task'] ?? ''));
            $input = [];
            if (is_array($entry['executed_input'] ?? null)) {
                $input = (array)$entry['executed_input'];
            } else if (is_array($entry['input'] ?? null)) {
                $input = (array)$entry['input'];
            } else if (is_array($command['input'] ?? null)) {
                $input = (array)$command['input'];
            }

            $issuecodes = array_values(array_unique(array_map('strval', (array)($entry['issue_codes'] ?? []))));
            $status = trim((string)($entry['status'] ?? 'unknown'));
            $queueitemid = trim((string)($queueitemids[$idx] ?? ''));

            $ledgerentry = [
                'thread_id' => $threadid,
                'run_id' => $runid,
                'queue_item_id' => $queueitemid,
                'source' => $source,
                'task' => $task,
                'status' => $status,
                'input' => $this->normalize_input($input),
                'observation_canonical' => $observationcanonical,
                'observation_full' => $observationfull,
                'produced_outputs' => is_array($entry['produced_outputs'] ?? null) ? (array)$entry['produced_outputs'] : [],
                'issue_codes' => $issuecodes,
                'created_at' => $now,
            ];

            $signature = $this->build_signature($ledgerentry);
            if ($signature === '') {
                continue;
            }

            $alreadypresent = false;
            foreach ($entries as $existing) {
                if (!is_array($existing)) {
                    continue;
                }
                if ((string)($existing['signature'] ?? '') === $signature) {
                    $alreadypresent = true;
                    break;
                }
            }
            if ($alreadypresent) {
                continue;
            }

            $ledgerentry['signature'] = $signature;
            $entries[] = $ledgerentry;
        }

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        $this->store->set_thread_metadata_value($threadid, self::META_KEY, array_values($entries));
    }

    /**
     * Return compact recent observation entries for SYSTEM_RUNTIME context.
     *
     * @param int $threadid
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function get_recent_for_runtime(int $threadid, int $limit = 12): array {
        $entries = $this->read_entries($threadid);
        if (empty($entries)) {
            return [];
        }

        if ($limit > 0 && count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        $runtime = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $observation = trim((string)($entry['observation_canonical'] ?? ''));
            if ($observation === '') {
                continue;
            }

            $row = [
                'task' => trim((string)($entry['task'] ?? '')),
                'status' => trim((string)($entry['status'] ?? '')),
                'observation' => $observation,
            ];

            if (!empty($entry['input']) && is_array($entry['input'])) {
                $row['input'] = (array)$entry['input'];
            }
            if (!empty($entry['issue_codes']) && is_array($entry['issue_codes'])) {
                $row['issue_codes'] = array_values(array_map('strval', (array)$entry['issue_codes']));
            }

            $runtime[] = $row;
        }

        return $runtime;
    }

    /**
     * Read raw ledger entries from thread metadata.
     *
     * @param int $threadid
     * @return array<int,array<string,mixed>>
     */
    private function read_entries(int $threadid): array {
        $value = $this->store->get_thread_metadata_value($threadid, self::META_KEY);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn($row): bool => is_array($row)));
    }

    /**
     * Normalize input for stable signatures and compact planner context.
     *
     * @param array<string,mixed> $input
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
            $normalized[$key] = $this->normalize_value($value);
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * Recursively normalize scalar/list/map value.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_value($value) {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn($item) => $this->normalize_value($item), $value);
        }

        ksort($value);
        foreach ($value as $k => $item) {
            $value[$k] = $this->normalize_value($item);
        }
        return $value;
    }

    /**
     * Build deterministic dedupe signature.
     *
     * @param array<string,mixed> $entry
     * @return string
     */
    private function build_signature(array $entry): string {
        $task = trim((string)($entry['task'] ?? ''));
        $observation = trim((string)($entry['observation_canonical'] ?? ''));
        if ($task === '' || $observation === '') {
            return '';
        }

        $parts = [
            (string)($entry['run_id'] ?? 0),
            (string)($entry['queue_item_id'] ?? ''),
            $task,
            trim((string)($entry['status'] ?? '')),
            $observation,
            json_encode((array)($entry['input'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        return hash('sha256', implode('|', $parts));
    }
}
