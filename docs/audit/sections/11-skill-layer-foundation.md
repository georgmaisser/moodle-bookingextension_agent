# Audit Section 11 — Skill Layer Foundation

**Scope:** `classes/local/wizard/{skill_registry,skill_registry_factory,skill_provider,base_skill,skill_contract_validator,skill_executability_evaluator,skill_discovery,course_targeted_skill,module_targeted_skill}.php`, `services/skill_version_policy.php`, `contracts/skill_family_contract.php`, `config/runtime_feature_flags.php`, `message_trigger_registry.php`, all 19 `interfaces/*.php` (+ `interfaces/summarizer/result_summary_contributor_interface.php`), and the in-scope DTOs (`skill_prompt_contract`, `skill_selection_result`, `discovery_result`, `parameter_construction_result`, `target_selector`, `context_target_resolution`, `agent_context`, `skill_risk_class`)
**Files audited:** 32 · **Methods audited:** ~150
**Arch chapter(s):** docs/architecture/14-skill-layer.md + 15-risk-classes.md · **Flowchart nodes:** `SKILLS` (TR/TRFAC/TI/TPC/TRC/BT/BSKILL/CSKILL/TCV), `MTRIG`
**Auditor verdict:** ✅ clean (issues are LOW/INFO only) — **no BLOCKER, no HIGH**

The skill-layer foundation is the strongest-engineered part of the engine I have seen: the
two-gate authorization derivation, the risk-class contract enforcement (R0↔readonly, R2/R3
require explicit context_scopes), the name-derived capability (never trusted from metadata),
fail-closed everywhere, immutable DTOs, and a clean engine→provider boundary all hold against
the code. Findings are doc-lag and minor clarity items.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | Gate-1 capability is **engine-derived** from the skill name (`build_skill_capability_name`) and re-derived inside `has_required_capabilities()` — never trusted from skill metadata; fail-closed on undefined cap / missing component (evaluator:192,210). Registry warns loudly when a skill's `<component>:skill_<name>` cap is undefined (registry:198). Risk-class declaration enforced at construction (base_skill:60) and activation (validator:189-199). No SQL, no user-input handling, no output rendering in this layer. |
| D2 Moodle API      | pass | Correct PSR-4 namespacing, file headers, `get_capability_info`/`has_capability`/`require_capability`, `context::instance_by_id(..., MUST_EXIST)`, `core_component`, `core_text`, `get_config`, `get_string` for all user-facing deny messages (validator:271-289). `phpcs --standard=moodle` could NOT be run (binary absent from this checkout) — style verified by inspection only (11-F04). |
| D3 Structure       | pass | Clean SRP split (registry=catalog, validator=governance, evaluator=runtime gate, discovery=filesystem, factory=DI cache). No engine→domain leak: `RESERVED_NAMESPACES=['booking','core','wizard']` is namespace ownership, not `mod_booking.*` heuristics. Two minor items: declared prompt-contract `capabilities` are silently dropped from Gate-1 (11-F02), and an unused `use` import (11-F03). |
| D4 Duplication     | pass | The `build_prompt_contract()` (registry) vs `prompt_contract_payload()` (base_skill) vs `skill_prompt_contract::to_array()` triple normalizes the same shape three times with drift-prone family/namespace fallbacks (11-F01) — the only real duplication, MEDIUM-adjacent but downgraded to LOW. `course_targeted_skill`/`module_targeted_skill` traits correctly *de-duplicate* per-skill targeting. |
| D5 Flowchart       | pass | `SKILLS` subgraph (TR/TRFAC/TI/TPC/TRC/BT/TCV) matches behaviour exactly. `MTRIG` node label and arch §4 both claim registry triggers "return `[]`", but `message_trigger_registry::get_available_triggers()` returns **5 core flow triggers** — the "no trigger→skill routing" invariant holds, but the "return `[]`" wording is stale doc-lag (11-F05). |
| D6 Docs coverage   | pass | ch.14/15 describe the code accurately and verifiably. Two wording inaccuracies: §4 references a registry `get_message_triggers()` method that does not exist (it is the skill-side interface method) (11-F05); §4 says newly discovered skills are "default-off" without the read-only-ON nuance the code actually implements (11-F06). |

### Two-gate / risk-class — verified against code (headline)

- **Name-derived capability is authoritative.** `skill_contract_validator::build_skill_capability_name($component, $skillname)` (validator:112-126) lowercases, regex-normalizes `[^a-z0-9]+ → _`, and yields `<component>:skill_<name>`. `skill_executability_evaluator::has_required_capabilities()` **re-derives** it from the skill name + stored component (evaluator:187-191) and force-adds it to the required set (evaluator:198-200), so a third-party skill cannot ship without its name cap being enforced even if its declared `capabilities` are empty. Undefined cap ⇒ `return false` (evaluator:210); missing component ⇒ `return false` (evaluator:192-195). Verified the generated name matches `db/access.php` (`bookingextension/agent:skill_<suffix>`, access.php:198).
- **Risk-class contract** (`verify`/`validate_skill_metadata`, validator:180-199): valid class required; **R0 ⇔ readonly** (both directions, :189-194); **R2/R3 require non-empty `context_scopes`** (:196-199); mismatch ⇒ `valid=false` ⇒ skill not registered (registry:181-188). Construction-time guard in `base_skill::__construct` throws on invalid class (base_skill:60). Matches ch.15 §2 and `LG_RISK*` exactly.
- **Activation default** (`is_skill_active`, registry:354-383): `aiskillenableall` overrides; else per-skill `aiskillenabled_<name>`; else **read-only skills auto-ON** unless `requires_explicit_activation()`, mutating skills OFF. This is a discovery/visibility default only — execution is still gated by the per-skill cap (fail-closed in the evaluator).

### DTO immutability — verified

`skill_selection_result`, `discovery_result`, `parameter_construction_result` use `public readonly` properties (PHP 8.1). `target_selector`, `context_target_resolution`, `agent_context` are `final` with private state and named static factories (no setters); `agent_context` mutates only private lazy-resolution caches and returns a *new* instance from `with_context()`. `skill_prompt_contract` holds an immutable private payload. `skill_risk_class` is a `final` const-only value object. All immutable as the chapters claim.

---

## B. Findings

### [11-F01] 🟢 LOW · D4 Duplication · skill_registry.php:474-547, base_skill.php:391-411, dto/skill_prompt_contract.php:46-65
**What:** The prompt-contract shape (intent/anchors/minimal_input/namespace/family/version/capabilities/context_scopes/risk_class) is assembled and normalized in three places with subtly different fallback logic.
**Evidence:** `base_skill::prompt_contract_payload()` derives the payload from schema `prompt_meta`; `skill_prompt_contract::to_array()` re-normalizes it and re-derives `family` from `namespace.'.general'`; `skill_registry::build_prompt_contract()` then *again* recomputes namespace (registry:485-491), version (`max(contract, meta)`, :493-495), family (`resolve_from_prompt_contract` + meta fallback, :496-499) and capabilities (prompt vs meta, :501-506). Three independent namespace/family fallbacks can drift.
**Impact:** Maintenance hazard: a change to family/namespace derivation must be mirrored in three spots or the prompt vs governance views diverge. No runtime defect today (outputs are consistent for current skills).
**Compensating control:** All three converge to `skill_family_contract::normalize_family()` which fail-closes to `wizard.general`, bounding the blast radius to a wrong-but-valid family.
**Recommendation:** Have `build_prompt_contract()` consume the skill's own `get_prompt_contract()->to_array()` as the single source for namespace/family/version and only layer governance-meta on top, removing the parallel re-derivation.

### [11-F02] 🟢 LOW · D3 Structure · skill_contract_validator.php:74-86 + skill_registry.php:501-506
**What:** A skill's *declared* prompt-contract `capabilities` are never enforced as Gate-1 capabilities — only the single engine-derived name capability is.
**Evidence:** `build_skill_metadata()` initializes `$capabilities = []` and appends **only** `$defaultcapability` (the name-derived cap), so `get_skill_capabilities()` (registry:407-414, read by the evaluator at :197) returns exactly one entry regardless of what `prompt_meta.capabilities` declares. The declared list is surfaced only into the *prompt* contract (registry:501-506), never into the gate.
**Impact:** A skill author who lists extra native caps in `prompt_meta.capabilities` expecting them to gate execution will find they are silently ignored at Gate 1. (Native action caps are correctly enforced separately as Gate 2 via `get_required_native_capabilities()`, so this is not a security hole — only a surprising no-op.)
**Compensating control:** Gate 2 (`native_capability_guard` / `require_native_capabilities`) enforces the real action capabilities at the operating context; the name cap always gates Gate 1.
**Recommendation:** Either fold declared `capabilities` into the Gate-1 required set in `build_skill_metadata()`, or document explicitly (ch.14 §6) that Gate-1 is name-cap-only and `capabilities` is advisory/prompt-facing.

### [11-F03] 🟢 LOW · D3 Structure · skill_executability_evaluator.php:30
**What:** Unused `use context;` is fine, but the evaluator imports nothing else stale — re-verified; the genuine item is `skill_registry.php:32` importing `result_summary_contributor_interface` which *is* used (line 96). No actual unused import found in the evaluator. **Downgraded to INFO — no defect** (kept as a checklist anchor; see note).
**Evidence:** `context::instance_by_id` is used at evaluator:203; the registry's summarizer import is used.
**Impact:** None.
**Compensating control:** n/a.
**Recommendation:** None — confirmed clean. (Finding retained only to record that the import sweep was performed.)

### [11-F04] 🟢 LOW · D2 Moodle API · whole section
**What:** `phpcs --standard=moodle` could not be executed in this checkout (no `vendor/bin/phpcs`), so coding-style compliance is verified by inspection only.
**Evidence:** `vendor/bin/phpcs` and a global `phpcs` are both absent.
**Impact:** Style regressions (line length, ordering) would not be mechanically caught here. By inspection all files carry the GPL header, correct `@package bookingextension_agent`, PSR-4-correct namespaces matching paths, full method docblocks, and `declare(strict_types=1)` on the newer files — no visible violations.
**Compensating control:** The C2 moodle-api cross-cutting sweep and CI run phpcs.
**Recommendation:** Confirm phpcs is green in CI for this file set before go-live (expected to pass).

### [11-F05] 🟢 LOW · D5 Flowchart / D6 Docs · message_trigger_registry.php:55-118, docs/architecture/14-skill-layer.md §4, docs/reference/flowchart-guide.md:180-181
**What:** The `MTRIG` flowchart node, ch.14 §4, and the flowchart-guide all state registry triggers "return `[]`", but `message_trigger_registry::get_available_triggers()` actually returns **5 core flow triggers** (`core.is_confirmation_message`, `core.discard_pending_confirmation`, `core.is_lookup_request`, `core.is_preview_request`, `core.force_new_duplicate_option`).
**Evidence:** `CORE_TRIGGERS` (message_trigger_registry:55-77) is non-empty and exposed by `get_available_triggers()`. Separately, ch.14 §4 names a registry method `get_message_triggers()` that does not exist on this class — that method belongs to `skill_trigger_provider_interface` (the *skill*-side hook, which providers may return `[]` from).
**Impact:** Doc-lag only. The substantive invariant — **no trigger→skill routing** — is correct: `normalize_used_triggers()` merely allow-lists the LLM's claimed trigger ids against the core set; there is no map from a trigger to a skill. The "registry triggers return `[]`" phrasing is simply false against the code.
**Compensating control:** n/a (documentation).
**Recommendation:** Reword ch.14 §4 / the `MTRIG` node to: "the registry exposes a fixed core flow-trigger allow-list and derives `core.*` signals server-side; there is no trigger→skill routing map," and drop the non-existent `get_message_triggers()` reference (or attribute it to the skill interface).

### [11-F06] ⚪ INFO · D6 Docs · docs/architecture/14-skill-layer.md §4 ("Activation")
**What:** ch.14 §4 says newly discovered skills are "**default-off** until explicitly enabled," which understates the implemented behaviour.
**Evidence:** `is_skill_active()` (registry:370-383) auto-enables **read-only** skills out of the box (unless `requires_explicit_activation()`); only mutating skills are default-off. The flowchart `TR` node states this correctly ("read-only skills ON out of the box, mutating skills OFF").
**Impact:** None functional; chapter is more conservative than the code. A reader could wrongly believe a fresh install exposes no read-only skills.
**Compensating control:** n/a.
**Recommendation:** Align §4 prose with the `TR` node: read-only ON by default, mutating OFF.

### [11-F07] ⚪ INFO · D3 Structure · classes/local/wizard/__pycache__/ + wunderbyte_*_endpoint.py
**What:** `classes/local/wizard/` contains Python artefacts (`__pycache__/*.pyc`, `wunderbyte_shop_endpoint.py`, `wunderbyte_trial_endpoint.py`) alongside the PHP classes.
**Evidence:** `ls` shows 5 `.pyc` files in `__pycache__` and two `.py` files in the wizard dir.
**Impact:** None on the audited PHP skill layer (the `.py` files are external mock endpoints, out of this section's PHP scope). The `__pycache__` is build cruft that does not belong in a Moodle `classes/` tree and could trip `core_component` directory scans / packaging.
**Compensating control:** `skill_discovery` only scans `*/skills` subdirs and filters `getExtension() !== 'php'`, so the Python files cannot be misread as skills.
**Recommendation:** Remove `__pycache__/` and relocate the `.py` mock endpoints out of `classes/` (cleanup backlog; the `.py` files themselves are owned by another section/cross-cutting cruft note).

---

## C. Per-file / per-method checklist

#### `skill_registry.php` (class `skill_registry`)
- [x] D1 [x] D2 [ ] D3 [ ] D4 [x] D5 [x] D6 — file-level (D3→11-F02, D4→11-F01)
- methods:
  - [x] `__construct()` / `register()` — fail-soft per-provider/per-skill diagnostics, namespace-reservation guard, dup guard, undefined-cap warn — clean
  - [x] `get_skill()` `get_provider_for_skill()` `normalize_skill_input()` `get_skill_names()` `get_skills()` — clean
  - [x] `get_skill_names_for_context()` `get_prompt_contracts_for_context()` — executability-filtered — clean
  - [x] `get_skill_contract()` `get_skill_contracts()` `get_contract_diagnostics()` `get_result_summary_contributors()` — clean
  - [x] `is_read_only_skill()` `is_skill_active()` — read-only-ON default verified — clean
  - [x] `get_skill_toggle_setting_name()` — normalizes to `aiskillenabled_<name>` — clean
  - [ ] `get_skill_capabilities()` — returns name-cap only (see 11-F02)
  - [x] `get_all_prompt_contracts()` — clean
  - [ ] `build_prompt_contract()` — triple normalization (see 11-F01)
  - [x] `get_contextual_prompt_packs()` — id-dedup — clean
  - [x] `make_default()` — provider-first; no-fallback-when-provider-present; always registers engine provider — clean
  - [x] `register_discovered_skills_without_provider()` `normalize_provider_component_name()` `append_provider_discovery_diagnostics()` `add_contract_diagnostic()` `fail_on_contract_diagnostics_when_strict()` `is_governance_strict_mode_enabled()` — clean

#### `skill_contract_validator.php` (class `skill_contract_validator`)
- [x] D1 [x] D2 [ ] D3 [x] D4 [x] D5 [x] D6 — file-level (D3→11-F02)
- methods:
  - [ ] `build_skill_metadata()` — name-cap only in `capabilities` (see 11-F02)
  - [x] `build_skill_capability_name()` — engine-derived, lowercased, regex-normalized — clean (security-critical, correct)
  - [x] `validate_skill_metadata()` — R0↔readonly + R2/R3 scopes + family/version/alias checks — clean (verified vs ch.15)
  - [x] `validate_registry_contracts()` — dup + alias-target + alias-version — clean
  - [x] `get_user_facing_deny_message()` — get_string for all reasons — clean
  - [x] `extract_skill_namespace()` `is_namespaced_skill_name()` `component_may_register_namespace()` — reserved-namespace ownership — clean

#### `base_skill.php` (abstract class `base_skill`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct()` — throws on invalid risk class — clean
  - [x] `is_read_only()` `get_risk_class()` `requires_explicit_activation()` `get_required_context_level()` — clean
  - [x] `supports_target_context()` `get_target_context_level()` `get_target_selector()` — opt-in default false; safety rule documented — clean
  - [x] `get_required_native_capabilities()` `require_native_capabilities()` — Gate-2 helper, `require_capability` at operating context — clean
  - [x] `attachments()` `thread_memory()` `skill_catalog()` — engine-agnostic accessors — clean
  - [x] `get_example_input()` `describe_proposed_action()` `short_skill_name()` `humanize_identifier()` `format_proposed_action_value()` `is_truthy_preview_flag()` `format_proposed_action_array()` — preview-only, no IO — clean
  - [x] `prompt_contract_payload()` — DTO-free override point — clean
  - [x] `get_prompt_contract()` (final) — DTO wrapper — clean
  - [x] `check_structure()` `pass()` `invalid()` `confirmable()` `run_preflight()` — DTO-free primitives — clean
  - [x] `preflight()` (final) — single DTO mapping point — clean

#### `skill_executability_evaluator.php` (class `skill_executability_evaluator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (security-critical, verified)
- methods:
  - [x] `__construct()` — DI of registry + authorization_service — clean
  - [x] `evaluate_skill()` — ordered deny chain (not_registered → runtime_disabled → inactive → requires_pro → missing_capability → context_invalid) — clean
  - [x] `evaluate_all_skills()` `get_executable_skill_names()` `deny_result()` — clean
  - [x] `has_required_capabilities()` — **re-derives name cap**, fail-closed on undefined cap / missing component / bad context — clean (the gate)
  - [x] `is_valid_context()` — delegates to authz, catches Throwable — clean

#### `skill_discovery.php` (class `skill_discovery`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `get_skill_instances()` `get_trigger_provider_instances()` `get_last_diagnostics()` — fail-soft per-class — clean
  - [x] `find_candidate_classes()` — scans only `*/skills`, php-only, excludes `/tests/` — clean
  - [x] `get_skill_directories()` `instantiate_if_supported()` `ensure_class_loaded()` `add_diagnostic()` `compare_skill_classes()` `get_namespace_priority()` — domain-namespace priority over core — clean

#### `skill_provider.php` (class `skill_provider`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — engine default provider — clean (all methods: get_component/get_skills/get_discovery_diagnostics/get_contextual_prompt_packs/get_issue_code_provider/get_prompt_guidance/get_result_summary_contributors)

#### `skill_registry_factory.php` (class `skill_registry_factory`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — per-request cache + fail-soft empty-registry fallback + `reset()` for tests — clean

#### `course_targeted_skill.php` (trait) / `module_targeted_skill.php` (trait)
- [x] all dimensions — clean. `course_targeted_skill` used by 6 skills; `module_targeted_skill` exercised by 2 tests + consumed by `module_target_resolver` (a framework contract trait, **not** dead code — grep of `classes/`+`tests/` confirms test consumers and `get_target_modname` implementors).

#### `services/skill_version_policy.php` (class `skill_version_policy`)
- [x] all dimensions — clean. Consumers: `preflight_version_validator`, `preflight_contract_validator` (grep-confirmed). `evaluate()` + `is_deprecated()` — pure, no IO.

#### `contracts/skill_family_contract.php` (class `skill_family_contract`)
- [x] all dimensions — clean. `from_skill_name`/`resolve_from_prompt_contract`/`is_valid_family`/`normalize_family` — fail-closed to `wizard.general`.

#### `config/runtime_feature_flags.php` (class `runtime_feature_flags`)
- [x] all dimensions — clean. Unknown flags → disabled / enforce-default (fail-safe). `enforcement_mode`/`is_enabled`/`snapshot`/`normalize_bool`.

#### `message_trigger_registry.php` (class `message_trigger_registry`) — node `MTRIG`
- [x] D1 [x] D2 [x] D3 [x] D4 [ ] D5 [ ] D6 — file-level (D5/D6 → 11-F05)
- methods:
  - [x] `__construct()` (DI of registry) `get_available_triggers()` `get_available_trigger_ids()` — clean
  - [x] `normalize_used_triggers()` — allow-lists LLM trigger ids; **no skill routing** — clean
  - [x] `normalize_response_type()` — known-set allow-list → UNKNOWN_TYPE — clean

#### DTOs
- [x] `dto/skill_prompt_contract.php` — immutable payload, normalize_string_list — clean
- [x] `dto/skill_selection_result.php` — `readonly` props — clean
- [x] `dto/discovery_result.php` — `readonly` props + to_array — clean
- [x] `dto/parameter_construction_result.php` — `readonly` props — clean
- [x] `dto/target_selector.php` — `final`, private ctor, named factories (for_course/for_module/create), is_empty/is_module_target — clean
- [x] `dto/context_target_resolution.php` — `final`, STATUS_* states, no silent ambient fallback — clean
- [x] `dto/agent_context.php` — `final`, lazy private caches, `with_context()` returns new instance — clean
- [x] `dto/skill_risk_class.php` — `final`, const-only, `is_valid()` — clean

#### Interfaces (19 + 1 summarizer) — all file-level [x] all dimensions, ISP-respecting
- [x] `skill_interface.php` — the core 9-method contract (matches `TI` node) — clean
- [x] `skill_provider_interface.php` — component/get_skills/prompt-packs/issue-codes/guidance — clean
- [x] `skill_catalog.php` `skill_discovery_provider_interface.php` `skill_introspection_provider_interface.php` — segregated discovery/introspection — clean
- [x] `skill_trigger_provider_interface.php` (`get_message_triggers`) `skill_input_normalizer_interface.php` `skill_input_normalizer_provider_interface.php` — optional skill hooks — clean
- [x] `result_summary_provider_interface.php` `skill_result_summary_provider_interface.php` `summarizer/result_summary_contributor_interface.php` — clean
- [x] `agent_authorization_service.php` `agent_conversation_store.php` `agent_executor.php` `agent_interpreter.php` — engine-port interfaces (inverted deps) — clean
- [x] `attachment_resolver.php` `thread_memory.php` `external_dependency_checker_interface.php` `issue_code_provider_interface.php` `operating_context_target_provider_interface.php` `queue_identity_provider_interface.php` — narrow, single-purpose — clean

---

## D. Go-live blockers from this section

**None.** No BLOCKER and no HIGH findings. The skill-layer foundation is go-live ready.

Recommended cleanups (non-gating):
- 11-F01 (LOW): collapse the triple prompt-contract normalization to one source of truth.
- 11-F02 (LOW): fold declared `capabilities` into Gate-1, or document Gate-1 as name-cap-only.
- 11-F04 (LOW): confirm `phpcs --standard=moodle` is green for this file set in CI (could not run locally).
- 11-F05/F06 (LOW/INFO): reword ch.14 §4 + `MTRIG` node ("return `[]`", non-existent `get_message_triggers()` on the registry, read-only-ON default).
- 11-F07 (INFO): remove `__pycache__/` cruft from `classes/local/wizard/`.
