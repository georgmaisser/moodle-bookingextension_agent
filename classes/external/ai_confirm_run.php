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
 * External service: confirm an AI agent run.
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
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\services\confirm_run_service;

/**
 * Confirm a proposed AI run and execute directly or via async task.
 *
 * This class is intentionally a thin WS adapter:
 * - validates auth/context/input
 * - delegates run-confirm orchestration to confirm_run_service
 * - applies display deanonymization and response formatting
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_confirm_run extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'queue_item_id' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id to confirm.'),
            'allow_session' => new external_value(
                PARAM_BOOL,
                'Allow confirmations for this thread in the current session.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Confirm and execute a pending run.
     *
     * @param int $contextid
     * @param int $threadid
     * @param string $queueitemid
     * @param bool $allowsession
     * @return array
     */
    public static function execute(int $contextid, int $threadid, string $queueitemid, bool $allowsession = false): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'threadid' => $threadid,
            'queue_item_id' => $queueitemid,
            'allow_session' => $allowsession,
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

        $params['contextid'] = (int)$context->id;
        $cmid = (int)$context->instanceid;
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, (int)$context->id);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $service = new confirm_run_service($registry, $store, $authz);

        $payload = $service->confirm(
            (int)$params['contextid'],
            $cmid,
            (int)$params['threadid'],
            (int)$USER->id,
            (string)$params['queue_item_id'],
            (bool)$params['allow_session']
        );

        $message = (string)($payload['message'] ?? '');
        $displaymessage = $message;
        $privacyapplied = 0;
        $anonymizer = new privacy_anonymizer($store);
        $displayresult = $anonymizer->deanonymize_message_for_display((int)$params['threadid'], $displaymessage);
        $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
        if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
            $privacyapplied = 1;
        }

        return [
            'success' => (bool)($payload['success'] ?? false),
            'runid' => (int)($payload['runid'] ?? 0),
            'threadid' => (int)($payload['threadid'] ?? (int)$params['threadid']),
            'response_type' => (string)($payload['response_type'] ?? 'error'),
            'message' => ws_message_formatter::format_ws_message($message, $context),
            'displaymessage' => ws_message_formatter::format_ws_message($displaymessage, $context),
            'privacyapplied' => $privacyapplied,
            'autoconfirm' => (int)($payload['autoconfirm'] ?? 0),
            'commands' => json_encode((array)($payload['commands'] ?? [])),
            'resultsjson' => json_encode((array)($payload['results'] ?? [])),
            'attemptedtasksjson' => json_encode((array)($payload['attempted_tasks'] ?? [])),
            'issuecodesjson' => json_encode((array)($payload['issue_codes'] ?? [])),
            'errorsjson' => json_encode((array)($payload['errors'] ?? [])),
            'pendingconfirmationcode' => (string)($payload['pending_confirmation_code'] ?? ''),
            'queueitemid' => (string)($payload['queueitemid'] ?? ''),
            'previewoptionid' => (int)($payload['previewoptionid'] ?? 0),
            'previewoptionidsjson' => json_encode((array)($payload['previewoptionids'] ?? [])),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the run was successfully queued.'),
            'runid' => new external_value(PARAM_INT, 'The id of the created run.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'response_type' => new external_value(PARAM_TEXT, 'Final response type from the runtime.'),
            'message' => new external_value(PARAM_RAW, 'Status message.'),
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for the user.'),
            'privacyapplied' => new external_value(PARAM_INT, 'Whether display deanonymization was applied.'),
            'autoconfirm' => new external_value(PARAM_INT, 'Whether the UI should auto-trigger confirmation.'),
            'commands' => new external_value(PARAM_RAW, 'JSON-encoded command list.'),
            'resultsjson' => new external_value(PARAM_RAW, 'JSON-encoded execution results.'),
            'attemptedtasksjson' => new external_value(PARAM_RAW, 'JSON-encoded attempted tasks.'),
            'issuecodesjson' => new external_value(PARAM_RAW, 'JSON-encoded issue codes.'),
            'errorsjson' => new external_value(PARAM_RAW, 'JSON-encoded errors.'),
            'pendingconfirmationcode' => new external_value(PARAM_TEXT, 'One-time pending confirmation code for debug.'),
            'queueitemid' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id for the next confirmation step.'),
            'previewoptionid' => new external_value(PARAM_INT, 'Latest option id to preview directly, if available.'),
            'previewoptionidsjson' => new external_value(
                PARAM_RAW,
                'JSON-encoded array of all preview option ids.',
                VALUE_DEFAULT,
                '[]'
            ),
        ]);
    }

}
