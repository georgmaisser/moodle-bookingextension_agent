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
 * Queue status policy.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

/**
 * Central policy for queue status semantics.
 */
class queue_status_policy {
    /** Actionable mutating statuses. */
    private const ACTIONABLE_MUTATING_STATUSES = ['queued', 'blocked_confirmation', 'ready', 'retry_waiting'];

    /** Statuses that may be picked up for execution when time/dependencies allow. */
    private const PICKUP_READY_STATUSES = ['ready', 'retry_waiting'];

    /**
     * Return actionable mutating statuses.
     *
     * @return array<int,string>
     */
    public static function actionable_mutating_statuses(): array {
        return self::ACTIONABLE_MUTATING_STATUSES;
    }

    /**
     * Return execution-pickup eligible statuses.
     *
     * @return array<int,string>
     */
    public static function pickup_ready_statuses(): array {
        return self::PICKUP_READY_STATUSES;
    }

    /**
     * Check whether mutating queue item status is still actionable.
     *
     * @param string $status
     * @return bool
     */
    public static function is_actionable_mutating_status(string $status): bool {
        return in_array(trim($status), self::ACTIONABLE_MUTATING_STATUSES, true);
    }

    /**
     * Check whether a queue item status can be picked up for execution.
     *
     * @param string $status
     * @return bool
     */
    public static function is_pickup_ready_status(string $status): bool {
        return in_array(trim($status), self::PICKUP_READY_STATUSES, true);
    }
}