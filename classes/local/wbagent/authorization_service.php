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
 * Agent authorization service implementation.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use context;
use context_module;
use bookingextension_agent\local\wbagent\interfaces\agent_authorization_service;
use moodle_exception;
use required_capability_exception;

/**
 * Handles authorization checks for the AI agent feature.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization_service implements agent_authorization_service {
    /**
     * Return true when bookingextension_agent is installed and upgraded.
     *
     * @return bool
     */
    public static function is_agent_extension_installed(): bool {
        if (!class_exists('\\core_plugin_manager')) {
            return false;
        }

        try {
            $plugininfo = \core_plugin_manager::instance()->get_plugin_info('bookingextension_agent');
            return ($plugininfo !== null) && (bool)$plugininfo->is_installed_and_upgraded();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve and validate a booking module context by context id.
     *
     * @param int $contextid
     * @return context_module
     */
    private function require_booking_module_context(int $contextid): context_module {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        if (!($context instanceof context_module)) {
            throw new moodle_exception('invalidcontext');
        }
        $cm = get_coursemodule_from_id('booking', (int)$context->instanceid);
        if (!$cm) {
            throw new moodle_exception('invalidcoursemodule', 'bookingextension_agent');
        }
        return $context;
    }

    /**
     * Assert that the given user may use the AI instructions feature for this context.
     *
     * @param int $userid
     * @param int $contextid
     * @return void
     */
    public function require_use_capability(int $userid, int $contextid): void {
        $context = $this->require_booking_module_context($contextid);
        if (!self::is_agent_extension_installed()) {
            throw new required_capability_exception($context, 'mod/booking:useaiinstructions', 'nopermissions', '');
        }
        if (!has_capability('mod/booking:useaiinstructions', $context, $userid)) {
            throw new required_capability_exception($context, 'mod/booking:useaiinstructions', 'nopermissions', '');
        }
    }

    /**
     * Return true if the user has permission to use AI instructions.
     *
     * @param int $userid
     * @param int $contextid
     * @return bool
     */
    public function can_use(int $userid, int $contextid): bool {
        if (!self::is_agent_extension_installed()) {
            return false;
        }

        try {
            $context = $this->require_booking_module_context($contextid);
            return has_capability('mod/booking:useaiinstructions', $context, $userid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Assert that the context belongs to an active booking module.
     *
     * @param int $contextid
     * @return void
     */
    public function require_valid_context(int $contextid): void {
        $this->require_booking_module_context($contextid);
    }
}
