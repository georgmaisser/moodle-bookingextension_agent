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

namespace bookingextension_agent\local\wbagent\services\selection;

/**
 * Resolve exact or unique-overlap task names.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_selection_overlap_policy {
    /**
     * Resolve canonical task name from exact name or unique short suffix.
     *
     * @param string $candidate
     * @param array<int,string> $allowedtasks
     * @return string|null
     */
    public function resolve(string $candidate, array $allowedtasks): ?string {
        $name = trim($candidate);
        if ($name === '') {
            return null;
        }

        if (in_array($name, $allowedtasks, true)) {
            return $name;
        }

        if (strpos($name, '.') !== false) {
            return null;
        }

        $matches = array_values(array_filter($allowedtasks, static function (string $taskname) use ($name): bool {
            return substr($taskname, strrpos($taskname, '.') + 1) === $name;
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }
}
