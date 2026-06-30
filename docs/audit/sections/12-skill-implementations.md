# Audit Section 12 — Skill Implementations

**Scope:** `classes/local/wizard/core/skills/*`, `classes/local/wizard/wizard/skills/*`, `classes/local/wizard/course/skills/*`, `classes/local/wizard/question/skills/generate_questions_skill.php`, `classes/local/wizard/services/scaffold/skill_template_generator.php`, `classes/local/wizard/services/user_memory_service.php`  ·  **Files audited:** 21  ·  **Methods audited:** ~190
**Arch chapter(s):** docs/architecture/14-skill-layer.md  ·  **Flowchart nodes:** SKILLS (per-skill execute/preflight), UM_SVC, UM_REMEMBER
**Auditor verdict:** ⚠️ issues (one HIGH PII-exposure; rest LOW/MEDIUM/INFO — no blocker)

The skill layer is, on the whole, disciplined: every memory skill uses the engine-supplied `$userid` (never input), the `user_memory_service` is the single DB chokepoint with ownership-checked delete, every mutating (R2) course/question skill declares native capabilities AND re-checks them inline at the operating context, the cross-user diagnose skills gate on the right Moodle capability at the target's context, and the scaffold path is in-memory/escaped. The one real gap is `core.search_users`, which (unlike every sibling read skill) returns full user PII for arbitrary users with no per-target visibility check.

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues | `core.search_users` returns full PII (email/phone/address/idnumber/custom fields) for any matched user with no `moodle/user:viewdetails`/`site:viewuseridentity` gate; mitigated to teacher+ by the name-derived skill capability but broader than native Moodle (12-F01, HIGH). Everything else: memory skills strictly user-isolated; diagnose skills gate cross-user reads; mutating skills enforce Gate 2 inline + via declared native caps. |
| D2 Moodle API      | issues | A number of user-facing/diagnosis/clarification strings are hard-coded English instead of `get_string` (12-F03, LOW — synchronizer re-renders). `add_quiz` declares incomplete native caps vs its inline checks (12-F04, INFO). All `$DB` access parameterised (`get_in_or_equal`, named params). |
| D3 Structure       | pass | No engine→domain leak; duck-typed setters all have engine callers (no dead code); single-responsibility respected; `recreate_skill_catalog` carries an inaccurate `['module']` context scope for a site-wide action (12-F02, MEDIUM). |
| D4 Duplication     | issues | Three near-identical mutating course skills (`add/update_activity`, `add/update_quiz`) and a repeated `clarification_issues`/hard-coded-English clarification pattern across diagnose + course skills (12-F05, LOW). |
| D5 Flowchart       | pass | `check_structure`/`run_preflight`/`execute` separation honoured; R0 skips preflight and self-resolves; `wizard.remember` R0 auto-executes / `wizard.forget` R2 confirmed — matches UM_REMEMBER/UM_SVC nodes; recall re-anchoring matches APO/anonymizer notes. No behavioural deviation found. |
| D6 Docs coverage   | n/a | Chapter 14 documents the skill *abstraction* (registry/base/contract) and explicitly defers concrete skills to the skills catalog README; it makes no per-skill claims to contradict. No material omission at the abstraction level. |

## B. Findings

### [12-F01] 🟠 HIGH · D1 Security · classes/local/wizard/core/skills/search_users_skill.php:180 (+ core_skill_base.php:344 `build_user_payload`, :677 `build_user_observation_full`)
> **✅ FIXED 2026-06-30** — `execute()` now drops candidates that fail `user_can_view_profile($user)`, closing the site-wide enumeration vector. See the remediation log in `docs/audit/README.md`.
**What:** `core.search_users` resolves arbitrary users by free-text query and returns their full PII — email, phone1/phone2, address, idnumber, institution, city, country, custom profile fields, lastaccess/lastlogin — with **no per-target visibility capability check**.
**Evidence:** `execute()` calls `$this->search_user_candidates_for_preview($query, $limit)` then `\core_user::get_user($candidateid, '*', MUST_EXIST)` → `build_user_payload($user)` which emits `'email'`, `'phone1'`, `'phone2'`, `'address'`, `'idnumber'`, `'institution'`, … and `build_user_observation_full()` ships them to the LLM as `email=…, phone1=…, address=…`. Nowhere is `has_capability('moodle/user:viewdetails' …)` or `moodle/site:viewuseridentity` checked at the resolved user's context. Core `search_users(0, 0, …)` does not capability-filter results. Contrast: `diagnose_permissions` gates cross-user on `moodle/role:review` (:250), `diagnose_notifications` on `moodle/user:viewalldetails` (:214), `analyze_course_structure` on `can_access_course` (:248) — `search_users` has nothing.
**Impact:** Any user holding `bookingextension/agent:skill_core_search_users` — granted to archetype **`teacher`** and `editingteacher` at CONTEXT_MODULE (db/access.php `$teacherskills`, :127) — can enumerate any site user's identity/contact PII by name fragment, including users they share no course with. A non-editing teacher in a single booking activity becomes a site-wide user-PII directory. This exceeds what native Moodle would expose to that role.
**Compensating control:** The engine's name-derived capability gate (`skill_executability_evaluator::has_required_capabilities`) restricts the skill to teacher-and-above, and the privacy anonymizer masks PII in the persisted/displayed transcript — so it is not an *anonymous* leak. But the acting teacher still sees the de-anonymized result, and teacher ≠ "may view this user's identity".
**Recommendation:** In `search_users_skill::execute()` (and the `resolve_*`/preview helpers that surface other users), enforce a visibility capability per candidate at the candidate's user context — e.g. drop fields the actor cannot see under `moodle/site:viewuseridentity` / `moodle/user:viewdetails`, or restrict results to users sharing a course with the actor when they lack `moodle/user:viewalldetails`. At minimum, raise the skill capability from `$teacherskills` to a narrower set, or trim the payload to non-identifying fields for non-managers.

### [12-F02] 🟡 MEDIUM · D3 Structure · classes/local/wizard/wizard/skills/recreate_skill_catalog_skill.php:80
**What:** `wizard.recreate_skill_catalog` is R2 (broad write) but declares no explicit `context_scopes`, so it silently inherits the `['module']` default — mislabelling a **site-wide** action (rebuilds the global skill-catalog embeddings CSV) as module-scoped.
**Evidence:** `get_schema()` returns no `prompt_meta.context_scopes`; `enrich_schema_with_prompt_meta()` (core_skill_base.php:106) only adds `input_fields_for_prompt`/`anchor_fields`, never `context_scopes`; `base_skill::prompt_contract_payload()` (base_skill.php:408) then defaults to `['module']`. `skill_contract_validator::validate_skill_metadata` (:197) requires R2/R3 to declare scopes but is satisfied by the injected `['module']`, so validation passes with a wrong scope.
**Impact:** The skill remains activatable, but its declared scope misrepresents blast radius to governance/preview and to any context-scope-based reasoning. The action itself is gated (R2 confirmation + `bookingextension/agent:skill_wizard_recreate_skill_catalog`, granted to teacher), so no privilege escalation — the risk is governance accuracy and a teacher being able to queue a global, cost-bearing embeddings rebuild from a module context.
**Compensating control:** R2 forces explicit confirmation; the task is idempotent/deduped (`reschedule_or_queue_adhoc_task`). No data corruption.
**Recommendation:** Declare `'context_scopes' => ['system']` in the schema's `prompt_meta` and consider moving the skill capability to `$managerskills` (a catalog rebuild is an admin/governance operation, not a teacher one).

### [12-F03] 🟢 LOW · D2 Moodle API · multiple (diagnose_permissions_skill.php:230/243/256, diagnose_notifications_skill.php:207/223, search_skills_skill.php:65–71/159/183, add_activity_skill.php:482/487/527, update_activity_skill.php:414/444, generate_questions_skill.php:432/437, …)
**What:** Many user-facing observation/clarification/error strings are hard-coded English literals instead of `get_string`, against the project's "every user-visible string via get_string, bound to outputlang" rule.
**Evidence:** e.g. `return $this->error_result('That user no longer exists.', …)`, `'More than one section matches "' . $query . '". Which one did you mean?'`, search_skills' `match(): 'Skill discovery is unavailable because embeddings are disabled.'` and `'Search query must not be empty.'`.
**Impact:** These strings reach the LLM as observations/clarifications, not the final user reply. The synchronizer re-renders the final answer in the user's language (per `feedback_synchronizer_always_answers`), so end-user-visible impact is low; the literals are effectively internal planner-facing text. Still a maintainability/i18n-consistency gap and a phpcs `moodle.Strings` risk.
**Compensating control:** Final-answer language is owned by the synchronizer; the diagnose/course skills already route these through `needs_clarification` observations rather than terminal user text.
**Recommendation:** Move user-facing literals to `get_string`/`localized_string($key, …, $outputlang)`, matching the pattern already used by `search_users`/`search_courses`/the memory skills.

### [12-F04] ⚪ INFO · D1 Security · classes/local/wizard/course/skills/add_quiz_skill.php:110
**What:** `add_quiz::get_required_native_capabilities()` returns only `['moodle/course:manageactivities']`, but the skill actually requires more — its own inline check (:294–295) also demands `mod/quiz:addinstance`, and generation demands `moodle/question:add` (:323).
**Evidence:** The declared list drives the engine's Gate-2 `require_native_capabilities()`; the additional caps are enforced only by the inline `has_capability(...)` block inside the skill.
**Impact:** None in practice — the inline checks close the gap, so no privilege bypass. But a maintainer relying on the declared list (or the cross-context operating-context re-check that uses the declared caps) would under-count the requirement.
**Compensating control:** Inline `has_capability` enforcement at the operating context covers `mod/quiz:addinstance` and `moodle/question:add`.
**Recommendation:** Add `mod/quiz:addinstance` to `get_required_native_capabilities()` so the declared contract matches the enforced reality (leave `moodle/question:add` inline since it is conditional on generation).

### [12-F05] 🟢 LOW · D4 Duplication · course/skills/{add,update}_activity_skill.php, course/skills/{add,update}_quiz_skill.php; forget_skill.php:333 vs diagnostic clarification helpers
**What:** The four mutating course skills share a large, near-identical skeleton (context resolution, native-cap check, MAX_RETRIES create/update loop, clarification/observation builders); the `clarification_issues()` / hard-coded clarification-string pattern is re-implemented across `forget_skill` and several diagnose/course skills.
**Evidence:** Parallel `get_required_native_capabilities`/`has_capability('moodle/course:manageactivities', …)` blocks and structurally identical "more than one matches … which one?" clarification builders.
**Impact:** Maintenance drift risk (a fix to one clarification/format path must be mirrored in the others). No correctness defect today.
**Compensating control:** Shared foundation services (`activity_creation_service`, `module_form_contract`, `course_context_loader`) already factor the heavy lifting; the duplication is in the thin skill shells.
**Recommendation:** Hoist the common mutating-course-skill scaffold (cap check + retry loop + clarification formatting) into a shared `course_mutation_skill_base`/trait; centralise the clarification-issue helper.

## C. Per-file / per-method checklist

#### `services/user_memory_service.php`  (class `user_memory_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (single DB chokepoint, parameterised, ownership-checked)
- methods: [x] `valid_scopes()` [x] `add()` (length/count/total budget, dedupe) [x] `get_all()` (userid-filtered) [x] `delete()` (ownership-checked id+userid) [x] `find()` (in-PHP filter on owned rows) [x] `get_for_scope()` [x] `parse_scopes()` [x] `normalize_scopes()` [x] `normalize()`

#### `wizard/skills/remember_skill.php`  (class `remember_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — uses engine `$userid`, never input
- methods: [x] `__construct` [x] `get_name` [x] `get_schema` [x] `get_example_input` [x] `get_message_triggers` [x] `check_structure` [x] `execute`

#### `wizard/skills/forget_skill.php`  (class `forget_skill`, R2)
- [x] D1 [x] D2 [x] D3 [ ] D4 (12-F05) [x] D5 [x] D6 — explicit-id/query/all all ownership-checked; never silent multi-delete
- methods: [x] `__construct` [x] `describe_proposed_action` [x] `get_name`/`get_schema`/`get_example_input`/`get_message_triggers` [x] `check_structure` [x] `run_preflight` [x] `execute` [x] `clarification_issues` [x] `format_candidates`

#### `wizard/skills/list_memories_skill.php`  (class `list_memories_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — own store only
- methods: [x] `__construct` [x] `get_name`/`get_schema`/`get_message_triggers` [x] `execute`

#### `wizard/skills/recall_memory_skill.php`  (class `recall_memory_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — user-isolated store queries; token-to-token re-anchor (no clear-text PII); `query` declared sensitive
- methods: [x] `__construct` [x] `set_runtime_threadid` (engine-called) [x] `get_name`/`get_schema`/`get_example_input` [x] `check_structure` [x] `get_message_triggers` [x] `execute` [x] `resolve_date_window` [x] `resolve_user_timezone` [x] `get_sensitive_input_fields` [x] `build_memory_observation_text`

#### `wizard/skills/explain_docs_skill.php`  (class `explain_docs_skill`, R0, `requires_explicit_activation`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — output is plain-text observations (no `format_text`); preview HTML via `doc_markdown_preview_renderer`→`ai_get_doc_content` (out of scope) which escapes errors
- methods: [x] `__construct` [x] `get_name` [x] `requires_explicit_activation` [x] `get_schema`/`get_example_input`/`get_message_triggers`/`get_contextual_prompt_packs` [x] `check_structure` [x] `execute` [x] `build_doc_result` [x] `build_observation_full` [x] `build_doc_url` (is_readable guard) [x] `error_result` [x] `create_docs_lookup_service` [x] `get_result_preview`

#### `wizard/skills/search_skills_skill.php`  (class `search_skills_skill`, R0)
- [x] D1 [ ] D2 (12-F03 hard-coded strings) [x] D3 [x] D4 [x] D5 [x] D6
- methods: [x] `__construct` [x] `set_discovery_provider` (engine-called) [ ] `discovery_failure_message` (12-F03) [x] `get_name`/`get_schema`/`get_example_input` [x] `get_passthrough_construction_field` [x] `get_message_triggers` [ ] `check_structure` (12-F03) [ ] `execute` (12-F03 literals; logic clean)

#### `wizard/skills/list_skills_skill.php`  (class `list_skills_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — deny-reason labels localised; introspection injected
- methods: [x] `__construct` [x] `set_introspection_provider` (engine-called) [x] `get_name`/`get_schema`/`get_example_input`/`get_message_triggers`/`get_contextual_prompt_packs` [x] `check_structure` [x] `execute` [x] `build_debug_summary` [x] `build_user_summary` [x] `describe_deny_reason` [x] `build_unavailable_action_detail` [x] `build_user_capabilities`

#### `wizard/skills/recreate_skill_catalog_skill.php`  (class `recreate_skill_catalog_skill`, R2)
- [x] D1 [x] D2 [ ] D3 (12-F02 scope) [x] D4 [x] D5 [x] D6
- methods: [x] `__construct` [x] `get_name` [x] `describe_proposed_action` [ ] `get_schema` (12-F02 missing context_scopes) [x] `get_example_input`/`get_message_triggers` [x] `check_structure` [x] `execute` (queues adhoc task)

#### `wizard/skills/scaffold_skill.php`  (class `scaffold_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — in-memory ZIP; preview values `s()`-escaped; data-URI download
- methods: [x] `__construct` [x] `get_name`/`get_schema`/`get_example_input` [x] `check_structure` [x] `run_preflight` [x] `execute` [x] `get_result_preview` [x] `get_sensitive_input_fields` [x] `get_message_triggers`

#### `services/scaffold/skill_template_generator.php`  (class `skill_template_generator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — `make_request_directory()`, `php_single_quote` escapes `\`+`'`, slugified identifiers, namespace-ownership validated
- methods (all static): [x] `generate` [x] `normalize_spec` [x] `validate_spec` [x] `build_skill_php` + all `skill_template_*` emitters [x] `render_properties`/`render_quoted_list` [x] `build_access_snippet`/`build_lang_snippet`/`build_readme` [x] `build_zip` [x] `normalize_properties`/`normalize_triggers`/`normalize_string_list` [x] `derive_namespace` [x] `slugify` [x] `risk_constant_name` [x] `php_single_quote`

#### `core/skills/core_skill_base.php`  (abstract `core_skill_base`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — shared read helpers; `build_user_payload`/`build_user_observation_full` are the PII surface consumed by 12-F01 (the gap is the *caller's* missing gate, not this base)
- methods: [x] `get_output_language` [x] `localized_string` [x] `build_skill_debug_message` [x] `enrich_schema_with_prompt_meta` (note: does not inject context_scopes — see 12-F02) [x] `stringify_debug_value` [x] `resolve_userid` [x] `resolve_courseid` [x] `course_input_targets_operating_context` [x] `resolve_readonly_course_context_id` [x] `run_preflight` [x] `build_user_payload` [x] `build_user_courses_payload` [x] `build_user_roles_payload` [x] `extract_custom_profile_fields` [x] `search_user_candidates_for_preview` [x] `search_course_candidates_for_preview` (visibility-aware via `core_course_category::search_courses`) [x] `list_course_candidates_for_preview` (`can_view_course_info` filter) [x] `count_active_course_enrolments` [x] `build_user_observation_full` [x] `format_*` helpers

#### `core/skills/get_current_user_skill.php`  (class `get_current_user_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — self only (`$USER`); preview escaped via `s()`
- methods: [x] `__construct`/`get_name`/`get_schema`/`get_example_input`/`check_structure`/`get_message_triggers`/`get_contextual_prompt_packs` [x] `execute` [x] `get_result_preview`

#### `core/skills/search_users_skill.php`  (class `search_users_skill`, R0)
- [ ] D1 (12-F01) [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — preview `s()`-escaped + profileurl links; **execute returns full PII without per-target visibility gate**
- methods: [x] `__construct`/`get_name`/`get_schema`/`get_example_input`/`get_message_triggers`/`get_contextual_prompt_packs` [x] `check_structure` [ ] `execute` (12-F01) [x] `normalize_query_input` [x] `build_query_retry_hint` [x] `get_result_preview`

#### `core/skills/diagnose_permissions_skill.php`  (class `diagnose_permissions_skill`, R0)
- [x] D1 [ ] D2 (12-F03) [x] D3 [ ] D4 (12-F05) [x] D5 [x] D6 — cross-user gate `moodle/role:review` (:250); parameterised `get_in_or_equal` override SQL (:327–335)
- methods: [x] `__construct`/`get_name`/`get_required_context_level`/`is_read_only`/`get_schema`/`get_example_input`/`get_message_triggers`/`get_contextual_prompt_packs`/`check_structure` [x] `execute` (resolve→gate→dispatch) [x] `diagnose_capability` [x] `diagnose_roles` [x] `suggest_capabilities` [x] `permission_label` [x] `build_result` [x] `get_result_preview` [x] `error_result`

#### `core/skills/diagnose_notifications_skill.php`  (class `diagnose_notifications_skill`, R0)
- [x] D1 [ ] D2 (12-F03) [x] D3 [x] D4 [x] D5 [x] D6 — cross-user gate `moodle/user:viewalldetails` at the target user context (:214)
- methods: [x] `__construct`/`get_*`/`check_structure` [x] `execute` (gate then read account flags) [x] result/observation builders [x] `error_result`

#### `course/skills/diagnose_user_in_course_skill.php`  (class `diagnose_user_in_course_skill`, R0)
- [x] D1 [ ] D2 (12-F03) [x] D3 [x] D4 [x] D5 [x] D6 — target-user/course resolved; cross-user gate enforced per aspect inside the delegated diagnosers (`enrolreview`/`role:review`/`progress:view`/`grade:viewall`), not in the skill shell (acceptable, defense one layer down)
- methods: [x] `__construct`/`get_*`/`check_structure` [x] `execute` [x] enrolment-overview/inventory-observation/result builders [x] `error_result`

#### `course/skills/search_courses_skill.php`  (class `search_courses_skill`, R0)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — visibility-aware listing (`can_view_course_info`), localised strings
- methods: [x] `__construct`/`get_*`/`check_structure` [x] `execute` [x] `resolve_query`/observation builders

#### `course/skills/analyze_course_structure_skill.php`  (class `analyze_course_structure_skill`, R0)
- [x] D1 [ ] D2 (12-F03 a few literals) [x] D3 [x] D4 [x] D5 [x] D6 — `can_access_course` gate (:248) + per-user `get_fast_modinfo` filtering in the service
- methods: [x] `__construct`/`get_*`/`check_structure` [x] `execute` [x] flag/observation builders [x] `error_result`

#### `course/skills/add_activity_skill.php`  (class `add_activity_skill`, R2)
- [x] D1 [ ] D2 (12-F03) [x] D3 [ ] D4 (12-F05) [x] D5 [x] D6 — native cap `moodle/course:manageactivities` declared + inline `has_capability` (:310)
- methods: [x] `__construct`/`get_required_context_level`/`get_target_context_level`/`get_required_native_capabilities`/`get_schema`/`check_structure` [x] preflight/execute [x] resolution + clarification + result builders

#### `course/skills/update_activity_skill.php`  (class `update_activity_skill`, R2)
- [x] D1 [ ] D2 (12-F03) [x] D3 [ ] D4 (12-F05) [x] D5 [x] D6 — native cap declared + inline check (:265)
- methods: [x] context/target/native-cap/schema/structure [x] preflight/execute [x] resolution/clarification/diff builders

#### `course/skills/add_quiz_skill.php`  (class `add_quiz_skill`, R2)
- [x] D1 [ ] D2 (12-F03) [x] D3 [ ] D4 (12-F05) [x] D5 [x] D6 — inline checks `manageactivities`+`mod/quiz:addinstance` (:294) + `question:add` on generate (:323); declared native caps incomplete (12-F04, INFO)
- methods: [x] context/target/native-cap/schema/structure [x] preflight/execute [x] stage/result builders

#### `course/skills/update_quiz_skill.php`  (class `update_quiz_skill`, R2)
- [x] D1 [ ] D2 (12-F03) [x] D3 [ ] D4 (12-F05) [x] D5 [x] D6 — inline `manageactivities` (:296) + conditional `question:add` (:326)
- methods: [x] context/target/native-cap/schema/structure [x] preflight/execute [x] resolution/clarify/result builders

#### `question/skills/generate_questions_skill.php`  (class `generate_questions_skill`, R2)
- [x] D1 [ ] D2 (12-F03) [x] D3 [x] D4 [x] D5 [x] D6 — native cap `moodle/question:add` declared + inline check (:362); count capped by `MAX_COUNT`; cross-context targeting (coursequery/courseid)
- methods: [x] context/target/native-cap/schema/structure [x] preflight/execute [x] category resolution + retry/import builders

## D. Go-live blockers from this section
- **None are hard blockers.** The one item that should gate launch by policy:
  - **12-F01 (HIGH):** `core.search_users` exposes arbitrary users' contact PII to any teacher-archetype holder with no per-target visibility capability check — the only read skill in scope without a cross-user gate. Fix (per-candidate `viewuseridentity`/`viewdetails` trim or narrower skill capability) or obtain an explicit maintainer waiver before go-live.
- Recommended pre/just-post launch (not blockers): 12-F02 (recreate_skill_catalog scope + move to manager), 12-F04 (declare `mod/quiz:addinstance`).
