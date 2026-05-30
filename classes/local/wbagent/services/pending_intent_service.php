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
 * Pending intent service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\conversation_store;

/**
 * Centralizes pending-intent read/write/consume flows.
 */
class pending_intent_service {
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
     * Get pending intent for a thread.
     *
     * @param int $threadid
     * @return array<string,mixed>|null
     */
    public function get(int $threadid): ?array {
        return $this->store->get_pending_intent($threadid);
    }

    /**
     * Consume pending intent once for user/context scope.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @return array<string,mixed>|null
     */
    public function consume(int $threadid, int $userid, int $contextid): ?array {
        return $this->store->consume_pending_intent($threadid, $userid, $contextid);
    }

    /**
     * Clear pending intent for a thread.
     *
     * @param int $threadid
     * @return void
     */
    public function clear(int $threadid): void {
        $this->store->clear_pending_intent($threadid);
    }

    /**
     * Persist a pending intent and return confirmation code.
     *
     * @param int $threadid
     * @param array<int,array<string,mixed>> $commands
     * @param int $userid
     * @param int $contextid
     * @param array<string,mixed> $metadata
     * @return string
     */
    public function set(
        int $threadid,
        array $commands,
        int $userid,
        int $contextid,
        array $metadata = []
    ): string {
        $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($commands));
        $this->store->set_pending_intent(
            $threadid,
            $commands,
            $intentkey,
            $userid,
            $contextid,
            $metadata
        );

        $pending = $this->store->get_pending_intent($threadid);
        return (string)($pending['confirmationcode'] ?? '');
    }
}
