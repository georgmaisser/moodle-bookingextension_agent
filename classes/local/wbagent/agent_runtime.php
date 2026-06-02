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

namespace bookingextension_agent\local\wbagent;

use core\context;
use context_module;
use bookingextension_agent\local\wbagent\config\runtime_feature_flags;
use bookingextension_agent\local\wbagent\services\decision\agent_decision_service;
use bookingextension_agent\local\wbagent\services\attempt_budget_dto;
use bookingextension_agent\local\wbagent\services\finalization_classifier;
use bookingextension_agent\local\wbagent\services\finalization_template_service;
use bookingextension_agent\local\wbagent\services\language_policy_service;
use bookingextension_agent\local\wbagent\services\localized_string_service;
use bookingextension_agent\local\wbagent\services\messaging\message_persistence_service;
use bookingextension_agent\local\wbagent\services\synchronizer_input_builder;
use bookingextension_agent\local\wbagent\services\synchronizer_output_contract;
use bookingextension_agent\local\wbagent\services\synchronizer_routing_service;
use bookingextension_agent\local\wbagent\services\security\authorization_service;

/**
 * Owns only high-level runtime orchestration.
 */
class agent_runtime {
    /** Maximum agent loop steps before bailing out. */
    public const MAX_LOOP_STEPS = 6;

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

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array<string,bool>
     */
    public static function get_runtime_feature_flags_snapshot(): array {
        return runtime_feature_flags::snapshot();
    }

    /** @var task_registry */
    private task_registry $registry;

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
     * @param task_registry $registry
     * @param orchestrator $orchestrator
     * @param conversation_store $store
     * @param authorization_service $authz
     */
    public function __construct(
        task_registry $registry,
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
     * Single-step runtime entrypoint.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function run(int $threadid, int $contextid, int $userid): array {
        $cmid = $this->resolve_cmid_from_contextid($contextid);
        $result = $this->run_internal($threadid, $cmid, $userid, [], null);
        return $this->finalize_and_persist_result($threadid, $result);
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
        $cmid = $this->resolve_cmid_from_contextid($contextid);
        $limit = ($maxsteps > 0) ? $maxsteps : self::MAX_LOOP_STEPS;
        $state = agent_state::make($limit);

        for ($step = 0; $step < $limit; $step++) {
            $result = $this->run_internal($threadid, $cmid, $userid, $state->get_observations(), $state);
            $result['loop_step'] = $step + 1;
            $result['loop_max_steps'] = $limit;
            $result['attempt_budget'] = attempt_budget_dto::from_loop($step + 1, $limit)->to_array();
            $result['attempt_budget']['loop_step'] = $step + 1;
            $result['attempt_budget']['loop_max_steps'] = $limit;

            if ((string)($result['response_type'] ?? '') === 'execution_result') {
                $observation = result_payload_summarizer::for_observation(
                    $result['results'] ?? [],
                    $step + 1
                );
                $state->record_step(
                    (array)($result['commands'] ?? []),
                    (array)($result['results'] ?? []),
                    $observation
                );

                if (!$this->budget_guard_allows_next_llm_call($step, $limit)) {
                    return $this->finalize_and_persist_budget_exceeded($threadid, $result, $state, $limit);
                }
                continue;
            }

            return $this->finalize_and_persist_result($threadid, $result, $state);
        }

        return $this->finalize_and_persist_budget_exceeded($threadid, [], $state, $limit);
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
     * Resolve cmid from a module context id with strict validation.
     *
     * @param int $contextid
     * @return int
     */
    private function resolve_cmid_from_contextid(int $contextid): int {
        try {
            $ctx = context::instance_by_id($contextid, MUST_EXIST);
            if (!($ctx instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
            return (int)$ctx->instanceid;
        } catch (\Throwable $e) {
            return (int)context_module::instance($contextid, MUST_EXIST)->instanceid;
        }
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
        }
        $result = $this->apply_finalization_strategy($threadid, $result, $state);
        $result = $this->enforce_final_response_contract($result, $threadid);
        $this->messagepersistence->persist_assistant_message($threadid, $result);
        return $result;
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
            return $this->apply_template_only_finalization($threadid, $result);
        }

        if ($strategy === finalization_classifier::STRATEGY_LLM_POLISH) {
            return $this->apply_synchronizer_message_polish($threadid, $result, $state);
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

        $cmid = $this->resolve_cmid_from_contextid($contextid);
        $observations = $this->synchronizerinputbuilder->build_observations($result, $state);

        try {
            $syncresult = $this->synchronizerroutingsvc->call_synchronizer_step(
                $this->orchestrator,
                $threadid,
                $cmid,
                $userid,
                $observations
            );
        } catch (\Throwable $e) {
            return $result;
        }

        unset($syncresult['_planner_raw_response']);
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

        if (!isset($result['ambiguities']) || !is_array($result['ambiguities'])) {
            $result['ambiguities'] = [];
        }
        if (!isset($result['ambiguity_options']) || !is_array($result['ambiguity_options'])) {
            $result['ambiguity_options'] = [];
        }
        if (!isset($result['errors']) || !is_array($result['errors'])) {
            $result['errors'] = [];
        }
        if (!isset($result['attempted_tasks']) || !is_array($result['attempted_tasks'])) {
            $result['attempted_tasks'] = [];
        }
        if (!isset($result['issue_codes']) || !is_array($result['issue_codes'])) {
            $result['issue_codes'] = [];
        }
        if (!isset($result['used_triggers']) || !is_array($result['used_triggers'])) {
            $result['used_triggers'] = [];
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

        $result['lang'] = $this->resolve_output_language($threadid, $result);

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
        $lang = $this->resolve_output_language($threadid, ['response_type' => $responsetype]);

        if ($responsetype === 'confirmation_request') {
            $message = localized_string_service::get('ai_confirm_needed', 'bookingextension_agent', null, $lang);
            if ($message !== '' && $message !== 'ai_confirm_needed') {
                return $message;
            }
        }

        if ($responsetype === 'error') {
            $message = localized_string_service::get(
                'ai_agent_malformed_taskcall_clarification',
                'bookingextension_agent',
                null,
                $lang
            );
            if ($message !== '' && $message !== 'ai_agent_malformed_taskcall_clarification') {
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
     * Attach loop bookkeeping to the result payload.
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
     * Execute one internal agent step: plan + decide, with NO persistence.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $observations
     * @param agent_state|null $state
     * @return array
     */
    private function run_internal(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        ?agent_state $state = null
    ): array {
        $triggerregistry = new message_trigger_registry($this->registry);

        $plannersteptype = !empty($observations)
            ? orchestrator::STEP_TYPE_SIMPLE_RETRIEVAL
            : orchestrator::STEP_TYPE_TOOL_CALL_PARSE;

        $result = $this->call_orchestrator_step(
            $threadid,
            $cmid,
            $userid,
            $observations,
            $plannersteptype,
            $state
        );
        $plannercontext = $this->extract_planner_context($result);

        unset($result['_planner_raw_response']);

        $outputlang = $this->resolve_output_language($threadid, $result);
        $result['used_triggers'] = $triggerregistry->normalize_used_triggers($result['used_triggers'] ?? []);

        $rawresponsetype = trim((string)($result['response_type'] ?? ''));
        $result['response_type'] = $triggerregistry->normalize_response_type($rawresponsetype);

        $result = $this->decisionsvc->process(
            $result,
            $threadid,
            $cmid,
            $userid,
            $outputlang,
            0,
            !empty($observations)
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
     * @param int $cmid
     * @param int $userid
     * @param array $observations
     * @param string $steptype
     * @param agent_state|null $state
     * @return array
     */
    private function call_orchestrator_step(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        string $steptype,
        ?agent_state $state = null
    ): array {
        return $this->orchestrator->process($threadid, $cmid, $userid, $observations, $steptype, $state);
    }

    /**
     * Resolve output language.
     *
     * @param int $threadid
     * @param array $result
     * @return string
     */
    private function resolve_output_language(int $threadid, array $result): string {
        return $this->languagepolicy->resolve_output_language($this->store, $threadid, $result);
    }
}
