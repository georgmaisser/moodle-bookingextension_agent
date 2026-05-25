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
 * Central factory for task registry instances.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * Provides a shared per-request task registry instance.
 */
class task_registry_factory {
    /** @var task_registry|null */
    private static ?task_registry $registry = null;

    /** @var string */
    private static string $lastbuildwarning = '';

    /**
     * Return shared default task registry.
     *
     * @return task_registry
     */
    public static function get_default(): task_registry {
        if (self::$registry === null) {
            self::$lastbuildwarning = '';
            try {
                self::$registry = task_registry::make_default();
            } catch (\Throwable $e) {
                self::$registry = new task_registry();
                self::$lastbuildwarning = 'Task registry fallback activated after build failure: '
                    . get_class($e) . ': ' . $e->getMessage();
                debugging(self::$lastbuildwarning, DEBUG_DEVELOPER);
            }
        }

        return self::$registry;
    }

    /**
     * Return the last non-fatal build warning from get_default().
     *
     * @return string
     */
    public static function get_last_build_warning(): string {
        return self::$lastbuildwarning;
    }

    /**
     * Reset cached registry instance.
     *
     * Intended for tests where component/task set may change between cases.
     *
     * @return void
     */
    public static function reset(): void {
        self::$registry = null;
        self::$lastbuildwarning = '';
    }
}
