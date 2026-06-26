# Audit: bookingextension_agent — Dead Code, Cruft & Flowchart Deviations

**Date:** 2026-06-26 · **Type:** read-only audit (no code changes) · **Scope:** `classes/` (250 files, ~59k LOC) + `tests/` (114 files) vs `docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd` (681 lines).

**Method:** four parallel sub-audits (planner core · queue/preflight pipeline · skills/periphery · flowchart). Every "dead" claim was grep-verified across the whole `classes/` + `tests/` tree; `.claude/worktrees/*` (stale `local\wbagent\` copies) excluded as noise. All behavioural-risk flowchart findings were independently re-verified before inclusion. Framework/dynamically-invoked methods (skill `execute()`/`preflight()`, external API `execute*`, task `execute()`, event observers, privacy provider, hook callbacks, DI factories, usort/array_map callbacks, reflection targets) are NOT treated as dead.

Per the flowchart policy (`feedback_flowchart_policy`): flowchart deviations are **reported only** — reconcile with Georg before any code or diagram edit.

---

## 0. Executive summary

| Category | Count | Highest-value |
|---|---|---|
| Cruft in autoload tree | 2 `.py` + `__pycache__/` (5 `.pyc`) | committed Python in `classes/` |
| Whole dead classes/interfaces | 3 | `mutation_result_dto`, `benchmark_seed_data`, dormant `skill_input_normalizer_interface` chain |
| Dead methods (zero callers) | ~30 | `agent_state` 4 cache methods, `assistant_state_guidance` 5 (2 pub + 3 transitive), `skill_registry` 5 |
| Dead constants | ~14 | `WB_ACTION_*` in aiready, version-issue consts, `CONFIRM_PREVIEW_*` key |
| Unused imports | ~13 | 6× `target_selector`, `core\context` in agent_runtime, 3× aiactions |
| Dead/redundant branches | 4 | `discovery_phase_service:665` duplicate `isset`, `MAX_EXPONENT` clamp |
| Behavioural flowchart deviations | 3 | **R2 blocked-TTL 900 vs documented 300**, R3 external gate is no-op, timeout-code split |
| Doc-lag / fabricated-name deviations | ~25 | orchestrator-split lag + many node method names that don't exist |

The codebase is largely clean: ~40 pipeline files and most of `catalog/`, `discovery/`, `preflight/` had **zero** findings, and no unused `use` imports were found in the entire pipeline tier. Dead code clusters in (a) per-run caches that were never wired (`agent_state`, `assistant_state_guidance`), (b) introspection/docs helpers built ahead of consumers, (c) interface methods declared for future contracts.

---

## 1. Cruft in the `classes/` autoload tree

```
[high] classes/local/wizard/wunderbyte_shop_endpoint.py      — committed Python in PHP PSR autoload tree; 0 PHP refs (only docs)
[high] classes/local/wizard/wunderbyte_trial_endpoint.py     — committed Python in PHP PSR autoload tree; 0 PHP refs (only docs)
[high] classes/local/wizard/__pycache__/  (5 .pyc files)     — Python bytecode cache; NOT git-tracked (local artifact on disk)
[low ] classes/local/wizard/prompts/initial_system_prompt.md — no PHP loader found (grep) → likely unused; verify before removal
```
`config/command_schema.json` is **NOT** cruft — loaded at `preflight_schema_validator.php:166`.
The `.py` endpoints are remote-side reference implementations; their presence under `classes/local/wizard/` pollutes the autoload namespace (they can never be loaded as Moodle classes).

---

## 2. Whole dead classes / interfaces

```
[high] dto/mutation_result_dto.php:38            — WHOLE CLASS DEAD — never imported/constructed/type-hinted/returned anywhere.
                                                    Drags ctor + factories success()/error()/skipped()/dry_run_ok() + to_array() + 5 readonly props.
[high] benchmark/benchmark_seed_data.php:31      — WHOLE CLASS DEAD — 0 PHP refs (docs prose only). Drags 4 public statics + ~16 constants.
[high] interfaces/skill_input_normalizer_interface.php:26 — INTERFACE, ZERO IMPLEMENTERS. The provider hook IS wired
        (skill_registry.php:248 instanceof skill_input_normalizer_provider_interface; :252 calls get_skill_input_normalizer()),
        but NOTHING implements either the normalizer or its provider interface → the whole normalizer chain is dormant.
        DESIGN DECISION needed: planned extension point vs. delete (do not blind-remove).
```

---

## 3. Dead methods (zero callers tree-wide)

### Per-run caches never wired (high value — clean kills)
```
[high] agent_state.php:219/230/240/251 — get/set_selection_skill_cache + get/set_construction_params_cache
        — only get/set_discovery_family_cache are actually used; the selection + construction per-run caches are dead.
[high] assistant_state_guidance_service.php:51  build_assistant_state_blocks()   — 0 callers
[high] assistant_state_guidance_service.php:83  build_contextual_guidance()       — 0 callers
[high] assistant_state_guidance_service.php:153 summarize_structured_state()      — transitively dead (only caller is :51)
[high] assistant_state_guidance_service.php:193 extract_result_facts()            — transitively dead (only caller is :153)
[high] assistant_state_guidance_service.php:249 matches_contextual_pack()         — transitively dead (only caller is :83)
        — corroborates memory project_agent_guidance_injection: contextual_prompt_packs never reach the live prompt.
        Only normalize_nonempty_string_list() (:127) is live.
```

### Superseded entrypoints / helpers
```
[high] agent_runtime.php:178            run()            — single-step entrypoint; only run_loop() is used (ai_send_message:267, confirm_run_service:318). Not in any interface.
[high] conversation_store.php:823       allow_confirmation_for_session()  — 0 code callers (docs/flowchart only); not in interface.
[high] conversation_store.php:887       clear_confirmation_allowance()    — 0 code callers (docs only); not in interface.
[med ] conversation_store.php:575       get_latest_run()                  — only self + interface decl (:151); the interface method itself is uncalled.
[high] ai_error_classifier.php:130      classify_from_db()                — documented DB-fallback path never wired in.
[high] skill_registry.php:421/438/468   get_all_schemas / get_all_schemas_for_context / explain_skill_schema_for_context — 0 callers.
[high] skill_registry.php:649/660       get_message_triggers / get_trigger_id_to_skill_name_map — 0 callers; bodies are `return []` stubs (dead by design).
[high] skill_registry_factory.php:65    get_last_build_warning()          — backing field written, never read.
[high] skill_contract_validator.php:301 get_deny_reason_priority()        — 0 callers (static, not interface).
[high] skill_discovery.php:89           get_trigger_provider_instances()  — test-only (integration_agent_framework_test:546).
[high] diagnostics/diagnostic_link_builder.php:99/109  cohorts() / course_groups()  — 0 callers (14 sibling link methods have callers).
[high] benchmark/benchmark_scenario_registry.php:82    get_set_names()    — 0 callers (runner uses get_scenarios()).
[high] queue/queue_manager.php:352      has_running_item()                — 0 callers (running-slot check uses try_mark_running()/can_pickup_now()).
[high] queue/queue_manager.php:737      build_input_signature()           — 0 callers (live one is private build_input_signature_details()).
[high] services/lookup/docs_embeddings_csv_repository.php:127  delete_corpus()        — 0 callers anywhere.
[high] services/lookup/docs_embeddings_index_service.php:267   get_registered_corpora() — 0 callers (callers use docs_corpus_registry()->list() directly).
[high] services/lookup/docs_embeddings_index_service.php:351   extract_title()        — 0 callers (logic duplicated inline at markdown_chunker::extract_h1).
[high] services/security/authorization_service.php:165 require_valid_context_for_levels() — 0 callers; docblock claims call sites that don't exist.
[high] services/security/authorization_service.php:184 require_capability_at()         — only the interface decl (agent_authorization_service:95); planned runtime-context-switch hook, unwired. Removal touches interface.
[high] services/agent_access_service.php:84  reset_cache()                  — 0 callers (plausibly test-teardown, but uncalled).
[high] core/skills/core_skill_base.php:216   resolve_groupid()             — 0 callers (siblings resolve_userid/resolve_courseid ARE used).
[high] core/skills/core_skill_base.php:250   can_access_user()             — 0 callers (diagnose skills gate inline).
```

### Over-exposed public — used only internally (reduce visibility, do NOT delete)
```
[high] queue/queue_manager.php:309 save_queue_items() (7 self-calls)
[high] queue/queue_transition_service.php:234 to_blocked_confirmation() (4 self-calls; siblings are external)
[high] skill_executability_evaluator.php:127 evaluate_all_skills() (only self caller :148)
[high] llm_debug_logger.php:58 log_exchange() (only self caller :111; real sites use log_exchange_always())
[high] message_trigger_registry.php:93/125 get_available_triggers / get_available_trigger_ids (internal only)
[high] embeddings/embeddings_catalog_builder_service.php:106/122 compute_content_hash / to_embedding_input (internal only)
[med ] planner_catalog_service.php:158/226/313/335/361 message_matches_intent_triggers / decode_catalog_json_array / compact_catalog_description / compact_catalog_example_input / compact_catalog_message_triggers (internal only — leftover public from the orchestrator split)
[med ] orchestrator_routing_service.php:286 route_policy_family (internal only)
[med ] orchestrator_prompt_profile_service.php:98 get_history_limit_for_phase (internal + own unit test)
[med ] shared_json_payload_extractor.php:71 extract_balanced_json_objects (only self caller :56)
[low ] language_policy_service.php:47 normalize_iso_language; user_memory_service.php:57 valid_scopes; privacy_anonymizer.php:139 get_mode
```

### Test-only public API (no production caller — keep only if tests are the contract)
```
[high] dto/agent_context.php:126 display_name()
[high] get_runtime_feature_flags_snapshot() — 4-way duplicate on orchestrator:98 / agent_runtime:77 / orchestrator_routing_service:54 (+1); each referenced ONLY by runtime_feature_flags_test.php → intentional parallel surface, asserted-equal by one test.
[med ] embeddings/embeddings_retrieval_service.php:137 build_planner_catalog_subset() (only integration test) — possible orphaned planner API.
[med ] dto/target_selector.php:66 create() (prod uses for_course()); services/lookup docs_corpus_registry:120 is_known(), docs_embeddings_csv_repository:110 read_rows_for_corpus(), docs_embeddings_readiness_service:158 get_corpus_index_summary()
[med ] language_policy_service.php:85 fallback_string_id_for_response_type()
[low ] queue_status_policy.php:115/124 actionable_mutating_statuses / pickup_ready_statuses (prod uses the is_* singular variants); context_target_unresolved_exception:61 get_resolution(); context_target_resolution:137 candidates()
```

---

## 4. Dead constants
```
[high] aiready.php:41/44 WB_ACTION_PLANNER_DECIDE / WB_ACTION_GENERATE_AGENT_REPLY — no self:: read in this class.
[high] interpreter.php:64 CURRENT_USER_TOKEN ('__current_user__') — literal never read.
[high] skill_contract_validator.php:63/66 ISSUE_SKILL_VERSION_UNSUPPORTED / ISSUE_SKILL_VERSION_DEPRECATED — 0 reads.
[high] message_trigger_registry.php:38 UNKNOWN_RESPONSE_TYPE — 0 external reads.
[high] confirm_run_service.php:46 CONFIRM_PREVIEW_OPTION_IDS_METADATA_KEY — declared; preview actually uses '_confirm_previews' (:698).
[high] agent_decision_service.php:86 RESPONSE_TYPE_ERROR — 0 reads; 'error' hardcoded at :1320.
[high] diagnose_enrolment_skill.php:48 DETAILED_METHODS — 0 reads (branches on literals).
[high] config/runtime_feature_flags.php:78 ENFORCEMENT_MODE_WARN — 0 reads (only OBSERVE/ENFORCE used).
[med ] config/runtime_feature_flags.php:44 SYNCHRONIZER_STRICT_CONTRACT — only tests + KNOWN_FLAGS loop; no production is_enabled() branch.
[med ] preflight_contract_validator.php:35/38 ISSUE_SKILL_VERSION_* — re-exported aliases, never referenced.
[med ] skill_contract_validator.php:60 DENY_SKILL_VERSION_UNSUPPORTED — single live consumer (list_skills_skill:416) + the dead priority method.
[low ] skill_version_policy.php:30 STATUS_SUPPORTED — written into return payload, never compared (part of documented contract — risky to remove).
[low ] agent_decision_service.php:83 RESPONSE_TYPE_CLARIFICATION — used once (:233); other sites use the literal.
```

---

## 5. Unused `use` imports
```
[high] agent_runtime.php:29                              core\context              — never resolves a context object.
[high] aiready.php:29                                    core_ai\aiactions\generate_text — only a comment ref.
[high] orchestrator_prompt_profile_service.php:21/22/23  explain_text/generate_text/summarise_text — 0 body refs.
[high] course/skills/{add_activity,add_quiz,analyze_course_structure,update_activity,update_quiz}_skill.php + question/skills/generate_questions_skill.php (×6, ~line 23) — use dto\target_selector — leftover from course_targeted_skill pattern (the get_target_selector() call is a trait method, not the class symbol).
[high] wizard/skills/list_skills_skill.php:20/22         context_module / interfaces\skill_interface — class implements skill_trigger_provider_interface, not these.
[high] external/ai_get_doc_content.php:29                context_module — only used by the dead $cmid local (see §6).
```
No unused imports were found anywhere in the pipeline tier (queue/preflight/risk/decision/execution/telemetry/messaging/security/catalog/discovery/embeddings/llm/lookup).

---

## 6. Dead / unreachable / redundant branches & locals
```
[high] services/discovery_phase_service.php:665 — REDUNDANT CONDITION — `isset($cmd['skill']) || isset($cmd['skill'])`
        (byte-identical operands; the OR collapses to a single isset). NOTE: this was moved VERBATIM from the
        orchestrator's extract_recent_skill_names_from_messages during the discovery-seam extraction — it is a
        PRE-EXISTING redundancy faithfully preserved, not introduced by the refactor. The second operand was almost
        certainly meant to test a DIFFERENT key (e.g. 'skillname'/'name'); functionally harmless today, but signals a
        likely-intended fallback that never fires. Worth a one-line fix once the intended key is confirmed.
[high] message_trigger_registry.php:111 — DEAD BRANCH — 'examples' ternary; CORE_TRIGGERS (55-77) never defines 'examples',
        so isset() is always false → true-branch dead, result always [] (and inside the dead get_available_triggers()).
[med ] preflight_execution_gate.php:38,109 — DEAD CLAMP — MAX_EXPONENT(=30) in min($retrycount, MAX_EXPONENT); :97 returns
        early when retrycount >= MAX_RETRIES(=4), so retrycount is always 0..3 → the clamp can never bind. Defensive, not a bug.
[low ] orchestrator.php:471 — REDUNDANT IF — resolve_synchronizer_action_class() generate_text branch (471-477) returns the
        same array shape as the unconditional fallback (479-483).
```
Dead locals / unused params (cleanup-only, low impact): `interpreter.php:1084 $seencommandsigs` (reassigned before read) and `:1187 $lang` param (never read); `planner_phase_service.php:130 $contextid` + `:221 $phaseoutput=[]` (both overwritten before read); `preflight_pipeline.php:101 $cmid` (assigned, never read); `external/ai_get_doc_content.php:85 $cmid` (copy-paste remnant); `privacy/provider.php:207 global $DB` in export_user_data() (never used); `llm_debug_logger.php:39 global $CFG` in is_enabled(); `confirm_run_service.php:212 $retrydecision` initial; several diagnose `build_result()` params ($links/$actinguserid) unread. Two orphan/duplicate docblocks: `search_users_skill.php:301-305`, `list_skills_skill.php:266-280`.

---

## 7. Residual duplication (the original audit's §1.1/§1.3 targets)

`risk_class_resolver` is now the single source of truth — the "risk-class resolved 6×" finding is **resolved** (one inline miss remains: `agent_decision_service.php:1100-1102` re-implements `is_valid($x)?$x:R3` instead of calling `normalize()`).

The retry/backoff duplication (§1.3) is **partly remaining**:
```
[med] confirm_run_service.php:811 build_retry_decision()  ↔  queue_transition_service.php:234 to_retry_waiting()
      — a single execution-failure path is gated by TWO policy engines: build_retry_decision() uses
        preflight_execution_gate::evaluate(); to_retry_waiting() independently re-gates via retry_policy_service
        (resolve_retry_hint_category + is_retryable_category + evaluate_retry_layer_guard) and can flip retry_waiting→failed.
[low] backoff/next_retry_at formula duplicated: queue_transition_service.php:133-135 and confirm_run_service.php:870-872
      both compute `retry_after_ms / backoff_ms / next_retry_at = time()+ceil(ms/1000)` by hand
      (to_retry_waiting() itself does not compute backoff — it passes caller $meta through). Candidate for one shared helper.
```

---

## 8. FLOWCHART DEVIATIONS (`AGENT_IMPLEMENTATION_FLOWCHART.mmd`)

Numeric constants almost all match exactly (backoff 500/200/4/4000, MAX_LOOP_STEPS=6, HISTORY_TAIL_LIMIT=14, signal weights 0.35/0.20/0.10/0.20, ranker 0.7/0.3, retry max-layers 2, session-allow TTL 900s, USERMEM limits 15/500/4096). Deviations are mostly orchestrator-split lag + fabricated node method names + a few behavioural gaps.

### 8A. Behavioural risk (verify with Georg — re-verified independently)
```
[high][code→flowchart] Q_BLOCKED — R2 blocked-confirmation TTL is 900s on the TRANSITION path, not the documented 300s.
        resolve_blocked_ttl_seconds() returns 300 for R2 (queue_manager.php:861), but TTL is seeded with the risk class
        ONLY at enqueue (:201). update_status() recomputes via resolve_blocked_expires_at($status,$now) with NO risk class
        (:243) → normalize('') is not R2 → DEFAULT_BLOCKED_TTL_SECONDS=900 (:858-869). R2 items routed to blocked via
        queue_transition_service::to_blocked_confirmation() (→ update_status) therefore get 900s. The documented 300s only
        applies to items BORN blocked at enqueue. Impact: R2 explicit-confirmation window can be 3× longer than documented.
[high][code→flowchart] PF_L3_EXT — the R3 external_dependency_check ships as a NO-OP stub. Flowchart presents an active gate
        (webhook reachable / payment provider ready / hard_block when down); the default
        noop_external_dependency_checker::check() always returns ok() (noop_external_dependency_checker.php:32). Injectable,
        but no real implementer is wired → the documented R3 external gate is currently inert.
[med ][code→flowchart] Q_FAIL_TTL ↔ LG_MATRIX — timeout-code split. Queue emits 'BLOCKED_CONFIRMATION_TIMEOUT'
        (queue_manager.php:602); the finalization template_only matrix keys on 'BLOCKED_TIMEOUT'
        (finalization_classifier.php:63 / finalization_template_service.php:33). agent_decision_service.php:182 references
        the long form, so a partial bridge may exist — verify whether a TTL-failed item actually reaches the BLOCKED_TIMEOUT
        template rule, or falls through to a generic error template.
```

### 8B. Orchestrator-split doc lag (predicted — the flowchart still draws the pre-split structure)
```
[high][flowchart→code] node ORC — process() (orchestrator.php:226) is now a thin coordinator delegating to
        discovery_phase_service::run, planner_phase_service::run_selection/run_construction, planner_result_composer::compose.
        The flowchart still draws all internals (DISC_*, FSIG, FRANK, SPLLM, SINT, CPLLM, CINT, PCOMP) inside one ORC node.
[high] UM_INJECT/build_runtime_context_block — now delegates to runtime_context_block_builder::build() (orchestrator.php:777).
[high] AZ4 — availability enforcement moved to provider_status_service::get_status(); orchestrator method is a delegator.
[high] PCOMP→CS15 — planner_result_composer::compose() is PURE (no store); persistence is in
        message_persistence_service::persist_assistant_message(), not the PCOMP node.
```

### 8C. Fabricated / wrong method names in nodes (doc-only, but mislead reviewers)
```
[high] PPB / SYNC_PPB — `::build()` does not exist; real methods are build_system_prompt() + build_prompt(). No synchronizer_profile.md file exists (prompt assembled inline).
[high] SYNC_RUN / SYNC_TEMPLATE — synchronize()/synchronize_template_only() and a `synchronizer` class do not exist. Real:
        agent_runtime::apply_synchronizer_message_polish() (:439) + apply_template_only_finalization() (:413), routed by
        apply_finalization_strategy() (:382); LLM via synchronizer_routing_service::call_synchronizer_step()→orchestrator::process_synchronizer().
[high] SPLLM/CPLLM — llm_call_service::invoke(source=planner_selection, action_class=selector_pick_skill) fabricated. Real:
        invoke_for_context(); source is a compact debug token (orc|p=sel|...) from orchestrator_routing_service::build_debug_source();
        none of planner_selection/planner_construction/selector_pick_skill/constructor_build_params exist.
[high] D_PROMOTE — resolve_command_risk_class() does not exist; real: risk_class_resolver::resolve_for_command() (unknown→R3 default IS present).
[high] PF_L1 — preflight_version_validator::validate_version() does not exist; real: validate(). The L1 facade preflight_contract_validator::validate() is omitted from the chart.
[high] TCV — verify_risk_class_declaration() does not exist; checks are inline in validate_skill_metadata() (skill_contract_validator.php:193-204).
[high] EMB_QUERY — no embedding_query_builder class; query assembled inline in discovery_phase_service::run() (:211-248). Behaviour matches.
[high] D_SAFE_CTX — no safety_context DTO; logic is inline $result mutation in agent_decision_service::process().
```

### 8D. Capability / namespace string deviations
```
[high][flowchart→code] CAPABILITY PREFIX — chart writes `agent:*`; code uses `bookingextension/agent:*` everywhere
        (db/access.php:29 useaiinstructions [editingteacher+manager], :44 seemagicwand [manager], :64 ignoreaiavailability).
        Per-skill cap = `bookingextension/agent:skill_<name>` (skill_contract_validator::build_skill_capability_name :117-131), not `agent:skill_<name>`.
[high][code→flowchart] CORESET/FSIG/LG_PLAN — core family namespace is `wizard.*`, not `core.*`. Core bonus triggers on
        strpos($family,'wizard.')===0 (family_signal_ranker.php:77); core skills are wizard.explain_docs/list_skills/remember.
[med ][code→flowchart] EXC_EVAL — deny-reason list wrong both ways: chart lists `skill_version_unsupported` which
        evaluate_skill() NEVER returns (skill_executability_evaluator.php:61-118), and OMITS `requires_pro` (DENY_REQUIRES_PRO :89,
        handled at executor.php:168).
[high][code→flowchart] AIER — ai_error_classifier emits issue codes TRIAL_TOKEN_INVALID / AI_PROVIDER_QUOTA_EXCEEDED
        (:58-116), NOT the four error-CLASS strings drawn (provider_timeout/transient_io/auth_failed/quota_exceeded — those
        originate in preflight_error_classifier.php:32,47,50 and orchestrator.php:406-408).
```

### 8E. Finalization matrix (LG_MATRIX) — code carries MORE codes than documented (no phantom codes)
Classifier is `services/finalization_classifier.php` (chart hinted `services/messaging/` — wrong dir). Rules 1/2/6/7/8 match. Extra (undocumented) entries:
```
[high] rule 3 (direct_final) adds CONTRACT_PHASE_RESPONSE_TYPE / _COMMANDS_NOT_ALLOWED / _SINGLE_COMMAND_REQUIRED / _SKILL_NOT_ALLOWED (:54-57).
[high] rule 4 (template_only) adds CONTRACT_SELECTION_SKILL_MISSING + the 7 SYNC_* consistency-gate rejections (:68-76).
[high] rule 5 (template_only error classes) adds provider_error + internal_status (:88,90).
```

### 8F. response_type enum conflation
```
[high] node RESP_TYPE lists execution_result + budget_exceeded as parsed response_types. Reality: interpreter
        ALLOWED_RESPONSE_TYPES = clarification/confirmation_request/skill_call/error/confirm_pending/sufficient
        (interpreter.php:54-61). `execution_result` is a runtime/decision-only state (agent_runtime.php:53-61);
        `budget_exceeded` is NOT a response_type — build_budget_exceeded_result() sets response_type=error + issue_code
        BUDGET_EXCEEDED (agent_runtime.php:756,765). The node conflates the parser enum with runtime states.
```

### 8G. Minor signature / attribution deviations (doc-only)
```
[high] Q_ENQUEUE — label has a contextid param; enqueue_command() has none (derived via resolve_thread_contextid()).
[high] ASM — ai_send_message::execute() has a 5th param `pagecontext` not shown.
[high] AZ_READY — "EVERY WS calls check_use_readiness first" overstated: set_debug_mode.php:68 gates on moodle/site:config only (other 12 endpoints do call it).
[high] SCONTRACT — irreversibility_notice/affected_scope_summary are NOT in synchronizer_output_contract::merge(); they live in finalization_classifier::requires_*() and aren't invoked in the live merge path.
[med ] D_PREFLIGHT — per-risk layer gating attributed to handle_preflight() but enforced inside preflight_pipeline::run(); PF_L3_EXT runs per-command inside the loop.
[med ] ACR — allow_session edge labels allow_confirmation_for_session; confirm_run_service calls allow_confirmation_for_thread() (:117).
```

### 8H. Confirmed accurate (verified, no action — for confidence)
Preflight backoff constants; MAX_LOOP_STEPS/HISTORY_TAIL_LIMIT; family weights & ranker; retry max-layers 2; session-allow TTL 900s; risk gating R1/R2/R3 + R3-no-retry→to_failed('R3_NO_RETRY'); USERMEM (table/columns/limits/risk classes/per-channel injection/privacy @ CONTEXT_USER); CSTORE 16 methods + CONFIRMATION_SESSION_ALLOWLIST_TTL=900; **confirm_run_service refactor** (uses agent_runtime::create_default :298; exception catch collapsed to terminal-only EXECUTION_EXCEPTION_FATAL with no retry edge :422-470; is_confirmation_allowed_for_thread at entry); LANG priority; MTRIG; ANON; D_TARGET_NOTE (build_operating_context_note/describe_target_context + lang key agent_confirm_target_course); preflight L2 codes; PRV2 status enum + guard_token-not-on-DTO; Gate-2 native_capability_guard backstop before execute(); guard token hash_equals over sha256(skill:opctx:input); can_pickup_now both conditions; try_mark_running atomic.

---

## 9. Verified NOT dead (false-positive guards recorded)
- All skill classes (auto-discovered via skill_registry; no `new` is normal). All 15 benchmark scenarios (registered in benchmark_scenario_registry::SETS). All 4 summarizer contributors (wired in skill_provider.php:127-130). All 13 external classes (db/services.php). Tasks (db/tasks.php) + both adhoc embeddings tasks (queued). `trial_consent_given` event (request_trial_key.php:104). Privacy provider, benchmark_db_writer/metrics_calculator/result_collector/envkey_manager (CLI/admin/CI).
- `agent_access_service::runs_on_wunderbyte_llm` — used at `mod/booking/settings.php:173` (NOT dead despite no agent-tree caller).
- `compare_skill_classes` (usort callback), `patch_provider_for_env` (array_map callback), event `init()` (Moodle override) — false positives, NOT dead.
- `embeddings_retrieval_service::search_top_k` vs `_streaming` — intentional twins.

---

## 10. Recommended priorities (for a future cleanup pass — NOT done here)

**Tier 1 — clean kills (high confidence, zero callers, not framework/interface):**
`__pycache__/` + 2 `.py` files; `mutation_result_dto.php` + `benchmark_seed_data.php` (whole files); the 4 `agent_state` cache methods; the 5 `assistant_state_guidance` methods; `agent_runtime::run()`; `skill_registry` 5 dead methods; the ~13 unused imports; the listed dead constants.

**Tier 2 — coordinate (touches interfaces / shared):**
`authorization_service::require_capability_at` + `require_valid_context_for_levels` (interface decl); `conversation_store::get_latest_run` (interface); `booking_issue_code_provider` 4 interface overrides; over-exposed publics → narrow visibility.

**Tier 3 — needs Georg/design decision:**
dormant `skill_input_normalizer_interface` chain (planned extension vs delete); the §7 two-engine retry duplication; **all §8 flowchart deviations** (especially 8A behavioural: R2-TTL 900-vs-300, PF_L3_EXT no-op, timeout-code split) — decide per item whether to fix code or update the diagram.

**Tier 4 — one-line correctness:**
`discovery_phase_service.php:665` duplicate `isset` — confirm the intended second key (pre-existing, moved verbatim during the discovery seam).
