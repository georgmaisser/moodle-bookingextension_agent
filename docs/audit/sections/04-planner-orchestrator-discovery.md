# Audit Section 04 — Planner Orchestrator & Discovery

**Scope:** `classes/local/wizard/orchestrator.php`, `services/discovery/*` (9 files), `services/discovery_phase_service.php`, `services/planner_phase_service.php`, `services/planner_phase_prompt_trait.php`, `services/planner_catalog_service.php`, `services/planner_result_composer.php`, `services/orchestrator_prompt_profile_service.php`, `services/orchestrator_routing_service.php`, `services/phase_prompt_bundle_builder.php`, `services/phase_trace_normalizer.php`, `message_trigger_registry.php`  ·  **Files audited:** 20  ·  **Methods audited:** ~95
**Arch chapter(s):** docs/architecture/05-planner-orchestrator.md + 06-discovery-families-embeddings.md  ·  **Flowchart nodes:** ORCH, FSIG, FRANK, DISC_A/B/C, FREG, EMB_QUERY
**Auditor verdict:** ⚠️ issues (no blocker)

The strict two-call planner split, the family signal formula (base .20 / core .10 / namespace_hint .35 / recency .20), the FRANK weighted blend (0.7 signal / 0.3 semantic, clamped), the bounded staged expansion (12/24/36 budgets, 0.60/0.45 confidence), the soft context prior (`is_hard_filter=false`), and the "no full skill dump / one sanctioned force-include (`wizard.search_skills`)" rule all hold **literally in the code** and match the flowchart. No SQL surface, no IDOR surface (the phase services take pre-validated `threadid`/`contextid`/`userid` from the runtime; ownership is enforced upstream). The defects found are doc-lag (the `core.`→`wizard.` family rename and stale orchestrator line numbers in ch.05/06), one genuine engine→domain leak in `message_trigger_registry`, and minor dead code.

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | No `$DB`/SQL in scope; no IDOR (ids pre-validated upstream, `context::instance_by_id(...,MUST_EXIST)`); prompt-injection surface in `build_prompt` is inherent to text-concatenation prompting but cannot escalate — every selected skill passes Gate 1 (`authorization_service`) + Gate 2 (`native_capability_guard`) in the executor, and the planner performs no mutation (04-F05, INFO). |
| D2 Moodle API      | pass | `get_config`/`get_string`, `core_ai\manager`, `context`, `core_text` used correctly; correct namespaces vs PSR-4 paths; headers present. phpcs could not be run in this env (no `vendor/bin/phpcs`) — style judged by inspection only. `orchestrator.php`, `phase_prompt_bundle_builder.php`, `planner_result_composer.php`, `message_trigger_registry.php` lack `declare(strict_types=1)` while the newer split services have it (pre-existing, consistent with documented design; INFO). |
| D3 Structure       | issues | One engine→domain leak (04-F02). Three zero-caller methods (04-F03/F04, LOW). Otherwise clean DI seams, thin orchestrator delegators, shared trait removes the build-prompt duplication. |
| D4 Duplication     | issues | `build_provider_error_result` / `build_empty_provider_result` are byte-identical in `orchestrator.php` and `planner_phase_service.php` (04-F06, LOW); the error-class string map is also duplicated in `ai_error_classifier` consumers. |
| D5 Flowchart       | pass | ORCH (strict discovery→selection→construction, 2 chat calls), FSIG, FRANK, DISC_A/B/C all match the .mmd nodes. Conditional construction confirmed. No behavioural deviation. |
| D6 Docs coverage   | issues | ch.06 §1/§2/§5 still say `core.general` / "`core.*`" / "family starts with `core.`" but the code hardcodes `wizard.general` and bonuses the `wizard.` prefix (04-F01, MEDIUM). ch.05 §2/§9 cite stale orchestrator line numbers (1075/1309/489/707/492) that no longer exist after the orchestrator split (04-F07, LOW). |

## B. Findings

### [04-F01] 🟡 MEDIUM · D6 Docs coverage · docs/architecture/06-discovery-families-embeddings.md §1,§2,§5
**What:** The discovery chapter describes the always-on core family as `core.general` / `core.*` and the signal "core bonus" as "family starts with `core.`", but the code uses the `wizard.` namespace throughout.
**Evidence:** `skill_family_contract::DEFAULT_FAMILY = 'wizard.general'` (line 33); `core_family_set::resolve()` seeds `['wizard.general']` and filters `strpos($family, 'wizard.') !== 0` (lines 39, 47); `family_signal_ranker::score_families()` adds the core bonus on `strpos($family, 'wizard.') === 0` (line 77). Chapter 06 §2 states "`core.general` is hardcoded, plus any `core.*` families"; §5 table row "core bonus … family starts with `core.`"; §1 "default `core.general`".
**Impact:** A maintainer reading the chapter would look for `core.*` families and miss that the real namespace is `wizard.*`; any new core/utility skill declared as `core.foo` would silently get **no** core bonus and would not join the always-on baseline.
**Compensating control:** The flowchart `FSIG`/`DISC_A` nodes use generic "core families" wording that is not contradicted; only the prose chapter names the wrong literal.
**Recommendation:** Update ch.06 §1/§2/§5 to `wizard.general` / `wizard.*` and "family starts with `wizard.`".

### [04-F02] 🟡 MEDIUM · D3 Structure (engine→domain leak) · classes/local/wizard/message_trigger_registry.php:71
**What:** The engine's core trigger catalog carries a `mod_booking`-specific trigger, violating the "engine carries no domain heuristics" boundary.
**Evidence:** `CORE_TRIGGERS` entry `core.is_preview_request` has description `'Latest user message asks to preview/show the latest worked booking option.'` (line 71). `message_trigger_registry` lives in the engine namespace `bookingextension_agent\local\wizard` and is consumed engine-side by `agent_runtime` and `interpreter`.
**Impact:** A non-booking host (the planned `local_wizard` extraction, see `wbagent → local_wizard` memo) inherits a booking-flavoured preview trigger; the term "booking option" reaches the planner prompt for every host. Low functional risk (it is one trigger description string), but it is exactly the engine→domain coupling the architecture forbids.
**Compensating control:** None in code; the leak is descriptive only and does not alter routing for non-booking skills.
**Recommendation:** Generalise the description to "the latest worked item/result" (skill-agnostic), or move preview-trigger ownership to a booking-side trigger contract so the engine core stays domain-free.

### [04-F03] 🟢 LOW · D3 Structure (dead code) · classes/local/wizard/services/orchestrator_routing_service.php:261 & :54
**What:** Two public methods on `orchestrator_routing_service` have zero production callers.
**Evidence:** `with_phase_in_debug_source()` (line 261) is referenced only by `tests/agent/contracts/orchestrator_routing_service_test.php` — grep of `classes/` finds no non-definition use. `get_runtime_feature_flags_snapshot()` (line 54) is a static duplicate of the orchestrator's own snapshot accessor; grep of `classes/` finds no caller of the routing-service copy (the consumers call `orchestrator::` / `runtime_feature_flags::` directly).
**Impact:** Maintenance cruft; a reader assumes `with_phase_in_debug_source` is on a live path. No correctness or security impact.
**Compensating control:** Covered by a unit test (so not silently broken), but still unreachable in production.
**Recommendation:** Remove both (and the orphan test for `with_phase_in_debug_source`), or document why they are retained.

### [04-F04] 🟢 LOW · D3 Structure (dead code) · classes/local/wizard/services/planner_catalog_service.php:220
**What:** `planner_catalog_service::decode_catalog_json_array()` has zero callers anywhere (`classes/` and `tests/`).
**Evidence:** `grep -rn decode_catalog_json_array classes/` returns only the definition at line 220.
**Impact:** Dead helper. No impact beyond cruft.
**Compensating control:** None needed.
**Recommendation:** Delete.

### [04-F05] ⚪ INFO · D1 Security (prompt injection) · classes/local/wizard/services/phase_prompt_bundle_builder.php:259-263
**What:** User message content is concatenated verbatim into the planner prompt under `[{ROLE}]` markers, so a user could embed fake `[SYSTEM]` / `[OUTPUT_CONTRACT]` sections.
**Evidence:** `build_prompt()` loops `$parts[] = "[{$role}]\n{$content}"` with no escaping/sanitisation of `$content` (lines 259-263); same for `[OBSERVATION n]` (line 407).
**Impact:** A crafted message can attempt to steer the selector/constructor. However the planner output is a constrained JSON skill selection that is re-validated by the interpreter (single-command, skill-must-match contracts) and **every** resulting skill execution is gated by `authorization_service` (Gate 1) + `native_capability_guard` (Gate 2) in `executor.php` (lines 34-35, 266). Injection therefore cannot make the agent do anything the authenticated user is not already permitted to do, and the planner itself performs no mutation.
**Compensating control:** Two-gate capability enforcement downstream; constrained JSON output contract; per-phase allowed-skill restriction in construction (`allowed_skills => [$selectedskill]`).
**Recommendation:** Accept as residual (inherent to text-prompt LLMs). Optionally strip bracketed section markers from user `$content` before injection as defence-in-depth.

### [04-F06] 🟢 LOW · D4 Duplication · classes/local/wizard/orchestrator.php:398-448 ↔ classes/local/wizard/services/planner_phase_service.php:752-802
**What:** `build_provider_error_result()` and `build_empty_provider_result()` are byte-for-byte duplicated between the orchestrator (synchronizer path) and `planner_phase_service` (planner path), including the identical error-class classification ladder.
**Evidence:** Compare orchestrator.php:398-448 with planner_phase_service.php:752-802 — same body, same comments. The orchestrator docblock at line 393 even notes "Shared by the synchronizer step here and (duplicated) by planner_phase_service."
**Impact:** Two copies drift independently; a fix to error classification must be made twice.
**Compensating control:** The duplication is acknowledged in the code comment.
**Recommendation:** Extract into a shared `provider_error_result_factory` (or a trait alongside `planner_phase_prompt_trait`) consumed by both.

### [04-F07] 🟢 LOW · D6 Docs coverage (doc-lag) · docs/architecture/05-planner-orchestrator.md §2,§8,§9
**What:** Chapter 05 cites concrete `orchestrator.php` line numbers for the planner/synchronizer LLM calls that no longer exist after the orchestrator was split into `discovery_phase_service` / `planner_phase_service`.
**Evidence:** §2 cites selection at `orchestrator.php:1075`, construction at `:1309`, embeddings at `:707`, synchronizer at `:492`/`:489`; §9 maps `SPLLM`/`CPLLM`/`SLLM` to `:1057`/`:1292`/`:489`. The current `orchestrator.php` is 809 lines; the two planner chat calls are now `planner_phase_service.php:222` (selection) and `:432` (construction), the embeddings call is `discovery_phase_service.php:294`, and the only `invoke_for_context` left in `orchestrator.php` is the synchronizer at line 372.
**Impact:** Line citations are wrong; the *behavioural* claim (exactly two planner chat calls + a separate synchronizer call) remains true and was re-verified.
**Compensating control:** The invariant itself is correct and confirmed.
**Recommendation:** Re-point ch.05 §2/§8/§9 at `discovery_phase_service` / `planner_phase_service` and refresh the line numbers (or drop exact lines in favour of method names).

## C. Per-file / per-method checklist

#### `classes/local/wizard/orchestrator.php` (class `orchestrator`)
- [x] D1 [x] D2 [ ] D3 (04-F06) [ ] D4 (04-F06) [x] D5 [ ] D6 (04-F07) — file-level
- methods:
  - [x] `get_runtime_feature_flags_snapshot()` — clean
  - [x] `__construct()` — DI wiring of split services; clean
  - [x] `get_runtime_provider_status()` — thin delegator; clean
  - [x] `process()` — strict discovery→selection→(conditional)construction→compose; D5✓ (two-call invariant)
  - [x] `process_synchronizer()` — separate single-call pass; D1✓ D5✓
  - [ ] `build_provider_error_result()` — see 04-F06 (D4)
  - [ ] `build_empty_provider_result()` — see 04-F06 (D4)
  - [x] `resolve_synchronizer_action_class()` — fallback chain; clean
  - [x] `run_discovery_phase()` / `run_selection_phase()` / `run_construction_phase()` — thin delegators; clean
  - [x] `get_default_initial_prompt_template_for_action()` — static prompt templates, no injection; clean
  - [x] `get_default_summary_prompt_prefix()` / `is_default_summary_prompt_prefix()` — clean
  - [x] `is_first_assistant_turn()` — clean
  - [x] `build_runtime_context_block()` — delegator; clean

#### `classes/local/wizard/services/discovery_phase_service.php` (class `discovery_phase_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (strict_types; `agent_access_service`/`provider_routing_util` resolve via same namespace)
- methods:
  - [x] `__construct()` — clean
  - [x] `run()` — dual-path discovery, embed top-k=12, family staging, no chat call; D5✓ (FREG/EMB_QUERY/FSIG/FRANK/DISC_A-C all honoured); D1✓ (no SQL, ids pre-validated)
  - [x] `ensure_search_skills_fallback()` — the one sanctioned force-include, deduplicated; D5✓
  - [x] `extract_recent_skill_names_from_messages()` — JSON-safe parse; clean
  - [x] `is_first_assistant_turn()` — clean (duplicated from orchestrator, acknowledged)
  - [x] `is_low_semantic_followup()` — pure word-count heuristic, language-agnostic; clean
  - [x] `find_recent_substantial_user_text()` — length-capped; clean
  - [x] `normalize_planner_trace_history()` — clean

#### `classes/local/wizard/services/planner_phase_service.php` (class `planner_phase_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (04-F06) [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct()` — clean
  - [x] `run_selection()` — planner chat call 1; single-command contract; telemetry best-effort try/catch; D5✓
  - [x] `run_construction()` — planner chat call 2, only on skill_call; allowed_skills restricted to selected skill; D5✓
  - [x] `normalize_selection_phase_output_for_handoff()` — strips params, enforces single command; D1✓
  - [x] `build_construction_runtime_catalog_for_selected_skill()` — restricts catalog to chosen skill; clean
  - [x] `enrich_construction_catalog_entry()` — surfaces guidance unconditionally in construction; clean
  - [x] `collect_skill_guidance_lines()` — duck-typed, skill-agnostic; clean
  - [x] `extract_selected_skill_from_selection_phase_output()` — clean
  - [x] `build_selection_contract_error_result()` / `build_selector_handoff_error_result()` — clean
  - [x] `resolve_passthrough_construction_field()` / `build_passthrough_construction_result()` — duck-typed, engine stays skill-agnostic; D3✓
  - [ ] `build_provider_error_result()` / `build_empty_provider_result()` — see 04-F06 (D4)
  - [x] `build_phase_handoff_observations()` — JSON-safe; clean

#### `classes/local/wizard/services/planner_phase_prompt_trait.php` (trait)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — centralises the previously-duplicated prompt wrappers (build_system_prompt/build_prompt/json_encode_or_empty); clean

#### `classes/local/wizard/services/planner_catalog_service.php` (class `planner_catalog_service`)
- [x] D1 [x] D2 [ ] D3 (04-F04) [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `slim_prompt_catalog_for_planner()` / `exclude_discovery_meta_skills()` / `sanitize_runtime_catalog_for_prompt()` — live-contract re-join; no full dump; D5✓
  - [x] `live_contract_lookup()` — defensive try/catch; clean
  - [ ] `decode_catalog_json_array()` — see 04-F04 (dead code)
  - [x] `render_catalog_as_text()` — compact text rendering; clean
  - [x] `compact_catalog_description()` / `compact_catalog_example_input()` / `compact_catalog_message_triggers()` — token economy; clean
  - [x] `filter_catalog_by_selected_families()` — family gating; clean
  - [x] `catalog_mode_is_static()` — clean
  - [x] `split_prompt_contracts_by_full_access()` — PRO-gate lock note via get_string; D1✓ D2✓
  - [x] `resolve_namespace_hint_from_prompt_contracts()` — deterministic majority hint; clean

#### `classes/local/wizard/services/phase_prompt_bundle_builder.php` (class `phase_prompt_bundle_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (no strict_types — INFO)
- methods:
  - [x] `__construct()` — clean
  - [x] `build_system_prompt()` — admin override via `normalize_config_prompt_template`, cache-stable placeholders, `strtr` schema placeholders neutralised to `[]`/`{}`; D1✓
  - [x] `is_planner_action()` / `get_default_constructor_prompt_template()` — clean
  - [ ] `build_prompt()` — see 04-F05 (D1, INFO: user content concatenated; mitigated by downstream gates)
  - [x] `build_output_contract_block()` / `build_output_contract_reminder()` — static contract text; clean
  - [x] `append_planner_traces_and_observations()` — interleave order; clean

#### `classes/local/wizard/services/orchestrator_routing_service.php` (class `orchestrator_routing_service`)
- [x] D1 [x] D2 [ ] D3 (04-F03) [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `resolve_action_class_for_phase()` / `resolve_selection_action_class()` / `resolve_construction_action_class()` — independent fallback chains; try/catch best-effort; D5✓
  - [x] `is_action_available_in_context()` — method_exists guard for cross-version core_ai; D2✓
  - [x] `build_debug_source()` — telemetry only; note `primaryprovider` is never set by the resolver (empty in discovery) — cosmetic (04-F03 sibling, not separately raised)
  - [ ] `with_phase_in_debug_source()` — see 04-F03 (dead code)
  - [x] `route_policy_family()` / `is_wunderbyte_routepolicy()` / `short_debug_token()` / `normalize_phase()` / `build_phase_route_policy()` — clean
  - [ ] `get_runtime_feature_flags_snapshot()` — see 04-F03 (dead duplicate)

#### `classes/local/wizard/services/orchestrator_prompt_profile_service.php` (class `orchestrator_prompt_profile_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `observations_are_framework_retry_hints()` — clean
  - [x] `get_planner_initial_prompt_config_key_for_phase()` — clean
  - [x] `get_history_limit_for_phase()` — seam, single tunable; clean
  - [x] `select_history_messages()` — tail window + first-user-message preservation; clean
  - [x] `normalize_config_prompt_template()` — CRLF/legacy-default normalisation; clean
  - [x] `normalize_phase()` — clean

#### `classes/local/wizard/services/planner_result_composer.php` (class `planner_result_composer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (no strict_types — INFO)
- methods:
  - [x] `compose()` — selection+construction trace, planned_steps carry-up; D5✓ (matches ch.05 §6)
  - [x] `build_phase_snapshot()` — clean

#### `classes/local/wizard/services/phase_trace_normalizer.php` (class `phase_trace_normalizer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `normalize()` canonicalises 3-phase trace; consumed by conversation_store + message_persistence_service; clean

#### `classes/local/wizard/message_trigger_registry.php` (class `message_trigger_registry`)
- [x] D1 [x] D2 [ ] D3 (04-F02) [x] D4 [x] D5 [x] D6 — file-level (no strict_types — INFO)
- methods:
  - [x] `__construct()` — clean
  - [x] `get_available_triggers()` — dedup by id; clean (consumed internally + by interpreter/agent_runtime)
  - [x] `get_available_trigger_ids()` — clean
  - [x] `normalize_used_triggers()` — allow-lists LLM-returned ids; D1✓ (no unvalidated passthrough)
  - [x] `normalize_response_type()` — known-set allow-list; clean
  - [ ] `CORE_TRIGGERS` const — see 04-F02 (engine→domain leak: `core.is_preview_request` names "booking option")

#### `classes/local/wizard/services/discovery/family_signal_ranker.php` (class `family_signal_ranker`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 (04-F01) — `score_families()` implements base .20/core .10(`wizard.`)/namespace_hint .35/recency .20 exactly; `normalize_weight()` clamps; FSIG✓; ch.06 names wrong `core.` literal (04-F01)

#### `classes/local/wizard/services/discovery/family_ranker.php` (class `family_ranker`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `rank()` = 0.7·signal+0.3·semantic clamped, signal-only when no embeddings, alpha tiebreak; `select_low_score_tail()` max 2 / min 0.15; FRANK✓

#### `classes/local/wizard/services/discovery/context_prior_builder.php` (class `context_prior_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `build()` sets `is_hard_filter=false` (soft prior); clean

#### `classes/local/wizard/services/discovery/core_family_set.php` (class `core_family_set`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 (04-F01) — `resolve()` seeds `wizard.general`, filters `wizard.` prefix, caps at 4; correct in code; ch.06 §2 says `core.` (04-F01)

#### `classes/local/wizard/services/discovery/family_registry_service.php` (class `family_registry_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `discover()` keeps FULL family universe (namespace_hint only marks Stage-A subset, never narrows); matches FREG node comment; clean

#### `classes/local/wizard/services/discovery/discovery_stage_controller.php` (class `discovery_stage_controller`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `resolve()` A→B→C escalation; `stage_a_covers_intent()` intent-coverage guard (inert without embeddings, `INTENT_SEMANTIC_MIN=0.15`); DISC_A_OK/DISC_B/DISC_C✓; helpers clean

#### `classes/local/wizard/services/discovery/discovery_budget_policy.php` (class `discovery_budget_policy`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — budgets 12/24/36 match ch.06 §7 and DISC_A/B/C; clean

#### `classes/local/wizard/services/discovery/discovery_confidence_policy.php` (class `discovery_confidence_policy`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — thresholds 0.60/0.45 match ch.06 §7; `normalize_score`/`is_sufficient` clean

#### `classes/local/wizard/services/discovery/skill_discovery_service.php` (class `skill_discovery_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `discover()` is the RAG fallback engine behind `wizard.search_skills` (consumed by executor + search_skills_skill, not dead); English-normalises query, fail-open; embeddings readiness gated; clean

## D. Go-live blockers from this section

**None.** No BLOCKER and no HIGH findings. All defects are MEDIUM-or-below:
- 04-F01 (MEDIUM, doc) — ch.06 `core.`→`wizard.` family-namespace drift.
- 04-F02 (MEDIUM, structure) — `message_trigger_registry` engine→domain leak ("booking option").
- 04-F03/F04/F06 (LOW) — dead methods + duplicated provider-error builders.
- 04-F05 (INFO) — prompt-injection surface, fully mitigated by downstream two-gate capability enforcement.
- 04-F07 (LOW, doc-lag) — stale orchestrator line numbers in ch.05.

The strict two-planner-call invariant, bounded staged discovery, family signal/rank formulas, soft context prior, and "no full skill dump" rule are all confirmed correct against the code and the flowchart.
