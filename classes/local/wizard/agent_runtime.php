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
 * Central agent runtime: loop steering and service delegation only.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\attempt_budget_dto;
use bookingextension_agent\local\wizard\services\finalization_classifier;
use bookingextension_agent\local\wizard\services\finalization_template_service;
use bookingextension_agent\local\wizard\services\language_policy_service;
use bookingextension_agent\local\wizard\services\localized_string_service;
use bookingextension_agent\local\wizard\services\messaging\message_persistence_service;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use bookingextension_agent\local\wizard\services\synchronizer_output_contract;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use bookingextension_agent\local\wizard\services\synchronizer_routing_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Owns only high-level runtime orchestration.
 */
class agent_runtime {
    /**
     * Maximum agent loop steps per run_loop frame before bailing out.
     *
     * Sized so a 5-step series (thread 558) or the 3-mutation course-authoring chain
     * (create_course → scaffold_content → create_selflearning_option) still has headroom
     * for one framework retry + the terminal step; the turn-global cap below is what
     * bounds runaway recursion.
     */
    public const MAX_LOOP_STEPS = 8;

    /**
     * Maximum planner loop steps per USER TURN, across all nested frames.
     *
     * MAX_LOOP_STEPS bounds one run_loop frame — but confirm_run_service re-enters run_loop
     * per confirmed step, and each nested frame used to get a fresh budget: thread 554 ran 8
     * selector turns for one user message. This budget is shared by every frame of the same
     * user turn (tracked in thread metadata, reset when a new user message arrives), so a
     * runaway confirm recursion is bounded turn-globally. Sized for a legitimate 5-step
     * series (≈2 steps per confirm frame) with headroom.
     */
    public const TURN_LOOP_BUDGET = 18;

    /** Allowed final response_type values for persisted assistant messages. */
    private const ALLOWED_FINAL_RESPONSE_TYPES = [
        'skill_call',
        'confirmation_request',
        'confirm_pending',
        'clarification',
        'sufficient',
        'error',
        'execution_result',
    ];

    /** Planner contract issue codes eligible for one framework retry hint in the loop. */
    private const LOOP_RETRYABLE_ISSUE_CODES = [
        'CONTRACT_PARSE_ERROR',
        'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED',
        // Command-bearing response_type with empty commands[] (interpreter's single
        // error_result() site). A transient planner flake — observed on the guest-claim
        // auto-continuation turn — that a manual "try again" reliably fixed, so the
        // framework retries it once itself instead of telling the user to retry.
        'CONTRACT_VALIDATION_ERROR',
        // A confirm_pending emitted although no confirmation is awaiting while planned
        // placeholders remain (selector mistook "pending steps" for a pending
        // confirmation, thread 558). One re-plan round recovers the series instead of
        // ending the turn and orphaning the remaining steps.
        'CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS',
        // Construction used input keys the skill schema rejects (interpreter structural
        // validation). Deterministically unhealable by repetition of the SAME output, but
        // healable by one retry WITH the repair text naming the canonical keys — which,
        // measured, never got its chance (W2 baseline 2026-07-12: 0/7 healed, 5/7 without
        // any retry). Genuinely missing user input is excluded at the tagging site
        // (RECOVERABLE_INPUT_ERROR → clarification, no retry).
        'CONTRACT_STRUCTURAL_MISMATCH',
        // Construction emitted a command for a skill outside the discovery-ranked allow-list
        // (thread 591: the selector mis-picked, the constructor 'corrected' to a skill it may
        // not choose — the guard rightly blocks that, but ending the turn terminally wasted
        // the recoverable case). One re-plan round lets SELECTION reconsider with the repair
        // detail naming the attempted skill and the allow-list (N-591a, option C part 2).
        // Consistent with the taxonomy: CONTRACT_ codes classify TECHNICAL/retryable.
        'CONTRACT_PHASE_SKILL_NOT_ALLOWED',
    ];

    /** Maximum number of loop-level framework retries per issue code. */
    private const LOOP_MAX_RETRIES_PER_ISSUE = 1;

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array
     */
    public static function get_runtime_feature_flags_snapshot(): array {
        return runtime_feature_flags::snapshot();
    }

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var orchestrator */
    private orchestrator $orchestrator;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var agent_decision_service */
    private agent_decision_service $decisionsvc;

    /** @var message_persistence_service */
    private message_persistence_service $messagepersistence;

    /** @var language_policy_service */
    private language_policy_service $languagepolicy;

    /** @var finalization_classifier */
    private finalization_classifier $finalizationclassifier;

    /** @var finalization_template_service */
    private finalization_template_service $finalizationtemplatesvc;

    /** @var synchronizer_input_builder */
    private synchronizer_input_builder $synchronizerinputbuilder;

    /** @var synchronizer_routing_service */
    private synchronizer_routing_service $synchronizerroutingsvc;

    /** @var synchronizer_output_contract */
    private synchronizer_output_contract $synchronizeroutputcontract;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param orchestrator $orchestrator
     * @param conversation_store $store
     * @param authorization_service $authz
     */
    public function __construct(
        skill_registry $registry,
        orchestrator $orchestrator,
        conversation_store $store,
        authorization_service $authz
    ) {
        $this->registry = $registry;
        $this->orchestrator = $orchestrator;
        $this->store = $store;
        $this->authz = $authz;
        $this->decisionsvc = new agent_decision_service($registry, $store, $authz);
        $this->messagepersistence = new message_persistence_service($store);
        $this->languagepolicy = new language_policy_service();
        $this->finalizationclassifier = new finalization_classifier();
        $this->finalizationtemplatesvc = new finalization_template_service();
        $this->synchronizerinputbuilder = new synchronizer_input_builder();
        $this->synchronizerroutingsvc = new synchronizer_routing_service();
        $this->synchronizeroutputcontract = new synchronizer_output_contract();
    }

    /**
     * Build a runtime wired with the default planner stack (orchestrator + interpreter).
     *
     * Application services (e.g. confirm_run_service) that need to re-enter the runtime
     * loop after a confirmed execution use this factory instead of constructing the
     * orchestrator/interpreter themselves, so planner wiring stays owned by the runtime.
     *
     * @param skill_registry $registry
     * @param conversation_store $store
     * @param authorization_service $authz
     * @return self
     */
    public static function create_default(
        skill_registry $registry,
        conversation_store $store,
        authorization_service $authz
    ): self {
        return new self(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            $authz
        );
    }

    /**
     * Multi-step runtime loop entrypoint.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param int $maxsteps
     * @return array
     */
    public function run_loop(int $threadid, int $contextid, int $userid, int $maxsteps = 0): array {
        $limit = ($maxsteps > 0) ? $maxsteps : self::MAX_LOOP_STEPS;

        // Turn-global budget: every frame of the same user turn (including confirm-nested
        // re-entries) draws from ONE shared pool, so the per-frame bound cannot be defeated
        // by recursion (thread 554: 8 selector turns despite MAX_LOOP_STEPS = 6).
        $turnremaining = $this->acquire_turn_loop_budget($threadid);
        if ($turnremaining <= 0) {
            $exhausted = [
                'response_type' => 'error',
                'message' => '',
                'commands' => [],
                'issue_codes' => ['BUDGET_EXCEEDED'],
                'loop_step' => 0,
                'loop_max_steps' => 0,
            ];
            return $this->finalize_terminal_result($threadid, $exhausted);
        }
        $limit = max(1, min($limit, $turnremaining));

        $state = agent_state::make($limit);
        $frameworkretrycounts = [];
        $stepsused = 0;

        try {
            return $this->run_loop_frame($threadid, $contextid, $userid, $limit, $state, $frameworkretrycounts, $stepsused);
        } finally {
            $this->consume_turn_loop_budget($threadid, $stepsused);
        }
    }

    /**
     * The actual planner loop of one frame (extracted so the turn-budget bookkeeping in
     * run_loop() can wrap every exit path in one finally).
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param int $limit Steps this frame may use.
     * @param agent_state $state
     * @param array $frameworkretrycounts
     * @param int $stepsused Out: steps actually consumed by this frame.
     * @return array
     */
    private function run_loop_frame(
        int $threadid,
        int $contextid,
        int $userid,
        int $limit,
        agent_state $state,
        array $frameworkretrycounts,
        int &$stepsused
    ): array {
        for ($step = 0; $step < $limit; $step++) {
            $stepsused = $step + 1;
            $result = $this->run_internal($threadid, $contextid, $userid, $state->get_observations(), $state);
            $result['loop_step'] = $step + 1;
            $result['loop_max_steps'] = $limit;
            $result['attempt_budget'] = attempt_budget_dto::from_loop($step + 1, $limit)->to_array();
            $result['attempt_budget']['loop_step'] = $step + 1;
            $result['attempt_budget']['loop_max_steps'] = $limit;
            $this->persist_phase_trace_for_loop_step($threadid, $result);

            if ((string)($result['response_type'] ?? '') === 'execution_result') {
                $stepresults = (array)($result['results'] ?? []);
                $observation = result_payload_summarizer::for_observation(
                    $stepresults,
                    $step + 1
                );
                // Backend data in observations is masked at construction time — the
                // only point where result identity still exists, so engine-static
                // instructional observations (e.g. search_skills catalog text) can
                // be exempted. Everything downstream (state, loop_results, live
                // [OBSERVATION n] blocks, sync input) inherits the masked text.
                $observation = $this->mask_step_observation_for_llm($threadid, $stepresults, $observation);
                $state->record_step(
                    (array)($result['commands'] ?? []),
                    $stepresults,
                    $observation
                );

                if (!$this->budget_guard_allows_next_llm_call($step, $limit)) {
                    return $this->finalize_and_persist_budget_exceeded($threadid, $result, $state, $limit);
                }
                continue;
            }

            $retryissuecode = $this->resolve_framework_retry_issue_code($result, $frameworkretrycounts);
            if ($retryissuecode !== null) {
                // Note: framework retry observations (appended below) are engine text
                // by construction and intentionally stay unmasked.
                if ($this->has_active_non_planner_retry_signal($result)) {
                    $result['issue_codes'] = array_values(array_unique(array_merge(
                        (array)($result['issue_codes'] ?? []),
                        ['PLANNER_RETRY_BLOCKED_LAYER_COLLISION']
                    )));
                    return $this->finalize_and_persist_result($threadid, $result, $state);
                }

                $frameworkretrycounts[$retryissuecode] = (int)($frameworkretrycounts[$retryissuecode] ?? 0) + 1;
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    [
                        'PLANNER_RETRY_DECISION',
                        'RETRY_DECISION_LAYER_PLANNER',
                        'RETRY_CATEGORY_TECHNICAL',
                    ]
                )));
                $retryobservation = $this->build_framework_retry_observation($retryissuecode);
                // F3: skill repair instructions are planner-only vocabulary — they travel on
                // exactly this retry channel (and the phase trace), never to the user.
                $repairhints = array_values(array_filter(array_map('strval', (array)($result['repair_hints'] ?? []))));
                if (empty($repairhints) && $retryissuecode === 'CONTRACT_STRUCTURAL_MISMATCH') {
                    // Legacy (pre-F3-migration) skills carry their repair guidance mixed into the
                    // errors — without it the structural retry is blind to the canonical keys.
                    // This is the planner-only channel, so relaying them here leaks nothing.
                    $repairhints = array_values(array_filter(array_map('strval', (array)($result['errors'] ?? []))));
                }
                if (!empty($repairhints)) {
                    $retryobservation .= "\nREPAIR: " . implode(' | ', $repairhints);
                }
                $state->append_observation($retryobservation);

                if (!$this->budget_guard_allows_next_llm_call($step, $limit)) {
                    return $this->finalize_and_persist_budget_exceeded($threadid, $result, $state, $limit);
                }
                continue;
            }

            $exhaustedissuecode = $this->resolve_exhausted_framework_retry_issue_code($result, $frameworkretrycounts);
            if ($exhaustedissuecode !== null) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    [
                        'LOOP_RETRY_EXHAUSTED',
                        'LOOP_RETRY_EXHAUSTED_' . $exhaustedissuecode,
                    ]
                )));
            }

            return $this->finalize_and_persist_result($threadid, $result, $state);
        }

        return $this->finalize_and_persist_budget_exceeded($threadid, [], $state, $limit);
    }

    /**
     * Remaining turn-global loop budget, resetting when a new user message opened a new turn.
     *
     * @param int $threadid
     * @return int
     */
    private function acquire_turn_loop_budget(int $threadid): int {
        $latestusermsgid = $this->latest_user_message_id($threadid);
        $stateraw = $this->store->get_thread_metadata_value($threadid, '_turn_loop_budget');
        $state = is_array($stateraw) ? $stateraw : [];
        if ((int)($state['msgid'] ?? -1) !== $latestusermsgid) {
            $state = ['msgid' => $latestusermsgid, 'remaining' => self::TURN_LOOP_BUDGET];
            $this->store->set_thread_metadata_value($threadid, '_turn_loop_budget', $state);
        }
        return (int)($state['remaining'] ?? self::TURN_LOOP_BUDGET);
    }

    /**
     * Deduct the steps a frame consumed from the shared turn budget.
     *
     * @param int $threadid
     * @param int $steps
     */
    private function consume_turn_loop_budget(int $threadid, int $steps): void {
        if ($steps <= 0) {
            return;
        }
        $stateraw = $this->store->get_thread_metadata_value($threadid, '_turn_loop_budget');
        if (!is_array($stateraw)) {
            return;
        }
        $stateraw['remaining'] = max(0, (int)($stateraw['remaining'] ?? 0) - $steps);
        $this->store->set_thread_metadata_value($threadid, '_turn_loop_budget', $stateraw);
    }

    /**
     * Id of the thread's latest user message — the marker of the current user turn.
     *
     * @param int $threadid
     * @return int
     */
    private function latest_user_message_id(int $threadid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT MAX(id) FROM {bx_agent_ai_messages} WHERE threadid = :threadid AND role = 'user'",
            ['threadid' => $threadid]
        );
    }

    /**
     * Finalize and persist an externally prepared terminal result.
     *
     * @param int $threadid
     * @param array $result
     * @return array
     */
    public function finalize_terminal_result(int $threadid, array $result): array {
        return $this->finalize_and_persist_result($threadid, $result);
    }

    /**
     * Apply final contract checks, optionally attach loop state, then persist once.
     *
     * @param int $threadid
     * @param array $result
     * @param agent_state|null $state
     * @return array
     */
    private function finalize_and_persist_result(int $threadid, array $result, ?agent_state $state = null): array {
        if ($state !== null) {
            $result = $this->attach_loop_results($result, $state);
            $result = $this->reclassify_abandoned_run_as_error($result);
        }
        $result = $this->apply_finalization_strategy($threadid, $result, $state);
        $result = $this->enforce_final_response_contract($result, $threadid);
        $this->maintain_clarification_origin_task($threadid, $result);
        $this->messagepersistence->persist_assistant_message($threadid, $result);
        return $result;
    }

    /**
     * B: keep a deterministic record of the task that triggered a clarification chain.
     *
     * When a turn ends in a blocking clarification (a real question to the user), remember the request
     * that started the chain so the NEXT discovery turn can fold it into the skill-retrieval query even
     * if the user's answer is a short, low-semantic token ("medium", "yes"). This is advisory only — it
     * never forces a skill (the heuristic fallback C lives in the orchestrator). Lifecycle:
     *  - set on the FIRST blocking clarification of a chain (preserved across follow-up clarifications),
     *  - cleared as soon as a turn resolves to anything that is NOT a blocking clarification.
     *
     * @param int $threadid
     * @param array $result
     * @return void
     */
    private function maintain_clarification_origin_task(int $threadid, array $result): void {
        $key = 'clarification_origin_task';

        if (!$this->is_blocking_clarification($result)) {
            // Resolved, moved on, or abandoned: forget the origin task.
            $this->store->set_thread_metadata_value($threadid, $key, '');
            return;
        }

        // Preserve the task that opened the chain; do not overwrite it with a follow-up clarification.
        $existing = trim((string)$this->store->get_thread_metadata_value($threadid, $key));
        if ($existing !== '') {
            return;
        }

        $task = $this->latest_user_message_text($threadid);
        if ($task !== '') {
            $this->store->set_thread_metadata_value($threadid, $key, $task);
        }
    }

    /**
     * A blocking clarification is a real question to the user (carries its own issue code), as opposed
     * to the informative "found enough context" clarification used by the read/loop path.
     *
     * @param array $result
     * @return bool
     */
    private function is_blocking_clarification(array $result): bool {
        if (trim((string)($result['response_type'] ?? '')) !== 'clarification') {
            return false;
        }
        $codes = array_values(array_filter(array_map('strval', (array)($result['issue_codes'] ?? []))));
        if (empty($codes)) {
            return false;
        }
        return !in_array('LOOP_EARLY_SUFFICIENT_CONTEXT', $codes, true);
    }

    /**
     * Most recent user message text (capped), used as the origin task for a clarification chain.
     *
     * @param int $threadid
     * @return string
     */
    private function latest_user_message_text(int $threadid): string {
        foreach (array_reverse($this->store->get_messages($threadid)) as $message) {
            if ((string)($message->role ?? '') === 'user') {
                return \core_text::substr(trim((string)($message->content ?? '')), 0, 600);
            }
        }
        return '';
    }

    /**
     * Apply deterministic finalization strategy routing.
     *
     * @param int $threadid
     * @param array $result
     * @param agent_state|null $state
     * @return array
     */
    private function apply_finalization_strategy(int $threadid, array $result, ?agent_state $state = null): array {
        $strategy = $this->finalizationclassifier->classify($result);

        if ($strategy === finalization_classifier::STRATEGY_TEMPLATE_ONLY) {
            $result = $this->apply_template_only_finalization($threadid, $result);
        } else if ($strategy === finalization_classifier::STRATEGY_LLM_POLISH) {
            $result = $this->apply_synchronizer_message_polish($threadid, $result, $state);
        }

        // Safety net: error sources now ship with message='' (the cause lives in
        // error_class/issue_codes/errors). Whatever path failed to compose a
        // message resolves the class template here — the user must never see an
        // empty error, and never the retired provider catch-all for non-provider
        // causes.
        if ((string)($result['response_type'] ?? '') === 'error' && trim((string)($result['message'] ?? '')) === '') {
            $templatemessage = $this->finalizationtemplatesvc->resolve_message($result);
            $result['message'] = $templatemessage !== ''
                ? $templatemessage
                : $this->build_contract_fallback_message('error', $threadid);
        }

        return $result;
    }

    /**
     * Apply deterministic template-only finalization behavior.
     *
     * @param int $threadid
     * @param array $result
     * @return array
     */
    private function apply_template_only_finalization(int $threadid, array $result): array {
        $message = trim((string)($result['message'] ?? ''));
        if ($message !== '') {
            return $result;
        }

        $templatemessage = $this->finalizationtemplatesvc->resolve_message($result);
        if ($templatemessage !== '') {
            $result['message'] = $templatemessage;
            return $result;
        }

        $result['message'] = $this->build_contract_fallback_message('error', $threadid);
        return $result;
    }

    /**
     * Run final synthesis step and merge only user-facing message refinements.
     *
     * Preserves response_type and command semantics from source result.
     *
     * @param int $threadid
     * @param array $result
     * @param agent_state|null $state
     * @return array
     */
    private function apply_synchronizer_message_polish(int $threadid, array $result, ?agent_state $state = null): array {
        $thread = $this->store->get_thread($threadid);
        if ($thread === null) {
            return $result;
        }

        $contextid = (int)($thread->contextid ?? 0);
        $userid = (int)($thread->userid ?? 0);
        if ($contextid <= 0 || $userid <= 0) {
            return $result;
        }

        // Deliberate error presentation: the synchronizer is FED the error cause
        // (error observation from the input builder) and asked to present it —
        // the output contract honours this flag instead of auto-rejecting
        // message replacement for error sources.
        //
        // HARD RULE (George, language agnosticism): the synchronizer ALWAYS formulates the
        // final reply — errors included, unmigrated skills included. A Czech user must get
        // a Czech answer even though no Czech engine strings exist, so no engine path may
        // render cause texts directly. F3 cleans WHAT the sync is fed (user_cause channel,
        // label strip, usermessage preference), never WHO formulates.
        if ((string)($result['response_type'] ?? '') === 'error') {
            $result['error_presentation_requested'] = true;
        }

        $observations = $this->synchronizerinputbuilder->build_observations($result, $state);

        // Deterministic continuation truth for the reply contract: the synchronizer runs at
        // turn end, and the engine continues automatically ONLY when this turn ends as a
        // confirmation_request (the queued work runs after the user confirms). Every other
        // terminal state (sufficient / clarification / error) means nothing runs after the
        // reply — the prompt contract must never let the model promise otherwise.
        $continuation = ((string)($result['response_type'] ?? '') === 'confirmation_request')
            ? synchronizer_prompt_builder::CONTINUATION_AWAITING_CONFIRMATION
            : synchronizer_prompt_builder::CONTINUATION_NONE;

        try {
            $syncresult = $this->synchronizerroutingsvc->call_synchronizer_step(
                $this->orchestrator,
                $threadid,
                $contextid,
                $userid,
                $observations,
                $continuation
            );
        } catch (\Throwable $e) {
            // Synchronizer polish is best-effort; return the unpolished result on failure.
            debugging('agent_runtime: synchronizer message polish failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $result;
        }

        unset($syncresult['_planner_raw_response']);

        if ($this->finalizationclassifier->requires_irreversibility_notice($result)) {
            $notice = trim((string)($syncresult['irreversibility_notice'] ?? ''));
            if ($notice === '') {
                return $result;
            }
        }

        if ($this->finalizationclassifier->requires_affected_scope_summary($result)) {
            $summary = trim((string)($syncresult['affected_scope_summary'] ?? ''));
            if ($summary === '') {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['SYNC_AFFECTED_SCOPE_SUMMARY_MISSING']
                )));
            }
        }

        return $this->synchronizeroutputcontract->merge($result, $syncresult);
    }

    /**
     * Build and persist a deterministic budget-exceeded response.
     *
     * @param int $threadid
     * @param array $result
     * @param agent_state $state
     * @param int $limit
     * @return array
     */
    private function finalize_and_persist_budget_exceeded(
        int $threadid,
        array $result,
        agent_state $state,
        int $limit
    ): array {
        $budgetfailed = $this->build_budget_exceeded_result($threadid, $result, $state, $limit);
        return $this->finalize_and_persist_result($threadid, $budgetfailed, $state);
    }

    /**
     * Check if another LLM call is still allowed within the loop budget.
     *
     * @param int $step
     * @param int $limit
     * @return bool
     */
    private function budget_guard_allows_next_llm_call(int $step, int $limit): bool {
        return ($step + 1) < $limit;
    }

    /**
     * Resolve retry-eligible planner contract issue code for loop-level framework retry hints.
     *
     * @param array $result
     * @param array $retrycounts
     * @return string|null
     */
    private function resolve_framework_retry_issue_code(array $result, array $retrycounts): ?string {
        $responsetype = trim((string)($result['response_type'] ?? ''));
        if ($responsetype !== 'error') {
            return null;
        }

        if ($this->has_r3_retry_blocker($result)) {
            return null;
        }

        $issuecodes = array_values(array_unique(array_filter(array_map(
            static fn($issuecode): string => trim((string)$issuecode),
            (array)($result['issue_codes'] ?? [])
        ))));

        foreach (self::LOOP_RETRYABLE_ISSUE_CODES as $issuecode) {
            if (!in_array($issuecode, $issuecodes, true)) {
                continue;
            }

            $attempts = (int)($retrycounts[$issuecode] ?? 0);
            if ($attempts >= self::LOOP_MAX_RETRIES_PER_ISSUE) {
                return null;
            }

            return $issuecode;
        }

        return null;
    }

    /**
     * Resolve retryable planner contract issue code that already exhausted loop retry budget.
     *
     * @param array $result
     * @param array $retrycounts
     * @return string|null
     */
    private function resolve_exhausted_framework_retry_issue_code(array $result, array $retrycounts): ?string {
        $responsetype = trim((string)($result['response_type'] ?? ''));
        if ($responsetype !== 'error') {
            return null;
        }

        if ($this->has_r3_retry_blocker($result)) {
            return null;
        }

        $issuecodes = array_values(array_unique(array_filter(array_map(
            static fn($issuecode): string => trim((string)$issuecode),
            (array)($result['issue_codes'] ?? [])
        ))));

        foreach (self::LOOP_RETRYABLE_ISSUE_CODES as $issuecode) {
            if (!in_array($issuecode, $issuecodes, true)) {
                continue;
            }

            $attempts = (int)($retrycounts[$issuecode] ?? 0);
            if ($attempts >= self::LOOP_MAX_RETRIES_PER_ISSUE) {
                return $issuecode;
            }
        }

        return null;
    }

    /**
     * Determine whether loop-level planner retries must be blocked by R3 guardrails.
     *
     * @param array $result
     * @return bool
     */
    private function has_r3_retry_blocker(array $result): bool {
        $issuecodes = array_values(array_unique(array_filter(array_map(
            static fn($issuecode): string => trim((string)$issuecode),
            (array)($result['issue_codes'] ?? [])
        ))));
        if (in_array('R3_NO_RETRY', $issuecodes, true)) {
            return true;
        }

        $riskclass = trim((string)($result['risk_class'] ?? ''));
        if ($riskclass === skill_risk_class::R3) {
            return true;
        }

        $queueriskclasses = (array)($result['queue_risk_classes'] ?? []);
        foreach ($queueriskclasses as $queueriskclass) {
            if (trim((string)$queueriskclass) === skill_risk_class::R3) {
                return true;
            }
        }

        $commands = (array)($result['commands'] ?? []);
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if (trim((string)($command['risk_class'] ?? '')) === skill_risk_class::R3) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for active non-planner retry paths to avoid cross-layer retry collisions.
     *
     * @param array $result
     * @return bool
     */
    private function has_active_non_planner_retry_signal(array $result): bool {
        $issuecodes = array_values(array_unique(array_filter(array_map(
            static fn($issuecode): string => trim((string)$issuecode),
            (array)($result['issue_codes'] ?? [])
        ))));

        $signals = [
            'RETRY_WAITING',
            'PREFLIGHT_RETRY_HINT',
            'EXECUTION_RETRY_HINT',
            'EXECUTION_EXCEPTION_RETRY_HINT',
            'RETRY_LAYER_LIMIT_EXCEEDED',
            'RETRY_LAYER_COLLISION',
        ];
        foreach ($signals as $signal) {
            if (in_array($signal, $issuecodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask backend data in a step observation before it can reach any prompt.
     *
     * Variant (a) of the privacy decision: with an active privacy mode, live
     * observations are anonymized exactly like the ledger copy — at construction
     * time, where the contributing result entries are still available. A step whose
     * every observation-carrying result declares `observation_engine_static` (e.g.
     * wizard.search_skills catalog instructions) stays unmasked: anonymizing
     * instructional engine text corrupts it (threads 286/288). Code tokens and JSON
     * keys are additionally protected inside the anonymizer itself.
     *
     * @param int $threadid
     * @param mixed[] $results result entries that produced the observation
     * @param string $observation
     * @return string
     */
    private function mask_step_observation_for_llm(int $threadid, array $results, string $observation): string {
        if (trim($observation) === '') {
            return $observation;
        }

        $privacy = new privacy_anonymizer($this->store);
        if (!$privacy->should_anonymize_llm_backend_data()) {
            return $observation;
        }

        $allenginestatic = !empty($results);
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $carriesobservation = trim((string)($entry['observation_full'] ?? '')) !== ''
                || trim((string)($entry['detail'] ?? '')) !== '';
            if ($carriesobservation && empty($entry['observation_engine_static'])) {
                $allenginestatic = false;
                break;
            }
        }
        if ($allenginestatic) {
            return $observation;
        }

        return (string)$privacy->anonymize_value_for_llm($threadid, $observation);
    }

    /**
     * Build framework-authored retry observation for the next planner loop step.
     *
     * @param string $issuecode
     * @return string
     */
    private function build_framework_retry_observation(string $issuecode): string {
        if ($issuecode === 'CONTRACT_PARSE_ERROR') {
            return 'RETRY_HINT: The previous parameter_construction output was not valid JSON. '
                . 'Retry once and return exactly one valid JSON object only. '
                . 'Do not use markdown fences. Escape inner double quotes inside string values.';
        }
        if ($issuecode === 'CONTRACT_STRUCTURAL_MISMATCH') {
            return 'RETRY_HINT: The previous parameter_construction used input keys or value shapes '
                . 'the skill schema does not accept. Retry once using ONLY the canonical keys from '
                . 'the skill schema; map the user\'s values onto them and drop everything else.';
        }

        if ($issuecode === 'CONTRACT_PHASE_SKILL_NOT_ALLOWED') {
            return 'RETRY_HINT: The previous parameter_construction emitted a command for a skill '
                . 'that was NOT the selected skill. Construction may never switch skills. Re-plan '
                . 'this step once: if the selected skill cannot fulfil it, SELECT the correct skill '
                . '(see the REPAIR detail below for what was attempted); if no available skill fits, '
                . 'respond with a clarification instead.';
        }

        if ($issuecode === 'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED') {
            return 'RETRY_HINT: Selection must emit exactly one direct command object in commands[]. '
                . 'Do not wrap skill inside helper keys like current/next/action. '
                . 'Use canonical selector shape only, for example commands=[{"skill":"<skill>","input":{}}].';
        }

        if ($issuecode === 'CONTRACT_VALIDATION_ERROR') {
            return 'RETRY_HINT: The previous planner output used a command-bearing response_type but '
                . 'commands[] was empty. Emit the intended command, for example '
                . 'commands=[{"skill":"<skill>","input":{...}}] — or, if no new command is needed, use '
                . 'response_type=clarification, confirm_pending or sufficient instead.';
        }

        if ($issuecode === 'CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS') {
            return 'RETRY_HINT: There is NO pending confirmation to execute — response_type=confirm_pending '
                . 'is only valid while a confirmation is awaiting. Planned steps remain in the queue: '
                . 'select the real skill for the next planned step with response_type=skill_call.';
        }

        return 'RETRY_HINT: Previous planner output violated the contract. Retry once with strict JSON contract compliance.';
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
        $lang = $this->resolve_output_language($result);
        $message = localized_string_service::get('ai_agent_loop_continue_question', 'bookingextension_agent', (object)[
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
            'attempted_skills' => (array)($result['attempted_skills'] ?? []),
            'issue_codes' => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['BUDGET_EXCEEDED']
            ))),
            'pending_confirmation_code' => '',
            'runid' => (int)($result['runid'] ?? 0),
            'results' => [],
            'lang' => $lang,
            'loop_step' => $state->step_count(),
            'loop_max_steps' => $limit,
            'attempt_budget' => array_merge(
                attempt_budget_dto::from_loop($state->step_count(), $limit, 'BUDGET_EXCEEDED')->to_array(),
                [
                    'loop_step' => $state->step_count(),
                    'loop_max_steps' => $limit,
                ]
            ),
        ];
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
        if (is_array($commands) && isset($commands['skill']) && !array_is_list($commands)) {
            $commands = [$commands];
        }
        if (!is_array($commands)) {
            $commands = [];
        }

        if (in_array($responsetype, ['skill_call', 'confirmation_request'], true) && empty($commands)) {
            $result['response_type'] = 'clarification';
            $result['commands'] = [];
            $result['issue_codes'] = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['CONTRACT_COMMANDS_REQUIRED']
            )));
            $responsetype = 'clarification';
        } else if (in_array($responsetype, ['clarification', 'confirm_pending', 'sufficient', 'error'], true)) {
            $result['commands'] = [];
        } else {
            $result['commands'] = array_values($commands);
        }

        $message = $this->strip_markdown_fences_from_message(trim((string)($result['message'] ?? '')));
        if ($message === '') {
            $message = $this->build_contract_fallback_message($responsetype, $threadid);
        }
        $result['message'] = $message;

        if (!isset($result['ambiguities']) || !is_array($result['ambiguities'])) {
            $result['ambiguities'] = [];
        }
        if (!isset($result['ambiguity_options']) || !is_array($result['ambiguity_options'])) {
            $result['ambiguity_options'] = [];
        }
        if (!isset($result['errors']) || !is_array($result['errors'])) {
            $result['errors'] = [];
        }
        if (!isset($result['attempted_skills']) || !is_array($result['attempted_skills'])) {
            $result['attempted_skills'] = [];
        }
        if (!isset($result['issue_codes']) || !is_array($result['issue_codes'])) {
            $result['issue_codes'] = [];
        }
        if (!isset($result['results']) || !is_array($result['results'])) {
            $result['results'] = [];
        }
        if (!isset($result['pending_confirmation_code']) || !is_string($result['pending_confirmation_code'])) {
            $result['pending_confirmation_code'] = '';
        }
        if (!isset($result['runid'])) {
            $result['runid'] = 0;
        }

        $result['lang'] = $this->resolve_output_language($result);

        return $result;
    }

    /**
     * Strip markdown fences from model messages.
     *
     * @param string $message
     * @return string
     */
    private function strip_markdown_fences_from_message(string $message): string {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return '';
        }

        $fence = chr(96) . chr(96) . chr(96);
        $lines = preg_split('/\R/', $trimmed);
        if (is_array($lines) && count($lines) >= 2) {
            $firstline = trim((string)$lines[0]);
            $lastline = trim((string)$lines[count($lines) - 1]);
            if (str_starts_with($firstline, $fence) && $lastline === $fence) {
                array_shift($lines);
                array_pop($lines);
                return trim(implode("\n", $lines));
            }
        }

        return $trimmed;
    }

    /**
     * Build deterministic fallback message when planner message is empty.
     *
     * @param string $responsetype
     * @param int $threadid
     * @return string
     */
    private function build_contract_fallback_message(string $responsetype, int $threadid): string {
        $lang = $this->resolve_output_language(['response_type' => $responsetype]);

        if ($responsetype === 'confirmation_request') {
            $message = localized_string_service::get('ai_confirm_needed', 'bookingextension_agent', null, $lang);
            if ($message !== '' && $message !== 'ai_confirm_needed') {
                return $message;
            }
        }

        if ($responsetype === 'error') {
            $message = localized_string_service::get(
                'ai_agent_malformed_skillcall_clarification',
                'bookingextension_agent',
                null,
                $lang
            );
            if ($message !== '' && $message !== 'ai_agent_malformed_skillcall_clarification') {
                return $message;
            }
            return 'I could not complete this step reliably. Please try again in one short sentence.';
        }

        $message = localized_string_service::get('ai_please_clarify', 'bookingextension_agent', null, $lang);
        if ($message !== '' && $message !== 'ai_please_clarify') {
            return $message;
        }

        return 'Please provide one short clarification so I can continue.';
    }

    /**
     * Honest-failure guard: a run that gives up as 'sufficient' even though EVERY executed step failed
     * (and none succeeded) is a failure masquerading as success. Left as 'sufficient', the synchronizer
     * is free to compose any message and may confabulate a cause (observed: a fabricated "Gateway
     * Time-out" while the real error was an unresolved course). Reclassify it to 'error' so the existing
     * faithful-error contract (error_presentation_requested / synchronizer [ERROR] input) presents the
     * real last error instead.
     *
     * Scope is deliberately tight: only when the run executed at least one command, at least one result
     * row is a hard error, and NO result row succeeded. A run that answered from context (no executed
     * step) or had any success stays 'sufficient'.
     *
     * @param array $result result already carrying loop_results (attach_loop_results ran first)
     * @return array
     */
    private function reclassify_abandoned_run_as_error(array $result): array {
        if (trim((string)($result['response_type'] ?? '')) !== 'sufficient') {
            return $result;
        }

        $rows = [];
        foreach ((array)($result['loop_results'] ?? []) as $step) {
            foreach ((array)($step['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }
        if (empty($rows)) {
            // Nothing was executed — a genuine "answered from context" sufficient.
            return $result;
        }

        $haserror = false;
        $hassuccess = false;
        $lasterror = '';
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($status === 'error') {
                $haserror = true;
                $msg = trim((string)($row['usermessage'] ?? ($row['detail'] ?? '')));
                if ($msg !== '') {
                    $lasterror = $msg;
                }
            } else if ($status !== '') {
                $hassuccess = true;
            }
        }

        // Only a run where every executed step failed is a disguised failure.
        if (!$haserror || $hassuccess) {
            return $result;
        }

        $result['response_type'] = 'error';
        if ($lasterror !== '') {
            $result['message'] = $lasterror;
            $result['errors'] = array_values(array_unique(array_merge(
                (array)($result['errors'] ?? []),
                [$lasterror]
            )));
        }
        $result['issue_codes'] = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            ['RUN_ABANDONED_ALL_STEPS_FAILED']
        )));

        return $result;
    }

    /**
     * Attach loop step results + counters to a terminal result.
     *
     * @param array $result
     * @param agent_state $state
     * @return array
     */
    private function attach_loop_results(array $result, agent_state $state): array {
        $result['loop_results'] = $state->get_steps();
        if (!isset($result['loop_step'])) {
            $result['loop_step'] = $state->step_count();
        }
        if (!isset($result['loop_max_steps'])) {
            $result['loop_max_steps'] = self::MAX_LOOP_STEPS;
        }
        return $result;
    }

    /**
     * Persist phase trace snapshots per loop step for runtime telemetry.
     *
     * @param int $threadid
     * @param array $result
     * @return void
     */
    private function persist_phase_trace_for_loop_step(int $threadid, array $result): void {
        $phasetrace = $result['phase_trace'] ?? null;
        if (!is_array($phasetrace) || empty($phasetrace)) {
            return;
        }

        $loopstep = (int)($result['loop_step'] ?? 0);
        if ($loopstep <= 0) {
            return;
        }

        $history = $this->store->get_thread_metadata_value($threadid, 'phase_trace_loop_history');
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'loop_step' => $loopstep,
            'response_type' => trim((string)($result['response_type'] ?? '')),
            'issue_codes' => array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($result['issue_codes'] ?? [])
            )))),
            'phase_trace' => $phasetrace,
        ];

        if (count($history) > self::MAX_LOOP_STEPS) {
            $history = array_slice($history, -self::MAX_LOOP_STEPS);
        }

        $this->store->set_thread_metadata_value($threadid, 'phase_trace_loop_history', $history);
    }

    /**
     * Execute one internal agent step: plan + decide, with NO persistence.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param agent_state|null $state
     * @return array
     */
    private function run_internal(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        ?agent_state $state = null
    ): array {
        $triggerregistry = new message_trigger_registry($this->registry);

        $result = $this->call_orchestrator_step(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $state
        );
        $plannercontext = $this->extract_planner_context($result);

        unset($result['_planner_raw_response']);

        $outputlang = $this->resolve_output_language($result);

        $rawresponsetype = trim((string)($result['response_type'] ?? ''));
        $result['response_type'] = $triggerregistry->normalize_response_type($rawresponsetype);

        $result = $this->decisionsvc->process(
            $result,
            $threadid,
            $contextid,
            $userid,
            $outputlang
        );
        $result = $this->merge_planner_context($result, $plannercontext);
        $result['lang'] = $outputlang;

        return $result;
    }

    /**
     * Extract planner artifacts that must survive decision/finalization routing.
     *
     * @param array $result
     * @return array
     */
    private function extract_planner_context(array $result): array {
        $context = [];

        if (isset($result['phase_trace']) && is_array($result['phase_trace'])) {
            $context['phase_trace'] = $result['phase_trace'];
        }

        if (isset($result['planner_result']) && is_array($result['planner_result'])) {
            $context['planner_result'] = $result['planner_result'];
            if (
                !isset($context['phase_trace'])
                && isset($result['planner_result']['phase_trace'])
                && is_array($result['planner_result']['phase_trace'])
            ) {
                $context['phase_trace'] = $result['planner_result']['phase_trace'];
            }
        }

        return $context;
    }

    /**
     * Re-attach planner artifacts after decision routing.
     *
     * Decision service intentionally focuses on routing and may return compact
     * payloads that do not carry planner metadata. Runtime persistence requires
     * these artifacts to remain available for store/synchronizer consumers.
     *
     * @param array $result
     * @param array $plannercontext
     * @return array
     */
    private function merge_planner_context(array $result, array $plannercontext): array {
        if (!isset($result['phase_trace']) && isset($plannercontext['phase_trace']) && is_array($plannercontext['phase_trace'])) {
            $result['phase_trace'] = $plannercontext['phase_trace'];
        }

        if (
            !isset($result['planner_result'])
            && isset($plannercontext['planner_result'])
            && is_array($plannercontext['planner_result'])
        ) {
            $result['planner_result'] = $plannercontext['planner_result'];
        }

        return $result;
    }

    /**
     * Execute a single orchestrator planning step.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param agent_state|null $state
     * @return array
     */
    private function call_orchestrator_step(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        ?agent_state $state = null
    ): array {
        return $this->orchestrator->process(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $state
        );
    }

    /**
     * Resolve output language.
     *
     * @param array $result
     * @return string
     */
    private function resolve_output_language(array $result): string {
        return $this->languagepolicy->resolve_output_language($result);
    }
}
