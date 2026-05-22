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
 * External service: activate trial AI context for course and module.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_module;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Activate trial context by enabling AI at course and module level.
 */
class activate_trial_context extends external_api {
    /**
     * Describe input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id.'),
        ]);
    }

    /**
     * Enable AI for the related course and module.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
        ]);

        $authz = new authorization_service();
        $context = context_module::instance($params['cmid']);
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, (int)$context->id);
        require_capability('moodle/site:config', context_system::instance());

        if (!class_exists('\\core_ai\\manager')) {
            return [
                'success' => false,
                'message' => get_string('aitrial_coreai_unavailable', 'bookingextension_agent'),
            ];
        }

        $registry = task_registry::make_default();
        $store = new conversation_store();
        $status = (new orchestrator($registry, new interpreter($registry), $store))
            ->get_runtime_provider_status((int)$params['cmid']);

        if (empty($status['provideractive'])) {
            return [
                'success' => false,
                'message' => get_string('aiready_check_provider_active_todo', 'bookingextension_agent'),
            ];
        }

        $cm = get_coursemodule_from_id('booking', (int)$params['cmid'], 0, false, MUST_EXIST);
        $DB->set_field('course', 'enableaitools', 1, ['id' => (int)$cm->course]);
        $DB->set_field('course_modules', 'enableaitools', 1, ['id' => (int)$params['cmid']]);

        rebuild_course_cache((int)$cm->course, true);

        return [
            'success' => true,
            'message' => get_string('aitrial_activate_success', 'bookingextension_agent'),
        ];
    }

    /**
     * Describe return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Activation status.'),
            'message' => new external_value(PARAM_RAW, 'User-facing status message.'),
        ]);
    }
}
