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
        $events[] = [
            'timestamp' => time(),
            'thread_id' => $threadid,
            'run_id' => $runid,
            'layer' => trim((string)($entry['layer'] ?? '')),
            'status' => trim((string)($entry['status'] ?? '')),
            'issue_codes' => array_values(array_unique(array_map('strval', (array)($entry['issue_codes'] ?? [])))),
            'retry_count' => (int)($entry['retry_count'] ?? 0),
            'duration_ms' => (int)($entry['duration_ms'] ?? 0),
            'error_class' => trim((string)($entry['error_class'] ?? '')),
        ];
        $this->store->set_thread_metadata_value($threadid, self::META_KEY, $events);
    }
}

