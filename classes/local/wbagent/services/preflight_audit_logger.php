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

/**
 * Immutable logger for preflight decisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_audit_logger {
    /** Metadata key for serialized audit events. */
    private const META_KEY = '_preflight_audit_log';

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
     * Append a new immutable audit entry.
     *
     * @param int $threadid
     * @param int $runid
     * @param array<string,mixed> $entry
     * @return void
     */
    public function append(int $threadid, int $runid, array $entry): void {
        if (!(bool)get_config('bookingextension_agent', 'preflight_audit_enabled')) {
            return;
        }

        $current = $this->store->get_thread_metadata_value($threadid, self::META_KEY);
        $events = is_array($current) ? array_values($current) : [];
        $contextid = max(0, (int)($entry['contextid'] ?? 0));
        if ($contextid <= 0) {
            $thread = $this->store->get_thread($threadid);
            $contextid = $thread ? max(0, (int)($thread->contextid ?? 0)) : 0;
        }
        $events[] = [
            'timestamp' => time(),
            'thread_id' => $threadid,
            'contextid' => $contextid,
            'run_id' => $runid,
            'queue_item_id' => trim((string)($entry['queue_item_id'] ?? '')),
            'taskname' => trim((string)($entry['taskname'] ?? '')),
            'task_version' => max(0, (int)($entry['task_version'] ?? 0)),
            'layer' => trim((string)($entry['layer'] ?? '')),
            'status' => trim((string)($entry['status'] ?? '')),
            'reason_code' => $this->resolve_reason_code($entry),
            'issue_codes' => array_values(array_unique(array_map('strval', (array)($entry['issue_codes'] ?? [])))),
            'retry_count' => (int)($entry['retry_count'] ?? 0),
            'retry_after_ms' => max(0, (int)($entry['retry_after_ms'] ?? 0)),
            'duration_ms' => (int)($entry['duration_ms'] ?? 0),
            'error_class' => trim((string)($entry['error_class'] ?? '')),
        ];
        $this->store->set_thread_metadata_value($threadid, self::META_KEY, $events);
    }

    /**
     * Return normalized preflight audit events for one thread.
     *
     * @param int $threadid
     * @return array<int,array<string,mixed>>
     */
    public function get_events(int $threadid): array {
        $current = $this->store->get_thread_metadata_value($threadid, self::META_KEY);
        if (!is_array($current)) {
            return [];
        }

        return array_values(array_filter($current, static fn($entry): bool => is_array($entry)));
    }

    /**
     * Build a compact monitoring summary grouped by reason code.
     *
     * @param int $threadid
     * @return array<string,mixed>
     */
    public function summarize_reason_codes(int $threadid): array {
        $events = $this->get_events($threadid);
        $total = count($events);
        $counts = [];
        $bylayer = [];
        $bystatus = [];

        foreach ($events as $event) {
            $reasoncode = trim((string)($event['reason_code'] ?? ''));
            if ($reasoncode === '') {
                $reasoncode = 'UNSPECIFIED';
            }
            $layer = trim((string)($event['layer'] ?? ''));
            $status = trim((string)($event['status'] ?? ''));

            $counts[$reasoncode] = (int)($counts[$reasoncode] ?? 0) + 1;
            if ($layer !== '') {
                $bylayer[$layer] = (int)($bylayer[$layer] ?? 0) + 1;
            }
            if ($status !== '') {
                $bystatus[$status] = (int)($bystatus[$status] ?? 0) + 1;
            }
        }

        arsort($counts);
        arsort($bylayer);
        arsort($bystatus);

        return [
            'total_events' => $total,
            'reason_code_counts' => $counts,
            'layer_counts' => $bylayer,
            'status_counts' => $bystatus,
        ];
    }

    /**
     * Resolve a stable reason code from audit entry context.
     *
     * @param array<string,mixed> $entry
     * @return string
     */
    private function resolve_reason_code(array $entry): string {
        $provided = trim((string)($entry['reason_code'] ?? ''));
        if ($provided !== '') {
            return $provided;
        }

        $status = trim((string)($entry['status'] ?? ''));
        return match ($status) {
            'pass' => 'PREFLIGHT_PASS',
            'soft_block' => 'PREFLIGHT_SOFT_BLOCK',
            'hard_block' => 'PREFLIGHT_HARD_BLOCK',
            'retry_hint' => 'PREFLIGHT_RETRY_HINT',
            'ready' => 'QUEUE_READY',
            'running' => 'QUEUE_RUNNING',
            'retry_waiting' => 'QUEUE_RETRY_WAITING',
            'blocked_confirmation' => 'QUEUE_BLOCKED_CONFIRMATION',
            'failed' => 'QUEUE_FAILED',
            'succeeded' => 'QUEUE_SUCCEEDED',
            'skipped' => 'QUEUE_SKIPPED',
            default => 'UNSPECIFIED',
        };
    }
}
