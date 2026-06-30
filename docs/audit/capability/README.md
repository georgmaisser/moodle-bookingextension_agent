# Capability-Fidelity & Cross-Context Authorization Audit — `bookingextension_agent`

**Date:** 2026-06-30 · **Type:** read-only security audit (no code changed) · **Scope:** every skill in
the agent (`core.*`/`course.*`/`question.*`/`wizard.*`) + the mod_booking skill provider
(`mod_booking.*`), the engine authorization machinery, and the existing test coverage.

**Method:** 7 parallel auditors, each grounded in a pre-established engine-authorization model and
told to be adversarial (construct the concrete cross-context attack, trace the capability check to
its context, or prove its absence). Per-area reports:
[00 engine baseline](00-engine-authz-baseline.md) ·
[01 course activity writes](01-course-activity-skills.md) ·
[02 agent R0 reads](02-agent-r0-read-skills.md) ·
[03 booking R0 reads](03-booking-r0-read-skills.md) ·
[04 booking mutating](04-booking-mutating-skills.md) ·
[05 wizard/memory/admin](05-wizard-memory-admin-skills.md) ·
[06 test coverage](06-test-coverage-inventory.md).

---

## Verdict

**The WRITE side is solid. The real exposure is on the READ side (R0 skills), where several skills
leak cross-user / cross-instance data because they bypass the scoped resolvers or apply no
visibility filter.** No write privilege-escalation across courses or booking instances was found;
memory is fully per-user isolated. There are **3 HIGH read-side holes** and a cluster of
medium/low issues, plus one **engine-structural root cause** (R0 has no central read-side
visibility gate).

> **The single most important correction from this pass:** the earlier **12-F01 `search_users`
> fix is INCOMPLETE.** `user_can_view_profile()` returns `true` for *any* user when
> `$CFG->forceloginforprofiles` is off (a common default), so on most sites the gate is a no-op —
> and even when it fires it authorises profile-*page* visibility, **not** the identity fields
> (email/idnumber/phone/address/custom fields) the payload still dumps. `search_users` must be
> re-hardened (see Fix Plan #1).

### What is enforced where (engine baseline)

| Skill kind | Gate 1 (skill-use cap `…:skill_<name>`) | Gate 2 (native caps) | Cross-context protection |
|---|---|---|---|
| **R0 read-only** | Executor, at **ambient** | **Executor backstop** (`executor.php:266`) at operating ctx — *runs for R0 too* (not preflight) | **Native caps ARE enforced if declared**; but most R0 read skills declare none and the base read helpers apply **no visibility filter** → the gap. |
| **R1/R2/R3 mutating** | Executor, at **ambient** | Preflight (`preflight_pipeline.php:207`) **+** executor backstop, at operating ctx | Solid. |
| **Mutating + target opt-in** | ambient only | operating = resolved **target** module/course ctx, Gate 2 re-checked there | Cross-course/instance write closed. |

Two myths from the initial scan were **refuted in code**:
- "R0 + declared native cap = silently ignored" → **false**: the executor Gate-2 backstop is not
  gated on `is_read_only()`, so it enforces them (this is why `diagnose_user_booking` and
  `book_users` are safe).
- "course.* activity skills are `tgt:0` → cross-course write hole" → **false**: they use the
  `course_targeted_skill` trait, so the operating context resolves to the *named* course and Gate 2
  checks `manageactivities` **there**.

Confirmed-safe invariants: **cross-instance option resolution is hard-scoped to `bookingid =
cm->instance`** (`booking_skill_support.php:937-944`) — an option from another instance fails to
`OPTION_NOT_FOUND`/`INVALID_OPTIONID`; **memory is per-user isolated** (no `userid` request param;
compound-key ownership-checked deletes).

---

## Findings by severity

### 🔴 HIGH

| ID | Skill | Problem | Evidence |
|----|-------|---------|----------|
| **CAP-01** | `core.search_users` | Over-broad PII + config-dependent no-op gate. The 12-F01 `user_can_view_profile()` gate is bypassed when `forceloginforprofiles` is off; identity fields (email/idnumber/phone/address/custom) + cross-course enrolments/roles are returned regardless; site-wide enumeration by name/id. | `search_users_skill.php:204-219`; payload `core_skill_base.php:344-395`; resolver `core_skill_base.php:547` → `search_users(0,0,…)` (no cap). |
| **CAP-02** | `mod_booking.get_option_details` | Cross-instance / site-wide read leak. `resolve_target_option_ids()` accepts `optionid`/`optionids` **without cmid scoping** (loads via global singleton); `cmid=0` does a site-wide `LIKE` over all options. No native cap, no actor check. Bypasses the otherwise-safe scoped resolver. Actor in instance A reads option in B → teachers/sessions/price/custom fields. | `get_option_details_skill.php:586-597`, `:272`, `:650-670`. |
| **CAP-03** | `wizard.recreate_skill_catalog` | Teacher can trigger a **site-global, cost-bearing** embeddings rebuild. Cap is in `$teacherskills`; the R2 skill declares no native cap → Gate 2 is a no-op; `['module']` scope misrepresents a system action. | `db/access.php:129`; `recreate_skill_catalog_skill.php:189-194`; (confirms finding 12-F02). |
| **CAP-ENG** | *(engine)* | **R0 has no central read-side visibility gate.** Root cause behind CAP-01/02 and CAP-04: `core_skill_base` read helpers (`build_user_payload`, `build_user_courses_payload`, `resolve_userid`) apply **no** capability/identity filter, so any R0 read skill built on them can surface arbitrary users' PII. | `core_skill_base.php:344-497`, `:159-182`, `:547`. |

### 🟠 MEDIUM

| ID | Skill | Problem | Evidence |
|----|-------|---------|----------|
| **CAP-04** | `course.diagnose_user_in_course` | The enrolment-overview path (aspect `enrolment`, no course) is **entirely ungated** — returns the target's full cross-course enrolment list with no cap / `is_enrolled` / `user_can_view_profile` (strictly weaker than the with-course path, which requires `course:enrolreview`). | `diagnose_user_in_course_skill.php:281-287` → `:458-485`. |
| **CAP-05** | `course.add_quiz` / `course.update_quiz` (`quiz_question_service`) | `add_by_ids` accepts `questionids` from another course's **private** bank with no per-question cap check → cross-course question text/answer disclosure via preview. HIGH on paper (field is in the public schema), MEDIUM today (no planner path populates `questionids`). | `quiz_question_service.php:321-340`. |
| **CAP-06** | *(engine)* `operating_context_target_registry::resolve_course` | Numeric/`courseid` path resolves with **no** `can_access_course()` check → low-grade course existence/name enumeration oracle (not a write hole — Gate 2 still blocks writes). | `operating_context_target_registry.php:107-121`. |
| **CAP-07** | `booking_skill_mutation_execute_service` | Single-option update writes via `booking_option::update()` **without re-verifying `bookingid = cm->instance`** — the only mutating path relying on a single upstream check. Not currently exploitable (server-side preflight scopes it; the confirm path rehydrates server-persisted commands). Defense-in-depth. | `booking_skill_mutation_execute_service.php:668-669`. |
| **CAP-08** | *(engine)* | Gate 1 (governance/name-cap) is **never re-checked at the operating context** — for cross-context opt-in skills, cross-context writes are bounded by Gate 2 alone. Latent footgun for future opt-in skills. | `skill_executability_evaluator.php:203,213`. |

### 🟡 LOW

| ID | Skill | Problem |
|----|-------|---------|
| **CAP-09** | `course.search_courses` | Numeric-id branch bypasses `can_view_course_info` → hidden course fullname/shortname/deep-link/enrolment-count via id probing (`core_skill_base.php:574-585`). |
| **CAP-10** | `mod_booking.diagnose_user_booking` | No in-`execute()` self-gate (relies solely on the executor backstop `readresponses`); `collect_user_certificates()` returns the target's site-wide certificates gated only by the actor's one-instance `readresponses`. |
| **CAP-11** | `course.add_quiz`/`update_quiz` | Declare only `moodle/course:manageactivities` as native cap → executor backstop never re-checks `mod/quiz:addinstance` (verified only in the skill's own preflight). |
| **CAP-12** | *(engine)* | Gate 2 is only as strong as the declaration — an empty `get_required_native_capabilities()` passes unconditionally; no engine assertion forces a non-readonly skill to declare ≥1 cap. Keyless guard token (`preflight_execution_gate.php:139`) safe only while no entry point accepts client-supplied commands+tokens. |

### ✅ Confirmed SAFE
All mutating booking skills (`create_option`/`book_users`(R3)/`update_option`/`update_option_trainer`/
`bulk_update`/`create`+`update_rule_from_template`/`configure_booking_instance`/`add_price_category`);
all `course.*` activity writes + `generate_questions` (cross-course closed by the trait + Gate 2 at
target); `diagnose_booking_issue`/`diagnose_cancellation_issue`/`search_options`/`analyze_rules`/
`list_option_properties`; `core.diagnose_notifications`/`diagnose_permissions`/`get_current_user`/
`analyze_course_structure`; all memory + wizard utility skills.

---

## The fallback design you asked for (read-side scoping)

The maintainer's instinct — *"if you don't have the broad right, maybe you have the right to see the
people in the courses you're enrolled in → fall back to that"* — is exactly the right fix shape for
the read-side holes. Concretely:

- **`search_users` (CAP-01):**
  1. Build the payload via `user_get_user_details()` / `\core_user\fields::get_identity_fields($context)`
     so identity fields (email/idnumber/phone/address/custom) appear **only** when the actor holds
     `moodle/site:viewuseridentity`.
  2. Scope the **search** itself: site-wide only if the actor holds `moodle/user:viewdetails` at
     system; **otherwise** restrict to users sharing a course with the actor
     (`search_users($sharedcourseid, …)` over the actor's enrolled courses). Admins/managers keep
     the full view.
- **`diagnose_user_in_course` (CAP-04):** gate the overview on `user_can_view_profile`, then narrow
  the enrolment list to the **intersection** of the target's courses with the actor's own
  `course:enrolreview` courses; full overview only for managers/admins.
- **`search_courses` (CAP-09):** apply `core_course_category::can_view_course_info()` in the numeric
  branch; when not viewable, fall through to the visibility-filtered text search instead of
  returning identity.
- **Harden once at the source:** add identity-field filtering to `core_skill_base::build_user_payload`
  / `build_user_courses_payload` (CAP-ENG) — fixes the identity dimension of CAP-01 and CAP-04 in one
  place.

---

## Test coverage (what's already proven vs. the gaps)

**Strong, always-run (PHPUnit) denial tests exist** for: cross-course native-cap
(`native_capability_guard_test.php:137`), cross-instance update/book
(`agent_option_skill_cross_instance_test.php:58/86`), Gate-2-at-target for questions
(`generate_questions_cross_context_test.php:93`), threadid IDOR
(`thread_idor_external_test.php:80/116`), name-cap-with-empty-decl
(`skill_name_capability_test.php:154`).

**No cross-context / under-privileged-denial test exists** for: **`core.search_users`**,
**`course.search_courses`**, **`diagnose_booking_issue`**, **`diagnose_cancellation_issue`**,
**`diagnose_user_booking`**, **`get_option_details`**, **`search_options`**. There is **no
`user_can_view_profile`/`viewuseridentity` test anywhere** — every read-side hole above is currently
untested. `diagnose_user_in_course` is "covered" only on the with-course path, **not** the ungated
overview path (CAP-04).

---

## Recommended fix plan (prioritised)

1. **CAP-01 `search_users`** — re-harden per the fallback design (identity-field gate + course-shared
   scoping). _Supersedes the incomplete 12-F01 fix._ Add a PHPUnit denial test (teacher cannot read a
   non-shared user's identity).
2. **CAP-02 `get_option_details`** — scope `resolve_target_option_ids` to `bookingid = cm->instance`
   (reject foreign ids); for `cmid=0`, restrict to accessible instances or drop the site-wide search.
   Add a cross-instance denial test.
3. **CAP-03 `recreate_skill_catalog`** — move the cap to manager/admin (`$managerskills`), declare a
   `moodle/site:config` native cap + `context_scopes => ['system']` / `CONTEXT_SYSTEM`.
4. **CAP-04 `diagnose_user_in_course`** — gate the overview path + narrow to the actor's
   `enrolreview` courses.
5. **CAP-ENG / build_user_payload** — central identity-field filter (closes the root cause).
6. **CAP-05/06/07/09/10/11** — schedule as hardening; CAP-05 (`questionids`) and CAP-07 should get the
   per-target check even though no planner path reaches them today.
7. **Engine (CAP-08/CAP-12)** — consider asserting "non-readonly skill must declare ≥1 native cap"
   and re-checking Gate 1 at the operating context for opt-in skills (defense-in-depth).
8. **Tests** — add denial tests for the 7 uncovered skills + the first-ever `viewuseridentity` test.

None of the HIGH findings is a *write* escalation; CAP-01/02 are **read/PII** exposures (GDPR-relevant
in the USI context), CAP-03 is an abuse/cost vector. All are fixable without engine surgery.

---

## Remediation log (2026-06-30)

The three HIGH findings are fixed, each with a first-of-its-kind capability denial test:

| ID | Status | Fix |
|----|--------|-----|
| **CAP-01** `search_users` | ✅ Fixed | `execute()` drops candidates with no actor relationship (self / shared course / site-level `user:viewdetails`/`viewalldetails` / admin) and strips identity fields unless `moodle/site:viewuseridentity` (system or shared course). New `search_users_capability_test` (4/4) — first `viewuseridentity` denial test in the suite. |
| **CAP-02** `get_option_details` | ✅ Fixed | Per-option visibility gate (`actor_can_view_option`) at the end of `resolve_target_option_ids` — every path (direct id, system numeric/title, module query) now drops options whose hosting activity isn't `uservisible` to the actor. New `get_option_details_capability_test` (3/3). |
| **CAP-03** `recreate_skill_catalog` | ✅ Fixed | Cap moved `$teacherskills` → `$managerskills` so it is no longer teacher-grantable — that is the substantive fix. The skill is an external/meta action with no native Moodle capability, so it is gated by its **manager-only name-derived capability** (Gate 1), like the other `wizard.*` meta skills (which the governance test already exempts); no `get_required_native_capabilities()` is declared. `context_scopes => ['system']` already in place. New `recreate_skill_catalog_capability_test` (3/3: teacher lacks the cap, manager holds it, no native cap). |

phpcs 0/0 on all; all tests run green on the VM (Moodle 5.1.1+, PHP 8.3). Commits: agent
`1c76453` (CAP-01 + audit corpus); mod_booking `547db7fab` (CAP-02). CAP-03 landed across two
commits (the `$managerskills` move `7b9b2bd`, then the clean-up to drop the redundant
`site:config` Gate-2 cap in favour of the name-cap-only gating).

**Note (corrected) — `oneclick.*` and the governance test.** During CAP-03 verification the test
`native_capability_guard_test::test_every_mutating_skill_declares_native_capabilities` fails on an
environment with `bookingextension/oneclick` installed, because `oneclick.create_instance` /
`oneclick.delete_instance` return `get_required_native_capabilities() = []`. **This is NOT a security
hole** (an earlier note here wrongly called it a write hole): both skills are gated by their Gate-1
skill-use capabilities `bookingextension/oneclick:skill_oneclick_create_instance` /
`:skill_oneclick_delete_instance` — both **`write`, manager-only**, with `RISK_SPAM` / `RISK_DATALOSS`
(`oneclick/db/access.php`). And they do not write into a Moodle course at all: they call an **external
provisioner API** (`provisioner_client` → /spawn, /execute) to spin up a *separate* trial Moodle
instance — there is no cross-course / cross-context Moodle target. They legitimately have no
Moodle-native capability to declare, so Gate 1 (manager-only) is the correct and sufficient gate.
The real takeaway is that the governance **invariant is too strict** for external-provisioning skills:
either whitelist them in `test_every_mutating_skill_declares_native_capabilities` or have them declare
a sentinel. Out of scope for this audit (separate plugin); flagged for the oneclick maintainer as a
test-fit issue, not a vulnerability.

> **Empirical note.** Simply removing the explicit `get_required_native_capabilities()` override from
> the oneclick skills does **not** fix the test — `base_skill::get_required_native_capabilities()`
> also returns `[]`, and the test checks `empty(...)` whether the method is overridden or inherited.
> So the failure is the invariant itself, not the override; the clean fix is the deliberate
> engine-agnostic opt-out flag (deferred — no rushed patch). The oneclick overrides were left in
> place (they document the deliberate "no native capability" choice).

The MEDIUM/LOW findings (CAP-04…CAP-12) remain open per the fix plan above.
