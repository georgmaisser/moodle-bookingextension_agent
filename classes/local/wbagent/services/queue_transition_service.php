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
}