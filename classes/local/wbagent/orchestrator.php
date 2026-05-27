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
 * AI orchestration layer.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use context_module;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core\di;
use core_text;
use bookingextension_agent\local\wbagent\interfaces\agent_interpreter;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\adaptive_task_catalog_service;
use bookingextension_agent\local\wbagent\services\execution_observation_ledger;

/**
 * Orchestrates LLM interaction via core_ai.
 *
 * Responsibilities:
 *  - Assemble a state-based system prompt (not full raw chat history).
 *  - Send the conversation context to the AI provider.
 *  - Hand the raw response off to the interpreter.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator {
    /** Maximum number of recent messages to include in the prompt. */
    public const MAX_HISTORY_MESSAGES = 12;

    /** Compact prompt profile for initial tool-call parsing. */
    public const STEP_TYPE_TOOL_CALL_PARSE = 'tool_call_parse';

    /** Compact prompt profile for iterative retrieval turns with observations. */
    public const STEP_TYPE_SIMPLE_RETRIEVAL = 'simple_retrieval';

    /** Richer prompt profile for final narration/reasoning turns. */
    public const STEP_TYPE_FINAL_REASONING = 'final_reasoning';

    /** Final synthesis turn: generate_text composes the polished answer from accumulated observations. */
    public const STEP_TYPE_FINAL_SYNTHESIS = 'final_synthesis';

    /** Default model for task-catalog embeddings. */
    public const EMBEDDINGS_DEFAULT_MODEL = 'text-embedding-3-small';

    /** Default embedding dimensions. */
    public const EMBEDDINGS_DEFAULT_DIMENSIONS = 1536;

    /** Default number of best matching tasks to inject for first planner step. */
    public const EMBEDDINGS_DEFAULT_TOP_K = 6;

    /** Debounce window (seconds) for scheduling embeddings rebuild task. */
    public const EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS = 300;

    /** Wunderbyte planner action class name. */
    private const WB_ACTION_PLANNER_DECIDE = '\\aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = '\\aiprovider_wunderbyte\\aiactions\\generate_agent_reply';

    /** @var task_registry */
    private task_registry $registry;

    /** @var interpreter */
    private agent_interpreter $interpreter;

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param task_registry      $registry
     * @param agent_interpreter  $interpreter
     * @param conversation_store $store
     */
    public function __construct(
        task_registry $registry,
        agent_interpreter $interpreter,
        conversation_store $store
    ) {
        $this->registry = $registry;
        $this->interpreter = $interpreter;
        $this->store = $store;
    }

    /**
     * Check whether a Moodle core_ai provider is configured and available.
     *
     * @param int $cmid   Course-module id.
     * @param int $userid User id.
     * @return bool
     */
    public function is_provider_available(int $cmid, int $userid): bool {
        $status = $this->get_runtime_provider_status($cmid);
        return (bool)($status['runtimeavailable'] ?? false);
    }

    /**
     * Resolve centralized provider/runtime status for booking agent execution.
     *
     * This is the single source of truth for availability checks used by both
     * readiness UI and runtime message processing.
     *
     * @param int $cmid Course-module id.
     * @return array<string,mixed>
     */
    public function get_runtime_provider_status(int $cmid): array {
        $default = [
            'providerconfigured' => false,
            'provideractive' => false,
            'courseenabled' => false,
            'contextenabled' => false,
            'runtimeavailable' => false,
            'toolactionclass' => '',
            'finalactionclass' => '',
            'toolroutepolicy' => 'default',
            'finalroutepolicy' => 'default',
        ];

        if (!class_exists('\core_ai\manager')) {
            return $default;
        }

        try {
            $context = context_module::instance($cmid);
            $manager = di::get(ai_manager::class);

            $providerinstances = (array)$manager->get_provider_instances();
            $providerconfigured = !empty($providerinstances);

            $hasenabledproviderinstance = false;
            foreach ($providerinstances as $instance) {
                if (!empty($instance->enabled)) {
                    $hasenabledproviderinstance = true;
                    break;
                }
            }

            $provideractive = $hasenabledproviderinstance;
            $candidateactions = [
                generate_text::class,
                summarise_text::class,
                explain_text::class,
                self::WB_ACTION_PLANNER_DECIDE,
                self::WB_ACTION_GENERATE_AGENT_REPLY,
            ];
            foreach ($candidateactions as $candidate) {
                if (!class_exists($candidate)) {
                    continue;
                }
                try {
                    $actionavailable = $manager->is_action_available($candidate);
                } catch (\Throwable $e) {
                    $actionavailable = false;
                }
                if ($actionavailable) {
                    $provideractive = true;
                    break;
                }
            }

            $courseenabled = method_exists($manager, 'is_ai_tools_enabled_in_course')
                ? ai_manager::is_ai_tools_enabled_in_course($context)
                : true;

            $moduleaienabled = true;
            if ($context->contextlevel === CONTEXT_MODULE) {
                $moduleaifields = ai_manager::get_ai_fields_from_course_module($context->instanceid);
                $moduleaienabled = is_null($moduleaifields->enableaitools)
                    || (bool)$moduleaifields->enableaitools;
            }

            $toolrouting = $this->resolve_action_class_for_step($manager, $context, self::STEP_TYPE_TOOL_CALL_PARSE);
            $finalrouting = $this->resolve_action_class_for_step($manager, $context, self::STEP_TYPE_FINAL_REASONING);

            $toolactionclass = (string)($toolrouting['actionclass'] ?? '');
            $finalactionclass = (string)($finalrouting['actionclass'] ?? '');

            $toolroutepolicy = (string)($toolrouting['routepolicy'] ?? 'default');
            $finalroutepolicy = (string)($finalrouting['routepolicy'] ?? 'default');

            $wunderbyteroutingselected = $toolroutepolicy === 'wunderbyte' && $finalroutepolicy === 'wunderbyte';

            $toolenabledincontext = false;
            if ($toolactionclass !== '') {
                if ($wunderbyteroutingselected) {
                    // Explicit override for wunderbyte custom actions: they are not
                    // placement-backed in core, so do not block on module action flags.
                    $toolenabledincontext = true;
                } else if ($toolroutepolicy === 'wunderbyte') {
                    // Defensive fallback when only one side is tagged as wunderbyte.
                    $toolenabledincontext = $moduleaienabled;
                } else {
                    $toolenabledincontext = $this->is_action_available_in_context($manager, $context, $toolactionclass);
                }
            }

            $finalenabledincontext = false;
            if ($finalactionclass !== '') {
                if ($wunderbyteroutingselected) {
                    // Explicit override for wunderbyte custom actions: they are not
                    // placement-backed in core, so do not block on module action flags.
                    $finalenabledincontext = true;
                } else if ($finalroutepolicy === 'wunderbyte') {
                    // Defensive fallback when only one side is tagged as wunderbyte.
                    $finalenabledincontext = $moduleaienabled;
                } else {
                    $finalenabledincontext = $this->is_action_available_in_context($manager, $context, $finalactionclass);
                }
            }

            $contextenabled = $toolenabledincontext && $finalenabledincontext;
            $runtimeavailable = $provideractive && $courseenabled && $contextenabled;

            return [
                'providerconfigured' => $providerconfigured,
                'provideractive' => $provideractive,
                'courseenabled' => $courseenabled,
                'contextenabled' => $contextenabled,
                'runtimeavailable' => $runtimeavailable,
                'toolactionclass' => $toolactionclass,
                'finalactionclass' => $finalactionclass,
                'toolroutepolicy' => $toolroutepolicy,
                'finalroutepolicy' => $finalroutepolicy,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Process a user message: call the LLM and interpret the response.
     *
     * @param  int      $threadid     Thread id.
     * @param  int      $cmid         Course-module id.
     * @param  int      $userid       User id.
     * @param  string[] $observations Optional structured observation strings from prior internal loop steps.
     *                                Injected into the prompt so the LLM can reason about tool results
     *                                before producing its next response.  Never persisted to the DB.
     * @return array  Interpreter result.
     */
    public function process(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations = [],
        string $steptype = self::STEP_TYPE_TOOL_CALL_PARSE
    ): array {
        $context = context_module::instance($cmid);
        $contextid = (int)$context->id;
        $manager = di::get(ai_manager::class);
        $normalizedsteptype = $this->normalize_step_type($steptype);
        $evaluator = new task_executability_evaluator($this->registry, new authorization_service());

        $routing = $this->resolve_action_class_for_step($manager, $context, $normalizedsteptype);
        $actionclass = (string)$routing['actionclass'];
        // Always provide full thread history (excluding ephemeral step bubbles)
        // so follow-up turns keep complete conversation context.
        $messages = array_values(array_filter(
            $this->store->get_messages($threadid),
            static fn($msg): bool => (string)($msg->role ?? '') !== 'step'
        ));

        // Compute adaptive task catalog: tiered (mandatory + recency for Step 2+, full for Step 1).
        $recenttaskhistory = $this->extract_recent_task_names_from_messages($messages);
        $isfirstassistantturn = $this->is_first_assistant_turn($messages);
        $promptcontracts = $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid);
        $adaptivecatalogresult = adaptive_task_catalog_service::get_adaptive_catalog(
            $promptcontracts,
            $recenttaskhistory,
            $normalizedsteptype
        );
        $adaptivecatalog = $adaptivecatalogresult['active_tasks'];

        $hasanyobservations = !empty($observations);
        $haseffectiveobservations = $hasanyobservations
            && !$this->observations_are_framework_retry_hints($observations);
        $plannertracehistory = $this->normalize_planner_trace_history(
            $this->store->get_thread_metadata_value($threadid, 'planner_trace_history')
        );
        $shouldincludetaskcatalog = (
            ($normalizedsteptype === self::STEP_TYPE_TOOL_CALL_PARSE && !$hasanyobservations)
            || ($normalizedsteptype === self::STEP_TYPE_SIMPLE_RETRIEVAL && $haseffectiveobservations)
        );
        $runtimecatalog = [];
        $unavailabletaskcatalog = [];
        $catalogselectionmode = 'none';
        $embeddingstatus = 'off';
        $embeddingrebuildqueued = false;
        $llm = new llm_call_service($this->store);
        if ($shouldincludetaskcatalog) {
            $allpromptcontracts = $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid, true);
            $runtimecatalog = $this->slim_prompt_catalog_for_planner($adaptivecatalog);
            $catalogselectionmode = 'slim';

            $iswunderbyteplanner = ($routing['routepolicy'] ?? '') === 'wunderbyte'
                && $actionclass === self::WB_ACTION_PLANNER_DECIDE;

            if ($iswunderbyteplanner) {
                $embeddingstatus = 'check';
                $embeddingsettings = (new embeddings_action_config_resolver())->resolve();
                $embeddingmodel = (string)($embeddingsettings['model'] ?? self::EMBEDDINGS_DEFAULT_MODEL);
                $embeddingdimensions = (int)($embeddingsettings['dimensions'] ?? self::EMBEDDINGS_DEFAULT_DIMENSIONS);

                // Keep embed-selected planner catalogs intentionally narrow.
                $embeddingtopk = self::EMBEDDINGS_DEFAULT_TOP_K;

                $readiness = new embeddings_readiness_service();
                if ($readiness->is_wunderbyte_embeddings_available()) {
                    $status = $readiness->get_catalog_status($this->registry, $embeddingmodel, $embeddingdimensions);
                    $embeddingstatus = (string)($status['status'] ?? 'unknown');
                    $embeddingrebuildqueued = $readiness->ensure_rebuild_scheduled_if_needed(
                        $status,
                        $embeddingmodel,
                        $embeddingdimensions,
                        self::EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS
                    );

                    if (!empty($status['ready']) && !empty($status['rows']) && is_array($status['rows'])) {
                        $querytext = '';
                        foreach (array_reverse($messages) as $msg) {
                            if (($msg->role ?? '') === 'user') {
                                $querytext = trim((string)($msg->content ?? ''));
                                break;
                            }
                        }

                        if ($querytext !== '') {
                            $embeddingcall = $llm->invoke_embeddings(
                                $threadid,
                                $cmid,
                                $userid,
                                'orc|st=tcp|ac=emb|rt=wb',
                                $querytext,
                                $embeddingdimensions
                            );

                            if (!empty($embeddingcall['success']) && !empty($embeddingcall['embedding'])) {
                                $retrieval = new embeddings_retrieval_service();
                                $toprows = $retrieval->search_top_k(
                                    (array)$embeddingcall['embedding'],
                                    $status['rows'],
                                    $embeddingtopk
                                );
                                $subset = $retrieval->build_planner_catalog_subset(
                                    $toprows,
                                    $allpromptcontracts
                                );
                                if (!empty($subset)) {
                                    $evaluations = $evaluator->evaluate_all_tasks($userid, $contextid);
                                    $descriptionindex = $this->build_task_description_index($allpromptcontracts);
                                    $matchedtasknames = [];
                                    $activesubset = [];
                                    foreach ($subset as $entry) {
                                        if (!is_array($entry)) {
                                            continue;
                                        }

                                        $taskname = trim((string)($entry['task'] ?? ''));
                                        if ($taskname === '') {
                                            continue;
                                        }

                                        $matchedtasknames[] = $taskname;
                                        $state = trim((string)($evaluations[$taskname]['executable_state'] ?? ''));
                                        if ($state === 'deny') {
                                            $unavailabletaskcatalog[] = [
                                                'task' => $taskname,
                                                'availability' => (string)(
                                                    ($evaluations[$taskname]['deny_reason'] ?? '') === task_contract_validator::DENY_MISSING_CAPABILITY
                                                        ? 'not_active_for_you'
                                                        : (($evaluations[$taskname]['deny_reason'] ?? '') === task_contract_validator::DENY_CONTEXT_INVALID
                                                            ? 'invalid_context'
                                                            : (($evaluations[$taskname]['deny_reason'] ?? '') === task_contract_validator::DENY_RUNTIME_DISABLED
                                                                ? 'runtime_disabled'
                                                                : 'not_active_now'))
                                                ),
                                                'description' => (string)($descriptionindex[$taskname] ?? ''),
                                            ];
                                            continue;
                                        }

                                        $activesubset[] = $entry;
                                    }

                                    $boostedcatalog = $this->merge_embedding_subset_with_fallback_catalog(
                                        $activesubset,
                                        $adaptivecatalog
                                    );
                                    $runtimecatalog = $this->slim_prompt_catalog_for_planner($boostedcatalog);
                                    $unavailabletaskcatalog = array_values(array_filter($unavailabletaskcatalog, static function ($entry) {
                                        return is_array($entry) && trim((string)($entry['task'] ?? '')) !== '';
                                    }));
                                    $catalogselectionmode = 'embed_boost';
                                    $embeddingstatus = 'applied';
                                } else {
                                    $embeddingstatus = 'nomatch';
                                }
                            } else {
                                $embeddingstatus = 'callfail';
                            }
                        }
                    }
                } else {
                    $embeddingstatus = 'unavailable';
                }
            }
        }

        $systemprompt = $this->build_system_prompt(
            $cmid,
            $userid,
            $contextid,
            $normalizedsteptype,
            $actionclass,
            $haseffectiveobservations,
            $adaptivecatalog,
            $runtimecatalog,
            $isfirstassistantturn,
            $shouldincludetaskcatalog
        );
        $runtimecontext = $this->build_runtime_context_block(
            $threadid,
            $cmid,
            $normalizedsteptype,
            $isfirstassistantturn,
            $hasanyobservations,
            $runtimecatalog,
            $unavailabletaskcatalog,
            $messages
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $normalizedsteptype,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode
        );
        $historycount = count(array_slice($messages, -$this->get_history_limit_for_step($normalizedsteptype)));
        $observationcount = count($observations);
        $primaryprovider = $this->resolve_primary_provider_for_action($manager, $actionclass);
        $debugsource = $this->build_orchestrator_debug_source(
            $normalizedsteptype,
            $actionclass,
            (string)$routing['routepolicy'],
            !empty($routing['routingfallback']),
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($runtimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $call = $llm->invoke($threadid, $cmid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');

        if (empty($call['success'])) {
            $errormessage = (string)($call['errormessage'] ?? 'Provider returned an error.');
            $errorcode = (int)($call['errorcode'] ?? 0);
            $errorname = (string)($call['errorname'] ?? '');
            $issuecodes = ai_error_classifier::classify_from_response($errormessage, $errorcode, $errorname);
            return [
                'response_type' => 'error',
                'message'       => get_string('ai_provider_error', 'bookingextension_agent'),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [$errormessage],
                'issue_codes'   => $issuecodes,
            ];
        }

        if ($rawtext === '') {
            return [
                'response_type' => 'error',
                'message'       => get_string('ai_provider_error', 'bookingextension_agent'),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => ['Provider returned empty content.'],
                'issue_codes'   => [],
            ];
        }

        $lastusermessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg->role ?? '') === 'user') {
                $lastusermessage = trim((string)($msg->content ?? ''));
                break;
            }
        }

        $interpreted = $this->interpreter->interpret($rawtext, $contextid, $userid, $lastusermessage);
        if (is_array($interpreted)) {
            // Preserve the exact raw planner payload so runtime can pass it unchanged
            // into the next call as PLANNER_TRACE.
            $interpreted['_planner_raw_response'] = $rawtext;
        }

        return $interpreted;
    }

    /**
     * Return the default initial system prompt template.
     *
     * Supported placeholders:
     * - {{bookingname}}
     * - {{timezonename}}
     * - {{nowiso}}
     * - {{tasklist}}
     * - {{schemajson}}
     * - {{taskcatalogjson}}
     * - {{fullschemajson}}
     *
     * @return string
     */
    public static function get_default_initial_prompt_template(): string {
        $path = self::get_default_initial_prompt_template_path();
        if (!is_readable($path)) {
            return 'You are an AI assistant for Moodle booking. Respond only with valid JSON.';
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return 'You are an AI assistant for Moodle booking. Respond only with valid JSON.';
        }

        return (string)$content;
    }

    /**
     * Return a slim default initial prompt template for a routed AI action.
     *
     * @param string $actionclass
     * @return string
     */
    public static function get_default_initial_prompt_template_for_action(string $actionclass): string {
        if (
            $actionclass === summarise_text::class
            || $actionclass === self::WB_ACTION_PLANNER_DECIDE
        ) {
            return <<<'PROMPT'
You are an AI agent planner for the "{{bookingname}}" context.

ACTION-SPECIFIC GUIDANCE FOR ROUTING:
- Keep instructions compact and action-oriented. Do not over-explain.
- Route the latest user message to exactly ONE task_call OR ask for missing data.
- Use only exact task names from the TASK CATALOG. Never invent aliases.
- If a matching task appears in UNAVAILABLE TASKS, mention that it exists but is currently not executable.
- Do not emit unavailable tasks in commands.

TASK CONTRACT FIRST (highest priority):
- Follow task-level routing hints from the TASK CATALOG (intent, minimal_input, anchors, example_input, message_triggers).
- Keep global routing generic; do not hardcode special behavior for individual task names.

READ-ONLY RULE (mandatory):
- For read-only intents (list, search, get, diagnose), return response_type=task_call.
- task_call MUST include commands with the task and ALL collected input fields.
- Never return task_call with commands=[].
- If required data is missing, ask exactly ONE clarifying question as response_type=clarification with commands=[].

MUTATIONS RULE (mandatory):
- For mutating intents (create, update, delete), return response_type=confirmation_request.
- confirmation_request MUST include commands with the task and ALL collected input fields.
- Never return confirmation_request with commands=[].
- If required data is missing, ask exactly ONE clarifying question as response_type=clarification with commands=[].
- Do not guess or invent missing data.

PROMPT;
        }

        if ($actionclass === explain_text::class) {
            return <<<'PROMPT'
You are an AI reasoning assistant for the "{{bookingname}}" context.

ACTION-SPECIFIC GUIDANCE FOR FINAL REASONING:
- Base your answer on the latest user message, observations, and assistant state.
- Be concise, precise, and helpful.
- Do not propose extra tool calls if the available context already answers the request.
- Use only exact task names from the TASK CATALOG below.
- Never invent aliases or category names such as docs.search or documentation.query.
- If observations already contain sufficient information, MUST return
    response_type="sufficient" with commands=[] and NO message field.
- If information is still missing for a mutating action, ask one focused clarification question.
- In final reasoning mode, prefer response_type=sufficient (no message) when observations answer the request.
- For documented read-only questions, if observations are still insufficient,
    you MAY return one documentation task_call from the task catalog to retrieve more relevant information.
- If you need another documentation task_call, prefer grounded candidate paths or topic hints over guessed root doc_path values.
- In final reasoning mode, do NOT use response_type=confirm_pending.
- In final reasoning mode, do NOT use response_type=error when observations already contain usable findings.
- In final reasoning mode, do NOT promise further searching/tool calls; summarize the available findings now.
- If observations already include concrete domain-specific configuration fields or labels,
    answer directly and do NOT ask the user to reconfirm intent.

PROMPT;
        }

        if (
            $actionclass === generate_text::class
            || $actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY
        ) {
            return <<<'PROMPT'
You are an expert that composes polished, helpful answers for the "{{bookingname}}" context.

SYNTHESIS TASK:
- Retrieved information is provided in the OBSERVATION blocks. Your job is to write a high-quality final answer.
- Do NOT call any tools or issue task_calls.
- Always return response_type="sufficient" with commands=[].
- OUTPUT FORMAT IS STRICT: return exactly one JSON object and nothing else.
- The first non-whitespace character MUST be "{" and the last non-whitespace character MUST be "}".
- Never output markdown, code fences, headings, or prose outside JSON.
- Put the complete user-facing explanation only into the JSON field "message".
- Required top-level keys: response_type, message, user_lang, commands.
- Optional top-level keys: used_triggers (may be omitted for synthesis).
- LANGUAGE: Detect the language from the [USER] message and write the entire answer in that language.
- Match the user language exactly unless the user requests otherwise.
- QUALITY: Write a thorough, well-structured explanation - not a verbatim copy of observations.
    * Explain WHY each step matters, not just WHAT to do.
    * Use headings (##) for major sections when appropriate.
    * Use numbered lists for step-by-step instructions.
    * Use bullet points for lists of options or features.
    * Add a brief intro sentence and a closing note where helpful.
- Keep all links from the observations intact and clickable.
- Do not mention "documentation", "observations", or internal system details.
- Do not invent steps or features not supported by the provided observations.
PROMPT;
        }

        return <<<'PROMPT'
You are an AI agent for the "{{bookingname}}" context.

ACTION-SPECIFIC GUIDANCE:
- Use only the provided task catalog and schema.
- Do not invent domain-specific identifiers or unsupported actions.
- For read-only intents, prefer direct task_call handling.
- For mutating intents, ask only for missing required data before confirmation.
PROMPT;
    }

    /**
     * Return the safe default prefix for final synthesis style customization.
     *
     * @return string
     */
    public static function get_default_summary_prompt_prefix(): string {
        return 'You are an expert that composes polished, helpful answers for the "ai" context.';
    }

    /**
     * Return absolute path to the default initial prompt markdown file.
     *
     * @return string
     */
    public static function get_default_initial_prompt_template_path(): string {
        return __DIR__ . '/prompts/initial_system_prompt.md';
    }

    /**
     * Build the state-based system prompt with compact task metadata embedded.
     *
     * @param  int    $cmid
     * @param  string $steptype
     * @param  string $actionclass
     * @param  bool   $hasobservations
     * @param  array  $adaptivecatalog Optional adaptive task catalog (reduced by recency/tier). If null, uses full catalog.
     * @param  array  $systemtaskcatalog Optional exact task catalog to embed into SYSTEM placeholders.
     * @param  bool   $isfirstassistantturn True when no assistant message exists yet in this thread.
     * @param  bool   $includetaskcatalog If true, embed task catalog placeholder in SYSTEM block.
     * @return string System prompt text.
     */
    private function build_system_prompt(
        int $cmid,
        int $userid,
        int $contextid,
        string $steptype = self::STEP_TYPE_TOOL_CALL_PARSE,
        string $actionclass = generate_text::class,
        bool $hasobservations = false,
        ?array $adaptivecatalog = null,
        array $systemtaskcatalog = [],
        bool $isfirstassistantturn = false,
        bool $includetaskcatalog = false
    ): string {
        $evaluator = new task_executability_evaluator($this->registry, new authorization_service());
        $schemas = $this->registry->get_all_schemas_for_context($evaluator, $userid, $contextid);
        $taskcatalog = $adaptivecatalog ?? $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid);
        if (empty($systemtaskcatalog) && $this->normalize_step_type($steptype) === self::STEP_TYPE_TOOL_CALL_PARSE) {
            $taskcatalog = $this->slim_prompt_catalog_for_planner($taskcatalog);
        }
        if (!empty($systemtaskcatalog)) {
            $taskcatalog = $systemtaskcatalog;
        }
        $tasklist = implode(', ', array_keys($schemas));
        $fullschemajson = json_encode($schemas, JSON_UNESCAPED_UNICODE);
        $taskcatalogjson = json_encode($taskcatalog, JSON_UNESCAPED_UNICODE);
        $systemtaskcatalogjson = $includetaskcatalog ? (string)$taskcatalogjson : '[]';

        // Keep core operational prompts fixed to avoid admin misconfiguration risks.
        // Only a single optional synthesis prefix is allowed via aiinitialprompt_summarise_text.
        $template = self::get_default_initial_prompt_template_for_action($actionclass);

        if (
            $actionclass === generate_text::class
            || $actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY
        ) {
            // Only prepend a custom admin-configured prefix; the default template already
            // contains the "You are an expert..." opening, so skip when no override is set.
            $summaryprefix = trim((string)(get_config('bookingextension_agent', 'aiinitialprompt_summarise_text') ?? ''));
            if ($summaryprefix !== '') {
                $trimmedtemplate = ltrim($template);
                $isexpertopening = static function (string $text): bool {
                    return preg_match(
                        '/^You are an expert that composes polished, helpful answers for the /',
                        trim($text)
                    ) === 1;
                };

                // Avoid duplicate synthesis intros when both prefix and template start
                // with the same expert-opening sentence.
                if ($isexpertopening($summaryprefix) && $isexpertopening($trimmedtemplate)) {
                    $newlinepos = strpos($trimmedtemplate, "\n");
                    if ($newlinepos === false) {
                        $template = $summaryprefix;
                    } else {
                        $template = $summaryprefix . "\n"
                            . ltrim(substr($trimmedtemplate, $newlinepos + 1), "\n");
                    }
                } else {
                    $template = $summaryprefix . "\n\n" . $trimmedtemplate;
                }
            }
        }

        $prompt = strtr($template, [
            // Keep placeholders stable across requests for better prompt-prefix caching.
            '{{bookingname}}' => '[SYSTEM_RUNTIME.booking_name]',
            '{{timezonename}}' => '[SYSTEM_RUNTIME.timezone]',
            '{{nowiso}}' => '[SYSTEM_RUNTIME.now_iso]',
            '{{tasklist}}' => $tasklist,
            '{{schemajson}}' => $systemtaskcatalogjson,
            '{{taskcatalogjson}}' => $systemtaskcatalogjson,
            '{{fullschemajson}}' => (string)$fullschemajson,
        ]);

        // Append all NON-OPTIONAL policies from centralized policy builder.
        // This is the single source of truth for dynamic policy appends.
        $policybuilder = new prompt_policy_builder();
        $prompt .= $policybuilder->build_all_policies(
            $steptype,
            $hasobservations,
            $isfirstassistantturn
        );

        return $prompt;
    }

    /**
     * Reduce task catalog entries to planner-facing routing metadata only.
     *
     * @param array $taskcatalog
     * @return array
     */
    private function slim_prompt_catalog_for_planner(array $taskcatalog): array {
        $slimcatalog = [];

        foreach ($taskcatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $taskname = (string)($entry['task'] ?? '');
            if ($taskname === '') {
                continue;
            }

            $newentry = [
                'task' => $taskname,
                'readonly' => (bool)($entry['readonly'] ?? false),
                'intent' => (string)($entry['intent'] ?? ''),
                'minimal_input' => (array)($entry['minimal_input'] ?? []),
                'example_input' => $this->compact_catalog_example_input((array)($entry['example_input'] ?? [])),
                'description' => $this->compact_catalog_description((string)($entry['description'] ?? '')),
                'message_triggers' => $this->compact_catalog_message_triggers((array)($entry['message_triggers'] ?? [])),
            ];

            if (empty($newentry['example_input']) || $newentry['minimal_input'] == $newentry['example_input']) {
                unset($newentry['example_input']);
            }

            $slimcatalog[] = $newentry;
        }

        return $slimcatalog;
    }

    /**
     * Put embedding matches first while preserving the adaptive catalog as a safety net.
     *
     * @param array<int,array<string,mixed>> $prioritycatalog
     * @param array<int,array<string,mixed>> $fallbackcatalog
     * @return array<int,array<string,mixed>>
     */
    private function merge_embedding_subset_with_fallback_catalog(array $prioritycatalog, array $fallbackcatalog): array {
        $merged = [];
        $seen = [];
        $add = static function (array $entries) use (&$merged, &$seen): void {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $taskname = trim((string)($entry['task'] ?? ''));
                if ($taskname === '' || isset($seen[$taskname])) {
                    continue;
                }
                $seen[$taskname] = true;
                $merged[] = $entry;
            }
        };

        $add($prioritycatalog);
        $add($fallbackcatalog);

        return $merged;
    }

    /**
     * Keep task descriptions compact for planner routing.
     *
     * @param string $description
     * @return string
     */
    private function compact_catalog_description(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if ($normalized === '') {
            return '';
        }

        if (core_text::strlen($normalized) <= 140) {
            return $normalized;
        }

        return rtrim(core_text::substr($normalized, 0, 137)) . '...';
    }

    /**
     * Keep example_input as a compact property-name list for routing hints.
     *
     * This preserves only explicitly declared example fields while avoiding
     * token-heavy concrete sample payloads.
     *
     * @param array $exampleinput
     * @return array<int,string>
     */
    private function compact_catalog_example_input(array $exampleinput): array {
        $keys = [];

        foreach (array_keys($exampleinput) as $key) {
            $name = trim((string)$key);
            if ($name !== '') {
                $keys[] = $name;
            }
        }

        $keys = array_values(array_unique($keys));
        if (empty($keys)) {
            return [];
        }

        return array_slice($keys, 0, 6);
    }

    /**
     * Drop verbose trigger examples and keep compact id + short description only.
     *
     * @param array $triggers
     * @return array<int,array<string,string>>
     */
    private function compact_catalog_message_triggers(array $triggers): array {
        $compact = [];

        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }

            $id = trim((string)($trigger['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $description = trim((string)($trigger['description'] ?? ''));
            $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

            $row = ['id' => $id];
            if ($description !== '') {
                $row['description'] = core_text::substr($description, 0, 140);
            }

            $compact[] = $row;
        }

        return $compact;
    }

    /**
     * Extract task names from recent messages for recency boosting.
     *
     * Scans assistant responses for attempted/executed task calls (from message metadata).
     *
     * @param \stdClass[] $messages
     * @return array<string> Task names in reverse chronological order (most recent first).
     */
    private function extract_recent_task_names_from_messages(array $messages): array {
        $tasknames = [];
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $msg = $messages[$i];
            if ((string)($msg->role ?? '') === 'assistant' && isset($msg->structuredjson)) {
                $meta = (array)json_decode((string)($msg->structuredjson ?? ''), true);
                // Extract task names from attempted_tasks or commands.
                $attemptedtasks = (array)($meta['attempted_tasks'] ?? []);
                if (!empty($attemptedtasks)) {
                    foreach ($attemptedtasks as $taskname) {
                        if (!in_array($taskname, $tasknames, true)) {
                            $tasknames[] = (string)$taskname;
                        }
                    }
                }
                // Also check commands if no attempted_tasks (fallback).
                $commands = (array)($meta['commands'] ?? []);
                foreach ($commands as $cmd) {
                    if (is_array($cmd) && isset($cmd['task'])) {
                        $taskname = (string)($cmd['task'] ?? '');
                        if ($taskname !== '' && !in_array($taskname, $tasknames, true)) {
                            $tasknames[] = $taskname;
                        }
                    }
                }
            }
        }
        return $tasknames;
    }

    /**
     * Determine whether this thread has already emitted an assistant message.
     *
     * @param array $messages
     * @return bool
     */
    private function is_first_assistant_turn(array $messages): bool {
        foreach ($messages as $message) {
            if ((string)($message->role ?? '') === 'assistant') {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the full prompt string from system prompt + message history + observations.
     *
     * Observations (from prior internal loop tool executions) are injected after the
     * conversation history and before the [ASSISTANT] marker so the LLM can incorporate
     * tool results into its next decision without those results ever being stored as
     * conversation messages.
     *
     * @param  string      $systemprompt
     * @param  \stdClass[] $messages
     * @param  string[]    $observations  Structured observation strings (may be empty).
     * @param  string      $runtimecontext Dynamic per-request context appended after static system prompt.
     * @param  string[]    $plannertracehistory Full planner trace history from thread metadata.
     * @param  bool        $autoconfirmmode Whether confirmation is already allowed for this thread.
     * @return string
     */
    private function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations = [],
        string $steptype = self::STEP_TYPE_TOOL_CALL_PARSE,
        string $runtimecontext = '',
        array $plannertracehistory = [],
        bool $autoconfirmmode = false
    ): string {
        $normalizedsteptype = $this->normalize_step_type($steptype);
        $trimmedmessages = array_slice($messages, -$this->get_history_limit_for_step($normalizedsteptype));

        if ($normalizedsteptype === self::STEP_TYPE_FINAL_REASONING) {
            $contextualguidance = $this->build_contextual_guidance($trimmedmessages);
            if ($contextualguidance !== '') {
                $systemprompt .= "\n\nCONTEXT-SPECIFIC GUIDANCE:\n" . $contextualguidance;
            }
        }

        $assistantstateblocks = [];
        if ($normalizedsteptype === self::STEP_TYPE_FINAL_REASONING) {
            $assistantstateblocks = $this->build_assistant_state_blocks($trimmedmessages);
        }
        if (!empty($assistantstateblocks)) {
            // Append FOLLOW-UP STATE POLICY from centralized builder.
            $policybuilder = new prompt_policy_builder();
            $systemprompt .= "\n\n" . $policybuilder->build_follow_up_state_policy();
        }

        $parts = ["[SYSTEM]\n{$systemprompt}"];

        if ($runtimecontext !== '') {
            $parts[] = "[SYSTEM_RUNTIME]\n{$runtimecontext}";
        }

        foreach ($trimmedmessages as $msg) {
            $role    = strtoupper($msg->role ?? 'user');
            $content = $msg->content ?? '';
            $parts[] = "[{$role}]\n{$content}";
        }

        foreach ($assistantstateblocks as $idx => $block) {
            $num = $idx + 1;
            $parts[] = "[ASSISTANT_STATE {$num}]\n{$block}";
        }

        $parts = $this->append_planner_traces_and_observations($parts, $plannertracehistory, $observations);

        $localoutputcontract = $this->build_local_output_contract_block($normalizedsteptype, $autoconfirmmode);
        if ($localoutputcontract !== '') {
            $parts[] = "[OUTPUT_CONTRACT]\n{$localoutputcontract}";
        }

        $parts[] = '[ASSISTANT]';
        return implode("\n\n", $parts);
    }

    /**
     * Build a local output contract reminder close to the assistant output slot.
     *
     * @param string $steptype
     * @param bool $autoconfirmmode
     * @return string
     */
    private function build_local_output_contract_block(string $steptype, bool $autoconfirmmode = false): string {
        $normalized = $this->normalize_step_type($steptype);
        if ($normalized === self::STEP_TYPE_FINAL_SYNTHESIS) {
            return '';
        }

        $lines = [
            'Return exactly one valid JSON object and nothing else.',
            'Do not output markdown, code fences, prose, or bullet lists outside JSON.',
            'Allowed response_type: task_call, confirmation_request, confirm_pending, clarification, sufficient, error.',
            'For task_call/confirmation_request: commands must be a non-empty array.',
            'For clarification/confirm_pending/sufficient/error: commands must be [].',
            '- response_type for ALL mutating actions: always "confirmation_request" (never "task_call"). This does NOT change.',
        ];

        if ($autoconfirmmode) {
            $lines[] = 'Auto-confirm mode is active.';
            $lines[] = 'Do NOT ask permission or phrase messages as questions. Instead: write a short statement announcing what will be executed.';
            $lines[] = 'Treat recent ASSISTANT/ASSISTANT_STATE execution evidence as authoritative. Never re-emit an already-executed action (same task+input signature).';
            $lines[] = 'If action already executed: report completion or skip to next unexecuted action.';
            $lines[] = 'Next unexecuted mutation → response_type="confirmation_request".';
        }

        return implode("\n", $lines);
    }

    /**
     * Normalize planner trace history values from thread metadata.
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalize_planner_trace_history($value): array {
        if (!is_array($value)) {
            return [];
        }

        $history = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                if ($entry !== '') {
                    $history[] = $entry;
                }
                continue;
            }

            if (is_array($entry)) {
                $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($json) && $json !== '') {
                    $history[] = $json;
                }
            }
        }

        return $history;
    }

    /**
     * Append planner traces and observations in interleaved order.
     *
     * Desired shape: USER, PLANNER_TRACE 1, OBSERVATION 1, PLANNER_TRACE 2, OBSERVATION 2, ...
     *
     * @param array<int,string> $parts
     * @param array<int,string> $plannertracehistory
     * @param array<int,string> $observations
     * @return array<int,string>
     */
    private function append_planner_traces_and_observations(
        array $parts,
        array $plannertracehistory,
        array $observations
    ): array {
        $max = max(count($plannertracehistory), count($observations));
        for ($i = 0; $i < $max; $i++) {
            $num = $i + 1;

            if (isset($plannertracehistory[$i])) {
                $parts[] = "[PLANNER_TRACE {$num}]\n" . $plannertracehistory[$i];
            }

            if (isset($observations[$i])) {
                $parts[] = "[OBSERVATION {$num}]\n" . (string)$observations[$i];
            }
        }

        return $parts;
    }

    /**
     * Build a small dynamic runtime context block for this request.
     *
     * Keeping per-request values out of the static [SYSTEM] block improves
     * prompt-prefix stability for upstream prompt caching.
     *
     * @param int $cmid
     * @param string $steptype
     * @param bool $isfirstassistantturn
     * @param bool $hasobservations
     * @param array $taskcatalog
     * @return string
     */
    private function build_runtime_context_block(
        int $threadid,
        int $cmid,
        string $steptype = self::STEP_TYPE_TOOL_CALL_PARSE,
        bool $isfirstassistantturn = false,
        bool $hasobservations = false,
        array $taskcatalog = [],
        array $unavailabletaskcatalog = [],
        array $messages = []
    ): string {
        $timezonename = (string)(get_config('core', 'timezone') ?? '');
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }

        try {
            $tz = new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            $timezonename = date_default_timezone_get();
            $tz = new \DateTimeZone($timezonename);
        }

        $cm = get_coursemodule_from_id('booking', $cmid);
        $bookingname = $cm ? format_string($cm->name) : 'this booking instance';
        $nowiso = (new \DateTime('now', $tz))->format(\DateTimeInterface::ATOM);

        $lines = [
            'booking_name: ' . $bookingname,
            'timezone: ' . $timezonename,
            'now_iso: ' . $nowiso,
        ];

        // Keep first-turn language enforcement in SYSTEM_RUNTIME so static SYSTEM
        // prompt prefixes remain cache-friendly across requests.
        if (
            $this->normalize_step_type($steptype) === self::STEP_TYPE_TOOL_CALL_PARSE
            && $isfirstassistantturn
            && !$hasobservations
        ) {
            $lines[] = '';
            $lines[] = 'NON-OPTIONAL LANGUAGE POLICY:';
            $lines[] = "- Include valid ISO 639-1 value 'user_lang'.";
        }

        $taskcatalogjson = json_encode($taskcatalog, JSON_UNESCAPED_UNICODE);
        if (is_string($taskcatalogjson) && $taskcatalogjson !== '') {
            $lines[] = '';
            $lines[] = 'TASK CATALOG:';
            $lines[] = $taskcatalogjson;
        }

        if (!empty($unavailabletaskcatalog)) {
            $unavailablejson = json_encode($unavailabletaskcatalog, JSON_UNESCAPED_UNICODE);
            if (is_string($unavailablejson) && $unavailablejson !== '') {
                $lines[] = '';
                $lines[] = 'UNAVAILABLE TASKS:';
                $lines[] = $unavailablejson;
            }
        }

        $completedcommands = $this->extract_completed_commands_from_messages($messages);
        $completedcommands = $this->merge_completed_commands_from_queue($threadid, $completedcommands);
        if (!empty($completedcommands)) {
            $lines[] = '';
            $lines[] = 'completed_commands:';
            foreach ($completedcommands as $command) {
                $json = json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || $json === '') {
                    continue;
                }
                $lines[] = '  - ' . $json;
            }
        }

        $observationledger = new execution_observation_ledger($this->store);
        $completedobservations = $observationledger->get_recent_for_runtime($threadid, 12);
        if (!empty($completedobservations)) {
            $lines[] = '';
            $lines[] = 'completed_observations:';
            foreach ($completedobservations as $observation) {
                $json = json_encode($observation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || $json === '') {
                    continue;
                }
                $lines[] = '  - ' . $json;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build compact runtime metadata for tasks that are known but currently unavailable.
     *
     * @param array<string,array<string,mixed>> $evaluations
     * @param array<string,string> $descriptionindex
     * @param array<int,string> $taskfilter
     * @return array<int,array<string,string>>
     */
    private function build_unavailable_task_catalog_for_runtime(
        array $evaluations,
        array $descriptionindex = [],
        array $taskfilter = []
    ): array {
        $catalog = [];
        $taskfiltermap = [];

        foreach ($taskfilter as $taskname) {
            $name = trim((string)$taskname);
            if ($name !== '') {
                $taskfiltermap[$name] = true;
            }
        }

        foreach ($evaluations as $taskname => $evaluation) {
            if (!empty($taskfiltermap) && empty($taskfiltermap[(string)$taskname])) {
                continue;
            }

            $state = trim((string)($evaluation['executable_state'] ?? ''));
            if ($state !== 'deny') {
                continue;
            }

            $reason = trim((string)($evaluation['deny_reason'] ?? ''));
            $availability = 'not_active_now';
            if ($reason === task_contract_validator::DENY_MISSING_CAPABILITY) {
                $availability = 'not_active_for_you';
            } else if ($reason === task_contract_validator::DENY_CONTEXT_INVALID) {
                $availability = 'invalid_context';
            } else if ($reason === task_contract_validator::DENY_RUNTIME_DISABLED) {
                $availability = 'runtime_disabled';
            }

            $catalog[] = [
                'task' => (string)$taskname,
                'availability' => $availability,
                'description' => (string)($descriptionindex[(string)$taskname] ?? ''),
            ];
        }

        if (count($catalog) > 60) {
            $catalog = array_slice($catalog, 0, 60);
        }

        return $catalog;
    }

    /**
     * Build task-description lookup map from prompt contracts.
     *
     * @param array<int,array<string,mixed>> $promptcontracts
     * @return array<string,string>
     */
    private function build_task_description_index(array $promptcontracts): array {
        $index = [];

        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $taskname = trim((string)($contract['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $index[$taskname] = trim((string)($contract['description'] ?? ''));
        }

        return $index;
    }

    /**
     * Extract recently completed commands (task + executed input) from assistant state.
     *
     * @param array $messages
     * @return array<int,array<string,mixed>>
     */
    private function extract_completed_commands_from_messages(array $messages): array {
        $completed = [];
        $latestassistantpayload = null;
        $fallbackassistantpayload = null;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured) || empty($structured)) {
                continue;
            }

            if (!is_array($fallbackassistantpayload)) {
                $fallbackassistantpayload = $structured;
            }

            $loopresults = (array)($structured['loop_results'] ?? []);
            $results = (array)($structured['results'] ?? []);
            if (!empty($loopresults) || !empty($results)) {
                $latestassistantpayload = $structured;
                break;
            }
        }

        if (!is_array($latestassistantpayload)) {
            $latestassistantpayload = $fallbackassistantpayload;
        }

        if (!is_array($latestassistantpayload) || empty($latestassistantpayload)) {
            return [];
        }

        $results = (array)($latestassistantpayload['loop_results'] ?? []);
        if (empty($results)) {
            $results = (array)($latestassistantpayload['results'] ?? []);
        }

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = trim((string)($entry['status'] ?? ''));
            if ($status !== 'executed') {
                continue;
            }

            $task = trim((string)($entry['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $input = (array)($entry['executed_input'] ?? $entry['input'] ?? []);
            $compact = ['task' => $task];
            $normalizedinput = $this->normalize_completed_command_input($input);
            if (!empty($normalizedinput)) {
                $compact['input'] = $normalizedinput;
            }
            $completed[] = $compact;
        }

        if (count($completed) > 12) {
            $completed = array_slice($completed, -12);
        }

        return $completed;
    }

    /**
     * Merge queue-sourced executed commands into completed command history.
     *
     * Uses succeeded queue items as authoritative cross-step execution memory.
     *
     * @param int $threadid
     * @param array<int,array<string,mixed>> $existing
     * @return array<int,array<string,mixed>>
     */
    private function merge_completed_commands_from_queue(int $threadid, array $existing): array {
        if ($threadid <= 0) {
            return $existing;
        }

        $manager = new queue_manager($this->store);
        $queueitems = $manager->get_queue_items($threadid);
        if (empty($queueitems)) {
            return $existing;
        }

        $queuecompleted = [];
        $seen = [];

        foreach ($queueitems as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int)($item['thread_id'] ?? 0) !== $threadid) {
                continue;
            }

            if (trim((string)($item['status'] ?? '')) !== 'succeeded') {
                continue;
            }

            $task = trim((string)($item['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $input = [];
            if (is_array($item['prepared_input'] ?? null)) {
                $input = (array)$item['prepared_input'];
            } else if (is_array($item['input'] ?? null)) {
                $input = (array)$item['input'];
            }

            $compact = ['task' => $task];
            $normalizedinput = $this->normalize_completed_command_input($input);
            if (!empty($normalizedinput)) {
                $compact['input'] = $normalizedinput;
            }

            $signature = $this->build_completed_command_signature($compact);
            if ($signature === '' || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $queuecompleted[] = $compact;
        }

        // Queue is authoritative for completed mutation history in the current thread.
        // Only if no succeeded queue items exist, fall back to message-derived evidence.
        $merged = !empty($queuecompleted) ? $queuecompleted : $existing;

        if (count($merged) > 12) {
            $merged = array_slice($merged, -12);
        }

        return $merged;
    }

    /**
     * Build a deterministic signature for completed command deduplication.
     *
     * @param array<string,mixed> $command
     * @return string
     */
    private function build_completed_command_signature(array $command): string {
        $task = trim((string)($command['task'] ?? ''));
        if ($task === '') {
            return '';
        }

        $input = [];
        if (is_array($command['input'] ?? null)) {
            $input = (array)$command['input'];
        }

        ksort($input);
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        return hash('sha256', $task . '|' . $json);
    }
    /**
     * Normalize executed input for SYSTEM_RUNTIME.completed_commands.
     *
     * Keeps stable planner-relevant parameters while trimming noisy payloads.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    private function normalize_completed_command_input(array $input): array {
        $dropkeys = [
            'confirmed',
            'outputlang',
            'lang',
            'user_lang',
            'sessiontoken',
            'sesskey',
        ];

        $normalized = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $dropkeys, true)) {
                continue;
            }

            $cleanvalue = $this->normalize_completed_command_value($value);
            if ($cleanvalue === null) {
                continue;
            }

            $normalized[$key] = $cleanvalue;
        }

        return $normalized;
    }

    /**
     * Normalize one completed command value recursively.
     *
     * @param mixed $value
     * @return mixed|null
     */
    private function normalize_completed_command_value($value) {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            return core_text::substr($trimmed, 0, 160);
        }

        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count >= 20) {
                    break;
                }

                $normalized = $this->normalize_completed_command_value($v);
                if ($normalized === null) {
                    continue;
                }

                if (is_string($k)) {
                    $out[$k] = $normalized;
                } else {
                    $out[] = $normalized;
                }
                $count++;
            }

            return empty($out) ? null : $out;
        }

        return null;
    }

    /**
     * Detect whether observations only contain framework-authored retry hints.
     *
     * @param array $observations
     * @return bool
     */
    private function observations_are_framework_retry_hints(array $observations): bool {
        $seen = false;

        foreach ($observations as $observation) {
            $text = trim((string)$observation);
            if ($text === '') {
                continue;
            }

            $seen = true;
            if (!str_starts_with($text, 'RETRY_HINT:')) {
                return false;
            }
        }

        return $seen;
    }

    /**
     * Normalize orchestrator step type values to supported profiles.
     *
     * @param string $steptype
     * @return string
     */
    private function normalize_step_type(string $steptype): string {
        $normalized = trim(core_text::strtolower($steptype));
        if ($normalized === self::STEP_TYPE_FINAL_REASONING) {
            return self::STEP_TYPE_FINAL_REASONING;
        }
        if ($normalized === self::STEP_TYPE_FINAL_SYNTHESIS) {
            return self::STEP_TYPE_FINAL_SYNTHESIS;
        }
        if ($normalized === self::STEP_TYPE_SIMPLE_RETRIEVAL) {
            return self::STEP_TYPE_SIMPLE_RETRIEVAL;
        }
        return self::STEP_TYPE_TOOL_CALL_PARSE;
    }

    /**
     * Resolve admin setting key for initial prompt templates per step profile.
     *
     * @param string $steptype
     * @return string
     */
    private function get_initial_prompt_config_key(string $steptype): string {
        if ($steptype === self::STEP_TYPE_FINAL_REASONING) {
            return 'aiinitialprompt_final_reasoning';
        }
        if ($steptype === self::STEP_TYPE_FINAL_SYNTHESIS) {
            return 'aiinitialprompt_final_synthesis';
        }
        if ($steptype === self::STEP_TYPE_SIMPLE_RETRIEVAL) {
            return 'aiinitialprompt_simple_retrieval';
        }
        return 'aiinitialprompt_tool_call_parse';
    }

    /**
     * Resolve the admin config key for action-specific initial prompts.
     *
     * @param string $actionclass
     * @return string
     */
    private function get_action_initial_prompt_config_key(string $actionclass): string {
        if (
            $actionclass === summarise_text::class
            || $actionclass === self::WB_ACTION_PLANNER_DECIDE
        ) {
            return 'aiinitialprompt_summarise_text';
        }
        if ($actionclass === explain_text::class) {
            return 'aiinitialprompt_explain_text';
        }
        if (
            $actionclass === generate_text::class
            || $actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY
        ) {
            return 'aiinitialprompt_generate_text';
        }
        return '';
    }

    /**
     * Return history depth per prompt profile.
     *
     * Full thread context is required so follow-up turns do not lose prior
     * user questions or assistant answers.
     *
     * @param string $steptype
     * @return int
     */
    private function get_history_limit_for_step(string $steptype): int {
        return PHP_INT_MAX;
    }

    /**
     * Treat empty or legacy full-template values as unset config for prompt fallback.
     *
     * @param string $template
     * @param string $legacydefault
     * @return string
     */
    private function normalize_config_prompt_template(string $template, string $legacydefault): string {
        $trimmed = trim($template);
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed === $legacydefault) {
            return '';
        }
        return $template;
    }

    /**
     * Route to action classes by step profile for OpenAI providers, with fallback.
     *
     * @param ai_manager $manager
     * @param context_module $context
     * @param string $steptype
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    private function resolve_action_class_for_step(ai_manager $manager, context_module $context, string $steptype): array {
        if ($this->is_wunderbyte_routing_available($manager)) {
            if (
                $steptype === self::STEP_TYPE_FINAL_REASONING
                || $steptype === self::STEP_TYPE_FINAL_SYNTHESIS
            ) {
                return [
                    'actionclass' => self::WB_ACTION_GENERATE_AGENT_REPLY,
                    'routepolicy' => 'wunderbyte',
                    'routingfallback' => false,
                ];
            }

            return [
                'actionclass' => self::WB_ACTION_PLANNER_DECIDE,
                'routepolicy' => 'wunderbyte',
                'routingfallback' => false,
            ];
        }

        if (!$this->should_use_openai_step_routing($manager)) {
            return [
                'actionclass' => generate_text::class,
                'routepolicy' => 'default',
                'routingfallback' => false,
            ];
        }

        if ($steptype === self::STEP_TYPE_FINAL_REASONING || $steptype === self::STEP_TYPE_FINAL_SYNTHESIS) {
            if ($this->is_action_available_in_context($manager, $context, generate_text::class)) {
                return [
                    'actionclass' => generate_text::class,
                    'routepolicy' => 'openai',
                    'routingfallback' => false,
                ];
            }
            if ($this->is_action_available_in_context($manager, $context, explain_text::class)) {
                return [
                    'actionclass' => explain_text::class,
                    'routepolicy' => 'openai',
                    'routingfallback' => true,
                ];
            }
            return [
                'actionclass' => generate_text::class,
                'routepolicy' => 'openai',
                'routingfallback' => true,
            ];
        }

        if ($this->is_action_available_in_context($manager, $context, summarise_text::class)) {
            return [
                'actionclass' => summarise_text::class,
                'routepolicy' => 'openai',
                'routingfallback' => false,
            ];
        }

        return [
            'actionclass' => generate_text::class,
            'routepolicy' => 'openai',
            'routingfallback' => true,
        ];
    }

    /**
     * Use step-based action routing only when OpenAI provider is active for text actions.
     *
     * @param ai_manager $manager
     * @return bool
     */
    private function should_use_openai_step_routing(ai_manager $manager): bool {
        try {
            $providers = $manager->get_providers_for_actions([generate_text::class], true);
            $forgenerate = (array)($providers[generate_text::class] ?? []);
            if (empty($forgenerate)) {
                return false;
            }
            $primary = reset($forgenerate);
            return (string)($primary->provider ?? '') === 'aiprovider_openai';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Determine whether wunderbyte-specific action routing is available.
     *
     * @param ai_manager $manager
     * @return bool
     */
    private function is_wunderbyte_routing_available(ai_manager $manager): bool {
        try {
            // Check if Wunderbyte provider instances exist and are active.
            $instances = $manager->get_provider_instances(['provider' => 'aiprovider_wunderbyte\\provider']);
            if (empty($instances)) {
                return false;
            }

            foreach ($instances as $instance) {
                if (empty($instance->enabled)) {
                    continue;
                }

                if (method_exists($instance, 'is_provider_configured') && !$instance->is_provider_configured()) {
                    continue;
                }

                // Provider exists, is enabled, and is configured.
                // Wunderbyte action classes will be available if the provider is installed.
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve the primary enabled provider plugin for an action.
     *
     * @param ai_manager $manager
     * @param string $actionclass
     * @return string
     */
    private function resolve_primary_provider_for_action(ai_manager $manager, string $actionclass): string {
        try {
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            $list = (array)($providers[$actionclass] ?? []);
            if (empty($list)) {
                return '';
            }
            $primary = reset($list);
            return (string)($primary->provider ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Build compact orchestrator telemetry in source field for local_wbagent_ai_llm_debug.
     *
     * @param string $steptype
     * @param string $actionclass
     * @param string $routepolicy
     * @param bool $routingfallback
     * @param string $primaryprovider
     * @param int $historycount
     * @param int $observationcount
     * @param string $catalogselectionmode
     * @param string $embeddingstatus
     * @param int $catalogsize
     * @param bool $embeddingrebuildqueued
     * @param bool $exception
     * @return string
     */
    private function build_orchestrator_debug_source(
        string $steptype,
        string $actionclass,
        string $routepolicy,
        bool $routingfallback,
        string $primaryprovider,
        int $historycount,
        int $observationcount,
        string $catalogselectionmode,
        string $embeddingstatus,
        int $catalogsize,
        bool $embeddingrebuildqueued,
        bool $exception
    ): string {
        $stepmap = [
            self::STEP_TYPE_TOOL_CALL_PARSE => 'tcp',
            self::STEP_TYPE_SIMPLE_RETRIEVAL => 'sr',
            self::STEP_TYPE_FINAL_REASONING => 'fr',
            self::STEP_TYPE_FINAL_SYNTHESIS => 'syn',
        ];
        $actionmap = [
            generate_text::class => 'gen',
            summarise_text::class => 'sum',
            explain_text::class => 'exp',
            self::WB_ACTION_PLANNER_DECIDE => 'wpl',
            self::WB_ACTION_GENERATE_AGENT_REPLY => 'wgr',
        ];

        $step = $stepmap[$steptype] ?? 'unk';
        $action = $actionmap[$actionclass] ?? 'oth';
        $route = 'df';
        if ($routepolicy === 'openai') {
            $route = 'oa';
        } else if ($routepolicy === 'wunderbyte') {
            $route = 'wb';
        }
        $provider = $this->short_provider_for_debug($primaryprovider);

        $source = 'orc'
            . '|st=' . $step
            . '|ac=' . $action
            . '|rt=' . $route
            . '|fb=' . ($routingfallback ? '1' : '0')
            . '|pv=' . $provider
            . '|hm=' . max(0, $historycount)
            . '|ob=' . max(0, $observationcount)
            . '|cm=' . $this->short_debug_token($catalogselectionmode)
            . '|em=' . $this->short_debug_token($embeddingstatus)
            . '|tk=' . max(0, $catalogsize)
            . '|rq=' . ($embeddingrebuildqueued ? '1' : '0')
            . '|ex=' . ($exception ? '1' : '0');

        if (core_text::strlen($source) > 100) {
            return core_text::substr($source, 0, 100);
        }

        return $source;
    }

    /**
     * Keep debug token values compact and stable.
     *
     * @param string $value
     * @return string
     */
    private function short_debug_token(string $value): string {
        $normalized = preg_replace('/[^a-z0-9_\-]+/i', '', core_text::strtolower(trim($value)));
        if (!is_string($normalized) || $normalized === '') {
            return 'na';
        }

        if (core_text::strlen($normalized) > 10) {
            return core_text::substr($normalized, 0, 10);
        }

        return $normalized;
    }

    /**
     * Convert provider plugin names to short debug tokens.
     *
     * @param string $provider
     * @return string
     */
    private function short_provider_for_debug(string $provider): string {
        $value = trim(core_text::strtolower($provider));
        if ($value === '') {
            return 'na';
        }
        if ($value === 'aiprovider_openai') {
            return 'oai';
        }
        if (str_starts_with($value, 'aiprovider_')) {
            $value = substr($value, 11);
        }
        if ($value === '') {
            return 'na';
        }
        return core_text::substr($value, 0, 10);
    }

    /**
     * Check action availability with context and global provider state.
     *
     * @param ai_manager $manager
     * @param context_module $context
     * @param string $actionclass
     * @return bool
     */
    private function is_action_available_in_context(ai_manager $manager, context_module $context, string $actionclass): bool {
        if (!$manager->is_action_available($actionclass)) {
            return false;
        }
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return true;
        }
        return $manager->is_action_enabled_in_context($context, $actionclass);
    }

    /**
     * Build compact structured state blocks from recent assistant messages.
     *
     * @param array $messages
     * @return string[]
     */
    private function build_assistant_state_blocks(array $messages): array {
        $states = [];

        foreach ($messages as $msg) {
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured) || empty($structured)) {
                continue;
            }

            $summary = $this->summarize_structured_state($structured);
            if ($summary !== '') {
                $states[] = $summary;
            }
        }

        if (count($states) > 6) {
            $states = array_slice($states, -6);
        }

        return $states;
    }

    /**
     * Summarize one structured assistant payload into a deterministic state line block.
     *
     * @param array $structured
     * @return string
     */
    private function summarize_structured_state(array $structured): string {
        $lines = [];

        $responsetype = trim((string)($structured['response_type'] ?? ''));
        if ($responsetype !== '') {
            $lines[] = 'response_type=' . $responsetype;
        }

        $lang = trim((string)($structured['lang'] ?? ''));
        if ($lang !== '') {
            $lines[] = 'lang=' . $lang;
        }

        $issuecodes = array_values(array_filter(array_map(
            static fn($code): string => trim((string)$code),
            (array)($structured['issue_codes'] ?? [])
        )));
        if (!empty($issuecodes)) {
            $lines[] = 'issue_codes=' . implode(',', array_slice($issuecodes, 0, 8));
        }

        $attemptedtasks = array_values(array_filter(array_map(
            static fn($task): string => trim((string)$task),
            (array)($structured['attempted_tasks'] ?? [])
        )));
        if (!empty($attemptedtasks)) {
            $lines[] = 'attempted_tasks=' . implode(',', array_slice($attemptedtasks, 0, 8));
        }

        $results = (array)($structured['results'] ?? []);
        if (empty($results)) {
            $results = (array)($structured['loop_results'] ?? []);
        }
        foreach ($this->extract_result_facts($results) as $fact) {
            $lines[] = $fact;
        }

        return implode("\n", array_slice($lines, 0, 12));
    }

    /**
     * Extract compact factual lines from structured task results.
     *
     * @param array $results
     * @return string[]
     */
    private function extract_result_facts(array $results): array {
        $facts = [];
        if (empty($results)) {
            return $facts;
        }

        for ($i = count($results) - 1; $i >= 0; $i--) {
            $entry = $results[$i] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $task = trim((string)($entry['task'] ?? ''));
            $status = trim((string)($entry['status'] ?? ''));
            if ($task !== '' || $status !== '') {
                $facts[] = trim('result=' . $task . ' status=' . $status);
            }

            $diagnosis = $entry['diagnosis'] ?? null;
            if (is_array($diagnosis)) {
                $option = trim((string)($diagnosis['optionname'] ?? ''));
                $userstatus = trim((string)($diagnosis['userstatus'] ?? ''));
                $facts[] = trim('diagnosis option=' . $option . ' user_status=' . $userstatus);

                $reasons = array_values(array_filter(array_map(
                    static fn($reason): string => trim((string)$reason),
                    (array)($diagnosis['reasons'] ?? [])
                )));
                if (!empty($reasons)) {
                    $facts[] = 'diagnosis_reasons=' . implode(' | ', array_slice($reasons, 0, 3));
                }
            }

            // Generic: summarize result content via the shared summarizer so any task type
            // (options, users, courses, diagnosis, docs, …) is represented in the state.
            $resultsummary = result_payload_summarizer::describe_result_for_state($entry);
            if ($resultsummary !== '') {
                $facts[] = 'found_results=' . $resultsummary;
            }

            $usermessage = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
            if ($usermessage !== '') {
                $usermessage = trim(preg_replace('/\s+/', ' ', $usermessage) ?? $usermessage);
                $facts[] = 'result_message=' . core_text::substr($usermessage, 0, 220);
            }

            if (count($facts) >= 12) {
                break;
            }
        }

        return array_slice(array_values(array_unique(array_filter($facts))), 0, 12);
    }

    /**
     * Build extra guidance only when specific topics appear in recent messages.
     *
     * @param array $messages
     * @return string
     */
    private function build_contextual_guidance(array $messages): string {
        $joined = '';
        foreach ($messages as $msg) {
            $joined .= "\n" . (string)($msg->content ?? '');
        }
        $joined = core_text::strtolower($joined);

        $guidancelines = [];
        $packs = $this->registry->get_contextual_prompt_packs();
        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            if (!$this->matches_contextual_pack($pack, $joined)) {
                continue;
            }

            $lines = $pack['guidance'] ?? [];
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $guidancelines[] = $line;
                }
            }
        }

        if (empty($guidancelines)) {
            return '';
        }

        return implode("\n", array_values(array_unique($guidancelines)));
    }

    /**
     * Check whether a contextual prompt pack matches current message context.
     *
     * @param array $pack
     * @param string $joined
     * @return bool
     */
    private function matches_contextual_pack(array $pack, string $joined): bool {
        $triggers = $pack['triggers'] ?? [];
        if (!is_array($triggers) || empty($triggers)) {
            return false;
        }

        foreach ($triggers as $trigger) {
            $needle = core_text::strtolower(trim((string)$trigger));
            if ($needle === '') {
                continue;
            }

            if (preg_match('/[\s_\-]/', $needle)) {
                if (strpos($joined, $needle) !== false) {
                    return true;
                }
                continue;
            }

            $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
            if ((bool)preg_match($pattern, $joined)) {
                return true;
            }
        }

        return false;
    }
}
