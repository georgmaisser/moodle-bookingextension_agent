# Audit Section 13 — Activities, Questions & Preview Services

**Scope:** `classes/local/wizard/services/activities/*` (8), `classes/local/wizard/services/questions/*` (4), `classes/local/wizard/services/{activity_preview_builder,proposed_action_preview,preview_passthrough,preview_support}.php` (4)  ·  **Files audited:** 16  ·  **Methods audited:** 79
**Arch chapter(s):** docs/architecture/16-support-services.md (general support seam; this cluster is skill-layer service support)  ·  **Flowchart nodes:** preview-as-data contract (`get_result_preview()` → `{type, html, js, payload}`)
**Auditor verdict:** ⚠️ issues (no blocker)

This is a skill-layer service cluster: every service takes an already-resolved `$course` / `$coursecontext` / `$userid` from its calling skill. Capability gating (Gate 1 use/skill + Gate 2 native `mod/<modname>:addinstance`, `moodle/course:manageactivities`, `mod/quiz:addinstance`, `moodle/question:add`) is enforced in the skills' `run_preflight()` (verified in `course/skills/add_activity_skill.php:310`, `add_quiz_skill.php:294-325`, `question/skills/generate_questions_skill.php:362`). These services are the validated execution core invoked *after* that gating; their own duty is to not widen what the user may do. They do not, with the qualified exceptions below.

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | No direct external entry; all `$DB` calls parameterised; GIFT temp file via `make_request_directory()` (not user-controlled); HTML built with `html_writer`+`s()`; capability re-checks live in callers. Two defended observations (13-F03, 13-F06). |
| D2 Moodle API      | pass | Uses `add_moduleinfo`/`update_moduleinfo`, headless `mod_form`, `qformat_gift`, `question_engine`, `get_fast_modinfo`, `format_string`/`format_text`, `get_string`/`get_string_manager`. phpcs not runnable in this env (see note). |
| D3 Structure       | issues | Reflection on `moodleform::$_form` (documented, contained); no dead code; one engine-coupling note via `conversation_store` use inside two services (13-F04). |
| D4 Duplication     | issues | `activity_preview_builder` private helpers duplicate `preview_support` public helpers near-verbatim (13-F01). `match_category`/`resolve_module_name`/`resolve_placement` share an exact/substring matcher shape (13-F05). |
| D5 Flowchart       | pass | Preview-as-data contract honoured: services emit `{type, html, js, payload}` data; `preview_passthrough`/`proposed_action_preview` forward only, never render (confirmed). |
| D6 Docs coverage   | issues | Chapter 16 documents the generic support seam but does not mention this activities/questions/preview service cluster at all (13-F02). |

## B. Findings

### [13-F01] 🟡 MEDIUM · D4 Duplication · classes/local/wizard/services/activity_preview_builder.php:286-348 vs services/preview_support.php:40-149
**What:** `activity_preview_builder` re-implements, as private statics, the exact helpers that already exist as public statics in `preview_support`: `lang()`, `str()`, `push()`, `text_value()`/`text()`, `positive_int()`/`posint()`, `is_truthy()`/`truthy()`, `course_name()`.
**Evidence:** `activity_preview_builder::str()` (line 343-348) is byte-for-byte the same body as `preview_support::str()` (line 53-58); `is_truthy()` (314-322) equals `truthy()` (108-116); `course_name()` (185-196) equals `preview_support::course_name()` (124-134) modulo a `format_string` inlining; `lang()`/`push()` identical.
**Impact:** Two copies of the language-forcing and row-pushing logic drift independently; a fix to `str()`'s lang handling must be made twice.
**Compensating control:** Both are pure data helpers with no security weight; behaviour currently matches.
**Recommendation:** Make `activity_preview_builder` delegate to `preview_support` (it is already in the same namespace) and delete the private duplicates.

### [13-F02] 🟡 MEDIUM · D6 Docs coverage · docs/architecture/16-support-services.md
**What:** The arch chapter assigned to this scope ("16 · Support services") describes only the cross-cutting engine support seam (anonymizer, language policy, trigger registry, error classifier, domain hooks) and never mentions the activities / questions / preview service cluster audited here.
**Evidence:** Chapter 16 §1-7 enumerate `privacy_anonymizer`, `language_policy_service`, `message_trigger_registry`, `ai_error_classifier`, the issue-code/normalizer interfaces and four small helpers; none of `module_form_contract`, `activity_creation_service`, `quiz_question_service`, `question_import_service`, `course_structure_service`, `activity_preview_builder`, `preview_passthrough` or `proposed_action_preview` appear in any architecture chapter.
**Impact:** A material body of write-path behaviour (headless mform validation, GIFT import, quiz question sourcing, preview-as-data) is undocumented; the preview-as-data contract is referenced by skill chapters but has no owning architecture section.
**Compensating control:** Each file carries a thorough class-level docblock; the skills' own chapters (14 skill layer) touch the contract.
**Recommendation:** Add an architecture section (or extend 14-skill-layer) describing the activity/question/preview service layer and the preview-as-data contract `{type, html, js, js_module, payload}`.

### [13-F03] 🟡 MEDIUM · D1 Security · classes/local/wizard/services/questions/question_generation_service.php:62-138
**What:** The source document text handed to the LLM is passed through verbatim with no upper length bound; only the question *count* is capped (`MAX_COUNT = 50`).
**Evidence:** `generate_gift()` calls `build_prompt($sourcetext, …)` and `build_prompt` interpolates `trim($sourcetext)` directly into the prompt body (line 134) with no `core_text::strlen`/`substr` guard; the only clamp is `$count = max(1, min(self::MAX_COUNT, …))` (line 101).
**Impact:** A very large uploaded document (the PDF-extraction path) can blow the provider's context window, causing a hard provider error rather than a graceful "document too large" message, and inflating token cost per attempt across the 3-attempt retry loop.
**Compensating control:** The retry loop and provider/token limits bound the blast radius to a failed turn (not data corruption); the document extraction itself lives in the skill layer (section 14), so a length guard could equally land there.
**Recommendation:** Add a defensive `core_text::substr` cap (e.g. configurable max source chars) in `build_prompt`, or validate length in the calling skill before generation.

### [13-F04] 🟢 LOW · D3 Structure · classes/local/wizard/services/activities/quiz_question_service.php:259-261 ; services/questions/question_generation_service.php:21
**What:** `quiz_question_service::generate_into_bank()` news up a `conversation_store` and pulls the active thread to derive a `threadid` purely for debug-logging context, coupling a domain service to the conversation engine.
**Evidence:** `$store = new conversation_store(); $thread = $store->get_active_thread($userid, $ambientcontextid); $threadid = $thread ? (int)$thread->id : 0;` — the only use of `$threadid` is the debug-log argument to `generate_gift()`.
**Impact:** Minor layering smell; the service knows about the conversation store to obtain a logging id. Not a behavioural defect.
**Compensating control:** `threadid` defaults to 0 when unknown and is only used for debug logging; no functional dependency.
**Recommendation:** Pass `threadid` down from the skill (which already has it) instead of re-deriving it here.

### [13-F05] 🟢 LOW · D4 Duplication · module_catalog_service.php:96-126 ; section_resolver_service.php:106-133 ; quiz_question_service.php:412-428
**What:** Three services repeat the same "exact (case-insensitive) match → substring match on a label" resolution shape over a list of `{id, name}`-style rows.
**Evidence:** `module_catalog_service::resolve_module_name`, `section_resolver_service::resolve_placement` (name branch), and `quiz_question_service::match_category` each lowercase a needle via `\core_text::strtolower(trim(...))`, scan for an exact hit, then fall back to `str_contains`.
**Impact:** Three independent copies of the matcher; behaviour can drift (e.g. one already added `str_contains` on `modname`, the others only on the label).
**Compensating control:** Each variant is small and currently correct.
**Recommendation:** Extract a shared `name_matcher` helper (exact-then-substring over `[id => label]`).

### [13-F06] 🟢 LOW · D1 Security / D3 · classes/local/wizard/services/activities/quiz_question_service.php:352-381
**What:** `add_random_from_category()` reports `'added' => max(1, $count)` unconditionally after `add_random_questions`, even though the named category may hold fewer real questions than `$count`.
**Evidence:** `$quizobj->get_structure()->add_random_questions(0, max(1, $count), $filtercondition);` then `return ['added' => max(1, $count), …]` (line 380) — the returned count is the *requested* number, not a verified one.
**Impact:** The confirmation/result message can overstate how many questions the random slots will draw if the category is under-populated (random slots are placeholders; the count is the slot count, which is honest for slots but misleading if read as "distinct questions added").
**Compensating control:** Random questions are slot definitions; N slots *are* created, so the count is technically accurate at the slot level. Read-only display impact only.
**Recommendation:** Document that the count is the random-slot count, or cross-check the category's available count and report `min`.

### [13-F07] ⚪ INFO · D2 Moodle API · whole scope
**What:** phpcs (`--standard=moodle`) could not be executed in this audit environment (no `phpcs` binary on PATH or in `vendor/bin`).
**Evidence:** `which phpcs` / `vendor/bin/phpcs` both absent.
**Impact:** D2 coding-style verdict for this section is by-inspection only.
**Compensating control:** All 16 files carry the GPL header + `declare(strict_types=1)`; namespaced PSR-4 classes (no `defined('MOODLE_INTERNAL')` needed — correct for autoloaded classes); the two `phpcs:ignore`/`phpcs:disable` blocks (GIFT backtick regex; `ForbiddenGlobalUse` in `question_preview_renderer`) are present and justified inline.
**Recommendation:** Run `vendor/bin/phpcs --standard=moodle` on the cluster in CI before launch.

### [13-F08] ⚪ INFO · D1 Security · module_form_contract.php — headless mform handling
**What:** Confirmed the headless mform path does NOT bypass validation.
**Evidence:** `validate()`/`validate_update()` build the real `mod_<modname>_mod_form` (lines 309-342, 209-242), read `_required` and run the form's own `validation()` (`collect_form_errors`, 252-272); `build_prepared_moduleinfo` starts from `prepare_new_moduleinfo_data()` (the same scaffold the UI uses) and merges the form's `exportValues()` over it. The single reflection access to `moodleform::$_form` (`quickform()`, 430-439) is contained, guarded and non-fatal. `push_build_globals`/`pop_build_globals` correctly save+restore `$PAGE`/`$COURSE`. The module whitelist (`module_catalog_service::WHITELIST` = page/url/label/book/folder/forum) gates which forms are ever built, and `course_allowed_module` + native `addinstance` filter the catalog by-the-user.
**Impact:** None — this is the documented, safe contract.
**Recommendation:** None.

### [13-F09] ⚪ INFO · D1 Security · question_import_service.php / question_preview_renderer.php — GIFT + preview safety
**What:** Confirmed the GIFT import and inline question preview paths are input-safe and side-effect-correct.
**Evidence:** `import_gift` writes the model GIFT to a request-scoped temp file from `make_request_directory()` (path not user-influenced), hands it to core `qformat_gift` with `setStoponerror(true)`, and rolls back partial imports via `question_delete_question` on failure (lines 90-130). `question_preview_renderer` renders read-only (`readonly=true`, marks/history HIDDEN), wraps everything in `ob_start`/`ob_end_clean`, builds a transient per-question `question_usage`, and collects render-time JS via the documented fragment pattern. Both `activity_preview_renderer` and `course_structure_preview` escape every value with `s()` and harden output with `ob_*`.
**Impact:** None.
**Recommendation:** None.

## C. Per-file / per-method checklist

#### `services/activities/activity_creation_service.php`  (class `activity_creation_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `create(stdClass,stdClass)` — transactional `add_moduleinfo`, rollback on throw; clean
  - [x] `update(stdClass,stdClass,stdClass)` — transactional `update_moduleinfo`; clean
  - [x] `private resolve_activity_url()` — guarded, falls back to course page; clean

#### `services/activities/module_form_contract.php`  (class `module_form_contract`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — see 13-F08 (confirmed safe)
- methods:
  - [x] `validate()` — clean
  - [x] `build_prepared_moduleinfo()` — clean
  - [x] `validate_update()` — clean
  - [x] `build_prepared_update_moduleinfo()` — clean
  - [x] `private build_update_form()` — clean
  - [x] `private collect_form_errors()` — runs real required + `validation()`; clean
  - [x] `private merge_exported()` — editor-field handling correct; clean
  - [x] `private build_form()` — clean
  - [x] `private push_build_globals()` / `pop_build_globals()` — save/restore `$PAGE`/`$COURSE`; clean
  - [x] `private element_defaults()` / `normalize_for_validation()` — clean
  - [x] `private quickform()` — contained guarded reflection; clean
  - [x] `private apply_inputs()` / `normalize_setting_key()` / `set_editor()` / `value_is_empty()` — clean

#### `services/activities/activity_preview_renderer.php`  (class `activity_preview_renderer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (see 13-F09)
- methods:
  - [x] `render()` — ob-hardened; clean
  - [x] `private build_html()` — all values `s()`-escaped; clean

#### `services/activities/course_structure_preview.php`  (class `course_structure_preview`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `render()` — ob-hardened, returns null when empty; clean
  - [x] `private build_html()` / `section_html()` / `activity_html()` — `s()`-escaped; clean
  - [x] `private section_badges()` / `activity_badges()` / `restriction_note()` / `badge()` — `get_string` badges, escaped; clean

#### `services/activities/course_structure_service.php`  (class `course_structure_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `analyze()` — visibility via `get_fast_modinfo($course,$userid)` only; capability-safe; clean
  - [x] `private build_section_node()` / `build_activity_node()` — uservisible gate correct; clean
  - [x] `private activity_intro_text()` — 1 guarded DB read; clean
  - [x] `private restriction_text()` / `format_to_text()` / `flatten()` / `group_mode_label()` — bounded text; clean

#### `services/activities/module_catalog_service.php`  (class `module_catalog_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (13-F05) n/a D5 [x] D6 — file-level
- methods:
  - [x] `list_addable_modules()` — installed+enabled+`course_allowed_module` (Gate 2 by-user); clean
  - [ ] `resolve_module_name()` — see 13-F05 (matcher duplication)
  - [x] `is_whitelisted()` — clean

#### `services/activities/section_resolver_service.php`  (class `section_resolver_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (13-F05) n/a D5 [x] D6 — file-level
- methods:
  - [x] `list_sections()` — read-only via modinfo; clean
  - [ ] `resolve_placement()` — see 13-F05 (matcher shape)
  - [x] `section_exists()` — clean

#### `services/activities/quiz_question_service.php`  (class `quiz_question_service`)
- [x] D1 [x] D2 [ ] D3 (13-F04) [ ] D4 (13-F05) n/a D5 [x] D6 — file-level
- methods:
  - [x] `static ensure_quiz_feedback()` — normalises feedback bands; clean
  - [x] `resolve_source()` — mode dispatch + clarify; `qtypes` intersected with allow-list; clean
  - [x] `list_available_categories()` — delegates to resolver (writable-only); clean
  - [x] `static build_source_clarification()` — string assembly; clean
  - [x] `add_questions_to_quiz()` — `MUST_EXIST` lookups, guarded; clean
  - [ ] `generate_into_bank()` — see 13-F04 (conversation_store coupling for log id)
  - [x] `reference_existing()` — clean
  - [x] `private add_by_ids()` — filters out `random` qtype; parameterised `get_field`; clean
  - [ ] `private add_random_from_category()` — see 13-F06 (count reporting)
  - [x] `private reference_ids_into_quiz()` — `quiz_add_quiz_question` + recompute sumgrades; clean
  - [ ] `private match_category()` — see 13-F05 (matcher shape)

#### `services/questions/question_bank_target_resolver.php`  (class `question_bank_target_resolver`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `resolve_for_context()` — course-context guard; idempotent get-or-create; clean
  - [x] `list_writable_targets()` — `moodle/question:add` per bank context, `parent<>0` filter; parameterised `get_records_select`; clean
  - [x] `resolve_selected_target()` — re-validates chosen category is a writable target (anti-IDOR); clean
  - [x] `private count_category_questions()` — parameterised; guarded; clean

#### `services/questions/question_generation_service.php`  (class `question_generation_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `__construct()` — clean
  - [ ] `generate_gift()` — see 13-F03 (unbounded source length)
  - [ ] `static build_prompt()` — see 13-F03; count clamped to MAX_COUNT; clean otherwise
  - [x] `static extract_gift()` — fence-stripping regex, extraction only (no execution); clean

#### `services/questions/question_import_service.php`  (class `question_import_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (see 13-F09)
- methods:
  - [x] `import_gift()` — request-dir temp file, `setStoponerror`, rollback on fail, parameterised category lookup; clean

#### `services/questions/question_preview_renderer.php`  (class `question_preview_renderer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (see 13-F09)
- methods:
  - [x] `render()` — read-only options, MAX_RENDER cap, ob-hardened, fragment-JS collection; clean
  - [x] `private static build_display_options()` — marks/history HIDDEN, answers VISIBLE; clean

#### `services/activity_preview_builder.php`  (class `activity_preview_builder`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (13-F01) [x] D5 [ ] D6 (13-F02) — file-level
- methods:
  - [x] `static add_activity_descriptor()` / `update_activity_descriptor()` / `add_quiz_descriptor()` / `update_quiz_descriptor()` — data-only, `get_string` text; clean
  - [x] `private static changed_basic_rows()` / `questions_summary()` / `module_type()` / `section_label()` / `target_title()` / `activity_name()` / `title()` — clean
  - [ ] `private static lang()/str()/push()/text_value()/positive_int()/is_truthy()/course_name()` — see 13-F01 (duplicate of preview_support)

#### `services/proposed_action_preview.php`  (class `proposed_action_preview`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `static build_preview_json()` — best-effort, skill-agnostic, `json_encode` guarded; clean
  - [x] `private static sanitize_rows()` — clean

#### `services/preview_passthrough.php`  (class `preview_passthrough`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `static resolve_preview_json()` — forwards precomputed previews; thread-metadata accumulation; clean
  - [x] `private static extract_first_preview()` / `first_preview_in_entries()` / `merge_with_accumulated()` — type-guarded merge, payload union; clean

#### `services/preview_support.php`  (class `preview_support`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (canonical home for 13-F01)
- methods:
  - [x] `static lang()/str()/push()/text()/posint()/truthy()/course_name()/list_value()` — data-only helpers; clean

## D. Go-live blockers from this section
None. No BLOCKER or HIGH findings. Recommended pre-launch cleanups: 13-F01 (helper duplication), 13-F02 (arch-doc coverage gap), 13-F03 (source-length guard). All are MEDIUM/LOW with compensating controls; none gate launch.
