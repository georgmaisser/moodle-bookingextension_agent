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
use bookingextension_agent\local\wbagent\services\catalog\adaptive_task_catalog_service;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wbagent\services\assistant_state_guidance_service;
use bookingextension_agent\local\wbagent\services\completed_command_history_service;
use bookingextension_agent\local\wbagent\services\execution_observation_ledger;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;
use bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wbagent\services\orchestrator_routing_service;
use bookingextension_agent\local\wbagent\services\provider_routing_util;
use bookingextension_agent\local\wbagent\services\security\authorization_service;

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
    public const EMBEDDINGS_DEFAULT_TOP_K = 4;

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

    /** @var completed_command_history_service */
    private completed_command_history_service $completedhistorysvc;

    /** @var assistant_state_guidance_service */
    private assistant_state_guidance_service $assistantsummariesvc;

    /** @var orchestrator_routing_service */
    private orchestrator_routing_service $orchestratorroutingsvc;

    /** @var orchestrator_prompt_profile_service */
    private orchestrator_prompt_profile_service $promptprofilesvc;

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
        $this->completedhistorysvc = new completed_command_history_service($store);
        $this->assistantsummariesvc = new assistant_state_guidance_service($registry);
        $this->orchestratorroutingsvc = new orchestrator_routing_service(
            self::STEP_TYPE_TOOL_CALL_PARSE,
            self::STEP_TYPE_SIMPLE_RETRIEVAL,
            self::STEP_TYPE_FINAL_REASONING,
            self::STEP_TYPE_FINAL_SYNTHESIS,
            self::WB_ACTION_PLANNER_DECIDE,
            self::WB_ACTION_GENERATE_AGENT_REPLY
        );
        $this->promptprofilesvc = new orchestrator_prompt_profile_service(
            self::STEP_TYPE_TOOL_CALL_PARSE,
            self::STEP_TYPE_SIMPLE_RETRIEVAL,
            self::STEP_TYPE_FINAL_REASONING,
            self::STEP_TYPE_FINAL_SYNTHESIS,
            self::WB_ACTION_PLANNER_DECIDE,
            self::WB_ACTION_GENERATE_AGENT_REPLY
        );
    }

    /**
     * Check whether a Moodle core_ai provider is configured and available.
     *
     * @param int $cmid   Course-module id.
     * @param int $userid User id.
     * @return bool
     */
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

            $toolrouting = $this->orchestratorroutingsvc->resolve_action_class_for_step(
                $manager,
                $context,
                self::STEP_TYPE_TOOL_CALL_PARSE
            );
            $finalrouting = $this->orchestratorroutingsvc->resolve_action_class_for_step(
                $manager,
                $context,
                self::STEP_TYPE_FINAL_REASONING
            );

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
                    $toolenabledincontext = $this->orchestratorroutingsvc->is_action_available_in_context(
                        $manager,
                        $context,
                        $toolactionclass
                    );
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
                    $finalenabledincontext = $this->orchestratorroutingsvc->is_action_available_in_context(
                        $manager,
                        $context,
                        $finalactionclass
                    );
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
        $normalizedsteptype = $this->promptprofilesvc->normalize_step_type($steptype);
        $evaluator = new task_executability_evaluator($this->registry, new authorization_service());

        $routing = $this->orchestratorroutingsvc->resolve_action_class_for_step(
            $manager,
            $context,
            $normalizedsteptype
        );
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
            && !$this->promptprofilesvc->observations_are_framework_retry_hints($observations);
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
            // Default planner catalog without embeddings: slim view of all executable tasks.
            $runtimecatalog = $this->slim_prompt_catalog_for_planner($allpromptcontracts);
            $catalogselectionmode = 'slim_all';

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
                                            $denyreasons = (string)($evaluations[$taskname]['deny_reason'] ?? '');
                                            $unavailabletaskcatalog[] = [
                                                'task' => $taskname,
                                                'availability' => $this->availability_from_deny_reason($denyreasons),
                                                'description' => (string)($descriptionindex[$taskname] ?? ''),
                                            ];
                                            continue;
                                        }

                                        $activesubset[] = $entry;
                                    }

                                    if (!empty($activesubset)) {
                                        // Embedding mode is strict top-k only: no fallback merge inflation.
                                        $runtimecatalog = array_slice($activesubset, 0, self::EMBEDDINGS_DEFAULT_TOP_K);
                                        $unavailabletaskcatalog = $this->sanitize_unavailable_task_catalog($unavailabletaskcatalog);
                                        $catalogselectionmode = 'embed_topk';
                                        $embeddingstatus = 'applied';
                                    } else {
                                        $embeddingstatus = 'nomatch';
                                    }
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
        $historycount = count(array_slice($messages, -$this->promptprofilesvc->get_history_limit_for_step($normalizedsteptype)));
        $observationcount = count($observations);
        $primaryprovider = provider_routing_util::resolve_primary_provider_for_action($manager, $actionclass);
        $debugsource = $this->orchestratorroutingsvc->build_debug_source(
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

        if (core_text::strlen($normalized) <= 240) {
            return $normalized;
        }

        return rtrim(core_text::substr($normalized, 0, 237)) . '...';
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

        // Keep enough fields so slotbooking/selflearning task variants do not
        // lose critical execution hints (e.g. slot_day_* or duration fields).
        return array_slice($keys, 0, 12);
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
                $row['description'] = core_text::substr($description, 0, 320);
            }

            $examples = (array)($trigger['examples'] ?? []);
            if (!empty($examples)) {
                $row['examples'] = $this->assistantsummariesvc->normalize_nonempty_string_list($examples, 2, 160);
                if (empty($row['examples'])) {
                    unset($row['examples']);
                }
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
        $normalizedsteptype = $this->promptprofilesvc->normalize_step_type($steptype);
        $trimmedmessages = array_slice($messages, -$this->promptprofilesvc->get_history_limit_for_step($normalizedsteptype));

        if ($normalizedsteptype === self::STEP_TYPE_FINAL_REASONING) {
            $contextualguidance = $this->assistantsummariesvc->build_contextual_guidance($trimmedmessages);
            if ($contextualguidance !== '') {
                $systemprompt .= "\n\nCONTEXT-SPECIFIC GUIDANCE:\n" . $contextualguidance;
            }
        }

        $assistantstateblocks = [];
        if ($normalizedsteptype === self::STEP_TYPE_FINAL_REASONING) {
            $assistantstateblocks = $this->assistantsummariesvc->build_assistant_state_blocks($trimmedmessages);
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
        $normalized = $this->promptprofilesvc->normalize_step_type($steptype);
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
            $lines[] = 'Do NOT ask permission or phrase messages as questions. '
                . 'Instead: write a short statement announcing what will be executed.';
            $lines[] = 'Treat recent ASSISTANT/ASSISTANT_STATE execution evidence as authoritative. '
                . 'Never re-emit an already-executed action (same task+input signature).';
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
                $json = $this->json_encode_or_empty($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== '') {
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
            $this->promptprofilesvc->normalize_step_type($steptype) === self::STEP_TYPE_TOOL_CALL_PARSE
            && $isfirstassistantturn
            && !$hasobservations
        ) {
            $lines[] = '';
            $lines[] = 'NON-OPTIONAL LANGUAGE POLICY:';
            $lines[] = "- Include valid ISO 639-1 value 'user_lang'.";
        }

        $this->append_json_object_section($lines, 'TASK CATALOG:', $taskcatalog);

        if (!empty($unavailabletaskcatalog)) {
            $this->append_json_object_section($lines, 'UNAVAILABLE TASKS:', $unavailabletaskcatalog);
        }

        $completedcommands = $this->completedhistorysvc->extract_from_messages($messages);
        $completedcommands = $this->completedhistorysvc->merge_from_queue($threadid, $completedcommands);
        $this->append_json_list_section($lines, 'completed_commands:', $completedcommands);

        $observationledger = new execution_observation_ledger($this->store);
        $completedobservations = $observationledger->get_recent_for_runtime($threadid, 12);
        $this->append_json_list_section($lines, 'completed_observations:', $completedobservations);

        return implode("\n", $lines);
    }

    /**
     * Append a JSON-encoded object section to runtime context lines.
     *
     * @param array<int,string> $lines
     * @param string $heading
     * @param mixed $value
     * @return void
     */
    private function append_json_object_section(array &$lines, string $heading, $value): void {
        $json = $this->json_encode_or_empty($value, JSON_UNESCAPED_UNICODE);
        if ($json === '') {
            return;
        }

        $lines[] = '';
        $lines[] = $heading;
        $lines[] = $json;
    }

    /**
     * Append a bullet-style JSON list section to runtime context lines.
     *
     * @param array<int,string> $lines
     * @param string $heading
     * @param array<int,mixed> $items
     * @return void
     */
    private function append_json_list_section(array &$lines, string $heading, array $items): void {
        if (empty($items)) {
            return;
        }

        $lines[] = '';
        $lines[] = $heading;
        foreach ($items as $item) {
            $json = $this->json_encode_or_empty($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === '') {
                continue;
            }
            $lines[] = '  - ' . $json;
        }
    }

    /**
     * JSON encode helper that always returns a string.
     *
     * @param mixed $value
     * @param int $flags
     * @return string
     */
    private function json_encode_or_empty($value, int $flags): string {
        $json = json_encode($value, $flags);
        if (!is_string($json)) {
            return '';
        }

        return $json;
    }

    /**
     * Map a contract deny reason to a runtime availability flag.
     *
     * @param string $reason
     * @return string
     */
    private function availability_from_deny_reason(string $reason): string {
        if ($reason === task_contract_validator::DENY_MISSING_CAPABILITY) {
            return 'not_active_for_you';
        }

        if ($reason === task_contract_validator::DENY_CONTEXT_INVALID) {
            return 'invalid_context';
        }

        if ($reason === task_contract_validator::DENY_RUNTIME_DISABLED) {
            return 'runtime_disabled';
        }

        return 'not_active_now';
    }

    /**
     * Keep only valid unavailable-task catalog entries.
     *
     * @param array<int,mixed> $catalog
     * @return array<int,array<string,string>>
     */
    private function sanitize_unavailable_task_catalog(array $catalog): array {
        return array_values(array_filter($catalog, static function ($entry): bool {
            return is_array($entry) && trim((string)($entry['task'] ?? '')) !== '';
        }));
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
}
