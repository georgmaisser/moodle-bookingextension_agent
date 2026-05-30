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
 * Shared trigger result helpers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent\services;

/**
 * Trigger checks on normalized interpreter/runtime payloads.
 */
class trigger_result_util {
    /**
     * Check whether normalized result contains a specific trigger id.
     *
     * @param array $result
     * @param string $triggerid
     * @return bool
     */
    public static function has_trigger(array $result, string $triggerid): bool {
        $needle = trim($triggerid);
        if ($needle === '') {
            return false;
        }

        foreach ((array)($result['used_triggers'] ?? []) as $id) {
            if (trim((string)$id) === $needle) {
                return true;
            }
        }

        return false;
    }
}
