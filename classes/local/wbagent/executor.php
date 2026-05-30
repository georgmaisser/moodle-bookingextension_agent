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
use bookingextension_agent\local\wbagent\services\preflight_execution_gate;
use bookingextension_agent\local\wbagent\services\security\authorization_service;

/**
 * Dispatches interpreter-validated commands to the appropriate task.
 *
 * Commands reaching execute_commands() are expected to carry prepared_input
 * plus a deterministic guard_token for mutating tasks, both produced during
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
    /** @var array<string,array<int,string>> Fields omitted from result echoes for privacy. */
    private const SENSITIVE_EXECUTED_INPUT_SUFFIX_FIELDS = [
        'recall_memory' => ['query'],
    ];

    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param task_registry         $registry
     * @param conversation_store    $store
     * @param authorization_service $authz
     */
    public function __construct(
        task_registry $registry,
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
         * and, for mutating tasks, a guard_token produced during decision-service
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
        try {
            $context = context::instance_by_id($contextid, MUST_EXIST);
            if (!($context instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
        } catch (\Throwable $e) {
            $context = context_module::instance($contextid, MUST_EXIST);
        }
        $cmid = (int)$context->instanceid;
        // Re-check authorization (always re-verify in adhoc context).
        $this->authz->require_use_capability($userid, $contextid);
        $this->authz->require_valid_context($contextid);
        $evaluator = new task_executability_evaluator($this->registry, $this->authz);

        // Idempotency guard.
        if ($this->store->run_exists_other_than($idempotencykey, $runid)) {
            return [[
                'status' => 'skipped',
                'detail' => get_string('agent_executor_run_already_executed', 'bookingextension_agent'),
                'resultid' => null,
            ]];
        }

        $results = [];
        $run = $this->store->get_run($runid);
        $threadid = (int)($run->threadid ?? 0);
        $anonymizer = new privacy_anonymizer($this->store);
        foreach ($commands as $cmd) {
            $taskname = $cmd['task'] ?? '';
            $input    = $cmd['input'] ?? [];
            if ($threadid > 0 && is_array($input)) {
                // Safety-net deanonymization: any remaining ANON tokens not resolved
                // earlier are resolved here (e.g. commands arriving via adhoc tasks
                // that bypassed the decision service preflight).
                $input = $anonymizer->deanonymize_command_input($threadid, $input);
            }

            $task = $this->registry->get_task($taskname);
            if (!$task) {
                $results[] = [
                    'status' => 'error',
                    'detail' => get_string('agent_executor_task_not_registered', 'bookingextension_agent', $taskname),
                    'resultid' => null,
                ];
                continue;
            }

            $evaluation = $evaluator->evaluate_task((string)$taskname, $userid, $contextid);
            if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
                $denyreason = trim((string)($evaluation['deny_reason'] ?? task_contract_validator::DENY_NOT_REGISTERED));
                $results[] = [
                    'status' => 'error',
                    'detail' => 'Task denied by governance gate (' . $denyreason . '): ' . (string)$taskname,
                    'resultid' => null,
                    'deny_reason' => $denyreason,
                    'diagnostics' => (array)($evaluation['diagnostics'] ?? []),
                ];
                continue;
            }

            // Lightweight structural guard only — no DB access.
            // Deep validation already happened in decision-service preflight.
            $structural = $task->check_structure($input);
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

            if (!$task->is_read_only()) {
                $guardtoken = trim((string)($cmd['guard_token'] ?? ''));
                if ($guardtoken === '') {
                    $results[] = [
                        'status' => 'error',
                        'detail' => 'Execution guard missing for mutating command.',
                        'issue_codes' => ['EXECUTION_GUARD_MISSING'],
                        'resultid' => null,
                        'task' => $taskname,
                    ];
                    continue;
                }

                if (!preflight_execution_gate::verify_guard_token($guardtoken, (string)$taskname, $contextid, $input)) {
                    $results[] = [
                        'status' => 'error',
                        'detail' => 'Execution guard mismatch for mutating command.',
                        'issue_codes' => ['EXECUTION_GUARD_MISMATCH'],
                        'resultid' => null,
                        'task' => $taskname,
                    ];
                    continue;
                }
            }

            $result = $task->execute($input, $contextid, $userid);
            if (is_array($result) && !isset($result['task'])) {
                $result['task'] = $taskname;
            }
            if (is_array($result) && !isset($result['executed_input']) && is_array($input)) {
                // Keep normalized executed input in loop results so follow-up planner turns
                // can deterministically avoid repeating already completed commands.
                $result['executed_input'] = $this->build_safe_executed_input($taskname, $input);
            }
            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                $previewmemory = $this->registry->get_preview_option_memory_for_task((string)$taskname);
                if ($previewmemory !== null) {
                    $previewmemory->remember_last_preview_options_for_execute(
                        $userid,
                        $cmid,
                        array_map('intval', $result['previewoptionids'])
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
     * @param string $taskname
     * @param array $input
     * @return array
     */
    private function build_safe_executed_input(string $taskname, array $input): array {
        $task = $this->registry->get_task($taskname);
        $allowedkeys = [];
        if ($task !== null) {
            $schema = $task->get_schema();
            $allowedkeys = array_fill_keys(array_keys((array)($schema['properties'] ?? [])), true);
        }

        $safe = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || ($task !== null && !isset($allowedkeys[$key]))) {
                continue;
            }
            $safe[$key] = $value;
        }

        $tasksuffix = trim((string)(explode('.', $taskname, 2)[1] ?? $taskname));
        foreach (self::SENSITIVE_EXECUTED_INPUT_SUFFIX_FIELDS[$tasksuffix] ?? [] as $fieldname) {
            unset($safe[$fieldname]);
        }

        return $safe;
    }

}
