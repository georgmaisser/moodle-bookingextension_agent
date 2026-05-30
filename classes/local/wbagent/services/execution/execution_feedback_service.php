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
 * Build user-facing execution feedback after task execution.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\execution;

use core\di;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager as ai_manager;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\task_registry_factory;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;
use bookingextension_agent\local\wbagent\services\localized_string_service;
use bookingextension_agent\local\wbagent\services\provider_routing_util;
use bookingextension_agent\local\wbagent\services\shared_json_payload_extractor;
use context_module;
use core_text;

/**
 * Generates post-execution feedback and client-safe run results.
 */
class execution_feedback_service {
    /** @var conversation_store */
    private conversation_store $store;

    /** @var task_registry */
    private task_registry $registry;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     * @param task_registry|null $registry
     */
    public function __construct(conversation_store $store, ?task_registry $registry = null) {
        $this->store = $store;
        $this->registry = $registry ?? task_registry_factory::get_default();
    }

    /**
     * Build the final assistant message and client-safe result payload.
     *
     * Message generation is now deterministic — the previous secondary LLM call
     * has been removed to comply with the "one agent-controlled LLM loop" rule.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $commands
     * @param array $results
     * @param string $outputlang
     * @return array
     */
    public function build_completion_feedback(
        int $threadid,
        int $cmid,
        int $userid,
        array $commands,
        array $results,
        string $outputlang = ''
    ): array {
        $allowpolish = $this->should_apply_polish_step($commands);
        $followupsuggestionslimit = $this->get_follow_up_suggestions_limit();

        // Only final clarification payloads (commands=[]) may be polished via LLM.
        // Command-bearing execution flows stay deterministic by design.
        if ($allowpolish) {
            $message = $this->generate_llm_feedback($threadid, $cmid, $userid, $commands, $results, $outputlang);
        } else {
            $message = $this->fallback_message_for_results($results, $outputlang);
        }

        $message = $this->append_link_to_message(
            $message,
            $this->extract_primary_link_from_results($results),
            $outputlang
        );

        $clientresults = $this->sanitize_results_for_client($results, $outputlang);

        // Follow-up suggestions are also part of the polish step and are therefore
        // disabled for command-bearing execution responses.
        if ($allowpolish && $followupsuggestionslimit > 0) {
            $followups = $this->generate_llm_follow_up_suggestions(
                $threadid,
                $cmid,
                $userid,
                $message,
                $commands,
                $results,
                $outputlang,
                $followupsuggestionslimit
            );
            if (!empty($followups['suggestions']) && is_array($followups['suggestions']) && !empty($clientresults)) {
                $clientresults[0]['suggestions'] = $followups['suggestions'];
                $followupmessage = trim((string)($followups['followupmessage'] ?? ''));
                if ($followupmessage !== '') {
                    $clientresults[0]['followupmessage'] = $followupmessage;
                }
            }
        }

        return [
            'message' => $message,
            'results' => $clientresults,
        ];
    }

    /**
     * Polish step is allowed only for final clarification payloads.
     *
     * @param array $commands
     * @return bool
     */
    private function should_apply_polish_step(array $commands): bool {
        if (empty($commands)) {
            return true;
        }

        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            if (!$this->registry->is_read_only_task($taskname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the LLM for the final user-facing post-execution message.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $commands
     * @param array $results
     * @param string $outputlang
     * @return string
     */
    private function generate_llm_feedback(
        int $threadid,
        int $cmid,
        int $userid,
        array $commands,
        array $results,
        string $outputlang
    ): string {
        $context = context_module::instance($cmid);
        $recentmessages = $this->store->get_recent_messages($threadid, 8);
        $latestusermessage = '';
        $anonymizer = new privacy_anonymizer($this->store);
        for ($i = count($recentmessages) - 1; $i >= 0; $i--) {
            if (($recentmessages[$i]->role ?? '') === 'user') {
                $latestusermessage = (string)($recentmessages[$i]->content ?? '');
                break;
            }
        }

        $sanitizedcommands = $anonymizer->anonymize_value_for_llm($threadid, $commands);
        $sanitizedresults = $anonymizer->anonymize_value_for_llm($threadid, $results);

        $prompt = $this->build_feedback_prompt(
            $outputlang,
            $latestusermessage,
            $sanitizedcommands,
            $sanitizedresults
        );

        try {
            $manager = di::get(ai_manager::class);
            if (!$manager->is_action_available(generate_text::class)) {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            $hascontextavailabilitycheck = method_exists($manager, 'is_action_enabled_in_context');
            $actiondisabledincontext = $hascontextavailabilitycheck
                && !call_user_func([$manager, 'is_action_enabled_in_context'], $context, generate_text::class);
            if ($actiondisabledincontext) {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            $debugsource = $this->build_execution_feedback_debug_source(
                $manager,
                count($recentmessages),
                count($results),
                generate_text::class
            );

            $llm = new llm_call_service($this->store);
            $call = $llm->invoke(
                $threadid,
                $cmid,
                $userid,
                $debugsource,
                $prompt,
                generate_text::class
            );
            if (empty($call['success'])) {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            $rawcontent = (string)($call['rawcontent'] ?? '');
            $message = $this->extract_message_from_feedback_response($rawcontent);
            if ($message === '') {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            return $message;
        } catch (\Throwable $e) {
            return $this->fallback_message_for_results($results, $outputlang);
        }
    }

    /**
     * Generate follow-up prompt suggestions via a second model call.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $finalmessage
     * @param array $commands
     * @param array $results
     * @param string $outputlang
     * @return array{followupmessage:string,suggestions:array<int,array<string,string>>}
     */
    private function generate_llm_follow_up_suggestions(
        int $threadid,
        int $cmid,
        int $userid,
        string $finalmessage,
        array $commands,
        array $results,
        string $outputlang,
        int $limit
    ): array {
        if ($limit <= 0) {
            return ['followupmessage' => '', 'suggestions' => []];
        }

        $context = context_module::instance($cmid);
        $latestusermessage = $this->extract_latest_user_message($threadid);
        $anonymizer = new privacy_anonymizer($this->store);
        $taskschemas = [];
        foreach ($this->registry->get_task_names() as $taskname) {
            $task = $this->registry->get_task($taskname);
            if (!$task) {
                continue;
            }
            $schema = (array)$task->get_schema();
            $taskschemas[] = [
                'task' => $taskname,
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => (bool)($schema['readonly'] ?? false),
            ];
        }

        $sanitizedcommands = $anonymizer->anonymize_value_for_llm($threadid, $commands);
        $sanitizedresults = $anonymizer->anonymize_value_for_llm($threadid, $results);
        $prompt = $this->build_follow_up_prompt(
            $outputlang,
            $latestusermessage,
            $finalmessage,
            $taskschemas,
            $sanitizedcommands,
            $sanitizedresults,
            $limit
        );

        try {
            $manager = di::get(ai_manager::class);
            if (!$manager->is_action_available(generate_text::class)) {
                return ['followupmessage' => '', 'suggestions' => []];
            }

            if (method_exists($manager, 'is_action_enabled_in_context')) {
                $actionenabledincontext = (bool)call_user_func(
                    [$manager, 'is_action_enabled_in_context'],
                    $context,
                    generate_text::class
                );
                if (!$actionenabledincontext) {
                    return ['followupmessage' => '', 'suggestions' => []];
                }
            }

            $llm = new llm_call_service($this->store);
            $call = $llm->invoke(
                $threadid,
                $cmid,
                $userid,
                'execution_feedback.generate_llm_follow_up_suggestions',
                $prompt,
                generate_text::class
            );
            if (empty($call['success'])) {
                return ['followupmessage' => '', 'suggestions' => []];
            }

            $rawcontent = trim((string)($call['rawcontent'] ?? ''));
            if ($rawcontent === '') {
                return ['followupmessage' => '', 'suggestions' => []];
            }

            return $this->parse_follow_up_suggestions_json($rawcontent, $taskschemas, $limit);
        } catch (\Throwable $e) {
            return ['followupmessage' => '', 'suggestions' => []];
        }
    }

    /**
     * Build prompt for follow-up suggestion generation.
     *
     * @param string $outputlang
     * @param string $latestusermessage
     * @param string $finalmessage
     * @param array $taskschemas
     * @param array $commands
     * @param array $results
     * @return string
     */
    private function build_follow_up_prompt(
        string $outputlang,
        string $latestusermessage,
        string $finalmessage,
        array $taskschemas,
        array $commands,
        array $results,
        int $limit
    ): string {
        $tasksjson = json_encode($taskschemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $commandsjson = json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultsjson = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "You are a follow-up prompt suggestion assistant for Moodle Booking.\n"
            . "You get the latest user request, executed task results, and the list of allowed tasks.\n"
            . "Suggest what the user could ask next, as editable prompt texts (not auto-executed commands).\n\n"
            . "Rules:\n"
            . "- Output ONLY valid JSON object.\n"
            . "- JSON format: {\"followupmessage\":\"...\",\"suggestions\":[{\"query\":\"...\",\"task\":\"...\","
            . "\"label\":\"...\"}]}.\n"
            . "- suggestions length: 1 to " . $limit . ".\n"
            . "- query must be a natural language prompt the user can edit and send.\n"
            . "- Do not output commands or internal metadata.\n"
            . "- task must be one of the allowed task names.\n"
            . "- Use same language as latest user message. If unclear use: "
            . ($outputlang !== '' ? $outputlang : 'current') . ".\n"
            . "- Keep suggestions specific to the actual result context.\n\n"
            . "Latest user message:\n"
            . ($latestusermessage !== '' ? $latestusermessage : '(none)') . "\n\n"
            . "Final assistant message:\n"
            . ($finalmessage !== '' ? $finalmessage : '(none)') . "\n\n"
            . "Allowed tasks:\n"
            . ($tasksjson !== false ? $tasksjson : '[]') . "\n\n"
            . "Executed commands:\n"
            . ($commandsjson !== false ? $commandsjson : '[]') . "\n\n"
            . "Execution results:\n"
            . ($resultsjson !== false ? $resultsjson : '[]');
    }

    /**
     * Parse model JSON output for follow-up suggestions.
     *
     * @param string $raw
     * @param array $taskschemas
     * @param int $limit
     * @return array{followupmessage:string,suggestions:array<int,array<string,string>>}
     */
    private function parse_follow_up_suggestions_json(string $raw, array $taskschemas, int $limit): array {
        if ($limit <= 0) {
            return ['followupmessage' => '', 'suggestions' => []];
        }

        $allowedtasks = [];
        foreach ($taskschemas as $task) {
            $name = trim((string)($task['task'] ?? ''));
            if ($name !== '') {
                $allowedtasks[$name] = true;
            }
        }

        $candidate = trim($raw);
        if ($candidate === '') {
            return ['followupmessage' => '', 'suggestions' => []];
        }

        if (preg_match('/\{.*\}/s', $candidate, $matches) === 1) {
            $candidate = (string)$matches[0];
        }

        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return ['followupmessage' => '', 'suggestions' => []];
        }

        $followupmessage = trim((string)($decoded['followupmessage'] ?? ''));
        $suggestions = [];
        $seenqueries = [];
        $rawsuggestions = $decoded['suggestions'] ?? [];
        if (!is_array($rawsuggestions)) {
            $rawsuggestions = [];
        }

        foreach ($rawsuggestions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $query = trim((string)($entry['query'] ?? ''));
            $task = trim((string)($entry['task'] ?? ''));
            $label = trim((string)($entry['label'] ?? ''));

            if ($query === '' || $task === '' || !isset($allowedtasks[$task])) {
                continue;
            }
            if ($label === '') {
                $label = $task;
            }
            if (isset($seenqueries[$query])) {
                continue;
            }

            $seenqueries[$query] = true;
            $suggestions[] = [
                'query' => $query,
                'task' => $task,
                'label' => $label,
            ];

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return [
            'followupmessage' => $followupmessage,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Resolve configured follow-up suggestion count.
     *
     * 0 disables follow-up suggestion generation entirely.
     *
     * @return int
     */
    private function get_follow_up_suggestions_limit(): int {
        $configured = get_config('bookingextension_agent', 'aifollowupsuggestionscount');
        if ($configured === false) {
            return 0;
        }

        return max(0, (int)$configured);
    }

    /**
     * Extract the latest user message from a thread.
     *
     * @param int $threadid
     * @return string
     */
    private function extract_latest_user_message(int $threadid): string {
        $recentmessages = $this->store->get_recent_messages($threadid, 8);
        for ($i = count($recentmessages) - 1; $i >= 0; $i--) {
            if (($recentmessages[$i]->role ?? '') === 'user') {
                return (string)($recentmessages[$i]->content ?? '');
            }
        }

        return '';
    }

    /**
     * Build the summary prompt for the post-execution LLM pass.
     *
     * @param string $outputlang
     * @param string $latestusermessage
     * @param array $commands
     * @param array $results
     * @return string
     */
    private function build_feedback_prompt(
        string $outputlang,
        string $latestusermessage,
        array $commands,
        array $results
    ): string {
        $commandsjson = json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultsjson = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $lang = trim($outputlang) !== '' ? trim($outputlang) : 'de';

        $system = "You are the final user-facing assistant message writer for Moodle Booking.\n"
            . "The internal tasks have already been executed successfully or with structured result data.\n"
            . "Return exactly one JSON object and nothing else.\n\n"
            . "JSON contract:\n"
            . "- response_type: must be \"clarification\".\n"
            . "- message: final user-facing answer text.\n"
            . "- used_triggers: array (use [] if unknown).\n"
            . "- next_step_intent: one short sentence in user language.\n"
            . "- commands: must be [].\n\n"
            . "Rules:\n"
            . "- Do not mention task names, command numbers, run ids, or raw JSON.\n"
            . "- CRITICAL: message should be nicely formatted with HTML, but keep it simple.\n"
            . "- CRITICAL: Do not use markdown formatting (no bold, italic, code blocks, lists).\n"
            . "- message is UTF-8 plain text, period.\n"
            . "- If there are zero matches, say that clearly.\n"
            . "- If there are matches, summarize them naturally and concisely.\n"
            . "- If booking options are included, use their real option ids from the structured results.\n"
            . "- Never renumber options as 1, 2, 3, ... unless those are the actual option ids.\n"
            . "- If ANON_USER tokens appear, keep them unchanged.\n"
            . "- If there is a link in the Observation, use it unchanged in the right place in the message.\n"
            . "- Never invent details not present in the results.";

        $runtime = "preferred_user_lang: " . $lang . "\n"
            . "NON-OPTIONAL LANGUAGE POLICY:\n"
            . "- Use ISO 639-1 value from preferred_user_lang for both lang and user_lang when uncertain.\n"
            . "- Keep the entire message field in that same language.";

        $parts = [
            "[SYSTEM]\n" . $system,
            "[SYSTEM_RUNTIME]\n" . $runtime,
            "[USER]\n" . ($latestusermessage !== '' ? $latestusermessage : '(none)'),
            "[OBSERVATION 1]\nExecuted commands:\n" . ($commandsjson !== false ? $commandsjson : '[]'),
            "[OBSERVATION 2]\nStructured results:\n" . ($resultsjson !== false ? $resultsjson : '[]'),
            '[ASSISTANT]',
        ];

        return implode("\n\n", $parts);
    }

    /**
     * Extract user-facing message from feedback model output.
     *
     * Accepts structured JSON payloads and keeps a plain-text fallback for safety.
     *
     * @param string $rawcontent
     * @return string
     */
    private function extract_message_from_feedback_response(string $rawcontent): string {
        $rawcontent = trim($rawcontent);
        if ($rawcontent === '') {
            return '';
        }

        foreach (shared_json_payload_extractor::extract_json_candidates($rawcontent) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }

            $message = trim((string)($decoded['message'] ?? ''));
            $message = strip_tags($message);
            if ($message !== '') {
                return $message;
            }
        }

        // Backward-compatible fallback in case a provider returns plain text.
        return strip_tags($rawcontent);
    }

    /**
     * Build compact debug source telemetry aligned with orchestrator format.
     *
     * Example: orc|st=tcp|ac=gen|rt=oa|fb=0|pv=oai|hm=1|ob=0|ex=0
     *
     * @param ai_manager $manager
     * @param int $historycount
     * @param int $observationcount
     * @param string $actionclass
     * @return string
     */
    private function build_execution_feedback_debug_source(
        ai_manager $manager,
        int $historycount,
        int $observationcount,
        string $actionclass
    ): string {
        $provider = provider_routing_util::resolve_primary_provider_for_action($manager, $actionclass);
        $providershort = provider_routing_util::short_provider_for_debug($provider);
        $route = ($providershort === 'oai') ? 'oa' : 'df';
        $actionmap = [
            generate_text::class => 'gen',
            summarise_text::class => 'sum',
            explain_text::class => 'exp',
        ];
        $action = $actionmap[$actionclass] ?? 'oth';

        $source = 'orc'
            . '|st=tcp'
            . '|ac=' . $action
            . '|rt=' . $route
            . '|fb=0'
            . '|pv=' . $providershort
            . '|hm=' . max(0, $historycount)
            . '|ob=' . max(0, $observationcount)
            . '|ex=0';

        if (core_text::strlen($source) > 100) {
            return core_text::substr($source, 0, 100);
        }

        return $source;
    }

    /**
     * Remove sensitive or low-value raw result fields before data reaches the client.
     *
     * @param array $results
     * @param string $outputlang
     * @return array
     */
    private function sanitize_results_for_client(array $results, string $outputlang = ''): array {
        $sanitized = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $entry = [
                'status' => (string)($result['status'] ?? ''),
                'detail' => $this->sanitize_result_detail($result, $outputlang),
                'resultid' => isset($result['resultid']) ? (int)$result['resultid'] : null,
            ];

            foreach (['link', 'url', 'editlink', 'viewlink', 'editurl', 'viewurl'] as $linkkey) {
                if (!isset($result[$linkkey]) || !is_string($result[$linkkey])) {
                    continue;
                }
                $linkvalue = trim((string)$result[$linkkey]);
                if ($linkvalue !== '') {
                    $entry[$linkkey] = $linkvalue;
                }
            }

            if (isset($result['task']) && is_string($result['task']) && trim($result['task']) !== '') {
                $entry['task'] = trim($result['task']);
            }

            // Keep executor-provided input payload for planner runtime memory.
            // This is consumed by orchestrator SYSTEM_RUNTIME.completed_commands.
            if (isset($result['executed_input']) && is_array($result['executed_input'])) {
                $entry['executed_input'] = $result['executed_input'];
            } else if (isset($result['input']) && is_array($result['input'])) {
                $entry['executed_input'] = $result['input'];
            }

            // Only pass task-authored user text through directly when no explicit output language
            // was requested (legacy/internal paths). Otherwise, frontend should use the normalized
            // top-level completion message to preserve language consistency.
            if (
                $outputlang === ''
                && isset($result['usermessage'])
                && is_string($result['usermessage'])
                && trim($result['usermessage']) !== ''
            ) {
                $entry['usermessage'] = trim($result['usermessage']);
            }

            if (isset($result['debugmessage']) && is_string($result['debugmessage']) && trim($result['debugmessage']) !== '') {
                $entry['debugmessage'] = trim($result['debugmessage']);
            }

            if (
                isset($result['next_step_intent'])
                && is_string($result['next_step_intent'])
                && trim($result['next_step_intent']) !== ''
            ) {
                $entry['next_step_intent'] = trim($result['next_step_intent']);
            }

            if (isset($result['userid'])) {
                $entry['userid'] = (int)$result['userid'];
            }

            if (isset($result['fullname']) && is_string($result['fullname']) && trim($result['fullname']) !== '') {
                $entry['fullname'] = trim($result['fullname']);
            }

            if (isset($result['email']) && is_string($result['email']) && trim($result['email']) !== '') {
                $entry['email'] = trim($result['email']);
            }

            if (isset($result['previewmode']) && is_string($result['previewmode']) && trim($result['previewmode']) !== '') {
                $entry['previewmode'] = trim($result['previewmode']);
            }

            if (isset($result['previewdata']) && is_array($result['previewdata'])) {
                $entry['previewdata'] = $result['previewdata'];
            }

            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                $entry['previewoptionids'] = array_values(array_map('intval', $result['previewoptionids']));
            }

            if (!empty($result['options']) && is_array($result['options'])) {
                $entry['options'] = $result['options'];
            }

            if (!empty($result['optiondetails']) && is_array($result['optiondetails'])) {
                $entry['optiondetails'] = $result['optiondetails'];
            }

            if (!empty($result['detail_capabilities']) && is_array($result['detail_capabilities'])) {
                $entry['detail_capabilities'] = $result['detail_capabilities'];
            }

            if (!empty($result['users']) && is_array($result['users'])) {
                $entry['users'] = $result['users'];
            }

            if (!empty($result['courses']) && is_array($result['courses'])) {
                $entry['courses'] = $result['courses'];
            }

            if (!empty($result['diagnosis']) && is_array($result['diagnosis'])) {
                $entry['diagnosis'] = $result['diagnosis'];
            }

            if (!empty($result['properties']) && is_array($result['properties'])) {
                $entry['properties'] = $result['properties'];
            }

            if (!empty($result['actions']) && is_array($result['actions'])) {
                $entry['actions'] = $result['actions'];
            }

            if (!empty($result['capabilities']) && is_array($result['capabilities'])) {
                $entry['capabilities'] = $result['capabilities'];
            }

            if (!empty($result['docs']) && is_array($result['docs'])) {
                $entry['docs'] = $result['docs'];
            }

            if (!empty($result['suggestions']) && is_array($result['suggestions'])) {
                $entry['suggestions'] = $result['suggestions'];
            }

            if (
                isset($result['followupmessage'])
                && is_string($result['followupmessage'])
                && trim($result['followupmessage']) !== ''
            ) {
                $entry['followupmessage'] = trim($result['followupmessage']);
            }

            if (
                $outputlang === ''
                && isset($result['summary'])
                && is_string($result['summary'])
                && trim($result['summary']) !== ''
            ) {
                $entry['summary'] = trim($result['summary']);
            }

            // Pass through verbatim observation content so the LLM loop receives
            // the full list without truncation from compact_text.
            if (
                isset($result['observation_full'])
                && is_string($result['observation_full'])
                && trim($result['observation_full']) !== ''
            ) {
                $entry['observation_full'] = trim($result['observation_full']);
            }

            $sanitized[] = $entry;
        }

        return $sanitized;
    }

    /**
     * Collapse raw task details into a safe client detail string.
     *
     * @param array $result
     * @param string $outputlang
     * @return string
     */
    private function sanitize_result_detail(array $result, string $outputlang = ''): string {
        // Diagnosis result: use localized string with option name when available.
        $category = result_payload_summarizer::detect_result_category($result);

        // Docs result: pass task-authored usermessage through regardless of outputlang,
        // because the content is doc text that must always reach the caller unchanged.
        if ($category === 'docs') {
            $usermessage = trim((string)($result['usermessage'] ?? ''));
            if ($usermessage !== '') {
                return $usermessage;
            }
            $detail = trim((string)($result['detail'] ?? ''));
            return $detail !== '' ? $detail : $this->localized('ai_result_detail_action_executed', null, $outputlang);
        }

        if ($category === 'diagnosis') {
            $optionname = trim((string)($result['diagnosis']['optionname'] ?? ''));
            if ($optionname !== '') {
                return $this->localized('ai_result_detail_diagnosis_with_option', $optionname, $outputlang);
            }
            return $this->localized('ai_result_detail_diagnosis_generic', null, $outputlang);
        }

        // Pass through task-authored user message when no output-language override is active.
        $usermessage = trim((string)($result['usermessage'] ?? ''));
        if ($usermessage !== '' && $outputlang === '') {
            return $usermessage;
        }

        if ($category === 'users') {
            return $this->localized_list_count_message(
                $result,
                'users',
                'ai_result_detail_users_none',
                'ai_result_detail_users_found',
                $outputlang
            );
        }

        if ($category === 'courses') {
            return $this->localized_list_count_message(
                $result,
                'courses',
                'ai_result_detail_courses_none',
                'ai_result_detail_courses_found',
                $outputlang
            );
        }

        if ($category === 'options') {
            return $this->localized_list_count_message(
                $result,
                'options',
                'ai_result_detail_options_none',
                'ai_result_detail_options_found',
                $outputlang
            );
        }

        if ($category === 'option_details') {
            return result_payload_summarizer::describe_entry($result, 0, 'client_fallback');
        }

        if ($category === 'current_user') {
            return $this->localized('ai_result_detail_current_user', null, $outputlang);
        }

        if ($category === 'capabilities' || $category === 'properties') {
            $summary = trim((string)($result['summary'] ?? ''));
            if ($summary !== '' && $outputlang === '') {
                return $summary;
            }
        }

        $detail = trim((string)($result['detail'] ?? ''));
        if ($detail === '') {
            $detail = $this->localized('ai_result_detail_action_executed', null, $outputlang);
        }

        return $this->append_link_to_message($detail, $this->extract_primary_link_from_result($result), $outputlang);
    }

    /**
     * Deterministic fallback when generating a user-facing result summary.
     *
     * @param array $results
     * @param string $outputlang
     * @return string
     */
    private function fallback_message_for_results(array $results, string $outputlang): string {
        if (empty($results)) {
            return $this->localized('ai_result_feedback_complete', null, $outputlang);
        }

        $first = $results[0] ?? [];
        if (!is_array($first)) {
            return $this->localized('ai_result_feedback_complete', null, $outputlang);
        }

        $category = result_payload_summarizer::detect_result_category($first);

        if ($category === 'users') {
            return $this->localized_list_count_message(
                $first,
                'users',
                'ai_result_feedback_users_none',
                'ai_result_feedback_users_found',
                $outputlang
            );
        }

        if ($category === 'courses') {
            return $this->localized_list_count_message(
                $first,
                'courses',
                'ai_result_feedback_courses_none',
                'ai_result_feedback_courses_found',
                $outputlang
            );
        }

        if ($category === 'options') {
            return $this->localized_list_count_message(
                $first,
                'options',
                'ai_result_feedback_options_none',
                'ai_result_feedback_options_found',
                $outputlang
            );
        }

        if ($category === 'current_user') {
            return $this->localized('ai_result_feedback_current_user', null, $outputlang);
        }

        $detail = trim((string)($first['detail'] ?? ''));
        if ($detail === '') {
            $detail = $this->localized('ai_result_feedback_complete', null, $outputlang);
        }

        return $this->append_link_to_message($detail, $this->extract_primary_link_from_result($first), $outputlang);
    }

    /**
     * Extract a primary link value from a task result entry.
     *
     * @param array $result
     * @return string
     */
    private function extract_primary_link_from_result(array $result): string {
        foreach (['link', 'url', 'editlink', 'viewlink', 'editurl', 'viewurl'] as $key) {
            if (!isset($result[$key]) || !is_string($result[$key])) {
                continue;
            }
            $candidate = trim((string)$result[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Extract the first available link from a list of result entries.
     *
     * @param array $results
     * @return string
     */
    private function extract_primary_link_from_results(array $results): string {
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $link = $this->extract_primary_link_from_result($result);
            if ($link !== '') {
                return $link;
            }
        }

        return '';
    }

    /**
     * Resolve a localized plugin string.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $outputlang
     * @return string
     */
    private function localized(string $identifier, $a = null, string $outputlang = ''): string {
        return localized_string_service::get($identifier, 'bookingextension_agent', $a, $outputlang);
    }

    /**
     * Localize a none/found message pair based on list count.
     *
     * @param array $result
     * @param string $listkey
     * @param string $nonekey
     * @param string $foundkey
     * @param string $outputlang
     * @return string
     */
    private function localized_list_count_message(
        array $result,
        string $listkey,
        string $nonekey,
        string $foundkey,
        string $outputlang
    ): string {
        $items = $result[$listkey] ?? [];
        if (!is_array($items) || count($items) === 0) {
            return $this->localized($nonekey, null, $outputlang);
        }

        return $this->localized($foundkey, count($items), $outputlang);
    }

    /**
     * Append a link to a plain-text message once, localized and deterministic.
     *
     * @param string $message
     * @param string $link
     * @param string $outputlang
     * @return string
     */
    private function append_link_to_message(string $message, string $link, string $outputlang): string {
        $message = trim($message);
        $link = trim($link);
        if ($link === '') {
            return $message;
        }

        if ($message !== '' && str_contains($message, $link)) {
            return $message;
        }

        $prefix = (trim(strtolower($outputlang)) === 'de') ? 'Link: ' : 'Link: ';
        if ($message === '') {
            return $prefix . $link;
        }

        return $message . ' ' . $prefix . $link;
    }
}
