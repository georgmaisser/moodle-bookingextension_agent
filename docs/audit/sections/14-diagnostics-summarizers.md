# Audit Section 14 — Diagnostics & Summarizers

**Scope:** `classes/local/wizard/diagnostics/*` (3 builders + 4 aspect diagnosers), `classes/local/wizard/summarizer/*` (4 contributors), `classes/local/wizard/result_payload_summarizer.php`, `classes/local/wizard/interfaces/summarizer/result_summary_contributor_interface.php`, `classes/local/wizard/interfaces/result_summary_provider_interface.php`  ·  **Files audited:** 11  ·  **Methods audited:** 47
**Arch chapter(s):** docs/architecture/16-support-services.md  ·  **Flowchart nodes:** SUPPORT, OBS_ACCUM, OB_OUT (`result_payload_summarizer::for_observation`)
**Auditor verdict:** ⚠️ issues (no blocker)

> NOTE: `skill_result_summary_provider_interface.php` (referenced by `result_payload_summarizer`) was read for completeness but lives under `interfaces/` (not the two interface files explicitly named in scope); it is clean and covered file-level below.

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | Cross-user gates are correct and read-only. Each aspect diagnoser enforces an actor-side capability (`moodle/role:review`, `moodle/course:enrolreview`, `moodle/grade:viewall`+`grade:view`, `report/progress:view`) before reading the TARGET user's data; grades respects hidden grades for self-requests; no IDOR, no SQL injection (all `$DB` placeholders parameterised), preview output escaped with `s()`/`html_writer`. The one residual is that the summarizer emits raw email/name and relies on the runtime anonymizer boundary (14-F04, INFO — confirmed wired). |
| D2 Moodle API      | issues | Diagnoser check/finding strings are hard-coded English (14-F01) — they render raw to the user in the checklist preview, bypassing the string API; the C2 sweep missed this. |
| D3 Structure       | issues | `result_payload_summarizer` carries domain heuristics in the engine namespace (`booking`→`bookingextension_agent` map; booking-options awareness) (14-F03, LOW). No dead code — every file has a real caller (verified). |
| D4 Duplication     | pass | The builders exist *because* per-skill copy-paste was consolidated; the four contributors are cohesive. Minor near-dup of category detection between contributors and the summarizer's own switch (14-F05, INFO). |
| D5 Flowchart       | pass | Behaviour matches `OBS_ACCUM`/`OB_OUT`: `for_observation()` feeds the observation loop and is masked by `privacy_anonymizer` downstream, exactly as the diagram shows. Diagnosers are skill-layer, not flowchart nodes. |
| D6 Docs coverage   | issues | Chapter 16 does not mention the diagnostics or summarizer subsystems at all (14-F06, LOW). The summarizer's own docblock claims a `for_client()` method that does not exist (14-F02, LOW). |

## B. Findings

### [14-F01] 🟡 MEDIUM · D2 Moodle API · classes/local/wizard/diagnostics/aspects/*.php
**What:** Every diagnoser check name and finding text is a hard-coded English literal, not routed through `get_string`, and is rendered verbatim to the user in the checklist preview.
**Evidence:** e.g. `access_aspect_diagnoser.php:76` `'Course is visible'`, `:85` `'The course is set to hidden; only users with "view hidden courses" can enter.'`, `:103` `'Enrolled but not active'`; `enrolment_aspect_diagnoser.php:78` `'No enrolment methods on the course'`; `grades_aspect_diagnoser.php:96` `'Gradebook hidden from students'`; `progress_aspect_diagnoser.php:86` `'Completion tracking disabled'`. These rows flow into `diagnostic_checklist_preview::build_html()` which renders them via `s((string)($row['check']))` / `s($findingtext)` (`diagnostic_checklist_preview.php:99,104`) — i.e. shown raw to the user in their browser, in English regardless of `outputlang`.
**Impact:** A non-English user sees English check/finding labels in the diagnosis preview card; violates the project rule "alle Strings über get_string" (`feedback_all_strings_via_get_string`) and Moodle's string API. The C2 cross-cutting sweep asserted "no hard-coded user-facing literals" (`crosscutting/C2-moodle-api.md:172`) — that claim does not hold for this subsystem.
**Compensating control:** The *observation* copy of these strings is re-narrated by the synchronizer in the user's language, so the LLM-facing path is unaffected; only the deterministic preview card shows raw English. Volume is large (~50 literals) but blast radius is a manager/teacher diagnostic tool, not a public surface.
**Recommendation:** Move the check/finding literals to `lang/en/bookingextension_agent.php` and emit via `get_string` (or have the diagnosers return string keys and localise in the preview builder). At minimum localise the strings that reach the rendered preview.

### [14-F02] 🟢 LOW · D6 Docs coverage · classes/local/wizard/result_payload_summarizer.php:35-38
**What:** The class docblock documents a `for_client()` method ("plain-text fallback message for client-facing responses") that does not exist on the class.
**Evidence:** `result_payload_summarizer.php:36-38` describes `for_client()`; `grep "for_client"` over `classes/` returns only this docblock. The actual client-fallback path is `execution_feedback_service.php:332` calling `describe_entry($result, 0, 'client_fallback')`.
**Impact:** Misleads a maintainer into looking for / calling a non-existent method.
**Compensating control:** None needed; cosmetic.
**Recommendation:** Replace the `for_client()` bullet with the real `describe_entry(..., 'client_fallback')` / `describe_result_for_state()` accessors.

### [14-F03] 🟢 LOW · D3 Structure · classes/local/wizard/result_payload_summarizer.php:149-178, 421
**What:** The engine-namespace summarizer carries booking-domain heuristics, a mild engine→domain leak.
**Evidence:** `build_summary_context()` line 421 hard-maps `$prefix === 'booking' ? 'bookingextension_agent' : $prefix`; `detect_result_category()` (`:149-177`) special-cases `options` ("booking option(s)") and `optiondetails` with booking-specific shapes (teachers/sessions/customfields) elaborated in `describe_entry()` `:240-326`.
**Impact:** Per `LG_AGN`/no-engine→domain-leak rule, booking specifics ideally enter via the contributor hook (`result_summary_contributor_interface`), which already exists and already handles `options`/`users`/`courses`. The summarizer's own `option_details`/`properties`/`capabilities` switch duplicates that seam in the engine.
**Compensating control:** The contributor mechanism is consulted FIRST (`describe_entry:201`), so this is a fallback path; the literal `'booking'` token is a pragmatic component-prefix shim, not behavioural routing.
**Recommendation:** Backlog — migrate the `option_details`/`properties` branches into a booking contributor and drop the `'booking'` literal once a provider supplies its own component mapping. Not a launch gate.

### [14-F04] ⚪ INFO · D1 Security · classes/local/wizard/summarizer/basic_collection_result_summary_contributor.php:82-99
**What:** The `users` summary embeds raw `firstname`/`lastname`/`email` into the observation/state string.
**Evidence:** `basic_collection_result_summary_contributor.php:90-94` builds `'... email=' . $email . ...'`; this becomes part of `for_observation()` output.
**Impact:** Would be a PII-into-prompt leak in isolation, BUT the summarizer is intentionally NOT self-anonymizing.
**Compensating control (verified):** `agent_runtime.php:194-203` runs `for_observation()` then `mask_step_observation_for_llm()` (the `privacy_anonymizer` boundary) before the text reaches the model — matching flowchart `OBS_ACCUM`/`ANON`. The contributor comment-of-record is `result_payload_summarizer.php` line 198-202. No defect; recorded so the dependency on the downstream mask is explicit.
**Recommendation:** None. If a future caller ever consumes a contributor's output without passing through the runtime mask, re-evaluate.

### [14-F05] ⚪ INFO · D4 Duplication · classes/local/wizard/summarizer/basic_collection_result_summary_contributor.php:54
**What:** A contributor re-derives the category by calling `result_payload_summarizer::detect_result_category($entry)` even though `supports()` already received `$category`.
**Evidence:** `basic_collection_result_summary_contributor.php:54` recomputes the category that the dispatcher passed to `supports()` and could pass to `summarize()`.
**Impact:** Trivial redundant call; no correctness issue.
**Compensating control:** N/A.
**Recommendation:** Optional — branch on the already-known support set instead of recomputing.

### [14-F06] 🟢 LOW · D6 Docs coverage · docs/architecture/16-support-services.md
**What:** Chapter 16 (the only architecture chapter mapped to this section) does not describe the diagnostics aspect-diagnoser family or the result-summarizer/contributor subsystem at all.
**Evidence:** Chapter 16 covers anonymizer, language policy, trigger registry, error classifier, domain hooks and "smaller helpers" — `grep -i "diagnos\|summariz"` over the chapter returns nothing; yet `result_payload_summarizer` is a flowchart-cited support service (OB_OUT) and the diagnosers are a major skill-support cluster.
**Impact:** A reader of the support-services chapter learns nothing about how observation summaries are produced or how diagnosis checklists are built/gated.
**Compensating control:** Per-skill diagnose docs may exist elsewhere; the summarizer is named in the flowchart.
**Recommendation:** Add a "result summarizer + contributors" subsection and a "diagnostics builders/aspect diagnosers" note (capability gates, FACTS-COLLECTOR principle) to chapter 16, or cross-reference the skill-layer chapter.

## C. Per-file / per-method checklist

#### `classes/local/wizard/diagnostics/diagnostic_result_builder.php` (class `diagnostic_result_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (pure static row/glyph/error factory)
- methods:
  - [x] `static row()` — clean (escapes via consumer; `$url->out(false)`)
  - [x] `static glyph()` — clean
  - [x] `static error_result()` — clean

#### `classes/local/wizard/diagnostics/diagnostic_link_builder.php` (class `diagnostic_link_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (server-side `moodle_url` builders; capability-aware gates)
- methods:
  - [x] `course()` `activity()` `user_profile()` `enrol_instances()` `enrolled_users()` — clean
  - [x] `check_permissions()` `assign_roles()` `grade_setup()` `user_grade_report()` — clean
  - [x] `notification_preferences()` `completion_settings()` `activity_completion_report()` `course_completion_report()` — clean
  - [x] `scheduled_tasks()` `outgoing_mail_config()` — clean (admin-only, gated by `if_admin`)
  - [x] `if_capable()` — D1✓ correct `has_capability($cap,$ctx,$userid)` gate
  - [x] `if_admin()` — D1✓ `is_siteadmin($userid)` gate

#### `classes/local/wizard/diagnostics/diagnostic_checklist_preview.php` (class `diagnostic_checklist_preview`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (output escaped, ob_start hardened)
- methods:
  - [x] `render()` — clean (filters empty rows, null on empty)
  - [x] `private build_html()` — D1✓ escapes `check`/`finding`/`title` via `s()`, link via `html_writer::link` + `rel=noopener`; glyph/class from internal constants

#### `classes/local/wizard/diagnostics/aspects/access_aspect_diagnoser.php` (class `access_aspect_diagnoser`)
- [ ] D2 — see 14-F01 (hard-coded strings)
- [x] D1 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `diagnose()` — D1✓ cross-user gate `moodle/role:review`; reads target modinfo/roles/groups read-only; D2 see 14-F01
  - [x] `private activity_visibility_row()` — D1✓ relies on `$cm->uservisible`/`availableinfo` (permission-respecting); D2 14-F01
  - [x] `private activity_overview_row()` — clean (counts only); D2 14-F01
  - [x] `private group_row()` — clean; D2 14-F01

#### `classes/local/wizard/diagnostics/aspects/enrolment_aspect_diagnoser.php` (class `enrolment_aspect_diagnoser`)
- [ ] D2 — see 14-F01
- [x] D1 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `diagnose()` — D1✓ gate `moodle/course:enrolreview`; admin-only task rows behind `is_siteadmin`
  - [x] `private analyse_instance()` — clean
  - [x] `private analyse_self()` — D1✓ `count_records('user_enrolments', [...])` parameterised; cohort name `format_string` with context
  - [x] `private analyse_cohort()` — clean
  - [x] `private existing_enrolment_row()` — D1✓ SQL uses `:courseid`/`:userid` placeholders (`:286-290`)
  - [x] `private enrolment_task_rows()` — clean; reads `task_scheduled` (bounded table)

#### `classes/local/wizard/diagnostics/aspects/grades_aspect_diagnoser.php` (class `grades_aspect_diagnoser`)
- [ ] D2 — see 14-F01
- [x] D1 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (most sensitive; gate correct)
- methods:
  - [x] `diagnose()` — D1✓ cross-user `moodle/grade:viewall`; self requires `grade:view`; MAX_ITEMS=25 cap
  - [x] `private filter_items()` — clean
  - [x] `private item_row()` — D1✓ respects `is_hidden()` for self without viewall (`:198`); no recompute
  - [x] `private format_grade()` — clean (try/catch around `grade_format_gradevalue`)

#### `classes/local/wizard/diagnostics/aspects/progress_aspect_diagnoser.php` (class `progress_aspect_diagnoser`)
- [ ] D2 — see 14-F01
- [x] D1 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `diagnose()` — D1✓ cross-user gate `report/progress:view`; MAX_ITEMS=30; visibility via target modinfo
  - [x] `private activity_row()` — clean (reads `cm_completion_details`, no recompute)
  - [x] `private append_course_completion_rows()` — clean (try/catch per criterion)

#### `classes/local/wizard/result_payload_summarizer.php` (class `result_payload_summarizer`)
- [ ] D3 — see 14-F03 (domain heuristics) · [ ] D6 — see 14-F02 (`for_client()` doc-lag)
- [x] D1 [x] D2 [x] D4 [x] D5 — file-level
- methods:
  - [x] `static for_observation()` — D5✓ matches OB_OUT; PII masked downstream (14-F04)
  - [x] `static describe_result_for_state()` — clean
  - [x] `static detect_result_category()` — D3 14-F03 (booking-aware keys)
  - [x] `static describe_entry()` — D3 14-F03 (option_details/properties branches); logic correct
  - [x] `private static compact_text()` — clean (mb-safe truncation)
  - [x] `private static summarize_with_contributors()` — clean (consulted before built-in switch)
  - [x] `private static build_summary_context()` — D3 14-F03 (`'booking'` literal map, `:421`)
  - [x] `private static summarize_with_skill_provider()` — clean (interface-guarded escape hatch)

#### `classes/local/wizard/summarizer/basic_collection_result_summary_contributor.php` (class)
- [x] D1 (see 14-F04 INFO) [x] D2 [x] D3 [x] D4 (see 14-F05 INFO) [x] D6 — file-level
- methods:
  - [x] `supports()` — clean
  - [x] `summarize()` — emits PII into observation, masked downstream (14-F04); recomputes category (14-F05)

#### `classes/local/wizard/summarizer/diagnosis_result_summary_contributor.php` (class)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D6 — file-level
- methods:
  - [x] `supports()` — clean
  - [x] `summarize()` — clean (caps reasons at 10, deterministic)

#### `classes/local/wizard/summarizer/docs_result_summary_contributor.php` (class)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D6 — file-level
- methods:
  - [x] `supports()` — clean
  - [x] `summarize()` — clean (size budgets; mb-safe truncation)

#### `classes/local/wizard/summarizer/single_object_result_summary_contributor.php` (class)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D6 — file-level
- methods:
  - [x] `supports()` — clean
  - [x] `summarize()` — clean (whitelisted scalar fields only)

#### `classes/local/wizard/interfaces/summarizer/result_summary_contributor_interface.php` (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — pure contract; two implementers + provider wiring confirmed

#### `classes/local/wizard/interfaces/result_summary_provider_interface.php` (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — pure contract; implemented by `skill_provider`, consumed by `skill_registry`

#### `classes/local/wizard/interfaces/skill_result_summary_provider_interface.php` (interface, adjacent)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — pure contract; guarded use in `result_payload_summarizer::summarize_with_skill_provider`

**Dead-code check:** grepped whole `classes/` + `tests/` tree. All four aspect diagnosers are instantiated in `course/skills/diagnose_user_in_course_skill.php:336-339`; the three builders are used by `diagnose_notifications_skill`, `diagnose_permissions_skill`, `diagnose_user_in_course_skill` (+ unit tests); the four contributors are registered in `skill_provider::get_result_summary_contributors():127-130` and dispatched via `skill_registry::get_result_summary_contributors()` → `result_payload_summarizer::summarize_with_contributors()`; `result_payload_summarizer` static methods are called from `agent_runtime`, `confirm_run_service`, `execution_observation_ledger`, `execution_feedback_service`. No dead code.

## D. Go-live blockers from this section
None. No BLOCKER or HIGH findings. The capability gating on cross-user diagnosis is correct and read-only (no privilege escalation: the actor's capability authorises reading the target's data, which is the intended elevation), and the PII-into-prompt path is masked at the verified `agent_runtime` anonymizer boundary. Recommended pre/post-launch cleanups: localise the diagnoser strings (14-F01, MEDIUM) and the small doc/structure items (14-F02/F03/F06).
