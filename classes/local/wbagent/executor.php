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
 * Agent command executor.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use context_module;
use core\context;
use bookingextension_agent\local\wbagent\interfaces\agent_executor;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\services\discovery\skill_discovery_service;
use bookingextension_agent\local\wbagent\services\introspection\skill_introspection_service;
use bookingextension_agent\local\wbagent\services\preflight_execution_gate;
use bookingextension_agent\local\wbagent\services\security\authorization_service;

/**
 * Dispatches interpreter-validated commands to the appropriate skill.
 *
 * Commands reaching execute_commands() are expected to carry prepared_input
 * plus a deterministic guard_token for mutating skills, both produced during
 * decision-service preflight. The executor therefore performs only lightweight
 * structural checks plus guard verification and does not re-run DB validation.
 *
 * Enforces idempotency, capability checks, and produces structured per-command
 * results.  Partial success is allowed; no rollback is performed.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class executor implements agent_executor {
    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param skill_registry         $registry
     * @param conversation_store    $store
     * @param authorization_service $authz
     */
    public function __construct(
        skill_registry $registry,
        conversation_store $store,
        authorization_service $authz
    ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->authz    = $authz;
    }

        /**
         * Execute a list of validated commands.
         *
         * Commands are expected to carry prepared_input (resolved IDs, normalised values)
         * and, for mutating skills, a guard_token produced during decision-service
         * preflight. The executor MUST NOT repeat DB-resolution logic.
         *
         * @param array  $commands
         * @param int    $contextid
         * @param int    $userid
         * @param string $idempotencykey
         * @param int    $runid
         * @return array
         */
    public function execute_commands(array $commands, int $contextid, int $userid, string $idempotencykey, int $runid): array {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        // Cmid is only needed by booking-style skills (e.g. preview-option memory);
        // 0 outside a module context.
        $cmid = ($context instanceof context_module) ? (int)$context->instanceid : 0;
        // Re-check authorization (always re-verify in adhoc context).
        $this->authz->require_use_capability($userid, $contextid);
        $this->authz->require_valid_context($contextid);
        $evaluator = new skill_executability_evaluator($this->registry, $this->authz);

        // Idempotency guard.
        if ($this->store->run_exists_other_than($idempotencykey, $runid)) {
            return [[
                'status' => 'skipped',
                'detail' => get_string('agent_executor_run_already_executed', 'bookingextension_agent'),
                'issue_codes' => ['EXECUTOR_ALREADY_EXECUTED'],
                'idempotency_reason' => 'EXECUTOR_RUN_EXISTS',
                'resultid' => null,
            ]];
        }

        $results = [];
        $run = $this->store->get_run($runid);
        $threadid = (int)($run->threadid ?? 0);
        $anonymizer = new privacy_anonymizer($this->store);
        foreach ($commands as $cmd) {
            $skillname = $cmd['skill'] ?? '';
            $input     = $cmd['input'] ?? [];
            if ($threadid > 0 && is_array($input)) {
                // Safety-net deanonymization: any remaining ANON tokens not resolved
                // earlier are resolved here (e.g. commands arriving via adhoc tasks
                // that bypassed the decision service preflight).
                $input = $anonymizer->deanonymize_command_input($threadid, $input);
            }

            // Fail closed: if a command parameter still carries an unresolved ANON_USER token after
            // de-anonymization (e.g. a placeholder surfaced from another thread/turn), do not execute
            // the skill with a meaningless placeholder - ask the planner to restate instead.
            if (
                $threadid > 0
                && is_array($input)
                && $anonymizer->should_anonymize_llm_backend_data()
                && $anonymizer->has_unresolved_anon_tokens($input)
            ) {
                $results[] = [
                    'status' => 'error',
                    'detail' => get_string('agent_privacy_unresolved_reference', 'bookingextension_agent'),
                    'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                    'resultid' => null,
                    'skill' => $skillname,
                ];
                continue;
            }

            $skill = $this->registry->get_skill($skillname);
            if (!$skill) {
                $results[] = [
                    'status' => 'error',
                    'detail' => get_string('agent_executor_skill_not_registered', 'bookingextension_agent', $skillname),
                    'resultid' => null,
                ];
                continue;
            }

            $evaluation = $evaluator->evaluate_skill((string)$skillname, $userid, $contextid);
            if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
                $denyreason = trim((string)($evaluation['deny_reason'] ?? skill_contract_validator::DENY_NOT_REGISTERED));
                $denymessage = skill_contract_validator::get_user_facing_deny_message($denyreason, (string)$skillname);
                $results[] = [
                    'status' => 'error',
                    'detail' => $denymessage
                        ?? ('Skill denied by governance gate (' . $denyreason . '): ' . (string)$skillname),
                    'resultid' => null,
                    'deny_reason' => $denyreason,
                    'issue_codes' => [
                        $denyreason === skill_contract_validator::DENY_REQUIRES_PRO
                            ? 'REQUIRES_PRO'
                            : 'SKILL_DENIED',
                    ],
                    'diagnostics' => (array)($evaluation['diagnostics'] ?? []),
                ];
                continue;
            }

            // Operating context for this command: the cross-context target resolved during
            // decision-service preflight, or the ambient context when none (today's default).
            // Gate 1 (governance, above) stays at the ambient context; Gate 2 (the skill's own
            // native capability check) and execution run at the operating context.
            $operatingcontextid = (int)($cmd['operating_contextid'] ?? $contextid);

            // Lightweight structural guard only — no DB access.
            // Deep validation already happened in decision-service preflight.
            $structural = $skill->check_structure($input);
            if (!($structural['valid'] ?? true)) {
                $detail = implode('; ', (array)($structural['errors'] ?? []));
                $entry = [
                    'status' => 'error',
                    'detail' => get_string('agent_executor_structural_failure', 'bookingextension_agent', $detail),
                    'resultid' => null,
                ];
                if (!empty($structural['observation_full']) && is_string($structural['observation_full'])) {
                    $entry['observation_full'] = trim($structural['observation_full']);
                }
                $results[] = $entry;
                continue;
            }

            if (!$skill->is_read_only()) {
                $guardtoken = trim((string)($cmd['guard_token'] ?? ''));
                if ($guardtoken === '') {
                    $results[] = [
                        'status' => 'error',
                        'detail' => 'Execution guard missing for mutating command.',
                        'issue_codes' => ['EXECUTION_GUARD_MISSING'],
                        'resultid' => null,
                        'skill' => $skillname,
                    ];
                    continue;
                }

                if (!preflight_execution_gate::verify_guard_token($guardtoken, (string)$skillname, $operatingcontextid, $input)) {
                    $results[] = [
                        'status' => 'error',
                        'detail' => 'Execution guard mismatch for mutating command.',
                        'issue_codes' => ['EXECUTION_GUARD_MISMATCH'],
                        'resultid' => null,
                        'skill' => $skillname,
                    ];
                    continue;
                }
            }

            // Hand the current thread id to skills that need it (duck-typed, executor stays
            // skill-agnostic) - e.g. recall_memory re-anchors recalled tokens into this thread's map.
            if (method_exists($skill, 'set_runtime_threadid')) {
                $skill->set_runtime_threadid($threadid);
            }

            // Inject the engine-provided introspection/discovery services (duck-typed) so the
            // list_skills/search_skills skills depend on contracts, not on engine machinery.
            if (method_exists($skill, 'set_introspection_provider')) {
                $skill->set_introspection_provider(new skill_introspection_service());
            }
            if (method_exists($skill, 'set_discovery_provider')) {
                $skill->set_discovery_provider(new skill_discovery_service());
            }

            $result = $skill->execute($input, $operatingcontextid, $userid);
            if (is_array($result) && !isset($result['skill'])) {
                $result['skill'] = $skillname;
            }
            if (is_array($result) && !isset($result['executed_input']) && is_array($input)) {
                // Keep normalized executed input in loop results so follow-up planner turns
                // can deterministically avoid repeating already completed commands.
                $result['executed_input'] = $this->build_safe_executed_input($skillname, $input);
            }
            if (
                !empty($result['previewoptionids'])
                && is_array($result['previewoptionids'])
                && method_exists($skill, 'remember_preview_options')
            ) {
                // The skill owns its domain-specific preview-option memory (duck-typed, optional).
                // The executor stays generic and carries no booking knowledge.
                $skill->remember_preview_options(
                    array_map('intval', $result['previewoptionids']),
                    $cmid,
                    $userid
                );
            }
            // Resolve the skill's preview here, on the RAW result, while its bespoke fields
            // (doc_path, previewoptionids, …) are still present. The result is a self-contained data
            // block {type, html, js_module, payload} that travels downstream as the single source of
            // truth for previews — so result sanitization never has to whitelist per-skill fields and
            // preview_passthrough no longer re-derives anything. Best-effort: never break execution.
            if (is_array($result) && method_exists($skill, 'get_result_preview')) {
                try {
                    $preview = $skill->get_result_preview($result, $contextid, $userid);
                    if (is_array($preview) && trim((string)($preview['type'] ?? '')) !== '') {
                        $result['preview'] = $preview;
                    }
                } catch (\Throwable $e) {
                    debugging(
                        'wbagent: get_result_preview failed for ' . $skillname . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Build a result-safe echo of the executed input.
     *
     * @param string $skillname
     * @param array $input
     * @return array
     */
    private function build_safe_executed_input(string $skillname, array $input): array {
        $skill = $this->registry->get_skill($skillname);
        $allowedkeys = [];
        if ($skill !== null) {
            $schema = $skill->get_schema();
            $allowedkeys = array_fill_keys(array_keys((array)($schema['properties'] ?? [])), true);
        }

        $safe = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || ($skill !== null && !isset($allowedkeys[$key]))) {
                continue;
            }
            $safe[$key] = $value;
        }

        // Duck-typed: skills that carry sensitive fields declare get_sensitive_input_fields().
        // Executor stays skill-agnostic — no hardcoded field names per skill name.
        if ($skill !== null && method_exists($skill, 'get_sensitive_input_fields')) {
            foreach ((array)$skill->get_sensitive_input_fields() as $fieldname) {
                unset($safe[(string)$fieldname]);
            }
        }

        return $safe;
    }
}
