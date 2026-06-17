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

use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\services\attempt_budget_dto;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\services\execution\execution_feedback_service;
use bookingextension_agent\local\wbagent\executor;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\skill_registry;
use bookingextension_agent\local\wbagent\queue\queue_manager;

/**
 * Handles confirmation flow independent from external API formatting.
 */
class confirm_run_service {
    /** Thread metadata key for aggregated option previews across one confirm chain. */
    private const CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY = '_confirm_preview_option_ids';

    /** Thread metadata key: signatures of mutating commands that failed non-retryably (repeat guard). */
    private const FAILED_COMMAND_SIGNATURES_KEY = '_failed_command_signatures';

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var pending_intent_service */
    private pending_intent_service $pendingintentsvc;

    /** @var queue_transition_service */
    private queue_transition_service $queuetransitionsvc;



    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param conversation_store $store
     * @param authorization_service $authz
     */
    public function __construct(skill_registry $registry, conversation_store $store, authorization_service $authz) {
        $this->registry = $registry;
        $this->store = $store;
        $this->authz = $authz;
        $this->pendingintentsvc = new pending_intent_service($store);
        $this->queuetransitionsvc = new queue_transition_service();
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
                $contextid,
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

        $pendingintent = $this->pendingintentsvc->consume($threadid, $userid, $contextid);
        if ($pendingintent === null) {
            return $this->build_error_payload(
                $threadid,
                $contextid,
                $cmid,
                $userid,
                'No pending confirmation is available for this action. Please ask the assistant again.'
            );
        }

        $queuesvc = new queue_manager($this->store);
        $auditlogger = new preflight_audit_logger($this->store);
        // Stale blocked_confirmation items are always expired (no admin toggle).
        $queuesvc->fail_expired_blocked_items($threadid);

        $activequeueitemid = $this->resolve_pending_queue_item_id(
            $queuesvc,
            $threadid,
            $pendingintent,
            $requestedqueueitemid
        );
        if ($activequeueitemid === '') {
            return $this->build_error_payload(
                $threadid,
                $contextid,
                $cmid,
                $userid,
                'Invalid or stale queue item id. Please confirm the latest assistant proposal.',
                ['INVALID_QUEUE_ITEM_ID'],
                ['Invalid or stale queue item id.'],
                ''
            );
        }

        $activeitem = $this->get_active_mutating_queue_item($queuesvc, $threadid, $activequeueitemid);
        if (!is_array($activeitem)) {
            return $this->build_error_payload(
                $threadid,
                $contextid,
                $cmid,
                $userid,
                'No pending confirmation is available for this action. Please ask the assistant again.',
                [],
                [],
                $activequeueitemid
            );
        }

        if (!$queuesvc->dependencies_succeeded($threadid, $activeitem)) {
            return $this->build_error_payload(
                $threadid,
                $contextid,
                $cmid,
                $userid,
                'Queue item is waiting for dependencies and cannot be picked up yet.',
                ['DEPENDENCY_WAITING'],
                ['Queue item is waiting for dependencies and cannot be picked up yet.'],
                $activequeueitemid
            );
        }

        $activestatus = trim((string)($activeitem['status'] ?? ''));
        if (queue_status_policy::is_retry_waiting_status($activestatus) && !$queuesvc->can_pickup_now($activeitem)) {
            $errors = ['Queue item is waiting for retry and cannot be picked up yet.'];
            $waitseconds = max(0, ((int)($activeitem['next_retry_at'] ?? 0)) - time());
            if ($waitseconds > 0) {
                $errors[] = 'Retry available in about ' . $waitseconds . 's.';
            }

            return $this->build_error_payload(
                $threadid,
                $contextid,
                $cmid,
                $userid,
                implode(' ', $errors),
                ['RETRY_WAITING'],
                $errors,
                $activequeueitemid,
                attempt_budget_dto::from_queue_item($activeitem)->to_array()
            );
        }

        $commandsforrun = $this->resolve_commands_for_run($queuesvc, $threadid, $activequeueitemid);
        if (empty($commandsforrun)) {
            return $this->build_error_payload(
                $threadid,
                $contextid,
                $cmid,
                $userid,
                'No pending confirmation is available for this action. Please ask the assistant again.',
                [],
                [],
                $activequeueitemid
            );
        }

        // Repeat guard: do not re-execute a command that already failed non-retryably in this thread
        // (e.g. an optionquery that matches nothing). Surface a clarification and let the planner/user
        // correct instead of silently failing the identical action again.
        $faileddetail = $this->get_failed_command_detail($threadid, $commandsforrun[0] ?? []);
        if ($faileddetail !== null) {
            $this->queuetransitionsvc->to_skipped(
                $queuesvc,
                $threadid,
                $activequeueitemid,
                'REPEATED_FAILED_COMMAND',
                ['REPEATED_FAILED_COMMAND'],
                'domain_error',
                'Identical command already failed earlier in this thread.'
            );
            return [
                'success' => true,
                'runid' => 0,
                'threadid' => $threadid,
                'response_type' => 'clarification',
                'message' => $faileddetail,
                'autoconfirm' => 0,
                'commands' => [],
                'results' => [],
                'attempted_skills' => [],
                'issue_codes' => ['REPEATED_FAILED_COMMAND'],
                'errors' => [],
                'pending_confirmation_code' => '',
                'queueitemid' => '',
                ...$this->build_preview_response_fields($threadid, [], $contextid, $userid),
            ];
        }

        $this->queuetransitionsvc->to_ready(
            $queuesvc,
            $threadid,
            $activequeueitemid,
            'CONFIRMATION_ACCEPTED'
        );
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
                $this->queuetransitionsvc->to_ready(
                    $queuesvc,
                    $threadid,
                    $activequeueitemid,
                    'RUNNING_SLOT_OCCUPIED'
                );
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
                $errorclass = preflight_error_classifier::infer_from_issue_codes($issuecodes);
                $retrymeta = [];
                $retrydecision = ['issue_codes' => $issuecodes];
                $executionstatus = 'failed';
                if (preflight_error_classifier::is_retryable_error_class($errorclass)) {
                    $retrydecision = $this->build_retry_decision(
                        $queuesvc,
                        $threadid,
                        $activequeueitemid,
                        $errorclass,
                        $issuecodes
                    );
                    $retrymeta = (array)($retrydecision['meta'] ?? []);
                    $executionstatus = (string)($retrydecision['queue_status'] ?? 'failed');
                    if (queue_status_policy::is_retry_waiting_status($executionstatus)) {
                        $this->queuetransitionsvc->to_retry_waiting(
                            $queuesvc,
                            $threadid,
                            $activequeueitemid,
                            'EXECUTION_RETRY_HINT',
                            (array)($retrydecision['issue_codes'] ?? $issuecodes),
                            $errorclass,
                            trim((string)($primary['detail'] ?? '')),
                            $retrymeta
                        );
                    } else {
                        $this->queuetransitionsvc->to_failed(
                            $queuesvc,
                            $threadid,
                            $activequeueitemid,
                            'EXECUTION_RETRY_EXHAUSTED',
                            (array)($retrydecision['issue_codes'] ?? $issuecodes),
                            $errorclass,
                            trim((string)($primary['detail'] ?? ''))
                        );
                    }
                } else {
                    $this->queuetransitionsvc->to_failed(
                        $queuesvc,
                        $threadid,
                        $activequeueitemid,
                        'EXECUTION_DOMAIN_FAILED',
                        $issuecodes,
                        'domain_error',
                        trim((string)($primary['detail'] ?? ''))
                    );
                    // Remember this non-retryable failure so the repeat guard can short-circuit an
                    // identical re-issue instead of looping (see top of confirm()).
                    $this->record_failed_command(
                        $threadid,
                        $commandsforrun[0] ?? [],
                        trim((string)($primary['detail'] ?? ''))
                    );
                }

                $auditlogger->append($threadid, (int)$runid, array_merge(
                    $this->build_queue_audit_context($queuesvc, $threadid, $activequeueitemid, $retrymeta),
                    [
                        'layer' => 'execution',
                        'status' => $executionstatus,
                        'issue_codes' => queue_status_policy::is_retry_waiting_status($executionstatus)
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

                if (!queue_status_policy::is_retry_waiting_status($executionstatus)) {
                    $this->mark_dependents_skipped($queuesvc, $threadid, $activequeueitemid);
                }
            } else {
                $this->queuetransitionsvc->to_succeeded(
                    $queuesvc,
                    $threadid,
                    $activequeueitemid,
                    'EXECUTION_SUCCEEDED',
                    $issuecodes
                );
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

            $previewjson = $this->resolve_and_accumulate_preview_json($threadid, $results, $contextid, $userid);
            $nextmutatingqueueitem = $this->find_next_mutating_queue_item($queuesvc, $threadid, $activequeueitemid);
            $shouldcontinue = $this->should_continue_with_runtime_loop($rawresults)
                || is_array($nextmutatingqueueitem)
                || $queuesvc->has_planned_placeholders($threadid);
            $usedterminalfinalizer = false;

            $orchestrator = new orchestrator($this->registry, new interpreter($this->registry), $this->store);
            $runtime = new agent_runtime($this->registry, $orchestrator, $this->store, $this->authz);

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

                $finalresult = $runtime->run_loop($threadid, $contextid, $userid);
            } else {
                $finalresult = [
                    'response_type' => 'sufficient',
                    'message' => (string)($feedback['message'] ?? ''),
                    'commands' => [],
                    'results' => $results,
                    'attempted_skills' => $this->extract_attempted_skills_from_commands($commandsforrun),
                    'issue_codes' => [],
                    'errors' => [],
                    'pending_confirmation_code' => '',
                ];
                $finalresult = $runtime->finalize_terminal_result($threadid, $finalresult);
                $usedterminalfinalizer = true;
            }

            if (!is_array($finalresult)) {
                $finalresult = [];
            }

            $pendingintent = $this->pendingintentsvc->get($threadid);
            if (!is_array($pendingintent)) {
                $nextqueueitem = $this->find_next_mutating_queue_item($queuesvc, $threadid);
                if (is_array($nextqueueitem)) {
                    $nextqueueitemid = (string)($nextqueueitem['queue_item_id'] ?? '');
                    $nextcommand = queue_command_mapper::from_queue_item($nextqueueitem, true);
                    if ($nextqueueitemid !== '' && is_array($nextcommand)) {
                        $confirmationcode = $this->pendingintentsvc->set(
                            $threadid,
                            $userid,
                            $contextid,
                            [
                                'queue_item_ids' => [$nextqueueitemid],
                            ]
                        );

                        $pendingintent = $this->pendingintentsvc->get($threadid);
                        $finalresult['pending_confirmation_code'] = $confirmationcode;
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
                if ($finalresponsetype === 'sufficient' && !$usedterminalfinalizer) {
                    // Synchronizer output (already in $finalresult['message']) takes priority.
                    // Skill execution feedback is fallback only when the Synchronizer produced no message.
                    $finalresult['message'] = $finalresult['message'] ?: (string)($feedback['message'] ?? '');
                } else if ($finalresponsetype === 'error' && !is_array($pendingintent)) {
                    $finalresult['response_type'] = 'sufficient';
                    // Synchronizer output takes priority; skill feedback is fallback only.
                    $finalresult['message'] = $finalresult['message'] ?: (string)($feedback['message'] ?? '');
                    $finalresult['issue_codes'] = [];
                    $finalresult['errors'] = [];
                }
            }

            $responsetype = (string)($finalresult['response_type'] ?? 'sufficient');
            $issuecodes = $this->normalize_string_list($finalresult['issue_codes'] ?? []);
            $errors = $this->normalize_string_list($finalresult['errors'] ?? []);
            // Never auto-confirm a follow-up when the execution in THIS confirm just failed — even if
            // the re-planned proposal looks "clean" (empty issue_codes/errors). Otherwise a domain
            // failure (e.g. "no matching options") that the planner keeps re-issuing turns into an
            // infinite frontend<->backend auto-confirm ping-pong. The user must explicitly confirm.
            $autoconfirmblocked = $failed || !empty($issuecodes) || !empty($errors);

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
                'attempted_skills' => (array)($finalresult['attempted_skills'] ?? []),
                'issue_codes' => $issuecodes,
                'errors' => $errors,
                'pending_confirmation_code' => (string)($finalresult['pending_confirmation_code'] ?? ''),
                'queueitemid' => $responsequeueitemid,
                'previewjson' => $previewjson,
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

            $errorclass = preflight_error_classifier::infer_from_issue_codes([]);
            $retrymeta = [];
            $executionstatus = 'failed';
            $executionissuecodes = [];
            if (preflight_error_classifier::is_retryable_error_class($errorclass)) {
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
                $this->queuetransitionsvc->to_retry_waiting(
                    $queuesvc,
                    $threadid,
                    $activequeueitemid,
                    'EXECUTION_EXCEPTION_RETRY_HINT',
                    $executionissuecodes,
                    $errorclass,
                    $e->getMessage(),
                    $retrymeta
                );
            } else {
                $this->queuetransitionsvc->to_failed(
                    $queuesvc,
                    $threadid,
                    $activequeueitemid,
                    'EXECUTION_EXCEPTION_FATAL',
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
            if (!queue_status_policy::is_retry_waiting_status($executionstatus)) {
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
                'attempted_skills' => [],
                'issue_codes' => [],
                'errors' => [],
                'pending_confirmation_code' => '',
                'queueitemid' => '',
                ...$this->build_preview_response_fields($threadid, $feedbackresults, $contextid, $userid),
            ];
        }
    }

    /**
     * Build a normalized error payload.
     *
     * @param int $threadid
     * @param int $contextid
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
        int $contextid,
        int $cmid,
        int $userid,
        string $message,
        array $issuecodes = [],
        array $errors = [],
        string $queueitemid = '',
        array $attemptbudget = []
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
            'attempted_skills' => [],
            'issue_codes' => $issuecodes,
            'errors' => $errors,
            'pending_confirmation_code' => '',
            'queueitemid' => $queueitemid,
            'attempt_budget' => $attemptbudget,
            ...$this->build_preview_response_fields($threadid, [], $contextid, $userid),
        ];
    }

    /**
     * Build response preview fields from current result context.
     *
     * @param int $threadid
     * @param array<int,mixed> $results
     * @return array{previewjson:string}
     */
    private function build_preview_response_fields(
        int $threadid,
        array $results,
        int $contextid,
        int $userid
    ): array {
        return [
            'previewjson' => $this->resolve_and_accumulate_preview_json($threadid, $results, $contextid, $userid),
        ];
    }

    /**
     * Resolve and accumulate preview json for the thread.
     *
     * @param int $threadid
     * @param array $results
     * @return string
     */
    private function resolve_and_accumulate_preview_json(
        int $threadid,
        array $results,
        int $contextid,
        int $userid
    ): string {
        // Generic, domain-agnostic passthrough of skill-provided preview blocks (precomputed by the
        // executor on the raw results; $contextid/$userid are no longer needed here).
        unset($contextid, $userid);
        return preview_passthrough::resolve_preview_json(
            $results,
            $threadid,
            '_confirm_previews'
        );
    }

    /**
     * Deterministic signature of a mutating command (skill + normalized input).
     *
     * @param array<string,mixed> $command
     * @return string
     */
    private static function command_signature(array $command): string {
        $skill = trim((string)($command['skill'] ?? ''));
        $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
        // Ignore presentation-only keys so the signature reflects the actual intent.
        unset($input['outputlang']);
        ksort($input);
        return sha1($skill . '|' . json_encode($input));
    }

    /**
     * Record a non-retryable command failure so an identical re-issue can be short-circuited.
     *
     * @param int $threadid
     * @param array<string,mixed> $command
     * @param string $detail Human-readable failure detail (shown to the user on repeat).
     * @return void
     */
    private function record_failed_command(int $threadid, array $command, string $detail): void {
        $skill = trim((string)($command['skill'] ?? ''));
        if ($skill === '') {
            return;
        }
        $signature = self::command_signature($command);
        $stored = $this->store->get_thread_metadata_value($threadid, self::FAILED_COMMAND_SIGNATURES_KEY);
        $map = is_array($stored) ? $stored : [];
        $map[$signature] = (string)substr(trim($detail), 0, 600);
        if (count($map) > 20) {
            $map = array_slice($map, -20, null, true);
        }
        $this->store->set_thread_metadata_value($threadid, self::FAILED_COMMAND_SIGNATURES_KEY, $map);
    }

    /**
     * Return the recorded failure detail when this exact command already failed non-retryably,
     * or null when it has not.
     *
     * @param int $threadid
     * @param array<string,mixed> $command
     * @return string|null
     */
    private function get_failed_command_detail(int $threadid, array $command): ?string {
        if (trim((string)($command['skill'] ?? '')) === '') {
            return null;
        }
        $stored = $this->store->get_thread_metadata_value($threadid, self::FAILED_COMMAND_SIGNATURES_KEY);
        $map = is_array($stored) ? $stored : [];
        $signature = self::command_signature($command);
        return isset($map[$signature]) ? (string)$map[$signature] : null;
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
        $riskclass = is_array($item) ? $this->normalize_risk_class((string)($item['risk_class'] ?? '')) : skill_risk_class::R3;

        // R3 skills are idempotency-critical; retry after execution is forbidden.
        if ($riskclass === skill_risk_class::R3) {
            $budget = attempt_budget_dto::from_queue_item([
                'preflight_retry_count' => $retrycount,
                'retry_count' => $retrycount,
            ], max(1, $retrycount + 1), 'R3_NO_RETRY')->to_array();

            return [
                'queue_status' => 'failed',
                'issue_codes' => array_values(array_unique(array_merge($issuecodes, ['R3_NO_RETRY']))),
                'meta' => [
                    'retry_count' => $retrycount,
                    'attempt_budget' => $budget,
                ],
            ];
        }

        $gate = new preflight_execution_gate();
        $decision = $gate->evaluate($errorclass, $retrycount, $issuecodes);
        $decisionissuecodes = array_values(array_unique(array_merge($issuecodes, $decision->issuecodes)));

        if ($decision->status !== 'retry_hint') {
            $budget = attempt_budget_dto::from_queue_item([
                'preflight_retry_count' => $retrycount,
                'retry_count' => $retrycount,
            ], max(1, $retrycount + 1), 'RETRY_EXHAUSTED')->to_array();
            return [
                'queue_status' => 'failed',
                'issue_codes' => $decisionissuecodes,
                'meta' => [
                    'retry_count' => $retrycount,
                    'attempt_budget' => $budget,
                ],
            ];
        }

        $nextretrycount = $retrycount + 1;
        $retryafterms = max(1, (int)$decision->retryafterms);
        $budget = attempt_budget_dto::from_queue_item([
            'preflight_retry_count' => $nextretrycount,
            'retry_count' => $nextretrycount,
        ], max(1, $nextretrycount + 1))->to_array();
        return [
            'queue_status' => 'retry_waiting',
            'issue_codes' => $decisionissuecodes,
            'meta' => [
                'retry_count' => $nextretrycount,
                'preflight_retry_count' => $nextretrycount,
                'retry_after_ms' => $retryafterms,
                'backoff_ms' => $retryafterms,
                'next_retry_at' => time() + (int)ceil($retryafterms / 1000),
                'attempt_budget' => $budget,
            ],
        ];
    }

    /**
     * Normalize a queued risk class.
     *
     * @param string $riskclass
     * @return string
     */
    private function normalize_risk_class(string $riskclass): string {
        $riskclass = trim($riskclass);
        if (skill_risk_class::is_valid($riskclass)) {
            return $riskclass;
        }

        return skill_risk_class::R3;
    }

    /**
     * Build common audit fields for a queue item.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @param array<string,mixed> $retrymeta
     * @return array{queue_item_id:string,skillname:string,skill_version:int,retry_after_ms:int,contextid:int,reason_code:string}
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
            'skillname' => trim((string)($item['skill'] ?? '')),
            'skill_version' => max(0, (int)($item['version'] ?? 0)),
            'retry_after_ms' => max(0, (int)($retrymeta['retry_after_ms'] ?? ($item['retry_after_ms'] ?? 0))),
            'reason_code' => trim((string)($item['reason_code'] ?? '')),
        ];
    }

    /**
     * Continue runtime loop only when repair/follow-up work remains.
     *
     * @param array $rawresults
     * @return bool
     */
    private function should_continue_with_runtime_loop(array $rawresults): bool {
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
     * Find next pending mutating queue item.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $excludequeueitemid
     * @return array<string,mixed>|null
     */
    private function find_next_mutating_queue_item(
        queue_manager $queuesvc,
        int $threadid,
        string $excludequeueitemid = ''
    ): ?array {
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (!$this->is_actionable_mutating_queue_item($item, $excludequeueitemid)) {
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
     * Extract attempted skill names from commands.
     *
     * @param array $commands
     * @return array<int,string>
     */
    private function extract_attempted_skills_from_commands(array $commands): array {
        $skills = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $skill = trim((string)($command['skill'] ?? ''));
            if ($skill !== '') {
                $skills[] = $skill;
            }
        }

        return array_values(array_unique($skills));
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

            if (!is_array($this->get_active_mutating_queue_item($queuesvc, $threadid, $requestedqueueitemid))) {
                return '';
            }

            return $requestedqueueitemid;
        }

        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        foreach ($queueitemids as $candidate) {
            if (is_array($this->get_active_mutating_queue_item($queuesvc, $threadid, $candidate))) {
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
     * @param string $activequeueitemid
     * @return array<int,array<string,mixed>>
     */
    private function resolve_commands_for_run(
        queue_manager $queuesvc,
        int $threadid,
        string $activequeueitemid
    ): array {
        $item = $this->get_active_mutating_queue_item($queuesvc, $threadid, $activequeueitemid);
        if (!is_array($item)) {
            return [];
        }

        return queue_command_mapper::from_queue_items([$item], true);
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
            if (!queue_status_policy::is_actionable_mutating_status($status)) {
                continue;
            }

            $this->queuetransitionsvc->to_skipped(
                $queuesvc,
                $threadid,
                $queueitemid,
                'DEPENDENCY_FAILED_SKIP',
                ['DEPENDENCY_FAILED', 'LOGICAL_SKIP'],
                'dependency_failed',
                'Skipped because a dependent queue item failed.'
            );
        }
    }

    /**
     * Return a queue item only when it is an actionable mutating item.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @return array<string,mixed>|null
     */
    private function get_active_mutating_queue_item(
        queue_manager $queuesvc,
        int $threadid,
        string $queueitemid
    ): ?array {
        if (trim($queueitemid) === '') {
            return null;
        }

        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        if (!$this->is_actionable_mutating_queue_item($item)) {
            return null;
        }

        return $item;
    }

    /**
     * True when an item is mutating and currently actionable by status.
     *
     * @param mixed $item
     * @param string $excludequeueitemid
     * @return bool
     */
    private function is_actionable_mutating_queue_item($item, string $excludequeueitemid = ''): bool {
        if (!is_array($item)) {
            return false;
        }

        $queueitemid = trim((string)($item['queue_item_id'] ?? ''));
        if ($queueitemid === '' || $queueitemid === $excludequeueitemid) {
            return false;
        }

        if ((string)($item['mutability'] ?? '') !== 'mutating') {
            return false;
        }

        $status = trim((string)($item['status'] ?? ''));
        return queue_status_policy::is_actionable_mutating_status($status);
    }
}
