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

namespace bookingextension_agent\local\wbagent\services\decision;

use core_text;
use bookingextension_agent\local\wbagent\booking_issue_code_provider;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\executor;
use bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\queue\observation_builder;
use bookingextension_agent\local\wbagent\services\execution\execution_feedback_service;
use bookingextension_agent\local\wbagent\services\execution_observation_ledger;
use bookingextension_agent\local\wbagent\services\language_policy_service;
use bookingextension_agent\local\wbagent\services\localized_string_service;
use bookingextension_agent\local\wbagent\services\preflight_pipeline;
use bookingextension_agent\local\wbagent\services\queue_transition_service;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\services\trigger_result_util;
use bookingextension_agent\local\wbagent\services\pending_intent_service;
use bookingextension_agent\local\wbagent\services\pending_queue_command_service;

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

    /** Trigger id: user allows creating missing user in confirmation flow. */
    private const TRIGGER_ALLOW_MISSING_USER_AUTOCREATE = 'booking.create_user_allowed_if_missing';

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

    /** @var pending_intent_service */
    private pending_intent_service $pendingintentsvc;

    /** @var queue_transition_service */
    private queue_transition_service $queuetransitionsvc;

    /** @var pending_queue_command_service */
    private pending_queue_command_service $pendingqueuecommandsvc;

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
        $this->pendingintentsvc = new pending_intent_service($store);
        $this->queuetransitionsvc = new queue_transition_service();
        $this->pendingqueuecommandsvc = new pending_queue_command_service($this->queuesvc);
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
        // 1. Preview shortcut: if the user asked for a preview and one is available.
        if ($previewoptionid > 0 && trigger_result_util::has_trigger($result, 'core.is_preview_request')) {
            return [
                'response_type'             => 'clarification',
                'message'                   => localized_string_service::get(
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
        $pendingintent = $this->pendingintentsvc->get($threadid);
        if ($pendingintent !== null) {
            if (trigger_result_util::has_trigger($result, self::TRIGGER_DISCARD_PENDING_CONFIRMATION)) {
                $this->pendingintentsvc->clear($threadid);
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
            && trigger_result_util::has_trigger($result, 'core.is_confirmation_message')
        ) {
            $result['response_type'] = self::RESPONSE_TYPE_CONFIRM_PENDING;
        }

        // 3. Handle explicit user confirmation of pending intent.
        if ((string)($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRM_PENDING) {
            return $this->handle_confirm_pending($result, $threadid, $cmid, $userid, $outputlang);
        }

        // 5. Safety: block accidental mutation carry-over on lookup requests.
        if (
            trigger_result_util::has_trigger($result, 'core.is_lookup_request')
            && (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST)
            && $this->has_mutating_commands($result)
        ) {
            return [
                'response_type'   => self::RESPONSE_TYPE_CLARIFICATION,
                'message'         => localized_string_service::get(
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
        }

        // 8. Run preflight on confirmation commands: resolve entities, detect conflicts,
        // update commands to carry prepared_input, route based on preflight result.
        if (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST && !empty($result['commands'])) {
            $result = $this->handle_preflight($result, $threadid, $cmid, $userid, $outputlang);
        }

        // 9. Store / clear pending intent.
        if (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST && !empty($result['commands'])) {
            $result['pending_confirmation_code'] = $this->persist_pending_intent_pointer(
                $threadid,
                $userid,
                $contextid,
                $result['queue_item_ids'] ?? []
            );
        } else {
            $this->pendingintentsvc->clear($threadid);
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
        if (trigger_result_util::has_trigger($result, 'core.is_confirmation_message')) {
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
        $pendingcommands = $this->pendingqueuecommandsvc->build_mutating_commands_from_pending_intent($pendingintent, $threadid);
        $summary = $this->build_pending_intent_summary($pendingcommands, $outputlang);
        $confirmationcode = trim((string)($pendingintent['confirmationcode'] ?? ''));
        $message = $this->localized(
            'ai_pending_intent_resolution_required',
            (object)[
                'action' => $summary !== ''
                    ? $summary
                    : $this->localized('ai_status_confirm_default', null, $outputlang),
                'code' => $confirmationcode !== '' ? $confirmationcode : '-',
            ],
            $outputlang
        );

        return $this->clarification_result_with_context(
            $message,
            $result,
            [
                'attempted_tasks' => array_values(array_unique((array)($result['attempted_tasks'] ?? []))),
                'issue_codes' => array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['PENDING_CONFIRMATION_EXISTS']
                ))),
                'pending_confirmation_code' => $confirmationcode,
            ]
        );
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
     * Build a deterministic fallback message per response type and language.
     *
     * @param  array  $result
     * @param  string $outputlang
     * @return string
     */
    private function build_fallback_message(array $result, string $outputlang = ''): string {
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
                        return localized_string_service::get($key, 'bookingextension_agent', null, $outputlang);
                    }
                }
            }
            return localized_string_service::get('ai_status_confirm_default', 'bookingextension_agent', null, $outputlang);
        }

        if ($responsetype === 'task_call') {
            if ($firsttask !== '') {
                $task = $this->registry->get_task($firsttask);
                if ($task !== null) {
                    $key = (string)($task->get_schema()['fallback_taskcall_string_key'] ?? '');
                    if ($key !== '') {
                        return localized_string_service::get($key, 'bookingextension_agent', null, $outputlang);
                    }
                }
            }
            // Any task not registered in the booking registry (e.g. cross-plugin tasks)
            // falls back to the generic default string.
            return localized_string_service::get('ai_status_taskcall_default', 'bookingextension_agent', null, $outputlang);
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
        $pendingintent = $this->pendingintentsvc->get($threadid);

        if ($pendingintent === null) {
            return $this->build_confirm_pending_no_intent_fallback(
                $result,
                $modelmessage,
                $isplaceholdermessage,
                $outputlang
            );
        }

        // Phase 2: queue is single source of truth; no fallback to stored commands.
        $confirmcommands = $this->pendingqueuecommandsvc->build_mutating_commands_from_pending_intent(
            $pendingintent,
            $threadid
        );
        if (empty($confirmcommands)) {
            return $this->build_confirm_pending_no_intent_fallback(
                $result,
                $modelmessage,
                $isplaceholdermessage,
                $outputlang
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
            return $this->clarification_result_with_context(
                $invalidmessage !== '' ? $invalidmessage
                    : localized_string_service::get('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang),
                $result,
                [
                    'errors' => $preflightresult['errors'] ?? [],
                    'attempted_tasks' => $preflightresult['attempted_tasks'] ?? [],
                    'issue_codes' => $preflightresult['issue_codes'] ?? [],
                ]
            );
        }

        // Use the prepared commands (with resolved inputs) for the pending intent.
        $preparedcommands = $preflightresult['prepared_commands'];
        $queueitemids = $this->normalize_queue_item_ids($pendingintent['queue_item_ids'] ?? []);
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

        $confirmmessage = $this->localized('ai_confirm_pending_intent', null, $outputlang);
        $confirmationcode = $this->persist_pending_intent_pointer(
            $threadid,
            $userid,
            $contextid,
            $queueitemids
        );

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
        if (!is_array($commands) || empty($commands)) {
            return $result;
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
        $queueitemids = $this->normalize_queue_item_ids($result['queue_item_ids'] ?? []);
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread(
            $userid,
            $contextid,
            $threadid
        );
        $this->queuetransitionsvc->apply_preflight_decision(
            $this->queuesvc,
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
                $retrymessage = localized_string_service::get(
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
                // Soft-confirmable: show confirmation_request with augmented message.
                return [
                    'response_type'   => 'confirmation_request',
                    'message'         => $validationmessage !== '' ? $validationmessage : $result['message'],
                    'commands'        => $confirmcommands,
                    'queue_item_ids'  => $this->normalize_queue_item_ids($result['queue_item_ids'] ?? []),
                    'ambiguities'     => [],
                    'errors'          => $blockingerrors,
                    'attempted_tasks' => $attemptedtasks,
                    'issue_codes'     => $allissuecodes,
                    'used_triggers'   => $result['used_triggers'] ?? [],
                ];
            }

            return [
                'response_type'   => 'clarification',
                'message'         => $validationmessage !== '' ? $validationmessage : localized_string_service::get(
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
        }

        return $result;
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

    /**
     * Persist the pending-intent pointer for confirmation-bound queue items.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @param array<int,mixed> $queueitemids
     * @return string
     */
    private function persist_pending_intent_pointer(
        int $threadid,
        int $userid,
        int $contextid,
        array $queueitemids
    ): string {
        $queueitemids = $this->normalize_queue_item_ids($queueitemids);
        return $this->pendingintentsvc->set(
            $threadid,
            $userid,
            $contextid,
            [
                'queue_item_ids' => $queueitemids,
            ]
        );
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
                        $this->queuetransitionsvc->to_failed(
                            $this->queuesvc,
                            $threadid,
                            $queueitemid,
                            'READONLY_EXECUTION_FAILED',
                            $issuecodes,
                            'domain_error',
                            trim((string)($entry['detail'] ?? ''))
                        );
                    } else {
                        $this->queuetransitionsvc->to_succeeded(
                            $this->queuesvc,
                            $threadid,
                            $queueitemid,
                            'READONLY_EXECUTION_SUCCEEDED',
                            $issuecodes
                        );
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
                $message = localized_string_service::get('ai_run_executed', 'bookingextension_agent', null, $outputlang);
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
                $this->queuetransitionsvc->to_failed(
                    $this->queuesvc,
                    $threadid,
                    $queueitemid,
                    'READONLY_PROVIDER_EXCEPTION',
                    [],
                    'provider_error',
                    $e->getMessage()
                );
            }

            $this->store->update_run_status($runid, 'failed', $failureresults);

            return [
                'response_type' => 'error',
                'message'       => localized_string_service::get('ai_provider_error', 'bookingextension_agent', null, $outputlang),
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
            if (in_array($status, ['error', 'failed'], true)) {
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

    /**
     * Build clarification result with contextual carry-over fields.
     *
     * @param string $message
     * @param array $contextresult
     * @param array $overrides
     * @return array
     */
    private function clarification_result_with_context(
        string $message,
        array $contextresult,
        array $overrides = []
    ): array {
        $clarification = $this->clarification_result($message);
        $clarification['ambiguities'] = array_values(array_unique((array)($contextresult['ambiguities'] ?? [])));
        $clarification['errors'] = array_values(array_unique((array)($contextresult['errors'] ?? [])));
        $clarification['used_triggers'] = (array)($contextresult['used_triggers'] ?? []);

        foreach ($overrides as $key => $value) {
            $clarification[$key] = $value;
        }

        return $clarification;
    }

    /**
     * Build clarification fallback when confirm_pending has no usable pending intent.
     *
     * @param array $result
     * @param string $modelmessage
     * @param bool $isplaceholdermessage
     * @param string $outputlang
     * @return array
     */
    private function build_confirm_pending_no_intent_fallback(
        array $result,
        string $modelmessage,
        bool $isplaceholdermessage,
        string $outputlang
    ): array {
        if ($modelmessage !== '' && !$isplaceholdermessage) {
            $fallback = $this->clarification_result($modelmessage);
            $fallback['used_triggers'] = (array)($result['used_triggers'] ?? []);
            if (!empty($result['next_step_intent'])) {
                $fallback['next_step_intent'] = trim((string)$result['next_step_intent']);
            }
            return $fallback;
        }

        return $this->clarification_result(
            localized_string_service::get('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang)
        );
    }

    // -------------------------------------------------------------------------
    // Private: localisation + normalization helpers.

    /**
     * Resolve a localized plugin string.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    private function localized(string $identifier, $a = null, string $lang = ''): string {
        return localized_string_service::get($identifier, 'bookingextension_agent', $a, $lang);
    }

    /**
     * Normalize queue item identifiers to non-empty strings.
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalize_queue_item_ids($value): array {
        return array_values(array_filter(array_map('strval', (array)$value)));
    }
}
