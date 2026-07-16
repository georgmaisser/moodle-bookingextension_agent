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

namespace bookingextension_agent\local\wizard;

use core\context;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core\di;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\interfaces\agent_interpreter;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\user_memory_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use bookingextension_agent\local\wizard\services\planner_result_composer;
use bookingextension_agent\local\wizard\services\provider_status_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\discovery_phase_service;
use bookingextension_agent\local\wizard\services\planner_phase_service;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use bookingextension_agent\local\wizard\services\security\authorization_service;

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
    use provider_error_result_trait;

    /** Discovery planner phase identifier. */
    public const PHASE_DISCOVERY = 'discovery';

    /** Selection planner phase identifier. */
    public const PHASE_SELECTION = 'selection';

    /** Parameter construction planner phase identifier. */
    public const PHASE_PARAMETER_CONSTRUCTION = 'parameter_construction';

    /** Default model for skill-catalog embeddings. */
    public const EMBEDDINGS_DEFAULT_MODEL = 'text-embedding-3-small';

    /** Default embedding dimensions. */
    public const EMBEDDINGS_DEFAULT_DIMENSIONS = 1536;

    /** Default number of best matching skills to inject for first planner step. */
    public const EMBEDDINGS_DEFAULT_TOP_K = 12;

    /** Debounce window (seconds) for scheduling embeddings rebuild skill. */
    public const EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS = 100;

    /** Wunderbyte planner action class name. */
    private const WB_ACTION_PLANNER_DECIDE = wb_action_names::PLANNER_DECIDE;

    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array
     */
    public static function get_runtime_feature_flags_snapshot(): array {
        return runtime_feature_flags::snapshot();
    }

    /** @var skill_registry */
    private skill_registry $registry;

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

    /** @var planner_catalog_service */
    private planner_catalog_service $plannercatalogsvc;

    /** @var runtime_context_block_builder */
    private runtime_context_block_builder $runtimecontextsvc;

    /** @var orchestrator_prompt_profile_service */
    private orchestrator_prompt_profile_service $promptprofilesvc;

    /** @var phase_prompt_bundle_builder */
    private phase_prompt_bundle_builder $promptbundlebuilder;

    /** @var synchronizer_prompt_builder */
    private synchronizer_prompt_builder $synchronizerpromptbuilder;

    /** @var discovery_phase_service */
    private discovery_phase_service $discoveryphasesvc;

    /** @var planner_phase_service */
    private planner_phase_service $plannerphasesvc;

    /**
     * Constructor.
     *
     * @param skill_registry      $registry
     * @param agent_interpreter  $interpreter
     * @param conversation_store $store
     */
    public function __construct(
        skill_registry $registry,
        agent_interpreter $interpreter,
        conversation_store $store
    ) {
        $this->registry = $registry;
        $this->interpreter = $interpreter;
        $this->store = $store;
        $this->completedhistorysvc = new completed_command_history_service($store);
        $this->assistantsummariesvc = new assistant_state_guidance_service();
        $this->orchestratorroutingsvc = new orchestrator_routing_service(
            self::WB_ACTION_PLANNER_DECIDE
        );
        $this->plannercatalogsvc = new planner_catalog_service($this->assistantsummariesvc);
        $this->runtimecontextsvc = new runtime_context_block_builder(
            $this->store,
            $this->completedhistorysvc,
            $this->plannercatalogsvc
        );
        $this->promptprofilesvc = new orchestrator_prompt_profile_service();
        $this->promptbundlebuilder = new phase_prompt_bundle_builder($this->registry, $this->promptprofilesvc);
        $this->synchronizerpromptbuilder = new synchronizer_prompt_builder();
        $this->discoveryphasesvc = new discovery_phase_service(
            $this->store,
            $this->registry,
            $this->orchestratorroutingsvc,
            $this->promptprofilesvc,
            $this->plannercatalogsvc,
            $this->runtimecontextsvc,
            $this->promptbundlebuilder
        );
        $this->plannerphasesvc = new planner_phase_service(
            $this->store,
            $this->registry,
            $this->interpreter,
            $this->orchestratorroutingsvc,
            $this->promptprofilesvc,
            $this->plannercatalogsvc,
            $this->runtimecontextsvc,
            $this->promptbundlebuilder
        );
    }

    /**
     * Check whether a Moodle core_ai provider is configured and available.
     *
     * @param int $contextid   Course-module id.
     * @param int $userid User id.
     * @return bool
     */
    /**
     * Resolve centralized provider/runtime status for booking agent execution.
     *
     * This is the single source of truth for availability checks used by both
     * readiness UI and runtime message processing.
     *
     * @param int $contextid Moodle context id (any level the agent runs at).
     * @return array
     */
    public function get_runtime_provider_status(int $contextid): array {
        // Logic lives in provider_status_service (orchestrator split, provider-status seam);
        // this thin delegator preserves the public API for aiready / ai_send_message /
        // activate_trial_context. The same routing service instance is reused.
        return (new provider_status_service($this->orchestratorroutingsvc))->get_status($contextid);
    }

    /**
     * Process a user message: call the LLM and interpret the response.
     *
     * @param  int      $threadid     Thread id.
     * @param  int      $contextid         Course-module id.
     * @param  int      $userid       User id.
     * @param  string[] $observations Optional structured observation strings from prior internal loop steps.
     *                                Injected into the prompt so the LLM can reason about tool results
     *                                before producing its next response.  Never persisted to the DB.
     * @param  agent_state|null $agentstate Optional per-run loop state for cache reuse across steps.
     * @return array  Interpreter result.
     */
    public function process(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations = [],
        ?agent_state $agentstate = null
    ): array {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $manager = di::get(ai_manager::class);
        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
        $discoverystate = $this->run_discovery_phase(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $agentstate,
            $context,
            $manager,
            $evaluator
        );

        $selectionstate = $this->run_selection_phase(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $discoverystate,
            $context,
            $manager
        );

        $intent = trim((string)($selectionstate['next_step_intent'] ?? ''));
        if ($intent === '') {
            $selectedskill = trim((string)($selectionstate['selected_skill'] ?? ''));
            if ($selectedskill !== '') {
                $intent = 'Executing ' . $selectedskill;
            }
        }

        if ($intent !== '') {
            $stepnum = ($agentstate !== null) ? ($agentstate->step_count() + 1) : 1;
            $this->store->add_step_message($threadid, $stepnum, $intent);
        }

        $selectionresponsetype = trim((string)($selectionstate['response_type'] ?? ''));
        if ($selectionresponsetype !== 'skill_call') {
            $constructionstate = [
                'phase' => self::PHASE_PARAMETER_CONSTRUCTION,
                'response_type' => $selectionresponsetype,
                'message' => (string)($selectionstate['message'] ?? ''),
                'commands' => (array)($selectionstate['commands'] ?? []),
                'ambiguities' => (array)($selectionstate['ambiguities'] ?? []),
                'errors' => (array)($selectionstate['errors'] ?? []),
                'issue_codes' => (array)($selectionstate['issue_codes'] ?? []),
                'lang' => (string)($selectionstate['lang'] ?? ''),
                'user_lang' => (string)($selectionstate['user_lang'] ?? ''),
            ];
        } else {
            $constructionstate = $this->run_construction_phase(
                $threadid,
                $contextid,
                $userid,
                $observations,
                $discoverystate,
                $selectionstate
            );
        }

        $plannerresultcomposer = new planner_result_composer();
        return $plannerresultcomposer->compose(
            $discoverystate,
            $selectionstate,
            $constructionstate
        );
    }

    /**
     * Process a dedicated synchronizer finalization step.
     *
     * This path is intentionally separate from planner phase execution so that
     * final reply polishing does not reuse planner step routing.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param string[] $observations
     * @param string $continuation
     * @return array
     */
    public function process_synchronizer(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations = [],
        string $continuation = synchronizer_prompt_builder::CONTINUATION_NONE
    ): array {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $manager = di::get(ai_manager::class);
        $messages = array_values(array_filter(
            $this->store->get_messages($threadid),
            static fn($msg): bool => (string)($msg->role ?? '') !== 'step'
        ));
        $contextid = (int)$context->id;
        $isfirstassistantturn = $this->is_first_assistant_turn($messages);
        $routing = $this->resolve_synchronizer_action_class($manager, $context);
        $actionclass = (string)($routing['actionclass'] ?? generate_text::class);
        $routepolicy = (string)($routing['routepolicy'] ?? 'sync_default');
        $routingfallback = !empty($routing['routingfallback']);

        $systemprompt = $this->synchronizerpromptbuilder->build_system_prompt($actionclass);
        $runtimeblocks = $this->build_runtime_context_block(
            $threadid,
            $contextid,
            self::PHASE_SELECTION,
            $isfirstassistantturn,
            !empty($observations),
            [],
            [],
            $messages,
            user_memory_service::SCOPE_SYNCHRONIZATION,
            $observations,
            false
        );
        $runtimestate = $runtimeblocks['volatile'];
        // Surface remaining planned placeholders with their TRUE execution status. Whether they
        // still run is decided by the engine's continuation state, never assumed: after a
        // confirmation_request they run once the user confirms; on every other terminal state
        // the turn is over and they will NOT run — the reply must say so instead of promising
        // automatic follow-up (thread 558: "Sprint 5 wird automatisch noch erstellt" on a
        // terminal clarification, with the orphaned placeholder still in the queue).
        $pendingintents = (new queue_manager($this->store, $this->registry))
            ->get_planned_placeholder_intents($threadid);
        if (!empty($pendingintents)) {
            if ($continuation === synchronizer_prompt_builder::CONTINUATION_AWAITING_CONFIRMATION) {
                $runtimestate .= "\n\nPENDING AGENT STEPS (run after the user confirms — "
                    . "do NOT suggest manual workarounds):\n";
            } else {
                $runtimestate .= "\n\nUNEXECUTED PLANNED STEPS (NOT completed — nothing runs after this reply; "
                    . "never state or imply these will happen automatically; name them as NOT done and "
                    . "ask the user how to proceed):\n";
            }
            foreach ($pendingintents as $idx => $intent) {
                $runtimestate .= ($idx + 1) . '. ' . trim($intent) . "\n";
            }
        }
        $prompt = $this->synchronizerpromptbuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $runtimeblocks['stable'],
            $runtimestate,
            $continuation
        );

        $llm = new llm_call_service($this->store);
        $debugsource = 'sync|st=sr|ac=' . ($actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY ? 'agr' : 'gen')
            . '|rt=' . ($routepolicy === 'sync_wunderbyte' ? 'wb' : 'df')
            . '|fb=' . ($routingfallback ? '1' : '0')
            . '|ob=' . count($observations);

        $call = $llm->invoke_for_context($threadid, $contextid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');
        if (empty($call['success'])) {
            return $this->build_provider_error_result($call);
        }

        if ($rawtext === '') {
            return $this->build_empty_provider_result();
        }

        $interpreted = $this->interpreter->interpret($rawtext, $contextid, $userid, '');
        if (is_array($interpreted)) {
            $interpreted['_planner_raw_response'] = $rawtext;
        }

        return $interpreted;
    }

    /**
     * Resolve synchronizer action class with dedicated fallback chain.
     *
     * @param ai_manager $manager
     * @param context $context
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    private function resolve_synchronizer_action_class(ai_manager $manager, context $context): array {
        try {
            if ($manager->is_action_available(self::WB_ACTION_GENERATE_AGENT_REPLY)) {
                return [
                    'actionclass' => self::WB_ACTION_GENERATE_AGENT_REPLY,
                    'routepolicy' => 'sync_wunderbyte',
                    'routingfallback' => false,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort: fall through to the next available action below.
            debugging('orchestrator: provider routing resolution failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if ($this->orchestratorroutingsvc->is_action_available_in_context($manager, $context, generate_text::class)) {
            return [
                'actionclass' => generate_text::class,
                'routepolicy' => 'sync_default',
                'routingfallback' => true,
            ];
        }

        return [
            'actionclass' => generate_text::class,
            'routepolicy' => 'sync_default',
            'routingfallback' => true,
        ];
    }

    /**
     * Discovery phase: collect routing, context, and runtime catalog state.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param agent_state|null $agentstate
     * @param context $context
     * @param ai_manager $manager
     * @param skill_executability_evaluator $evaluator
     * @return array
     */
    private function run_discovery_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        ?agent_state $agentstate,
        context $context,
        ai_manager $manager,
        skill_executability_evaluator $evaluator
    ): array {
        // Logic lives in discovery_phase_service (orchestrator split, discovery seam);
        // this thin delegator preserves the internal call site in process().
        return $this->discoveryphasesvc->run(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $agentstate,
            $context,
            $manager,
            $evaluator
        );
    }

    /**
     * Selection phase: build prompt, telemetry, and debug-source payload.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param array $discoverystate
     * @param context $context
     * @param ai_manager $manager
     * @return array
     */
    private function run_selection_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        context $context,
        ai_manager $manager
    ): array {
        // Logic lives in planner_phase_service (orchestrator split, planner-phase seam);
        // this thin delegator preserves the internal call site in process().
        return $this->plannerphasesvc->run_selection(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $discoverystate,
            $context,
            $manager
        );
    }

    /**
     * Construction phase: execute planner call and interpret response.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param array $discoverystate
     * @param array $selectionstate
     * @return array
     */
    private function run_construction_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        array $selectionstate
    ): array {
        // Logic lives in planner_phase_service (orchestrator split, planner-phase seam);
        // this thin delegator preserves the internal call site in process().
        return $this->plannerphasesvc->run_construction(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $discoverystate,
            $selectionstate
        );
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
You are an AI agent planner.

ACTION-SPECIFIC GUIDANCE FOR ROUTING:
- Keep instructions compact and action-oriented. Do not over-explain.
- Use this strict decision order (first matching rule wins):
  1) already completed outcome in completed_commands/completed_observations
      -> response_type=sufficient, commands=[].
  2) explicit confirmation of an already pending action
      -> response_type=confirm_pending, commands=[].
  3) missing required input for the selected skill
      -> response_type=clarification, commands=[].
  4) grounded mutating intent
      -> response_type=skill_call (selector) or confirmation_request (constructor), commands non-empty.
  5) grounded read-only intent
      -> response_type=skill_call, commands non-empty.
  6) multi-step request, first turn, no [PENDING PLANNED STEPS] in context
      -> select the first skill + set planned_steps=[{intent of step 2},{intent of step 3},...].
- CONTEXT-AWARE PLANNING: Action skills resolve their own target via their query field (optionquery,
  coursequery, userquery, ...). For "do X for/in <named target>", select the ACTION skill directly and
  pass the named target as its query — do NOT add a preceding search/resolution/lookup step (this
  includes a target that is the current SYSTEM_RUNTIME context; e.g. "create a quiz in this course" ->
  the quiz skill now, NOT course.search_courses first; "book Anna into the First Aid course" ->
  book_users now, NOT a search step first). Use a search/list skill ONLY when the user explicitly wants
  to find or list something, never as a means to an action. A skill that cannot resolve its target will
  ask for clarification itself.
- Use only exact skill names from the SKILL CATALOG. Never invent aliases.
- If a matching skill appears in UNAVAILABLE SKILLS, do NOT execute it and do NOT invent your own wording.
  When its description is prefixed with "[Locked: requires the Wunderbyte PRO license or subscription - <url>]",
  respond (clarification) that this task is only available with a Wunderbyte PRO license or a Wunderbyte
  subscription, and include that exact <url> from the marker as a markdown link labelled Get Pro, i.e.
  [Get Pro](<url>). Never reveal the internal skill name and never tell the user to try again later or
  contact support. If it is unavailable for any other reason (no such marker), just state that it exists
  but is currently not executable.
- Do not emit unavailable skills in commands.
- Never re-emit an already completed action signature (same skill + normalized input intent).
- A completed action does NOT cover a request that adds a NEW scope or target — a named activity,
  course, option or person that the completed input did not contain. That is a NEW action:
  emit the command again including the new scope (thread 542: "search X" completed does not
  answer "search X in activity Y" — search again with the activity).

GROUNDING (prefer skills over free-form answers):
- If a skill in the SKILL CATALOG can fulfil OR answer the request, select it (response_type=skill_call)
  instead of answering from your own knowledge. This explicitly includes questions about your own
  capabilities or which actions exist: prefer the catalog's introspection/listing skill over composing
  such a list yourself (a self-composed list is partial and goes stale).
- Only answer directly (response_type=sufficient) for pure conversation/acknowledgement, or when no
  catalog skill applies.

SKILL CONTRACT FIRST (highest priority):
- Follow skill-level routing hints from the SKILL CATALOG (WHEN, REQUIRED, TRIGGERS).
- Keep global routing generic; do not hardcode special behavior for individual skill names.

PROMPT;
        }

        if ($actionclass === explain_text::class) {
            return <<<'PROMPT'
You are an AI reasoning assistant.

ACTION-SPECIFIC GUIDANCE:
- Base your answer on the latest user message, observations, and assistant state.
- Be concise, precise, and helpful.
- Do not propose extra tool calls if the available context already answers the request.
- Use only exact skill names from the SKILL CATALOG below.
- Never invent aliases or category names such as docs.search or documentation.query.
- If observations already contain sufficient information, MUST return
    response_type="sufficient" with commands=[] and NO message field.
- If information is still missing for a mutating action, ask one focused clarification question.
- For documented read-only questions, if observations are still insufficient,
    you MAY return one documentation skill_call from the skill catalog to retrieve more relevant information.
- If you need another documentation skill_call, prefer grounded candidate paths or topic hints over guessed root doc_path values.
- If observations already include concrete domain-specific configuration fields or labels,
    answer directly and do NOT ask the user to reconfirm intent.

PROMPT;
        }

        if (
            $actionclass === generate_text::class
            || $actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY
        ) {
            return <<<'PROMPT'
You are an expert that composes polished, helpful answers.

SYNTHESIS SKILL:
- Retrieved information is provided in the OBSERVATION blocks. Your job is to write a high-quality final answer.
- Do NOT call any tools or issue skill_calls.
- Always return response_type="sufficient" with commands=[].
- OUTPUT FORMAT IS STRICT: return exactly one JSON object and nothing else.
- The first non-whitespace character MUST be "{" and the last non-whitespace character MUST be "}".
- Never output markdown, code fences, headings, or prose outside JSON.
- Put the complete user-facing explanation only into the JSON field "message".
- Required top-level keys: response_type, message, user_lang, commands.
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
You are an AI agent.

ACTION-SPECIFIC GUIDANCE:
- Use only the provided skill catalog and schema.
- Do not invent domain-specific identifiers or unsupported actions.
- For read-only intents, prefer direct skill_call handling.
- For mutating intents, ask only for missing required data before confirmation.
PROMPT;
    }

    /**
     * Return the safe default prefix for final synthesis style customization.
     *
     * @return string
     */
    public static function get_default_summary_prompt_prefix(): string {
        return 'You are an expert that composes polished, helpful answers.';
    }

    /**
     * True when the given prefix is the (current or legacy) seeded default and
     * therefore must not be treated as an admin customization.
     *
     * @param string $prefix
     * @return bool
     */
    public static function is_default_summary_prompt_prefix(string $prefix): bool {
        return in_array(trim($prefix), [
            self::get_default_summary_prompt_prefix(),
            // Legacy seeded value from before the cache-stable prompt cleanup.
            'You are an expert that composes polished, helpful answers for the "ai" context.',
        ], true);
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
     * Build the dynamic runtime context blocks for this request.
     *
     * Keeping per-request values out of the static [SYSTEM] block improves
     * prompt-prefix stability for upstream prompt caching. The result is split
     * into a per-thread-stable part ('stable', emitted as [SYSTEM_RUNTIME] right
     * after [SYSTEM]) and a volatile per-request part ('volatile', emitted as
     * [SYSTEM_RUNTIME_STATE] below the conversation history) so that high-churn
     * content (timestamp, adaptive catalog, execution ledgers) never invalidates
     * the cacheable prompt prefix.
     *
     * @param int $threadid
     * @param int $contextid
     * @param string $phase
     * @param bool $isfirstassistantturn
     * @param bool $hasobservations
     * @param array $skillcatalog
     * @param array $unavailableskillcatalog
     * @param array $messages
     * @param string $memorychannel
     * @param array $liveobservations observation strings already emitted as [OBSERVATION n]
     *                                blocks in the same prompt — used to compact duplicate
     *                                ledger entries
     * @param bool $catalogisstatic
     * @return array
     */
    private function build_runtime_context_block(
        int $threadid,
        int $contextid,
        string $phase = self::PHASE_DISCOVERY,
        bool $isfirstassistantturn = false,
        bool $hasobservations = false,
        array $skillcatalog = [],
        array $unavailableskillcatalog = [],
        array $messages = [],
        string $memorychannel = '',
        array $liveobservations = [],
        bool $catalogisstatic = false
    ): array {
        return $this->runtimecontextsvc->build(
            $threadid,
            $contextid,
            $phase,
            $isfirstassistantturn,
            $hasobservations,
            $skillcatalog,
            $unavailableskillcatalog,
            $messages,
            $memorychannel,
            $liveobservations,
            $catalogisstatic
        );
    }
}
