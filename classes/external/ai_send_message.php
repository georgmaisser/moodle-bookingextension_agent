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
 * External service: send a message to the AI agent.
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
use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\services\pending_intent_service;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Send a user message to the AI agent and receive the AI's response.
 *
 * This is a thin API wrapper.  All orchestration logic lives in
 * {@see agent_runtime}.  This class is responsible only for:
 *  1. Auth / sesskey validation.
 *  2. Privacy precheck and storing the user message.
 *  3. Delegating to AgentRuntime::run().
 *  4. Applying display-side privacy deanonymisation.
 *  5. Formatting the result for the external API contract.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_send_message extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid'    => new external_value(PARAM_INT, 'Module context id of the booking instance.'),
            'message' => new external_value(PARAM_RAW, 'User message text.'),
            'threadid' => new external_value(
                PARAM_INT,
                'Optional thread id to pin this message to an existing active thread.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Send a message to the AI agent.
     *
     * @param int    $contextid
     * @param string $message
     * @param int    $threadid
     * @return array
     */
    public static function execute(int $contextid, string $message, int $threadid = 0): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['contextid' => $contextid, 'message' => $message, 'threadid' => $threadid]
        );
        $contextid = (int)$params['contextid'];
        $message = trim($params['message']);
        $threadid = (int)($params['threadid'] ?? 0);
        $authz = new authorization_service();
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $contextid = (int)$context->id;
        $cmid = (int)$context->instanceid;
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, (int)$context->id);

        if (empty($message)) {
            $emptymsg = get_string('ai_empty_message', 'bookingextension_agent');
            return [
                'response_type'         => 'error',
                'message'               => $emptymsg,
                'displaymessage'        => $emptymsg,
                'privacyapplied'        => 0,
                'autoconfirm'           => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => '[]',
                'attemptedtasksjson'    => '[]',
                'issuecodesjson'        => '[]',
                'pendingconfirmationcode' => '',
                'queueitemid'           => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewoptionid'       => 0,
                'previewoptionidsjson'  => '[]',
            ];
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $registry = task_registry::make_default();
        $store = new conversation_store();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);

        $runtimeproviderstatus = $orchestrator->get_runtime_provider_status($cmid);
        if (empty($runtimeproviderstatus['runtimeavailable'])) {
            $errormessage = get_string('ai_provider_not_configured', 'bookingextension_agent');
            if (!empty($runtimeproviderstatus['provideractive']) && empty($runtimeproviderstatus['courseenabled'])) {
                $errormessage = get_string('aiready_check_course_enabled_todo', 'bookingextension_agent');
            } else if (!empty($runtimeproviderstatus['provideractive']) && empty($runtimeproviderstatus['contextenabled'])) {
                $errormessage = get_string('aiready_check_context_enabled_todo', 'bookingextension_agent');
            }

            return [
                'response_type'         => 'error',
                'message'               => $errormessage,
                'displaymessage'        => $errormessage,
                'privacyapplied'        => 0,
                'autoconfirm'           => 0,
                'commands'              => '[]',
                'ambiguities'           => '[]',
                'ambiguityoptionsjson'  => '[]',
                'errorsjson'            => '[]',
                'attemptedtasksjson'    => '[]',
                'issuecodesjson'        => '[]',
                'pendingconfirmationcode' => '',
                'queueitemid'           => '',
                'threadid'              => 0,
                'runid'                 => 0,
                'resultsjson'           => '[]',
                'previewoptionid'       => 0,
                'previewoptionidsjson'  => '[]',
            ];
        }

        $thread = null;
        if ($threadid > 0) {
            global $DB;
            $candidate = $DB->get_record('local_wbagent_ai_threads', [
                'id' => $threadid,
                'userid' => (int)$USER->id,
                'contextid' => $contextid,
                'status' => 'active',
            ]);
            if ($candidate) {
                $thread = $candidate;
            }
        }

        if ($thread === null) {
            $thread = $store->get_or_create_thread((int)$USER->id, $contextid, (int)$cm->instance);
        }
        $threadid = (int)$thread->id;
        $anonymizer = new privacy_anonymizer($store);
        $store->set_thread_metadata_value($threadid, '_confirm_preview_option_ids', []);

        // Privacy precheck before storing the user message.
        $precheck = $anonymizer->precheck_user_message($threadid, $message);
        $message = (string)($precheck['sanitizedmessage'] ?? $message);

        $store->add_message($threadid, 'user', $message);

        // Progress-only status for polling UI; this must not trigger extra LLM calls.
        $store->clear_step_messages($threadid);
        $store->add_step_message($threadid, 1, (string)get_string('ai_thinking', 'bookingextension_agent'), 'runtime.loop');

        // Release the session lock before the blocking LLM call so that
        // concurrent step-polling requests (ai_poll_thread) can be served
        // without waiting for this long-running request to complete.
        \core\session\manager::write_close();

        // Agentic loop: read-only tool calls are executed internally (no user confirmation
        // needed), observations are fed back to the LLM, and only the final user-visible
        // response (clarification, confirmation_request, error) is persisted.
        $runtime = new agent_runtime($registry, $orchestrator, $store, $authz);
        $result = $runtime->run_loop($threadid, $contextid, (int)$USER->id);

        // Display-side privacy deanonymisation (presentation concern, stays here).
        $displaymessage = (string)($result['message'] ?? '');
        $privacyapplied = 0;
        $displayresult = $anonymizer->deanonymize_message_for_display($threadid, $displaymessage);
        $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
        if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
            $privacyapplied = 1;
        }

        $formattedmessage = ws_message_formatter::format_ws_message((string)($result['message'] ?? ''), $context);
        $formatteddisplaymessage = ws_message_formatter::format_ws_message($displaymessage, $context);
        $issuecodes = self::normalize_string_list($result['issue_codes'] ?? []);
        $errors = self::normalize_string_list($result['errors'] ?? []);
        $autoconfirmblocked = !empty($issuecodes) || !empty($errors);
        $previewoptionid = self::resolve_preview_option_id_for_response(
            $registry,
            $cmid,
            (int)$USER->id,
            (array)($result['results'] ?? [])
        );
        $responsequeueitemid = self::resolve_response_queue_item_id($store, $threadid);
        $responsecommands = self::resolve_response_commands($store, $threadid, $responsequeueitemid, $result);

        return [
            'response_type'         => $result['response_type'] ?? 'error',
            'message'               => $formattedmessage,
            'displaymessage'        => $formatteddisplaymessage,
            'privacyapplied'        => $privacyapplied,
            'autoconfirm'           => (int)(
                (string)($result['response_type'] ?? '') === 'confirmation_request'
                && $store->is_confirmation_allowed_for_thread((int)$USER->id, $contextid, $threadid)
                && !$autoconfirmblocked
            ),
            'commands'              => json_encode($responsecommands),
            'ambiguities'           => json_encode($result['ambiguities'] ?? []),
            'ambiguityoptionsjson'  => json_encode($result['ambiguity_options'] ?? []),
            'errorsjson'            => json_encode($errors),
            'attemptedtasksjson'    => json_encode($result['attempted_tasks'] ?? []),
            'issuecodesjson'        => json_encode($issuecodes),
            'pendingconfirmationcode' => (string)($result['pending_confirmation_code'] ?? ''),
            'queueitemid'           => $responsequeueitemid,
            'threadid'              => $threadid,
            'runid'                 => (int)($result['runid'] ?? 0),
            'resultsjson'           => json_encode($result['results'] ?? []),
            'previewoptionid'       => $previewoptionid,
            'previewoptionidsjson'  => self::resolve_preview_option_ids_json_for_response(
                $registry,
                $cmid,
                (int)$USER->id,
                (array)($result['results'] ?? [])
            ),
        ];
    }

    /**
     * Normalize any list-like value into a compact non-empty string list.
     *
     * @param mixed $value
        $result = $runtime->run_loop($threadid, $contextid, (int)$USER->id);
     */
    private static function normalize_string_list($value): array {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            $text = trim((string)$entry);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values($normalized);
    }

    /**
     * Resolve queue item id for the active confirmation step in a thread.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return string
     */
    private static function resolve_response_queue_item_id(conversation_store $store, int $threadid): string {
        $pendingintentsvc = new pending_intent_service($store);
        $pendingintent = $pendingintentsvc->get($threadid);
        if (!is_array($pendingintent)) {
            return '';
        }

        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        return (string)($queueitemids[0] ?? '');
    }

    /**
     * Resolve the command payload that should be exposed in the WS response.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param string $queueitemid
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private static function resolve_response_commands(
        conversation_store $store,
        int $threadid,
        string $queueitemid,
        array $result
    ): array {
        if ((string)($result['response_type'] ?? '') !== 'confirmation_request' || $queueitemid === '') {
            return is_array($result['commands'] ?? null) ? (array)$result['commands'] : [];
        }

        $queuesvc = new queue_manager($store);
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        if (!is_array($item)) {
            return is_array($result['commands'] ?? null) ? (array)$result['commands'] : [];
        }

        $task = trim((string)($item['task'] ?? ''));
        if ($task === '') {
            return [];
        }

        $input = is_array($item['prepared_input'] ?? null) && !empty($item['prepared_input'])
            ? (array)$item['prepared_input']
            : (is_array($item['input'] ?? null) ? (array)$item['input'] : []);
        $command = [
            'task' => $task,
            'version' => max(1, (int)($item['version'] ?? 1)),
            'input' => $input,
        ];
        $guardtoken = trim((string)($item['guard_token'] ?? ''));
        if ($guardtoken !== '') {
            $command['guard_token'] = $guardtoken;
        }
        $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
        if (!empty($dependson)) {
            $command['depends_on'] = $dependson;
        }

        return [$command];
    }

    /**
     * Resolve all preview option ids for WS responses as a JSON-encoded array.
     *
     * @param task_registry $registry
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @return string JSON-encoded int array
     */
    private static function resolve_preview_option_ids_json_for_response(
        task_registry $registry,
        int $cmid,
        int $userid,
        array $results
    ): string {
        $ids = [];
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $previewids = is_array($entry['previewoptionids'] ?? null) ? (array)$entry['previewoptionids'] : [];
            foreach ($previewids as $id) {
                $normalized = (int)$id;
                if ($normalized > 0) {
                    $ids[] = $normalized;
                }
            }
            $resultid = (int)($entry['resultid'] ?? 0);
            if ($resultid > 0 && !in_array($resultid, $ids, true)) {
                $ids[] = $resultid;
            }
        }
        if (empty($ids)) {
            foreach ($registry->get_preview_option_memory_helpers() as $helper) {
                $storedids = array_map(
                    'intval',
                    (array)$helper->resolve_last_preview_option_ids_for_execute($cmid, $userid)
                );
                foreach ($storedids as $storedid) {
                    if ($storedid > 0) {
                        $ids[] = $storedid;
                    }
                }
            }
        }
        return json_encode(array_values(array_unique($ids)));
    }

    /**
     * Resolve preview option id for WS responses.
     *
     * @param task_registry $registry
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @return int
     */
    private static function resolve_preview_option_id_for_response(
        task_registry $registry,
        int $cmid,
        int $userid,
        array $results
    ): int {
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $resultid = (int)($entry['resultid'] ?? 0);
            if ($resultid > 0) {
                return $resultid;
            }

            $previewids = is_array($entry['previewoptionids'] ?? null) ? (array)$entry['previewoptionids'] : [];
            foreach ($previewids as $id) {
                $optionid = (int)$id;
                if ($optionid > 0) {
                    return $optionid;
                }
            }
        }

        foreach ($registry->get_preview_option_memory_helpers() as $helper) {
            $storedpreviewids = (array)$helper->resolve_last_preview_option_ids_for_execute($cmid, $userid);
            foreach ($storedpreviewids as $id) {
                $optionid = (int)$id;
                if ($optionid > 0) {
                    return $optionid;
                }
            }
        }

        return 0;
    }

    /**
     * Returns external function result schema.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response_type' => new external_value(PARAM_TEXT, 'Response type from the AI.'),
            'message'       => new external_value(PARAM_RAW, 'AI message / summary for the user.'),
            'displaymessage' => new external_value(PARAM_RAW, 'Display message for user UI (de-masked if privacy mode applies).'),
            'privacyapplied' => new external_value(PARAM_INT, '1 if display masking indicator applied, otherwise 0.'),
            'autoconfirm'    => new external_value(PARAM_INT, '1 if the UI should auto-trigger confirmation, otherwise 0.'),
            'commands'      => new external_value(PARAM_RAW, 'JSON-encoded array of proposed commands.'),
            'ambiguities'   => new external_value(PARAM_RAW, 'JSON-encoded array of ambiguity questions.'),
            'ambiguityoptionsjson' => new external_value(
                PARAM_RAW,
                'JSON-encoded structured ambiguity options for clickable frontend suggestions.'
            ),
            'errorsjson'    => new external_value(PARAM_RAW, 'JSON-encoded technical validation errors.'),
            'attemptedtasksjson' => new external_value(PARAM_RAW, 'JSON-encoded attempted task names.'),
            'issuecodesjson' => new external_value(PARAM_RAW, 'JSON-encoded issue codes from task validation.'),
            'pendingconfirmationcode' => new external_value(PARAM_TEXT, 'One-time pending confirmation code for debug.'),
            'queueitemid' => new external_value(PARAM_ALPHANUMEXT, 'Queue item id for confirmation.'),
            'threadid'      => new external_value(PARAM_INT, 'Thread id.'),
            'runid'         => new external_value(PARAM_INT, 'Run id (0 if not yet created).'),
            'resultsjson'   => new external_value(PARAM_RAW, 'JSON-encoded execution results (if available).'),
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
