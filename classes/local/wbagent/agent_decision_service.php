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
use bookingextension_agent\local\wbagent\services\execution_observation_ledger;
use bookingextension_agent\local\wbagent\services\language_policy_service;
use bookingextension_agent\local\wbagent\services\preflight_pipeline;

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

    /** @var queue_manager */
    private queue_manager $queuesvc;

    /** @var observation_builder */
    private observation_builder $observationbuilder;

    /** @var preflight_pipeline */
    private preflight_pipeline $preflightpipeline;

    /** @var language_policy_service */
    private language_policy_service $languagepolicy;

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
        $this->queuesvc = new queue_manager($store, $registry);
        $this->observationbuilder = new observation_builder();
        $this->preflightpipeline = new preflight_pipeline($registry, $store);
        $this->languagepolicy = new language_policy_service();
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
                return $this->build_pending_resolution_clarification($result, $pendingintent, $threadid, $outputlang);
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
        $usermessage = $this->get_last_user_message($threadid);
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
            $queueitemids = array_values(array_filter(array_map(
                'strval',
                (array)($result['queue_item_ids'] ?? [])
            )));
            $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($result['commands']));
            $this->store->set_pending_intent(
                $threadid,
                !empty($queueitemids) ? [] : $result['commands'],
                $intentkey,
                $userid,
                $contextid,
                [
                    'queue_item_ids' => $queueitemids,
                    'queue_authoritative' => !empty($queueitemids),
                ]
            );
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
    private function build_pending_resolution_clarification(
        array $result,
        array $pendingintent,
        int $threadid,
        string $outputlang
    ): array {
        // Phase 2: queue is single source of truth; no fallback to stored commands.
        $pendingcommands = $this->build_commands_from_pending_queue($pendingintent, $threadid);
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
     * Build command payloads from pending queue item ids.
     *
     * @param array<string,mixed> $pendingintent
     * @param int $threadid
     * @return array<int,array<string,mixed>>
     */
    private function build_commands_from_pending_queue(array $pendingintent, int $threadid): array {
        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        if (empty($queueitemids)) {
            return [];
        }

        $commands = [];
        foreach ($queueitemids as $queueitemid) {
            $item = $this->queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item) || (string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            if (!in_array($status, ['blocked_confirmation', 'ready', 'queued', 'retry_waiting'], true)) {
                continue;
            }

            $task = trim((string)($item['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $input = is_array($item['prepared_input'] ?? null) && !empty($item['prepared_input'])
                ? (array)$item['prepared_input']
                : (is_array($item['input'] ?? null) ? (array)$item['input'] : []);
            $command = [
                'task' => $task,
                'version' => max(1, (int)($item['version'] ?? 1)),
                'input' => $input,
            ];
            $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
            if (!empty($dependson)) {
                $command['depends_on'] = $dependson;
            }
            $commands[] = $command;
        }

        return $commands;
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
        $contextid = (int)\context_module::instance($cmid)->id;
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

        // Phase 2: queue is single source of truth; no fallback to stored commands.
        $confirmcommands = $this->build_commands_from_pending_queue($pendingintent, $threadid);
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
        $preflightresult = $this->preflightpipeline->run(
            $confirmcommands,
            $threadid,
            $contextid,
            $userid
        );
        if (trim((string)($preflightresult['status'] ?? '')) !== 'pass') {
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
        $queueitemids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        foreach ($preparedcommands as $idx => $preparedcommand) {
            $queueitemid = (string)($queueitemids[$idx] ?? '');
            $preparedinput = is_array($preparedcommand['input'] ?? null) ? (array)$preparedcommand['input'] : [];
            if ($queueitemid === '' || empty($preparedinput)) {
                continue;
            }
            $this->queuesvc->set_prepared_input(
                $threadid,
                $queueitemid,
                $contextid,
                $preparedinput
            );
        }

        $preparedcommands = $this->apply_execution_guard_tokens(
            $preparedcommands,
            $contextid
        );

        $confirmmessage = $this->localized_string('ai_confirm_pending_intent', 'bookingextension_agent', null, $outputlang);
        $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($preparedcommands));
        $this->store->set_pending_intent(
            $threadid,
            !empty($queueitemids) ? [] : $preparedcommands,
            $intentkey,
            $userid,
            $contextid,
            [
                'queue_item_ids' => $queueitemids,
                'queue_authoritative' => !empty($queueitemids),
            ]
        );
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

        // Queue ingestion records commands before preflight; mutating status is
        // assigned later from the preflight decision.
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

        foreach ($mutatingcommands as $idx => $mutatingcommand) {
            $status = 'queued';
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
            // Phase 2 T5: include ALL mutating commands and ALL queue item ids so the
            // ai_confirm_run call can execute the full batch in a single round-trip.
            $result['commands'] = array_values(
                array_filter($mutatingcommands, static fn($e): bool => is_array($e))
            );
            $result['queue_item_ids'] = array_values(
                array_filter($mutatingqueueids, static fn($id): bool => $id !== '')
            );

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
        $contextid = (int)\context_module::instance($cmid)->id;
        $commands = (array)($result['commands'] ?? []);
        $lastusermessage = trim($this->get_last_user_message($threadid));
        $planner = new planner_service($this->store);
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

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            if ($lastusermessage !== '') {
                $command['input'] = $planner->enrich_recovery_input(
                    $taskname,
                    $task->get_schema(),
                    $lastusermessage,
                    $input,
                    $threadid,
                    $cmid,
                    $userid
                );
            }
        }
        unset($command);

        $preflightresult = $this->with_output_language(
            $outputlang,
            fn() => $this->preflightpipeline->run(
                $commands,
                $threadid,
                $contextid,
                $userid
            )
        );
        $preparedcommands = array_values(array_filter(
            (array)($preflightresult['prepared_commands'] ?? []),
            static fn($command): bool => is_array($command)
        ));
        $status = trim((string)($preflightresult['status'] ?? ''));
        $allissuecodes = array_values(array_unique(array_map('strval', (array)($preflightresult['issue_codes'] ?? []))));
        $attemptedtasks = array_values(array_unique(array_map('strval', (array)($preflightresult['attempted_tasks'] ?? []))));
        $allissues = array_values(array_filter(
            (array)($preflightresult['issues'] ?? []),
            static fn($issue): bool => is_array($issue)
        ));
        $blockingerrors = array_values(array_unique(array_map('strval', (array)($preflightresult['errors'] ?? []))));
        $v2result = [
            'status' => $status,
            'issue_codes' => $allissuecodes,
            'blocking_layer' => trim((string)($preflightresult['blocking_layer'] ?? '')),
            'retry_after_ms' => (int)($preflightresult['retry_after_ms'] ?? 0),
            'retry_count' => (int)($preflightresult['retry_count'] ?? 0),
            'duration_ms' => (int)($preflightresult['duration_ms'] ?? 0),
        ];
        $queueitemids = array_values(array_filter(array_map('strval', (array)($result['queue_item_ids'] ?? []))));
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread(
            $userid,
            $contextid,
            $threadid
        );
        $this->apply_preflight_queue_decision(
            $threadid,
            $queueitemids,
            $status,
            $allissuecodes,
            $blockingerrors,
            $v2result,
            $autoconfirmmode
        );
        foreach ($preparedcommands as $idx => $preparedcommand) {
            $queueitemid = trim((string)($queueitemids[$idx] ?? ''));
            $preparedinput = is_array($preparedcommand['input'] ?? null) ? (array)$preparedcommand['input'] : [];
            if ($queueitemid !== '' && !empty($preparedinput)) {
                $this->queuesvc->set_prepared_input(
                    $threadid,
                    $queueitemid,
                    $contextid,
                    $preparedinput
                );
            }
        }

        $preparedcommands = $this->apply_execution_guard_tokens(
            $preparedcommands,
            $contextid
        );

        // If there were blocking errors, decide whether to allow confirmable continuation.
        if ($status !== 'pass') {
            $validationmessage = trim(implode(' ', $blockingerrors));
            if ($status === 'retry_hint') {
                $retrymessage = $this->localized_string(
                    $this->languagepolicy->preflight_retry_hint_string_id(),
                    'bookingextension_agent',
                    null,
                    $outputlang
                );
                return [
                    'response_type'   => 'confirmation_request',
                    'message'         => $validationmessage !== '' ? $validationmessage : $retrymessage,
                    'commands'        => !empty($preparedcommands) ? $preparedcommands : (array)$result['commands'],
                    'queue_item_ids'  => $queueitemids,
                    'ambiguities'     => [],
                    'errors'          => $blockingerrors,
                    'attempted_tasks' => $attemptedtasks,
                    'issue_codes'     => $allissuecodes,
                    'used_triggers'   => $result['used_triggers'] ?? [],
                ];
            }

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
                ($status === 'soft_block' || $this->has_confirmable_prevalidation_issues($allissuecodes))
                && !$hasclarificationissues
                && !empty($result['commands'])
            ) {
                $confirmcommands = !empty($preparedcommands) ? $preparedcommands : (array)$result['commands'];
                $confirmcommands = $this->apply_confirmable_overrides($confirmcommands, $allissues);
                // Soft-confirmable: show confirmation_request with augmented message.
                return [
                    'response_type'   => 'confirmation_request',
                    'message'         => $validationmessage !== '' ? $validationmessage : $result['message'],
                    'commands'        => $confirmcommands,
                    'queue_item_ids'  => array_values(array_filter(array_map('strval', (array)($result['queue_item_ids'] ?? [])))),
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
        $result['commands']      = $preparedcommands;
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
     * Apply the canonical preflight decision to queued mutating items.
     *
     * @param int $threadid
     * @param array<int,string> $queueitemids
     * @param string $status
     * @param array<int,string> $issuecodes
     * @param array<int,string> $errors
     * @param array<string,mixed> $v2result
     * @param bool $autoconfirmmode
     * @return void
     */
    private function apply_preflight_queue_decision(
        int $threadid,
        array $queueitemids,
        string $status,
        array $issuecodes,
        array $errors,
        array $v2result,
        bool $autoconfirmmode
    ): void {
        $queueitemids = array_values(array_filter(array_map('strval', $queueitemids)));
        if (empty($queueitemids)) {
            return;
        }

        $status = trim($status);
        $targetstatus = 'failed';
        $errorclass = '';
        $extrafields = [];
        $message = trim(implode(' ', array_values(array_unique(array_map('strval', $errors)))));

        if ($status === 'pass') {
            $targetstatus = $autoconfirmmode ? 'ready' : 'blocked_confirmation';
        } else if ($status === 'soft_block') {
            $targetstatus = 'blocked_confirmation';
        } else if ($status === 'retry_hint') {
            $targetstatus = 'retry_waiting';
            $errorclass = 'preflight_retry';
        } else {
            $targetstatus = 'failed';
            $errorclass = 'preflight_block';
        }

        foreach ($queueitemids as $queueitemid) {
            $item = $this->queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item)) {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }
            if ((string)($item['status'] ?? '') === 'failed' && !empty((array)($item['issue_codes'] ?? []))) {
                continue;
            }

            if ($targetstatus === 'retry_waiting') {
                $currentretrycount = max(0, (int)($item['preflight_retry_count'] ?? $item['retry_count'] ?? 0));
                $nextretrycount = $currentretrycount + 1;
                $retryafterms = max(1, (int)($v2result['retry_after_ms'] ?? 0));
                if ($retryafterms <= 1) {
                    $retryafterms = min(4000, 500 * (2 ** max(0, min(8, $nextretrycount - 1))));
                }
                $extrafields = [
                    'retry_count' => $nextretrycount,
                    'preflight_retry_count' => $nextretrycount,
                    'retry_after_ms' => $retryafterms,
                    'backoff_ms' => $retryafterms,
                    'next_retry_at' => time() + (int)ceil($retryafterms / 1000),
                ];
            }

            $this->queuesvc->update_status(
                $threadid,
                $queueitemid,
                $targetstatus,
                $issuecodes,
                $errorclass,
                $message,
                $extrafields
            );
        }
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
            if (isset($codeset['MISSING_LOCATION_CONFIRM_REQUIRED']) || isset($codeset['LOCATION_NOT_FOUND_POSSIBLE'])) {
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

    /**
     * Attach deterministic execution guard tokens to mutating prepared commands.
     *
     * @param array<int,array<string,mixed>> $commands
     * @param int $contextid
     * @return array<int,array<string,mixed>>
     */
    private function apply_execution_guard_tokens(array $commands, int $contextid): array {
        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $task = $this->registry->get_task($taskname);
            if ($task === null || $task->is_read_only()) {
                unset($command['guard_token']);
                continue;
            }

            $preparedinput = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $command['guard_token'] = \bookingextension_agent\local\wbagent\services\preflight_execution_gate::build_guard_token(
                $taskname,
                $contextid,
                $preparedinput
            );
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
        $contextid = (int)\context_module::instance($cmid)->id;
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
                $command['input'] = $anonymizer->deanonymize_command_input_for_active_user(
                    $contextid,
                    $userid,
                    $input
                );
            }
            unset($command);
        }

        $idempotencykey = hash(
            'sha256',
            $userid . ':' . $contextid . ':' . $threadid
                . ':' . json_encode($preparedcommands) . ':' . microtime(true)
        );
        $runid = $this->store->create_run(
            $threadid,
            $userid,
            $contextid,
            $idempotencykey,
            $preparedcommands
        );

        try {
            $this->store->update_run_status($runid, 'running');
            $feedback = $this->with_output_language($outputlang, function () use (
                $preparedcommands,
                $queueitemids,
                $cmid,
                $contextid,
                $userid,
                $idempotencykey,
                $runid,
                $threadid,
                $outputlang
            ): array {
                $exec = new executor($this->registry, $this->store, $this->authz);
                $rawresults = $exec->execute_commands(
                    $preparedcommands,
                    $contextid,
                    $userid,
                    $idempotencykey,
                    $runid
                );
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

                    // Atomically acquire the running slot (checks + sets in one DB transaction).
                    if (!$this->queuesvc->try_mark_running($threadid, $queueitemid)) {
                        // Slot already occupied by a concurrent request; skip status update.
                        continue;
                    }
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
            $observationledger = new execution_observation_ledger($this->store);
            $observationledger->append_from_results(
                $threadid,
                (array)$results,
                [
                    'source' => 'readonly_execute',
                    'run_id' => (int)$runid,
                    'commands' => $preparedcommands,
                    'queue_item_ids' => $queueitemids,
                ]
            );
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
        $createoptiontask = $this->resolve_task_name_by_suffix('create_option');

        if (
            in_array('TEACHER_USER_NOT_FOUND', $issuecodes, true)
            && $createoptiontask !== ''
            && in_array($createoptiontask, $attemptedtasks, true)
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
     * Ensure create-option commands include duplicate_title override after explicit user confirmation.
     *
     * @param  array $result
     * @return array
     */
    private function apply_duplicate_title_override(array $result): array {
        if (!in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            return $result;
        }
        $createoptiontask = $this->resolve_task_name_by_suffix('create_option');
        if ($createoptiontask === '') {
            return $result;
        }
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }
        $changed = false;
        foreach ($commands as $idx => $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== $createoptiontask) {
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
     * Prepend a matching create-user task when user explicitly allows creating missing teacher accounts.
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
        $createusertask = $this->resolve_task_name_by_suffix('create_user');
        $createoptiontask = $this->resolve_task_name_by_suffix('create_option');

        if ((string)($result['response_type'] ?? '') !== 'confirmation_request') {
            return $result;
        }
        if ($createusertask === '' || $createoptiontask === '') {
            return $result;
        }
        if (!$this->user_allows_missing_user_autocreate($usermessage)) {
            return $result;
        }

        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($result['issue_codes'] ?? [])
        );
        if (!in_array('TEACHER_USER_NOT_FOUND', $issuecodes, true)) {
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
            if ((string)($command['task'] ?? '') === $createusertask) {
                return $result;
            }
        }

        $teacherquery = '';
        foreach ($commands as $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== $createoptiontask) {
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
            'task'    => $createusertask,
            'version' => 1,
            'input'   => ['userquery' => $teacherquery, 'outputlang' => $outputlang],
        ]);
        $result['commands'] = array_values($commands);
        return $result;
    }

    /**
     * Resolve a task name by exact suffix match (e.g. "create_option").
     *
     * @param string $suffix
     * @return string
     */
    private function resolve_task_name_by_suffix(string $suffix): string {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return '';
        }

        $needle = '.' . $suffix;
        foreach (array_keys($this->registry->get_tasks()) as $taskname) {
            if (!is_string($taskname) || $taskname === '') {
                continue;
            }
            if (str_ends_with($taskname, $needle)) {
                return $taskname;
            }
        }

        return '';
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
     * Extract a useful option search query from user text.
     *
     * @param string $message
     * @return string
     */
    private function extract_option_search_query(string $message): string {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        $quoted = '';
        if (preg_match('/["“”„\']([^"“”„\']{3,160})["“”„\']/', $message, $matches)) {
            $quoted = trim((string)($matches[1] ?? ''));
        }
        if ($quoted !== '') {
            return $quoted;
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
