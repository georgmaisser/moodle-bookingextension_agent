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
use bookingextension_agent\local\wbagent\contracts\task_family_contract;
use bookingextension_agent\local\wbagent\config\runtime_feature_flags;
use bookingextension_agent\local\wbagent\interfaces\agent_interpreter;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\services\catalog\adaptive_task_catalog_service;
use bookingextension_agent\local\wbagent\services\discovery\family_ranker;
use bookingextension_agent\local\wbagent\services\discovery\family_registry_service;
use bookingextension_agent\local\wbagent\services\discovery\family_signal_ranker;
use bookingextension_agent\local\wbagent\services\discovery\discovery_stage_controller;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wbagent\services\embeddings\family_embeddings_retrieval_service;
use bookingextension_agent\local\wbagent\services\assistant_state_guidance_service;
use bookingextension_agent\local\wbagent\services\completed_command_history_service;
use bookingextension_agent\local\wbagent\services\execution_observation_ledger;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;
use bookingextension_agent\local\wbagent\services\discovery\context_prior_builder;
use bookingextension_agent\local\wbagent\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wbagent\services\orchestrator_routing_service;
use bookingextension_agent\local\wbagent\services\planner_result_composer;
use bookingextension_agent\local\wbagent\services\provider_routing_util;
use bookingextension_agent\local\wbagent\services\synchronizer_prompt_builder;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\services\telemetry\routing_decision_log_service;

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

    /** Discovery planner phase identifier. */
    public const PHASE_DISCOVERY = 'discovery';

    /** Selection planner phase identifier. */
    public const PHASE_SELECTION = 'selection';

    /** Parameter construction planner phase identifier. */
    public const PHASE_PARAMETER_CONSTRUCTION = 'parameter_construction';

    /** Default model for task-catalog embeddings. */
    public const EMBEDDINGS_DEFAULT_MODEL = 'text-embedding-3-small';

    /** Default embedding dimensions. */
    public const EMBEDDINGS_DEFAULT_DIMENSIONS = 1536;

    /** Default number of best matching tasks to inject for first planner step. */
    public const EMBEDDINGS_DEFAULT_TOP_K = 6;

    /** Debounce window (seconds) for scheduling embeddings rebuild task. */
    public const EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS = 300;

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

    /** @var phase_prompt_bundle_builder */
    private phase_prompt_bundle_builder $promptbundlebuilder;

    /** @var synchronizer_prompt_builder */
    private synchronizer_prompt_builder $synchronizerpromptbuilder;

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
            self::WB_ACTION_PLANNER_DECIDE
        );
        $this->promptprofilesvc = new orchestrator_prompt_profile_service();
        $this->promptbundlebuilder = new phase_prompt_bundle_builder($this->registry, $this->promptprofilesvc);
        $this->synchronizerpromptbuilder = new synchronizer_prompt_builder();
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

            $toolrouting = $this->orchestratorroutingsvc->resolve_action_class_for_phase(
                $manager,
                $context,
                orchestrator_routing_service::PHASE_DISCOVERY
            );
            $toolactionclass = (string)($toolrouting['actionclass'] ?? '');
            $finalactionclass = self::WB_ACTION_GENERATE_AGENT_REPLY;

            $toolroutepolicy = (string)($toolrouting['routepolicy'] ?? 'default');
            $finalroutepolicy = 'cons_wunderbyte';

            $wunderbyteroutingselected =
                $this->orchestratorroutingsvc->is_wunderbyte_routepolicy($toolroutepolicy)
                && $this->orchestratorroutingsvc->is_wunderbyte_routepolicy($finalroutepolicy);

            $toolenabledincontext = false;
            if ($toolactionclass !== '') {
                if ($wunderbyteroutingselected) {
                    // Explicit override for wunderbyte custom actions: they are not
                    // placement-backed in core, so do not block on module action flags.
                    $toolenabledincontext = true;
                } else if ($this->orchestratorroutingsvc->is_wunderbyte_routepolicy($toolroutepolicy)) {
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
                } else if ($this->orchestratorroutingsvc->is_wunderbyte_routepolicy($finalroutepolicy)) {
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
     * @param  agent_state|null $agentstate Optional per-run loop state for cache reuse across steps.
     * @return array  Interpreter result.
     */
    public function process(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations = [],
        ?agent_state $agentstate = null
    ): array {
        $context = context_module::instance($cmid);
        $manager = di::get(ai_manager::class);
        $evaluator = new task_executability_evaluator($this->registry, new authorization_service());
        $discoverystate = $this->run_discovery_phase(
            $threadid,
            $cmid,
            $userid,
            $observations,
            $agentstate,
            $context,
            $manager,
            $evaluator
        );

        $selectionstate = $this->run_selection_phase(
            $threadid,
            $cmid,
            $userid,
            $observations,
            $discoverystate,
            $context,
            $manager
        );

        $constructionstate = $this->run_construction_phase(
            $threadid,
            $cmid,
            $userid,
            $observations,
            $discoverystate,
            $selectionstate
        );

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
     * @param int $cmid
     * @param int $userid
     * @param array<int,string> $observations
     * @return array<string,mixed>
     */
    public function process_synchronizer(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations = []
    ): array {
        $context = context_module::instance($cmid);
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
        $runtimecontext = $this->build_runtime_context_block(
            $threadid,
            $cmid,
            self::PHASE_SELECTION,
            $isfirstassistantturn,
            !empty($observations),
            [],
            [],
            $messages
        );
        $prompt = $this->synchronizerpromptbuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $runtimecontext
        );

        $llm = new llm_call_service($this->store);
        $debugsource = 'sync|st=sr|ac=' . ($actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY ? 'agr' : 'gen')
            . '|rt=' . ($routepolicy === 'sync_wunderbyte' ? 'wb' : 'df')
            . '|fb=' . ($routingfallback ? '1' : '0')
            . '|ob=' . count($observations);

        $call = $llm->invoke($threadid, $cmid, $userid, $debugsource, $prompt, $actionclass);
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
     * @param context_module $context
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    private function resolve_synchronizer_action_class(ai_manager $manager, context_module $context): array {
        try {
            if ($manager->is_action_available(self::WB_ACTION_GENERATE_AGENT_REPLY)) {
                return [
                    'actionclass' => self::WB_ACTION_GENERATE_AGENT_REPLY,
                    'routepolicy' => 'sync_wunderbyte',
                    'routingfallback' => false,
                ];
            }
        } catch (\Throwable $e) {
            $ignored = $e;
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
     * @param int $cmid
     * @param int $userid
     * @param array $observations
     * @param agent_state|null $agentstate
     * @param context_module $context
     * @param ai_manager $manager
     * @param task_executability_evaluator $evaluator
     * @return array<string,mixed>
     */
    private function run_discovery_phase(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        ?agent_state $agentstate,
        context_module $context,
        ai_manager $manager,
        task_executability_evaluator $evaluator
    ): array {
        $contextid = (int)$context->id;

        $routing = $this->orchestratorroutingsvc->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_SELECTION
        );
        $actionclass = (string)($routing['actionclass'] ?? '');

        $messages = array_values(array_filter(
            $this->store->get_messages($threadid),
            static fn($msg): bool => (string)($msg->role ?? '') !== 'step'
        ));

        $recenttaskhistory = $this->extract_recent_task_names_from_messages($messages);
        $isfirstassistantturn = $this->is_first_assistant_turn($messages);
        $promptcontracts = $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid);
        $adaptivecatalogresult = adaptive_task_catalog_service::get_adaptive_catalog(
            $promptcontracts,
            $recenttaskhistory,
            orchestrator_routing_service::PHASE_DISCOVERY
        );
        $adaptivecatalog = $adaptivecatalogresult['active_tasks'];

        $hasanyobservations = !empty($observations);
        $haseffectiveobservations = $hasanyobservations
            && !$this->promptprofilesvc->observations_are_framework_retry_hints($observations);
        $plannertracehistory = $this->normalize_planner_trace_history(
            $this->store->get_thread_metadata_value($threadid, 'planner_trace_history')
        );
        $shouldincludetaskcatalog = !$hasanyobservations;

        $runtimecatalog = [];
        $unavailabletaskcatalog = [];
        $catalogselectionmode = 'none';
        $embeddingstatus = 'off';
        $embeddingrebuildqueued = false;
        $usedembeddingcache = false;
        $discoverystage = 'none';
        $discoveryconfidencescore = null;
        $discoveryescalationreason = 'none';
        $selectedfamilies = [];
        $embeddingcall = [];
        $status = [];
        $llm = new llm_call_service($this->store);

        if ($shouldincludetaskcatalog) {
            $allpromptcontracts = $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid, true);
            $runtimecatalog = $this->slim_prompt_catalog_for_planner($allpromptcontracts);
            $catalogselectionmode = 'slim_all';

            $iswunderbyteplanner =
                $this->orchestratorroutingsvc->is_wunderbyte_routepolicy((string)($routing['routepolicy'] ?? ''))
                && $actionclass === self::WB_ACTION_PLANNER_DECIDE;

            if ($iswunderbyteplanner) {
                $embeddingstatus = 'check';
                $embeddingsettings = (new embeddings_action_config_resolver())->resolve();
                $embeddingmodel = (string)($embeddingsettings['model'] ?? self::EMBEDDINGS_DEFAULT_MODEL);
                $embeddingdimensions = (int)($embeddingsettings['dimensions'] ?? self::EMBEDDINGS_DEFAULT_DIMENSIONS);
                $querytext = '';
                foreach (array_reverse($messages) as $msg) {
                    if (($msg->role ?? '') === 'user') {
                        $querytext = trim((string)($msg->content ?? ''));
                        break;
                    }
                }

                $cachekey = '';
                if ($querytext !== '') {
                    $cachekey = sha1(
                        $querytext
                        . '|m=' . $embeddingmodel
                        . '|d=' . $embeddingdimensions
                        . '|u=' . $userid
                        . '|c=' . $contextid
                    );
                }

                if ($cachekey !== '' && $agentstate !== null) {
                    $cachedcatalog = $agentstate->get_discovery_family_cache($cachekey);
                    if ($cachedcatalog !== null) {
                        $runtimecatalog = $this->sanitize_runtime_catalog_for_prompt(
                            (array)($cachedcatalog['runtimecatalog'] ?? $runtimecatalog)
                        );
                        $unavailabletaskcatalog = (array)($cachedcatalog['unavailabletaskcatalog'] ?? $unavailabletaskcatalog);
                        $catalogselectionmode = (string)($cachedcatalog['catalogselectionmode'] ?? 'embed_topk_cache');
                        $embeddingstatus = 'cached_' . trim((string)($cachedcatalog['embeddingstatus'] ?? 'applied'));
                        $embeddingrebuildqueued = !empty($cachedcatalog['embeddingrebuildqueued']);
                        $usedembeddingcache = true;
                    }
                }

                if (!$usedembeddingcache) {
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

                        if (!empty($status['ready']) && !empty($status['rows']) && is_array($status['rows']) && $querytext !== '') {
                            $embeddingcall = $llm->invoke_embeddings(
                                $threadid,
                                $cmid,
                                $userid,
                                'orc|p=disc|st=tcp|ac=emb|rt=wb',
                                $querytext,
                                $embeddingdimensions
                            );

                            if (!empty($embeddingcall['success']) && !empty($embeddingcall['embedding'])) {
                                $retrieval = new embeddings_retrieval_service();
                                $toprows = $retrieval->search_top_k(
                                    (array)$embeddingcall['embedding'],
                                    $status['rows'],
                                    self::EMBEDDINGS_DEFAULT_TOP_K
                                );

                                if (runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED)) {
                                    $familycontextprior = (new context_prior_builder())->build($contextid, [
                                        'userid' => $userid,
                                        'namespace_hint' =>
                                            $this->resolve_namespace_hint_from_prompt_contracts($allpromptcontracts),
                                    ]);
                                    $familydiscovery = (new family_registry_service())->discover(
                                        $allpromptcontracts,
                                        $familycontextprior
                                    )->to_array();
                                    $families = (array)($familydiscovery['families'] ?? []);
                                    if (!empty($families)) {
                                        $signalscores = (new family_signal_ranker())->score_families(
                                            $families,
                                            $familycontextprior,
                                            $recenttaskhistory
                                        );
                                        $semanticscores = (new family_embeddings_retrieval_service())->score_families(
                                            $families,
                                            (array)$embeddingcall['embedding'],
                                            (array)$status['rows']
                                        );
                                        $rankedfamilies = (new family_ranker())->rank(
                                            $families,
                                            $signalscores,
                                            $semanticscores
                                        );
                                        $familyscores = [];
                                        foreach ($rankedfamilies as $row) {
                                            $family = trim((string)($row['family'] ?? ''));
                                            if ($family === '') {
                                                continue;
                                            }
                                            $familyscores[$family] = (float)($row['score'] ?? 0.0);
                                        }

                                        if (!empty($familyscores)) {
                                            $toprows = (new family_embeddings_retrieval_service())
                                                ->boost_task_rows($toprows, $familyscores);
                                            $embeddingstatus = 'family_boosted';
                                        }
                                    }
                                }

                                if (!empty($toprows)) {
                                    $runtimecatalog = $this->sanitize_runtime_catalog_for_prompt(array_values($toprows));
                                    $catalogselectionmode = $embeddingstatus === 'family_boosted'
                                        ? 'embed_topk_family_boost'
                                        : 'embed_topk';
                                    $embeddingstatus = 'applied';
                                } else {
                                    $embeddingstatus = 'nomatch';
                                }
                            } else {
                                $embeddingstatus = 'callfail';
                            }
                        }
                    } else {
                        $embeddingstatus = 'unavailable';
                    }

                    if ($cachekey !== '' && $agentstate !== null) {
                        $agentstate->set_discovery_family_cache($cachekey, [
                            'runtimecatalog' => $runtimecatalog,
                            'unavailabletaskcatalog' => $unavailabletaskcatalog,
                            'catalogselectionmode' => $catalogselectionmode,
                            'embeddingstatus' => $embeddingstatus,
                            'embeddingrebuildqueued' => $embeddingrebuildqueued,
                        ]);
                    }
                }
            }

            if (runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_DISCOVERY_ENABLED)) {
                $namespacehint = $this->resolve_namespace_hint_from_prompt_contracts($allpromptcontracts);
                $familycontextprior = (new context_prior_builder())->build($contextid, [
                    'userid' => $userid,
                    'namespace_hint' => $namespacehint,
                ]);
                $familydiscovery = (new family_registry_service())->discover(
                    $allpromptcontracts,
                    $familycontextprior
                )->to_array();
                $families = (array)($familydiscovery['families'] ?? []);
                if (!empty($families)) {
                    $signalscores = (new family_signal_ranker())->score_families(
                        $families,
                        $familycontextprior,
                        $recenttaskhistory
                    );

                    $semanticscores = [];
                    if (
                        !empty($embeddingcall['success'])
                        && !empty($embeddingcall['embedding'])
                        && !empty($status['rows'])
                        && is_array($status['rows'])
                    ) {
                        $semanticscores = (new family_embeddings_retrieval_service())->score_families(
                            $families,
                            (array)$embeddingcall['embedding'],
                            (array)$status['rows']
                        );
                    }

                    $rankedfamilies = (new family_ranker())->rank(
                        $families,
                        $signalscores,
                        $semanticscores
                    );
                    $stageresult = (new discovery_stage_controller())->resolve(
                        $rankedfamilies,
                        (array)($familydiscovery['context_families'] ?? []),
                        (array)($familydiscovery['core_families'] ?? [])
                    );

                    $discoverystage = (string)($stageresult['discovery_stage'] ?? 'none');
                    $discoveryconfidencescore = $stageresult['confidence_score'] ?? null;
                    $discoveryescalationreason = (string)($stageresult['escalation_reason'] ?? 'none');
                    $selectedfamilies = array_values(array_filter(array_map(
                        static fn($family): string => task_family_contract::normalize_family((string)$family),
                        (array)($stageresult['selected_families'] ?? [])
                    )));

                    if (!empty($selectedfamilies)) {
                        $runtimecatalog = $this->filter_catalog_by_selected_families($runtimecatalog, $selectedfamilies);
                        if ($catalogselectionmode === 'slim_all') {
                            $catalogselectionmode = 'slim_family_stage_' . strtolower($discoverystage);
                        } else if (str_starts_with($catalogselectionmode, 'embed_topk')) {
                            $catalogselectionmode .= '_stage_' . strtolower($discoverystage);
                        }
                    }
                }
            }
        }

        $systemprompt = $this->build_system_prompt(
            $cmid,
            $userid,
            $contextid,
            self::PHASE_DISCOVERY,
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
            self::PHASE_DISCOVERY,
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
            self::PHASE_DISCOVERY,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode
        );

        $historycount = count(array_slice(
            $messages,
            -$this->promptprofilesvc->get_history_limit_for_phase(self::PHASE_DISCOVERY)
        ));
        $observationcount = count($observations);
        $primaryprovider = (string)($routing['primaryprovider'] ?? '');
        $debugsource = $this->orchestratorroutingsvc->build_debug_source(
            $actionclass,
            (string)($routing['routepolicy'] ?? 'default'),
            !empty($routing['routingfallback']),
            orchestrator_routing_service::PHASE_DISCOVERY,
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($runtimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $phaseoutput = [
            'response_type' => 'sufficient',
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            'errors' => [],
            'issue_codes' => [],
            'used_triggers' => [],
            'next_step_intent' => '',
            'phase' => self::PHASE_DISCOVERY,
            'catalogselectionmode' => $catalogselectionmode,
            'embeddingstatus' => $embeddingstatus,
            'discovery_stage' => $discoverystage,
            'discovery_confidence_score' => $discoveryconfidencescore,
            'discovery_escalation_reason' => $discoveryescalationreason,
            'selected_families' => $selectedfamilies,
        ];

        return [
            'contextid' => $contextid,
            'routing' => $routing,
            'actionclass' => $actionclass,
            'messages' => $messages,
            'recenttaskhistory' => $recenttaskhistory,
            'isfirstassistantturn' => $isfirstassistantturn,
            'promptcontracts' => $promptcontracts,
            'adaptivecatalog' => $adaptivecatalog,
            'hasanyobservations' => $hasanyobservations,
            'haseffectiveobservations' => $haseffectiveobservations,
            'plannertracehistory' => $plannertracehistory,
            'shouldincludetaskcatalog' => $shouldincludetaskcatalog,
            'runtimecatalog' => $runtimecatalog,
            'unavailabletaskcatalog' => $unavailabletaskcatalog,
            'catalogselectionmode' => $catalogselectionmode,
            'embeddingstatus' => $embeddingstatus,
            'embeddingrebuildqueued' => $embeddingrebuildqueued,
            'discovery_stage' => $discoverystage,
            'discovery_confidence_score' => $discoveryconfidencescore,
            'discovery_escalation_reason' => $discoveryescalationreason,
            'selected_families' => $selectedfamilies,
            'prompt' => $prompt,
            'debugsource' => $debugsource,
            'phase' => self::PHASE_DISCOVERY,
            'phase_output' => $phaseoutput,
            'response_type' => (string)($phaseoutput['response_type'] ?? ''),
            'message' => (string)($phaseoutput['message'] ?? ''),
            'issue_codes' => (array)($phaseoutput['issue_codes'] ?? []),
            'errors' => (array)($phaseoutput['errors'] ?? []),
        ];
    }

    /**
     * Selection phase: build prompt, telemetry, and debug-source payload.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $observations
     * @param array<string,mixed> $discoverystate
     * @param context_module $context
     * @param ai_manager $manager
     * @return array<string,mixed>
     */
    private function run_selection_phase(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        array $discoverystate,
        context_module $context,
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
        $unavailabletaskcatalog = (array)($discoverystate['unavailabletaskcatalog'] ?? []);
        $plannertracehistory = (array)($discoverystate['plannertracehistory'] ?? []);
        $catalogselectionmode = (string)($discoverystate['catalogselectionmode'] ?? 'none');
        $embeddingstatus = (string)($discoverystate['embeddingstatus'] ?? 'off');
        $embeddingrebuildqueued = !empty($discoverystate['embeddingrebuildqueued']);
        $hasanyobservations = !empty($discoverystate['hasanyobservations']);
        $haseffectiveobservations = !empty($discoverystate['haseffectiveobservations']);
        $isfirstassistantturn = !empty($discoverystate['isfirstassistantturn']);
        $shouldincludetaskcatalog = !empty($discoverystate['shouldincludetaskcatalog']);
        $adaptivecatalog = (array)($discoverystate['adaptivecatalog'] ?? []);
        $discoverystage = (string)($discoverystate['discovery_stage'] ?? 'none');
        $discoveryconfidencescore = $discoverystate['discovery_confidence_score'] ?? null;
        $discoveryescalationreason = (string)($discoverystate['discovery_escalation_reason'] ?? 'none');

        $systemprompt = $this->build_system_prompt(
            $cmid,
            $userid,
            $contextid,
            self::PHASE_SELECTION,
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
            self::PHASE_SELECTION,
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
            self::PHASE_SELECTION,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode
        );

        $historycount = count(array_slice(
            $messages,
            -$this->promptprofilesvc->get_history_limit_for_phase(self::PHASE_SELECTION)
        ));
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
        $call = $llm->invoke($threadid, $cmid, $userid, $debugsource, $prompt, $actionclass);
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
                    'recent_task_names' => (array)($discoverystate['recenttaskhistory'] ?? []),
                ]
            );
        } catch (\Throwable $e) {
            $ignored = $e;
        }

        $lastusermessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg->role ?? '') === 'user') {
                $lastusermessage = trim((string)($msg->content ?? ''));
                break;
            }
        }

        $selectedtask = $this->extract_selected_task_from_selection_phase_output($phaseoutput);

        return [
            'prompt' => $prompt,
            'debugsource' => $debugsource,
            'lastusermessage' => $lastusermessage,
            'selected_task' => $selectedtask,
            'phase' => self::PHASE_SELECTION,
            'phase_output' => $phaseoutput,
            'response_type' => (string)($phaseoutput['response_type'] ?? ''),
            'message' => (string)($phaseoutput['message'] ?? ''),
            'issue_codes' => (array)($phaseoutput['issue_codes'] ?? []),
            'errors' => (array)($phaseoutput['errors'] ?? []),
        ];
    }

    /**
     * Normalize selection output to an explicit single-task selector handoff.
     *
     * This strips accidental parameter payloads from selection commands and keeps
     * only the selected task identity for constructor handoff.
     *
     * @param array<string,mixed> $phaseoutput
     * @return array<string,mixed>
     */
    private function normalize_selection_phase_output_for_handoff(array $phaseoutput): array {
        $responsetype = trim((string)($phaseoutput['response_type'] ?? ''));
        if ($responsetype !== 'task_call') {
            if (!isset($phaseoutput['selected_task'])) {
                $phaseoutput['selected_task'] = '';
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
        $selectedtask = trim((string)($phaseoutput['selected_task'] ?? ''));
        if ($selectedtask === '') {
            $selectedtask = trim((string)($command['task'] ?? ''));
        }
        if ($selectedtask === '') {
            return $this->build_selection_contract_error_result(
                'CONTRACT_SELECTION_TASK_MISSING',
                'CONTRACT_VIOLATION: selection phase task_call did not provide a selected task.'
            );
        }

        $version = max(1, (int)($command['version'] ?? 1));
        $phaseoutput['selected_task'] = $selectedtask;
        $phaseoutput['commands'] = [[
            'task' => $selectedtask,
            'version' => $version,
            'input' => [],
        ]];

        return $phaseoutput;
    }

    /**
     * Construction phase: execute planner call and interpret response.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array<string,mixed> $discoverystate
     * @param array<string,mixed> $selectionstate
     * @return array<string,mixed>
     */
    private function run_construction_phase(
        int $threadid,
        int $cmid,
        int $userid,
        array $observations,
        array $discoverystate,
        array $selectionstate
    ): array {
        $llm = new llm_call_service($this->store);
        $context = context_module::instance($cmid);
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
        $shouldincludetaskcatalog = !empty($discoverystate['shouldincludetaskcatalog']);
        $catalogselectionmode = (string)($discoverystate['catalogselectionmode'] ?? 'none');
        $embeddingstatus = (string)($discoverystate['embeddingstatus'] ?? 'off');
        $embeddingrebuildqueued = !empty($discoverystate['embeddingrebuildqueued']);
        $unavailabletaskcatalog = (array)($discoverystate['unavailabletaskcatalog'] ?? []);
        $selectedtask = trim((string)($selectionstate['selected_task'] ?? ''));

        if ($selectedtask === '') {
            return $this->build_selector_handoff_error_result();
        }

        $constructionruntimecatalog = $this->build_construction_runtime_catalog_for_selected_task(
            $selectedtask,
            $runtimecatalog,
            $adaptivecatalog
        );

        $constructionobservations = array_values($observations);
        $constructionobservations = array_merge(
            $constructionobservations,
            $this->build_phase_handoff_observations($discoverystate, $selectionstate)
        );

        $systemprompt = $this->build_system_prompt(
            $cmid,
            $userid,
            $contextid,
            self::PHASE_PARAMETER_CONSTRUCTION,
            $actionclass,
            $haseffectiveobservations || !empty($constructionobservations),
            $adaptivecatalog,
            $constructionruntimecatalog,
            $isfirstassistantturn,
            $shouldincludetaskcatalog
        );
        $runtimecontext = $this->build_runtime_context_block(
            $threadid,
            $cmid,
            self::PHASE_PARAMETER_CONSTRUCTION,
            $isfirstassistantturn,
            !empty($constructionobservations),
            $constructionruntimecatalog,
            $unavailabletaskcatalog,
            $messages
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $constructionobservations,
            self::PHASE_PARAMETER_CONSTRUCTION,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode
        );

        $historycount = count(array_slice(
            $messages,
            -$this->promptprofilesvc->get_history_limit_for_phase(self::PHASE_PARAMETER_CONSTRUCTION)
        ));
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

        $call = $llm->invoke($threadid, $cmid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');

        if (empty($call['success'])) {
            return $this->build_provider_error_result($call);
        }

        if ($rawtext === '') {
            return $this->build_empty_provider_result();
        }

        $lastusermessage = (string)($selectionstate['lastusermessage'] ?? '');
        $constructionallowedtasks = [$selectedtask];
        $interpreted = $this->interpreter->interpret_phase_output(
            $rawtext,
            self::PHASE_PARAMETER_CONSTRUCTION,
            [
                'contextid' => $contextid,
                'userid' => $userid,
                'lastusermessage' => $lastusermessage,
                'allowed_tasks' => $constructionallowedtasks,
            ]
        );
        if (is_array($interpreted)) {
            $interpreted['_planner_raw_response'] = $rawtext;
        }

        return $interpreted;
    }

    /**
     * Restrict construction runtime catalog to the selector-chosen task only.
     *
     * @param string $selectedtask
     * @param array<int,array<string,mixed>> $runtimecatalog
     * @param array<int,array<string,mixed>> $adaptivecatalog
     * @return array<int,array<string,mixed>>
     */
    private function build_construction_runtime_catalog_for_selected_task(
        string $selectedtask,
        array $runtimecatalog,
        array $adaptivecatalog
    ): array {
        $filtered = [];

        foreach ($runtimecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['task'] ?? '')) !== $selectedtask) {
                continue;
            }
            $filtered[] = $this->enrich_construction_catalog_entry($selectedtask, $entry);
        }

        if (!empty($filtered)) {
            return array_values($filtered);
        }

        foreach ($adaptivecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['task'] ?? '')) !== $selectedtask) {
                continue;
            }
            $filtered[] = $this->enrich_construction_catalog_entry($selectedtask, $entry);
        }

        return array_values($filtered);
    }

    /**
     * Attach concrete parameter examples for the selected construction task.
     *
     * @param string $selectedtask
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function enrich_construction_catalog_entry(string $selectedtask, array $entry): array {
        $task = $this->registry->get_task($selectedtask);
        if ($task === null) {
            return $entry;
        }

        $exampleparameters = (array)$task->get_example_input();
        if (!empty($exampleparameters)) {
            $entry['example_parameters'] = $exampleparameters;
        }

        return $entry;
    }

    /**
     * Build construction-phase task allow-list from discovery-ranked catalogs.
     *
     * @param array<int,array<string,mixed>> $runtimecatalog
     * @param array<int,array<string,mixed>> $adaptivecatalog
     * @return array<int,string>
     */
    private function build_construction_allowed_tasks(array $runtimecatalog, array $adaptivecatalog): array {
        $tasks = [];

        foreach ($runtimecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $task = trim((string)($entry['task'] ?? ''));
            if ($task !== '') {
                $tasks[] = $task;
            }
        }

        if (!empty($tasks)) {
            return array_values(array_unique($tasks));
        }

        foreach ($adaptivecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $task = trim((string)($entry['task'] ?? ''));
            if ($task !== '') {
                $tasks[] = $task;
            }
        }

        return array_values(array_unique($tasks));
    }

    /**
     * Extract an explicitly selected task from selection-phase output.
     *
     * @param array<string,mixed> $phaseoutput
     * @return string
     */
    private function extract_selected_task_from_selection_phase_output(array $phaseoutput): string {
        return trim((string)($phaseoutput['selected_task'] ?? ''));
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
            'message' => get_string('ai_provider_error', 'bookingextension_agent'),
            'commands' => [],
            'selected_task' => '',
            'ambiguities' => [],
            'errors' => [$error],
            'issue_codes' => [$issuecode],
        ];
    }

    /**
     * Build a standardized selector-handoff error when construction lacks selected_task.
     *
     * @return array<string,mixed>
     */
    private function build_selector_handoff_error_result(): array {
        return [
            'response_type' => 'error',
            'message' => get_string('ai_provider_error', 'bookingextension_agent'),
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['CONTRACT_VIOLATION: selection phase did not provide a selected_task for construction.'],
            'issue_codes' => ['CONTRACT_SELECTION_TASK_MISSING'],
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
        return [
            'response_type' => 'error',
            'message' => get_string('ai_provider_error', 'bookingextension_agent'),
            'commands' => [],
            'ambiguities' => [],
            'errors' => [$errormessage],
            'issue_codes' => $issuecodes,
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
            'message' => get_string('ai_provider_error', 'bookingextension_agent'),
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['Provider returned empty content.'],
            'issue_codes' => [],
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
            'selected_task' => (string)($selectionstate['selected_task'] ?? ''),
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
You are an AI agent planner for the "{{bookingname}}" context.

ACTION-SPECIFIC GUIDANCE FOR ROUTING:
- Keep instructions compact and action-oriented. Do not over-explain.
- Use this strict decision order (first matching rule wins):
  1) already completed outcome in completed_commands/completed_observations
      -> response_type=sufficient, commands=[].
  2) explicit confirmation of an already pending action
      -> response_type=confirm_pending, commands=[].
  3) missing required input for the selected task
      -> response_type=clarification, commands=[].
  4) grounded mutating intent
      -> response_type=confirmation_request, commands non-empty.
  5) grounded read-only intent
      -> response_type=task_call, commands non-empty.
- Use only exact task names from the TASK CATALOG. Never invent aliases.
- If a matching task appears in UNAVAILABLE TASKS, mention that it exists but is currently not executable.
- Do not emit unavailable tasks in commands.
- Never re-emit an already completed action signature (same task + normalized input intent).
- Never use response_type=task_call for mutating intents.

TASK CONTRACT FIRST (highest priority):
- Follow task-level routing hints from the TASK CATALOG (intent, minimal_input, anchors, example_input, message_triggers).
- Keep global routing generic; do not hardcode special behavior for individual task names.

PROMPT;
        }

        if ($actionclass === explain_text::class) {
            return <<<'PROMPT'
You are an AI reasoning assistant for the "{{bookingname}}" context.

ACTION-SPECIFIC GUIDANCE:
- Base your answer on the latest user message, observations, and assistant state.
- Be concise, precise, and helpful.
- Do not propose extra tool calls if the available context already answers the request.
- Use only exact task names from the TASK CATALOG below.
- Never invent aliases or category names such as docs.search or documentation.query.
- If observations already contain sufficient information, MUST return
    response_type="sufficient" with commands=[] and NO message field.
- If information is still missing for a mutating action, ask one focused clarification question.
- For documented read-only questions, if observations are still insufficient,
    you MAY return one documentation task_call from the task catalog to retrieve more relevant information.
- If you need another documentation task_call, prefer grounded candidate paths or topic hints over guessed root doc_path values.
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
     * Build the state-based system prompt with compact task metadata embedded.
     *
     * @param  int    $cmid
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
        string $phase = self::PHASE_DISCOVERY,
        string $actionclass = generate_text::class,
        bool $hasobservations = false,
        ?array $adaptivecatalog = null,
        array $systemtaskcatalog = [],
        bool $isfirstassistantturn = false,
        bool $includetaskcatalog = false
    ): string {
        return $this->promptbundlebuilder->build_system_prompt(
            $cmid,
            $userid,
            $contextid,
            $phase,
            $actionclass,
            $hasobservations,
            $adaptivecatalog,
            $systemtaskcatalog,
            $isfirstassistantturn,
            $includetaskcatalog
        );
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
     * Keep only planner-relevant fields before runtime catalog prompt injection.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @return array<int,array<string,mixed>>
     */
    private function sanitize_runtime_catalog_for_prompt(array $catalog): array {
        $sanitized = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $task = trim((string)($entry['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $minimalinput = is_array($entry['minimal_input'] ?? null)
                ? (array)$entry['minimal_input']
                : $this->decode_catalog_json_array((string)($entry['minimal_input_json'] ?? '[]'));

            $exampleinputraw = is_array($entry['example_input'] ?? null)
                ? (array)$entry['example_input']
                : $this->decode_catalog_json_array((string)($entry['example_input_json'] ?? '[]'));

            $triggerraw = is_array($entry['message_triggers'] ?? null)
                ? (array)$entry['message_triggers']
                : $this->decode_catalog_json_array((string)($entry['message_triggers_json'] ?? '[]'));

            $row = [
                'task' => $task,
                'readonly' => !empty($entry['readonly']) && (string)$entry['readonly'] !== '0',
                'intent' => trim((string)($entry['intent'] ?? '')),
                'minimal_input' => $minimalinput,
                'description' => $this->compact_catalog_description((string)($entry['description'] ?? '')),
                'message_triggers' => $this->compact_catalog_message_triggers($triggerraw),
            ];

            $exampleinput = $this->compact_catalog_example_input($exampleinputraw);
            if (!empty($exampleinput) && $exampleinput !== $minimalinput) {
                $row['example_input'] = $exampleinput;
            }

            $sanitized[] = $row;
        }

        return $sanitized;
    }

    /**
     * Decode JSON array/object payload safely.
     *
     * @param string $json
     * @return array<int|string,mixed>
     */
    private function decode_catalog_json_array(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
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
     * @param  string      $phase Explicit planner phase (discovery/selection/parameter_construction).
     * @param  string      $runtimecontext Dynamic per-request context appended after static system prompt.
     * @param  string[]    $plannertracehistory Full planner trace history from thread metadata.
     * @param  bool        $autoconfirmmode Whether confirmation is already allowed for this thread.
     * @return string
     */
    private function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations = [],
        string $phase = self::PHASE_DISCOVERY,
        string $runtimecontext = '',
        array $plannertracehistory = [],
        bool $autoconfirmmode = false
    ): string {
        return $this->promptbundlebuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $phase,
            $runtimecontext,
            $plannertracehistory,
            $autoconfirmmode
        );
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
     * Build a small dynamic runtime context block for this request.
     *
     * Keeping per-request values out of the static [SYSTEM] block improves
     * prompt-prefix stability for upstream prompt caching.
     *
     * @param int $cmid
     * @param string $phase
     * @param bool $isfirstassistantturn
     * @param bool $hasobservations
     * @param array $taskcatalog
     * @return string
     */
    private function build_runtime_context_block(
        int $threadid,
        int $cmid,
        string $phase = self::PHASE_DISCOVERY,
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
        if ($phase === self::PHASE_DISCOVERY && $isfirstassistantturn && !$hasobservations) {
            $lines[] = '';
            $lines[] = 'NON-OPTIONAL LANGUAGE POLICY:';
            $lines[] = "- Include valid ISO 639-1 value 'user_lang'.";
        }

        $this->append_json_object_section($lines, 'TASK CATALOG:', $taskcatalog);

        if (!empty($unavailabletaskcatalog)) {
            $this->append_json_object_section($lines, 'UNAVAILABLE TASKS:', $unavailabletaskcatalog);
        }

        $privacy = new privacy_anonymizer($this->store);

        $completedcommands = $this->completedhistorysvc->extract_from_messages($messages);
        $completedcommands = $this->completedhistorysvc->merge_from_queue($threadid, $completedcommands);
        $completedcommands = (array)$privacy->anonymize_value_for_llm($threadid, $completedcommands);
        $this->append_json_list_section($lines, 'completed_commands:', $completedcommands);

        $observationledger = new execution_observation_ledger($this->store);
        $completedobservations = $observationledger->get_recent_for_runtime($threadid, 12);
        $completedobservations = (array)$privacy->anonymize_value_for_llm($threadid, $completedobservations);
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
     * Keep only catalog entries whose task family is in selected discovery families.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @param array<int,string> $selectedfamilies
     * @return array<int,array<string,mixed>>
     */
    private function filter_catalog_by_selected_families(array $catalog, array $selectedfamilies): array {
        if (empty($catalog) || empty($selectedfamilies)) {
            return $catalog;
        }

        $allow = [];
        foreach ($selectedfamilies as $family) {
            $normalized = task_family_contract::normalize_family((string)$family);
            if ($normalized !== task_family_contract::DEFAULT_FAMILY) {
                $allow[$normalized] = true;
            }
        }

        if (empty($allow)) {
            return [];
        }

        $filtered = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $taskname = trim((string)($entry['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $family = task_family_contract::from_task_name($taskname);
            if (!isset($allow[$family])) {
                continue;
            }

            $filtered[] = $entry;
        }

        return array_values($filtered);
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

    /**
     * Resolve a deterministic namespace hint from prompt contracts.
     *
     * @param array<int,array<string,mixed>> $promptcontracts
     * @return string
     */
    private function resolve_namespace_hint_from_prompt_contracts(array $promptcontracts): string {
        $counts = [];
        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $namespace = trim((string)($contract['namespace'] ?? ''));
            if ($namespace === '') {
                continue;
            }

            $counts[$namespace] = (int)($counts[$namespace] ?? 0) + 1;
        }

        if (empty($counts)) {
            return '';
        }

        arsort($counts, SORT_NUMERIC);
        return (string)array_key_first($counts);
    }

    /**
     * Augment a primary planner catalog with a small number of recent executable tasks.
     *
     * @param array<int,array<string,mixed>> $primarycatalog
     * @param array<int,string> $recenttaskhistory
     * @param array<int,array<string,mixed>> $fallbackcatalog
     * @param array<string,array<string,mixed>> $evaluations
     * @param int $maxadditions
     * @return array<int,array<string,mixed>>
     */
    private function augment_catalog_with_recent_executable_tasks(
        array $primarycatalog,
        array $recenttaskhistory,
        array $fallbackcatalog,
        array $evaluations,
        int $maxadditions = 1
    ): array {
        if ($maxadditions <= 0 || empty($recenttaskhistory) || empty($fallbackcatalog)) {
            return $primarycatalog;
        }

        $existing = [];
        foreach ($primarycatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $taskname = trim((string)($entry['task'] ?? ''));
            if ($taskname !== '') {
                $existing[$taskname] = true;
            }
        }

        $fallbackindex = [];
        foreach ($fallbackcatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $taskname = trim((string)($entry['task'] ?? ''));
            if ($taskname !== '') {
                $fallbackindex[$taskname] = $entry;
            }
        }

        $result = $primarycatalog;
        $added = 0;
        foreach ($recenttaskhistory as $taskname) {
            $taskname = trim((string)$taskname);
            if ($taskname === '' || isset($existing[$taskname])) {
                continue;
            }

            $executablestate = trim((string)($evaluations[$taskname]['executable_state'] ?? ''));
            if ($executablestate === 'deny') {
                continue;
            }

            if (!isset($fallbackindex[$taskname])) {
                continue;
            }

            $result[] = $fallbackindex[$taskname];
            $existing[$taskname] = true;
            $added++;
            if ($added >= $maxadditions) {
                break;
            }
        }

        return $result;
    }
}
