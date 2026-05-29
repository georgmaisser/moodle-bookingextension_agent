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
 * Application service: confirm and execute a pending queue-backed run.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use core\task\manager as task_manager;
use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\execution_feedback_service;
use bookingextension_agent\local\wbagent\executor;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\task\execute_ai_run_adhoc;

/**
 * Handles confirmation flow independent from external API formatting.
 */
class confirm_run_service {
    /** Thread metadata key for aggregated option previews across one confirm chain. */
    private const CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY = '_confirm_preview_option_ids';

    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     * @param conversation_store $store
     * @param authorization_service $authz
     */
    public function __construct(task_registry $registry, conversation_store $store, authorization_service $authz) {
        $this->registry = $registry;
        $this->store = $store;
        $this->authz = $authz;
    }

    /**
     * Confirm and execute one pending queue-backed command.
     *
     * @param int $contextid
     * @param int $cmid
     * @param int $threadid
     * @param int $userid
     * @param string $queueitemid
     * @param bool $allowsession
     * @return array<string,mixed>
     */
    public function confirm(
        int $contextid,
        int $cmid,
        int $threadid,
        int $userid,
        string $queueitemid,
        bool $allowsession = false
    ): array {
        $requestedqueueitemid = trim($queueitemid);
        if ($requestedqueueitemid === '') {
            return $this->build_error_payload(
                $threadid,
                $cmid,
                $userid,
                'Missing queue item id. Please confirm the latest assistant proposal.',
                ['INVALID_QUEUE_ITEM_ID'],
                ['Missing queue item id.'],
                ''
            );
        }

        if ($allowsession) {
            $this->store->allow_confirmation_for_thread($userid, $contextid, $threadid);
        }

        $pendingintent = $this->store->consume_pending_intent($threadid, $userid, $contextid);
        if ($pendingintent === null) {
            return $this->build_error_payload(
                $threadid,
                $cmid,
                $userid,
                'No pending confirmation is available for this action. Please ask the assistant again.'
            );
        }

        $queuesvc = new queue_manager($this->store);
        $auditlogger = new preflight_audit_logger($this->store);
        if ((bool)get_config('bookingextension_agent', 'queue_blocked_ttl_enabled')) {
            $queuesvc->fail_expired_blocked_items($threadid);
        }

        $activequeueitemid = $this->resolve_pending_queue_item_id(
            $queuesvc,
            $threadid,
            $pendingintent,
            $requestedqueueitemid
        );
        if ($activequeueitemid === '') {
            return $this->build_error_payload(
                $threadid,
                $cmid,
                $userid,
                'Invalid or stale queue item id. Please confirm the latest assistant proposal.',
                ['INVALID_QUEUE_ITEM_ID'],
                ['Invalid or stale queue item id.'],
                ''
            );
        }

        $commandsforrun = $this->resolve_commands_for_run($queuesvc, $threadid, $pendingintent, $activequeueitemid);
        if (empty($commandsforrun)) {
            return $this->build_error_payload(
                $threadid,
                $cmid,
                $userid,
                'No pending confirmation is available for this action. Please ask the assistant again.',
                [],
                [],
                $activequeueitemid
            );
        }

        $activeitem = $queuesvc->get_queue_item($threadid, $activequeueitemid);
        if (is_array($activeitem) && !$queuesvc->dependencies_succeeded($threadid, $activeitem)) {
            return $this->build_error_payload(
                $threadid,
                $cmid,
                $userid,
                'Queue item is waiting for dependencies and cannot be picked up yet.',
                ['DEPENDENCY_WAITING'],
                ['Queue item is waiting for dependencies and cannot be picked up yet.'],
                $activequeueitemid
            );
        }

        $activestatus = is_array($activeitem) ? trim((string)($activeitem['status'] ?? '')) : '';
        if (is_array($activeitem) && $activestatus === 'retry_waiting' && !$queuesvc->can_pickup_now($activeitem)) {
            $errors = ['Queue item is waiting for retry and cannot be picked up yet.'];
            $waitseconds = max(0, ((int)($activeitem['next_retry_at'] ?? 0)) - time());
            if ($waitseconds > 0) {
                $errors[] = 'Retry available in about ' . $waitseconds . 's.';
            }

            return $this->build_error_payload(
                $threadid,
                $cmid,
                $userid,
                implode(' ', $errors),
                ['RETRY_WAITING'],
                $errors,
                $activequeueitemid
            );
        }

        $queuesvc->update_status($threadid, $activequeueitemid, 'ready');
        $auditlogger->append($threadid, 0, array_merge(
            $this->build_queue_audit_context($queuesvc, $threadid, $activequeueitemid),
            [
                'layer' => 'confirmation',
                'status' => 'ready',
                'issue_codes' => [],
                'retry_count' => 0,
                'duration_ms' => 0,
                'error_class' => '',
            ]
        ));

        $outputlang = trim((string)$this->store->get_thread_metadata_value($threadid, 'last_output_lang'));
        if ($outputlang === '') {
            $outputlang = current_language();
        }

        $idempotencykey = hash('sha256', $userid . ':' . $contextid . ':' . $threadid
            . ':' . json_encode($commandsforrun) . ':' . microtime(true));
        $runid = $this->store->create_run(
            $threadid,
            $userid,
            $contextid,
            $idempotencykey,
            $commandsforrun
        );

        $executionmode = (string)(get_config('bookingextension_agent', 'aiexecutionmode') ?? 'direct');
        if ($executionmode === 'adhoc') {
            $this->store->update_run_status($runid, 'queued');
            $queuedmessage = get_string('ai_run_queued', 'bookingextension_agent');
            $this->store->add_message($threadid, 'assistant', $queuedmessage, [
                'response_type' => 'execution_result',
                'runid' => (int)$runid,
                'status' => 'queued',
                'results' => [],
            ]);

            $task = new execute_ai_run_adhoc();
            $task->set_custom_data([
                'runid' => $runid,
                'userid' => $userid,
                'contextid' => $contextid,
                'idempotencykey' => $idempotencykey,
            ]);
            $task->set_userid($userid);
            task_manager::queue_adhoc_task($task);

            return [
                'success' => true,
                'runid' => (int)$runid,
                'threadid' => $threadid,
                'response_type' => 'queued',
                'message' => $queuedmessage,
                'autoconfirm' => 0,
                'commands' => $commandsforrun,
                'results' => [],
                'attempted_tasks' => [],
                'issue_codes' => [],
                'errors' => [],
                'pending_confirmation_code' => '',
                'queueitemid' => $activequeueitemid,
                'previewoptionid' => $this->resolve_preview_option_id_for_response($cmid, $userid, []),
                'previewoptionids' => $this->resolve_preview_option_ids_for_response($cmid, $userid, []),
            ];
        }

        // Release session lock before long-running execution.
        \core\session\manager::write_close();

        $this->store->update_run_status($runid, 'running');
        try {
            if ($queuesvc->try_mark_running($threadid, $activequeueitemid)) {
                $auditlogger->append($threadid, (int)$runid, array_merge(
                    $this->build_queue_audit_context($queuesvc, $threadid, $activequeueitemid),
                    [
                        'layer' => 'execution',
                        'status' => 'running',
                        'issue_codes' => [],
                        'retry_count' => 0,
                        'duration_ms' => 0,
                        'error_class' => '',
                    ]
                ));
            } else {
                $queuesvc->update_status($threadid, $activequeueitemid, 'ready');
            }

            $exec = new executor($this->registry, $this->store, $this->authz);
            $rawresults = $exec->execute_commands(
                $commandsforrun,
                $contextid,
                $userid,
                $idempotencykey,
                $runid
            );
            $feedbackservice = new execution_feedback_service($this->store);
            $feedback = $feedbackservice->build_completion_feedback(
                $threadid,
                $cmid,
                $userid,
                $commandsforrun,
                $rawresults,
                $outputlang
            );
            $results = (array)($feedback['results'] ?? []);

            $primary = is_array($rawresults[0] ?? null) ? (array)$rawresults[0] : [];
            $status = trim((string)($primary['status'] ?? ''));
            $failed = ($status === 'error' || $status === 'failed');
            $issuecodes = $this->normalize_string_list($primary['issue_codes'] ?? []);
            if ($failed) {
                $errorclass = $this->infer_execution_error_class($issuecodes);
                $retrymeta = [];
                $retrydecision = ['issue_codes' => $issuecodes];
                $executionstatus = 'failed';
                if (in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
                    $retrydecision = $this->build_retry_decision(
                        $queuesvc,
                        $threadid,
                        $activequeueitemid,
                        $errorclass,
                        $issuecodes
                    );
                    $retrymeta = (array)($retrydecision['meta'] ?? []);
                    $executionstatus = (string)($retrydecision['queue_status'] ?? 'failed');
                    $queuesvc->update_status(
                        $threadid,
                        $activequeueitemid,
                        $executionstatus,
                        (array)($retrydecision['issue_codes'] ?? $issuecodes),
                        $errorclass,
                        trim((string)($primary['detail'] ?? '')),
                        $retrymeta
                    );
                } else {
                    $queuesvc->update_status(
                        $threadid,
                        $activequeueitemid,
                        'failed',
                        $issuecodes,
                        'domain_error',
                        trim((string)($primary['detail'] ?? ''))
                    );
                }

                $auditlogger->append($threadid, (int)$runid, array_merge(
                    $this->build_queue_audit_context($queuesvc, $threadid, $activequeueitemid, $retrymeta),
                    [
                        'layer' => 'execution',
                        'status' => $executionstatus,
                        'issue_codes' => $executionstatus === 'retry_waiting'
                            ? $issuecodes
                            : array_values(array_unique(array_merge(
                                $issuecodes,
                                (array)($retrydecision['issue_codes'] ?? [])
                            ))),
                        'retry_count' => (int)($retrymeta['retry_count'] ?? 0),
                        'duration_ms' => 0,
                        'error_class' => $errorclass !== '' ? $errorclass : 'domain_error',
                    ]
                ));

                if ($executionstatus !== 'retry_waiting') {
                    $this->mark_dependents_skipped($queuesvc, $threadid, $activequeueitemid);
                }
            } else {
                $queuesvc->update_status($threadid, $activequeueitemid, 'succeeded', $issuecodes);
                $auditlogger->append($threadid, (int)$runid, array_merge(
                    $this->build_queue_audit_context($queuesvc, $threadid, $activequeueitemid),
                    [
                        'layer' => 'execution',
                        'status' => 'succeeded',
                        'issue_codes' => $issuecodes,
                        'retry_count' => 0,
                        'duration_ms' => 0,
                        'error_class' => '',
                    ]
                ));
            }

            $this->store->update_run_status($runid, 'completed', $results);
            $observationledger = new execution_observation_ledger($this->store);
            $observationledger->append_from_results(
                $threadid,
                $results,
                [
                    'source' => 'confirm_run',
                    'run_id' => (int)$runid,
                    'commands' => $commandsforrun,
                    'queue_item_ids' => [$activequeueitemid],
                ]
            );

            $aggregatedpreviewids = $this->remember_confirm_preview_option_ids($threadid, $results);
            $shouldcontinue = $this->should_continue_with_runtime_loop($rawresults, $commandsforrun, $commandsforrun)
                || $this->has_remaining_mutating_queue_items($queuesvc, $threadid, $activequeueitemid);

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
                    $this->store->set_thread_metadata_value(
                        $threadid,
                        '_loop_seed_observations',
                        array_values(array_unique($seedobservations))
                    );
                }

                $orchestrator = new orchestrator($this->registry, new interpreter($this->registry), $this->store);
                $runtime = new agent_runtime($this->registry, $orchestrator, $this->store, $this->authz);
                $finalresult = $runtime->run_loop($threadid, $contextid, $userid);
            } else {
                $finalresult = [
                    'response_type' => 'sufficient',
                    'message' => (string)($feedback['message'] ?? ''),
                    'commands' => [],
                    'results' => $results,
                    'attempted_tasks' => $this->extract_attempted_tasks_from_commands($commandsforrun),
                    'issue_codes' => [],
                    'errors' => [],
                    'pending_confirmation_code' => '',
                ];
                $this->store->add_message($threadid, 'assistant', (string)$finalresult['message'], $finalresult);
            }

            if (!is_array($finalresult)) {
                $finalresult = [];
            }

            $pendingintent = $this->store->get_pending_intent($threadid);
            if (!is_array($pendingintent)) {
                $nextqueueitem = $this->find_next_mutating_queue_item($queuesvc, $threadid);
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
                        $nextguardtoken = trim((string)($nextqueueitem['guard_token'] ?? ''));
                        if ($nextguardtoken !== '') {
                            $nextcommand['guard_token'] = $nextguardtoken;
                        }
                        $nextdependson = array_values(array_filter(array_map(
                            'strval',
                            (array)($nextqueueitem['depends_on'] ?? [])
                        )));
                        if (!empty($nextdependson)) {
                            $nextcommand['depends_on'] = $nextdependson;
                        }

                        $intentkey = hash(
                            'sha256',
                            (string)$userid . ':' . $threadid . '::' . json_encode([$nextcommand])
                        );
                        $this->store->set_pending_intent(
                            $threadid,
                            [],
                            $intentkey,
                            $userid,
                            $contextid,
                            [
                                'queue_item_ids' => [$nextqueueitemid],
                                'queue_authoritative' => true,
                            ]
                        );

                        $pendingintent = $this->store->get_pending_intent($threadid);
                        $finalresult['pending_confirmation_code'] = (string)($pendingintent['confirmationcode'] ?? '');
                        if (empty((array)($finalresult['commands'] ?? []))) {
                            $finalresult['commands'] = [$nextcommand];
                        }
                        $finalresult['response_type'] = 'confirmation_request';
                        if ($this->has_successful_execution_results($results)) {
                            $finalresult['message'] = (string)($feedback['message'] ?? $finalresult['message'] ?? '');
                            $finalresult['issue_codes'] = [];
                            $finalresult['errors'] = [];
                        }
                    }
                }
            }

            if ($this->has_successful_execution_results($results)) {
                $finalresponsetype = (string)($finalresult['response_type'] ?? '');
                if ($finalresponsetype === 'sufficient') {
                    $finalresult['message'] = (string)($feedback['message'] ?? $finalresult['message'] ?? '');
                } else if ($finalresponsetype === 'error' && !is_array($pendingintent)) {
                    $finalresult['response_type'] = 'sufficient';
                    $finalresult['message'] = (string)($feedback['message'] ?? $finalresult['message'] ?? '');
                    $finalresult['issue_codes'] = [];
                    $finalresult['errors'] = [];
                }
            }

            $responsetype = (string)($finalresult['response_type'] ?? 'sufficient');
            $issuecodes = $this->normalize_string_list($finalresult['issue_codes'] ?? []);
            $errors = $this->normalize_string_list($finalresult['errors'] ?? []);
            $autoconfirmblocked = !empty($issuecodes) || !empty($errors);

            $responsequeueitemid = '';
            if (is_array($pendingintent)) {
                $responsequeueitemid = $this->resolve_pending_queue_item_id(
                    $queuesvc,
                    $threadid,
                    $pendingintent
                );
            }

            return [
                'success' => true,
                'runid' => (int)$runid,
                'threadid' => $threadid,
                'response_type' => $responsetype,
                'message' => (string)($finalresult['message'] ?? ''),
                'autoconfirm' => (int)(
                    $responsetype === 'confirmation_request'
                    && $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid)
                    && !$autoconfirmblocked
                ),
                'commands' => (array)($finalresult['commands'] ?? []),
                'results' => (array)($finalresult['results'] ?? []),
                'attempted_tasks' => (array)($finalresult['attempted_tasks'] ?? []),
                'issue_codes' => $issuecodes,
                'errors' => $errors,
                'pending_confirmation_code' => (string)($finalresult['pending_confirmation_code'] ?? ''),
                'queueitemid' => $responsequeueitemid,
                'previewoptionid' => $this->resolve_preview_option_id_for_response(
                    $cmid,
                    $userid,
                    (array)($finalresult['results'] ?? [])
                ),
                'previewoptionids' => $this->resolve_confirm_preview_option_ids_for_response(
                    $threadid,
                    $cmid,
                    $userid,
                    $results,
                    $aggregatedpreviewids
                ),
            ];
        } catch (\Throwable $e) {
            $rawresults = [['status' => 'error', 'detail' => $e->getMessage(), 'resultid' => null]];
            $feedbackservice = new execution_feedback_service($this->store);
            $feedback = $feedbackservice->build_completion_feedback(
                $threadid,
                $cmid,
                $userid,
                $commandsforrun,
                $rawresults,
                $outputlang
            );

            $errorclass = $this->infer_execution_error_class([]);
            $retrymeta = [];
            $executionstatus = 'failed';
            $executionissuecodes = [];
            if (in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
                $retrydecision = $this->build_retry_decision(
                    $queuesvc,
                    $threadid,
                    $activequeueitemid,
                    $errorclass,
                    []
                );
                $retrymeta = (array)($retrydecision['meta'] ?? []);
                $executionstatus = (string)($retrydecision['queue_status'] ?? 'failed');
                $executionissuecodes = (array)($retrydecision['issue_codes'] ?? []);
                $queuesvc->update_status(
                    $threadid,
                    $activequeueitemid,
                    $executionstatus,
                    $executionissuecodes,
                    $errorclass,
                    $e->getMessage(),
                    $retrymeta
                );
            } else {
                $queuesvc->update_status(
                    $threadid,
                    $activequeueitemid,
                    'failed',
                    [],
                    'provider_error',
                    $e->getMessage()
                );
            }

            $auditlogger->append($threadid, (int)$runid, array_merge(
                $this->build_queue_audit_context($queuesvc, $threadid, $activequeueitemid, $retrymeta),
                [
                    'layer' => 'execution',
                    'status' => $executionstatus,
                    'issue_codes' => $executionissuecodes,
                    'retry_count' => (int)($retrymeta['retry_count'] ?? 0),
                    'duration_ms' => 0,
                    'error_class' => $errorclass !== '' ? $errorclass : 'provider_error',
                ]
            ));
            if ($executionstatus !== 'retry_waiting') {
                $this->mark_dependents_skipped($queuesvc, $threadid, $activequeueitemid);
            }

            $feedbackresults = (array)($feedback['results'] ?? []);
            $this->store->update_run_status($runid, 'failed', $feedbackresults);

            return [
                'success' => false,
                'runid' => (int)$runid,
                'threadid' => $threadid,
                'response_type' => 'error',
                'message' => (string)($feedback['message'] ?? ''),
                'autoconfirm' => 0,
                'commands' => [],
                'results' => $feedbackresults,
                'attempted_tasks' => [],
                'issue_codes' => [],
                'errors' => [],
                'pending_confirmation_code' => '',
                'queueitemid' => '',
                'previewoptionid' => $this->resolve_preview_option_id_for_response($cmid, $userid, $feedbackresults),
                'previewoptionids' => $this->resolve_preview_option_ids_for_response($cmid, $userid, $feedbackresults),
            ];
        }
    }

    /**
     * Build a normalized error payload.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $message
     * @param array<int,string> $issuecodes
     * @param array<int,string> $errors
     * @param string $queueitemid
     * @return array<string,mixed>
     */
    private function build_error_payload(
        int $threadid,
        int $cmid,
        int $userid,
        string $message,
        array $issuecodes = [],
        array $errors = [],
        string $queueitemid = ''
    ): array {
        return [
            'success' => false,
            'runid' => 0,
            'threadid' => $threadid,
            'response_type' => 'error',
            'message' => $message,
            'autoconfirm' => 0,
            'commands' => [],
            'results' => [],
            'attempted_tasks' => [],
            'issue_codes' => $issuecodes,
            'errors' => $errors,
            'pending_confirmation_code' => '',
            'queueitemid' => $queueitemid,
            'previewoptionid' => $this->resolve_preview_option_id_for_response($cmid, $userid, []),
            'previewoptionids' => $this->resolve_preview_option_ids_for_response($cmid, $userid, []),
        ];
    }

    /**
     * Resolve all preview option ids for responses.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @return int[]
     */
    private function resolve_preview_option_ids_for_response(int $cmid, int $userid, array $results): array {
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
            foreach ($this->registry->get_preview_option_memory_helpers() as $helper) {
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

        return array_values(array_unique($ids));
    }

    /**
     * Resolve preview option id for responses.
     *
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @return int
     */
    private function resolve_preview_option_id_for_response(int $cmid, int $userid, array $results): int {
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

        foreach ($this->registry->get_preview_option_memory_helpers() as $helper) {
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
     * Aggregate preview option ids into thread metadata.
     *
     * @param int $threadid
     * @param array $results
     * @return int[]
     */
    private function remember_confirm_preview_option_ids(int $threadid, array $results): array {
        $currentids = $this->extract_preview_option_ids_from_results($results);
        $storedids = $this->store->get_thread_metadata_value($threadid, self::CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY);
        $aggregatedids = array_values(array_unique(array_filter(array_map('intval', array_merge(
            is_array($storedids) ? $storedids : [],
            $currentids
        )))));

        $this->store->set_thread_metadata_value($threadid, self::CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY, $aggregatedids);
        return $aggregatedids;
    }

    /**
     * Resolve preview ids for confirm responses.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $results
     * @param int[]|null $aggregatedids
     * @return int[]
     */
    private function resolve_confirm_preview_option_ids_for_response(
        int $threadid,
        int $cmid,
        int $userid,
        array $results,
        ?array $aggregatedids = null
    ): array {
        $ids = is_array($aggregatedids)
            ? $aggregatedids
            : (is_array($this->store->get_thread_metadata_value($threadid, self::CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY))
                ? $this->store->get_thread_metadata_value($threadid, self::CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY)
                : []);

        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge(
            is_array($ids) ? $ids : [],
            $this->extract_preview_option_ids_from_results($results)
        )))));

        if (empty($ids)) {
            return $this->resolve_preview_option_ids_for_response($cmid, $userid, $results);
        }

        return $ids;
    }

    /**
     * Extract preview-capable option ids from execution results.
     *
     * @param array $results
     * @return int[]
     */
    private function extract_preview_option_ids_from_results(array $results): array {
        $ids = [];
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((int)($entry['userid'] ?? 0) <= 0) {
                $resultid = (int)($entry['resultid'] ?? 0);
                if ($resultid > 0) {
                    $ids[] = $resultid;
                }
            }

            foreach ((array)($entry['previewoptionids'] ?? []) as $previewid) {
                $normalizedid = (int)$previewid;
                if ($normalizedid > 0) {
                    $ids[] = $normalizedid;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * True when at least one executed command produced a successful result.
     *
     * @param array $results
     * @return bool
     */
    private function has_successful_execution_results(array $results): bool {
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = trim((string)($entry['status'] ?? ''));
            if (in_array($status, ['executed', 'ok'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize list-like value into non-empty string list.
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalize_string_list($value): array {
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
     * Infer execution error class from structured issue codes.
     *
     * @param array<int,string> $issuecodes
     * @return string
     */
    private function infer_execution_error_class(array $issuecodes): string {
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

        return '';
    }

    /**
     * Build queue retry/failure metadata through central execution gate.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param string $errorclass
     * @param array<int,string> $issuecodes
     * @return array{queue_status:string,issue_codes:array<int,string>,meta:array<string,int>}
     */
    private function build_retry_decision(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        string $errorclass,
        array $issuecodes
    ): array {
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        $retrycount = is_array($item) ? max(0, (int)($item['retry_count'] ?? 0)) : 0;
        $gate = new preflight_execution_gate();
        $decision = $gate->evaluate($errorclass, $retrycount, $issuecodes);
        $decisionissuecodes = array_values(array_unique(array_merge($issuecodes, $decision->issuecodes)));

        if ($decision->status !== 'retry_hint') {
            return [
                'queue_status' => 'failed',
                'issue_codes' => $decisionissuecodes,
                'meta' => ['retry_count' => $retrycount],
            ];
        }

        $nextretrycount = $retrycount + 1;
        $retryafterms = max(1, (int)$decision->retryafterms);
        return [
            'queue_status' => 'retry_waiting',
            'issue_codes' => $decisionissuecodes,
            'meta' => [
                'retry_count' => $nextretrycount,
                'preflight_retry_count' => $nextretrycount,
                'retry_after_ms' => $retryafterms,
                'backoff_ms' => $retryafterms,
                'next_retry_at' => time() + (int)ceil($retryafterms / 1000),
            ],
        ];
    }

    /**
     * Build common audit fields for a queue item.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<string,mixed> $retrymeta
     * @return array{queue_item_id:string,taskname:string,task_version:int,retry_after_ms:int,contextid:int}
     */
    private function build_queue_audit_context(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid,
        array $retrymeta = []
    ): array {
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        $item = is_array($item) ? $item : [];

        return [
            'queue_item_id' => $queueitemid,
            'contextid' => max(0, (int)($item['contextid'] ?? 0)),
            'taskname' => trim((string)($item['task'] ?? '')),
            'task_version' => max(0, (int)($item['version'] ?? 0)),
            'retry_after_ms' => max(0, (int)($retrymeta['retry_after_ms'] ?? ($item['retry_after_ms'] ?? 0))),
        ];
    }

    /**
     * Continue runtime loop only when repair/follow-up work remains.
     *
     * @param array $rawresults
     * @param array $allconfirmedcommands
     * @param array $executedcommands
     * @return bool
     */
    private function should_continue_with_runtime_loop(
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

        return false;
    }

    /**
     * Check whether additional mutating queue work is still pending.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $activequeueitemid
     * @return bool
     */
    private function has_remaining_mutating_queue_items(
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
     * Find next pending mutating queue item.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @return array<string,mixed>|null
     */
    private function find_next_mutating_queue_item(queue_manager $queuesvc, int $threadid): ?array {
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

            if (!$queuesvc->dependencies_succeeded($threadid, $item)) {
                continue;
            }

            return $item;
        }

        return null;
    }

    /**
     * Extract attempted task names from commands.
     *
     * @param array $commands
     * @return array<int,string>
     */
    private function extract_attempted_tasks_from_commands(array $commands): array {
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
     * Resolve active queue item id for current pending intent.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param array<string,mixed> $pendingintent
     * @param string $requestedqueueitemid
     * @return string
     */
    private function resolve_pending_queue_item_id(
        queue_manager $queuesvc,
        int $threadid,
        array $pendingintent,
        string $requestedqueueitemid = ''
    ): string {
        $requestedqueueitemid = trim($requestedqueueitemid);
        if ($requestedqueueitemid !== '') {
            $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
            if (empty($queueitemids) || !in_array($requestedqueueitemid, $queueitemids, true)) {
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
        foreach ($queueitemids as $candidate) {
            $item = $queuesvc->get_queue_item($threadid, $candidate);
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }
            $status = (string)($item['status'] ?? '');
            if (in_array($status, ['blocked_confirmation', 'ready', 'queued', 'retry_waiting'], true)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Resolve command batch for the current confirmation.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param array<string,mixed> $pendingintent
     * @param string $activequeueitemid
     * @return array<int,array<string,mixed>>
     */
    private function resolve_commands_for_run(
        queue_manager $queuesvc,
        int $threadid,
        array $pendingintent,
        string $activequeueitemid
    ): array {
        if ($activequeueitemid === '') {
            return [];
        }

        $item = $queuesvc->get_queue_item($threadid, $activequeueitemid);
        if (!is_array($item) || (string)($item['mutability'] ?? '') !== 'mutating') {
            return [];
        }

        $status = trim((string)($item['status'] ?? ''));
        if (!in_array($status, ['blocked_confirmation', 'ready', 'queued', 'retry_waiting'], true)) {
            return [];
        }
        if (!$queuesvc->dependencies_succeeded($threadid, $item)) {
            return [];
        }

        $task = trim((string)($item['task'] ?? ''));
        $input = is_array($item['prepared_input'] ?? null) && !empty($item['prepared_input'])
            ? (array)$item['prepared_input']
            : (is_array($item['input'] ?? null) ? (array)$item['input'] : []);
        if ($task === '') {
            return [];
        }

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
     * Mark dependent queue items as skipped after a failed prerequisite.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $failedqueueitemid
     * @return void
     */
    private function mark_dependents_skipped(queue_manager $queuesvc, int $threadid, string $failedqueueitemid): void {
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
            if (!in_array($status, ['queued', 'blocked_confirmation', 'ready', 'retry_waiting'], true)) {
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
