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

use context_module;
use core\context;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core\di;
use core_text;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\interfaces\agent_interpreter;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\result_payload_summarizer;
use bookingextension_agent\local\wizard\services\agent_access_service;
use bookingextension_agent\local\wizard\services\provider_compat;
use bookingextension_agent\local\wizard\services\catalog\adaptive_skill_catalog_service;
use bookingextension_agent\local\wizard\services\discovery\family_ranker;
use bookingextension_agent\local\wizard\services\discovery\family_registry_service;
use bookingextension_agent\local\wizard\services\discovery\family_signal_ranker;
use bookingextension_agent\local\wizard\services\discovery\discovery_stage_controller;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\execution_observation_ledger;
use bookingextension_agent\local\wizard\services\user_memory_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\discovery\context_prior_builder;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use bookingextension_agent\local\wizard\services\planner_result_composer;
use bookingextension_agent\local\wizard\services\provider_routing_util;
use bookingextension_agent\local\wizard\services\provider_status_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\discovery_phase_service;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\telemetry\routing_decision_log_service;

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
    public const EMBEDDINGS_DEFAULT_TOP_K = 8;

    /** Debounce window (seconds) for scheduling embeddings rebuild skill. */
    public const EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS = 100;

    /** Wunderbyte planner action class name. */
    private const WB_ACTION_PLANNER_DECIDE = 'aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = 'aiprovider_wunderbyte\\aiactions\\generate_agent_reply';

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array<string,bool>
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
        $this->assistantsummariesvc = new assistant_state_guidance_service($registry);
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
     * @return array<string,mixed>
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
     * @param array<int,string> $observations
     * @return array<string,mixed>
     */
    public function process_synchronizer(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations = []
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
        // Inject pending planned step intents so the sync never suggests manual workarounds
        // for steps the agent is still planning to execute.
        $pendingintents = (new queue_manager($this->store, $this->registry))
            ->get_planned_placeholder_intents($threadid);
        if (!empty($pendingintents)) {
            $runtimestate .= "\n\nPENDING AGENT STEPS (will be executed automatically — do NOT suggest manual workarounds):\n";
            foreach ($pendingintents as $idx => $intent) {
                $runtimestate .= ($idx + 1) . '. ' . trim($intent) . "\n";
            }
        }
        $prompt = $this->synchronizerpromptbuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $runtimeblocks['stable'],
            $runtimestate
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
     * @return array<string,mixed>
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
     * @param array<string,mixed> $discoverystate
     * @param context $context
     * @param ai_manager $manager
     * @return array<string,mixed>
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
        $contextid = (int)($discoverystate['contextid'] ?? 0);
        $routing = $this->orchestratorroutingsvc->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_SELECTION
        );
        $actionclass = (string)($routing['actionclass'] ?? generate_text::class);
        $messages = (array)($discoverystate['messages'] ?? []);
        $promptcontracts = (array)($discoverystate['promptcontracts'] ?? []);
        $runtimecatalog = (array)($discoverystate['runtimecatalog'] ?? []);
        $unavailableskillcatalog = (array)($discoverystate['unavailableskillcatalog'] ?? []);
        $plannertracehistory = (array)($discoverystate['plannertracehistory'] ?? []);
        $catalogselectionmode = (string)($discoverystate['catalogselectionmode'] ?? 'none');
        $embeddingstatus = (string)($discoverystate['embeddingstatus'] ?? 'off');
        $embeddingrebuildqueued = !empty($discoverystate['embeddingrebuildqueued']);
        $hasanyobservations = !empty($discoverystate['hasanyobservations']);
        $haseffectiveobservations = !empty($discoverystate['haseffectiveobservations']);
        $isfirstassistantturn = !empty($discoverystate['isfirstassistantturn']);
        $shouldincludeskillcatalog = !empty($discoverystate['shouldincludeskillcatalog']);
        $adaptivecatalog = (array)($discoverystate['adaptivecatalog'] ?? []);
        $discoverystage = (string)($discoverystate['discovery_stage'] ?? 'none');
        $discoveryconfidencescore = $discoverystate['discovery_confidence_score'] ?? null;
        $discoveryescalationreason = (string)($discoverystate['discovery_escalation_reason'] ?? 'none');

        $systemprompt = $this->build_system_prompt(
            $contextid,
            $userid,
            self::PHASE_SELECTION,
            $actionclass,
            $haseffectiveobservations,
            $adaptivecatalog,
            $runtimecatalog,
            $isfirstassistantturn,
            $shouldincludeskillcatalog
        );
        $runtimeblocks = $this->build_runtime_context_block(
            $threadid,
            $contextid,
            self::PHASE_SELECTION,
            $isfirstassistantturn,
            $hasanyobservations,
            $runtimecatalog,
            $unavailableskillcatalog,
            $messages,
            '',
            $observations,
            $this->catalog_mode_is_static($catalogselectionmode)
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        $plannedstepintents = (new queue_manager($this->store, $this->registry))
            ->get_planned_placeholder_intents($threadid);
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            self::PHASE_SELECTION,
            $runtimeblocks['stable'],
            $plannertracehistory,
            $autoconfirmmode,
            $plannedstepintents,
            $runtimeblocks['volatile']
        );

        $historycount = count(
            $this->promptprofilesvc->select_history_messages($messages, self::PHASE_SELECTION)
        );
        $observationcount = count($observations);
        $primaryprovider = provider_routing_util::resolve_primary_provider_for_action($manager, $actionclass);
        $debugsource = $this->orchestratorroutingsvc->build_debug_source(
            $actionclass,
            (string)($routing['routepolicy'] ?? 'default'),
            !empty($routing['routingfallback']),
            orchestrator_routing_service::PHASE_SELECTION,
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($runtimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $llm = new llm_call_service($this->store);
        $phaseoutput = [];
        $call = $llm->invoke_for_context($threadid, $contextid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');
        if (empty($call['success'])) {
            $phaseoutput = $this->build_provider_error_result($call);
        } else if ($rawtext === '') {
            $phaseoutput = $this->build_empty_provider_result();
        } else {
            $phaseoutput = $this->interpreter->interpret_phase_output(
                $rawtext,
                self::PHASE_SELECTION,
                [
                    'contextid' => $contextid,
                    'userid' => $userid,
                ]
            );
            if (is_array($phaseoutput)) {
                $phaseoutput = $this->normalize_selection_phase_output_for_handoff($phaseoutput);
                $phaseoutput['_planner_raw_response'] = $rawtext;
            }
        }

        // Persist normalized routing telemetry and a shadow-only discovery trace.
        // This must never alter the active routing decision path.
        try {
            $flagssnapshot = runtime_feature_flags::snapshot();
            $contextprior = (new context_prior_builder())->build($contextid, [
                'userid' => $userid,
                'namespace_hint' => $this->resolve_namespace_hint_from_prompt_contracts($promptcontracts),
            ]);
            $routingtelemetry = [
                'catalogselectionmode' => $catalogselectionmode,
                'discovery_stage' => $discoverystage,
                'confidence_score' => $discoveryconfidencescore,
                'escalation_reason' => $discoveryescalationreason,
            ];
            (new routing_decision_log_service())->persist_thread_routing_decision(
                $this->store,
                $threadid,
                $routingtelemetry,
                $flagssnapshot,
                [
                    'promptcontracts' => $promptcontracts,
                    'contextprior' => $contextprior,
                    'recent_skill_names' => (array)($discoverystate['recentskillhistory'] ?? []),
                ]
            );
        } catch (\Throwable $e) {
            // Best-effort discovery enrichment; the base catalog is still usable without it.
            debugging('orchestrator: discovery enrichment failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $lastusermessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg->role ?? '') === 'user') {
                $lastusermessage = trim((string)($msg->content ?? ''));
                break;
            }
        }

        $selectedskill = $this->extract_selected_skill_from_selection_phase_output($phaseoutput);

        return [
            'prompt' => $prompt,
            'debugsource' => $debugsource,
            'lastusermessage' => $lastusermessage,
            'selected_skill' => $selectedskill,
            'phase' => self::PHASE_SELECTION,
            'phase_output' => $phaseoutput,
            'response_type' => (string)($phaseoutput['response_type'] ?? ''),
            'message' => (string)($phaseoutput['message'] ?? ''),
            'issue_codes' => (array)($phaseoutput['issue_codes'] ?? []),
            'errors' => (array)($phaseoutput['errors'] ?? []),
            'planned_steps' => (array)($phaseoutput['planned_steps'] ?? []),
        ];
    }

    /**
     * Normalize selection output to an explicit single-skill selector handoff.
     *
     * This strips accidental parameter payloads from selection commands and keeps
     * only the selected skill identity for constructor handoff.
     *
     * @param array<string,mixed> $phaseoutput
     * @return array<string,mixed>
     */
    private function normalize_selection_phase_output_for_handoff(array $phaseoutput): array {
        $responsetype = trim((string)($phaseoutput['response_type'] ?? ''));
        if ($responsetype !== 'skill_call') {
            if (!isset($phaseoutput['selected_skill'])) {
                $phaseoutput['selected_skill'] = '';
            }
            return $phaseoutput;
        }

        $commands = (array)($phaseoutput['commands'] ?? []);
        if (count($commands) !== 1) {
            return $this->build_selection_contract_error_result(
                'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED',
                'CONTRACT_VIOLATION: selection phase must emit exactly one selector command.'
            );
        }

        $command = is_array($commands[0]) ? $commands[0] : [];
        $selectedskill = trim((string)($phaseoutput['selected_skill'] ?? ''));
        if ($selectedskill === '') {
            $selectedskill = trim((string)($command['skill'] ?? ''));
        }
        if ($selectedskill === '') {
            return $this->build_selection_contract_error_result(
                'CONTRACT_SELECTION_SKILL_MISSING',
                'CONTRACT_VIOLATION: selection phase skill_call did not provide a selected skill.'
            );
        }

        $version = max(1, (int)($command['version'] ?? 1));
        $phaseoutput['selected_skill'] = $selectedskill;
        $phaseoutput['commands'] = [[
            'skill' => $selectedskill,
            'version' => $version,
            'input' => [],
        ]];

        return $phaseoutput;
    }

    /**
     * Construction phase: execute planner call and interpret response.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array<string,mixed> $discoverystate
     * @param array<string,mixed> $selectionstate
     * @return array<string,mixed>
     */
    private function run_construction_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        array $selectionstate
    ): array {
        $llm = new llm_call_service($this->store);
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $manager = di::get(ai_manager::class);
        $routing = $this->orchestratorroutingsvc->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );
        $actionclass = (string)($routing['actionclass'] ?? generate_text::class);
        $contextid = (int)($discoverystate['contextid'] ?? 0);
        $messages = (array)($discoverystate['messages'] ?? []);
        $adaptivecatalog = (array)($discoverystate['adaptivecatalog'] ?? []);
        $runtimecatalog = (array)($discoverystate['runtimecatalog'] ?? []);
        $plannertracehistory = (array)($discoverystate['plannertracehistory'] ?? []);
        $isfirstassistantturn = !empty($discoverystate['isfirstassistantturn']);
        $haseffectiveobservations = !empty($discoverystate['haseffectiveobservations']);
        $shouldincludeskillcatalog = !empty($discoverystate['shouldincludeskillcatalog']);
        $catalogselectionmode = (string)($discoverystate['catalogselectionmode'] ?? 'none');
        $embeddingstatus = (string)($discoverystate['embeddingstatus'] ?? 'off');
        $embeddingrebuildqueued = !empty($discoverystate['embeddingrebuildqueued']);
        $unavailableskillcatalog = (array)($discoverystate['unavailableskillcatalog'] ?? []);
        $selectedskill = trim((string)($selectionstate['selected_skill'] ?? ''));

        if ($selectedskill === '') {
            return $this->build_selector_handoff_error_result();
        }

        $constructionruntimecatalog = $this->build_construction_runtime_catalog_for_selected_skill(
            $selectedskill,
            $runtimecatalog,
            $adaptivecatalog
        );

        $constructionobservations = array_values($observations);
        $constructionobservations = array_merge(
            $constructionobservations,
            $this->build_phase_handoff_observations($discoverystate, $selectionstate)
        );

        $systemprompt = $this->build_system_prompt(
            $contextid,
            $userid,
            self::PHASE_PARAMETER_CONSTRUCTION,
            $actionclass,
            $haseffectiveobservations || !empty($constructionobservations),
            $adaptivecatalog,
            $constructionruntimecatalog,
            $isfirstassistantturn,
            $shouldincludeskillcatalog
        );
        $runtimeblocks = $this->build_runtime_context_block(
            $threadid,
            $contextid,
            self::PHASE_PARAMETER_CONSTRUCTION,
            $isfirstassistantturn,
            !empty($constructionobservations),
            $constructionruntimecatalog,
            $unavailableskillcatalog,
            $messages,
            '',
            $constructionobservations,
            $this->catalog_mode_is_static($catalogselectionmode)
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $constructionobservations,
            self::PHASE_PARAMETER_CONSTRUCTION,
            $runtimeblocks['stable'],
            $plannertracehistory,
            $autoconfirmmode,
            [],
            $runtimeblocks['volatile']
        );

        $historycount = count(
            $this->promptprofilesvc->select_history_messages($messages, self::PHASE_PARAMETER_CONSTRUCTION)
        );
        $observationcount = count($constructionobservations);
        $primaryprovider = (string)($routing['primaryprovider'] ?? '');
        $debugsource = $this->orchestratorroutingsvc->build_debug_source(
            $actionclass,
            (string)($routing['routepolicy'] ?? 'default'),
            !empty($routing['routingfallback']),
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION,
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($constructionruntimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $call = $llm->invoke_for_context($threadid, $contextid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');

        if (empty($call['success'])) {
            return $this->build_provider_error_result($call);
        }

        if ($rawtext === '') {
            return $this->build_empty_provider_result();
        }

        $lastusermessage = (string)($selectionstate['lastusermessage'] ?? '');
        $constructionallowedskills = [$selectedskill];
        $interpreted = $this->interpreter->interpret_phase_output(
            $rawtext,
            self::PHASE_PARAMETER_CONSTRUCTION,
            [
                'contextid' => $contextid,
                'userid' => $userid,
                'lastusermessage' => $lastusermessage,
                'allowed_skills' => $constructionallowedskills,
            ]
        );
        if (is_array($interpreted)) {
            $interpreted['_planner_raw_response'] = $rawtext;
        }

        return $interpreted;
    }

    /**
     * Restrict construction runtime catalog to the selector-chosen skill only.
     *
     * @param string $selectedskill
     * @param array<int,array<string,mixed>> $runtimecatalog
     * @param array<int,array<string,mixed>> $adaptivecatalog
     * @return array<int,array<string,mixed>>
     */
    private function build_construction_runtime_catalog_for_selected_skill(
        string $selectedskill,
        array $runtimecatalog,
        array $adaptivecatalog
    ): array {
        $filtered = [];

        foreach ($runtimecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['skill'] ?? '')) !== $selectedskill) {
                continue;
            }
            $filtered[] = $this->enrich_construction_catalog_entry($selectedskill, $entry);
        }

        if (!empty($filtered)) {
            return array_values($filtered);
        }

        foreach ($adaptivecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['skill'] ?? '')) !== $selectedskill) {
                continue;
            }
            $filtered[] = $this->enrich_construction_catalog_entry($selectedskill, $entry);
        }

        return array_values($filtered);
    }

    /**
     * Attach concrete parameter examples for the selected construction skill.
     *
     * @param string $selectedskill
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function enrich_construction_catalog_entry(string $selectedskill, array $entry): array {
        $skill = $this->registry->get_skill($selectedskill);
        if ($skill === null) {
            return $entry;
        }

        $exampleparameters = (array)$skill->get_example_input();
        if (!empty($exampleparameters)) {
            $entry['example_parameters'] = $exampleparameters;
        }

        // In the construction phase exactly one skill is in scope, so we surface ALL of its prompt-pack
        // guidance unconditionally (no lexical trigger gate). This is the only place situational rules
        // — e.g. "for several options with the same name, search first to obtain their IDs and use
        // optionid" — actually reach the constructor. Without this, get_contextual_prompt_packs() only
        // feeds the embeddings catalog and never the live planner prompt, and trigger-based gating made
        // such guidance language-dependent (it silently vanished for non-English requests).
        $guidance = $this->collect_skill_guidance_lines($skill);
        if (!empty($guidance)) {
            $entry['guidance'] = $guidance;
        }

        return $entry;
    }

    /**
     * Collect all contextual prompt-pack guidance lines declared by a skill, unconditionally.
     *
     * Trigger arrays on the packs are ignored on purpose: in the construction phase the skill is
     * already chosen, so relevance filtering is unnecessary and the (lexical, language-specific)
     * trigger gate would only drop useful guidance.
     *
     * @param object $skill
     * @return array<int,string>
     */
    private function collect_skill_guidance_lines(object $skill): array {
        if (!method_exists($skill, 'get_contextual_prompt_packs')) {
            return [];
        }

        $lines = [];
        foreach ((array)$skill->get_contextual_prompt_packs() as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            foreach ((array)($pack['guidance'] ?? []) as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * Extract an explicitly selected skill from selection-phase output.
     *
     * @param array<string,mixed> $phaseoutput
     * @return string
     */
    private function extract_selected_skill_from_selection_phase_output(array $phaseoutput): string {
        return trim((string)($phaseoutput['selected_skill'] ?? ''));
    }

    /**
     * Build a standardized selection-phase contract error payload.
     *
     * @param string $issuecode
     * @param string $error
     * @return array<string,mixed>
     */
    private function build_selection_contract_error_result(string $issuecode, string $error): array {
        return [
            'response_type' => 'error',
            // Deliberately empty: an internal contract violation is NOT a provider
            // error. The message is composed downstream — synchronizer presentation
            // fed by the error observation, or the class template as fallback.
            'message' => '',
            'error_class' => 'internal_contract',
            'commands' => [],
            'selected_skill' => '',
            'ambiguities' => [],
            'errors' => [$error],
            'issue_codes' => [$issuecode],
        ];
    }

    /**
     * Build a standardized selector-handoff error when construction lacks selected_skill.
     *
     * @return array<string,mixed>
     */
    private function build_selector_handoff_error_result(): array {
        return [
            'response_type' => 'error',
            // Deliberately empty — internal handoff error, resolved downstream
            // (CONTRACT_SELECTION_SKILL_MISSING has a dedicated template text).
            'message' => '',
            'error_class' => 'internal_contract',
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['CONTRACT_VIOLATION: selection phase did not provide a selected_skill for construction.'],
            'issue_codes' => ['CONTRACT_SELECTION_SKILL_MISSING'],
        ];
    }

    /**
     * Build a standardized provider error payload.
     *
     * @param array<string,mixed> $call
     * @return array<string,mixed>
     */
    private function build_provider_error_result(array $call): array {
        $errormessage = (string)($call['errormessage'] ?? 'Provider returned an error.');
        $errorcode = (int)($call['errorcode'] ?? 0);
        $errorname = (string)($call['errorname'] ?? '');
        $issuecodes = ai_error_classifier::classify_from_response($errormessage, $errorcode, $errorname);

        $errorclass = 'provider_error';
        if (in_array('TRIAL_TOKEN_INVALID', $issuecodes, true)) {
            $errorclass = 'auth_failed';
        } else if (in_array('AI_PROVIDER_QUOTA_EXCEEDED', $issuecodes, true)) {
            $errorclass = 'quota_exceeded';
        } else {
            $lower = core_text::strtolower($errormessage);
            if (strpos($lower, 'timeout') !== false || strpos($lower, 'timed out') !== false) {
                $errorclass = 'provider_timeout';
            } else if (strpos($lower, 'curl error 28') !== false || strpos($lower, 'connection reset') !== false) {
                $errorclass = 'transient_io';
            }
        }

        return [
            'response_type' => 'error',
            // Deliberately empty: the template fallback resolves the localized
            // class-specific text from error_class (provider classes never go to
            // the synchronizer — the provider itself is the failing component).
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            'errors' => [$errormessage],
            'issue_codes' => $issuecodes,
            'error_class' => $errorclass,
        ];
    }

    /**
     * Build a standardized empty-provider payload.
     *
     * @return array<string,mixed>
     */
    private function build_empty_provider_result(): array {
        return [
            'response_type' => 'error',
            // Deliberately empty — the transient_io class template resolves it.
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['Provider returned empty content.'],
            'issue_codes' => [],
            'error_class' => 'transient_io',
        ];
    }

    /**
     * Build compact observations to hand off discovery/selection outcomes.
     *
     * @param array<string,mixed> $discoverystate
     * @param array<string,mixed> $selectionstate
     * @return array<int,string>
     */
    private function build_phase_handoff_observations(array $discoverystate, array $selectionstate): array {
        $observations = [];
        $discoverypayload = [
            'phase' => self::PHASE_DISCOVERY,
            'response_type' => (string)($discoverystate['response_type'] ?? ''),
            'message' => (string)($discoverystate['message'] ?? ''),
            'issue_codes' => (array)($discoverystate['issue_codes'] ?? []),
            'errors' => (array)($discoverystate['errors'] ?? []),
        ];
        $selectionpayload = [
            'phase' => self::PHASE_SELECTION,
            'response_type' => (string)($selectionstate['response_type'] ?? ''),
            'message' => (string)($selectionstate['message'] ?? ''),
            'selected_skill' => (string)($selectionstate['selected_skill'] ?? ''),
            'issue_codes' => (array)($selectionstate['issue_codes'] ?? []),
            'errors' => (array)($selectionstate['errors'] ?? []),
        ];

        $discoveryjson = $this->json_encode_or_empty($discoverypayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($discoveryjson !== '') {
            $observations[] = 'phase_handoff.discovery=' . $discoveryjson;
        }

        $selectionjson = $this->json_encode_or_empty($selectionpayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($selectionjson !== '') {
            $observations[] = 'phase_handoff.selection=' . $selectionjson;
        }

        return $observations;
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
- CONTEXT-AWARE PLANNING: Do NOT add a search/resolution/lookup step for a target that is already
  identified in the SYSTEM_RUNTIME context. If the target course/activity is the current one in
  SYSTEM_RUNTIME.moodle_context, select the action skill directly instead of a preceding search step
  (e.g. "create a quiz in this course" -> the quiz skill now, NOT course.search_courses first).
  Plan a resolution step only when the user names a target that is NOT the current context.
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
     * Build the state-based system prompt with compact skill metadata embedded.
     *
     * @param  int    $contextid
     * @param  string $actionclass
     * @param  bool   $hasobservations
     * @param  array  $adaptivecatalog Optional adaptive skill catalog (reduced by recency/tier). If null, uses full catalog.
     * @param  array  $systemskillcatalog Optional exact skill catalog to embed into SYSTEM placeholders.
     * @param  bool   $isfirstassistantturn True when no assistant message exists yet in this thread.
     * @param  bool   $includeskillcatalog If true, embed skill catalog placeholder in SYSTEM block.
     * @return string System prompt text.
     */
    private function build_system_prompt(
        int $contextid,
        int $userid,
        string $phase = self::PHASE_DISCOVERY,
        string $actionclass = generate_text::class,
        bool $hasobservations = false,
        ?array $adaptivecatalog = null,
        array $systemskillcatalog = [],
        bool $isfirstassistantturn = false,
        bool $includeskillcatalog = false
    ): string {
        return $this->promptbundlebuilder->build_system_prompt(
            $userid,
            $contextid,
            $phase,
            $actionclass,
            $hasobservations,
            $adaptivecatalog,
            $systemskillcatalog,
            $isfirstassistantturn,
            $includeskillcatalog
        );
    }

    /**
     * Reduce skill catalog entries to planner-facing routing metadata only.
     *
     * @param array $skillcatalog
     * @return array
     */
    private function slim_prompt_catalog_for_planner(array $skillcatalog): array {
        return $this->plannercatalogsvc->slim_prompt_catalog_for_planner($skillcatalog);
    }

    /**
     * Inject skills that declare governance mandatory_on_trigger when the latest user message matches
     * one of their declared intent_triggers.
     *
     * This replaces the previous skill-name-specific routing (hardcoded explain_docs / list_skills +
     * de/en keyword heuristics in the engine). Both the "must be offered" decision and the trigger
     * phrases now live entirely in the skill's governance contract, so the engine stays agnostic:
     * it carries no skill names and no language keywords. Embedding top-k discovery can rank domain
     * skills above such a meta-skill; this guarantees it still reaches the selector, which decides.
     * No-op for skills already present, for skills not declaring the flag, or on no trigger match.
     *
     * @param array<int,array<string,mixed>> $runtimecatalog Final (post-filter) candidate catalog.
     * @param array<int,array<string,mixed>> $allcontracts   Full skill contracts (source of the row).
     * @param array<int,object> $messages                    Conversation messages (latest user text).
     * @return array<int,array<string,mixed>>
     */
    private function ensure_trigger_mandatory_skills(
        array $runtimecatalog,
        array $allcontracts,
        array $messages
    ): array {
        return $this->plannercatalogsvc->ensure_trigger_mandatory_skills($runtimecatalog, $allcontracts, $messages);
    }

    /**
     * Whether any declared intent trigger occurs (case-insensitive substring) in the user message.
     *
     * @param string $haystack Already-lowercased user message.
     * @param array<int,mixed> $triggers Skill-declared trigger phrases.
     * @return bool
     */
    private function message_matches_intent_triggers(string $haystack, array $triggers): bool {
        return $this->plannercatalogsvc->message_matches_intent_triggers($haystack, $triggers);
    }

    /**
     * Keep only planner-relevant fields before runtime catalog prompt injection.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @return array<int,array<string,mixed>>
     */
    private function sanitize_runtime_catalog_for_prompt(array $catalog): array {
        return $this->plannercatalogsvc->sanitize_runtime_catalog_for_prompt($catalog);
    }

    /**
     * Decode JSON array/object payload safely.
     *
     * @param string $json
     * @return array<int|string,mixed>
     */
    private function decode_catalog_json_array(string $json): array {
        return $this->plannercatalogsvc->decode_catalog_json_array($json);
    }

    /**
     * Render the skill catalog as compact plain text instead of JSON.
     *
     * @param array $catalog
     * @return string
     */
    private function render_catalog_as_text(array $catalog): string {
        return $this->plannercatalogsvc->render_catalog_as_text($catalog);
    }

    /**
     * Compact the skill catalog description to a shorter length.
     *
     * @param string $description The raw description.
     * @return string The compacted description.
     */
    private function compact_catalog_description(string $description): string {
        return $this->plannercatalogsvc->compact_catalog_description($description);
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
        return $this->plannercatalogsvc->compact_catalog_example_input($exampleinput);
    }

    /**
     * Drop verbose trigger examples and keep compact id + short description only.
     *
     * @param array $triggers
     * @return array<int,array<string,string>>
     */
    private function compact_catalog_message_triggers(array $triggers): array {
        return $this->plannercatalogsvc->compact_catalog_message_triggers($triggers);
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
     * @param  string      $phase Explicit planner phase (discovery/selection/parameter_construction).
     * @param  string      $runtimecontext Per-thread-stable runtime facts appended after static system prompt.
     * @param  string[]    $plannertracehistory Full planner trace history from thread metadata.
     * @param  bool        $autoconfirmmode Whether confirmation is already allowed for this thread.
     * @param  string      $runtimestate Per-request volatile runtime state appended after history.
     * @return string
     */
    private function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations = [],
        string $phase = self::PHASE_DISCOVERY,
        string $runtimecontext = '',
        array $plannertracehistory = [],
        bool $autoconfirmmode = false,
        array $plannedstepintents = [],
        string $runtimestate = ''
    ): string {
        return $this->promptbundlebuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $phase,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode,
            $plannedstepintents,
            $runtimestate
        );
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
     * @return array{stable: string, volatile: string}
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
    /**
     * Whether the active skill catalog is static across turns (no embeddings / slim_all family).
     *
     * A static catalog is identical every turn, so it belongs in the per-thread-stable prompt block
     * (cached prefix); an adaptive embeddings top-K catalog changes per query and stays volatile.
     *
     * @param string $catalogselectionmode the resolved catalog selection mode
     * @return bool
     */
    private function catalog_mode_is_static(string $catalogselectionmode): bool {
        return $this->plannercatalogsvc->catalog_mode_is_static($catalogselectionmode);
    }

    /**
     * Whitespace-normalize observation text for the ledger-vs-live dedup check.
     *
     * @param string $text
     * @return string
     */
    private function normalize_for_observation_dedup(string $text): string {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
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
     * Keep only catalog entries whose skill family is in selected discovery families.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @param array<int,string> $selectedfamilies
     * @return array<int,array<string,mixed>>
     */
    private function filter_catalog_by_selected_families(array $catalog, array $selectedfamilies): array {
        return $this->plannercatalogsvc->filter_catalog_by_selected_families($catalog, $selectedfamilies);
    }

    /**
     * Split prompt contracts into readonly (selectable without full access) and
     * mutating ones, which move to the unavailable catalog with an upgrade hint.
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
     */
    private function split_prompt_contracts_by_full_access(array $contracts): array {
        return $this->plannercatalogsvc->split_prompt_contracts_by_full_access($contracts);
    }

    /**
     * Resolve a deterministic namespace hint from prompt contracts.
     *
     * @param array<int,array<string,mixed>> $promptcontracts
     * @return string
     */
    private function resolve_namespace_hint_from_prompt_contracts(array $promptcontracts): string {
        return $this->plannercatalogsvc->resolve_namespace_hint_from_prompt_contracts($promptcontracts);
    }
}
