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
 * External service: request a trial key challenge nonce.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use cache;
use context_module;
use context_system;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\authorization_service;

/**
 * Request trial key nonce for back-channel challenge.
 */
class request_trial_key extends external_api {
    /**
     * Describe input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
        ]);
    }

    /**
     * Generate and cache nonce for trial challenge endpoint.
     *
     * @param int $contextid
     * @return array
     */
    public static function execute(int $contextid): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
        ]);

        $authz = new authorization_service();
        try {
            $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
            if (!($context instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
        } catch (\Throwable $e) {
            $context = context_module::instance((int)$params['contextid'], MUST_EXIST);
        }
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

        $token = random_string(32);
        $cache = cache::make('bookingextension_agent', 'trialnonce');
        $cache->set('nonce_' . $token, $token);

        return [
            'success' => true,
            'message' => get_string('aitrial_token_received', 'bookingextension_agent'),
        ];
    }

    /**
     * Describe return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Request status.'),
            'message' => new external_value(PARAM_RAW, 'User-facing status message.'),
        ]);
    }
}
