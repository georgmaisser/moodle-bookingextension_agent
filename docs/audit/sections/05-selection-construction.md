# Audit Section 05 — Selection & Construction

**Scope:** `classes/local/wizard/services/selection/*` (lazy_skill_loader, skill_selection_overlap_policy, skill_selector), `services/construction/*` (parameter_constructor, parameter_contract_validator), `services/catalog/adaptive_skill_catalog_service.php`, `services/skill_catalog_discovery.php`, `services/shared_json_payload_extractor.php`, `services/spawn_contract_service.php` · **Files audited:** 9 · **Methods audited:** 23
**Arch chapter(s):** docs/architecture/07-selection-and-construction.md · **Flowchart nodes:** SEL (skill_selector / TSEL), CONS (parameter_constructor / PCON, parameter_contract_validator / PVAL); supporting: SKILLLOAD, CINT, MV_SUBSET, EXC_SPAWN
**Auditor verdict:** ⚠️ issues (no blocker)

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass   | Pure in-memory transformation layer; never touches `$DB`, no SQL, no output rendering, no capability checks of its own. Allow-list (`allowedskills`) is enforced twice (overlap policy + lazy loader); Gate 1/Gate 2 belong to the evaluator and preflight (correctly out of scope). One INFO: `strtotime()` on untrusted timestamp strings (05-F05). |
| D2 Moodle API      | pass   | Namespaced PSR-4 files, GPL headers, docblocks present. No Moodle API misuse. Minor strict_types inconsistency (05-F06). No hard-coded user strings (these services emit only technical error labels consumed internally, never user-facing — the synchronizer/interpreter localise). |
| D3 Structure       | issues | Engine→domain leak: `parameter_constructor` hard-codes mod_booking/musi field names (`teacherquery`, `bookusersquery`, `coursestarttime`, `courseendtime`, `question`, `search_queries`) — 05-F01 (HIGH). `spawn_contract_service` has no runtime caller (05-F03, LOW). |
| D4 Duplication     | issues | `prune_empty_input_values` duplicated verbatim in `parameter_constructor` and `interpreter` (05-F02, MEDIUM). |
| D5 Flowchart       | pass   | SEL/CONS/SKILLLOAD/PVAL behave as drawn. `get_mandatory_skills` removal (DISCO_RULE / EMB_QUERY nodes) is correctly reflected — only a code comment remains (05-F07, INFO). |
| D6 Docs coverage   | issues | Ch. 07 §4 says construction "normalizes the payload against the skill's schema" — understates the hard-coded domain-field normalizers that actually exist (05-F04, LOW). §5 lists a three-way valid/clarification/recoverable outcome that the in-scope DTO does not itself model (05-F08, INFO). |

## B. Findings

### [05-F01] 🟠 HIGH · D3 Structure · classes/local/wizard/services/construction/parameter_constructor.php:81,58-69,118,140-154
> **✅ FIXED 2026-06-30** — engine de-leaked: booking self-ref/timestamp fields → mod_booking `slot_booking_normalizer`; `search_queries` → `explain_docs` skill; `question` hydration → schema-driven `from_user_message` flag (6 skills declare it). `parameter_constructor` now names no domain field. phpcs 0/0; construction + booking-skill contract tests green; real-LLM matrix verifies end-to-end. See `docs/audit/README.md` remediation log.
**What:** The engine-layer parameter constructor hard-codes mod_booking/local_musi-specific field names, violating the "no engine→domain leak" boundary the architecture mandates.
**Evidence:**
- `normalize_self_user_references()` line 81: `$fields = ['teacherquery', 'selectusersquery', 'bookusersquery'];` — all three are booking/musi skill field names.
- `build()` lines 58-69: special-cases `coursestarttime` / `courseendtime` for timestamp coercion — booking-option schema fields.
- `canonicalize_command_input()` line 118: special-cases `search_queries` (a wizard/search field).
- `hydrate_question_field()` lines 140-154: special-cases a `question` property.
A grep of the whole skill tree (`classes/local/wizard/{course,wizard,core,question}/skills`) finds **zero** references to `teacherquery`/`coursestarttime`/`bookusersquery`; they live only in the engine (`parameter_constructor.php` and `privacy_anonymizer.php`). The registry already exposes the sanctioned, skill-owned hook for this exact job — `parameter_constructor` itself calls `$this->registry->normalize_skill_input($skillname, $input)` at line 116 (provider-owned `skill_input_normalizer`). The hard-coded blocks belong in those per-skill normalizers, not in the engine.
**Impact:** Engine carries domain heuristics: a new skill or a third-party family re-using a field named `question` or `coursestarttime` inherits unexpected mutation; conversely a renamed booking field silently loses normalization with no compile-time signal. This is the precise leak class flagged across the project (e.g. `feedback_executor_stays_clean`, the engine-extraction blueprint). It is a maintainability/contract-purity defect, not an exploit — hence HIGH, not BLOCKER.
**Compensating control:** Behaviour is currently correct for the shipped booking skills, and `prune_empty_input_values` + `check_structure()` downstream mean a mis-normalized field surfaces as a structural error rather than a bad mutation. No data-corruption path.
**Recommendation:** Move each hard-coded block into the relevant skill's provider `skill_input_normalizer` (the `normalize_skill_input` path already wired at line 116). Keep `parameter_constructor` schema-driven only. If a generic coercion is genuinely wanted (e.g. timestamp fields), drive it off a schema `type`/`format` declaration rather than a literal field-name list.

### [05-F02] 🟡 MEDIUM · D4 Duplication · classes/local/wizard/services/construction/parameter_constructor.php:165-186
**What:** `parameter_constructor::prune_empty_input_values()` is a verbatim duplicate of `interpreter::prune_empty_input_values()`.
**Evidence:** `parameter_constructor.php:165-186` and `interpreter.php:691-714` are the same recursive prune (drop empty-string/null/empty-array, keep `0`/`false`). Both run on the same payload in the same turn: the interpreter prunes during `normalize_commands_payload` (interpreter.php:619,643), then `parameter_constructor::build` prunes again at line 56.
**Impact:** Two copies drift independently (one already documents the "keeps numeric 0 and boolean false" rule in its docblock, the other does not). The double execution is harmless but redundant.
**Compensating control:** Both copies currently agree, so no behavioural bug today.
**Recommendation:** Extract one shared helper (e.g. a small `input_payload_pruner` used by both, or a static on `shared_json_payload_extractor`'s neighbourhood) and call it from a single place.

### [05-F03] 🟢 LOW · D3 Structure · classes/local/wizard/services/spawn_contract_service.php
**What:** `spawn_contract_service` has no runtime (`classes/`) caller — only test code references it.
**Evidence:** `grep -rln spawn_contract_service classes/` returns only the file itself; callers are `tests/agent/contracts/spawn_contract_service_test.php` and `reference_scenarios_contract_test.php`. Its three public methods (`normalize_skill_result`, `apply_output_bindings`, `normalize_spawn_commands`) are exercised only by those tests. The flowchart node `EXC_SPAWN` concedes this: "runtime enqueue path currently optional".
**Impact:** Designed-but-unwired contract surface. Not dead in the framework-entry-point sense (it is a contract service with passing tests for a planned feature), but it ships with no production consumer.
**Compensating control:** Test-covered; clearly labelled as optional in the flowchart, so this is an intentional staging state, not an oversight.
**Recommendation:** Either wire the spawn enqueue path before go-live or annotate the class docblock as "not yet runtime-wired (see EXC_SPAWN)" so a reader does not assume it is on the live path. No launch gate.

### [05-F04] 🟢 LOW · D6 Docs coverage · docs/architecture/07-selection-and-construction.md §4
**What:** Ch. 07 §4 describes construction as normalizing "the payload against the skill's schema," which omits the engine-side domain-field normalizers that actually run.
**Evidence:** §4: "The raw parameters are turned into a canonical input by `parameter_constructor::build(...)`, which normalizes the payload against the skill's schema." The real `build()` additionally applies self-user-reference splitting, timestamp coercion, `search_queries` CSV-splitting, and `question` hydration from the last user message (parameter_constructor.php:52-72) — none schema-driven.
**Impact:** A maintainer reading the chapter would not expect the hard-coded field handling found in 05-F01.
**Compensating control:** None needed; doc-lag only.
**Recommendation:** When 05-F01 is resolved by moving normalizers into per-skill providers, update §4 to say construction delegates field normalization to the skill-owned `normalize_skill_input` plus generic empty-pruning.

### [05-F05] ⚪ INFO · D1 Security · classes/local/wizard/services/construction/parameter_constructor.php:207
**What:** `normalize_timestamp_value()` passes a model-supplied string to `strtotime()`.
**Evidence:** Line 207 `$parsed = strtotime($trimmed);` on `coursestarttime`/`courseendtime` strings that originate from LLM output.
**Impact:** None of concern: `strtotime` returns `false` on garbage (handled), result is an int timestamp, and the value is structurally re-validated by `check_structure()` and semantically by the skill's `preflight()` before any DB write. Locale-relative phrases ("next monday") could parse to surprising values, but a wrong-but-valid timestamp is caught at preflight/confirmation, not mis-executed silently.
**Compensating control:** Downstream `check_structure` + `preflight` + R2 confirmation.
**Recommendation:** None required. Optionally restrict to ISO-8601/epoch only to avoid relative-phrase surprises.

### [05-F06] ⚪ INFO · D2 Moodle API · adaptive_skill_catalog_service.php, shared_json_payload_extractor.php
**What:** Two in-scope files omit `declare(strict_types=1)` while the other seven declare it.
**Evidence:** `adaptive_skill_catalog_service.php` and `shared_json_payload_extractor.php` lack the declaration (both are static-only utility classes).
**Impact:** Cosmetic inconsistency; strict_types is not required by the Moodle standard. No behavioural effect.
**Recommendation:** Add `declare(strict_types=1)` for consistency with the cluster.

### [05-F07] ⚪ INFO · D5 Flowchart · classes/local/wizard/services/catalog/adaptive_skill_catalog_service.php:22
**What:** `get_mandatory_skills` survives only as a comment; the method and the mandatory-skill tier are gone, matching the flowchart.
**Evidence:** `grep -rn get_mandatory_skills classes/ tests/` returns a single hit — the explanatory comment in `adaptive_skill_catalog_service.php:22-23`. The service's `get_adaptive_catalog()` now returns the full catalog unchanged (`unset($recentskillhistory, $phase); return ['active_skills' => $fullcatalog];`). This is exactly what flowchart nodes DISCO_RULE/EMB_QUERY mandate ("get_mandatory_skills are gone").
**Impact:** None — confirmed-correct. The scope brief listed `get_mandatory_skills` as a thing to audit; it has been fully removed, so that sub-item is N/A.
**Recommendation:** None. (Optionally the now-vestigial `$recentskillhistory`/`$phase` params could be dropped, but they are kept for signature compatibility with the one caller `discovery_phase_service`.)

### [05-F08] ⚪ INFO · D6 Docs coverage · docs/architecture/07-selection-and-construction.md §5
**What:** §5 presents a three-way validator outcome (valid / clarification / RECOVERABLE_INPUT_ERROR), but the in-scope `parameter_contract_validator` and its DTO only model `valid` + `errors` + `issuecodes`.
**Evidence:** `parameter_construction_result` has fields `input/valid/errors/issuecodes` (no `clarification` flag). `parameter_contract_validator::validate` just forwards `check_structure()`'s `valid/errors/issue_codes`. The clarification-vs-recoverable *routing* happens later in `interpreter` (interpreter.php:230 maps the `RECOVERABLE_INPUT_ERROR` issue code → response_type `clarification`, else `error`), i.e. outside this cluster.
**Impact:** The chapter attributes to the validator a decision that the interpreter actually makes. Reader could look for a clarification branch inside the validator and not find it. Behaviour is correct; the description is slightly mislocated.
**Compensating control:** None needed.
**Recommendation:** Note in §5 that the validator emits issue codes only and the interpreter performs the clarification/recoverable mapping.

## C. Per-file / per-method checklist

#### `services/selection/lazy_skill_loader.php`  (class `lazy_skill_loader`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct(skill_registry)` — clean
  - [x] `load_skill(string, array): ?skill_interface` — allow-list enforced (`in_array(...,true)`), strict comparison; clean

#### `services/selection/skill_selection_overlap_policy.php`  (class `skill_selection_overlap_policy`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (no external new-caller by name; instantiated as `skill_selector` default dependency — NOT dead)
- methods:
  - [x] `resolve(string, array): ?string` — exact match first; rejects ambiguous suffix (`count===1`); rejects dotted non-exact; clean

#### `services/selection/skill_selector.php`  (class `skill_selector`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct(lazy_skill_loader, ?overlap_policy)` — DI with sane default; clean
  - [x] `select(array, array, string): skill_selection_result` — missing-skill, governance-denied, not-registered, and success branches all return a DTO; `version` floored to ≥1; clean. (Single-command "exactly one" contract is enforced by the interpreter, by design — out of this class.)

#### `services/construction/parameter_constructor.php`  (class `parameter_constructor`)
- [ ] D3 — see 05-F01 (engine→domain field leak); [ ] D4 — see 05-F02 (prune duplication); [ ] D6 — see 05-F04; [x] D1 (see 05-F05 INFO) [x] D2 [x] D5
- methods:
  - [x] `__construct(skill_registry)` — clean
  - [ ] `build(...)` — see 05-F01 (coursestarttime/courseendtime special-case)
  - [ ] `private normalize_self_user_references(array)` — see 05-F01 (teacher/selectusers/bookusers query field list)
  - [ ] `private canonicalize_command_input(string, array)` — see 05-F01 (search_queries); calls registry normalizer (correct)
  - [ ] `private hydrate_question_field(...)` — see 05-F01 (question property special-case)
  - [ ] `private prune_empty_input_values(array)` — see 05-F02 (duplicate of interpreter)
  - [x] `private normalize_timestamp_value(mixed): ?int` — robust int/string/array handling; `strtotime` caveat = 05-F05 INFO

#### `services/construction/parameter_contract_validator.php`  (class `parameter_contract_validator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — see 05-F08 (doc places clarification routing here; code only forwards issue codes)
- methods:
  - [x] `validate(skill_interface, array, string): parameter_construction_result` — forwards `check_structure` valid/errors/issue_codes; trims+filters codes; clean

#### `services/catalog/adaptive_skill_catalog_service.php`  (class `adaptive_skill_catalog_service`)
- [x] D1 [x] D3 [x] D4 [x] D5 — file-level; [ ] D2 — see 05-F06 (no strict_types); D5 confirm = 05-F07
- methods:
  - [x] `static get_adaptive_catalog(array, array, string): array` — pass-through full catalog (lexical tiering removed per DISCO_RULE); clean

#### `services/skill_catalog_discovery.php`  (class `skill_catalog_discovery` implements `skill_catalog`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `instances(string): array` — thin adapter to `skill_discovery::get_skill_instances`; clean
  - [x] `diagnostics(): array` — thin adapter; clean

#### `services/shared_json_payload_extractor.php`  (class `shared_json_payload_extractor`)
- [x] D1 [x] D3 [x] D4 [x] D5 [x] D6 — file-level; [ ] D2 — see 05-F06 (no strict_types)
- methods:
  - [x] `static extract_json_candidates(string): string[]` — dedupes plain/fenced/balanced candidates; clean
  - [x] `static extract_balanced_json_objects(string): string[]` — correct string-aware brace matcher (handles escapes, quoted braces); clean

#### `services/spawn_contract_service.php`  (class `spawn_contract_service`)
- [x] D1 [x] D2 [x] D4 [x] D5 [x] D6 — file-level; [ ] D3 — see 05-F03 (no runtime caller)
- methods:
  - [x] `normalize_skill_result(string, array): array` — clean
  - [x] `apply_output_bindings(array, array, array): array` — validates ref present in available outputs; emits errors; clean
  - [x] `normalize_spawn_commands(array): array` — skips non-array/empty-skill; floors version; dedupes depends_on; clean
  - [x] `private normalize_produced_outputs(string, array)` — builds bare/`parent.`/`<skill>.` keys; clean
  - [x] `private normalize_binding_reference(string)` — maps `outputs.`→`parent.`; clean

## D. Go-live blockers from this section
- **None are launch-gating blockers.** No exploitable security hole, no data-corruption path, no mutation mis-execution in this cluster (selection/construction never touch the DB; all output is structurally and then domain-validated downstream before any write).
- **Recommended pre-launch fix (HIGH):** 05-F01 — move the hard-coded mod_booking/musi field normalizers (`teacherquery`, `bookusersquery`, `selectusersquery`, `coursestarttime`, `courseendtime`, `search_queries`, `question`) out of the engine `parameter_constructor` into the per-skill provider `normalize_skill_input` path that is already wired. This is a contract-purity / engine-cleanliness gate, not a security gate, but it is the one finding worth resolving before the engine is frozen for the `local_wizard` extraction.
