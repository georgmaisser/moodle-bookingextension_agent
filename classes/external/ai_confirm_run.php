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

use core\task\manager as task_manager;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\execution_feedback_service;
use bookingextension_agent\local\wbagent\executor;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\services\preflight_audit_logger;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\task\execute_ai_run_adhoc;


/**
 * Confirm a proposed AI run and execute directly or via async task.
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
            'cmid'     => new external_value(PARAM_INT, 'Course-module id.'),
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
     * Create a run and execute it (direct by default, queue optional).
     *
     * @param int    $cmid
     * @param int    $threadid
     * @param string $queueitemid Queue item id to confirm.
     * @param bool $allowsession
     * @return array
     */
    public static function execute(int $cmid, int $threadid, string $queueitemid, bool $allowsession = false): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'threadid' => $threadid,
            'queue_item_id' => $queueitemid,
            'allow_session' => $allowsession,
        ]);

        require_sesskey();

        $authz = new authorization_service();
        $context = context_module::instance($params['cmid']);
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, (int)$context->id);

        $requestedqueueitemid = trim((string)$params['queue_item_id']);
        if ($requestedqueueitemid === '') {
            return [
                'success' => false,
                'runid' => 0,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'error',
                'message' => 'Missing queue item id. Please confirm the latest assistant proposal.',
                'displaymessage' => 'Missing queue item id. Please confirm the latest assistant proposal.',
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => '[]',
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => json_encode(['INVALID_QUEUE_ITEM_ID']),
                'errorsjson' => json_encode(['Missing queue item id.']),
                'pendingconfirmationcode' => '',
                'queueitemid' => '',
                'previewoptionid' => 0,
                'previewoptionidsjson' => '[]',
            ];
        }

        $store = new conversation_store();
        $previewoptionid = self::resolve_preview_option_id_for_response($params['cmid'], (int)$USER->id, []);
        if (!empty($params['allow_session'])) {
            $store->allow_confirmation_for_thread((int)$USER->id, (int)$params['cmid'], (int)$params['threadid']);
        }
        $pendingintent = $store->consume_pending_intent((int)$params['threadid'], (int)$USER->id, (int)$params['cmid']);
        if ($pendingintent === null) {
            return [
                'success' => false,
                'runid' => 0,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'error',
                'message' => 'No pending confirmation is available for this action. Please ask the assistant again.',
                'displaymessage' => 'No pending confirmation is available for this action. Please ask the assistant again.',
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => '[]',
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => '[]',
                'errorsjson' => '[]',
                'pendingconfirmationcode' => '',
                'queueitemid' => '',
                'previewoptionid' => $previewoptionid,
                'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    []
                ),
            ];
        }

        $queuesvc = new queue_manager($store);
        $auditlogger = new preflight_audit_logger($store);
        if ((bool)get_config('bookingextension_agent', 'queue_blocked_ttl_enabled')) {
            $queuesvc->fail_expired_blocked_items((int)$params['threadid']);
        }
        $cmdsarray = array_values((array)$pendingintent['commands']);
        $activequeueitemid = self::resolve_pending_queue_item_id(
            $queuesvc,
            (int)$params['threadid'],
            $pendingintent,
            $requestedqueueitemid
        );

        if ($activequeueitemid === '') {
            return [
                'success' => false,
                'runid' => 0,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'error',
                'message' => 'Invalid or stale queue item id. Please confirm the latest assistant proposal.',
                'displaymessage' => 'Invalid or stale queue item id. Please confirm the latest assistant proposal.',
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => '[]',
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => json_encode(['INVALID_QUEUE_ITEM_ID']),
                'errorsjson' => json_encode(['Invalid or stale queue item id.']),
                'pendingconfirmationcode' => '',
                'queueitemid' => '',
                'previewoptionid' => $previewoptionid,
                'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    []
                ),
            ];
        }

        $commandsforrun = self::resolve_commands_for_run($queuesvc, (int)$params['threadid'], $pendingintent, $activequeueitemid);

        if (empty($commandsforrun)) {
            return [
                'success' => false,
                'runid' => 0,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'error',
                'message' => 'No pending confirmation is available for this action. Please ask the assistant again.',
                'displaymessage' => 'No pending confirmation is available for this action. Please ask the assistant again.',
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => '[]',
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => '[]',
                'errorsjson' => '[]',
                'pendingconfirmationcode' => '',
                'queueitemid' => $activequeueitemid,
                'previewoptionid' => $previewoptionid,
                'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    []
                ),
            ];
        }

        if ($activequeueitemid !== '') {
            $activeitem = $queuesvc->get_queue_item((int)$params['threadid'], $activequeueitemid);
            $activestatus = is_array($activeitem) ? trim((string)($activeitem['status'] ?? '')) : '';
            if (is_array($activeitem) && $activestatus === 'retry_waiting' && !$queuesvc->can_pickup_now($activeitem)) {
                $waitinguntil = (int)($activeitem['next_retry_at'] ?? 0);
                $waitseconds = max(0, $waitinguntil - time());
                $errors = ['Queue item is waiting for retry and cannot be picked up yet.'];
                if ($waitseconds > 0) {
                    $errors[] = 'Retry available in about ' . $waitseconds . 's.';
                }

                return [
                    'success' => false,
                    'runid' => 0,
                    'threadid' => (int)$params['threadid'],
                    'response_type' => 'error',
                    'message' => implode(' ', $errors),
                    'displaymessage' => implode(' ', $errors),
                    'privacyapplied' => 0,
                    'autoconfirm' => 0,
                    'commands' => '[]',
                    'resultsjson' => '[]',
                    'attemptedtasksjson' => '[]',
                    'issuecodesjson' => json_encode(['RETRY_WAITING']),
                    'errorsjson' => json_encode($errors),
                    'pendingconfirmationcode' => '',
                    'queueitemid' => $activequeueitemid,
                    'previewoptionid' => $previewoptionid,
                    'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                        $params['cmid'],
                        (int)$USER->id,
                        []
                    ),
                ];
            }
        }

        if ($activequeueitemid !== '') {
            $queuesvc->update_status((int)$params['threadid'], $activequeueitemid, 'ready');
            $auditlogger->append((int)$params['threadid'], 0, [
                'layer' => 'confirmation',
                'status' => 'ready',
                'issue_codes' => [],
                'retry_count' => 0,
                'duration_ms' => 0,
                'error_class' => '',
            ]);
        }

        $outputlang = trim((string)$store->get_thread_metadata_value((int)$params['threadid'], 'last_output_lang'));
        if ($outputlang === '') {
            $outputlang = current_language();
        }

        $idempotencykey = hash('sha256', $USER->id . ':' . $params['cmid'] . ':' . $params['threadid']
            . ':' . json_encode($commandsforrun) . ':' . microtime(true));
        $runid = $store->create_run(
            $params['threadid'],
            (int)$USER->id,
            $params['cmid'],
            $idempotencykey,
            $commandsforrun
        );

        $executionmode = (string)(get_config('bookingextension_agent', 'aiexecutionmode') ?? 'direct');
        if ($executionmode === 'adhoc') {
            $store->update_run_status($runid, 'queued');
            $store->add_message((int)$params['threadid'], 'assistant', get_string('ai_run_queued', 'bookingextension_agent'), [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'queued',
                'results' => [],
            ]);

            $task = new execute_ai_run_adhoc();
            $task->set_custom_data([
                'runid'          => $runid,
                'userid'         => (int)$USER->id,
                'cmid'           => $params['cmid'],
                'idempotencykey' => $idempotencykey,
            ]);
            $task->set_userid((int)$USER->id);
            task_manager::queue_adhoc_task($task);

            return [
                'success' => true,
                'runid'   => $runid,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'queued',
                'message' => get_string('ai_run_queued', 'bookingextension_agent'),
                'displaymessage' => get_string('ai_run_queued', 'bookingextension_agent'),
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => json_encode($commandsforrun),
                'resultsjson' => '[]',
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => '[]',
                'errorsjson' => '[]',
                'pendingconfirmationcode' => '',
                'queueitemid' => $activequeueitemid,
                'previewoptionid' => $previewoptionid,
                'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    []
                ),
            ];
        }

        // Release the session lock before long-running command execution and
        // runtime loop calls so concurrent poll requests are not blocked.
        \core\session\manager::write_close();

        $store->update_run_status($runid, 'running');
        try {
            if ($activequeueitemid !== '') {
                if ($queuesvc->has_running_item((int)$params['threadid'], $activequeueitemid)) {
                    $queuesvc->update_status((int)$params['threadid'], $activequeueitemid, 'ready');
                } else {
                    $queuesvc->update_status((int)$params['threadid'], $activequeueitemid, 'running');
                    $auditlogger->append((int)$params['threadid'], (int)$runid, [
                        'layer' => 'execution',
                        'status' => 'running',
                        'issue_codes' => [],
                        'retry_count' => 0,
                        'duration_ms' => 0,
                        'error_class' => '',
                    ]);
                }
            }

            $registry = task_registry::make_default();
            $exec = new executor($registry, $store, $authz);
            $rawresults = $exec->execute_commands(
                $commandsforrun,
                $params['cmid'],
                (int)$USER->id,
                $idempotencykey,
                $runid
            );
            $feedbackservice = new execution_feedback_service($store);
            $feedback = $feedbackservice->build_completion_feedback(
                (int)$params['threadid'],
                (int)$params['cmid'],
                (int)$USER->id,
                $commandsforrun,
                $rawresults,
                $outputlang
            );
            $results = $feedback['results'];

            if ($activequeueitemid !== '') {
                $primary = is_array($rawresults[0] ?? null) ? (array)$rawresults[0] : [];
                $status = trim((string)($primary['status'] ?? ''));
                $failed = ($status === 'error' || $status === 'failed');
                $issuecodes = self::normalize_string_list($primary['issue_codes'] ?? []);

                if ($failed) {
                    $errorclass = self::infer_execution_error_class($issuecodes, (string)($primary['detail'] ?? ''));
                    $retrymeta = [];
                    if (in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
                        $retrymeta = self::build_retry_waiting_meta(
                            $queuesvc,
                            (int)$params['threadid'],
                            $activequeueitemid
                        );
                        $queuesvc->update_status(
                            (int)$params['threadid'],
                            $activequeueitemid,
                            'retry_waiting',
                            $issuecodes,
                            $errorclass,
                            trim((string)($primary['detail'] ?? '')),
                            $retrymeta
                        );
                    } else {
                        $queuesvc->update_status(
                            (int)$params['threadid'],
                            $activequeueitemid,
                            'failed',
                            $issuecodes,
                            'domain_error',
                            trim((string)($primary['detail'] ?? ''))
                        );
                    }
                    $auditlogger->append((int)$params['threadid'], (int)$runid, [
                        'layer' => 'execution',
                        'status' => in_array($errorclass, ['provider_timeout', 'transient_io'], true)
                            ? 'retry_waiting'
                            : 'failed',
                        'issue_codes' => $issuecodes,
                        'retry_count' => (int)($retrymeta['retry_count'] ?? 0),
                        'duration_ms' => 0,
                        'error_class' => $errorclass !== '' ? $errorclass : 'domain_error',
                    ]);
                    if (!in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
                        self::mark_dependents_skipped($queuesvc, (int)$params['threadid'], $activequeueitemid);
                    }
                } else {
                    $queuesvc->update_status((int)$params['threadid'], $activequeueitemid, 'succeeded', $issuecodes);
                    $auditlogger->append((int)$params['threadid'], (int)$runid, [
                        'layer' => 'execution',
                        'status' => 'succeeded',
                        'issue_codes' => $issuecodes,
                        'retry_count' => 0,
                        'duration_ms' => 0,
                        'error_class' => '',
                    ]);
                }
            }

            $store->update_run_status($runid, 'completed', $results);

            $shouldcontinue = self::should_continue_with_runtime_loop($rawresults, $cmdsarray, $commandsforrun)
                || self::has_remaining_mutating_queue_items(
                    $queuesvc,
                    (int)$params['threadid'],
                    $activequeueitemid
                );

            if ($shouldcontinue) {
                $seedobservations = [];
                $feedbackobservation = trim((string)($feedback['message'] ?? ''));
                if ($feedbackobservation !== '') {
                    $seedobservations[] = $feedbackobservation;
                }
                $seedobservation = trim((string)result_payload_summarizer::for_observation($results, 1));
                if ($seedobservation !== '') {
                    $seedobservations[] = $seedobservation;
                }
                if (!empty($seedobservations)) {
                    $store->set_thread_metadata_value(
                        (int)$params['threadid'],
                        '_loop_seed_observations',
                        array_values(array_unique($seedobservations))
                    );
                }
                $orchestrator = new orchestrator($registry, new interpreter($registry), $store);
                $runtime = new agent_runtime($registry, $orchestrator, $store, $authz);
                $finalresult = $runtime->run_loop((int)$params['threadid'], (int)$params['cmid'], (int)$USER->id);
            } else {
                $finalresult = [
                    'response_type' => 'sufficient',
                    'message' => (string)($feedback['message'] ?? ''),
                    'commands' => [],
                    'results' => $results,
                    'attempted_tasks' => self::extract_attempted_tasks_from_commands($commandsforrun),
                    'issue_codes' => [],
                    'errors' => [],
                    'pending_confirmation_code' => '',
                ];
                $store->add_message((int)$params['threadid'], 'assistant', (string)$finalresult['message'], $finalresult);
            }

            if (!is_array($finalresult)) {
                $finalresult = [];
            }

            $pendingintent = $store->get_pending_intent((int)$params['threadid']);
            if (!is_array($pendingintent)) {
                $nextqueueitem = self::find_next_mutating_queue_item($queuesvc, (int)$params['threadid']);
                if (is_array($nextqueueitem)) {
                    $nextqueueitemid = (string)($nextqueueitem['queue_item_id'] ?? '');
                    $nexttask = trim((string)($nextqueueitem['task'] ?? ''));
                    $nextinput = is_array($nextqueueitem['prepared_input'] ?? null) && !empty($nextqueueitem['prepared_input'])
                        ? (array)$nextqueueitem['prepared_input']
                        : (is_array($nextqueueitem['input'] ?? null) ? (array)$nextqueueitem['input'] : []);
                    if ($nextqueueitemid !== '' && $nexttask !== '') {
                        $nextcommand = [
                            'task' => $nexttask,
                            'version' => max(1, (int)($nextqueueitem['version'] ?? 1)),
                            'input' => $nextinput,
                        ];
                        $nextdependson = array_values(array_filter(array_map(
                            'strval',
                            (array)($nextqueueitem['depends_on'] ?? [])
                        )));
                        if (!empty($nextdependson)) {
                            $nextcommand['depends_on'] = $nextdependson;
                        }

                        $intentkey = hash(
                            'sha256',
                            (string)$USER->id . ':' . (int)$params['threadid'] . '::' . json_encode([$nextcommand])
                        );
                        $store->set_pending_intent(
                            (int)$params['threadid'],
                            [$nextcommand],
                            $intentkey,
                            (int)$USER->id,
                            (int)$params['cmid'],
                            ['queue_item_ids' => [$nextqueueitemid]]
                        );

                        $pendingintent = $store->get_pending_intent((int)$params['threadid']);
                        $finalresult['pending_confirmation_code'] = (string)($pendingintent['confirmationcode'] ?? '');
                        if (empty((array)($finalresult['commands'] ?? []))) {
                            $finalresult['commands'] = [$nextcommand];
                        }
                        if (trim((string)($finalresult['response_type'] ?? '')) === '') {
                            $finalresult['response_type'] = 'confirmation_request';
                        }
                    }
                }
            }

            $displaymessage = (string)($finalresult['message'] ?? '');
            $privacyapplied = 0;
            $anonymizer = new privacy_anonymizer($store);
            $displayresult = $anonymizer->deanonymize_message_for_display((int)$params['threadid'], $displaymessage);
            $displaymessage = (string)($displayresult['message'] ?? $displaymessage);
            if ((int)($displayresult['replacedcount'] ?? 0) > 0) {
                $privacyapplied = 1;
            }

            $responsetype = (string)($finalresult['response_type'] ?? 'sufficient');
            $issuecodes = self::normalize_string_list($finalresult['issue_codes'] ?? []);
            $errors = self::normalize_string_list($finalresult['errors'] ?? []);
            $autoconfirmblocked = !empty($issuecodes) || !empty($errors);
            $formattedmessage = self::format_ws_message((string)($finalresult['message'] ?? ''), $context);
            $formatteddisplaymessage = self::format_ws_message($displaymessage, $context);
            $previewoptionid = self::resolve_preview_option_id_for_response(
                $params['cmid'],
                (int)$USER->id,
                (array)($finalresult['results'] ?? [])
            );
            $responsequeueitemid = '';
            if (is_array($pendingintent)) {
                $responsequeueitemid = self::resolve_pending_queue_item_id(
                    $queuesvc,
                    (int)$params['threadid'],
                    $pendingintent
                );
            }

            return [
                'success' => true,
                'runid'   => $runid,
                'threadid' => (int)$params['threadid'],
                'response_type' => $responsetype,
                'message' => $formattedmessage,
                'displaymessage' => $formatteddisplaymessage,
                'privacyapplied' => $privacyapplied,
                'autoconfirm' => (int)(
                    $responsetype === 'confirmation_request'
                    && $store->is_confirmation_allowed_for_thread((int)$USER->id, $params['cmid'], (int)$params['threadid'])
                    && !$autoconfirmblocked
                ),
                'commands' => json_encode($finalresult['commands'] ?? []),
                'resultsjson' => json_encode($finalresult['results'] ?? []),
                'attemptedtasksjson' => json_encode($finalresult['attempted_tasks'] ?? []),
                'issuecodesjson' => json_encode($issuecodes),
                'errorsjson' => json_encode($errors),
                'pendingconfirmationcode' => (string)($finalresult['pending_confirmation_code'] ?? ''),
                'queueitemid' => $responsequeueitemid,
                'previewoptionid' => $previewoptionid,
                'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    $results
                ),
            ];
        } catch (\Throwable $e) {
            $rawresults = [['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null]];
            $feedbackservice = new execution_feedback_service($store);
            $feedback = $feedbackservice->build_completion_feedback(
                (int)$params['threadid'],
                (int)$params['cmid'],
                (int)$USER->id,
                $commandsforrun,
                $rawresults,
                $outputlang
            );

            if ($activequeueitemid !== '') {
                $errorclass = self::infer_execution_error_class([], $e->getMessage());
                $retrymeta = [];
                if (in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
                    $retrymeta = self::build_retry_waiting_meta(
                        $queuesvc,
                        (int)$params['threadid'],
                        $activequeueitemid
                    );
                    $queuesvc->update_status(
                        (int)$params['threadid'],
                        $activequeueitemid,
                        'retry_waiting',
                        [],
                        $errorclass,
                        $e->getMessage(),
                        $retrymeta
                    );
                } else {
                    $queuesvc->update_status(
                        (int)$params['threadid'],
                        $activequeueitemid,
                        'failed',
                        [],
                        'provider_error',
                        $e->getMessage()
                    );
                }
                $auditlogger->append((int)$params['threadid'], (int)$runid, [
                    'layer' => 'execution',
                    'status' => in_array($errorclass, ['provider_timeout', 'transient_io'], true)
                        ? 'retry_waiting'
                        : 'failed',
                    'issue_codes' => [],
                    'retry_count' => (int)($retrymeta['retry_count'] ?? 0),
                    'duration_ms' => 0,
                    'error_class' => $errorclass !== '' ? $errorclass : 'provider_error',
                ]);
                if (!in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
                    self::mark_dependents_skipped($queuesvc, (int)$params['threadid'], $activequeueitemid);
                }
            }

            $store->update_run_status($runid, 'failed', $feedback['results']);
            return [
                'success' => false,
                'runid'   => $runid,
                'threadid' => (int)$params['threadid'],
                'response_type' => 'error',
                'message' => (string)$feedback['message'],
                'displaymessage' => (string)$feedback['message'],
                'privacyapplied' => 0,
                'autoconfirm' => 0,
                'commands' => '[]',
                'resultsjson' => json_encode($feedback['results'] ?? []),
                'attemptedtasksjson' => '[]',
                'issuecodesjson' => '[]',
                'errorsjson' => '[]',
                'pendingconfirmationcode' => '',
                'queueitemid' => '',
                'previewoptionid' => self::resolve_preview_option_id_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    (array)($feedback['results'] ?? [])
                ),
                'previewoptionidsjson' => self::resolve_preview_option_ids_json_for_response(
                    $params['cmid'],
                    (int)$USER->id,
                    (array)($feedback['results'] ?? [])
                ),
            ];
        }
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the run was successfully queued.'),
            'runid'   => new external_value(PARAM_INT, 'The id of the created run.'),
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

    /**
     * Resolve all preview option ids for WS responses as a JSON-encoded array.
     *
     * Prefers ids explicitly set in task results (previewoptionids), then
     * falls back to the per-user cache written by executor.php at run time.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $results  Sanitized feedback results from executor (not finalresult).
     * @return string JSON-encoded int array, e.g. "[123,456]"
     */
    private static function resolve_preview_option_ids_json_for_response(int $cmid, int $userid, array $results): string {
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
            $ids = array_values(array_filter(
                array_map('intval', booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid))
            ));
        }
        return json_encode(array_values(array_unique($ids)));
    }

    /**
     * Resolve preview option id for WS responses.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @return int
     */
    private static function resolve_preview_option_id_for_response(int $cmid, int $userid, array $results): int {
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

        $storedpreviewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
        foreach ($storedpreviewids as $id) {
            $optionid = (int)$id;
            if ($optionid > 0) {
                return $optionid;
            }
        }

        $lastworked = booking_task_support::resolve_last_option_for_user_for_execute($cmid, $userid);
        return $lastworked ? (int)$lastworked : 0;
    }

    /**
     * Format a markdown-like assistant message as HTML for WS output.
     *
     * @param string $message
     * @param context_module $context
     * @return string
     */
    private static function format_ws_message(string $message, context_module $context): string {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        return format_text(\markdown_to_html($message), 1, [
            'context' => $context,
            'para' => false,
        ]);
    }

    /**
     * Normalize any list-like value into a compact non-empty string list.
     *
     * @param mixed $value
     * @return array<int,string>
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
     * Infer execution error class from issue codes and detail text.
     *
     * @param array<int,string> $issuecodes
     * @param string $detail
     * @return string
     */
    private static function infer_execution_error_class(array $issuecodes, string $detail): string {
        foreach ($issuecodes as $code) {
            $upper = strtoupper(trim((string)$code));
            if ($upper === '') {
                continue;
            }
            if (str_contains($upper, 'TIMEOUT')) {
                return 'provider_timeout';
            }
            if (str_contains($upper, 'TRANSIENT_IO') || str_contains($upper, 'IO_TRANSIENT')) {
                return 'transient_io';
            }
        }

        $normalized = strtolower(trim($detail));
        if ($normalized !== '') {
            if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out')) {
                return 'provider_timeout';
            }
            if (str_contains($normalized, 'temporary') || str_contains($normalized, 'transient io')) {
                return 'transient_io';
            }
        }

        return '';
    }

    /**
     * Build queue metadata for retry_waiting state.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @return array<string,int>
     */
    private static function build_retry_waiting_meta(queue_manager $queuesvc, int $threadid, string $queueitemid): array {
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        $retrycount = is_array($item) ? max(0, (int)($item['retry_count'] ?? 0)) : 0;
        $nextretrycount = $retrycount + 1;
        $backoffms = min(4000, 500 * (2 ** max(0, min(8, $nextretrycount - 1))));
        $nextretryat = time() + (int)ceil($backoffms / 1000);

        return [
            'retry_count' => $nextretrycount,
            'preflight_retry_count' => $nextretrycount,
            'retry_after_ms' => $backoffms,
            'backoff_ms' => $backoffms,
            'next_retry_at' => $nextretryat,
        ];
    }

    /**
     * Continue planner/runtime follow-up only when execution indicates repair
     * needs or when additional staged commands still remain.
     *
     * @param array $rawresults
     * @param array $allconfirmedcommands
     * @param array $executedcommands
     * @return bool
     */
    private static function should_continue_with_runtime_loop(
        array $rawresults,
        array $allconfirmedcommands,
        array $executedcommands
    ): bool {
        if (count($allconfirmedcommands) > count($executedcommands)) {
            return true;
        }

        foreach ($rawresults as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = trim((string)($entry['status'] ?? ''));
            if (in_array($status, ['error', 'failed'], true)) {
                return true;
            }
        }

        if (!empty($executedcommands)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether additional mutating queue work is still pending after this run.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $activequeueitemid
     * @return bool
     */
    private static function has_remaining_mutating_queue_items(
        queue_manager $queuesvc,
        int $threadid,
        string $activequeueitemid
    ): bool {
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }

            $queueitemid = (string)($item['queue_item_id'] ?? '');
            if ($queueitemid === '' || $queueitemid === $activequeueitemid) {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            if (in_array($status, ['queued', 'blocked_confirmation', 'ready', 'retry_waiting'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the next pending mutating queue item that still needs confirmation/execution.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @return array<string,mixed>|null
     */
    private static function find_next_mutating_queue_item(queue_manager $queuesvc, int $threadid): ?array {
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            if (!in_array($status, ['queued', 'blocked_confirmation', 'ready', 'retry_waiting'], true)) {
                continue;
            }

            return $item;
        }

        return null;
    }

    /**
     * Extract attempted task names from commands for structured response payload.
     *
     * @param array $commands
     * @return array<int,string>
     */
    private static function extract_attempted_tasks_from_commands(array $commands): array {
        $tasks = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $task = trim((string)($command['task'] ?? ''));
            if ($task !== '') {
                $tasks[] = $task;
            }
        }

        return array_values(array_unique($tasks));
    }

    /**
     * Resolve the active queue item id for the current pending intent.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param array<string,mixed> $pendingintent
     * @return string
     */
    private static function resolve_pending_queue_item_id(
        queue_manager $queuesvc,
        int $threadid,
        array $pendingintent,
        string $requestedqueueitemid = ''
    ): string {
        $requestedqueueitemid = trim($requestedqueueitemid);
        if ($requestedqueueitemid !== '') {
            $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
            if (!empty($queueitemids) && !in_array($requestedqueueitemid, $queueitemids, true)) {
                return '';
            }

            $requesteditem = $queuesvc->get_queue_item($threadid, $requestedqueueitemid);
            if (!is_array($requesteditem)) {
                return '';
            }

            if ((string)($requesteditem['mutability'] ?? '') !== 'mutating') {
                return '';
            }

            $requestedstatus = (string)($requesteditem['status'] ?? '');
            if (!in_array($requestedstatus, ['blocked_confirmation', 'ready', 'queued', 'retry_waiting'], true)) {
                return '';
            }

            return $requestedqueueitemid;
        }

        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        foreach ($queueitemids as $queueitemid) {
            $item = $queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }
            $status = (string)($item['status'] ?? '');
            if (in_array($status, ['blocked_confirmation', 'ready', 'queued', 'retry_waiting'], true)) {
                return $queueitemid;
            }
        }

        return '';
    }

    /**
     * Resolve the concrete command batch to execute for the current confirmation.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param array<string,mixed> $pendingintent
     * @param string $activequeueitemid
     * @return array<int,array<string,mixed>>
     */
    private static function resolve_commands_for_run(
        queue_manager $queuesvc,
        int $threadid,
        array $pendingintent,
        string $activequeueitemid
    ): array {
        if ($activequeueitemid !== '') {
            $item = $queuesvc->get_queue_item($threadid, $activequeueitemid);
            if (is_array($item)) {
                $task = trim((string)($item['task'] ?? ''));
                $input = is_array($item['prepared_input'] ?? null) && !empty($item['prepared_input'])
                    ? (array)$item['prepared_input']
                    : (is_array($item['input'] ?? null) ? (array)$item['input'] : []);
                if ($task !== '') {
                    $command = [
                        'task' => $task,
                        'version' => max(1, (int)($item['version'] ?? 1)),
                        'input' => $input,
                    ];
                    $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
                    if (!empty($dependson)) {
                        $command['depends_on'] = $dependson;
                    }
                    return [$command];
                }
            }
        }

        return [];
    }

      /**
       * Mark dependent queue items as skipped after a failed prerequisite.
       *
       * @param queue_manager $queuesvc
       * @param int $threadid
       * @param string $failedqueueitemid
       * @return void
       */
    private static function mark_dependents_skipped(
        queue_manager $queuesvc,
        int $threadid,
        string $failedqueueitemid
    ): void {
        if ($failedqueueitemid === '') {
            return;
        }

        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $queueitemid = (string)($item['queue_item_id'] ?? '');
            if ($queueitemid === '') {
                continue;
            }

            $dependson = array_values(array_map('strval', (array)($item['depends_on'] ?? [])));
            if (!in_array($failedqueueitemid, $dependson, true)) {
                continue;
            }

            $status = (string)($item['status'] ?? '');
            if (!in_array($status, ['queued', 'blocked_confirmation', 'ready'], true)) {
                continue;
            }

            $queuesvc->update_status(
                $threadid,
                $queueitemid,
                'skipped',
                ['DEPENDENCY_FAILED'],
                'domain_conflict',
                'Skipped because a dependent queue item failed.'
            );
        }
    }
}
