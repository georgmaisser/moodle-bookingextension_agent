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

namespace bookingextension_agent\local\wizard\config;

/**
 * Reads a retention setting given in days, where 0 means "keep forever".
 *
 * Only an unset or empty setting falls back to the default. An explicit 0 stays 0, so a caller can keep the data
 * forever as the setting describes (Wunderbyte-GmbH/Wunderbyte-GmbH#2585).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class retention_setting {
    /**
     * Return the configured retention in days.
     *
     * @param string $name Setting name in the agent's config.
     * @param int $default Days used when the setting is unset or empty.
     * @return int Days to keep, 0 = keep forever. Never negative.
     */
    public static function days(string $name, int $default): int {
        $configured = get_config('bookingextension_agent', $name);
        if ($configured === false || trim((string)$configured) === '') {
            return max(0, $default);
        }

        return max(0, (int)$configured);
    }
}
