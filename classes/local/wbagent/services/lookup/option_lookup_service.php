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

use context_module;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Provides read-only lookup operations for booking options.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_lookup_service {
    /** @var task_registry */
    private task_registry $registry;

    /** @var int */
    private int $userid;

    /**
     * Constructor.
     *
     * @param task_registry|null $registry
     * @param int $userid
     */
    public function __construct(?task_registry $registry = null, int $userid = 0) {
        $this->registry = $registry ?? task_registry::make_default();
        $this->userid = max(0, $userid);
    }

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
        $task = $this->registry->get_task('mod_booking.search_options');
        if ($task === null) {
            return [];
        }

        $input = ['query' => $query, 'limit' => $limit, 'when' => $when];
        $structural = $task->check_structure($input);
        if (!($structural['valid'] ?? false)) {
            return [];
        }

        $contextid = (int)context_module::instance($cmid, MUST_EXIST)->id;
        $result = $task->execute($input, $contextid, $this->userid);
        return is_array($result) ? $result : [];
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
        $task = $this->registry->get_task('mod_booking.update_option');
        if ($task === null) {
            return ['valid' => false, 'errors' => ['Task mod_booking.update_option is not registered.'], 'ambiguities' => []];
        }

        $input = ['optionquery' => $query, 'optionwhen' => $when];
        $structural = $task->check_structure($input);
        return [
            'valid' => (bool)($structural['valid'] ?? false),
            'errors' => array_values(array_map('strval', (array)($structural['errors'] ?? []))),
            'ambiguities' => array_values(array_map('strval', (array)($structural['ambiguities'] ?? []))),
        ];
    }
}
