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
 * External service: dry-run validation for booking option mutations (no side effects).
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_module;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\dto\bulk_update_options_input_dto;
use bookingextension_agent\local\wbagent\dto\create_option_input_dto;
use bookingextension_agent\local\wbagent\dto\update_option_input_dto;
use bookingextension_agent\local\wbagent\services\mutation\option_mutation_service;


/**
 * Validate a booking option mutation without executing it (dry-run endpoint).
 *
 * Returns validation errors and ambiguities.  No records are created or modified.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_validate_option extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'task'   => new external_value(PARAM_ALPHANUMEXT, 'Mutation type: create, update, or bulk_update.'),
            'fields' => new external_value(PARAM_RAW, 'JSON-encoded option fields to validate.'),
        ]);
    }

    /**
     * Validate a mutation without executing it.
     *
     * @param int    $contextid
     * @param string $task   One of: create, update, bulk_update.
     * @param string $fields JSON-encoded fields.
     * @return array
     */
    public static function execute(int $contextid, string $task, string $fields): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'task'   => $task,
            'fields' => $fields,
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
        $cmid = (int)$context->instanceid;
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        require_capability('mod/booking:updatebooking', $context);

        $fieldsarray = json_decode($params['fields'], true);
        if (!is_array($fieldsarray)) {
            return ['valid' => false, 'errors' => ['Invalid JSON in fields parameter.'], 'ambiguities' => []];
        }

        $service = new option_mutation_service();

        if ($params['task'] === 'create') {
            try {
                $dto = create_option_input_dto::from_array($fieldsarray);
            } catch (\InvalidArgumentException $e) {
                return ['valid' => false, 'errors' => [$e->getMessage()], 'ambiguities' => []];
            }
            $result = $service->validate_create($dto, $cmid);
        } else if ($params['task'] === 'update') {
            $dto    = update_option_input_dto::from_array($fieldsarray);
            $result = $service->validate_update($dto, $cmid);
        } else if ($params['task'] === 'bulk_update') {
            $dto    = bulk_update_options_input_dto::from_array($fieldsarray);
            $result = $service->validate_bulk_update($dto, $cmid);
        } else {
            return [
                'valid'       => false,
                'errors'      => ["Unknown task type: {$params['task']}. Use create, update, or bulk_update."],
                'ambiguities' => [],
            ];
        }

        return [
            'valid'       => (bool)($result['valid'] ?? false),
            'errors'      => array_values(array_map('strval', (array)($result['errors'] ?? []))),
            'ambiguities' => array_values(array_map('strval', (array)($result['ambiguities'] ?? []))),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'valid'       => new external_value(PARAM_BOOL, 'Whether the input is valid.'),
            'errors'      => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message.'),
                'Validation errors.',
                VALUE_DEFAULT,
                []
            ),
            'ambiguities' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Ambiguity message.'),
                'Ambiguous inputs that need clarification.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
