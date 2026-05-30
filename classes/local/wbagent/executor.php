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
use bookingextension_agent\local\wbagent\services\localized_string_service;
use bookingextension_agent\local\wbagent\services\preflight_execution_gate;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\services\spawn_contract_service;

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
    /** @var int Maximum number of follow-up suggestions returned per result. */
    private const MAX_FOLLOW_UP_SUGGESTIONS = 3;

    /** @var int Maximum recursion depth for spawn command execution. */
    private const MAX_SPAWN_DEPTH = 4;

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
        $spawncontracts = new spawn_contract_service();

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
            $result = $this->enrich_result_with_follow_ups($taskname, $input, $result);
            if (is_array($result)) {
                $result = $spawncontracts->normalize_task_result((string)$taskname, $result);
            }
            $results[] = $result;

            if (is_array($result)) {
                $spawnresults = $this->execute_spawn_chain(
                    (string)$taskname,
                    $result,
                    $contextid,
                    $userid,
                    $spawncontracts,
                    $evaluator,
                    1
                );
                if (!empty($spawnresults)) {
                    $results = array_merge($results, $spawnresults);
                }
            }
        }

        return $results;
    }

    /**
     * Execute spawned child commands from one parent task result.
     *
     * @param string $parenttask
     * @param array<string,mixed> $parentresult
     * @param int $contextid
     * @param int $userid
     * @param spawn_contract_service $spawncontracts
     * @param task_executability_evaluator $evaluator
     * @param int $depth
     * @return array<int,array<string,mixed>>
     */
    private function execute_spawn_chain(
        string $parenttask,
        array $parentresult,
        int $contextid,
        int $userid,
        spawn_contract_service $spawncontracts,
        task_executability_evaluator $evaluator,
        int $depth
    ): array {
        if ($depth > self::MAX_SPAWN_DEPTH) {
            return [[
                'task' => $parenttask,
                'status' => 'error',
                'detail' => 'Spawn chain depth exceeded the safety limit.',
                'issue_codes' => ['SPAWN_DEPTH_EXCEEDED'],
                'spawn_depth' => $depth,
                'resultid' => null,
            ]];
        }

        $spawncommands = is_array($parentresult['spawn_commands'] ?? null)
            ? (array)$parentresult['spawn_commands']
            : [];
        if (empty($spawncommands)) {
            return [];
        }

        $availableoutputs = is_array($parentresult['produced_outputs'] ?? null)
            ? (array)$parentresult['produced_outputs']
            : [];

        $results = [];
        foreach ($spawncommands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $childtask = trim((string)($command['task'] ?? ''));
            if ($childtask === '') {
                continue;
            }

            $childinput = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $bindings = is_array($command['output_bindings'] ?? null) ? (array)$command['output_bindings'] : [];
            $bindingresolution = $spawncontracts->apply_output_bindings($childinput, $bindings, $availableoutputs);
            $childinput = (array)($bindingresolution['input'] ?? []);
            $bindingerrors = (array)($bindingresolution['errors'] ?? []);
            if (!empty($bindingerrors)) {
                $results[] = [
                    'task' => $childtask,
                    'status' => 'error',
                    'detail' => implode('; ', $bindingerrors),
                    'issue_codes' => ['SPAWN_OUTPUT_BINDING_MISSING'],
                    'parent_task' => $parenttask,
                    'spawn_depth' => $depth,
                    'resultid' => null,
                ];
                continue;
            }

            $task = $this->registry->get_task($childtask);
            if ($task === null) {
                $results[] = [
                    'task' => $childtask,
                    'status' => 'error',
                    'detail' => get_string('agent_executor_task_not_registered', 'bookingextension_agent', $childtask),
                    'issue_codes' => ['SPAWN_TASK_NOT_REGISTERED'],
                    'parent_task' => $parenttask,
                    'spawn_depth' => $depth,
                    'resultid' => null,
                ];
                continue;
            }

            if (!$task->is_read_only()) {
                $results[] = [
                    'task' => $childtask,
                    'status' => 'blocked_confirmation',
                    'detail' => 'Spawned mutating command requires explicit confirmation.',
                    'issue_codes' => ['SPAWN_MUTATION_REQUIRES_CONFIRMATION'],
                    'parent_task' => $parenttask,
                    'spawn_depth' => $depth,
                    'resultid' => null,
                ];
                continue;
            }

            $evaluation = $evaluator->evaluate_task($childtask, $userid, $contextid);
            if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
                $denyreason = trim((string)($evaluation['deny_reason'] ?? task_contract_validator::DENY_NOT_REGISTERED));
                $results[] = [
                    'task' => $childtask,
                    'status' => 'error',
                    'detail' => 'Spawn task denied by governance gate (' . $denyreason . ').',
                    'issue_codes' => ['SPAWN_TASK_DENIED'],
                    'deny_reason' => $denyreason,
                    'diagnostics' => (array)($evaluation['diagnostics'] ?? []),
                    'parent_task' => $parenttask,
                    'spawn_depth' => $depth,
                    'resultid' => null,
                ];
                continue;
            }

            $structure = $task->check_structure($childinput);
            if (!($structure['valid'] ?? true)) {
                $results[] = [
                    'task' => $childtask,
                    'status' => 'error',
                    'detail' => implode('; ', (array)($structure['errors'] ?? [])),
                    'issue_codes' => ['SPAWN_STRUCTURE_INVALID'],
                    'parent_task' => $parenttask,
                    'spawn_depth' => $depth,
                    'resultid' => null,
                ];
                continue;
            }

            $preflight = $task->preflight($childinput, $contextid, $userid);
            if (trim((string)$preflight->status) !== 'pass') {
                $results[] = [
                    'task' => $childtask,
                    'status' => 'error',
                    'detail' => 'Spawn late-preflight blocked child execution.',
                    'issue_codes' => array_values(array_unique(array_merge(
                        ['SPAWN_LATE_PREFLIGHT_BLOCKED'],
                        (array)$preflight->issuecodes
                    ))),
                    'blocking_layer' => (string)$preflight->blockinglayer,
                    'retry_after_ms' => (int)$preflight->retryafterms,
                    'parent_task' => $parenttask,
                    'spawn_depth' => $depth,
                    'resultid' => null,
                ];
                continue;
            }

            $childresult = $task->execute((array)$preflight->preparedinput, $contextid, $userid);
            if (!is_array($childresult)) {
                $childresult = [
                    'task' => $childtask,
                    'status' => 'executed',
                    'results' => [],
                ];
            }
            if (!isset($childresult['task'])) {
                $childresult['task'] = $childtask;
            }
            if (!isset($childresult['executed_input'])) {
                $childresult['executed_input'] = $this->build_safe_executed_input($childtask, (array)$preflight->preparedinput);
            }
            $childresult['parent_task'] = $parenttask;
            $childresult['spawn_depth'] = $depth;
            $childresult = $spawncontracts->normalize_task_result($childtask, $childresult);
            $results[] = $childresult;

            $grandchildren = $this->execute_spawn_chain(
                $childtask,
                $childresult,
                $contextid,
                $userid,
                $spawncontracts,
                $evaluator,
                $depth + 1
            );
            if (!empty($grandchildren)) {
                $results = array_merge($results, $grandchildren);
            }
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

    /**
     * Add consistent follow-up guidance and supported next-step suggestions.
     *
     * @param string $taskname
     * @param array $input
     * @param array $result
     * @return array
     */
    private function enrich_result_with_follow_ups(string $taskname, array $input, array $result): array {
        if (empty($result['status']) || !in_array((string)$result['status'], ['executed', 'skipped', 'error'], true)) {
            return $result;
        }

        $limit = $this->get_follow_up_suggestions_limit();
        if ($limit <= 0) {
            unset($result['suggestions'], $result['followupmessage']);
            return $result;
        }

        $lang = trim((string)($result['outputlang'] ?? $input['outputlang'] ?? ''));
        $suggestions = $result['suggestions'] ?? [];
        if (!is_array($suggestions) || empty($suggestions)) {
            $suggestions = $this->build_follow_up_suggestions($taskname, $lang, $limit, $result);
        } else {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        if (empty($suggestions)) {
            return $result;
        }

        $result['suggestions'] = $suggestions;
        if (empty($result['followupmessage'])) {
            $result['followupmessage'] = localized_string_service::get('ai_followup_offer', 'bookingextension_agent', null, $lang);
        }

        return $result;
    }

    /**
     * Build a short list of supported next-step suggestions for the current task.
     * @param string $taskname
     * @param string $lang
     * @param int $limit
     * @param array $result
     *
     * @return array
     *
     */
    private function build_follow_up_suggestions(string $taskname, string $lang, int $limit, array $result = []): array {
        $suggestions = [];
        $seen = [];

        // Prefer suggestions grounded in actual result payload (docs/options/properties/etc.).
        $this->append_result_driven_suggestions($suggestions, $seen, $taskname, $lang, $result);

        if (count($suggestions) >= $limit) {
            return array_slice($suggestions, 0, $limit);
        }

        // Fill remaining slots with task-level fallbacks.
        $candidates = $this->get_follow_up_candidate_tasks($taskname);
        $tasks = $this->registry->get_tasks();
        foreach ($candidates as $candidatetask) {
            if (!isset($tasks[$candidatetask])) {
                continue;
            }

            $label = $this->get_task_label($candidatetask, $lang);
            $query = localized_string_service::get('ai_followup_suggestion_query', 'bookingextension_agent', $label, $lang);
            $this->append_suggestion($suggestions, $seen, $candidatetask, $label, $query);

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Resolve configured follow-up suggestion count.
     *
     * 0 disables follow-up suggestions entirely.
     *
     * @return int
     */
    private function get_follow_up_suggestions_limit(): int {
        $configured = get_config('bookingextension_agent', 'aifollowupsuggestionscount');
        if ($configured === false) {
            return self::MAX_FOLLOW_UP_SUGGESTIONS;
        }

        return max(0, (int)$configured);
    }

    /**
     * Add context-aware follow-up suggestions derived from the current task result.
     *
     * @param array $suggestions
     * @param array $seen
     * @param string $taskname
     * @param string $lang
     * @param array $result
     * @return void
     */
    private function append_result_driven_suggestions(
        array &$suggestions,
        array &$seen,
        string $taskname,
        string $lang,
        array $result
    ): void {
        // Keep result-driven follow-ups generic and registry-driven.
        $contexttoken = '';
        foreach (['options', 'docs', 'users', 'courses', 'actions', 'properties'] as $listkey) {
            $contexttoken = $this->get_first_row_field($result, $listkey, ['label', 'name', 'title', 'fullname', 'path']);
            if ($contexttoken !== '') {
                break;
            }
        }

        if ($contexttoken === '') {
            return;
        }

        foreach ($this->get_follow_up_candidate_tasks($taskname) as $candidatetask) {
            if ($candidatetask === $taskname) {
                continue;
            }

            $label = $this->get_task_label($candidatetask, $lang);
            $query = localized_string_service::get('ai_followup_suggestion_query', 'bookingextension_agent', $label, $lang) . ' (' . $contexttoken . ')';
            $this->append_suggestion($suggestions, $seen, $candidatetask, $label, $query);
            break;
        }
    }

    /**
     * Append a suggestion if it is complete and not already present.
     *
     * @param array $suggestions
     * @param array $seen
     * @param string $task
     * @param string $label
     * @param string $query
     * @return void
     */
    private function append_suggestion(array &$suggestions, array &$seen, string $task, string $label, string $query): void {
        $task = trim($task);
        $label = trim($label);
        $query = trim($query);
        if ($task === '' || $label === '' || $query === '') {
            return;
        }

        $signature = $task . '|' . $query;
        if (isset($seen[$signature])) {
            return;
        }

        $seen[$signature] = true;
        $suggestions[] = [
            'task' => $task,
            'label' => $label,
            'query' => $query,
        ];
    }

    /**
     * Read a preferred text value from the first row of a result list.
     *
     * @param array $result
     * @param string $listkey
     * @param array $fieldcandidates
     * @return string
     */
    private function get_first_row_field(array $result, string $listkey, array $fieldcandidates): string {
        $rows = $result[$listkey] ?? [];
        if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
            return '';
        }

        foreach ($fieldcandidates as $field) {
            $value = trim((string)($rows[0][$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Return ordered task candidates for follow-up suggestions.
     *
     * @param string $taskname
     * @return array<int,string>
     */
    private function get_follow_up_candidate_tasks(string $taskname): array {
        $tasks = array_keys($this->registry->get_tasks());
        if (empty($tasks)) {
            return [];
        }

        $iscurrentreadonly = $this->registry->is_read_only_task($taskname);
        $currentprefix = $this->task_namespace_prefix($taskname);

        usort($tasks, function (string $left, string $right) use ($taskname, $iscurrentreadonly, $currentprefix): int {
            $leftscore = $this->task_follow_up_score($left, $taskname, $iscurrentreadonly, $currentprefix);
            $rightscore = $this->task_follow_up_score($right, $taskname, $iscurrentreadonly, $currentprefix);
            if ($leftscore !== $rightscore) {
                return $rightscore <=> $leftscore;
            }
            return strcmp($left, $right);
        });

        return array_values(array_filter($tasks, static function (string $candidate) use ($taskname): bool {
            return $candidate !== $taskname;
        }));
    }

    /**
     * Compute generic ranking score for follow-up candidate tasks.
     *
     * @param string $candidate
     * @param string $taskname
     * @param bool $iscurrentreadonly
     * @param string $currentprefix
     * @return int
     */
    private function task_follow_up_score(
        string $candidate,
        string $taskname,
        bool $iscurrentreadonly,
        string $currentprefix
    ): int {
        if ($candidate === $taskname) {
            return -100;
        }

        $score = 0;
        if ($this->registry->is_read_only_task($candidate) === $iscurrentreadonly) {
            $score += 2;
        }

        if ($this->task_namespace_prefix($candidate) === $currentprefix) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Return task namespace prefix before first dot.
     *
     * @param string $taskname
     * @return string
     */
    private function task_namespace_prefix(string $taskname): string {
        $parts = explode('.', $taskname, 2);
        return trim((string)($parts[0] ?? ''));
    }

    /**
     * Resolve a user-facing label for a task, honoring optional language overrides.
     *
     * @param string $taskname
     * @param string $lang
     * @return string
     */
    private function get_task_label(string $taskname, string $lang): string {
        $task = $this->registry->get_task($taskname);
        if ($task) {
            $schema = $task->get_schema();
            $description = trim((string)($schema['description'] ?? ''));
            if ($description !== '') {
                return $this->truncate_label($description);
            }
        }

        return $taskname;
    }

    /**
     * Build a compact task label from a longer description.
     *
     * @param string $description
     * @return string
     */
    private function truncate_label(string $description): string {
        $description = trim($description);
        if (strlen($description) <= 96) {
            return $description;
        }

        $truncated = substr($description, 0, 96);
        $lastspace = strrpos($truncated, ' ');
        if ($lastspace !== false && $lastspace >= 40) {
            $truncated = substr($truncated, 0, $lastspace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B,.;:") . '...';
    }
}
