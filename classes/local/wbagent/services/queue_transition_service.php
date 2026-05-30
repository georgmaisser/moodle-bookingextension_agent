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
 * Queue transition service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\queue\queue_manager;

/**
 * Centralizes queue status transitions.
 */
class queue_transition_service {
    /**
     * Apply canonical preflight decision to mutating queue items.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param array<int,string> $queueitemids
     * @param string $status
     * @param array<int,string> $issuecodes
     * @param array<int,string> $errors
     * @param array<string,mixed> $v2result
     * @param bool $autoconfirmmode
     * @return void
     */
    public function apply_preflight_decision(
        queue_manager $queuesvc,
        int $threadid,
        array $queueitemids,
        string $status,
        array $issuecodes,
        array $errors,
        array $v2result,
        bool $autoconfirmmode
    ): void {
        $queueitemids = $this->normalize_queue_item_ids($queueitemids);
        if (empty($queueitemids)) {
            return;
        }

        $status = trim($status);
        $targetstatus = 'failed';
        $errorclass = '';
        $extrafields = [];
        $message = trim(implode(' ', array_values(array_unique(array_map('strval', $errors)))));

        if ($status === 'pass') {
            $targetstatus = $autoconfirmmode ? 'ready' : 'blocked_confirmation';
        } else if ($status === 'soft_block') {
            $targetstatus = 'blocked_confirmation';
        } else if ($status === 'retry_hint') {
            $targetstatus = 'retry_waiting';
            $errorclass = 'preflight_retry';
        } else {
            $targetstatus = 'failed';
            $errorclass = 'preflight_block';
        }

        foreach ($queueitemids as $queueitemid) {
            $item = $queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }
            if ((string)($item['status'] ?? '') === 'failed' && !empty((array)($item['issue_codes'] ?? []))) {
                continue;
            }

            if ($targetstatus === 'retry_waiting') {
                $currentretrycount = max(0, (int)($item['preflight_retry_count'] ?? $item['retry_count'] ?? 0));
                $nextretrycount = $currentretrycount + 1;
                $retryafterms = max(1, (int)($v2result['retry_after_ms'] ?? 0));
                if ($retryafterms <= 1) {
                    $retryafterms = min(4000, 500 * (2 ** max(0, min(8, $nextretrycount - 1))));
                }
                $extrafields = [
                    'retry_count' => $nextretrycount,
                    'preflight_retry_count' => $nextretrycount,
                    'retry_after_ms' => $retryafterms,
                    'backoff_ms' => $retryafterms,
                    'next_retry_at' => time() + (int)ceil($retryafterms / 1000),
                ];
            }

            if ($targetstatus === 'ready') {
                $this->to_ready($queuesvc, $threadid, $queueitemid, $issuecodes);
            } else if ($targetstatus === 'retry_waiting') {
                $this->to_retry_waiting(
                    $queuesvc,
                    $threadid,
                    $queueitemid,
                    $issuecodes,
                    $errorclass,
                    $message,
                    $extrafields
                );
            } else if ($targetstatus === 'failed') {
                $this->to_failed($queuesvc, $threadid, $queueitemid, $issuecodes, $errorclass, $message);
            } else if ($targetstatus === 'skipped') {
                $this->to_skipped($queuesvc, $threadid, $queueitemid, $issuecodes, $errorclass, $message);
            } else if ($targetstatus === 'succeeded') {
                $this->to_succeeded($queuesvc, $threadid, $queueitemid, $issuecodes);
            } else {
                $this->to_status(
                    $queuesvc,
                    $threadid,
                    $queueitemid,
                    $targetstatus,
                    $issuecodes,
                    $errorclass,
                    $message,
                    $extrafields
                );
            }
        }
    }

    /**
     * Transition queue item to an arbitrary status.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $status
     * @param array<int,string> $issuecodes
     * @param string $errorclass
     * @param string $message
     * @param array<string,mixed> $meta
     * @return void
     */
    public function to_status(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $status,
        array $issuecodes = [],
        string $errorclass = '',
        string $message = '',
        array $meta = []
    ): void {
        $queuesvc->update_status($threadid, $queueitemid, $status, $issuecodes, $errorclass, $message, $meta);
    }

    /**
     * Transition queue item to ready.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<int,string> $issuecodes
     * @return void
     */
    public function to_ready(queue_manager $queuesvc, int $threadid, string $queueitemid, array $issuecodes = []): void {
        $queuesvc->update_status($threadid, $queueitemid, 'ready', $issuecodes);
    }

    /**
     * Transition queue item to retry_waiting.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<int,string> $issuecodes
     * @param string $errorclass
     * @param string $message
     * @param array<string,mixed> $meta
     * @return void
     */
    public function to_retry_waiting(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        array $issuecodes,
        string $errorclass,
        string $message,
        array $meta
    ): void {
        $queuesvc->update_status($threadid, $queueitemid, 'retry_waiting', $issuecodes, $errorclass, $message, $meta);
    }

    /**
     * Transition queue item to failed.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<int,string> $issuecodes
     * @param string $errorclass
     * @param string $message
     * @return void
     */
    public function to_failed(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        array $issuecodes = [],
        string $errorclass = '',
        string $message = ''
    ): void {
        $queuesvc->update_status($threadid, $queueitemid, 'failed', $issuecodes, $errorclass, $message);
    }

    /**
     * Transition queue item to skipped.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<int,string> $issuecodes
     * @param string $errorclass
     * @param string $message
     * @return void
     */
    public function to_skipped(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        array $issuecodes = [],
        string $errorclass = '',
        string $message = ''
    ): void {
        $queuesvc->update_status($threadid, $queueitemid, 'skipped', $issuecodes, $errorclass, $message);
    }

    /**
     * Transition queue item to succeeded.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<int,string> $issuecodes
     * @return void
     */
    public function to_succeeded(queue_manager $queuesvc, int $threadid, string $queueitemid, array $issuecodes = []): void {
        $queuesvc->update_status($threadid, $queueitemid, 'succeeded', $issuecodes);
    }

    /**
     * Normalize queue item ids into non-empty unique string list.
     *
     * @param array<int,mixed> $queueitemids
     * @return array<int,string>
     */
    private function normalize_queue_item_ids(array $queueitemids): array {
        $normalized = [];
        foreach ($queueitemids as $id) {
            $value = trim((string)$id);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}