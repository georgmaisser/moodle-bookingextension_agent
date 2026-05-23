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
 * Application service for booking option lookups.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\lookup;

use bookingextension_agent\local\wbagent\booking\booking_task_support;

/**
 * Provides read-only lookup operations for booking options.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_lookup_service {
    /**
     * Search booking options by free-text query.
     *
     * @param int    $cmid
     * @param string $query
     * @param int    $limit
     * @param string $when  Optional temporal hint for disambiguation.
     * @return array
     */
    public function search_options(int $cmid, string $query, int $limit = 10, string $when = ''): array {
        return (new booking_task_support())->execute(
            'booking.search_options',
            ['query' => $query, 'limit' => $limit, 'when' => $when],
            $cmid,
            0
        );
    }

    /**
     * Resolve a single booking option by query.
     *
     * Runs structural checks for update_option against the query and returns
     * the result so callers can inspect errors/ambiguities.
     *
     * @param int    $cmid
     * @param string $query
     * @param string $when  Optional temporal hint.
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function resolve_single_option(int $cmid, string $query, string $when = ''): array {
        return (new booking_task_support())->check_structure(
            'booking.update_option',
            ['optionquery' => $query, 'optionwhen' => $when],
            $cmid
        );
    }
}
