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

namespace bookingextension_agent\local\wbagent\interfaces;

/**
 * Optional provider-owned memory for last preview option ids.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface preview_option_memory_interface {
    /**
     * Store preview option ids for the current user+cmid execution context.
     *
     * @param int $userid
     * @param int $cmid
     * @param array<int,int> $optionids
     * @return void
     */
    public function remember_last_preview_options_for_execute(int $userid, int $cmid, array $optionids): void;

    /**
     * Resolve recently remembered preview option ids for user+cmid.
     *
     * @param int $cmid
     * @param int $userid
     * @return array<int,int>
     */
    public function resolve_last_preview_option_ids_for_execute(int $cmid, int $userid): array;
}
