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

namespace bookingextension_agent\local\wbagent\booking;

/**
 * Legacy compatibility shim for booking mutations.
 *
 * The booking task framework no longer hardcodes concrete booking task names.
 * Third-party providers are expected to register their own tasks through the
 * generic discovery and registry layers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_task_mutation_execute_service {
    /**
     * No-op compatibility entry point.
     *
     * @param string $taskname
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param booking_task_support $support
     * @return array<string,mixed>|null
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid, booking_task_support $support): ?array {
        return null;
    }
}