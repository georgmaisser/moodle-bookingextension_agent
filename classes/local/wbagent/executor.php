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
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use context_module;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\interfaces\agent_executor;
use bookingextension_agent\local\wbagent\privacy_anonymizer;

/**
 * Dispatches interpreter-validated commands to the appropriate task.
 *
 * Commands reaching execute_commands() are expected to carry prepared_input
 * (resolved by task->preflight() in agent_decision_service).  The executor
 * therefore performs ONLY lightweight structural checks (check_structure) and
 * does NOT re-run DB-dependent validation.
 *
 * Enforces idempotency, capability checks, and produces structured per-command
 * results.  Partial success is allowed; no rollback is performed.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class executor implements agent_executor {
    /** @var int Maximum number of follow-up suggestions returned per result. */
    private const MAX_FOLLOW_UP_SUGGESTIONS = 3;

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
      * produced by task->preflight() in agent_decision_service.
      * The executor MUST NOT repeat DB-resolution logic.
      *
      * @param array  $commands
      * @param int    $cmid
      * @param int    $userid
      * @param string $idempotencykey
      * @param int    $runid
      * @return array
      */
    public function execute_commands(array $commands, int $cmid, int $userid, string $idempotencykey, int $runid): array {
        $contextid = (int)context_module::instance($cmid)->id;
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
            // Deep validation was already performed by task->preflight() in agent_decision_service.
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

            $result = $task->execute($input, $cmid, $userid);
            if (is_array($result) && !isset($result['task'])) {
                $result['task'] = $taskname;
            }
            if (is_array($result) && !isset($result['executed_input']) && is_array($input)) {
                // Keep normalized executed input in loop results so follow-up planner turns
                // can deterministically avoid repeating already completed commands.
                $result['executed_input'] = $input;
            }
            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                booking_task_support::remember_last_preview_options_for_user_for_execute(
                    $userid,
                    $cmid,
                    array_map('intval', $result['previewoptionids'])
                );
            }
            $result = $this->enrich_result_with_follow_ups($taskname, $input, $result);
            $results[] = $result;
        }

        return $results;
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
            $result['followupmessage'] = $this->localized_string('ai_followup_offer', null, $lang);
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
            $query = $this->localized_string('ai_followup_suggestion_query', $label, $lang);
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
        $tasks = $this->registry->get_tasks();

        $firstdoc = $this->get_first_row_field($result, 'docs', ['title', 'path']);
        if ($firstdoc !== '' && $taskname !== 'booking.explain_docs_topic' && isset($tasks['booking.explain_docs_topic'])) {
            $this->append_suggestion(
                $suggestions,
                $seen,
                'booking.explain_docs_topic',
                $this->get_task_label('booking.explain_docs_topic', $lang),
                $this->localized_string('ai_docs_explain_followup_query', $firstdoc, $lang)
            );
        }

        $firstoption = $this->get_first_row_field($result, 'options', ['name']);
        if ($firstoption !== '') {
            if (isset($tasks['booking.update_option'])) {
                $this->append_suggestion(
                    $suggestions,
                    $seen,
                    'booking.update_option',
                    $this->get_task_label('booking.update_option', $lang),
                    $this->localized_string('ai_followup_update_option_query', $firstoption, $lang)
                );
            }
            if (isset($tasks['booking.diagnose_booking_issue'])) {
                $this->append_suggestion(
                    $suggestions,
                    $seen,
                    'booking.diagnose_booking_issue',
                    $this->get_task_label('booking.diagnose_booking_issue', $lang),
                    $this->localized_string('ai_followup_diagnose_option_query', $firstoption, $lang)
                );
            }
            if (isset($tasks['booking.search_options'])) {
                $this->append_suggestion(
                    $suggestions,
                    $seen,
                    'booking.search_options',
                    $this->get_task_label('booking.search_options', $lang),
                    $this->localized_string('ai_followup_search_related_options_query', $firstoption, $lang)
                );
            }
        }

        $firstproperty = $this->get_first_row_field($result, 'properties', ['label', 'name']);
        if ($firstproperty !== '' && isset($tasks['booking.create_option'])) {
            $this->append_suggestion(
                $suggestions,
                $seen,
                'booking.create_option',
                $this->get_task_label('booking.create_option', $lang),
                $this->localized_string('ai_followup_create_option_with_property_query', $firstproperty, $lang)
            );
        }

        $firstaction = $this->get_first_row_field($result, 'actions', ['label']);
        if ($firstaction !== '' && isset($tasks['booking.list_actions'])) {
            $this->append_suggestion(
                $suggestions,
                $seen,
                'booking.list_actions',
                $this->get_task_label('booking.list_actions', $lang),
                $this->localized_string('ai_followup_suggestion_query', $firstaction, $lang)
            );
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
        $map = [
            'booking.explain_docs_topic' => [
                'booking.list_actions',
                'booking.search_options',
                'booking.diagnose_booking_issue',
            ],
            'booking.list_actions' => [
                'booking.explain_docs_topic',
                'booking.search_options',
                'booking.list_option_properties',
            ],
            'booking.search_options' => [
                'booking.list_option_properties',
                'booking.update_option',
                'booking.bulk_update_options',
            ],
            'booking.search_users' => [
                'booking.get_current_user',
                'booking.search_courses',
                'booking.list_actions',
            ],
            'booking.search_courses' => [
                'booking.search_users',
                'booking.create_option',
                'booking.list_actions',
            ],
            'booking.list_option_properties' => [
                'booking.create_option',
                'booking.update_option',
                'booking.list_actions',
            ],
            'booking.get_current_user' => [
                'booking.search_users',
                'booking.search_options',
                'booking.list_actions',
            ],
            'booking.diagnose_booking_issue' => [
                'booking.explain_docs_topic',
                'booking.search_options',
                'booking.list_actions',
            ],
        ];

        $fallback = [
            'booking.list_actions',
            'booking.explain_docs_topic',
            'booking.search_options',
        ];

        $tasks = $map[$taskname] ?? $fallback;
        return array_values(array_filter(array_unique($tasks), static function (string $candidate) use ($taskname): bool {
            return $candidate !== $taskname;
        }));
    }

    /**
     * Resolve a user-facing label for a task, honoring optional language overrides.
     *
     * @param string $taskname
     * @param string $lang
     * @return string
     */
    private function get_task_label(string $taskname, string $lang): string {
        $stringmap = [
            'booking.create_option' => 'ai_action_create_option',
            'booking.create_user' => 'ai_action_create_user',
            'booking.update_option' => 'ai_action_update_option',
            'booking.bulk_update_options' => 'ai_action_bulk_update_options',
            'booking.search_options' => 'ai_action_search_options',
            'booking.search_users' => 'ai_action_search_users',
            'booking.search_courses' => 'ai_action_search_courses',
            'booking.add_price_category' => 'ai_action_add_price_category',
            'booking.list_option_properties' => 'ai_action_list_option_properties',
            'booking.list_actions' => 'ai_action_list_actions',
            'booking.get_current_user' => 'ai_action_get_current_user',
            'booking.recreate_task_catalog' => 'ai_action_recreate_task_catalog',
            'booking.explain_docs_topic' => 'ai_action_explain_docs_topic',
            'booking.diagnose_booking_issue' => 'ai_action_diagnose_booking_issue',
        ];

        if (isset($stringmap[$taskname])) {
            return $this->localized_string($stringmap[$taskname], null, $lang);
        }

        $task = $this->registry->get_task($taskname);
        if ($task) {
            $schema = $task->get_schema();
            $description = trim((string)($schema['description'] ?? ''));
            if ($description !== '') {
                return $description;
            }
        }

        return $taskname;
    }

    /**
     * Read a localized string, optionally forcing a specific output language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    private function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $targetlang = trim($lang);
        if ($targetlang === '') {
            return get_string($identifier, 'mod_booking', $a);
        }

        return get_string_manager()->get_string($identifier, 'mod_booking', $a, $targetlang);
    }
}
