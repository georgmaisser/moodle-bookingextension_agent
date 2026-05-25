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
 * External service: update a booking option (v1 Application-Service API).
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
use bookingextension_agent\local\wbagent\dto\update_option_input_dto;
use bookingextension_agent\local\wbagent\services\mutation\option_mutation_service;


/**
 * Update a booking option via the versioned Application-Service API (v1).
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_update_option extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'fields'         => new external_value(
                PARAM_RAW,
                'JSON-encoded option fields (optionid or optionquery required).'
            ),
            'idempotencykey' => new external_value(
                PARAM_ALPHANUMEXT,
                'Client-supplied idempotency key for safe retries.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Update a booking option.
     *
     * @param int    $contextid
     * @param string $fields         JSON-encoded update fields.
     * @param string $idempotencykey Optional client-supplied idempotency key.
     * @return array
     */
    public static function execute(int $contextid, string $fields, string $idempotencykey = ''): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'fields'         => $fields,
            'idempotencykey' => $idempotencykey,
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

        // Idempotency: if this key was already processed successfully, return the stored result.
        if ($params['idempotencykey'] !== '') {
            $existing = $DB->get_record('booking_ai_runs', ['idempotencykey' => $params['idempotencykey']]);
            if ($existing && $existing->status === 'completed') {
                $results = json_decode((string)($existing->resultsjson ?? ''), true);
                $resultid = (int)(is_array($results) ? ($results[0]['resultid'] ?? 0) : 0);
                return [
                    'status'   => 'skipped',
                    'detail'   => 'Already processed (idempotency key matched).',
                    'resultid' => $resultid,
                    'warnings' => [],
                ];
            }
        }

        $fieldsarray = json_decode($params['fields'], true);
        if (!is_array($fieldsarray)) {
            return ['status' => 'error', 'detail' => 'Invalid JSON in fields parameter.', 'resultid' => 0, 'warnings' => []];
        }

        $dto     = update_option_input_dto::from_array($fieldsarray);
        $service = new option_mutation_service();

        $validation = $service->validate_update($dto, $cmid);
        if (!($validation['valid'] ?? false)) {
            $detail = implode('; ', array_merge(
                array_values((array)($validation['errors'] ?? [])),
                array_values((array)($validation['ambiguities'] ?? []))
            ));
            return ['status' => 'error', 'detail' => $detail, 'resultid' => 0, 'warnings' => []];
        }

        $result = $service->update_option($dto, $cmid, (int)$USER->id);
        return [
            'status'   => $result->status,
            'detail'   => $result->detail,
            'resultid' => (int)($result->resultid ?? 0),
            'warnings' => array_values(array_map('strval', $result->warnings)),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'   => new external_value(PARAM_TEXT, 'Result status (executed|error|skipped).'),
            'detail'   => new external_value(PARAM_RAW, 'Human-readable detail message.'),
            'resultid' => new external_value(PARAM_INT, 'ID of the updated option, or 0.'),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Warning text.'),
                'Warnings.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
