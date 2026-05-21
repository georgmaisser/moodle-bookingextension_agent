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

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
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
        $seq = $this->next_sequence($threadid);
        $now = time();

        $task = trim((string)($command['task'] ?? ''));
        $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
        $signature = $this->build_input_signature($task, $input);

        $item = [
            'queue_item_id' => 'q' . $threadid . '_' . $seq,
            'thread_id' => $threadid,
            'run_id' => $runid,
            'step_id' => $stepid,
            'task' => $task,
            'input' => $input,
            'prepared_input' => null,
            'input_signature' => $signature,
            'mutability' => $mutability,
            'depends_on' => array_values(array_map('strval', $dependson)),
            'status' => $status,
            'retry_count' => 0,
            'next_retry_at' => null,
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
     * @return void
     */
    public function update_status(
        int $threadid,
        string $queueitemid,
        string $status,
        array $issuecodes = [],
        string $errorclass = '',
        string $lasterrormessage = ''
    ): void {
        $items = $this->get_queue_items($threadid);
        $now = time();
        foreach ($items as &$item) {
            if ((string)($item['queue_item_id'] ?? '') !== $queueitemid) {
                continue;
            }
            $item['status'] = $status;
            $item['updated_at'] = $now;
            if (!empty($issuecodes)) {
                $item['issue_codes'] = array_values(array_unique(array_map('strval', $issuecodes)));
            }
            if ($errorclass !== '') {
                $item['error_class'] = $errorclass;
            }
            if ($lasterrormessage !== '') {
                $item['last_error_message'] = $lasterrormessage;
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
     * Build deterministic input signature.
     *
     * @param string $task
     * @param array $input
     * @return string
     */
    public function build_input_signature(string $task, array $input): string {
        $normalized = $this->normalize_for_signature($input);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $task . ':' . (string)$json);
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
}
