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
 * Agent decision/routing layer.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use core_text;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\queue\observation_builder;
use bookingextension_agent\local\wbagent\services\preflight_audit_logger;
use bookingextension_agent\local\wbagent\services\preflight_domain_check_runner;
use bookingextension_agent\local\wbagent\services\preflight_execution_gate;
use bookingextension_agent\local\wbagent\services\preflight_schema_validator;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Routing and decision layer for the agent runtime.
 *
 * Owns ALL routing logic previously embedded in AgentRuntime::decide():
 *  - Preview shortcuts
 *  - Confirmation flow (confirm_pending state machine)
 *  - Duplicate-title overrides
 *  - Lookup-safety mutation guard
 *  - Mutating command promotion from task_call → confirmation_request
 *  - Read-only command auto-execution
 *  - Pre-validation of confirmation commands (with deanonymization)
 *  - Teacher autocreate augmentation
 *  - Pending intent storage and clearing
 *
 * AgentRuntime delegates entirely to this class so it remains a thin
 * coordinator that owns only the loop, state, and persistence.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_decision_service {
    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_TASK_CALL = 'task_call';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_CONFIRMATION_REQUEST = 'confirmation_request';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_CONFIRM_PENDING = 'confirm_pending';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_CLARIFICATION = 'clarification';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_ERROR = 'error';

    /** Response type constant for unknown/invalid responses from normalization. */
    private const RESPONSE_TYPE_UNKNOWN = 'UNKNOWN_TYPE';

    /** Trigger id: user explicitly discards current pending confirmation intent. */
    private const TRIGGER_DISCARD_PENDING_CONFIRMATION = 'core.discard_pending_confirmation';

    /** @deprecated Use issue_code_provider::get_duplicate_confirmation_issue_codes() instead. */
    public const DUPLICATE_TITLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
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

    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var issue_code_provider_interface */
    private issue_code_provider_interface $issuecodeprovider;

    /** @var recovery_enrichment_service */
    private recovery_enrichment_service $recoverysvc;

    /** @var queue_manager */
    private queue_manager $queuesvc;

    /** @var observation_builder */
    private observation_builder $observationbuilder;

    /** @var preflight_schema_validator */
    private preflight_schema_validator $preflightschemavalidator;

    /** @var preflight_domain_check_runner */
    private preflight_domain_check_runner $preflightdomainrunner;

    /** @var preflight_execution_gate */
    private preflight_execution_gate $preflightexecutiongate;

    /** @var preflight_audit_logger */
    private preflight_audit_logger $preflightauditlogger;

    /**
     * Constructor.
     *
     * @param task_registry                   $registry
     * @param conversation_store              $store
     * @param authorization_service          $authz
     * @param issue_code_provider_interface   $issuecodeprovider
     */
    public function __construct(
        task_registry $registry,
        conversation_store $store,
        authorization_service $authz,
        issue_code_provider_interface $issuecodeprovider = null
    ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->authz    = $authz;
        $this->issuecodeprovider = $issuecodeprovider ?? new booking_issue_code_provider();
        $this->recoverysvc = new recovery_enrichment_service($registry);
        $this->queuesvc = new queue_manager($store);
        $this->observationbuilder = new observation_builder();
        $this->preflightschemavalidator = new preflight_schema_validator();
        $this->preflightdomainrunner = new preflight_domain_check_runner();
        $this->preflightexecutiongate = new preflight_execution_gate();
        $this->preflightauditlogger = new preflight_audit_logger($store);
    }

    // -------------------------------------------------------------------------
    // Public interface.

    /**
     * Route the raw orchestrator result through the full decision tree.
     *
     * This is the single authoritative routing method.  AgentRuntime calls it
     * once per internal loop step after the LLM has responded.
     *
     * @param  array  $result          Interpreter result from orchestrator::process().
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @param  int    $previewoptionid Resolved preview option id (0 = none).
     * @return array  Normalized result ready for persistence or loop continuation.
     */
    public function process(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang,
        int $previewoptionid,
        bool $hasobservationscontext = false
    ): array {
        $contextid = (int)\context_module::instance($cmid)->id;
        if ((bool)get_config('bookingextension_agent', 'queue_blocked_ttl_enabled')) {
            $expiredblocked = $this->queuesvc->fail_expired_blocked_items($threadid);
            if ($expiredblocked > 0) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['BLOCKED_CONFIRMATION_TIMEOUT']
                )));
            }
        }
        $evaluator = new task_executability_evaluator($this->registry, $this->authz);
        $commandfallback = $this->normalize_commands_for_contract_recovery($result['commands'] ?? []);

        // 1. Preview shortcut: if the user asked for a preview and one is available.
        if ($previewoptionid > 0 && $this->result_has_trigger($result, 'core.is_preview_request')) {
            return [
                'response_type'             => 'clarification',
                'message'                   => $this->localized_string(
                    'ai_preview_latest_option',
                    'bookingextension_agent',
                    null,
                    $outputlang
                ),
                'used_triggers'             => $result['used_triggers'] ?? [],
                'commands'                  => [],
                'ambiguities'               => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'ambiguity_options'         => [],
                'errors'                    => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks'           => [],
                'issue_codes'               => array_values(array_unique((array)($result['issue_codes'] ?? []))),
                'pending_confirmation_code' => '',
            ];
        }

        // 1b. Step-8 guard: when a confirmation intent is pending, block unrelated
        // new intents until the user either confirms or explicitly discards.
        $pendingintent = $this->store->get_pending_intent($threadid);
        if ($pendingintent !== null) {
            if ($this->result_has_trigger($result, self::TRIGGER_DISCARD_PENDING_CONFIRMATION)) {
                $this->store->clear_pending_intent($threadid);
                $result['used_triggers'] = array_values(array_filter(
                    (array)($result['used_triggers'] ?? []),
                    static fn(string $trigger): bool => $trigger !== self::TRIGGER_DISCARD_PENDING_CONFIRMATION
                ));
            } else if ($this->should_block_new_intent_while_pending($result)) {
                return $this->build_pending_resolution_clarification($result, $pendingintent, $outputlang);
            }
        }

        // 2. Normalise task_call with confirmation trigger → confirm_pending.
        if (
            (string)($result['response_type'] ?? '') !== self::RESPONSE_TYPE_CONFIRM_PENDING
            && $this->result_has_trigger($result, 'core.is_confirmation_message')
        ) {
            $result['response_type'] = self::RESPONSE_TYPE_CONFIRM_PENDING;
        }

        // 3. Handle explicit user confirmation of pending intent.
        if ((string)($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRM_PENDING) {
            return $this->handle_confirm_pending($result, $threadid, $cmid, $userid, $outputlang);
        }

        // 4. Duplicate-title override: if the user explicitly asked to create anyway.
        if (
            $this->result_has_trigger($result, 'core.force_new_duplicate_option')
            && $this->has_recent_duplicate_title_prompt($threadid)
        ) {
            $result = $this->apply_duplicate_title_override($result);
        }

        // 5. Safety: block accidental mutation carry-over on lookup requests.
        if (
            $this->result_has_trigger($result, 'core.is_lookup_request')
            && (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST)
            && $this->has_mutating_commands($result)
        ) {
            return [
                'response_type'   => self::RESPONSE_TYPE_CLARIFICATION,
                'message'         => $this->localized_string(
                    'ai_lookup_detected_blocked_mutation',
                    'bookingextension_agent',
                    null,
                    $outputlang
                ),
                'commands'        => [],
                'ambiguities'     => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'errors'          => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks' => $result['attempted_tasks'] ?? [],
                'issue_codes'     => array_values(array_unique((array)($result['issue_codes'] ?? []))),
            ];
        }

        // 5b. Generic readonly recovery:
        // If the model returned a dead-end clarification or readonly-style error,
        // attempt a task-agnostic
        // schema-based readonly recovery BEFORE command routing so it can
        // execute in-process and does not leak as task_call to the frontend contract.
        $usermessage = $this->get_last_user_message($threadid);
        $lookupintent = (
            $this->result_has_trigger($result, 'core.is_lookup_request')
            || $this->looks_like_lookup_request($usermessage, $result)
        );
        $iscontractincompleteintentonly = $this->is_contract_incomplete_intent_only(
            $result,
            $lookupintent,
            $hasobservationscontext
        );

        $recentlinkresponse = $this->resolve_recent_link_lookup_response(
            $threadid,
            $usermessage,
            $lookupintent,
            $outputlang,
            $result
        );
        if (is_array($recentlinkresponse)) {
            return $recentlinkresponse;
        }

        if ($iscontractincompleteintentonly) {
            $result['issue_codes'] = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['CONTRACT_INCOMPLETE_INTENT_ONLY']
            )));
        }

        if ($this->should_attempt_recovery($result, $hasobservationscontext)) {
            $hadcommandsbefore = !empty((array)($result['commands'] ?? []));
            $hadresultsbefore = !empty((array)($result['results'] ?? []));
            $result = $this->recoverysvc->promote(
                $result,
                $usermessage,
                $outputlang,
                $threadid,
                $cmid,
                $userid,
                $evaluator,
                $contextid,
                fn(
                    string $taskname,
                    string $message,
                    string $lang,
                    int $thread,
                    int $module,
                    int $user
                ): ?array => $this->build_recovery_input_for_task($taskname, $message, $lang, $thread, $module, $user),
                fn(string $message, array $recoveryresult): bool => $this->looks_like_lookup_request($message, $recoveryresult),
                fn(string $message): bool => $this->looks_like_diagnostic_intent($message),
                fn(string $taskname, bool $islookup): int => $this->score_generic_recovery_task($taskname, $islookup),
                fn(int $thread): string => $this->extract_option_context_query_from_thread($thread)
            );

            if (
                !$hadcommandsbefore
                && !$hadresultsbefore
                && (string)($result['response_type'] ?? '') === self::RESPONSE_TYPE_TASK_CALL
                && !empty((array)($result['commands'] ?? []))
            ) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['READONLY_RECOVERY_FORCED']
                )));
            }
        }

        // 6. Harden: if the LLM incorrectly used task_call for a mutating command, promote.
        if ($this->has_mutating_commands($result) && ($result['response_type'] ?? '') === self::RESPONSE_TYPE_TASK_CALL) {
            $result['response_type'] = self::RESPONSE_TYPE_CONFIRMATION_REQUEST;
            $normalizedmsg = core_text::strtolower(trim((string)($result['message'] ?? '')));
            if (in_array($normalizedmsg, ['executing', 'executing.', 'running', 'running.'], true)) {
                $result['message'] = '';
            }
        }

        // 7. Execute read-only commands immediately; confirmation-gate mutating ones.
        if (
            in_array(
                (string)($result['response_type'] ?? ''),
                [
                    self::RESPONSE_TYPE_TASK_CALL,
                    self::RESPONSE_TYPE_CONFIRMATION_REQUEST,
                ],
                true
            )
        ) {
            $result = $this->handle_command_routing($result, $threadid, $cmid, $userid, $outputlang);
            $candidatefallback = $this->normalize_commands_for_contract_recovery($result['commands'] ?? []);
            if (!empty($candidatefallback)) {
                $commandfallback = $candidatefallback;
            }
        }

        // 8. Run preflight on confirmation commands: resolve entities, detect conflicts,
        // update commands to carry prepared_input, route based on preflight result.
        if (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST && !empty($result['commands'])) {
            $result = $this->handle_preflight($result, $threadid, $cmid, $userid, $outputlang);
            $candidatefallback = $this->normalize_commands_for_contract_recovery($result['commands'] ?? []);
            if (!empty($candidatefallback)) {
                $commandfallback = $candidatefallback;
            }
        }

        // 9. Augment teacher autocreate when user allows it.
        $result = $this->augment_missing_teacher_autocreate_confirmation($result, $usermessage, $outputlang);
        $candidatefallback = $this->normalize_commands_for_contract_recovery($result['commands'] ?? []);
        if (!empty($candidatefallback)) {
            $commandfallback = $candidatefallback;
        }

        // 9c. Final boundary guard: a readonly-only task_call must never leave
        // this service as task_call; execute it here and return execution_result.
        $result = $this->enforce_task_boundary_invariants($result, $threadid, $cmid, $userid, $outputlang);
        $candidatefallback = $this->normalize_commands_for_contract_recovery($result['commands'] ?? []);
        if (!empty($candidatefallback)) {
            $commandfallback = $candidatefallback;
        }

        // 9d. Contract hardening: normalize impossible response_type/commands combinations.
        $result = $this->enforce_response_contract_invariants($result, $commandfallback);

        // 10. Ensure message is never empty before storing pending intent.
        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            $result['message'] = $this->build_fallback_message($result, $outputlang);
        }

        // 11. Store / clear pending intent.
        if (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST && !empty($result['commands'])) {
            $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($result['commands']));
            $this->store->set_pending_intent($threadid, $result['commands'], $intentkey, $userid, $cmid);
            $pendingintent = $this->store->get_pending_intent($threadid);
            $result['pending_confirmation_code'] = (string)($pendingintent['confirmationcode'] ?? '');
        } else {
            $this->store->clear_pending_intent($threadid);
            $result['pending_confirmation_code'] = '';
        }

        return $result;
    }

    /**
     * Decide whether current model output should be blocked while a pending
     * confirmation intent exists.
     *
     * @param array $result
     * @return bool
     */
    private function should_block_new_intent_while_pending(array $result): bool {
        if ($this->result_has_trigger($result, 'core.is_confirmation_message')) {
            return false;
        }

        $responsetype = (string)($result['response_type'] ?? '');
        if ($responsetype === self::RESPONSE_TYPE_CONFIRM_PENDING) {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return true;
        }

        return in_array(
            $responsetype,
            [self::RESPONSE_TYPE_TASK_CALL, self::RESPONSE_TYPE_CONFIRMATION_REQUEST, 'sufficient'],
            true
        );
    }

    /**
     * Build the clarification response instructing the user to resolve the
     * current pending confirmation before starting a new intent.
     *
     * @param array $result
     * @param array $pendingintent
     * @param string $outputlang
     * @return array
     */
    private function build_pending_resolution_clarification(array $result, array $pendingintent, string $outputlang): array {
        $pendingcommands = is_array($pendingintent['commands'] ?? null) ? (array)$pendingintent['commands'] : [];
        $summary = $this->build_pending_intent_summary($pendingcommands, $outputlang);
        $confirmationcode = trim((string)($pendingintent['confirmationcode'] ?? ''));
        $message = $this->localized_string(
            'ai_pending_intent_resolution_required',
            'bookingextension_agent',
            (object)[
                'action' => $summary !== ''
                    ? $summary
                    : $this->localized_string('ai_status_confirm_default', 'bookingextension_agent', null, $outputlang),
                'code' => $confirmationcode !== '' ? $confirmationcode : '-',
            ],
            $outputlang
        );

        return [
            'response_type'             => self::RESPONSE_TYPE_CLARIFICATION,
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => array_values(array_unique((array)($result['ambiguities'] ?? []))),
            'ambiguity_options'         => [],
            'errors'                    => array_values(array_unique((array)($result['errors'] ?? []))),
            'attempted_tasks'           => array_values(array_unique((array)($result['attempted_tasks'] ?? []))),
            'issue_codes'               => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['PENDING_CONFIRMATION_EXISTS']
            ))),
            'pending_confirmation_code' => $confirmationcode,
            'used_triggers'             => (array)($result['used_triggers'] ?? []),
            'runid'                     => 0,
            'results'                   => [],
        ];
    }

    /**
     * Create a concise human-readable summary of the currently pending commands.
     *
     * @param array $pendingcommands
     * @param string $outputlang
     * @return string
     */
    private function build_pending_intent_summary(array $pendingcommands, string $outputlang): string {
        if (empty($pendingcommands)) {
            return '';
        }

        return trim($this->build_fallback_message([
            'response_type' => self::RESPONSE_TYPE_CONFIRMATION_REQUEST,
            'commands' => $pendingcommands,
            'message' => '',
        ], $outputlang));
    }

    /**
     * Enforce framework-level task routing invariants at process() exit.
     *
     * @param array $result
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $outputlang
     * @return array
     */
    private function enforce_task_boundary_invariants(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        if ((string)($result['response_type'] ?? '') !== self::RESPONSE_TYPE_TASK_CALL) {
            return $result;
        }

        $commands = (array)($result['commands'] ?? []);
        if (empty($commands) || $this->has_mutating_commands(['commands' => $commands])) {
            return $result;
        }

        return $this->handle_command_routing($result, $threadid, $cmid, $userid, $outputlang);
    }

    /**
     * Enforce generic response-contract invariants independent of task semantics.
     *
     * @param array $result
     * @return array
     */
    private function enforce_response_contract_invariants(array $result, array $fallbackcommands = []): array {
        $responsetype = (string)($result['response_type'] ?? '');
        $commands = $this->normalize_commands_for_contract_recovery($result['commands'] ?? []);
        $issuecodes = (array)($result['issue_codes'] ?? []);

        $requirescommands = in_array(
            $responsetype,
            [self::RESPONSE_TYPE_TASK_CALL, self::RESPONSE_TYPE_CONFIRMATION_REQUEST],
            true
        );
        if ($requirescommands && empty($commands)) {
            if (!empty($fallbackcommands)) {
                $result['commands'] = array_values($fallbackcommands);
                $result['issue_codes'] = array_values(array_unique(array_merge($issuecodes, ['CONTRACT_COMMANDS_RECOVERED'])));
                return $result;
            }

            $result['response_type'] = self::RESPONSE_TYPE_CLARIFICATION;
            $result['commands'] = [];
            $result['issue_codes'] = array_values(array_unique(array_merge($issuecodes, ['CONTRACT_COMMANDS_REQUIRED'])));
            return $result;
        }

        $forbidscommands = in_array(
            $responsetype,
            [self::RESPONSE_TYPE_CLARIFICATION, self::RESPONSE_TYPE_CONFIRM_PENDING, self::RESPONSE_TYPE_ERROR],
            true
        );
        if ($forbidscommands && !empty($commands)) {
            $result['commands'] = [];
            $result['issue_codes'] = array_values(array_unique(array_merge($issuecodes, ['CONTRACT_COMMANDS_FORBIDDEN'])));
        }

        return $result;
    }

    /**
     * Normalize potentially mixed command payloads into a list of command arrays.
     *
     * @param mixed $commands
     * @return array<int,array>
     */
    private function normalize_commands_for_contract_recovery($commands): array {
        if ($commands instanceof \stdClass) {
            $commands = (array)$commands;
        }

        if (!is_array($commands)) {
            return [];
        }

        if (isset($commands['task']) && is_string($commands['task'])) {
            return [$commands];
        }

        $normalized = [];
        foreach ($commands as $command) {
            if ($command instanceof \stdClass) {
                $command = (array)$command;
            }
            if (is_array($command) && !empty($command)) {
                $normalized[] = $command;
            }
        }

        return array_values($normalized);
    }

    /**
     * Named routing condition for entering recovery enrichment.
     *
     * @param array $result
     * @return bool
     */
    private function should_attempt_recovery(array $result, bool $hasobservationscontext = false): bool {
        $responsetype = (string)($result['response_type'] ?? '');
        if (
            !in_array(
                $responsetype,
                [self::RESPONSE_TYPE_ERROR, self::RESPONSE_TYPE_CLARIFICATION, self::RESPONSE_TYPE_UNKNOWN],
                true
            )
        ) {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        if (!empty((array)($result['results'] ?? []))) {
            return false;
        }

        // Once observations already exist in the loop context, defer to the synthesis
        // stage rather than forcing another recovery lookup step.
        if ($hasobservationscontext) {
            return false;
        }

        // Do not attempt recovery when the orchestrator already produced a substantive
        // clarification message. A real answer is present — let the synthesis step refine
        // it rather than firing an unnecessary second lookup task.
        if ($responsetype === self::RESPONSE_TYPE_CLARIFICATION) {
            if ($this->is_substantive_clarification_message((string)($result['message'] ?? ''), $result)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a deterministic fallback message per response type and language.
     *
     * Made public so that AgentRuntime can call it if needed after process().
     * Each booking task declares its own fallback string keys via get_schema():
     *   - 'fallback_confirm_string_key'  for confirmation_request responses
     *   - 'fallback_taskcall_string_key' for task_call responses
     *
     * Tasks that are not registered in the booking registry (e.g. cross-plugin
     * tasks) receive the generic default fallback string.
     *
     * @param  array  $result
     * @param  string $outputlang
     * @return string
     */
    public function build_fallback_message(array $result, string $outputlang = ''): string {
        $responsetype = (string)($result['response_type'] ?? '');
        $commands = $result['commands'] ?? [];
        $firsttask = '';
        if (is_array($commands) && !empty($commands) && is_array($commands[0] ?? null)) {
            $firsttask = (string)($commands[0]['task'] ?? '');
        }

        if ($responsetype === 'confirmation_request') {
            if ($firsttask !== '') {
                $task = $this->registry->get_task($firsttask);
                if ($task !== null) {
                    $key = (string)($task->get_schema()['fallback_confirm_string_key'] ?? '');
                    if ($key !== '') {
                        return $this->localized_string($key, 'bookingextension_agent', null, $outputlang);
                    }
                }
            }
            return $this->localized_string('ai_status_confirm_default', 'bookingextension_agent', null, $outputlang);
        }

        if ($responsetype === 'task_call') {
            if ($firsttask !== '') {
                $task = $this->registry->get_task($firsttask);
                if ($task !== null) {
                    $key = (string)($task->get_schema()['fallback_taskcall_string_key'] ?? '');
                    if ($key !== '') {
                        return $this->localized_string($key, 'bookingextension_agent', null, $outputlang);
                    }
                }
            }
            // Any task not registered in the booking registry (e.g. cross-plugin tasks)
            // falls back to the generic default string.
            return $this->localized_string('ai_status_taskcall_default', 'bookingextension_agent', null, $outputlang);
        }

        return trim((string)($result['message'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Private: confirmation flow.

    /**
     * Handle a confirm_pending response: run preflight on stored commands and propagate the intent.
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_confirm_pending(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $modelmessage = trim((string)($result['message'] ?? ''));
        $normalizedmessage = core_text::strtolower($modelmessage);
        $isplaceholdermessage = in_array($normalizedmessage, ['executing', 'executing.', 'running', 'running.'], true);
        $pendingintent = $this->store->get_pending_intent($threadid);

        if ($pendingintent === null) {
            if ($modelmessage !== '' && !$isplaceholdermessage) {
                $fallback = $this->clarification_result($modelmessage);
                $fallback['used_triggers'] = (array)($result['used_triggers'] ?? []);
                if (!empty($result['next_step_intent'])) {
                    $fallback['next_step_intent'] = trim((string)$result['next_step_intent']);
                }
                return $fallback;
            }
            return $this->clarification_result(
                $this->localized_string('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang)
            );
        }

        $confirmcommands = is_array($pendingintent['commands'] ?? null) ? (array)$pendingintent['commands'] : [];
        if (empty($confirmcommands)) {
            if ($modelmessage !== '' && !$isplaceholdermessage) {
                $fallback = $this->clarification_result($modelmessage);
                $fallback['used_triggers'] = (array)($result['used_triggers'] ?? []);
                if (!empty($result['next_step_intent'])) {
                    $fallback['next_step_intent'] = trim((string)$result['next_step_intent']);
                }
                return $fallback;
            }
            return $this->clarification_result(
                $this->localized_string('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang)
            );
        }

        // Re-run preflight so that prepared_input is refreshed for the executor.
        $preflightresult = $this->run_preflight_pipeline_on_commands($confirmcommands, $threadid, $cmid, $userid);
        if (!$preflightresult['valid']) {
            $invalidmessage = implode(' ', array_values(array_unique(array_filter((array)($preflightresult['errors'] ?? [])))));
            return [
                'response_type'             => 'clarification',
                'message'                   => $invalidmessage !== '' ? $invalidmessage
                    : $this->localized_string('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang),
                'commands'                  => [],
                'ambiguities'               => [],
                'ambiguity_options'         => [],
                'errors'                    => $preflightresult['errors'] ?? [],
                'attempted_tasks'           => $preflightresult['attempted_tasks'] ?? [],
                'issue_codes'               => $preflightresult['issue_codes'] ?? [],
                'pending_confirmation_code' => '',
                'used_triggers'             => $result['used_triggers'] ?? [],
                'runid'                     => 0,
                'results'                   => [],
            ];
        }

        // Use the prepared commands (with resolved inputs) for the pending intent.
        $preparedcommands = $preflightresult['prepared_commands'];

        $confirmmessage = $this->localized_string('ai_confirm_pending_intent', 'bookingextension_agent', null, $outputlang);
        $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($preparedcommands));
        $this->store->set_pending_intent($threadid, $preparedcommands, $intentkey, $userid, $cmid);
        $updatedpending = $this->store->get_pending_intent($threadid);
        $confirmationcode = (string)($updatedpending['confirmationcode'] ?? '');

        return [
            'response_type'             => 'confirmation_request',
            'message'                   => $confirmmessage,
            'commands'                  => $preparedcommands,
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => [],
            'issue_codes'               => [],
            'pending_confirmation_code' => $confirmationcode,
            'used_triggers'             => $result['used_triggers'] ?? [],
            'runid'                     => 0,
            'results'                   => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Private: command routing.

    /**
     * Route commands: execute read-only immediately, confirmation-gate mutating ones.
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_command_routing(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $commands = $this->inject_output_language_into_commands((array)($result['commands'] ?? []), $outputlang);
        $nextstepintent = trim((string)($result['next_step_intent'] ?? ''));
        $commands = $this->enrich_option_anchor_inputs($commands);
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }

        // Generic safety guard: do not execute readonly task calls that require an
        // option anchor (optionquery/optionid schema) when none was provided.
        $missingoptionanchortask = $this->find_missing_option_anchor_readonly_task($commands);
        if ($missingoptionanchortask !== '') {
            return [
                'response_type'   => 'clarification',
                'message'         => $this->localized_string(
                    'agent_booking_diagnose_ambiguity_option_title_or_id',
                    'bookingextension_agent',
                    null,
                    $outputlang
                ),
                'commands'        => [],
                'ambiguities'     => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'errors'          => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks' => [$missingoptionanchortask],
                'issue_codes'     => array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['MISSING_OPTION_REFERENCE_RECOVERY']
                ))),
            ];
        }

        $split = $this->split_commands_by_mutability($commands);
        $readonlycommands = $split['readonly'];
        $mutatingcommands = $split['mutating'];
        $readonlyqueueids = [];
        $mutatingqueueids = [];
        $readonlyexecution = null;

        // Shadow-queue ingestion: record all commands before execution/routing.
        // This preserves current behavior while making queue state observable.
        $runid = 0;
        if (is_int($result['runid'] ?? null)) {
            $runid = (int)$result['runid'];
        }
        $stepid = (int)($result['loop_step'] ?? 0);

        foreach ($readonlycommands as $readonlycommand) {
            $queued = $this->queuesvc->enqueue_command(
                $threadid,
                $runid,
                $stepid,
                (array)$readonlycommand,
                'readonly',
                'ready'
            );
            $readonlyqueueids[] = (string)($queued['queue_item_id'] ?? '');
        }

        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $cmid, $threadid);
        foreach ($mutatingcommands as $idx => $mutatingcommand) {
            $status = $autoconfirmmode ? 'ready' : ($idx === 0 ? 'blocked_confirmation' : 'queued');
            $dependson = array_values(array_map('strval', (array)($mutatingcommand['depends_on'] ?? [])));
            if ($idx > 0 && !empty($mutatingqueueids[$idx - 1])) {
                $dependson[] = (string)$mutatingqueueids[$idx - 1];
            }
            $dependson = array_values(array_unique(array_filter($dependson)));
            if ((bool)get_config('bookingextension_agent', 'queue_dag_validation_enabled')) {
                $existingitems = $this->queuesvc->get_queue_items($threadid);
                if (!$this->queuesvc->validate_depends_on_is_dag($existingitems, $dependson)) {
                    $result['issue_codes'] = array_values(array_unique(array_merge(
                        (array)($result['issue_codes'] ?? []),
                        ['DEPENDENCY_CYCLE']
                    )));
                    $status = 'failed';
                }
            }
            $queued = $this->queuesvc->enqueue_command(
                $threadid,
                $runid,
                $stepid,
                (array)$mutatingcommand,
                'mutating',
                $status,
                $dependson
            );
            $mutatingqueueids[] = (string)($queued['queue_item_id'] ?? '');
        }

        if (!empty($readonlycommands)) {
            $readonlycommands = $this->enrich_readonly_commands_with_planner(
                $readonlycommands,
                $threadid,
                $cmid,
                $userid
            );
        }

        if (!empty($readonlycommands)) {
            $readonlyexecution = $this->execute_readonly_commands(
                $readonlycommands,
                $readonlyqueueids,
                $threadid,
                $cmid,
                $userid,
                $outputlang,
                $nextstepintent
            );
        }

        if (!empty($mutatingcommands)) {
            // Write operations remain confirmation-gated.
            $result['response_type'] = 'confirmation_request';
            $result['commands'] = $this->slice_first_mutation_confirmation_stage($mutatingcommands);
            if (count($mutatingcommands) > 1) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['MULTISTEP_MUTATION_STAGED']
                )));
            }

            $confirmmessage = trim((string)($result['message'] ?? ''));
            if ($confirmmessage === '') {
                $confirmmessage = $this->build_fallback_message($result, $outputlang);
            }

            if (is_array($readonlyexecution)) {
                if ($this->execution_result_has_failures($readonlyexecution)) {
                    return [
                        'response_type'  => 'clarification',
                        'message'        => trim((string)($readonlyexecution['message'] ?? '')),
                        'commands'       => [],
                        'ambiguities'    => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                        'errors'         => array_values(array_unique(array_merge(
                            (array)($result['errors'] ?? []),
                            (array)($readonlyexecution['errors'] ?? [])
                        ))),
                        'runid'          => (int)($readonlyexecution['runid'] ?? 0),
                        'results'        => is_array($readonlyexecution['results'] ?? null)
                            ? $readonlyexecution['results']
                            : [],
                        'issue_codes'    => array_values(array_unique((array)($result['issue_codes'] ?? []))),
                    ];
                } else {
                    $readonlymessage = trim((string)($readonlyexecution['message'] ?? ''));
                    $result['message'] = $readonlymessage !== ''
                        ? $readonlymessage . "\n\n" . $confirmmessage
                        : $confirmmessage;
                    $result['runid'] = (int)($readonlyexecution['runid'] ?? 0);
                    $result['results'] = is_array($readonlyexecution['results'] ?? null)
                        ? $readonlyexecution['results']
                        : [];
                }
            } else {
                $result['message'] = $confirmmessage;
            }

            // Mark non-first staged mutating items as skipped if the first fails later.
            // Current runtime stages only one mutation command per confirmation step.
            if (count($mutatingqueueids) > 1) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['QUEUE_MUTATION_STAGED']
                )));
            }
        } else if (is_array($readonlyexecution)) {
            $result = $readonlyexecution;
        }

        return $result;
    }

    /**
     * Keep only the first mutating command for the current confirmation stage.
     *
     * Remaining steps are expected to be produced by a follow-up planner turn
     * after the first command has been executed.
     *
     * @param array $mutatingcommands
     * @return array
     */
    private function slice_first_mutation_confirmation_stage(array $mutatingcommands): array {
        $mutatingcommands = array_values(array_filter($mutatingcommands, static fn($entry): bool => is_array($entry)));
        if (empty($mutatingcommands)) {
            return [];
        }

        return [$mutatingcommands[0]];
    }

    /**
     * Find the first readonly task command that requires option anchoring but has none.
     *
     * A task is considered option-anchored when its schema declares optionquery or optionid.
     *
     * @param array $commands
     * @return string Task name, or empty string when all commands are sufficiently anchored.
     */
    private function find_missing_option_anchor_readonly_task(array $commands): string {
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '' || !$this->registry->is_read_only_task($taskname)) {
                continue;
            }

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                continue;
            }

            $schema = $task->get_schema();
            $properties = (array)($schema['properties'] ?? []);
            $requiresoptionanchor = isset($properties['optionquery']) || isset($properties['optionid']);
            if (!$requiresoptionanchor) {
                continue;
            }

            $input = (array)($command['input'] ?? []);
            $hasoptionid = (int)($input['optionid'] ?? 0) > 0;
            $hasoptionquery = trim((string)($input['optionquery'] ?? '')) !== '';
            if (!$hasoptionid && !$hasoptionquery) {
                return $taskname;
            }
        }

        return '';
    }

    /**
     * Enrich readonly command inputs using planner_service when the task schema allows it.
     *
     * This keeps execution generic and capability-driven: planner_service itself decides
     * whether enrichment is applicable based on schema capabilities/properties.
     *
     * @param array $commands
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    private function enrich_readonly_commands_with_planner(
        array $commands,
        int $threadid,
        int $cmid,
        int $userid
    ): array {
        $usermessage = trim($this->get_last_user_message($threadid));
        if ($usermessage === '' || $userid <= 0) {
            return $commands;
        }

        $planner = new planner_service($this->store);
        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '' || !$this->registry->is_read_only_task($taskname)) {
                continue;
            }

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $command['input'] = $planner->enrich_recovery_input(
                $taskname,
                $task->get_schema(),
                $usermessage,
                $input,
                $threadid,
                $cmid,
                $userid
            );
        }
        unset($command);

        return $commands;
    }

    /**
     * Enrich command inputs with derived option anchors when possible.
     *
     * This is task-agnostic and schema-driven: if a task exposes optionid/optionquery,
     * we derive missing anchors from free-form input fields.
     *
     * @param array $commands
     * @return array
     */
    private function enrich_option_anchor_inputs(array $commands): array {
        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                continue;
            }

            $schema = $task->get_schema();
            $properties = (array)($schema['properties'] ?? []);
            if (empty($properties)) {
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            if (isset($properties['optionquery']) && is_array($properties['optionquery'])) {
                $optionquery = trim((string)($input['optionquery'] ?? ''));
                if ($optionquery !== '') {
                        $trimchars = " \t\n\r\0\x0B\"'“”„" . chr(96) . ".,;:!?()[]{}";
                        $input['optionquery'] = trim($optionquery, $trimchars);
                }
            }

            if (isset($properties['optionid']) && is_array($properties['optionid'])) {
                $optionid = (int)($input['optionid'] ?? 0);
                if ($optionid <= 0) {
                    $candidates = [
                        trim((string)($input['question'] ?? '')),
                        trim((string)($input['query'] ?? '')),
                        trim((string)($input['optionquery'] ?? '')),
                    ];
                    foreach ($candidates as $candidate) {
                        if ($candidate === '') {
                            continue;
                        }
                        $derivedid = $this->extract_option_id_from_message($candidate);
                        if ($derivedid > 0) {
                            $input['optionid'] = $derivedid;
                            break;
                        }
                    }
                }
            }

            $command['input'] = $input;
        }
        unset($command);

        return $commands;
    }

    /**
     * Run preflight validation on confirmation commands.
     *
     * Calls task->preflight() for each command, which:
     *  - resolves entity IDs (options, users, etc.)
     *  - detects conflicts (duplicate titles, missing fields, etc.)
     *  - normalises input
     *  - does NOT perform writes
     *
     * On success: updates each command's 'input' to prepared_input so the
     * executor never has to re-resolve anything.
     *
     * On failure: routes to confirmation_request (if confirmable soft issues) or
     * clarification (if hard blocking issues).
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_preflight(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $commands = (array)($result['commands'] ?? []);
        $lastusermessage = trim($this->get_last_user_message($threadid));
        $anonymizer = new privacy_anonymizer($this->store);
        $planner = new planner_service($this->store);
        $updatedcommands = [];
        $allissuecodes = [];
        $allissues = [];
        $blockingerrors = [];
        $attemptedtasks = [];

        foreach ($commands as $idx => $command) {
            if (!is_array($command)) {
                $blockingerrors[] = get_string('agent_decision_command_malformed', 'bookingextension_agent', $idx + 1);
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                $blockingerrors[] = get_string('agent_decision_command_missing_task', 'bookingextension_agent', $idx + 1);
                continue;
            }
            $attemptedtasks[] = $taskname;

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                $blockingerrors[] = get_string('agent_decision_command_task_not_registered', 'bookingextension_agent', (object)[
                    'idx' => $idx + 1,
                    'task' => $taskname,
                ]);
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            // Deanonymize before preflight so task sees real values.
            if ($threadid > 0 && $userid > 0) {
                $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            }

            // Enrich planner-capable tasks (especially docs explain) before preflight.
            if ($lastusermessage !== '') {
                $input = $planner->enrich_recovery_input(
                    $taskname,
                    $task->get_schema(),
                    $lastusermessage,
                    $input,
                    $threadid,
                    $cmid,
                    $userid
                );
            }

            $preflightresult = $this->with_output_language(
                $outputlang,
                fn() => $task->preflight($input, $cmid, $userid)
            );

            // Collect issue codes.
            foreach ($preflightresult->get_issue_codes() as $code) {
                if ($code !== '') {
                    $allissuecodes[] = $code;
                }
            }
            $allissues = array_merge($allissues, $preflightresult->issues);

            if (!$preflightresult->isvalid) {
                // Collect blocking issues.
                foreach ($preflightresult->get_issues_by_severity('needs_clarification') as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $blockingerrors[] = $msg;
                    }
                }
                // Confirmable issues from an invalid preflight result are still blocking
                // at this point — they were not confirmed yet.
                foreach ($preflightresult->get_issues_by_severity('needs_confirmation') as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $blockingerrors[] = $msg;
                    }
                }
                continue;
            }

            // Preflight succeeded: store prepared_input so executor never re-resolves.
            $updatedcommand = $command;
            $updatedcommand['input'] = $preflightresult->preparedinput;
            $updatedcommands[] = $updatedcommand;
        }

        $allissuecodes = array_values(array_unique($allissuecodes));
        $attemptedtasks = array_values(array_unique($attemptedtasks));

        // If there were blocking errors, decide whether to allow confirmable continuation.
        if (!empty($blockingerrors)) {
            $validationmessage = trim(implode(' ', $blockingerrors));
            $hasclarificationissues = false;
            foreach ($allissues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                if (trim((string)($issue['severity'] ?? '')) === 'needs_clarification') {
                    $hasclarificationissues = true;
                    break;
                }
            }

            if (
                $this->has_confirmable_prevalidation_issues($allissuecodes)
                && !$hasclarificationissues
                && !empty($result['commands'])
            ) {
                // Soft-confirmable: show confirmation_request with augmented message.
                return [
                    'response_type'   => 'confirmation_request',
                    'message'         => $validationmessage !== '' ? $validationmessage : $result['message'],
                    'commands'        => (array)$result['commands'],
                    'ambiguities'     => [],
                    'errors'          => $blockingerrors,
                    'attempted_tasks' => $attemptedtasks,
                    'issue_codes'     => $allissuecodes,
                    'used_triggers'   => $result['used_triggers'] ?? [],
                ];
            }

            return [
                'response_type'   => 'clarification',
                'message'         => $validationmessage !== '' ? $validationmessage : $this->localized_string(
                    'ai_no_pending_intent',
                    'bookingextension_agent',
                    null,
                    $outputlang
                ),
                'commands'        => [],
                'ambiguities'     => [],
                'errors'          => $blockingerrors,
                'attempted_tasks' => $attemptedtasks,
                'issue_codes'     => $allissuecodes,
                'used_triggers'   => $result['used_triggers'] ?? [],
            ];
        }

        // All commands passed preflight.  Swap raw commands for prepared-input versions.
        $result['commands']      = $updatedcommands;
        $result['issue_codes']   = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            $allissuecodes
        )));
        $result['attempted_tasks'] = $attemptedtasks;

        // If preflight returned confirmable issues (but is_valid=true), surface them.
        $confirmableissues = array_filter(
            $allissues,
            static fn(array $i): bool => ($i['severity'] ?? '') === 'needs_confirmation'
        );
        if (!empty($confirmableissues)) {
            $confirmationmessage = trim((string)($result['message'] ?? ''));
            if ($confirmationmessage === '') {
                $parts = [];
                foreach ($confirmableissues as $issue) {
                    $q = trim((string)($issue['user_question'] ?? $issue['message'] ?? ''));
                    if ($q !== '') {
                        $parts[] = $q;
                    }
                }
                $confirmationmessage = implode(' ', $parts);
            }
            $result['message'] = $confirmationmessage;

            // Augment commands with issue-specific override tokens.
            $result['commands'] = $this->apply_confirmable_overrides($result['commands'], $confirmableissues);
        }

        return $result;
    }

    /**
     * Apply override tokens to commands based on confirmable issue codes.
     *
     * When a confirmable issue is known to require an override token in the
     * command input (e.g. MISSING_LOCATION_CONFIRM_REQUIRED → override=location),
     * this method mutates the commands array so that execute() sees the right
     * override flags.
     *
     * @param  array $commands
     * @param  array $confirmableissues
     * @return array
     */
    private function apply_confirmable_overrides(array $commands, array $confirmableissues): array {
        $codeset = [];
        foreach ($confirmableissues as $issue) {
            $code = trim((string)($issue['code'] ?? ''));
            if ($code !== '') {
                $codeset[$code] = true;
            }
        }

        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }
            if (!is_array($command['input'] ?? null)) {
                $command['input'] = [];
            }
            if (isset($codeset['MISSING_LOCATION_CONFIRM_REQUIRED'])) {
                $overrides = is_array($command['input']['override'] ?? null)
                    ? $command['input']['override']
                    : [];
                $overrides[] = 'location';
                $overrides[] = 'address';
                $command['input']['override'] = array_values(array_unique(array_map(
                    static fn($t): string => strtolower(trim((string)$t)),
                    $overrides
                )));
            }
            if (isset($codeset['SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED'])) {
                $command['input']['confirmed'] = true;
            }
        }
        unset($command);

        return $commands;
    }

    // -------------------------------------------------------------------------
    // Private: read-only command execution.

    /**
     * Execute read-only commands directly and return an execution result payload.
     *
     * @param  array  $commands
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function execute_readonly_commands(
        array $commands,
        array $queueitemids,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang,
        string $nextstepintent = ''
    ): array {
        // Read-only auto-execution must use deanonymized inputs, otherwise person names
        // replaced during privacy precheck can degrade exact option/user lookups.
        $preparedcommands = $this->inject_output_language_into_commands($commands, $outputlang);
        if ($threadid > 0 && $userid > 0) {
            $anonymizer = new privacy_anonymizer($this->store);
            foreach ($preparedcommands as &$command) {
                if (!is_array($command)) {
                    continue;
                }
                $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
                $command['input'] = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            }
            unset($command);
        }

        $idempotencykey = hash(
            'sha256',
            $userid . ':' . $cmid . ':' . $threadid . ':' . json_encode($preparedcommands) . ':' . microtime(true)
        );
        $runid = $this->store->create_run($threadid, $userid, $cmid, $idempotencykey, $preparedcommands);

        try {
            $this->store->update_run_status($runid, 'running');
            $feedback = $this->with_output_language($outputlang, function () use (
                $preparedcommands,
                $queueitemids,
                $cmid,
                $userid,
                $idempotencykey,
                $runid,
                $threadid,
                $outputlang
            ): array {
                $exec = new executor($this->registry, $this->store, $this->authz);
                $rawresults = $exec->execute_commands($preparedcommands, $cmid, $userid, $idempotencykey, $runid);
                $feedbackservice = new execution_feedback_service($this->store, $this->registry);
                $feedback = $feedbackservice->build_completion_feedback(
                    $threadid,
                    $cmid,
                    $userid,
                    $preparedcommands,
                    $rawresults,
                    $outputlang
                );

                // Queue status projection: running -> succeeded/failed per readonly item.
                foreach ($queueitemids as $idx => $queueitemid) {
                    $queueitemid = (string)$queueitemid;
                    if ($queueitemid === '') {
                        continue;
                    }

                    if ($this->queuesvc->has_running_item($threadid, $queueitemid)) {
                        // Preserve invariant: max one running item per thread_id.
                        $this->queuesvc->update_status($threadid, $queueitemid, 'ready');
                        continue;
                    }

                    $this->queuesvc->update_status($threadid, $queueitemid, 'running');
                    $entry = is_array($rawresults[$idx] ?? null) ? (array)$rawresults[$idx] : [];
                    $status = trim((string)($entry['status'] ?? ''));
                    $failed = ($status === 'error' || $status === 'failed');
                    $issuecodes = array_values(array_map('strval', (array)($entry['issue_codes'] ?? [])));

                    if ($failed) {
                        $this->queuesvc->update_status(
                            $threadid,
                            $queueitemid,
                            'failed',
                            $issuecodes,
                            'domain_error',
                            trim((string)($entry['detail'] ?? ''))
                        );
                    } else {
                        $this->queuesvc->update_status($threadid, $queueitemid, 'succeeded', $issuecodes);
                    }
                }

                return $feedback;
            });
            $results = $feedback['results'];
            $this->store->update_run_status($runid, 'completed', $results);
            $message = trim((string)($feedback['message'] ?? ''));
            if ($message === '') {
                $message = $this->localized_string('ai_run_executed', 'bookingextension_agent', null, $outputlang);
            }

            $queueobservation = $this->observationbuilder->build_observation($this->queuesvc->get_queue_items($threadid));
            if ($queueobservation !== '') {
                $message .= "\n\n" . $queueobservation;
            }

            $payload = [
                'response_type' => 'execution_result',
                'message'       => $message,
                'commands'      => $preparedcommands,
                'ambiguities'   => [],
                'errors'        => [],
                'runid'         => (int)$runid,
                'results'       => $results,
            ];

            $disambiguationrequired = false;
            foreach ($results as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!empty($entry['disambiguation_required'])) {
                    $disambiguationrequired = true;
                    $candidate = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
                    if ($candidate !== '') {
                        $payload['message'] = $candidate;
                    }
                    break;
                }
            }

            if ($disambiguationrequired) {
                $payload['response_type'] = 'clarification';
                $payload['commands'] = [];
                $payload['issue_codes'] = ['DOCS_DISAMBIGUATION_REQUIRED'];
            }

            if (trim($nextstepintent) !== '') {
                $payload['next_step_intent'] = trim($nextstepintent);
            }

            return $payload;
        } catch (\Throwable $e) {
            $failureresults = [[
                'status'   => 'error',
                'detail'   => $e->getMessage(),
                'resultid' => null,
            ]];

            foreach ($queueitemids as $queueitemid) {
                $queueitemid = (string)$queueitemid;
                if ($queueitemid === '') {
                    continue;
                }
                $this->queuesvc->update_status($threadid, $queueitemid, 'failed', [], 'provider_error', $e->getMessage());
            }

            $this->store->update_run_status($runid, 'failed', $failureresults);

            return [
                'response_type' => 'error',
                'message'       => $this->localized_string('ai_provider_error', 'bookingextension_agent', null, $outputlang),
                'commands'      => $preparedcommands,
                'ambiguities'   => [],
                'errors'        => [$e->getMessage()],
                'runid'         => (int)$runid,
                'results'       => $failureresults,
            ];
        }
    }

    /**
     * Inject a canonical output language into each command input.
     *
     * This is framework-wide and avoids per-task language plumbing.
     * Tasks may still override outputlang explicitly.
     *
     * @param array $commands
     * @param string $outputlang
     * @return array
     */
    private function inject_output_language_into_commands(array $commands, string $outputlang): array {
        $lang = trim($outputlang);
        if ($lang === '') {
            return $commands;
        }

        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $input['outputlang'] = $lang;
            $command['input'] = $input;
        }
        unset($command);

        return $commands;
    }

    /**
     * Run a callback while forcing the current language when requested.
     *
     * @param string $outputlang
     * @param callable $callback
     * @return mixed
     */
    private function with_output_language(string $outputlang, callable $callback) {
        $targetlang = trim($outputlang);
        if ($targetlang === '') {
            return $callback();
        }

        $currentlang = current_language();
        $switched = $targetlang !== $currentlang;
        if ($switched) {
            force_current_language($targetlang);
        }

        try {
            return $callback();
        } finally {
            if ($switched) {
                force_current_language($currentlang);
            }
        }
    }

    // Private: preflight helpers.

    /**
     * Run preflight validation on a list of commands.
     *
     * Calls task->preflight() for each command (with deanonymization) and
     * returns:
     *   valid             — bool: whether all commands passed
     *   prepared_commands — the commands with input replaced by prepared_input
     *   errors            — human-readable error messages (blocking)
     *   attempted_tasks   — list of task names
     *   issue_codes       — all issue codes from all commands
     *
     * @param  array $commands
     * @param  int   $threadid
     * @param  int   $cmid
     * @param  int   $userid
     * @return array{valid:bool,prepared_commands:array,errors:array,attempted_tasks:array,issue_codes:array}
     */
    private function run_preflight_on_commands(
        array $commands,
        int $threadid,
        int $cmid,
        int $userid
    ): array {
        $preparedcommands = [];
        $errors = [];
        $attemptedtasks = [];
        $issuecodes = [];

        $anonymizer = new privacy_anonymizer($this->store);

        foreach ($commands as $idx => $command) {
            $label = 'Command #' . ($idx + 1);
            if (!is_array($command)) {
                $errors[] = $label . ': malformed command payload.';
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                $errors[] = $label . ': missing task.';
                continue;
            }
            $attemptedtasks[] = $taskname;

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                $errors[] = $label . ': task ' . $taskname . ' is not registered.';
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            // Deanonymize before preflight so task sees real values.
            if ($threadid > 0 && $userid > 0) {
                $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            }

            $preflightresult = $task->preflight($input, $cmid, $userid);

            foreach ($preflightresult->get_issue_codes() as $code) {
                if ($code !== '') {
                    $issuecodes[] = $code;
                }
            }

            if (!$preflightresult->isvalid) {
                foreach ($preflightresult->issues as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $errors[] = $msg;
                    }
                }
                // Infer TEACHER_USER_NOT_FOUND from message text for backward-compatible
                // fallback-message generation in build_confirmation_validation_message().
                foreach ($errors as $error) {
                    $normalizederror = core_text::strtolower(trim((string)$error));
                    if (
                        str_contains($normalizederror, 'no user matched user query')
                    ) {
                        $issuecodes[] = 'TEACHER_USER_NOT_FOUND';
                    }
                }
                continue;
            }

            // Preflight passed: update command input with resolved prepared_input.
            $updatedcommand = $command;
            $updatedcommand['input'] = $preflightresult->preparedinput;
            $preparedcommands[] = $updatedcommand;
        }

        return [
            'valid'             => empty($errors),
            'prepared_commands' => $preparedcommands,
            'errors'            => array_values(array_unique($errors)),
            'attempted_tasks'   => array_values(array_unique($attemptedtasks)),
            'issue_codes'       => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Run legacy preflight and optional V2 pipeline/shadow comparison.
     *
     * Pipeline behavior:
     * - Always runs legacy preflight for backward compatibility.
     * - Optionally evaluates V2 in shadow mode for audit-only comparison.
     * - Optionally enforces V2 result for runtime gating when enabled.
     *
     * @param array $commands
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @return array{valid:bool,prepared_commands:array,errors:array,attempted_tasks:array,issue_codes:array,v2_result:array<string,mixed>}
     */
    private function run_preflight_pipeline_on_commands(
        array $commands,
        int $threadid,
        int $cmid,
        int $userid
    ): array {
        $legacy = $this->run_preflight_on_commands($commands, $threadid, $cmid, $userid);
        $legacy['v2_result'] = [];

        $v2enabled = (bool)get_config('bookingextension_agent', 'preflight_v2_enabled');
        $shadowmode = (bool)get_config('bookingextension_agent', 'preflight_v2_shadow_mode');
        if (!$v2enabled && !$shadowmode) {
            return $legacy;
        }

        $v2 = $this->evaluate_preflight_v2_result($commands, $legacy, $threadid);
        $legacy['v2_result'] = $v2->to_array();
        $this->log_preflight_v2_shadow_comparison($threadid, $legacy, $v2);

        if (!$v2enabled) {
            return $legacy;
        }

        $status = $v2->status;
        if ($status === 'pass') {
            return $legacy;
        }

        $issuecodes = !empty($v2->issuecodes) ? $v2->issuecodes : (array)($legacy['issue_codes'] ?? []);
        $legacy['issue_codes'] = array_values(array_unique(array_map('strval', $issuecodes)));
        if ($status === 'retry_hint') {
            $legacy['valid'] = false;
            $legacy['errors'] = array_values(array_unique(array_merge(
                (array)($legacy['errors'] ?? []),
                ['Preflight retry requested. Please retry after backoff.']
            )));
            return $legacy;
        }

        $legacy['valid'] = false;
        if (empty($legacy['errors'])) {
            $legacy['errors'] = [
                $status === 'soft_block'
                    ? 'Preflight requires clarification/confirmation before execution.'
                    : 'Preflight blocked execution.',
            ];
        }

        return $legacy;
    }

    /**
     * Evaluate preflight contract v2 from command batch + legacy result.
     *
     * @param array $commands
     * @param array $legacy
     * @param int $threadid
     * @return preflight_result_v2
     */
    private function evaluate_preflight_v2_result(array $commands, array $legacy, int $threadid): preflight_result_v2 {
        $startedat = microtime(true);

        foreach ($commands as $command) {
            $command = is_array($command) ? $command : [];
            $schemavalidation = $this->preflightschemavalidator->validate($command);
            if (($schemavalidation['valid'] ?? false) === true) {
                continue;
            }
            $result = new preflight_result_v2(
                'hard_block',
                ['SCHEMA_ERROR'],
                'schema',
                0,
                0,
                (int)max(0, (microtime(true) - $startedat) * 1000)
            );
            $this->preflightauditlogger->append($threadid, 0, [
                'layer' => 'schema',
                'status' => $result->status,
                'issue_codes' => $result->issuecodes,
                'retry_count' => 0,
                'duration_ms' => $result->durationms,
                'error_class' => 'schema_error',
            ]);
            return $result;
        }

        $domainresult = $this->preflightdomainrunner->run((array)($legacy['issue_codes'] ?? []), $startedat);
        if (!$legacy['valid'] && $domainresult->status === 'pass') {
            $domainresult = new preflight_result_v2(
                'hard_block',
                (array)($legacy['issue_codes'] ?? []),
                'domain',
                0,
                0,
                $domainresult->durationms
            );
        }

        $errorclass = $this->infer_error_class_from_issue_codes((array)($legacy['issue_codes'] ?? []));
        $result = $domainresult;
        if ($errorclass !== '' && in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
            $result = $this->preflightexecutiongate->evaluate(
                $errorclass,
                0,
                (array)($legacy['issue_codes'] ?? [])
            );
        }

        $this->preflightauditlogger->append($threadid, 0, [
            'layer' => $result->blockinglayer !== '' ? $result->blockinglayer : 'preflight',
            'status' => $result->status,
            'issue_codes' => $result->issuecodes,
            'retry_count' => $result->retrycount,
            'duration_ms' => $result->durationms,
            'error_class' => $errorclass,
        ]);
        return $result;
    }

    /**
     * Infer gate-relevant error_class from issue codes.
     *
     * @param array<int,string> $issuecodes
     * @return string
     */
    private function infer_error_class_from_issue_codes(array $issuecodes): string {
        foreach ($issuecodes as $code) {
            $upper = core_text::strtoupper(trim((string)$code));
            if ($upper === '') {
                continue;
            }
            if (str_contains($upper, 'TIMEOUT')) {
                return 'provider_timeout';
            }
            if (str_contains($upper, 'TRANSIENT_IO') || str_contains($upper, 'IO_TRANSIENT')) {
                return 'transient_io';
            }
            if (str_contains($upper, 'PERMISSION')) {
                return 'permission_error';
            }
            if (str_contains($upper, 'CONFLICT')) {
                return 'domain_conflict';
            }
            if (str_contains($upper, 'VALIDATION') || str_contains($upper, 'MISSING_')) {
                return 'validation_error';
            }
        }
        return '';
    }

    /**
     * Log legacy-vs-v2 comparison in shadow mode.
     *
     * @param int $threadid
     * @param array $legacy
     * @param preflight_result_v2 $v2
     * @return void
     */
    private function log_preflight_v2_shadow_comparison(int $threadid, array $legacy, preflight_result_v2 $v2): void {
        if (!(bool)get_config('bookingextension_agent', 'preflight_v2_shadow_mode')) {
            return;
        }

        $legacyvalid = !empty($legacy['valid']);
        $v2allows = $v2->status === 'pass';
        $shadowissuecodes = [];
        if ($legacyvalid !== $v2allows) {
            $shadowissuecodes[] = 'PREFLIGHT_V2_SHADOW_MISMATCH';
        }
        $shadowissuecodes = array_values(array_unique(array_merge(
            $shadowissuecodes,
            (array)($v2->issuecodes ?? [])
        )));

        $this->preflightauditlogger->append($threadid, 0, [
            'layer' => 'shadow_compare',
            'status' => $v2->status,
            'issue_codes' => $shadowissuecodes,
            'retry_count' => $v2->retrycount,
            'duration_ms' => $v2->durationms,
            'error_class' => $this->infer_error_class_from_issue_codes((array)($legacy['issue_codes'] ?? [])),
        ]);
    }

    /**
     * Build a user-facing clarification text from pre-confirmation validation result.
     *
     * @param  array  $validation
     * @param  string $outputlang
     * @return string
     */
    private function build_confirmation_validation_message(array $validation, string $outputlang): string {
        $errors = (array)($validation['errors'] ?? []);
        $ambiguities = (array)($validation['ambiguities'] ?? []);
        $attemptedtasks = array_map(
            static fn($task): string => trim((string)$task),
            (array)($validation['attempted_tasks'] ?? [])
        );
        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($validation['issue_codes'] ?? [])
        );

        if (
            in_array('TEACHER_USER_NOT_FOUND', $issuecodes, true)
            && in_array('booking.create_option', $attemptedtasks, true)
            && $this->has_confirmable_prevalidation_issues($issuecodes)
        ) {
            $teacherquery = $this->extract_teacher_query_from_validation_errors($errors);
            if ($teacherquery === '') {
                $teacherquery = $this->localized_string('ai_property_teacherquery', 'bookingextension_agent', null, $outputlang);
            }
            return $this->localized_string(
                'ai_confirm_missing_teacher_user_create_option',
                'bookingextension_agent',
                (object)['userquery' => $teacherquery],
                $outputlang
            );
        }

        $parts = [];
        if (!empty($errors)) {
            $parts[] = trim(implode(' ', array_map(static fn($v): string => trim((string)$v), $errors)));
        }
        if (!empty($ambiguities)) {
            $parts[] = trim(implode(' ', array_map(static fn($v): string => trim((string)$v), $ambiguities)));
        }

        $message = trim(implode(' ', array_filter($parts)));
        if ($message !== '') {
            return $message;
        }

        return $this->localized_string('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang);
    }

    /**
     * Extract teacher query value from validation error text.
     *
     * @param  array $errors
     * @return string
     */
    private function extract_teacher_query_from_validation_errors(array $errors): string {
        foreach ($errors as $error) {
            $text = trim((string)$error);
            if ($text === '' || preg_match('/"([^"]+)"/', $text, $matches) !== 1) {
                continue;
            }
            return trim((string)($matches[1] ?? ''));
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Private: command classification helpers.

    /**
     * Check whether a response contains at least one mutating (non-read-only) command.
     *
     * @param  array $result
     * @return bool
     */
    private function has_mutating_commands(array $result): bool {
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return false;
        }
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '' && !$this->registry->is_read_only_task($taskname)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split commands into read-only and mutating groups.
     *
     * Unknown or malformed commands are treated as mutating for safety.
     *
     * @param  array $commands
     * @return array ['readonly' => array, 'mutating' => array]
     */
    private function split_commands_by_mutability(array $commands): array {
        $readonly = [];
        $mutating = [];

        foreach ($commands as $command) {
            if (!is_array($command)) {
                $mutating[] = ['task' => '', 'input' => []];
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '' && $this->registry->is_read_only_task($taskname)) {
                $readonly[] = $command;
            } else {
                $mutating[] = $command;
            }
        }

        return ['readonly' => $readonly, 'mutating' => $mutating];
    }

    /**
     * Detect failed read-only execution.
     *
     * @param  array $execution
     * @return bool
     */
    private function execution_result_has_failures(array $execution): bool {
        if ((string)($execution['response_type'] ?? '') === 'error') {
            return true;
        }
        $results = $execution['results'] ?? [];
        if (!is_array($results)) {
            return false;
        }
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = core_text::strtolower(trim((string)($entry['status'] ?? '')));
            if (in_array($status, ['error', 'failed', 'skipped'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether pre-validation issue codes support keeping confirmation flow.
     *
     * @param  array $issuecodes
     * @return bool
     */
    private function has_confirmable_prevalidation_issues(array $issuecodes): bool {
        $normalized = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            $issuecodes
        );
        $confirmablecodes = $this->issuecodeprovider->get_prevalidation_confirmable_issue_codes();
        return !empty(array_intersect($confirmablecodes, $normalized));
    }

    // -------------------------------------------------------------------------
    // Private: trigger helpers.

    /**
     * Check whether a normalized interpreter result includes a specific trigger id.
     *
     * @param  array  $result
     * @param  string $triggerid
     * @return bool
     */
    private function result_has_trigger(array $result, string $triggerid): bool {
        $usedtriggers = $result['used_triggers'] ?? [];
        if (!is_array($usedtriggers) || trim($triggerid) === '') {
            return false;
        }
        foreach ($usedtriggers as $candidate) {
            if (trim((string)$candidate) === $triggerid) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Private: duplicate-title helpers.

    /**
     * Check whether the recent assistant response asked about duplicate titles.
     *
     * @param  int $threadid
     * @return bool
     */
    private function has_recent_duplicate_title_prompt(int $threadid): bool {
        $messages = $this->store->get_recent_messages($threadid, 8);
        if (empty($messages)) {
            return false;
        }
        foreach ($messages as $msg) {
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }
            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                continue;
            }
            if ((string)($structured['response_type'] ?? '') !== 'confirmation_request') {
                continue;
            }
            $issuecodes = $structured['issue_codes'] ?? [];
            if (!is_array($issuecodes)) {
                continue;
            }
            $normalizedcodes = array_values(array_filter(array_map(
                static fn($code): string => strtoupper(trim((string)$code)),
                $issuecodes
            )));
            $duplicatecodeprovider = $this->issuecodeprovider->get_duplicate_confirmation_issue_codes();
            if (!empty(array_intersect($duplicatecodeprovider, $normalizedcodes))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ensure create_option commands include duplicate_title override after explicit user confirmation.
     *
     * @param  array $result
     * @return array
     */
    private function apply_duplicate_title_override(array $result): array {
        if (!in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            return $result;
        }
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }
        $changed = false;
        foreach ($commands as $idx => $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== 'booking.create_option') {
                continue;
            }
            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                continue;
            }
            $overrides = $input['override'] ?? [];
            if (!is_array($overrides)) {
                $overrides = [];
            }
            if (!in_array('duplicate_title', $overrides, true)) {
                $overrides[] = 'duplicate_title';
                $input['override'] = array_values(array_unique($overrides));
                $commands[$idx]['input'] = $input;
                $changed = true;
            }
        }
        if ($changed) {
            $result['commands'] = array_values($commands);
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private: teacher autocreate augmentation.

    /**
     * Prepend booking.create_user when user explicitly allows creating missing teacher accounts.
     *
     * @param  array  $result
     * @param  string $usermessage
     * @param  string $outputlang
     * @return array
     */
    private function augment_missing_teacher_autocreate_confirmation(
        array $result,
        string $usermessage,
        string $outputlang = ''
    ): array {
        if ((string)($result['response_type'] ?? '') !== 'confirmation_request') {
            return $result;
        }
        if ($this->registry->get_task('booking.create_user') === null) {
            return $result;
        }
        if (!$this->user_allows_missing_user_autocreate($usermessage)) {
            return $result;
        }

        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($result['issue_codes'] ?? [])
        );
        $errors = array_map(
            static fn($error): string => core_text::strtolower(trim((string)$error)),
            (array)($result['errors'] ?? [])
        );

        $hasteachernotfounderror = false;
        foreach ($errors as $error) {
            if (
                $error !== ''
                && (
                    str_contains($error, 'no user matched user query')
                )
            ) {
                $hasteachernotfounderror = true;
                break;
            }
        }

        if (!in_array('TEACHER_USER_NOT_FOUND', $issuecodes, true) && !$hasteachernotfounderror) {
            return $result;
        }

        $commands = is_array($result['commands'] ?? null) ? (array)$result['commands'] : [];
        if (empty($commands)) {
            return $result;
        }
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if ((string)($command['task'] ?? '') === 'booking.create_user') {
                return $result;
            }
        }

        $teacherquery = '';
        foreach ($commands as $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== 'booking.create_option') {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $candidate = trim((string)($input['teacherquery'] ?? ''));
            if ($candidate !== '') {
                $teacherquery = $candidate;
                break;
            }
        }

        if ($teacherquery === '') {
            return $result;
        }

        array_unshift($commands, [
            'task'    => 'booking.create_user',
            'version' => 1,
            'input'   => ['userquery' => $teacherquery, 'outputlang' => $outputlang],
        ]);
        $result['commands'] = array_values($commands);
        return $result;
    }

    /**
     * Detect user intent that permits creating missing users.
     *
     * @param  string $usermessage
     * @return bool
     */
    private function user_allows_missing_user_autocreate(string $usermessage): bool {
        $normalized = core_text::strtolower(trim(preg_replace('/\s+/', ' ', $usermessage) ?? $usermessage));
        if ($normalized === '') {
            return false;
        }
        return (bool)preg_match(
            '/('
            . 'auch\s+wenn\s+.*benutzer.*nicht\s+existiert|'
            . 'if\s+.*user.*does\s+not\s+exist|'
            . 'even\s+if\s+.*user.*does\s+not\s+exist'
            . ')/u',
            $normalized
        );
    }

    // -------------------------------------------------------------------------
    // Private: store / thread helpers.

    /**
     * Retrieve the last user message from the thread.
     *
     * @param  int $threadid
     * @return string
     */
    private function get_last_user_message(int $threadid): string {
        $messages = $this->store->get_recent_messages($threadid, 8);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]->role ?? '') === 'user') {
                return (string)($messages[$i]->content ?? '');
            }
        }
        return '';
    }

    /**
     * Promote a dead-end clarification into a readonly task call using generic recovery.
     *
     * Recovery strategy:
     * Keep only registered read-only tasks.
     * Build task input schema-driven from user message/context.
     * If no candidate exists, attempt generic lookup recovery for
     * read-only tasks that expose a "query" property.
     *
     * @param array $result
     * @param string $usermessage
     * @param string $outputlang
     * @param int $threadid
     * @param int $cmid
     * @return array
     */
    private function promote_clarification_with_generic_task_recovery(
        array $result,
        string $usermessage,
        string $outputlang,
        int $threadid,
        int $cmid,
        int $userid
    ): array {
        $responsetype = (string)($result['response_type'] ?? '');
        if (!in_array($responsetype, ['clarification', 'error'], true)) {
            return $result;
        }
        if (!empty((array)($result['commands'] ?? [])) || !empty((array)($result['results'] ?? []))) {
            return $result;
        }

        $usedtriggers = (array)($result['used_triggers'] ?? []);
        $nextstepintent = trim((string)($result['next_step_intent'] ?? ''));
        $candidatetasks = [];

        // Direct booking.explain_docs_topic recovery when appropriate.
        // This runs even without explicit core.is_lookup_request trigger to catch cases where
        // the model says it will search docs but doesn't provide structured trigger data.
        if (empty($candidatetasks) && $this->looks_like_lookup_request($usermessage, $result)) {
            $taskname = 'booking.explain_docs_topic';
            if ($this->registry->is_read_only_task($taskname) && $this->registry->get_task($taskname) !== null) {
                $candidatetasks[$taskname] = true;
            }
        }

        // Generic diagnostic fallback: when wording indicates a diagnosis question,
        // prefer readonly tasks that accept a full question and an option anchor.
        if (empty($candidatetasks) && $this->looks_like_diagnostic_intent($usermessage)) {
            foreach ($this->registry->get_task_names() as $taskname) {
                if (!$this->registry->is_read_only_task($taskname)) {
                    continue;
                }
                $task = $this->registry->get_task($taskname);
                if ($task === null) {
                    continue;
                }
                $schema = $task->get_schema();
                $properties = (array)($schema['properties'] ?? []);
                if (
                    isset($properties['question']) && is_array($properties['question'])
                    && (isset($properties['optionquery']) || isset($properties['optionid']))
                ) {
                    $candidatetasks[(string)$taskname] = true;
                }
            }
        }

        // Generic lookup fallback: choose read-only search-like tasks with a query property.
        if (empty($candidatetasks) && $this->result_has_trigger($result, 'core.is_lookup_request')) {
            foreach ($this->registry->get_task_names() as $taskname) {
                if (!$this->registry->is_read_only_task($taskname)) {
                    continue;
                }
                $task = $this->registry->get_task($taskname);
                if ($task === null) {
                    continue;
                }
                $schema = $task->get_schema();
                $properties = (array)($schema['properties'] ?? []);
                if (!isset($properties['query']) || !is_array($properties['query'])) {
                    continue;
                }
                $candidatetasks[(string)$taskname] = true;
            }
        }

        // Generic context fallback: when prior thread context already resolved an option-like
        // query, prefer read-only query tasks that are semantically option-related.
        if (empty($candidatetasks)) {
            $contextquery = $this->extract_option_context_query_from_thread($threadid);
            if ($contextquery !== '') {
                $scored = [];
                foreach ($this->registry->get_task_names() as $taskname) {
                    if (!$this->registry->is_read_only_task($taskname)) {
                        continue;
                    }
                    $task = $this->registry->get_task($taskname);
                    if ($task === null) {
                        continue;
                    }
                    $schema = $task->get_schema();
                    $properties = (array)($schema['properties'] ?? []);
                    if (!isset($properties['query']) || !is_array($properties['query'])) {
                        continue;
                    }

                    $score = 0;
                    $description = core_text::strtolower(trim((string)($schema['description'] ?? '')));
                    $tasknamelower = core_text::strtolower((string)$taskname);
                    if (str_contains($description, 'option')) {
                        $score += 3;
                    }
                    if (str_contains($tasknamelower, 'option')) {
                        $score += 2;
                    }
                    if (str_contains($tasknamelower, 'search')) {
                        $score += 1;
                    }
                    $scored[] = ['task' => (string)$taskname, 'score' => $score];
                }

                usort($scored, static function (array $a, array $b): int {
                    return (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
                });
                foreach ($scored as $entry) {
                    $taskname = trim((string)($entry['task'] ?? ''));
                    if ($taskname !== '') {
                        $candidatetasks[$taskname] = true;
                    }
                }
            }
        }

        // Generic question-only fallback:
        // If trigger-based routing failed, prefer readonly tasks that can operate
        // from a free-form question without additional option anchors.
        if (empty($candidatetasks)) {
            foreach ($this->registry->get_task_names() as $taskname) {
                if (!$this->registry->is_read_only_task($taskname)) {
                    continue;
                }

                $task = $this->registry->get_task($taskname);
                if ($task === null) {
                    continue;
                }

                $schema = $task->get_schema();
                $properties = (array)($schema['properties'] ?? []);
                if (!isset($properties['question']) || !is_array($properties['question'])) {
                    continue;
                }

                // Skip tasks that REQUIRE explicit option anchors from the user.
                // Having the fields in the schema is OK if they are not required.
                $optionqueryisrequired = !empty($properties['optionquery']['required'] ?? false);
                $optionidisrequired = !empty($properties['optionid']['required'] ?? false);
                if ($optionqueryisrequired || $optionidisrequired) {
                    continue;
                }

                $candidatetasks[(string)$taskname] = true;
            }
        }

        if (empty($candidatetasks)) {
            return $result;
        }

        $islookuprecovery = $this->result_has_trigger($result, 'core.is_lookup_request');
        $tasknames = array_keys($candidatetasks);
        usort($tasknames, function (string $a, string $b) use ($islookuprecovery): int {
            return $this->score_generic_recovery_task($b, $islookuprecovery)
                <=> $this->score_generic_recovery_task($a, $islookuprecovery);
        });

        foreach ($tasknames as $taskname) {
            $input = $this->build_recovery_input_for_task(
                $taskname,
                $usermessage,
                $outputlang,
                $threadid,
                $cmid,
                $userid
            );
            if ($input === null) {
                continue;
            }

            $recoverypayload = [
                'response_type'   => 'task_call',
                'message'         => $this->localized_string('ai_status_taskcall_default', 'bookingextension_agent', null, $outputlang),
                'commands'        => [[
                    'task' => $taskname,
                    'version' => 1,
                    'input' => $input,
                ]],
                'ambiguities'     => [],
                'errors'          => [],
                'attempted_tasks' => [$taskname],
                'issue_codes'     => array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['AUTO_GENERIC_TASK_RECOVERY']
                ))),
                'used_triggers'   => $usedtriggers,
            ];

            if ($nextstepintent !== '') {
                $recoverypayload['next_step_intent'] = $nextstepintent;
            }

            return $recoverypayload;
        }

        return $result;
    }

    /**
     * Score a recovery candidate task by schema fit to the user message.
     *
     * @param string $taskname
     * @param bool $islookuprecovery
     * @return int
     */
    private function score_generic_recovery_task(string $taskname, bool $islookuprecovery = false): int {
        $task = $this->registry->get_task($taskname);
        if ($task === null) {
            return -1000;
        }

        $schema = $task->get_schema();
        $properties = (array)($schema['properties'] ?? []);
        $score = 0;

        $hasquestion = isset($properties['question']) && is_array($properties['question']);
        $hasquery = isset($properties['query']) && is_array($properties['query']);
        $hasoptionanchor = isset($properties['optionquery']) || isset($properties['optionid']);
        $hasuserquery = isset($properties['userquery']) && is_array($properties['userquery']);

        if ($islookuprecovery && $taskname === 'booking.explain_docs_topic') {
            $score += 12;
        }

        if ($hasquestion) {
            $score += 5;
        }

        if ($hasoptionanchor) {
            $score += 2;
        }

        if ($hasuserquery) {
            $score += 1;
        }

        if ($hasquery && !$hasquestion) {
            $score += 3;
        }

        return $score;
    }

    /**
     * Detect whether a clarification/error response indicates a documentation lookup attempt.
     *
     * @param string $message
     * @param array $result
     * @return bool
     */
    private function looks_like_lookup_request(string $message, array $result): bool {
        // Use only structural signals from the parsed LLM result.
        // Raw-message NL analysis is intentionally omitted to avoid language-specific heuristics.

        // Signal 1: next_step_intent contains a structured read-only intent class
        // (set by the routing determinism policy, e.g. info_lookup, docs_explain).
        $nextstepintent = core_text::strtolower(trim((string)($result['next_step_intent'] ?? '')));
        if (str_contains($nextstepintent, 'info_lookup') || str_contains($nextstepintent, 'docs_explain')) {
            return true;
        }

        // Signal 2: commands reference only read-only task name prefixes from the task catalog.
        $commands = $result['commands'] ?? [];
        if (!empty($commands)) {
            foreach ($commands as $command) {
                $taskname = core_text::strtolower(trim((string)($command['task'] ?? '')));
                if (
                    str_starts_with($taskname, 'get_')
                    || str_starts_with($taskname, 'list_')
                    || str_starts_with($taskname, 'search_')
                    || str_starts_with($taskname, 'find_')
                    || str_starts_with($taskname, 'docs_')
                    || str_starts_with($taskname, 'check_')
                    || str_starts_with($taskname, 'diagnose_')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve short link-follow-up requests from recent structured assistant results.
     *
     * @param int $threadid
     * @param string $usermessage
     * @param bool $lookupintent
     * @param string $outputlang
     * @param array $result
     * @return array|null
     */
    private function resolve_recent_link_lookup_response(
        int $threadid,
        string $usermessage,
        bool $lookupintent,
        string $outputlang,
        array $result
    ): ?array {
        if (!$lookupintent || !$this->looks_like_link_lookup_request($usermessage)) {
            return null;
        }

        $link = $this->extract_recent_structured_link($threadid);
        if ($link === '') {
            return null;
        }

        $message = (trim(core_text::strtolower($outputlang)) === 'de')
            ? 'Hier ist der Link: ' . $link
            : 'Here is the link: ' . $link;

        return [
            'response_type'   => self::RESPONSE_TYPE_CLARIFICATION,
            'message'         => $message,
            'commands'        => [],
            'ambiguities'     => array_values(array_unique((array)($result['ambiguities'] ?? []))),
            'errors'          => array_values(array_unique((array)($result['errors'] ?? []))),
            'attempted_tasks' => array_values(array_unique((array)($result['attempted_tasks'] ?? []))),
            'issue_codes'     => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['LOOKUP_RECENT_LINK_RESOLVED']
            ))),
            'used_triggers'   => (array)($result['used_triggers'] ?? []),
        ];
    }

    /**
     * Detect short user requests asking for a link/URL.
     *
     * @param string $message
     * @return bool
     */
    private function looks_like_link_lookup_request(string $message): bool {
        $normalized = core_text::strtolower(trim((string)preg_replace('/\s+/', ' ', $message)));
        if ($normalized === '') {
            return false;
        }

        return (bool)preg_match('/\b(link|url|href)\b/u', $normalized);
    }

    /**
     * Extract the most recent URL-like link from structured assistant payloads.
     *
     * @param int $threadid
     * @return string
     */
    private function extract_recent_structured_link(int $threadid): string {
        $messages = $this->store->get_recent_messages($threadid, 16);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ((string)($messages[$i]->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($messages[$i]->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                continue;
            }

            $link = $this->extract_link_from_value($structured);
            if ($link !== '') {
                return $link;
            }
        }

        return '';
    }

    /**
     * Recursively extract the first link-like string from structured values.
     *
     * @param mixed $value
     * @return string
     */
    private function extract_link_from_value($value): string {
        if (is_string($value)) {
            $candidate = trim($value);
            if ($candidate !== '' && preg_match('#^https?://#i', $candidate)) {
                return $candidate;
            }
            return '';
        }

        if (!is_array($value)) {
            return '';
        }

        $prioritykeys = ['link', 'url', 'editlink', 'viewlink', 'editurl', 'viewurl'];
        foreach ($prioritykeys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $candidate = $this->extract_link_from_value($value[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach ($value as $item) {
            $candidate = $this->extract_link_from_value($item);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Detect incomplete planner contract payloads that carry intent text but no executable semantics.
     *
     * @param array $result
     * @param bool $lookupintent
     * @param bool $hasobservationscontext
     * @return bool
     */
    private function is_contract_incomplete_intent_only(
        array $result,
        bool $lookupintent,
        bool $hasobservationscontext
    ): bool {
        if ($hasobservationscontext) {
            return false;
        }

        if (!$lookupintent) {
            return false;
        }

        if (!empty((array)($result['commands'] ?? [])) || !empty((array)($result['results'] ?? []))) {
            return false;
        }

        if ((string)($result['response_type'] ?? '') !== self::RESPONSE_TYPE_CLARIFICATION) {
            return false;
        }

        return $this->is_non_substantive_clarification_message((string)($result['message'] ?? ''), $result);
    }

    /**
     * Determine whether a clarification contains enough answer substance to avoid recovery.
     *
     * @param string $message
     * @param array $result
     * @return bool
     */
    private function is_substantive_clarification_message(string $message, array $result): bool {
        return !$this->is_non_substantive_clarification_message($message, $result);
    }

    /**
     * Detect progress-only clarifications (intent narration without concrete answer content).
     *
     * @param string $message
     * @param array $result
     * @return bool
     */
    private function is_non_substantive_clarification_message(string $message, array $result): bool {
        $message = trim($message);
        if ($message === '') {
            return true;
        }

        if (!empty((array)($result['results'] ?? [])) || !empty((array)($result['commands'] ?? []))) {
            return false;
        }

        // Concrete evidence markers: links or structured multi-line explanations.
        if (
            str_contains($message, '](')
            || str_contains(core_text::strtolower($message), 'http://')
            || str_contains(core_text::strtolower($message), 'https://')
        ) {
            return false;
        }
        if (str_contains($message, "\n") && strlen($message) >= 120) {
            return false;
        }

        $normalized = core_text::strtolower((string)preg_replace('/\s+/', ' ', $message));
        $intentmarkers = [
            'ich werde',
            'ich suche',
            'ich schaue',
            'ich rufe',
            'ich pruefe',
            'ich prüfe',
            'i will',
            'i am going to',
            'let me',
            'i will now',
            'i am checking',
            'i am searching',
        ];
        $hasintentmarker = false;
        foreach ($intentmarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                $hasintentmarker = true;
                break;
            }
        }

        $lookupmarkers = [
            'dokumentation',
            'documentation',
            'benachrichtigung',
            'notification',
            'suche',
            'search',
            'nachsehen',
            'look up',
        ];
        $haslookupmarker = false;
        foreach ($lookupmarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                $haslookupmarker = true;
                break;
            }
        }

        if ($hasintentmarker && $haslookupmarker) {
            return true;
        }

        // Very short single-line clarifications are usually progress text, not a final answer.
        if (!str_contains($message, "\n") && strlen($message) < 90) {
            return true;
        }

        return false;
    }

    /**
     * Heuristic detector for generic diagnostic intent in user text.
     *
     * @param string $message
     * @return bool
     */
    private function looks_like_diagnostic_intent(string $message): bool {
        $normalized = core_text::strtolower(trim((string)preg_replace('/\s+/', ' ', $message)));
        if ($normalized === '') {
            return false;
        }

        $pattern = '/(\?'
            . '|\bwhy\b|\bwarum\b|\bwieso\b|\bcannot\b|can\s+not'
            . '|kann\s+.*\snicht|\bnicht\s+buchen\b|\bnot\s+booked\b'
            . '|\bcancel\b|\bstorno\b|\bstornieren\b|\bdiagnose\b'
            . '|\büberprüfe\b|\bpruefe\b)/u';
        return (bool)preg_match($pattern, $normalized);
    }

    /**
     * Build schema-driven recovery input for a given task.
     *
     * @param string $taskname
     * @param string $usermessage
     * @param string $outputlang
     * @param int $threadid
     * @param int $cmid
     * @return array|null
     */
    private function build_recovery_input_for_task(
        string $taskname,
        string $usermessage,
        string $outputlang,
        int $threadid,
        int $cmid,
        int $userid
    ): ?array {
        $task = $this->registry->get_task($taskname);
        if ($task === null || !$task->is_read_only()) {
            return null;
        }

        $schema = $task->get_schema();
        $properties = (array)($schema['properties'] ?? []);
        if (empty($properties)) {
            return null;
        }

        $question = trim($usermessage);
        $optionquery = $this->extract_option_search_query($usermessage);
        $optionid = $this->extract_option_id_from_message($usermessage);
        if ($optionquery === '') {
            $optionquery = $this->infer_exact_option_query_from_message($usermessage, $cmid);
        }
        if ($optionquery === '' && $this->message_refers_to_context_option($usermessage)) {
            $optionquery = $this->extract_option_context_query_from_thread($threadid);
        }
        $userquery = $this->infer_user_query_from_message($usermessage);

        $hasoptionanchor = isset($properties['optionquery']) || isset($properties['optionid']);
        if ($hasoptionanchor && $optionquery === '' && $optionid <= 0) {
            return null;
        }

        $input = [];
        if (isset($properties['outputlang']) && is_array($properties['outputlang']) && $outputlang !== '') {
            $input['outputlang'] = $outputlang;
        }
        if (isset($properties['question']) && is_array($properties['question']) && $question !== '') {
            $input['question'] = $question;
        }
        if (isset($properties['optionquery']) && is_array($properties['optionquery']) && $optionquery !== '') {
            $input['optionquery'] = $optionquery;
        }
        if (isset($properties['optionid']) && is_array($properties['optionid']) && $optionid > 0) {
            $input['optionid'] = $optionid;
        }
        if (isset($properties['query']) && is_array($properties['query']) && $optionquery !== '') {
            $input['query'] = $optionquery;
        }
        if (isset($properties['userquery']) && is_array($properties['userquery']) && $userquery !== '') {
            $input['userquery'] = $userquery;
        }

        $planner = new planner_service($this->store);
        $input = $planner->enrich_recovery_input(
            $taskname,
            $schema,
            $usermessage,
            $input,
            $threadid,
            $cmid,
            $userid
        );

        // Ensure all required properties are present.
        foreach ($properties as $name => $def) {
            if (!is_array($def) || empty($def['required'])) {
                continue;
            }
            if (!array_key_exists((string)$name, $input)) {
                return null;
            }
        }

        return $input;
    }

    /**
     * Infer a resolvable option query from a free-form user sentence.
     *
     * @param string $message
     * @param int $cmid
     * @return string
     */
    private function infer_option_query_from_message(string $message, int $cmid): string {
        $message = trim((string)preg_replace('/\s+/', ' ', $message));
        if ($message === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $message) ?: [];
        if (empty($tokens)) {
            return '';
        }

        $attempts = 0;
        $maxtokens = min(6, count($tokens));
        for ($len = $maxtokens; $len >= 1; $len--) {
            for ($start = 0; $start + $len <= count($tokens); $start++) {
                $phrase = trim(implode(' ', array_slice($tokens, $start, $len)));
                if (core_text::strlen($phrase) < 3) {
                    continue;
                }

                $resolved = booking_task_support::resolve_single_option($cmid, $phrase, '');
                if (($resolved['status'] ?? '') === 'ok') {
                    return $phrase;
                }

                $attempts++;
                if ($attempts >= 30) {
                    return '';
                }
            }
        }

        return '';
    }

    /**
     * Infer a high-confidence option query from free-form text via exact-title resolution.
     *
     * Unlike infer_option_query_from_message(), this method does NOT use fuzzy option
     * search and therefore avoids accidental matches for generic words like "cancel".
     *
     * @param string $message
     * @param int $cmid
     * @return string
     */
    private function infer_exact_option_query_from_message(string $message, int $cmid): string {
        $message = trim((string)preg_replace('/\s+/', ' ', $message));
        if ($message === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $message) ?: [];
        if (empty($tokens)) {
            return '';
        }

        $attempts = 0;
        $maxtokens = min(6, count($tokens));
        for ($len = $maxtokens; $len >= 1; $len--) {
            for ($start = 0; $start + $len <= count($tokens); $start++) {
                $phrase = trim(implode(' ', array_slice($tokens, $start, $len)));
                $trimchars = " \t\n\r\0\x0B\"'“”„" . chr(96) . ".,;:!?()[]{}";
                $phrase = trim($phrase, $trimchars);
                if (core_text::strlen($phrase) < 3) {
                    continue;
                }

                $exact = booking_task_support::find_existing_options_by_exact_title($cmid, $phrase);
                if (($exact['status'] ?? '') === 'single') {
                    return $phrase;
                }

                $attempts++;
                if ($attempts >= 30) {
                    return '';
                }
            }
        }

        return '';
    }

    /**
     * Extract an explicit option id from a free-form user sentence.
     *
     * @param string $message
     * @return int
     */
    private function extract_option_id_from_message(string $message): int {
        $message = trim($message);
        if ($message === '') {
            return 0;
        }

        $patterns = [
            '/\boption\s*id\s*[:#-]?\s*(\d{1,10})\b/iu',
            '/\boptionid\s*[:#-]?\s*(\d{1,10})\b/iu',
            '/\bbooking\s*option\s*id\s*[:#-]?\s*(\d{1,10})\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                $id = (int)($matches[1] ?? 0);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return 0;
    }

    /**
     * Infer a resolvable user query from a free-form user sentence.
     *
     * @param string $message
     * @return string
     */
    private function infer_user_query_from_message(string $message): string {
        $message = trim((string)preg_replace('/\s+/', ' ', $message));
        if ($message === '') {
            return '';
        }

        $direct = booking_task_support::resolve_single_user($message);
        if (($direct['status'] ?? '') === 'ok') {
            return $message;
        }

        $tokens = preg_split('/\s+/u', $message) ?: [];
        if (empty($tokens)) {
            return '';
        }

        $hits = [];
        $attempts = 0;
        $maxtokens = min(3, count($tokens));
        for ($len = $maxtokens; $len >= 1; $len--) {
            for ($start = 0; $start + $len <= count($tokens); $start++) {
                $phrase = trim(implode(' ', array_slice($tokens, $start, $len)));
                if (core_text::strlen($phrase) < 3) {
                    continue;
                }

                $resolved = booking_task_support::resolve_single_user($phrase);
                if (($resolved['status'] ?? '') === 'ok') {
                    $userid = (int)($resolved['userid'] ?? 0);
                    if ($userid > 0) {
                        $score = ($len * 100) + core_text::strlen($phrase);
                        if (!isset($hits[$userid]) || $score > (int)($hits[$userid]['score'] ?? 0)) {
                            $hits[$userid] = [
                                'phrase' => $phrase,
                                'score' => $score,
                            ];
                        }
                    }
                }

                $attempts++;
                if ($attempts >= 30) {
                    break 2;
                }
            }
        }

        if (empty($hits)) {
            return '';
        }

        uasort($hits, static function (array $a, array $b): int {
            return (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
        });

        $best = (array)reset($hits);
        return trim((string)($best['phrase'] ?? ''));
    }

    /**
     * Extract a quoted phrase from user text as a high-confidence search query.
     *
     * @param string $message
     * @return string
     */
    private function extract_quoted_query(string $message): string {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        if (preg_match('/["“”„\']([^"“”„\']{3,160})["“”„\']/', $message, $matches)) {
            return trim((string)($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * Extract a useful option search query from user text.
     *
     * @param string $message
     * @return string
     */
    private function extract_option_search_query(string $message): string {
        $quoted = $this->extract_quoted_query($message);
        if ($quoted !== '') {
            return $quoted;
        }

        return '';
    }

    /**
     * Check whether user wording explicitly refers to previously discussed option context.
     *
     * @param string $message
     * @return bool
     */
    private function message_refers_to_context_option(string $message): bool {
        $normalized = core_text::strtolower(trim((string)preg_replace('/\s+/', ' ', $message)));
        if ($normalized === '') {
            return false;
        }

        $pattern = '/\b('
            . 'last\s+option|previous\s+option|this\s+option|that\s+option'
            . '|letzte\s+option|vorherige\s+option|diese\s+option|jene\s+option'
            . '|die\s+option|dieser\s+kurs|diese\s+buchungsoption'
            . '|oben\s+genannte\s+option)\b/u';
        return (bool)preg_match($pattern, $normalized);
    }

    /**
     * Extract option query from recent structured thread context.
     *
     * @param int $threadid
     * @return string
     */
    private function extract_option_context_query_from_thread(int $threadid): string {
        $messages = $this->store->get_recent_messages($threadid, 12);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ((string)($messages[$i]->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($messages[$i]->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                continue;
            }

            $contextquery = $this->extract_option_query_from_structured_payload($structured);
            if ($contextquery !== '') {
                return $contextquery;
            }
        }

        return '';
    }

    /**
     * Extract option query candidate from a structured assistant payload.
     *
     * @param array $structured
     * @return string
     */
    private function extract_option_query_from_structured_payload(array $structured): string {
        $resultsets = [];
        foreach (['results', 'loop_results'] as $field) {
            foreach ((array)($structured[$field] ?? []) as $entry) {
                if (is_array($entry)) {
                    $resultsets[] = $entry;
                }
            }
        }

        for ($i = count($resultsets) - 1; $i >= 0; $i--) {
            $entry = (array)$resultsets[$i];
            $diagnosisname = trim((string)($entry['diagnosis']['optionname'] ?? ''));
            if ($diagnosisname !== '') {
                return $diagnosisname;
            }

            $options = (array)($entry['options'] ?? []);
            if (!empty($options)) {
                $first = (array)$options[0];
                $name = trim((string)($first['name'] ?? $first['text'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        foreach ((array)($structured['commands'] ?? []) as $command) {
            if (!is_array($command)) {
                continue;
            }
            $input = (array)($command['input'] ?? []);
            $optionquery = trim((string)($input['optionquery'] ?? ''));
            if ($optionquery !== '') {
                return $optionquery;
            }
        }

        return '';
    }

    /**
     * Build a minimal clarification result.
     *
     * @param  string $message
     * @return array
     */
    private function clarification_result(string $message): array {
        return [
            'response_type'             => 'clarification',
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => [],
            'issue_codes'               => [],
            'pending_confirmation_code' => '',
            'used_triggers'             => [],
            'runid'                     => 0,
            'results'                   => [],
        ];
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
