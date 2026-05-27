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
 * Central agent runtime: owns the full agent loop.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use core_text;
use core\context;
use context_module;
use bookingextension_agent\local\wbagent\agent_state;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wbagent\services\execution_observation_ledger;
use bookingextension_agent\local\wbagent\services\language_policy_service;

/**
 * Owns the complete agent execution loop: plan → execute → observe → decide.
 *
 * Responsibilities:
 * - Own the full agent loop (planning via LLM, tool execution, observation, next-step decision).
 * - Handle confirmation state machine, trigger routing, and read-only auto-execution.
 * - Manage pending intents and session state via conversation_store.
 * - Enforce the step counter and max-step limit for multi-turn loops.
 *
 * The API layer (ai_send_message) is a thin wrapper that:
 * 1. Does auth / session validation.
 * 2. Stores the user message.
 * 3. Calls AgentRuntime::run().
 * 4. Applies display-side privacy deanonymisation.
 * 5. Formats the result for the external API contract.
 *
 * Adding a new task MUST NOT require changes here — the task registry discovers
 * tasks automatically from all installed components.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_runtime {
    /** Maximum agent loop steps before bailing out. */
    public const MAX_LOOP_STEPS = 6;

    /** Maximum hard-contract repair retries before falling back to clarification. */
    private const MAX_CONTRACT_GATE_RETRIES = 2;

    /** Maximum identical readonly command-signature executions per loop invocation. */
    private const MAX_REPEATED_READONLY_SIGNATURE_STEPS = 2;

    /** Allowed final response_type values for persisted assistant messages. */
    private const ALLOWED_FINAL_RESPONSE_TYPES = [
        'task_call',
        'confirmation_request',
        'confirm_pending',
        'clarification',
        'sufficient',
        'error',
        'execution_result',
    ];

    /** @deprecated Use issue_code_provider::get_duplicate_confirmation_issue_codes() instead. */
    public const DUPLICATE_TITLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
    ];

    /** @deprecated Use issue_code_provider::get_token_subscription_issue_codes() instead. */
    public const TOKEN_SUBSCRIPTION_ISSUE_CODES = [
        'TRIAL_TOKEN_INVALID',
        'TRIAL_TOKEN_EXPIRED',
        'SUBSCRIPTION_REQUIRED',
        'AI_PROVIDER_AUTH_FAILED',
        'AI_PROVIDER_QUOTA_EXCEEDED',
    ];

    /** @deprecated Use issue_code_provider::get_prevalidation_confirmable_issue_codes() instead. */
    public const PREVALIDATION_CONFIRMABLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
        'CONFIRMATION_REQUIRED',
        'MISSING_LOCATION_CONFIRM_REQUIRED',
        'LOCATION_NOT_FOUND_POSSIBLE',
        'SLOTBOOKING_DURATION_EQUALS_WINDOW',
        'TEACHER_USER_NOT_FOUND',
    ];

    /** @deprecated Use issue_code_provider::get_basic_subscription_url() instead. */
    public const BASIC_SUBSCRIPTION_URL =
        'https://showroom.wunderbyte.at/mod/booking/optionview.php?optionid=73&cmid=938&userid=1';

    /** @deprecated Use issue_code_provider::get_premium_subscription_url() instead. */
    public const PRIVACY_PLUS_SUBSCRIPTION_URL =
        'https://showroom.wunderbyte.at/mod/booking/optionview.php?optionid=74&cmid=938&userid=1';

    /** @var task_registry */
    private task_registry $registry;

    /** @var orchestrator */
    private orchestrator $orchestrator;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var issue_code_provider_interface */
    private issue_code_provider_interface $issuecodeprovider;

    /** @var agent_decision_service */
    private agent_decision_service $decisionsvc;

    /** @var message_persistence_service */
    private message_persistence_service $messagepersistence;

    /** @var loop_finalizer */
    private loop_finalizer $loopfinalizer;

    /** @var queue_manager */
    private queue_manager $queuesvc;

    /** @var language_policy_service */
    private language_policy_service $languagepolicy;

    /**
     * Constructor.
     *
     * @param task_registry                   $registry
     * @param orchestrator                    $orchestrator
     * @param conversation_store              $store
     * @param authorization_service          $authz
     * @param issue_code_provider_interface   $issuecodeprovider
     */
    public function __construct(
        task_registry $registry,
        orchestrator $orchestrator,
        conversation_store $store,
        authorization_service $authz,
        issue_code_provider_interface $issuecodeprovider = null
    ) {
        $this->registry     = $registry;
        $this->orchestrator = $orchestrator;
        $this->store        = $store;
        $this->authz        = $authz;
        $this->issuecodeprovider = $issuecodeprovider ?? new booking_issue_code_provider();
        $this->decisionsvc  = new agent_decision_service($registry, $store, $authz, $this->issuecodeprovider);
        $this->messagepersistence = new message_persistence_service($store);
        $this->loopfinalizer = new loop_finalizer();
        $this->queuesvc = new queue_manager($store);
        $this->languagepolicy = new language_policy_service();
    }

    // -------------------------------------------------------------------------
    // Public interface.

    /**
     * Process the latest user message stored in the thread and return a
     * normalized result ready for the API layer.
     *
     * This is the single-step entry point: the orchestrator is called once, the
     * result is interpreted, and — if the LLM chose read-only tools — those tools
     * are immediately executed, with the observations added back to context.
     * Mutating commands always require user confirmation before execution.
     *
     * The returned array contains:
     *   response_type           (string)
     *   message                 (string)
     *   commands                (array)
     *   ambiguities             (array)
     *   ambiguity_options       (array)
     *   errors                  (array)
     *   attempted_tasks         (array)
     *   issue_codes             (array)
     *   pending_confirmation_code (string)
     *   used_triggers           (array)
     *   runid                   (int)
     *   results                 (array)
     *   lang                    (string)
     *
     * @param  int $threadid
     * @param  int $contextid
     * @param  int $userid
     * @return array
     */
    public function run(int $threadid, int $contextid, int $userid): array {
        try {
            $ctx = context::instance_by_id($contextid, MUST_EXIST);
            if (!($ctx instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
            $cmid = (int)$ctx->instanceid;
        } catch (\Throwable $e) {
            $cmid = (int)context_module::instance($contextid, MUST_EXIST)->instanceid;
        }
        $result = $this->run_internal($threadid, $cmid, $userid, [], null);
        $this->refresh_pending_queue_retry_state($threadid, $contextid);
        $result = $this->enforce_final_response_contract($result, $threadid);
        $this->messagepersistence->persist_assistant_message($threadid, $result);
        return $result;
    }

    /**
     * Check if another LLM call is still allowed within the loop budget.
     *
     * @param int $step Zero-based current step index.
     * @param int $limit Max configured loop steps.
     * @return bool
     */
    private function budget_guard_allows_next_llm_call(int $step, int $limit): bool {
        return ($step + 1) < $limit;
    }

    /**
     * Build a deterministic budget-exceeded result payload.
     *
     * @param int $threadid
     * @param array $result
     * @param agent_state $state
     * @param int $limit
     * @return array
     */
    private function build_budget_exceeded_result(int $threadid, array $result, agent_state $state, int $limit): array {
        $lang = $this->resolve_output_language($threadid, $result);
        $message = $this->localized_string('ai_agent_loop_continue_question', 'bookingextension_agent', (object)[
            'steps' => $limit,
        ], $lang);
        if ($message === '' || $message === 'ai_agent_loop_continue_question') {
            $message = 'Execution stopped because the loop budget is exhausted. Please simplify your request and try again.';
        }

        return [
            'response_type' => 'error',
            'message' => $message,
            'commands' => [],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => (array)($result['attempted_tasks'] ?? []),
            'issue_codes' => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['BUDGET_EXCEEDED']
            ))),
            'pending_confirmation_code' => '',
            'used_triggers' => (array)($result['used_triggers'] ?? []),
            'runid' => (int)($result['runid'] ?? 0),
            'results' => [],
            'lang' => $lang,
            'loop_step' => $state->step_count(),
            'loop_max_steps' => $limit,
        ];
    }

    /**
     * Refresh retry state for any pending intent queue items.
     *
     * Queue-backed confirmation intents carry queue_item_ids in thread metadata.
     * This keeps retry-waiting items moving once their pickup time has arrived,
     * and stores a compact snapshot when items are still blocked.
     *
     * @param int $threadid
     * @return void
     */
    private function refresh_pending_queue_retry_state(int $threadid, int $contextid): void {
        $pendingintent = $this->store->get_pending_intent($threadid);
        if ($pendingintent === null) {
            $this->store->set_thread_metadata_value($threadid, 'pending_queue_retry_state', null);
            return;
        }

        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        if (empty($queueitemids)) {
            $this->store->set_thread_metadata_value($threadid, 'pending_queue_retry_state', null);
            return;
        }

        $snapshot = [
            'contextid' => $contextid,
            'queue_item_ids' => [],
            'ready' => [],
            'waiting' => [],
        ];

        foreach ($queueitemids as $queueitemid) {
            $item = $this->queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item)) {
                continue;
            }

            $snapshot['queue_item_ids'][] = $queueitemid;
            $status = trim((string)($item['status'] ?? ''));
            if ($status !== 'retry_waiting') {
                continue;
            }

            if ($this->queuesvc->can_pickup_now($item)) {
                $this->queuesvc->update_status(
                    $threadid,
                    $queueitemid,
                    'ready',
                    (array)($item['issue_codes'] ?? []),
                    (string)($item['error_class'] ?? ''),
                    (string)($item['last_error_message'] ?? '')
                );
                $snapshot['ready'][] = $queueitemid;
                continue;
            }

            $snapshot['waiting'][] = [
                'queue_item_id' => $queueitemid,
                'contextid' => max(0, (int)($item['contextid'] ?? $contextid)),
                'next_retry_at' => (int)($item['next_retry_at'] ?? 0),
                'retry_after_ms' => (int)($item['retry_after_ms'] ?? 0),
                'backoff_ms' => (int)($item['backoff_ms'] ?? 0),
                'blocked_expires_at' => (int)($item['blocked_expires_at'] ?? 0),
            ];
        }

        if (empty($snapshot['queue_item_ids'])) {
            $this->store->set_thread_metadata_value($threadid, 'pending_queue_retry_state', null);
            return;
        }

        $this->store->set_thread_metadata_value($threadid, 'pending_queue_retry_state', $snapshot);
    }

    /**
     * Multi-step agent loop entry point.
     *
     * Implements a true internal agent loop: the LLM plans, tools execute,
     * observations are accumulated, and the next LLM call receives those
     * observations as structured context — all within a single request.
     *
     * Loop contract:
     * - Internal steps (execution_result) do NOT persist messages.
     * - Only the final step that requires user interaction persists ONE message.
     * - Observations from each step are fed back to the LLM via the orchestrator,
     *   never stored in the conversation DB.
     * - Mutating commands are never auto-executed; they always stop the loop for
     *   user confirmation.
     *
     * @param  int $threadid
     * @param  int $contextid
     * @param  int $userid
     * @param  int $maxsteps Override for MAX_LOOP_STEPS (0 = use constant).
     * @return array Final normalized result (one persistent assistant message written).
     */
    public function run_loop(int $threadid, int $contextid, int $userid, int $maxsteps = 0): array {
        try {
            $ctx = context::instance_by_id($contextid, MUST_EXIST);
            if (!($ctx instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
            $cmid = (int)$ctx->instanceid;
        } catch (\Throwable $e) {
            $cmid = (int)context_module::instance($contextid, MUST_EXIST)->instanceid;
        }
        $limit = ($maxsteps > 0) ? $maxsteps : self::MAX_LOOP_STEPS;
        $missingcommandsretryused = false;
        $preflightclarificationretryused = false;
        $readonlysignaturerepeatcounts = [];

        $seedobservationsraw = $this->store->get_thread_metadata_value($threadid, '_loop_seed_observations');
        $seedobservations = [];
        if (is_array($seedobservationsraw)) {
            foreach ($seedobservationsraw as $observation) {
                $trimmed = trim((string)$observation);
                if ($trimmed !== '') {
                    $seedobservations[] = $trimmed;
                }
            }
        }

        // Check whether the previous call hit the step limit and stored its observations
        // for resumption.  If the resume payload is still fresh, pre-load those observations
        // so the LLM receives full context from earlier steps without repeating tool calls.
        $resumedata = $this->store->get_thread_metadata_value($threadid, '_loop_resume');
        $resumeallowed = false;
        $recentmessages = $this->store->get_recent_messages($threadid, 8);
        for ($i = count($recentmessages) - 1; $i >= 0; $i--) {
            if ((string)($recentmessages[$i]->role ?? '') !== 'assistant') {
                continue;
            }
            $structured = json_decode((string)($recentmessages[$i]->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                break;
            }
            $issuecodes = array_map(
                static fn($code): string => trim(core_text::strtoupper((string)$code)),
                (array)($structured['issue_codes'] ?? [])
            );
            $resumeallowed = in_array('LOOP_STEP_LIMIT', $issuecodes, true);
            break;
        }
        $isresume   = (
            $resumeallowed
            &&
            is_array($resumedata)
            && !empty($resumedata['observations'])
            && ((int)($resumedata['expiresat'] ?? 0)) > time()
        );
        if (!empty($seedobservations)) {
            $state = agent_state::make_resumed($limit, $seedobservations);
            $this->store->set_thread_metadata_value($threadid, '_loop_seed_observations', null);
            // New seeded turn: reset planner-result cache so stale payloads cannot
            // leak into follow-up planner blocks.
            $this->store->set_thread_metadata_value($threadid, 'last_planner_result_json', null);
            $this->store->set_thread_metadata_value($threadid, 'planner_trace_history', []);
        } else if ($isresume) {
            $state = agent_state::make_resumed($limit, (array)$resumedata['observations']);
            $this->store->set_thread_metadata_value($threadid, '_loop_resume', null);
        } else {
            $state = agent_state::make($limit);
            // New user turn: reset planner-result cache so stale payloads from a
            // previous turn cannot leak into the next [PLANNER_RESULT] block.
            $this->store->set_thread_metadata_value($threadid, 'last_planner_result_json', null);
            $this->store->set_thread_metadata_value($threadid, 'planner_trace_history', []);
            // Clean up an expired entry if present.
            if (is_array($resumedata)) {
                $this->store->set_thread_metadata_value($threadid, '_loop_resume', null);
            }
        }

        // Remove step messages from previous turns before writing new ones,
        // so the frontend (which resets lastSeenStepId=0 each send) never
        // re-fetches stale Step 1 / Step 2 / … bubbles from earlier runs.
        $this->store->clear_step_messages($threadid);
        $anonymizer = new privacy_anonymizer($this->store);

        for ($step = 0; $step < $limit; $step++) {
            $state->currentstep = $step + 1;

            // Plan + route — does NOT persist anything.
            $result = $this->run_internal($threadid, $cmid, $userid, $state->get_observations(), $state);
            $this->refresh_pending_queue_retry_state($threadid, $contextid);

            $result['loop_step']      = $step + 1;
            $result['loop_max_steps'] = $limit;

            // If the step executed read-only tools successfully, record the observation
            // and continue the internal loop — the LLM will see the results next step.
            if ((string)($result['response_type'] ?? '') === 'execution_result') {
                $observationledger = new execution_observation_ledger($this->store);
                $observationledger->append_from_results(
                    $threadid,
                    (array)($result['results'] ?? []),
                    [
                        'source' => 'runtime_loop',
                        'run_id' => (int)($result['runid'] ?? 0),
                        'commands' => (array)($result['commands'] ?? []),
                    ]
                );

                $observation = result_payload_summarizer::for_observation(
                    $result['results'] ?? [],
                    $step + 1
                );
                $observation = (string)$anonymizer->anonymize_value_for_llm($threadid, $observation);
                $commands = (array)($result['commands'] ?? []);
                $state->record_step(
                    $commands,
                    $result['results'] ?? [],
                    $observation
                );

                $this->write_step_progress_message($threadid, $step + 1, $result, $anonymizer);

                // Hard stop: repeated readonly fingerprint reached budget.
                if (
                    $this->is_readonly_signature_budget_reached(
                        $readonlysignaturerepeatcounts,
                        $commands,
                        (array)($result['results'] ?? [])
                    )
                ) {
                    $lang = $this->resolve_output_language($threadid, $result);
                    $final = $this->loop_repeat_narration_result(
                        $threadid,
                        $cmid,
                        $userid,
                        $state,
                        $lang,
                        trim((string)($result['message'] ?? ''))
                    );
                    $final['issue_codes'] = array_values(array_unique(array_merge(
                        (array)($final['issue_codes'] ?? []),
                        ['LOOP_RESEARCH_BUDGET_REACHED']
                    )));
                    $final = $this->attach_loop_results($final, $state);
                    $final = $this->enforce_final_response_contract($final, $threadid);
                    $this->messagepersistence->persist_assistant_message($threadid, $final);
                    return $final;
                }

                // Hard stop: same readonly step repeated consecutively.
                if ($this->is_repeated_readonly_step($state, $commands, (array)($result['results'] ?? []))) {
                    $lang = $this->resolve_output_language($threadid, $result);
                    $final = $this->loop_repeat_narration_result(
                        $threadid,
                        $cmid,
                        $userid,
                        $state,
                        $lang,
                        trim((string)($result['message'] ?? ''))
                    );
                    $final['issue_codes'] = array_values(array_unique(array_merge(
                        (array)($final['issue_codes'] ?? []),
                        ['LOOP_REPEAT_DETECTED']
                    )));
                    $final = $this->attach_loop_results($final, $state);
                    $final = $this->enforce_final_response_contract($final, $threadid);
                    $this->messagepersistence->persist_assistant_message($threadid, $final);
                    return $final;
                }

                $final = $this->loopfinalizer->finalize(
                    $result,
                    $state,
                    self::MAX_LOOP_STEPS,
                    fn(array $commands, array $results): array => $this->extract_step_task_names($commands, $results),
                    fn(string $id, string $component, ?object $a, string $lang): string =>
                        $this->localized_string($id, $component, $a, $lang),
                    fn(array $results, string $currentmessage): string =>
                        $this->build_loop_repeat_summary($results, $currentmessage)
                );
                if (is_array($final)) {
                    // Even for deterministic early-finalize, run one synthesis step so
                    // the final user-facing response is composed via final_synthesis.
                    $final = $this->run_synthesis_step($threadid, $cmid, $userid, $state, $final);
                    $final = $this->attach_loop_results($final, $state);
                    $final = $this->enforce_final_response_contract($final, $threadid);
                    $this->messagepersistence->persist_assistant_message($threadid, $final);
                    return $final;
                }

                // Do NOT persist — continue to next internal step.
                if (!$this->budget_guard_allows_next_llm_call($step, $limit)) {
                    $budgetfailed = $this->build_budget_exceeded_result($threadid, $result, $state, $limit);
                    $budgetfailed = $this->attach_loop_results($budgetfailed, $state);
                    $budgetfailed = $this->enforce_final_response_contract($budgetfailed, $threadid);
                    $this->messagepersistence->persist_assistant_message($threadid, $budgetfailed);
                    return $budgetfailed;
                }
                continue;
            }

            // Sufficiency exit: planner only signals readiness; synthesis composes user-facing output.
            if ($this->is_sufficiency_exit_signal($result, $state)) {
                if ($this->should_convert_sufficient_to_readonly_clarification($result, $state)) {
                    $result['response_type'] = 'clarification';
                    $result['message'] = '';
                    $result = $this->run_synthesis_step($threadid, $cmid, $userid, $state, $result);
                } else {
                    $result['sufficiency_exit'] = true;
                    $result['message'] = '';
                    $result = $this->run_synthesis_step($threadid, $cmid, $userid, $state, $result);
                    $result['sufficiency_exit'] = true;
                }
            } else if ($this->should_synthesize_after_success_without_pending_intent($threadid, $result, $state)) {
                // Some planner outputs downgrade successful completion to confirm_pending
                // even though no pending intent remains. Synthesize a proper final answer.
                $result['response_type'] = 'sufficient';
                $result['message'] = '';
                $result = $this->run_synthesis_step($threadid, $cmid, $userid, $state, $result);
            } else if (
                (string)($result['response_type'] ?? '') === 'clarification'
                && empty((array)($result['commands'] ?? []))
                && $this->should_run_synthesis_for_clarification($result)
                && count($state->get_observations()) > 0
            ) {
                // Backward-compatible synthesis path for legacy planner outputs.
                $result = $this->run_synthesis_step($threadid, $cmid, $userid, $state, $result);
            }

            // One-shot self-healing retry for preflight clarification responses that
            // include actionable error details (e.g. ambiguity candidates).
            if ($this->should_retry_preflight_clarification($result, $state, $preflightclarificationretryused)) {
                $preflightclarificationretryused = true;
                $state->record_step([], [], $this->build_preflight_retry_observation($result, $step + 1));
                if (!$this->budget_guard_allows_next_llm_call($step, $limit)) {
                    $budgetfailed = $this->build_budget_exceeded_result($threadid, $result, $state, $limit);
                    $budgetfailed = $this->attach_loop_results($budgetfailed, $state);
                    $budgetfailed = $this->enforce_final_response_contract($budgetfailed, $threadid);
                    $this->messagepersistence->persist_assistant_message($threadid, $budgetfailed);
                    return $budgetfailed;
                }
                continue;
            }

            // Any other response type requires user interaction or signals completion.
            if ($this->should_recover_from_missing_commands_error($result, $state)) {
                // Self-healing retry: if the model returned a command-bearing response type
                // without commands, give it one silent corrective retry.
                if (!$missingcommandsretryused) {
                    $missingcommandsretryused = true;
                    if (!$this->budget_guard_allows_next_llm_call($step, $limit)) {
                        $budgetfailed = $this->build_budget_exceeded_result($threadid, $result, $state, $limit);
                        $budgetfailed = $this->attach_loop_results($budgetfailed, $state);
                        $budgetfailed = $this->enforce_final_response_contract($budgetfailed, $threadid);
                        $this->messagepersistence->persist_assistant_message($threadid, $budgetfailed);
                        return $budgetfailed;
                    }
                    continue;
                }
            }

            // Write step progress for all response types that reach here.
            // Steps should always be visible to the user, whether it's planning, confirmation,
            // clarification, or final result. The step label reflects the response type and content.
            $responsetype = (string)($result['response_type'] ?? '');
            if ($responsetype !== 'execution_result') {
                // Execution_result steps were already written above; write for all other types.
                $this->write_step_progress_message($threadid, $step + 1, $result, $anonymizer);
            }

            // Persist the SINGLE final assistant message and return.
            $result = $this->attach_loop_results($result, $state);
            $result = $this->enforce_final_response_contract($result, $threadid);
            $this->messagepersistence->persist_assistant_message($threadid, $result);
            return $result;
        }

        // Maximum steps reached: for observation-backed loops, answer with available findings
        // instead of asking for another research continuation.
        if (!empty($state->get_observations())) {
            $lang = trim(core_text::strtolower((string)$this->store->get_thread_metadata_value($threadid, 'last_output_lang')));
            if ($lang === '') {
                $lang = current_language();
            }
            $result = $this->loop_repeat_narration_result($threadid, $cmid, $userid, $state, $lang);
            $result['issue_codes'] = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['LOOP_RESEARCH_BUDGET_REACHED']
            )));
        } else {
            // Keep explicit continue-question behavior only when no observations were gathered.
            $this->store->set_thread_metadata_value($threadid, '_loop_resume', [
                'observations' => $state->get_observations(),
                'expiresat'    => time() + 900,
            ]);
            $result = $this->loop_continue_result(current_language(), $limit);
        }
        $result = $this->attach_loop_results($result, $state);
        $result = $this->enforce_final_response_contract($result, $threadid);
        $this->messagepersistence->persist_assistant_message($threadid, $result);
        return $result;
    }
    /**
     * Increment readonly signature counters and check whether loop budget is reached.
     *
     * @param array<string,int> $counts
     * @param array $commands
     * @param array $results
     * @return bool
     */
    private function is_readonly_signature_budget_reached(array &$counts, array $commands, array $results): bool {
        $signatures = $this->extract_step_command_signatures($commands, $results);
        if (empty($signatures)) {
            return false;
        }

        sort($signatures);
        $key = hash('sha256', json_encode($signatures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $counts[$key] = (int)($counts[$key] ?? 0) + 1;

        return $counts[$key] >= self::MAX_REPEATED_READONLY_SIGNATURE_STEPS;
    }

    /**
     * Enforce response-contract invariants before persisting user-visible assistant output.
     *
     * @param array $result
     * @param int $threadid
     * @return array
     */
    private function enforce_final_response_contract(array $result, int $threadid): array {
        $responsetype = trim((string)($result['response_type'] ?? ''));
        if (!in_array($responsetype, self::ALLOWED_FINAL_RESPONSE_TYPES, true)) {
            $result['response_type'] = 'clarification';
            $result['commands'] = [];
            $result['issue_codes'] = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['CONTRACT_INVALID_RESPONSE_TYPE']
            )));
            $responsetype = 'clarification';
        }

        $commands = $result['commands'] ?? [];
        if (is_array($commands) && isset($commands['task']) && !array_is_list($commands)) {
            $commands = [$commands];
        }
        if (!is_array($commands)) {
            $commands = [];
        }

        if (in_array($responsetype, ['task_call', 'confirmation_request'], true) && empty($commands)) {
            $result['response_type'] = 'clarification';
            $result['commands'] = [];
            $result['issue_codes'] = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['CONTRACT_COMMANDS_REQUIRED']
            )));
            $responsetype = 'clarification';
        } else if (in_array($responsetype, ['clarification', 'confirm_pending', 'error'], true)) {
            $result['commands'] = [];
        } else {
            $result['commands'] = array_values($commands);
        }

        $message = $this->strip_markdown_fences_from_message(trim((string)($result['message'] ?? '')));
        if ($message === '') {
            $message = $this->build_contract_fallback_message($responsetype, $threadid);
        }
        $result['message'] = $message;

        $userlang = $this->languagepolicy->resolve_output_language($this->store, $threadid, $result);

        // Hard policy: lang must match user_lang unless explicitly overridden upstream.
        $result['user_lang'] = $userlang;
        $result['lang'] = $userlang;

        $nextstepintent = trim((string)($result['next_step_intent'] ?? ''));
        if ($nextstepintent === '') {
            $nextstepintent = $this->localized_string(
                'ai_next_step_intent_default',
                'bookingextension_agent',
                null,
                $userlang
            );
        }
        $result['next_step_intent'] = $nextstepintent;

        $usedtriggers = [];
        foreach ((array)($result['used_triggers'] ?? []) as $trigger) {
            $trigger = trim((string)$trigger);
            if ($trigger !== '') {
                $usedtriggers[] = $trigger;
            }
        }
        $result['used_triggers'] = array_values(array_unique($usedtriggers));

        return $result;
    }

    /**
     * Normalize language values to ISO-639-1 lowercase or empty string.
     *
     * @param string $value
     * @return string
     */
    private function normalize_iso_language(string $value): string {
        return $this->languagepolicy->normalize_iso_language($value);
    }

    /**
     * Remove markdown code fences around assistant messages when present.
     *
     * @param string $message
     * @return string
     */
    private function strip_markdown_fences_from_message(string $message): string {
        if ($message === '') {
            return '';
        }

        if (preg_match('/^\x60\x60\x60(?:json)?\s*([\s\S]*?)\s*\x60\x60\x60$/i', $message, $matches) === 1) {
            $inner = trim((string)($matches[1] ?? ''));
            if ($inner !== '') {
                return $inner;
            }
        }

        return $message;
    }

    /**
     * Build a deterministic fallback message when model output misses user-facing text.
     *
     * @param string $responsetype
     * @param int $threadid
     * @return string
     */
    private function build_contract_fallback_message(string $responsetype, int $threadid): string {
        $lang = $this->languagepolicy->resolve_output_language($this->store, $threadid, []);
        $stringid = $this->languagepolicy->fallback_string_id_for_response_type($responsetype);

        return $this->localized_string($stringid, 'bookingextension_agent', null, $lang);
    }

    /**
     * Attach accumulated internal-step results to the final loop response.
     *
     * Collects all execution results recorded in $state and populates:
     *  - $result['loop_results']  — flat list of every result from every step.
     *  - $result['results']       — same list, but only when the response itself
     *                               carries no results (backward compat).
     *
     * This makes structured tool outputs available to callers (tests, UI)
     * even when the final response_type is 'clarification'.
     *
     * @param  array       $result Final result array.
     * @param  agent_state $state  Loop state with recorded steps.
     * @return array Updated result array.
     */
    private function attach_loop_results(array $result, agent_state $state): array {
        if ($state->step_count() === 0) {
            return $result;
        }
        $accumulated = [];
        $accumulatedtasks = [];
        $accumulatederrors = [];
        foreach ($state->get_steps() as $step) {
            foreach (
                $this->extract_step_task_names(
                    (array)($step['tool_calls'] ?? []),
                    (array)($step['results'] ?? [])
                ) as $taskname
            ) {
                if ($taskname !== '') {
                    $accumulatedtasks[] = $taskname;
                }
            }
            foreach ((array)($step['results'] ?? []) as $r) {
                $accumulated[] = $r;
                if (!is_array($r)) {
                    continue;
                }
                if (trim((string)($r['status'] ?? '')) !== 'error') {
                    continue;
                }
                $detail = trim((string)($r['detail'] ?? $r['usermessage'] ?? ''));
                if ($detail !== '') {
                    $accumulatederrors[] = $detail;
                }
            }
        }
        if (empty($accumulated)) {
            return $result;
        }

        if ($this->has_issue_code($result, 'LOOP_REPEAT_DETECTED')) {
            $accumulated = $this->deduplicate_loop_results($accumulated);
        }

        $result['loop_results'] = $accumulated;
        // Populate 'results' when the final response has none of its own.
        if (empty($result['results'])) {
            $result['results'] = $accumulated;
        }

        if (empty($result['attempted_tasks']) && !empty($accumulatedtasks)) {
            $result['attempted_tasks'] = array_values(array_unique($accumulatedtasks));
        }

        if (empty($result['errors']) && !empty($accumulatederrors)) {
            $result['errors'] = array_values(array_unique($accumulatederrors));
        }

        if ($this->has_issue_code($result, 'LOOP_REPEAT_DETECTED')) {
            // Prefer an informative summary for repeat stops so diagnosis reasons
            // are visible even when the loop ends before an additional LLM narration step.
            $current = trim((string)($result['message'] ?? ''));
            $summary = $this->build_loop_repeat_summary($accumulated, $current);
            $result['message'] = $this->is_low_information_message($current) ? $summary : ($summary !== '' ? $summary : $current);
        } else {
            // Preserve LLM-authored formatting/content for normal loop completion.
            // Observations are already provided to synthesis; avoid runtime appends.
            $result['message'] = trim((string)($result['message'] ?? ''));
        }

        return $result;
    }

    /**
     * Return true when all recorded loop tool results are from read-only tasks.
     *
     * @param agent_state $state
     * @return bool
     */
    private function loop_state_contains_only_readonly_results(agent_state $state): bool {
        $sawresult = false;
        foreach ($state->get_steps() as $step) {
            $tasks = $this->extract_step_task_names(
                (array)($step['tool_calls'] ?? []),
                (array)($step['results'] ?? [])
            );
            foreach ($tasks as $taskname) {
                $taskname = trim((string)$taskname);
                if ($taskname === '') {
                    continue;
                }
                $sawresult = true;
                if (!$this->registry->is_read_only_task($taskname)) {
                    return false;
                }
            }
        }

        return $sawresult;
    }

    /**
     * Deduplicate repeated loop results while preserving order.
     *
     * @param array $results
     * @return array
     */
    private function deduplicate_loop_results(array $results): array {
        $indexesbykey = [];
        $unique = [];

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $task = trim((string)($entry['task'] ?? ''));
            $resultid = (int)($entry['resultid'] ?? 0);
            $diagnosisuserid = (int)($entry['diagnosis']['userid'] ?? 0);
            $diagnosisoptionid = (int)($entry['diagnosis']['optionid'] ?? 0);

            $dedupkey = implode('|', [
                $task,
                (string)$resultid,
                (string)$diagnosisuserid,
                (string)$diagnosisoptionid,
            ]);

            if (!array_key_exists($dedupkey, $indexesbykey)) {
                $indexesbykey[$dedupkey] = count($unique);
                $unique[] = $entry;
                continue;
            }

            $existingindex = (int)$indexesbykey[$dedupkey];
            $existing = $unique[$existingindex] ?? [];
            if ($this->score_loop_result_entry($entry) > $this->score_loop_result_entry((array)$existing)) {
                $unique[$existingindex] = $entry;
            }
        }

        return array_values($unique);
    }

    /**
     * Heuristic score to keep the most informative repeated loop result.
     *
     * @param array $entry
     * @return int
     */
    private function score_loop_result_entry(array $entry): int {
        $score = 0;

        if (trim((string)($entry['status'] ?? '')) === 'executed') {
            $score += 10;
        }

        $issue = trim(core_text::strtolower((string)($entry['diagnosis']['issue'] ?? '')));
        if ($issue === 'cannot_book') {
            $score += 30;
        } else if ($issue === 'missing_email') {
            $score += 20;
        } else if ($issue === 'booking_status') {
            $score += 10;
        }

        $reasons = array_values(array_filter(array_map(
            static fn($reason): string => trim((string)$reason),
            (array)($entry['diagnosis']['reasons'] ?? [])
        )));
        $score += min(count($reasons), 10);

        $message = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
        $score += min((int)floor(strlen($message) / 80), 5);

        return $score;
    }

    /**
     * Check whether a normalized issue code exists on the result.
     *
     * @param array $result
     * @param string $needle
     * @return bool
     */
    private function has_issue_code(array $result, string $needle): bool {
        $needle = trim(core_text::strtoupper($needle));
        if ($needle === '') {
            return false;
        }
        foreach ((array)($result['issue_codes'] ?? []) as $code) {
            if (trim(core_text::strtoupper((string)$code)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a user-visible summary for repeated readonly loops.
     *
     * @param array $results
     * @param string $currentmessage
     * @return string
     */
    private function build_loop_repeat_summary(array $results, string $currentmessage): string {
        $currentmessage = trim($currentmessage);
        $bestfallback = '';
        $resultsummary = '';

        for ($i = count($results) - 1; $i >= 0; $i--) {
            $entry = $results[$i] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $candidate = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? $entry['summary'] ?? ''));
            if ($candidate !== '' && $bestfallback === '' && !$this->is_low_information_message($candidate)) {
                $bestfallback = $candidate;
            }
            // Prefer localized task-authored text for user-facing summaries.
            if ($resultsummary === '') {
                if ($candidate !== '') {
                    $resultsummary = $candidate;
                } else {
                    $resultsummary = result_payload_summarizer::describe_entry($entry, 0, 'client_fallback');
                }
            }

            $diagnosis = $entry['diagnosis'] ?? null;
            if (!is_array($diagnosis)) {
                continue;
            }

            $intro = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? $currentmessage));
            if ($intro === '' || $this->is_low_information_message($intro)) {
                $intro = $bestfallback;
            }

            $reasons = [];
            foreach ((array)($diagnosis['reasons'] ?? []) as $reason) {
                $text = trim((string)$reason);
                if ($text !== '') {
                    $reasons[] = '- ' . $text;
                }
            }

            if (!empty($reasons)) {
                $lines = array_slice(array_values(array_unique($reasons)), 0, 5);
                if ($intro !== '') {
                    return $intro . "\n\n" . implode("\n", $lines);
                }
                return implode("\n", $lines);
            }
        }

        // If any result type provided a meaningful summary, return a localized fallback.
        if ($resultsummary !== '') {
            $base = $this->is_low_information_message($currentmessage) ? $bestfallback : $currentmessage;
            if ($this->is_low_information_message($base)) {
                $base = '';
            }
            return $base !== '' ? $base : $resultsummary;
        }

        if (!$this->is_low_information_message($currentmessage)) {
            return $currentmessage;
        }
        if ($bestfallback !== '') {
            return $bestfallback;
        }
        return $currentmessage;
    }

    /**
     * Enrich a generic LLM message with a result summary extracted from loop results.
     *
     * When the LLM returns a short, non-specific message after a loop step, the
     * framework appends a deterministic summary built by result_payload_summarizer.
     * This is generic: it works for any task type (options, users, courses, etc.).
     *
     * @param  string $message   Current LLM message.
     * @param  array  $results   Accumulated loop step results.
     * @return string            Enriched message (unchanged when already informative).
     */
    private function maybe_enrich_message_from_results(string $message, array $results): string {
        $message = trim($message);
        // Keep already detailed messages with links; otherwise allow enrichment.
        if (
            $message !== ''
            && (strlen($message) > 200 || str_contains($message, "\n"))
            && str_contains(core_text::strtolower($message), 'http')
        ) {
            return $message;
        }

        // Find the first result entry that yields a non-empty localized summary.
        $summary = '';
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $candidate = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? $entry['summary'] ?? ''));
            if ($candidate === '') {
                $candidate = result_payload_summarizer::describe_entry($entry, 0, 'client_fallback');
            }
            if ($candidate !== '') {
                $summary = $candidate;
                break;
            }
        }

        if ($summary === '') {
            return $message;
        }

        // Skip enrichment when the summary content already appears in the message.
        $messagelower = core_text::strtolower($message);
        $summarylower = core_text::strtolower($summary);
        // Use a short representative token (first 20 chars) to avoid false negatives.
        $token = core_text::substr($summarylower, 0, 20);
        if ($token !== '' && strpos($messagelower, $token) !== false) {
            return $message;
        }

        return $message !== '' ? $message . ' ' . $summary : $summary;
    }

    /**
     * Decide whether the current readonly execution step already contains enough
     * information to end the loop with a clarification response.
     *
     * @param array $result
     * @param agent_state $state
     * @return bool
     */
    private function should_finalize_after_execution_result(array $result, agent_state $state): bool {
        if ((string)($result['response_type'] ?? '') !== 'execution_result') {
            return false;
        }

        $results = (array)($result['results'] ?? []);
        if (empty($results)) {
            return false;
        }

        $commands = (array)($result['commands'] ?? []);
        $tasks = $this->extract_step_task_names($commands, $results);
        if ($state->step_count() < 2) {
            return false;
        }

        $message = trim((string)($result['message'] ?? ''));
        $enriched = $this->maybe_enrich_message_from_results($message, $results);

        if ($this->is_low_information_message($enriched)) {
            return false;
        }

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $selectedpath = trim((string)($entry['selected_doc_path'] ?? ''));
            if ($selectedpath !== '') {
                return true;
            }
            if (!empty((array)($entry['docs'] ?? []))) {
                return true;
            }
        }

        return strlen($enriched) >= 120;
    }

    /**
     * Build a deterministic clarification payload from a sufficiently informative
     * readonly execution step.
     *
     * @param array $result
     * @param agent_state $state
     * @return array
     */
    private function build_sufficient_execution_result_clarification(array $result, agent_state $state): array {
        $results = (array)($result['results'] ?? []);
        $message = trim((string)($result['message'] ?? ''));
        $message = $this->maybe_enrich_message_from_results($message, $results);

        if ($message === '' || $this->is_low_information_message($message)) {
            $message = $this->build_loop_repeat_summary($results, $message);
        }

        if ($message === '' || $this->is_low_information_message($message)) {
            $message = $this->localized_string('ai_run_executed', 'bookingextension_agent', null, (string)($result['lang'] ?? ''));
            if ($message === 'ai_run_executed') {
                $message = 'I found enough information to answer your question.';
            }
        }

        $attemptedtasks = $this->extract_step_task_names((array)($result['commands'] ?? []), $results);

        return [
            'response_type'             => 'clarification',
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => $attemptedtasks,
            'issue_codes'               => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['LOOP_EARLY_SUFFICIENT_CONTEXT']
            ))),
            'pending_confirmation_code' => '',
            'used_triggers'             => (array)($result['used_triggers'] ?? []),
            'runid'                     => (int)($result['runid'] ?? 0),
            'results'                   => [],
            'lang'                      => (string)($result['lang'] ?? ''),
            'loop_step'                 => $state->step_count(),
            'loop_max_steps'            => self::MAX_LOOP_STEPS,
        ];
    }

    /**
     * Decide whether a loop step error should be downgraded to a user-facing clarification.
     *
     * @param array $result
     * @param agent_state $state
     * @return bool
     */
    private function should_recover_from_missing_commands_error(array $result, agent_state $state): bool {
        if ((string)($result['response_type'] ?? '') !== 'error') {
            return false;
        }

        $needle = 'response type requires at least one command but none were provided';
        $message = core_text::strtolower(trim((string)($result['message'] ?? '')));
        if (str_contains($message, $needle)) {
            return true;
        }

        foreach ((array)($result['errors'] ?? []) as $error) {
            $candidate = core_text::strtolower(trim((string)$error));
            if (str_contains($candidate, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a clarification result from prior loop observations when the current step failed structurally.
     *
     * @param array $result
     * @param agent_state $state
     * @return array
     */
    private function recover_missing_commands_error_result(array $result, agent_state $state): array {
        $accumulated = [];
        foreach ($state->get_steps() as $step) {
            foreach ((array)($step['results'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $accumulated[] = $entry;
                }
            }
        }

        $summary = $this->build_loop_repeat_summary($accumulated, '');
        if ($summary === '') {
            $summary = trim((string)($result['message'] ?? ''));
        }

        $technicalneedle = 'response type requires at least one command but none were provided';
        if ($summary === '' || str_contains(core_text::strtolower($summary), $technicalneedle)) {
            $lang = trim((string)($result['lang'] ?? ''));
            $summary = $this->localized_string(
                'ai_agent_malformed_taskcall_clarification',
                'bookingextension_agent',
                null,
                $lang
            );
            if ($summary === 'ai_agent_malformed_taskcall_clarification') {
                $summary = 'I could not reliably parse the last step. Please ask your question again in one short sentence.';
            }
        }

        return [
            'response_type'             => 'clarification',
            'message'                   => $summary,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => (array)($result['attempted_tasks'] ?? []),
            'issue_codes'               => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['LOOP_MALFORMED_TASKCALL_RECOVERED']
            ))),
            'pending_confirmation_code' => '',
            'used_triggers'             => (array)($result['used_triggers'] ?? []),
            'runid'                     => 0,
            'results'                   => [],
            'lang'                      => (string)($result['lang'] ?? ''),
        ];
    }

    /**
     * Decide whether a clarification should trigger one internal retry.
     *
     * Reuses the existing loop/observation mechanism without introducing a
     * separate retry subsystem. Guarded to run at most once per request.
     *
     * @param array $result
     * @param agent_state $state
     * @param bool $alreadyused
     * @return bool
     */
    private function should_retry_preflight_clarification(
        array $result,
        agent_state $state,
        bool $alreadyused
    ): bool {
        if ($alreadyused) {
            return false;
        }

        if ((string)($result['response_type'] ?? '') !== 'clarification') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        $attemptedtasks = (array)($result['attempted_tasks'] ?? []);
        $errors = array_values(array_filter(array_map('trim', (array)($result['errors'] ?? []))));
        $issuecodes = array_values(array_filter(array_map('trim', (array)($result['issue_codes'] ?? []))));

        if (empty($attemptedtasks) || empty($errors)) {
            return false;
        }

        // Never retry loop-control/system conditions.
        foreach ($issuecodes as $code) {
            if (str_starts_with((string)$code, 'LOOP_')) {
                return false;
            }
        }

        // Default behavior: allow retry before any prior observation exists.
        if (empty($state->get_observations())) {
            return true;
        }

        // In multi-step runs, only retry when we have clear schema/contract-repair
        // evidence. This avoids perturbing unrelated clarification flows.
        foreach ($issuecodes as $code) {
            $upper = core_text::strtoupper((string)$code);
            if (str_starts_with($upper, 'CONTRACT_')) {
                return true;
            }
        }

        $repairtext = core_text::strtolower(
            trim((string)($result['message'] ?? '')) . ' ' . implode(' ', $errors)
        );
        $repairmarkers = [
            'corrected canonical keys',
            'missing required fields',
            'remove unknown keys',
            'unknown properties',
            'schema mismatch',
            'resend the same task once with corrected input',
        ];

        foreach ($repairmarkers as $marker) {
            if ($marker !== '' && str_contains($repairtext, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a post-success confirm_pending should be synthesized.
     *
     * @param int $threadid
     * @param array $result
     * @param agent_state $state
     * @return bool
     */
    private function should_synthesize_after_success_without_pending_intent(
        int $threadid,
        array $result,
        agent_state $state
    ): bool {
        if ((string)($result['response_type'] ?? '') !== 'confirm_pending') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        if (count($state->get_observations()) === 0) {
            return false;
        }

        return $this->store->get_pending_intent($threadid) === null;
    }

    /**
     * Build a compact synthetic observation from a preflight clarification.
     *
     * @param array $result
     * @param int $step
     * @return string
     */
    private function build_preflight_retry_observation(array $result, int $step): string {
        $parts = [];
        $attemptedtasks = array_values(array_filter(array_map('trim', (array)($result['attempted_tasks'] ?? []))));
        $errors = array_values(array_filter(array_map('trim', (array)($result['errors'] ?? []))));

        $parts[] = 'Preflight failed before any task execution. Repair the command input and retry the same task.';
        $parts[] = 'Return response_type="task_call" with a non-empty commands array.';

        if (count($attemptedtasks) === 1) {
            $parts[] = 'Keep task=' . $attemptedtasks[0] . ' unless the latest user message explicitly changes the intent.';
            $parts[] = 'Required payload shape: {"response_type":"task_call","commands":[{"task":"'
                . $attemptedtasks[0]
                . '","version":1,"input":{...}}]}.';
        } else if (!empty($attemptedtasks)) {
            $parts[] = 'Keep the same attempted task set unless the latest user message explicitly changes the intent.';
            $parts[] = 'Required payload shape: '
                . '{"response_type":"task_call","commands":[{"task":"<same_task>","version":1,"input":{...}}]}.';
        } else {
            $parts[] = 'Required payload shape: '
                . '{"response_type":"task_call","commands":[{"task":"<task>","version":1,"input":{...}}]}.';
        }

        $fixinstructions = $this->build_preflight_fix_instructions($errors);
        if (!empty($fixinstructions)) {
            $parts[] = 'Required command corrections: ' . implode(' ', array_slice($fixinstructions, 0, 8));
        } else {
            $message = trim((string)($result['message'] ?? ''));
            if ($message !== '') {
                $parts[] = $message;
            }
            if (!empty($errors)) {
                $parts[] = 'Errors: ' . implode(' || ', array_slice($errors, 0, 12));
            }
        }

        $issuecodes = array_values(array_filter(array_map('trim', (array)($result['issue_codes'] ?? []))));
        if (!empty($issuecodes)) {
            $parts[] = 'issue_codes=' . implode(',', array_slice($issuecodes, 0, 12));
        }

        if (!empty($attemptedtasks)) {
            $parts[] = 'attempted_tasks=' . implode(',', array_slice($attemptedtasks, 0, 4));
        }

        $taskcatalogcontext = $this->build_retry_task_catalog_context($attemptedtasks);
        if ($taskcatalogcontext !== '') {
            $parts[] = $taskcatalogcontext;
        }

        if (empty($parts)) {
            return 'RETRY_HINT: Step ' . $step . ': Preflight clarification without details.';
        }

        return 'RETRY_HINT: Step ' . $step . ': Preflight clarification. ' . implode(' ', $parts);
    }

    /**
     * Build compact task-catalog context for retry observations.
     *
     * @param array<int,string> $attemptedtasks
     * @return string
     */
    private function build_retry_task_catalog_context(array $attemptedtasks): string {
        if (empty($attemptedtasks)) {
            return '';
        }

        $contracts = (array)$this->registry->get_all_prompt_contracts();
        $bytask = [];
        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $taskname = trim((string)($contract['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $bytask[$taskname] = $contract;
        }

        $selected = [];
        foreach ($attemptedtasks as $taskname) {
            if (isset($bytask[$taskname])) {
                $selected[] = $this->slim_retry_task_contract((array)$bytask[$taskname]);
            }
        }

        if (empty($selected)) {
            return '';
        }

        if (count($selected) === 1) {
            $json = json_encode($selected[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($json) ? $json : '';
        }

        $json = json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }

    /**
     * Keep retry task context compact and stable for planner repair steps.
     *
     * @param array $contract
     * @return array
     */
    private function slim_retry_task_contract(array $contract): array {
        return [
            'task' => trim((string)($contract['task'] ?? '')),
            'readonly' => !empty($contract['readonly']),
            'intent' => trim((string)($contract['intent'] ?? '')),
            'minimal_input' => array_values((array)($contract['minimal_input'] ?? [])),
            'example_input' => (array)($contract['example_input'] ?? []),
        ];
    }

    /**
     * Build concrete, planner-friendly input correction instructions from validation errors.
     *
     * @param array<int,string> $errors
     * @return array<int,string>
     */
    private function build_preflight_fix_instructions(array $errors): array {
        $instructions = [];

        foreach ($errors as $error) {
            $clean = trim(preg_replace('/^\s*Command\s*#\d+\s*:\s*/i', '', (string)$error) ?? (string)$error);
            if ($clean === '') {
                continue;
            }

            if (
                preg_match('/Field\s+"([^"]+)"\s+is\s+required\s+for\s+mode\s+"([^"]+)"\.?/i', $clean, $m) === 1
            ) {
                $instructions[] = 'Wrong: input.mode="' . $m[2] . '" without input.' . $m[1]
                    . '. Right: set input.' . $m[1] . ' to a non-empty value.';
                continue;
            }

            if (
                preg_match('/Field\s+"([^"]+)"\s+must\s+be\s+either\s+"([^"]+)"\s+or\s+"([^"]+)"\.?/i', $clean, $m) === 1
            ) {
                $instructions[] = 'Wrong: input.' . $m[1] . ' has an unsupported value. Right: set input.' . $m[1]
                    . ' to exactly one of ["' . $m[2] . '", "' . $m[3] . '"].';
                continue;
            }

            if (preg_match('/Field\s+"([^"]+)"\s+is\s+required\.?/i', $clean, $m) === 1) {
                $instructions[] = 'Wrong: missing required field input.' . $m[1]
                    . '. Right: set input.' . $m[1] . ' to a non-empty value.';
                continue;
            }

            if (preg_match('/Field\s+"([^"]+)"\s+must\s+be\s+numeric\.?/i', $clean, $m) === 1) {
                $instructions[] = 'Wrong: input.' . $m[1] . ' is not numeric. Right: set input.' . $m[1] . ' to a numeric value.';
                continue;
            }

            $instructions[] = 'Wrong: ' . $clean . ' Right: correct the field values and resend the same task once.';
        }

        return array_values(array_unique($instructions));
    }

    /**
     * Detect whether observations only contain framework-authored retry hints.
     *
     * @param array $observations
     * @return bool
     */
    private function observations_are_framework_retry_hints(array $observations): bool {
        $seen = false;

        foreach ($observations as $observation) {
            $text = trim((string)$observation);
            if ($text === '') {
                continue;
            }

            $seen = true;
            if (!str_starts_with($text, 'RETRY_HINT:')) {
                return false;
            }
        }

        return $seen;
    }

    /**
     * Detect generic low-information status messages.
     *
     * @param string $message
     * @return bool
     */
    private function is_low_information_message(string $message): bool {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return true;
        }
        if (strlen($trimmed) > 180 || str_contains($trimmed, "\n")) {
            return false;
        }

        $normalized = core_text::strtolower($trimmed);
        $markers = [
            'i have checked',
            'i checked',
            'checked your booking situation',
            'checked the situation',
        ];
        foreach ($markers as $marker) {
            if (strpos($normalized, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a human-readable label for an internal loop step.
     *
     * @param int   $stepnum
     * @param array $commands
     * @param array $results
     * @return string
     */
    private function build_step_label(int $stepnum, array $commands, array $results, string $nextstepintent = ''): string {
        $intent = trim($nextstepintent);
        if ($intent === '') {
            $intent = $this->extract_next_step_intent($results);
        }
        if ($intent !== '') {
            // Keep planner phrasing unchanged so frontend step bubbles mirror
            // the exact next_step_intent message from planner JSON.
            return $intent;
        }

        $descriptions = [];
        foreach ($this->extract_step_task_names($commands, $results) as $taskname) {
            if ($taskname === '') {
                continue;
            }

            $task = $this->registry->get_task($taskname);
            $schema = $task ? $task->get_schema() : [];
            $description = trim((string)($schema['description'] ?? ''));
            if ($description !== '') {
                $description = preg_replace('/\s+via\s+.+$/i', '', $description) ?? $description;
                $description = rtrim($description, ". \t\n\r\0\x0B");
            }
            if ($description === '') {
                $description = $this->humanize_task_name($taskname);
            }
            $descriptions[] = $description;
        }

        $descriptions = array_values(array_unique(array_filter($descriptions)));
        if (!empty($descriptions)) {
            return 'Step ' . $stepnum . ': ' . implode(' + ', $descriptions);
        }

        if (!empty($results)) {
            return 'Step ' . $stepnum . ': Processing tool results';
        }

        return 'Step ' . $stepnum . ': Processing';
    }

    /**
     * Write one ephemeral step-progress message for frontend polling.
     *
     * @param int $threadid
     * @param int $stepnum
     * @param array $result
     * @param privacy_anonymizer $anonymizer
     * @return void
     */
    private function write_step_progress_message(
        int $threadid,
        int $stepnum,
        array $result,
        privacy_anonymizer $anonymizer
    ): void {
        $commands = (array)($result['commands'] ?? []);
        $results = (array)($result['results'] ?? []);
        $steptask = implode(', ', $this->extract_step_task_names($commands, $results));
        $steplabel = $this->build_step_label(
            $stepnum,
            $commands,
            $results,
            (string)($result['next_step_intent'] ?? '')
        );
        $displaylabelresult = $anonymizer->deanonymize_message_for_display($threadid, $steplabel);
        $displaylabel = (string)($displaylabelresult['message'] ?? $steplabel);
        $displaytask = (string)($anonymizer->deanonymize_message_for_display($threadid, $steptask)['message'] ?? $steptask);
        $this->store->add_step_message($threadid, $stepnum, $displaylabel, $displaytask);
    }

    /**
     * Extract a natural-language next step intent from task results.
     *
     * @param array $results
     * @return string
     */
    private function extract_next_step_intent(array $results): string {
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $intent = trim((string)($result['next_step_intent'] ?? ''));
            if ($intent !== '') {
                return $intent;
            }
        }

        return '';
    }

    /**
     * Extract task names for a completed loop step.
     *
     * execution_result payloads often clear `commands`, so labels and cycle detection
     * need to fall back to the task names embedded in `results`.
     *
     * @param array $commands
     * @param array $results
     * @return string[]
     */
    private function extract_step_task_names(array $commands, array $results): array {
        $tasknames = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '') {
                $tasknames[] = $taskname;
            }
        }

        if (!empty($tasknames)) {
            return array_values(array_unique($tasknames));
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $taskname = trim((string)($result['task'] ?? ''));
            if ($taskname !== '') {
                $tasknames[] = $taskname;
            }
        }

        return array_values(array_unique($tasknames));
    }

    /**
     * Convert a technical task name into a readable fallback label.
     *
     * @param string $taskname
     * @return string
     */
    private function humanize_task_name(string $taskname): string {
        $taskname = trim($taskname);
        if ($taskname === '') {
            return 'Processing';
        }

        $tail = $taskname;
        if (str_contains($taskname, '.')) {
            $parts = explode('.', $taskname);
            $tail = (string)end($parts);
        }

        $tail = str_replace('_', ' ', $tail);
        return ucfirst($tail);
    }

    /**
     * Detect whether the current readonly step repeats the same command signature as the previous step.
     *
     * For docs traversal, the same readonly task may legitimately repeat with a different
     * doc_path or line_start. Those follow-up reads must not be treated as a loop.
     *
     * @param agent_state $state
     * @param array $commands
     * @param array $results
     * @return bool
     */
    private function is_repeated_readonly_step(agent_state $state, array $commands, array $results): bool {
        if ($state->step_count() < 2) {
            return false;
        }

        $steps = $state->get_steps();
        $currentsignatures = $this->extract_step_command_signatures(
            (array)($steps[count($steps) - 1]['tool_calls'] ?? []),
            (array)($steps[count($steps) - 1]['results'] ?? [])
        );
        $previoussignatures = $this->extract_step_command_signatures(
            (array)($steps[count($steps) - 2]['tool_calls'] ?? []),
            (array)($steps[count($steps) - 2]['results'] ?? [])
        );

        // Keep a safety check against malformed current step payload.
        if (empty($currentsignatures) || empty($this->extract_step_command_signatures($commands, $results))) {
            return false;
        }

        sort($currentsignatures);
        sort($previoussignatures);

        return $currentsignatures === $previoussignatures;
    }

    /**
     * Extract comparable command signatures for a completed loop step.
     *
     * Prefer task + normalized input for tool calls. Fall back to task names embedded
     * in results when the executed command payload is unavailable.
     *
     * @param array $commands
     * @param array $results
     * @return string[]
     */
    private function extract_step_command_signatures(array $commands, array $results): array {
        $signatures = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                $input = [];
            }

            $normalizedinput = $this->normalize_command_input_for_signature($input);
            $encodedinput = json_encode($normalizedinput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signatures[] = $taskname . '|' . (is_string($encodedinput) ? $encodedinput : '{}');
        }

        if (!empty($signatures)) {
            return array_values(array_unique($signatures));
        }

        return $this->extract_step_task_names($commands, $results);
    }

    /**
     * Recursively normalize command input for stable loop-signature comparison.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_command_input_for_signature($value) {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn($item) => $this->normalize_command_input_for_signature($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize_command_input_for_signature($item);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Private: loop helpers.

    /**
     * Execute one internal agent step: plan (LLM) + decide (routing), with NO persistence.
     *
     * Unlike run(), this method never writes an assistant message to the DB.
     * It is the building block for run_loop() and is also used by run() (which
     * adds the single persistence call afterwards).
     *
     * @param  int           $threadid     Thread id.
     * @param  int           $cmid         Course-module id.
     * @param  int           $userid       User id.
     * @param  string[]      $observations Structured observation strings from prior internal steps.
     *                                      Injected into the LLM prompt — never stored in the DB.
     * @param  agent_state|null $state    Current loop state (null for single-shot run()).
     * @return array Normalized result (not yet persisted).
     */
    private function run_internal(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        ?agent_state $state = null
    ): array {
        $previewoptionid = $this->resolve_preview_option_id($threadid, $cmid, $userid);
        $triggerregistry = new message_trigger_registry($this->registry);

        $optiontypeshortcut = $this->build_option_type_explanation_shortcut($threadid);
        if (is_array($optiontypeshortcut)) {
            return $optiontypeshortcut;
        }

        // Plan: initial step uses tool_call_parse; every follow-up step with
        // observations (including framework retry hints) stays append-only via
        // simple_retrieval.
        $plannersteptype = !empty($observations)
            ? orchestrator::STEP_TYPE_SIMPLE_RETRIEVAL
            : orchestrator::STEP_TYPE_TOOL_CALL_PARSE;

        // Final synthesis via generate_text is triggered separately in run_loop() once the planner
        // signals completion with response_type=clarification and commands=[].
        $result = $this->call_orchestrator_step(
            $threadid,
            $cmid,
            $userid,
            $observations,
            $plannersteptype
        );

        $plannerrawresponse = is_string($result['_planner_raw_response'] ?? null)
            ? (string)$result['_planner_raw_response']
            : '';
        unset($result['_planner_raw_response']);

        // Keep PLANNER_TRACE as the exact last planner answer for the next call.
        $plannerresultjson = $plannerrawresponse;
        if ($plannerresultjson === '') {
            $fallbackjson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $plannerresultjson = is_string($fallbackjson) ? $fallbackjson : '';
        }
        if ($plannerresultjson !== '') {
            $this->store->set_thread_metadata_value($threadid, 'planner_trace_history', [$plannerresultjson]);
            $this->store->set_thread_metadata_value($threadid, 'last_planner_result_json', $plannerresultjson);
        }

        $outputlang = $this->resolve_output_language($threadid, $result);
        $this->store->set_thread_metadata_value($threadid, 'last_output_lang', $outputlang);

        // Option A language detection: on the first (TCP) call the model may return the user's
        // language. Persist it as a separate key so subsequent SR steps cannot overwrite it.
        if ($plannersteptype === orchestrator::STEP_TYPE_TOOL_CALL_PARSE) {
            $explicitlang = $this->normalize_iso_language((string)($result['user_lang'] ?? $result['lang'] ?? ''));
            if ($explicitlang !== '') {
                $this->store->set_thread_metadata_value($threadid, 'user_input_lang', $explicitlang);
            }
        }

        $result['used_triggers'] = $triggerregistry->normalize_used_triggers($result['used_triggers'] ?? []);
        $result = $this->normalize_unknown_response_type_to_contract_error($result, $outputlang, $triggerregistry);

        // HARD CONTRACT GATE: Detect and recover from parse/schema errors before routing.
        // These errors must NEVER reach decision_service unguarded.
        $result = $this->apply_hard_contract_gate($result, $threadid, $cmid, $userid, $observations, $outputlang);
        if ($this->is_hard_contract_error($result)) {
            // Early exit with safe fallback — do not proceed to decision_service.
            return $result;
        }

        $rawresponsetype = trim((string)($result['response_type'] ?? ''));
        $result['response_type'] = $triggerregistry->normalize_response_type($rawresponsetype);

        // Server-authoritative derivation of core.is_lookup_request.
        // The LLM value is intentionally ignored: the trigger is set/cleared purely
        // based on whether all commands reference read-only tasks in the registry.
        if ((string)($result['response_type'] ?? '') === 'task_call') {
            $commands = (array)($result['commands'] ?? []);
            $allreadonly = !empty($commands);
            foreach ($commands as $command) {
                if (!is_array($command)) {
                    $allreadonly = false;
                    break;
                }
                $taskname = trim((string)($command['task'] ?? ''));
                if ($taskname === '' || !$this->registry->is_read_only_task($taskname)) {
                    $allreadonly = false;
                    break;
                }
            }
            // Rebuild used_triggers: remove any LLM-provided is_lookup_request, then re-add if warranted.
            $usedtriggers = array_values(array_filter(
                (array)($result['used_triggers'] ?? []),
                static fn(string $t): bool => $t !== 'core.is_lookup_request'
            ));
            if ($allreadonly) {
                $usedtriggers[] = 'core.is_lookup_request';
            }
            $result['used_triggers'] = $triggerregistry->normalize_used_triggers($usedtriggers);
        } else if (in_array((string)($result['response_type'] ?? ''), ['confirmation_request', 'sufficient'], true)) {
            // Mutating or terminal responses must never carry the lookup trigger.
            $result['used_triggers'] = array_values(array_filter(
                (array)($result['used_triggers'] ?? []),
                static fn(string $t): bool => $t !== 'core.is_lookup_request'
            ));
        }

        // Signature-based guard: Block readonly task calls when exact signature
        // already completed in prior steps. Generalized to all readonly tasks.
        $result = $this->apply_signature_based_recall_guard($result, $state, $outputlang);

        // Legacy taskname-based recall guard is intentionally not applied here.
        // Routing stability is enforced via signature-based recall guard above.

        // Infer issue codes when the LLM returned a generic error.
        if (
            (string)($result['response_type'] ?? '') === 'error'
            && empty((array)($result['issue_codes'] ?? []))
        ) {
            $contextid = (int)context_module::instance($cmid)->id;
            $fallback = ai_error_classifier::classify_from_db($userid, $contextid);
            if (!empty($fallback)) {
                $result['issue_codes'] = $fallback;
            }
        }

        // Decide: route through the confirmation / trigger / execution decision tree.
        $result = $this->decisionsvc->process(
            $result,
            $threadid,
            $cmid,
            $userid,
            $outputlang,
            $previewoptionid,
            !empty($observations)
        );
        $result['lang'] = $outputlang;

        // Override message for token/subscription issues.
        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($result['issue_codes'] ?? [])
        );
        if (!empty(array_intersect(self::TOKEN_SUBSCRIPTION_ISSUE_CODES, $issuecodes))) {
            $result['message'] = $this->localized_string(
                'ai_trial_token_invalid_subscription_message',
                'bookingextension_agent',
                (object)[
                    'basicurl'       => self::BASIC_SUBSCRIPTION_URL,
                    'privacyplusurl' => self::PRIVACY_PLUS_SUBSCRIPTION_URL,
                ],
                $outputlang
            );
        }

        return $result;
    }

    /**
     * Prevent redundant readonly calls when exact signature already completed.
     *
     * Generalized signature-based guard: blocks ANY readonly task call when
     * the exact task+input combination was already executed in a prior step.
     * This prevents loop-drift from repeated prompting of the same readonly queries.
     *
     * Runs BEFORE the task-specific observe-based guards, so signature-level
     * redundancy is caught first and doesn't cascade into task-specific logic.
     *
     * @param array $result
     * @param agent_state|null $state  Current loop state (null outside run_loop).
     * @param string $outputlang
     * @return array
     */
    private function apply_signature_based_recall_guard(
        array $result,
        ?agent_state $state,
        string $outputlang
    ): array {
        // Guard only applies when in run_loop() with prior steps.
        if ($state === null || $state->step_count() === 0) {
            return $result;
        }

        if ((string)($result['response_type'] ?? '') !== 'task_call') {
            return $result;
        }

        $commands = (array)($result['commands'] ?? []);
        if (empty($commands)) {
            return $result;
        }

        // Check if all commands are readonly tasks.
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '' && !$this->registry->is_read_only_task($taskname)) {
                // At least one mutating command: skip this guard.
                return $result;
            }
        }

        // Extract current command signatures and observed signatures.
        $currentsigs = $this->extract_step_command_signatures($commands, []);
        $observedsigs = $state->extract_observed_command_signatures();

        if (empty($currentsigs) || empty($observedsigs)) {
            return $result;
        }

        // Check for any signature overlap.
        $overlap = array_intersect($currentsigs, $observedsigs);
        if (empty($overlap)) {
            return $result;
        }

        // Match found: downgrade to clarification.
        $result['response_type'] = 'clarification';
        $result['commands'] = [];
        $result['issue_codes'] = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            ['LOOP_REDUNDANT_SIGNATURE_BLOCKED']
        )));

        $currentmessage = trim((string)($result['message'] ?? ''));
        if ($this->is_low_information_message($currentmessage)) {
            $result['message'] = $this->localized_string(
                'ai_redundant_readonly_blocked',
                'bookingextension_agent',
                null,
                $outputlang
            );
            if ($result['message'] === 'ai_redundant_readonly_blocked') {
                $result['message'] = 'This information was already researched. '
                    . 'Using existing findings to answer your question.';
            }
        }

        return $result;
    }

    /**
     * Prevent redundant diagnose re-calls when observations already contain diagnosis results.
     *
     * @param array $result
     * @param array $observations
     * @param string $outputlang
     * @return array
     */
    private function apply_observation_based_recall_guard(array $result, array $observations, string $outputlang): array {
        if (empty($observations) || (string)($result['response_type'] ?? '') !== 'task_call') {
            return $result;
        }

        $commands = (array)($result['commands'] ?? []);
        $diagnosistasks = $this->get_diagnosis_task_names();
        if (empty($commands) || empty($diagnosistasks) || !$this->all_commands_match_any_task($commands, $diagnosistasks)) {
            return $result;
        }

        if (!$this->observations_include_diagnosis_result($observations)) {
            return $result;
        }

        $result['response_type'] = 'clarification';
        $result['commands'] = [];
        $result['issue_codes'] = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            ['LOOP_REDUNDANT_DIAGNOSE_RECALL_BLOCKED']
        )));

        $currentmessage = trim((string)($result['message'] ?? ''));
        if ($this->is_low_information_message($currentmessage)) {
            $result['message'] = $this->localized_string(
                'ai_diagnose_recall_blocked_use_existing',
                'bookingextension_agent',
                null,
                $outputlang
            );
        }

        return $result;
    }

    /**
     * Check whether every command targets the same task.
     *
     * @param array $commands
     * @param string $taskname
     * @return bool
     */
    private function all_commands_match_task(array $commands, string $taskname): bool {
        if (empty($commands)) {
            return false;
        }

        foreach ($commands as $command) {
            if (!is_array($command)) {
                return false;
            }
            if (trim((string)($command['task'] ?? '')) !== $taskname) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether every command targets one of the provided task names.
     *
     * @param array $commands
     * @param array<int,string> $tasknames
     * @return bool
     */
    private function all_commands_match_any_task(array $commands, array $tasknames): bool {
        if (empty($commands) || empty($tasknames)) {
            return false;
        }

        $allowed = array_fill_keys(array_values(array_unique($tasknames)), true);
        foreach ($commands as $command) {
            if (!is_array($command)) {
                return false;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '' || !isset($allowed[$taskname])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return names of available diagnosis tasks discovered in the registry.
     *
     * @return array<int,string>
     */
    private function get_diagnosis_task_names(): array {
        $matches = [];
        foreach ($this->registry->get_tasks() as $taskname => $task) {
            if (!is_string($taskname) || $taskname === '') {
                continue;
            }

            $schemadescription = '';
            if (is_object($task) && method_exists($task, 'get_schema')) {
                $schema = (array)$task->get_schema();
                $schemadescription = core_text::strtolower(trim((string)($schema['description'] ?? '')));
            }

            $namelower = core_text::strtolower($taskname);
            if (str_contains($namelower, 'diagnose') || str_contains($schemadescription, 'diagnos')) {
                $matches[] = $taskname;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * Detect whether observations already include a diagnosis result summary.
     *
     * @param array $observations
     * @return bool
     */
    private function observations_include_diagnosis_result(array $observations): bool {
        foreach ($observations as $observation) {
            $text = trim((string)$observation);
            if ($text === '') {
                continue;
            }
            if (preg_match('/\bdiagnosis\s+for\s+option\b/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * HARD CONTRACT GATE: Prevent parse/schema errors from reaching decision_service.
     *
     * When the orchestrator/interpreter detects a parse failure or contract violation,
     * this gate attempts a single recovery retry with a structured recovery hint.
     * If retry also fails, returns a safe clarification without commands.
     *
     * @param array $result
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $observations
     * @param string $outputlang
     * @return array
     */
    private function apply_hard_contract_gate(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        string $outputlang
    ): array {
        // Detect hard contract errors: parse failures or unknown response types.
        if (!$this->is_hard_contract_error($result)) {
            return $result;
        }

        // Check retry counter: bounded repair retries only.
        $retrykey = '_contract_gate_retry_' . (int)($result['runid'] ?? 0);
        $retrycount = (int)$this->store->get_thread_metadata_value($threadid, $retrykey);

        if ($retrycount < self::MAX_CONTRACT_GATE_RETRIES) {
            // Inject recovery observation and retry.
            $this->store->set_thread_metadata_value($threadid, $retrykey, $retrycount + 1);

            $msg = "RETRY_HINT: CONTRACT_REPAIR_REQUIRED\n"
                . "OUTPUT_CONTRACT: Follow the NON-OPTIONAL RESPONSE CONTRACT POLICY in the system prompt exactly.\n"
                . "OUTPUT_CONTRACT: Return exactly one valid JSON object only (no markdown or code fences).\n"
                . "FALLBACK_IF_UNSURE: Use response_type=clarification with commands=[] and a short message.";
            $retryobservations = array_merge((array)$observations, [$msg]);
            $retryresult = $this->call_orchestrator_step(
                $threadid,
                $cmid,
                $userid,
                $retryobservations,
                !empty($retryobservations)
                    ? orchestrator::STEP_TYPE_SIMPLE_RETRIEVAL
                    : orchestrator::STEP_TYPE_TOOL_CALL_PARSE
            );

            $retryrawresponse = is_string($retryresult['_planner_raw_response'] ?? null)
                ? (string)$retryresult['_planner_raw_response']
                : '';
            unset($retryresult['_planner_raw_response']);
            if ($retryrawresponse !== '') {
                $this->store->set_thread_metadata_value($threadid, 'planner_trace_history', [$retryrawresponse]);
                $this->store->set_thread_metadata_value($threadid, 'last_planner_result_json', $retryrawresponse);
            }

            $triggerregistry = new message_trigger_registry($this->registry);
            $retryresult = $this->normalize_unknown_response_type_to_contract_error(
                $retryresult,
                $outputlang,
                $triggerregistry
            );

            if (!$this->is_hard_contract_error($retryresult)) {
                // Recovery successful: return retry result.
                return $retryresult;
            }
            // Retry also failed: fall through to safe fallback below.
        }

        // Fallback: return safe clarification with no commands.
        return [
            'response_type'             => 'clarification',
            'message'                   => $this->localized_string(
                'ai_agent_malformed_taskcall_clarification',
                'bookingextension_agent',
                null,
                $outputlang
            ),
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => ['Structural error in LLM response'],
            'attempted_tasks'           => [],
            'issue_codes'               => ['CONTRACT_GATE_FAILED'],
            'pending_confirmation_code' => '',
            'used_triggers'             => [],
            'runid'                     => 0,
            'results'                   => [],
            'lang'                      => $outputlang,
        ];
    }

    /**
     * Convert unknown response types into hard contract errors before routing.
     *
     * @param array $result
     * @param string $outputlang
     * @param message_trigger_registry $triggerregistry
     * @return array
     */
    private function normalize_unknown_response_type_to_contract_error(
        array $result,
        string $outputlang,
        message_trigger_registry $triggerregistry
    ): array {
        $rawresponsetype = trim((string)($result['response_type'] ?? ''));
        if ($rawresponsetype === '') {
            return $result;
        }

        $normalizedresponsetype = $triggerregistry->normalize_response_type($rawresponsetype);
        if ($normalizedresponsetype !== message_trigger_registry::UNKNOWN_RESPONSE_TYPE) {
            $result['response_type'] = $normalizedresponsetype;
            return $result;
        }

        $result['response_type'] = 'error';
        $result['commands'] = [];
        $result['issue_codes'] = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            [message_trigger_registry::UNKNOWN_RESPONSE_TYPE]
        )));
        if (trim((string)($result['message'] ?? '')) === '') {
            $result['message'] = $this->localized_string(
                'ai_agent_malformed_taskcall_clarification',
                'bookingextension_agent',
                null,
                $outputlang
            );
        }

        return $result;
    }

    /**
     * Detect whether result contains a hard contract error that must not reach decision_service.
     *
     * @param array $result
     * @return bool
     */
    private function is_hard_contract_error(array $result): bool {
        if ((string)($result['response_type'] ?? '') !== 'error') {
            return false;
        }

        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($result['issue_codes'] ?? [])
        );

        // Detect hard gates: CONTRACT_* markers or UNKNOWN_TYPE.
        foreach ($issuecodes as $code) {
            if (strpos($code, 'CONTRACT_') === 0 || $code === 'UNKNOWN_TYPE') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a deterministic clarification reply when the user asks what the
     * option type means right after a type-request clarification.
     *
     * @param int $threadid
     * @return array|null
     */
    private function build_option_type_explanation_shortcut(int $threadid): ?array {
        $messages = $this->store->get_recent_messages($threadid, 8);
        if (empty($messages)) {
            return null;
        }

        $lastuserindex = -1;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ((string)($messages[$i]->role ?? '') === 'user') {
                $lastuserindex = $i;
                break;
            }
        }
        if ($lastuserindex < 0) {
            return null;
        }

        $latestusertext = trim((string)($messages[$lastuserindex]->content ?? ''));
        if (!$this->is_meta_clarification_follow_up($latestusertext)) {
            return null;
        }

        $previousassistant = null;
        for ($i = $lastuserindex - 1; $i >= 0; $i--) {
            if ((string)($messages[$i]->role ?? '') === 'assistant') {
                $previousassistant = $messages[$i];
                break;
            }
        }
        if ($previousassistant === null) {
            return null;
        }

        $structured = json_decode((string)($previousassistant->structuredjson ?? ''), true);
        if (!is_array($structured)) {
            $structured = [];
        }

        if (!$this->assistant_prompted_for_option_type($structured)) {
            return null;
        }

        $lang = $this->resolve_output_language($threadid, [
            'lang' => (string)($structured['lang'] ?? ''),
            'user_lang' => (string)($structured['user_lang'] ?? ''),
        ]);

        $message = $this->localized_string('ai_optiontype_help_message', 'bookingextension_agent', null, $lang);
        $nextstepintent = $this->localized_string('ai_optiontype_help_next_step_intent', 'bookingextension_agent', null, $lang);

        return [
            'response_type' => 'clarification',
            'message' => $message,
            'commands' => [],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => array_values(array_unique((array)($structured['attempted_tasks'] ?? []))),
            'issue_codes' => ['OPTION_TYPE_HELP_CLARIFICATION'],
            'pending_confirmation_code' => '',
            'used_triggers' => [],
            'runid' => 0,
            'results' => [],
            'lang' => $lang,
            'user_lang' => $lang,
            'next_step_intent' => $nextstepintent,
        ];
    }

    /**
     * Detect short user follow-ups asking for explanation.
     *
     * @param string $message
     * @return bool
     */
    private function is_meta_clarification_follow_up(string $message): bool {
        $text = trim(core_text::strtolower($message));
        if ($text === '') {
            return false;
        }

        // Language-neutral heuristic: short follow-up question with no command payload.
        $isquestion = str_contains($text, '?');
        $wordcount = count(array_values(array_filter(preg_split('/\s+/u', $text) ?: [])));
        return $isquestion && $wordcount > 0 && $wordcount <= 8;
    }

    /**
     * Determine whether the previous assistant message asked for booking option type.
     *
     * @param array $structured
     * @return bool
     */
    private function assistant_prompted_for_option_type(array $structured): bool {
        $responsetype = (string)($structured['response_type'] ?? '');
        if ($responsetype !== 'clarification' && $responsetype !== 'confirmation_request') {
            return false;
        }

        $issuecodes = array_values(array_unique((array)($structured['issue_codes'] ?? [])));
        if (in_array('OPTION_TYPE_HELP_CLARIFICATION', $issuecodes, true)) {
            return true;
        }

        return false;
    }

    /**
     * Execute a single orchestrator planning step.
     *
     * This centralizes all runtime-side model planning calls so instrumentation and
     * behavior stay consistent across normal loop steps and special narration paths.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $observations
     * @param string $steptype
     * @return array
     */
    private function call_orchestrator_step(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        string $steptype
    ): array {
        return $this->orchestrator->process($threadid, $cmid, $userid, $observations, $steptype);
    }

    /**
     * Resolve output language with server-side priority on user message language.
     *
     * @param int $threadid
     * @param array $result
     * @return string
     */
    private function resolve_output_language(int $threadid, array $result): string {
        return $this->languagepolicy->resolve_output_language($this->store, $threadid, $result);
    }

    /**
     * Build a clarification result asking the user whether to continue after hitting the step limit.
     *
     * Observations are stored in thread metadata (_loop_resume) by the caller before
     * this method is invoked so the next turn can resume seamlessly.
     *
     * @param  string $lang
     * @param  int    $maxsteps
     * @return array
     */
    private function loop_continue_result(string $lang, int $maxsteps): array {
        $message = $this->localized_string(
            'ai_agent_loop_continue_question',
            'bookingextension_agent',
            (object)['steps' => $maxsteps],
            $lang
        );
        if ($message === 'ai_agent_loop_continue_question') {
            $message = 'I have completed ' . $maxsteps . ' research steps but need more to fully'
                . ' answer your question. Shall I continue?';
        }
        return [
            'response_type'            => 'clarification',
            'message'                  => $message,
            'commands'                 => [],
            'ambiguities'              => [],
            'ambiguity_options'        => [],
            'errors'                   => [],
            'attempted_tasks'          => [],
            'issue_codes'              => ['LOOP_STEP_LIMIT'],
            'pending_confirmation_code' => '',
            'used_triggers'            => [],
            'runid'                    => 0,
            'results'                  => [],
            'lang'                     => $lang,
        ];
    }

    /**
     * Fire a final generate_text (STEP_TYPE_FINAL_SYNTHESIS) step once the planner has signalled
     * that all observations are sufficient to answer.
     *
     * generate_text receives the accumulated observations and composes the polished final answer.
     * Falls back to the planning result's message if synthesis is malformed.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param agent_state $state
     * @param array $planningresult The clarification result from the planner (used as fallback).
     * @return array
     */
    private function run_synthesis_step(
        int $threadid,
        int $cmid,
        int $userid,
        agent_state $state,
        array $planningresult
    ): array {
        $observations = $state->get_observations();
        // Derive language for synthesis:
        // 1. SR/planning result explicit lang (current turn).
        // 2. TCP-detected user language (Option A: stored on first call, survives SR steps).
        // Avoid injecting the generic UI-lang fallback — prefer silence over wrong language.
        $plannerlang = $this->normalize_iso_language(
            (string)($planningresult['user_lang'] ?? $planningresult['lang'] ?? '')
        );
        if ($plannerlang === '') {
            $plannerlang = $this->normalize_iso_language(
                (string)$this->store->get_thread_metadata_value($threadid, 'user_input_lang')
            );
        }
        if ($plannerlang !== '' && preg_match('/^[a-z]{2}$/', $plannerlang)) {
            $observations[] = "Language reminder: the user's language is \"{$plannerlang}\". "
                . "Write the entire 'message' field in that language.";
        }
        $synthesis = $this->call_orchestrator_step(
            $threadid,
            $cmid,
            $userid,
            $observations,
            orchestrator::STEP_TYPE_FINAL_SYNTHESIS
        );

        // Coerce error-without-commands to clarification (model stayed well-formed but mistyped).
        $synthesis = $this->normalize_final_reasoning_narration($synthesis);

        if ($this->is_final_clarification_without_commands($synthesis)) {
            $synthesislang = $this->resolve_output_language($threadid, $synthesis);
            $synthesis['lang'] = $synthesislang;
            // Preserve planner clarification intent for search-style follow-ups, but force
            // synthesized sufficiency for explain/diagnose flows.
            $plantype = (string)($planningresult['response_type'] ?? '');
            $plannertasks = (array)($planningresult['attempted_tasks'] ?? []);
            $recordedtasks = $this->extract_recorded_step_task_names($state);
            $alltasks = array_values(array_unique(array_merge($plannertasks, $recordedtasks)));
            $hasexplainordiagnose = $this->has_explain_or_diagnose_task($alltasks);
            $issearchresultclarification = $plantype === 'clarification'
                && !$hasexplainordiagnose;
            $synthesis['response_type'] = $issearchresultclarification ? 'clarification' : 'sufficient';
            $synthesis['loop_step'] = $state->step_count();
            $synthesis['loop_max_steps'] = self::MAX_LOOP_STEPS;
            return $synthesis;
        }

        // Synthesis failed or produced unexpected output — fall back to the planning result.
        $planningresult['loop_step'] = $state->step_count();
        $planningresult['loop_max_steps'] = self::MAX_LOOP_STEPS;
        return $planningresult;
    }

    /**
     * Collect unique task names recorded in loop state steps.
     *
     * @param agent_state $state
     * @return array<int,string>
     */
    private function extract_recorded_step_task_names(agent_state $state): array {
        $tasknames = [];
        foreach ($state->get_steps() as $step) {
            $names = $this->extract_step_task_names(
                (array)($step['tool_calls'] ?? []),
                (array)($step['results'] ?? [])
            );
            foreach ($names as $name) {
                $trimmed = trim((string)$name);
                if ($trimmed !== '') {
                    $tasknames[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($tasknames));
    }

    /**
     * Determine whether any executed/planned task indicates explain/diagnose behavior.
     *
     * @param array<int,string> $tasknames
     * @return bool
     */
    private function has_explain_or_diagnose_task(array $tasknames): bool {
        foreach ($tasknames as $taskname) {
            $normalized = trim(core_text::strtolower((string)$taskname));
            if ($normalized === '') {
                continue;
            }

            if (
                str_contains($normalized, 'explain_')
                || str_contains($normalized, 'diagnose_')
                || str_contains($normalized, '.explain_')
                || str_contains($normalized, '.diagnose_')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert premature planner sufficiency into clarification for read-only loops.
     *
     * For search/resolve loops with observations, planner may emit response_type=sufficient
     * although the expected UX is a follow-up clarification. Keep explain/diagnose flows
     * exempt so they still conclude with sufficient.
     *
     * @param array $result
     * @param agent_state $state
     * @return bool
     */
    private function should_convert_sufficient_to_readonly_clarification(array $result, agent_state $state): bool {
        if ((string)($result['response_type'] ?? '') !== 'sufficient') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        if ($state->step_count() < 1 || !$state->has_observations()) {
            return false;
        }

        if (!$this->loop_state_contains_only_readonly_results($state)) {
            return false;
        }

        $plannertasks = (array)($result['attempted_tasks'] ?? []);
        $recordedtasks = $this->extract_recorded_step_task_names($state);
        $alltasks = array_values(array_unique(array_merge($plannertasks, $recordedtasks)));

        return !$this->has_explain_or_diagnose_task($alltasks);
    }

    /**
     * Determine whether planner output is a strict sufficiency-exit signal.
     *
     * @param array $result
     * @param agent_state $state
     * @return bool
     */
    private function is_sufficiency_exit_signal(array $result, agent_state $state): bool {
        $rt = (string)($result['response_type'] ?? '');

        // New explicit signal: SR/SYN returns response_type=sufficient.
        if ($rt === 'sufficient') {
            return empty((array)($result['commands'] ?? []));
        }

        // Legacy signal: clarification with magic sentinel message.
        if ($rt !== 'clarification') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        if (count($state->get_observations()) < 1) {
            return false;
        }

        return trim((string)($result['message'] ?? '')) === 'observation_sufficient';
    }

    /**
     * Check whether normalized planner result contains a specific trigger id.
     *
     * @param array $result
     * @param string $triggerid
     * @return bool
     */
    private function result_has_trigger(array $result, string $triggerid): bool {
        $needle = trim($triggerid);
        if ($needle === '') {
            return false;
        }

        foreach ((array)($result['used_triggers'] ?? []) as $id) {
            if (trim((string)$id) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve language reminder source for synthesis.
     *
     * Enforce explicit source priority: Planner -> UI language -> Thread metadata.
     * NO runtime heuristics or pattern detection — language authority is LLM only.
     *
     * @param int $threadid
     * @param array $planningresult
     * @return string
     */
    private function resolve_synthesis_user_language(int $threadid, array $planningresult): string {
        // 1. Planner explicit language output.
        $planninglang = $this->normalize_iso_language((string)($planningresult['user_lang'] ?? $planningresult['lang'] ?? ''));
        if ($planninglang !== '') {
            return $planninglang;
        }

        // 2. UI language for the current turn.
        $uilang = $this->normalize_iso_language((string)current_language());
        if ($uilang !== '') {
            return $uilang;
        }

        // 3. Thread metadata (from previous synthesis outputs).
        $threadlang = $this->normalize_iso_language(
            (string)$this->store->get_thread_metadata_value($threadid, 'last_output_lang')
        );
        if ($threadlang !== '') {
            return $threadlang;
        }

        // 4. Final fallback.
        return $this->normalize_iso_language((string)current_language());
    }

    /**
     * Build final loop-repeat response by attempting one narration-only LLM step.
     *
     * The model gets a strict instruction to summarize findings without new tool calls.
     * If it fails to provide a usable clarification, falls back to loop_repeat_result().
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param agent_state $state
     * @param string $lang
     * @param string $latestmessage
     * @return array
     */
    private function loop_repeat_narration_result(
        int $threadid,
        int $cmid,
        int $userid,
        agent_state $state,
        string $lang,
        string $latestmessage = ''
    ): array {
        $observations = $state->get_observations();
        $observations[] = 'System note: Repeated readonly tool step detected. '
            . 'Do NOT call tools again. Return response_type=clarification, commands=[], '
            . 'and summarize the latest findings for the user in plain language.';

        $narration = $this->call_orchestrator_step(
            $threadid,
            $cmid,
            $userid,
            $observations,
            orchestrator::STEP_TYPE_FINAL_SYNTHESIS
        );
        $narration = $this->normalize_final_reasoning_narration($narration);
        if ($this->is_final_clarification_without_commands($narration)) {
            $narrationlang = $this->resolve_output_language($threadid, $narration);
            return [
                'response_type'             => 'clarification',
                'message'                   => trim((string)($narration['message'] ?? '')),
                'commands'                  => [],
                'ambiguities'               => [],
                'ambiguity_options'         => [],
                'errors'                    => [],
                'attempted_tasks'           => [],
                'issue_codes'               => ['LOOP_REPEAT_DETECTED'],
                'pending_confirmation_code' => '',
                'used_triggers'             => (array)($narration['used_triggers'] ?? []),
                'runid'                     => (int)($narration['runid'] ?? 0),
                'results'                   => [],
                'lang'                      => $narrationlang,
                'loop_step'                 => $state->step_count(),
                'loop_max_steps'            => self::MAX_LOOP_STEPS,
            ];
        }

        // Deterministic fallback is mandatory when narration output is malformed
        // or command-bearing. This guarantees loop termination with commands=[].
        return $this->build_deterministic_loop_repeat_fallback($state, $lang, $latestmessage);
    }

    /**
     * Normalize final-reasoning narration payloads into clarification when safe.
     *
     * Some models still emit response_type=error with a usable user-facing summary
     * despite explicit final-reasoning instructions. In that case, keep the content
     * and coerce to clarification as long as no commands are present.
     *
     * @param array $result
     * @return array
     */
    private function normalize_final_reasoning_narration(array $result): array {
        if ((string)($result['response_type'] ?? '') !== 'error') {
            return $result;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return $result;
        }

        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            return $result;
        }

        $result['response_type'] = 'clarification';
        $result['errors'] = [];

        return $result;
    }

    /**
     * Accept only final clarification payloads as narration-polish output.
     *
     * @param array $result
     * @return bool
     */
    private function is_final_clarification_without_commands(array $result): bool {
        $rt = (string)($result['response_type'] ?? '');
        if ($rt !== 'clarification' && $rt !== 'sufficient') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        return trim((string)($result['message'] ?? '')) !== '';
    }

    /**
     * Guard synthesis for actionable clarification states.
     *
     * Clarifications that carry validation/ambiguity/error signals should be
     * shown directly to the user and must not be rewritten into a generic
     * final narration by the synthesis model.
     *
     * @param array $result
     * @return bool
     */
    private function should_run_synthesis_for_clarification(array $result): bool {
        if (!empty((array)($result['errors'] ?? []))) {
            return false;
        }

        if (!empty((array)($result['ambiguities'] ?? []))) {
            return false;
        }

        if (!empty((array)($result['issue_codes'] ?? []))) {
            return false;
        }

        if (trim((string)($result['pending_confirmation_code'] ?? '')) !== '') {
            return false;
        }

        return true;
    }

    /**
     * Build a deterministic clarification fallback from accumulated loop results.
     *
     * @param agent_state $state
     * @param string $lang
     * @param string $latestmessage
     * @return array
     */
    private function build_deterministic_loop_repeat_fallback(
        agent_state $state,
        string $lang,
        string $latestmessage = ''
    ): array {
        $accumulated = [];
        foreach ($state->get_steps() as $step) {
            foreach ((array)($step['results'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $accumulated[] = $entry;
                }
            }
        }

        $message = trim($latestmessage);
        if (!empty($accumulated)) {
            $summary = trim($this->build_loop_repeat_summary($accumulated, ''));
            if ($summary !== '') {
                $message = $summary;
            }
        }

        if ($message === '') {
            $message = $this->localized_string(
                'ai_agent_loop_repeat_message',
                'bookingextension_agent',
                (object)['steps' => $state->step_count()],
                $lang
            );
            if ($message === 'ai_agent_loop_repeat_message') {
                $message = 'I completed repeated lookup steps and returned the latest result.';
            }
        }

        return [
            'response_type'             => 'clarification',
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => [],
            'issue_codes'               => ['LOOP_REPEAT_DETECTED', 'LOOP_REPEAT_NARRATION_FALLBACK'],
            'pending_confirmation_code' => '',
            'used_triggers'             => [],
            'runid'                     => 0,
            'results'                   => [],
            'lang'                      => $lang,
            'loop_step'                 => $state->step_count(),
            'loop_max_steps'            => self::MAX_LOOP_STEPS,
        ];
    }

    /**
     * Build a clarification result for repeated readonly loop steps.
     *
     * @param string $lang
     * @param int $stepcount
     * @param string $latestmessage
     * @return array
     */
    private function loop_repeat_result(string $lang, int $stepcount, string $latestmessage = ''): array {
        $message = trim($latestmessage);
        if ($message === '') {
            $message = $this->localized_string(
                'ai_agent_loop_repeat_message',
                'bookingextension_agent',
                (object)['steps' => $stepcount],
                $lang
            );
            if ($message === 'ai_agent_loop_repeat_message') {
                $message = 'I completed repeated lookup steps and returned the latest result.';
            }
        }
        return [
            'response_type'             => 'clarification',
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => [],
            'issue_codes'               => ['LOOP_REPEAT_DETECTED'],
            'pending_confirmation_code' => '',
            'used_triggers'             => [],
            'runid'                     => 0,
            'results'                   => [],
            'lang'                      => $lang,
            'loop_step'                 => $stepcount,
            'loop_max_steps'            => self::MAX_LOOP_STEPS,
        ];
    }

    // -------------------------------------------------------------------------
    // Private: store / thread helpers.

    /**
     * Resolve the preview option id from thread metadata.
     *
     * @param  int $threadid
     * @param  int $cmid
     * @param int $userid
     * @return int
     */
    private function resolve_preview_option_id(int $threadid, int $cmid, int $userid): int {
        global $DB;

        $optionid = (int)($this->store->get_thread_metadata_value($threadid, 'lastworkedoptionid') ?? 0);
        if ($optionid > 0) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, IGNORE_MISSING);
            if ($cm && $DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                return $optionid;
            }
        }

        // Read-only discovery flows typically persist preview ids (not lastworkedoptionid).
        $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
        foreach ($previewids as $id) {
            $candidate = (int)$id;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Private: localisation helper.

    /**
     * Resolve a localised string in the requested language.
     *
     * @param  string $identifier
     * @param  string $component
     * @param  mixed  $a
     * @param  string $lang
     * @return string
     */
    private function localized_string(string $identifier, string $component, $a = null, string $lang = ''): string {
        $currentlang = current_language();
        $targetlang  = trim($lang);
        $switched    = $targetlang !== '' && $targetlang !== $currentlang;

        if ($switched) {
            force_current_language($targetlang);
        }

        try {
            return get_string($identifier, $component, $a);
        } finally {
            if ($switched) {
                force_current_language($currentlang);
            }
        }
    }
}
