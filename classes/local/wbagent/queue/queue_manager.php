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
 * Shadow queue manager backed by thread metadata.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\queue;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interfaces\queue_identity_provider_interface;
use bookingextension_agent\local\wbagent\services\preflight_execution_gate;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Shadow queue manager for queue status tracking.
 */
class queue_manager {
    /** Metadata key for queue items. */
    private const META_QUEUE_ITEMS = '_task_queue_items';

    /** Metadata key for queue sequence. */
    private const META_QUEUE_SEQ = '_task_queue_seq';

    /** @var conversation_store */
    private conversation_store $store;

    /** @var task_registry|null */
    private ?task_registry $registry;

    /** @var int Default TTL for blocked confirmations in seconds. */
    private const DEFAULT_BLOCKED_TTL_SECONDS = 900;

    /** @var array<int,string> Queue statuses considered terminal. */
    private const TERMINAL_STATUSES = ['succeeded', 'failed', 'skipped'];

    /** @var array<int,string> Queue fields allowed via update_status extra payload. */
    private const ALLOWED_EXTRA_STATUS_FIELDS = [
        'preflight_retry_count',
        'retry_after_ms',
        'backoff_ms',
        'blocked_expires_at',
        'next_retry_at',
        'retry_count',
    ];

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store, ?task_registry $registry = null) {
        $this->store = $store;
        $this->registry = $registry;
    }

    /**
     * Enqueue a command into the shadow queue.
     *
     * @param int $threadid
     * @param int $runid
     * @param int $stepid
     * @param array $command
     * @param string $mutability readonly|mutating
     * @param string $status
     * @param array $dependson
     * @return array<string,mixed>
     */
    public function enqueue_command(
        int $threadid,
        int $runid,
        int $stepid,
        array $command,
        string $mutability,
        string $status,
        array $dependson = []
    ): array {
        $items = $this->get_queue_items($threadid);
        $dependson = array_values(array_map('strval', $dependson));
        $contextid = $this->resolve_thread_contextid($threadid);

        if (
            (bool)get_config('bookingextension_agent', 'queue_dag_validation_enabled')
            && !empty($dependson)
            && !$this->validate_depends_on_is_dag($items, $dependson)
        ) {
            $now = time();
            $seq = $this->next_sequence($threadid);
            $faileditem = [
                'queue_item_id' => 'q' . $threadid . '_' . $seq,
                'thread_id' => $threadid,
                'contextid' => $contextid,
                'run_id' => $runid,
                'step_id' => $stepid,
                'task' => trim((string)($command['task'] ?? '')),
                'input' => is_array($command['input'] ?? null) ? (array)$command['input'] : [],
                'prepared_input' => null,
                'guard_token' => '',
                'input_signature' => '',
                'input_signature_mode' => 'none',
                'input_signature_payload' => [],
                'mutability' => $mutability,
                'depends_on' => $dependson,
                'status' => 'failed',
                'retry_count' => 0,
                'preflight_retry_count' => 0,
                'next_retry_at' => null,
                'retry_after_ms' => 0,
                'backoff_ms' => 0,
                'blocked_expires_at' => null,
                'issue_codes' => ['DEPENDENCY_CYCLE'],
                'error_class' => 'dependency_cycle',
                'last_error_message' => 'depends_on cycle detected during queue ingestion.',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $items[] = $faileditem;
            $this->save_queue_items($threadid, $items);
            return $faileditem;
        }

        $seq = $this->next_sequence($threadid);
        $now = time();

        $task = trim((string)($command['task'] ?? ''));
        $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
        $signaturedetails = $this->build_input_signature_details($task, $input);
        $signature = (string)($signaturedetails['signature'] ?? '');
        $signaturemode = (string)($signaturedetails['mode'] ?? 'raw_input');
        $signaturepayload = is_array($signaturedetails['payload'] ?? null) ? (array)$signaturedetails['payload'] : [];

        // Idempotency: if an equivalent item (same signature) is already active,
        // always reuse it. For mutating commands, also reuse already succeeded
        // items to avoid replaying the same business action across follow-up calls.
        foreach ($items as $existing) {
            if ((string)($existing['input_signature'] ?? '') !== $signature) {
                continue;
            }

            $existingstatus = trim((string)($existing['status'] ?? ''));
            if (!in_array($existingstatus, self::TERMINAL_STATUSES, true)) {
                $existing['dedupe_decision'] = 'reused_active';
                return $existing;
            }

            if ($mutability === 'mutating' && $existingstatus === 'succeeded') {
                $existing['dedupe_decision'] = 'reused_succeeded';
                return $existing;
            }
        }

        $item = [
            'queue_item_id' => 'q' . $threadid . '_' . $seq,
            'thread_id' => $threadid,
            'contextid' => $contextid,
            'run_id' => $runid,
            'step_id' => $stepid,
            'task' => $task,
            'version' => max(1, (int)($command['version'] ?? 1)),
            'input' => $input,
            'prepared_input' => null,
            'guard_token' => '',
            'input_signature' => $signature,
            'input_signature_mode' => $signaturemode,
            'input_signature_payload' => $signaturepayload,
            'dedupe_decision' => 'enqueued',
            'mutability' => $mutability,
            'depends_on' => $dependson,
            'status' => $status,
            'retry_count' => 0,
            'preflight_retry_count' => 0,
            'next_retry_at' => null,
            'retry_after_ms' => 0,
            'backoff_ms' => 0,
            'blocked_expires_at' => $this->resolve_blocked_expires_at($status, $now),
            'issue_codes' => [],
            'error_class' => '',
            'last_error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $items[] = $item;
        $this->save_queue_items($threadid, $items);
        return $item;
    }

    /**
     * Update queue item status and optional issue metadata.
     *
     * @param int $threadid
     * @param string $queueitemid
     * @param string $status
     * @param array $issuecodes
     * @param string $errorclass
     * @param string $lasterrormessage
     * @param array<string,mixed> $extrafields
     * @return void
     */
    public function update_status(
        int $threadid,
        string $queueitemid,
        string $status,
        array $issuecodes = [],
        string $errorclass = '',
        string $lasterrormessage = '',
        array $extrafields = []
    ): void {
        $items = $this->get_queue_items($threadid);
        $now = time();
        foreach ($items as &$item) {
            if ((string)($item['queue_item_id'] ?? '') !== $queueitemid) {
                continue;
            }
            $item['status'] = $status;
            $item['updated_at'] = $now;
            $item['blocked_expires_at'] = $this->resolve_blocked_expires_at($status, $now);
            if (!empty($issuecodes)) {
                $item['issue_codes'] = array_values(array_unique(array_map('strval', $issuecodes)));
            }
            if ($errorclass !== '') {
                $item['error_class'] = $errorclass;
            }
            if ($lasterrormessage !== '') {
                $item['last_error_message'] = $lasterrormessage;
            }
            if (!empty($extrafields)) {
                foreach ($extrafields as $key => $value) {
                    $normalizedkey = trim((string)$key);
                    if (!in_array($normalizedkey, self::ALLOWED_EXTRA_STATUS_FIELDS, true)) {
                        continue;
                    }
                    $item[$normalizedkey] = $value;
                }
            }
            break;
        }
        unset($item);

        $this->save_queue_items($threadid, $items);
    }

    /**
     * Return all queue items for a thread.
     *
     * @param int $threadid
     * @return array<int,array<string,mixed>>
     */
    public function get_queue_items(int $threadid): array {
        $items = $this->store->get_thread_metadata_value($threadid, self::META_QUEUE_ITEMS);
        return is_array($items) ? array_values(array_filter($items, static fn($row): bool => is_array($row))) : [];
    }

    /**
     * Return a single queue item by id.
     *
     * @param int $threadid
     * @param string $queueitemid
     * @return array<string,mixed>|null
     */
    public function get_queue_item(int $threadid, string $queueitemid): ?array {
        $queueitemid = trim($queueitemid);
        if ($queueitemid === '') {
            return null;
        }

        foreach ($this->get_queue_items($threadid) as $item) {
            if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Save queue items for a thread.
     *
     * @param int $threadid
     * @param array $items
     * @return void
     */
    public function save_queue_items(int $threadid, array $items): void {
        $this->store->set_thread_metadata_value($threadid, self::META_QUEUE_ITEMS, array_values($items));
    }

    /**
     * Persist prepared_input for a queue item once preflight resolved it.
     *
     * @param int $threadid
     * @param string $queueitemid
     * @param int $contextid
     * @param array<string,mixed> $preparedinput
     * @return void
     */
    public function set_prepared_input(int $threadid, string $queueitemid, int $contextid, array $preparedinput): void {
        $items = $this->get_queue_items($threadid);
        $now = time();
        foreach ($items as &$item) {
            if ((string)($item['queue_item_id'] ?? '') !== $queueitemid) {
                continue;
            }
            $item['prepared_input'] = $preparedinput;
            $taskname = trim((string)($item['task'] ?? ''));
            $item['guard_token'] = $taskname !== ''
                ? preflight_execution_gate::build_guard_token($taskname, $contextid, $preparedinput)
                : '';
            $item['updated_at'] = $now;
            break;
        }
        unset($item);

        $this->save_queue_items($threadid, $items);
    }

    /**
     * Check whether another queue item is already running in thread.
     *
     * @param int $threadid
     * @param string $excludequeueitemid
     * @return bool
     */
    public function has_running_item(int $threadid, string $excludequeueitemid = ''): bool {
        foreach ($this->get_queue_items($threadid) as $item) {
            if ((string)($item['status'] ?? '') !== 'running') {
                continue;
            }
            if ($excludequeueitemid !== '' && (string)($item['queue_item_id'] ?? '') === $excludequeueitemid) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Atomically acquire the running slot for a queue item.
     *
     * Uses a DB-level row lock (FOR UPDATE on MySQL/PostgreSQL) so concurrent
     * requests cannot both pass the "no other running item" check and both
     * proceed to execute.  On MSSQL the lock clause is omitted; the method
     * still works correctly in single-user scenarios.
     *
     * @param int    $threadid     Thread that owns the queue.
     * @param string $queueitemid  The item that wants to become 'running'.
     * @return bool  true  – slot acquired, item is now persisted as 'running'.
     *               false – another item (or this item) is already running, or item not found.
     */
    public function try_mark_running(int $threadid, string $queueitemid): bool {
        global $DB;

        $queueitemid = trim($queueitemid);
        if ($queueitemid === '') {
            return false;
        }

        try {
            $transaction = $DB->start_delegated_transaction();

            // Lock the thread row so concurrent callers serialise behind this transaction.
            // FOR UPDATE is supported on MySQL/MariaDB and PostgreSQL; skip on MSSQL.
            $forupdate = $DB->get_dbfamily() !== 'mssql' ? ' FOR UPDATE' : '';
            $thread = $DB->get_record_sql(
                "SELECT id, metadatajson FROM {local_wbagent_ai_threads} WHERE id = :id{$forupdate}",
                ['id' => $threadid]
            );

            if (!$thread) {
                $transaction->allow_commit();
                return false;
            }

            $metadata = json_decode((string)($thread->metadatajson ?? ''), true);
            if (!is_array($metadata)) {
                $transaction->allow_commit();
                return false;
            }

            $items = is_array($metadata[self::META_QUEUE_ITEMS] ?? null)
                ? $metadata[self::META_QUEUE_ITEMS]
                : [];

            // Reject if ANY item is already running (including the target itself,
            // which would indicate a concurrent request already acquired the slot).
            foreach ($items as $item) {
                if ((string)($item['status'] ?? '') === 'running') {
                    $transaction->allow_commit();
                    return false;
                }
            }

            // Mark the target item as running.
            $found = false;
            foreach ($items as &$item) {
                if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                    $item['status'] = 'running';
                    $item['updated_at'] = time();
                    $found = true;
                    break;
                }
            }
            unset($item);

            if (!$found) {
                $transaction->allow_commit();
                return false;
            }

            $metadata[self::META_QUEUE_ITEMS] = array_values($items);
            $update = new \stdClass();
            $update->id = $threadid;
            $update->metadatajson = json_encode($metadata);
            $update->timemodified = time();
            $DB->update_record('local_wbagent_ai_threads', $update);

            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            // Transaction rolled back automatically on exception in Moodle.
            return false;
        }
    }

    /**
     * Determine whether a queue item can be picked up right now.
     *
     * @param array<string,mixed> $item
     * @param int|null $now
     * @param array<int,array<string,mixed>>|null $items
     * @return bool
     */
    public function can_pickup_now(array $item, ?int $now = null, ?array $items = null): bool {
        $now = $now ?? time();
        $status = trim((string)($item['status'] ?? ''));
        if (!in_array($status, ['ready', 'retry_waiting'], true)) {
            return false;
        }

        $blockedexpiresat = (int)($item['blocked_expires_at'] ?? 0);
        if ($blockedexpiresat > 0 && $blockedexpiresat > $now) {
            return false;
        }

        $nextretryat = (int)($item['next_retry_at'] ?? 0);
        if ($nextretryat > 0 && $nextretryat > $now) {
            return false;
        }

        if (!$this->dependencies_succeeded_from_items($item, $items)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether all dependencies for a queue item have succeeded.
     *
     * @param int $threadid
     * @param array<string,mixed> $item
     * @return bool
     */
    public function dependencies_succeeded(int $threadid, array $item): bool {
        return $this->dependencies_succeeded_from_items($item, $this->get_queue_items($threadid));
    }

    /**
     * Check dependencies against a provided queue snapshot.
     *
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>>|null $items
     * @return bool
     */
    private function dependencies_succeeded_from_items(array $item, ?array $items = null): bool {
        $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
        if (empty($dependson)) {
            return true;
        }

        if ($items === null) {
            $threadid = (int)($item['thread_id'] ?? 0);
            if ($threadid <= 0) {
                return false;
            }
            $items = $this->get_queue_items($threadid);
        }

        $byid = [];
        foreach ($items as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = trim((string)($candidate['queue_item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byid[$id] = $candidate;
        }

        foreach ($dependson as $dependencyid) {
            if (!isset($byid[$dependencyid])) {
                return false;
            }
            if ((string)($byid[$dependencyid]['status'] ?? '') !== 'succeeded') {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that appending a node with given dependencies keeps graph acyclic.
     *
     * @param array<int,array<string,mixed>> $existingitems
     * @param array<int,string> $newdependson
     * @return bool
     */
    public function validate_depends_on_is_dag(array $existingitems, array $newdependson): bool {
        if (empty($newdependson)) {
            return true;
        }

        $graph = [];
        foreach ($existingitems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['queue_item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $graph[$id] = array_values(array_map('strval', (array)($item['depends_on'] ?? [])));
        }

        $newid = '__new__';
        $graph[$newid] = array_values(array_map('strval', $newdependson));
        $state = [];
        foreach (array_keys($graph) as $node) {
            if ($this->dfs_cycle_detect($node, $graph, $state)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fail blocked confirmation queue items where TTL expired.
     *
     * @param int $threadid
     * @return int Number of changed items.
     */
    public function fail_expired_blocked_items(int $threadid): int {
        $changed = 0;
        $now = time();
        $items = $this->get_queue_items($threadid);
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['status'] ?? '') !== 'blocked_confirmation') {
                continue;
            }
            $expiresat = (int)($item['blocked_expires_at'] ?? 0);
            if ($expiresat <= 0 || $expiresat > $now) {
                continue;
            }
            $item['status'] = 'failed';
            $item['issue_codes'] = ['BLOCKED_CONFIRMATION_TIMEOUT'];
            $item['error_class'] = 'blocked_timeout';
            $item['last_error_message'] = 'blocked_confirmation TTL expired.';
            $item['updated_at'] = $now;
            $changed++;
        }
        unset($item);

        if ($changed > 0) {
            $this->save_queue_items($threadid, $items);
        }
        return $changed;
    }

    /**
     * Build deterministic input signature.
     *
     * @param string $task
     * @param array $input
     * @return string
     */
    public function build_input_signature(string $task, array $input): string {
        $details = $this->build_input_signature_details($task, $input);
        return (string)($details['signature'] ?? '');
    }

    /**
     * Build deterministic input signature plus debug metadata.
     *
     * @param string $task
     * @param array $input
     * @return array{signature:string,mode:string,payload:array<string,mixed>}
     */
    private function build_input_signature_details(string $task, array $input): array {
        $signaturepayload = $input;
        $mode = 'raw_input';

        if ($this->registry !== null) {
            $taskinstance = $this->registry->get_task($task);
            if ($taskinstance instanceof queue_identity_provider_interface) {
                try {
                    $businessidentity = $taskinstance->build_queue_business_identity($input);
                    if (!empty($businessidentity)) {
                        $mode = 'task_business';
                        $signaturepayload = [
                            '__identity_mode' => 'task_business',
                            'identity' => $businessidentity,
                        ];
                    }
                } catch (\Throwable $e) {
                    // Fallback to raw input signature if task-provided identity fails.
                    $mode = 'raw_input';
                    $signaturepayload = $input;
                }
            }
        }

        $normalized = $this->normalize_for_signature($signaturepayload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'signature' => hash('sha256', $task . ':' . (string)$json),
            'mode' => $mode,
            'payload' => is_array($normalized) ? $normalized : ['value' => $normalized],
        ];
    }

    /**
     * Normalize input recursively for stable signature hashing.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_for_signature($value) {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn($entry) => $this->normalize_for_signature($entry), $value);
            }
            ksort($value);
            foreach ($value as $key => $entry) {
                $value[$key] = $this->normalize_for_signature($entry);
            }
            return $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    /**
     * Increment and return per-thread queue sequence.
     *
     * @param int $threadid
     * @return int
     */
    private function next_sequence(int $threadid): int {
        $raw = $this->store->get_thread_metadata_value($threadid, self::META_QUEUE_SEQ);
        $seq = max(0, (int)$raw) + 1;
        $this->store->set_thread_metadata_value($threadid, self::META_QUEUE_SEQ, $seq);
        return $seq;
    }

    /**
     * Resolve thread context id for queue metadata anchoring.
     *
     * @param int $threadid
     * @return int
     */
    private function resolve_thread_contextid(int $threadid): int {
        if ($threadid <= 0) {
            return 0;
        }

        $thread = $this->store->get_thread($threadid);
        return $thread ? max(0, (int)($thread->contextid ?? 0)) : 0;
    }

    /**
     * Resolve blocked_confirmation expiry timestamp by config.
     *
     * @param string $status
     * @param int $now
     * @return int|null
     */
    private function resolve_blocked_expires_at(string $status, int $now): ?int {
        if ($status !== 'blocked_confirmation') {
            return null;
        }
        if (!(bool)get_config('bookingextension_agent', 'queue_blocked_ttl_enabled')) {
            return null;
        }

        $configuredttl = (int)get_config('bookingextension_agent', 'queue_blocked_ttl_seconds');
        $ttl = $configuredttl > 0 ? $configuredttl : self::DEFAULT_BLOCKED_TTL_SECONDS;
        $ttl = max(1, $ttl);
        return $now + $ttl;
    }

    /**
     * DFS helper for cycle detection.
     *
     * @param string $node
     * @param array<string,array<int,string>> $graph
     * @param array<string,int> $state
     * @return bool
     */
    private function dfs_cycle_detect(string $node, array $graph, array &$state): bool {
        $mark = (int)($state[$node] ?? 0);
        if ($mark === 1) {
            return true;
        }
        if ($mark === 2) {
            return false;
        }

        $state[$node] = 1;
        foreach ((array)($graph[$node] ?? []) as $dep) {
            $dep = trim((string)$dep);
            if ($dep === '' || !array_key_exists($dep, $graph)) {
                continue;
            }
            if ($this->dfs_cycle_detect($dep, $graph, $state)) {
                return true;
            }
        }
        $state[$node] = 2;
        return false;
    }
}
