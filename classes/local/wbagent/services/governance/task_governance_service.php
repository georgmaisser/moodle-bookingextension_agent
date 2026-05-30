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
 * Service for governing task-level enable/disable settings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\governance;

use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Handles admin-settings-level governance for individual agent tasks.
 *
 * Responsible for syncing the master "enable all" toggle to the per-task
 * config entries so that each task's enabled state is explicitly stored and
 * readable without further indirection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_governance_service {
    /**
     * Synchronize per-task toggle settings when "enable all tasks" is triggered.
     *
     * Called as an admin_setting updatedcallback after `aitaskenableall` is saved.
     * If the trigger checkbox was set to 1, every discovered task's individual
     * config key (`aitaskenabled_<taskname>`) is set to 1. Afterwards the trigger
     * is reset to 0 so the setting remains a one-shot action.
     *
     * @return void
     */
    public static function sync_enableall_toggles(): void {
        if (!get_config('bookingextension_agent', 'aitaskenableall')) {
            return;
        }

        try {
            $registry = task_registry_factory::get_default();
            $contracts = $registry->get_task_contracts();

            foreach ($contracts as $taskname => $unusedmeta) {
                $settingname = task_registry::get_task_toggle_setting_name((string)$taskname);
                set_config($settingname, 1, 'bookingextension_agent');
            }
        } catch (\Throwable $e) {
            debugging(
                'bookingextension_agent: unable to sync aitaskenableall toggles: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        } finally {
            // Keep this checkbox as a one-shot trigger, never a persistent on-state.
            set_config('aitaskenableall', 0, 'bookingextension_agent');
        }
    }
}
