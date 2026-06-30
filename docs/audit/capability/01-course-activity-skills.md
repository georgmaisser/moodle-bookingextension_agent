# Capability Fidelity Audit — `course.*` activity skills + `question.generate_questions`

Scope: the cross-course WRITE cluster.
Date: 2026-06-30. Method: read-only code trace. Nothing changed.

> **Maintainer's concern (verbatim):** a user with rights in course A must NOT be able to
> create activities, see user data, etc. in course B where they have no access.

---

## 0. Correction to the audit premise (important)

The task brief states these skills are **`tgt:0` — do NOT opt into target-context resolution**, so
Gate 2 would check native caps at the **ambient** context, leaving a cross-course hole.

**That premise is false for the current code.** Every skill in scope `use`s the
`course_targeted_skill` trait
(`mod/booking/bookingextension/agent/classes/local/wizard/course_targeted_skill.php`), which
implements:

- `supports_target_context(): bool { return true; }` (line 43)
- `get_target_selector(array $input): ?target_selector` reading `courseid` / `coursequery`
  (lines 54-61)

and each skill additionally declares `get_target_context_level(): int { return CONTEXT_COURSE; }`.

Consequence in the engine: in `preflight_pipeline::run()`
(`.../services/preflight_pipeline.php:169`) the operating context is resolved by
`skill_operating_context_resolver::resolve()` → `context_resolver::resolve_operating_context()` →
`operating_context_target_registry::resolve_course()`. When the user names course B, the operating
context becomes **B's `context_course`**, and **Gate 2** (`native_capability_guard::missing_capabilities`,
preflight_pipeline.php:207 and executor.php:266) checks the declared native cap **at B**, not at the
ambient course A. The executor backstop re-checks at the same operating context
(`executor.php:181` carries `operating_contextid` from the prepared command).

So the headline cross-course *write* hole the brief feared is **closed by construction**: a teacher
of A who names B is denied because `moodle/course:manageactivities` (or `moodle/question:add`) at B
fails — twice (preflight + executor), and each skill *also* re-checks in its own `run_preflight()` at
the operating course context. `add_moduleinfo()` / `update_moduleinfo()`
(`activity_creation_service.php:51,88`) do **no** capability check themselves, exactly as Moodle core
— but they are unreachable without a guard token, which is only issued after the operating-context
Gate 2 passes.

The real findings below are the **residual** holes that survive that model: question-reference
side-channels, and target-resolution by raw id without a visibility check.

---

## Per-skill verdicts

### `course.add_activity` — **SAFE**

- Target: opts in via `course_targeted_skill` (courseid / coursequery). Operating context = target course.
- Write cap `moodle/course:manageactivities` declared (skill:108) → enforced at operating context by
  Gate 2 in preflight (preflight_pipeline.php:207) and executor (executor.php:266). Re-checked in
  `run_preflight()` at the operating course context (add_activity_skill.php:310). Module-specific
  `mod/<modname>:addinstance` enforced via the capability-filtered catalog
  (`module_catalog_service::list_addable_modules($course,$userid)`, skill:321).
- Section resolution (`section_resolver_service`) and field validation operate on `$course` derived
  from the operating context only; `execute()` trusts `$preparedinput['courseid']` set in preflight
  (skill:363), which is bound to the operating context. The guard token binds
  skill+operating_contextid+input, so a tampered `courseid` is rejected (executor.php:235).
- **Attack trace (teacher of A names B):** operating context → B. Gate 2 checks
  `moodle/course:manageactivities` at B → user lacks it → **denied at preflight_pipeline.php:208**
  (no guard token issued), and would again be denied at executor.php:267. No activity is created in B.
  **Blocked.**

### `course.update_activity` — **SAFE**

- Same trait / cap / context model as add_activity (skill:106, 265).
- Target cm is resolved **inside the operating course's modinfo**: `get_fast_modinfo($course)` then
  `$modinfo->get_cm($cmid)` (update_activity_skill.php:402-408). A `cmid` belonging to another course
  throws → clarification (skill:410). So a raw `cmid` cannot reach across courses.
- **Attack trace:** teacher of A names B → operating context B → Gate 2 (`manageactivities@B`) fails →
  **blocked** (same lines as above). Even if A is ambient and the attacker passes a cmid that lives in
  B without naming B, the cmid lookup happens in A's modinfo and misses → clarification. **Blocked.**

### `course.add_quiz` — **SAFE for the quiz hull; see shared HOLE for the `ids` question source**

- Trait/context model identical. Preflight enforces **both** `moodle/course:manageactivities` and
  `mod/quiz:addinstance` at the operating course context (add_quiz_skill.php:293-298), plus
  `moodle/question:add` when the generate source is chosen (skill:321-326).
- **Defense-in-depth gap (LOW):** `get_required_native_capabilities()` declares only
  `moodle/course:manageactivities` (skill:110). The engine's central Gate 2 backstop therefore does
  **not** re-check `mod/quiz:addinstance` at execute time — that cap is verified *only* in
  `run_preflight()`. Not directly exploitable (the guard token binds skill+operating-context+input, so
  a replayed/crafted command can't skip preflight), but if a future refactor ever issued a token by a
  different path, the executor backstop would not catch a missing `mod/quiz:addinstance`. Cheap to
  harden by adding `mod/quiz:addinstance` to the declared list.
- Question population: `generate` and `category` sources are course-scoped and capability-filtered
  (see below). The `ids` source is the shared HOLE H1 below.
- **Attack trace (create quiz in B):** operating context B → Gate 2 (`manageactivities@B`) fails →
  **blocked at preflight_pipeline.php:208.** **Blocked.**

### `course.update_quiz` — **SAFE for the quiz; see shared HOLE for the `ids` question source**

- Trait/context model identical; preflight checks `moodle/course:manageactivities` at operating
  context (update_quiz_skill.php:296) and `moodle/question:add` for the generate source (skill:326).
  Target quiz cm resolved within the operating course's modinfo (skill:457-473), so a cross-course
  cmid cannot be targeted.
- Same LOW defense-in-depth note as add_quiz (declares only `manageactivities`; `mod/quiz:addinstance`
  is not relevant for update, but the executor backstop never sees a quiz-specific cap).
- Exposes the same `questionids` → `ids` path → shared HOLE H1.
- **Attack trace (edit a quiz in B):** operating context B → Gate 2 fails → **blocked.**

### `question.generate_questions` — **SAFE**

- Trait/context model identical; native cap `moodle/question:add` declared (skill:141) → Gate 2 at
  operating course (preflight_pipeline.php:207, executor.php:266). Re-checked in `run_preflight()` at
  the operating course context (generate_questions_skill.php:362).
- Target bank/category resolution is the strong part of the codebase:
  `question_bank_target_resolver::list_writable_targets()` enumerates only `qbank` instances that are
  `uservisible` AND pass `has_capability('moodle/question:add', $bankcontext, $userid)`
  (question_bank_target_resolver.php:106-110), all within the **operating** course
  (`$ambient->get_course_context()`). `resolve_selected_target()` re-validates a chosen
  `target_categoryid` against that writable set and **throws** for a stale/forged id
  (resolver.php:149-180). `import_gift()` binds the category to the passed module context
  (`contextid = $context->id`, question_import_service.php:78) and rejects a category not in that bank
  (import_service.php:80). `execute()` uses the operating `$contextid` (skill:567), so questions land
  in the target (operating) course's bank, never elsewhere.
- **Attack trace (write into B's question bank):** operating context B → Gate 2 (`question:add@B`)
  fails → **blocked at preflight_pipeline.php:208.** Even with a forged `target_categoryid` pointing
  at another course, `resolve_selected_target()` throws because it isn't in the operating course's
  writable targets. **Blocked.**

---

## HOLES

### H1 — `questionids` (`ids` source) references arbitrary questions into a quiz without bank-context authorization — **HIGH (cross-course information disclosure)**

**Files:**
`.../services/activities/quiz_question_service.php:321-340` (`add_by_ids`),
reachable from `course.add_quiz` (add_quiz_skill.php:416 → `add_questions_to_quiz` →
quiz_question_service.php:218-219) and `course.update_quiz`
(update_quiz_skill.php:411-412 → same path). `add_by_ids` →
`reference_ids_into_quiz` → `quiz_add_quiz_question()` (quiz_question_service.php:390-401).

**What it does / fails to do:** `add_by_ids()` takes the raw `questionids` from user input and
validates **only** that each id exists in `{question}` and is not a `random` qtype
(quiz_question_service.php:325-326). It performs **no** check that the question belongs to the
operating course's question bank, and **no** capability check (`moodle/question:use` /
`moodle/question:add` / `moodle/question:useall`) at the question's own bank context. It then calls
`quiz_add_quiz_question()` (core), which adds a reference and does not capability-check the caller.

**Attack trace:** Actor is `editingteacher` in course A (operating context = A; Gate 2
`manageactivities@A` and `mod/quiz:addinstance@A` pass legitimately). Actor invokes
`course.add_quiz` / `course.update_quiz` against **their own** course A and supplies
`questionids:[<id from course B's private question bank, where they have no access>]`. The ids path
validates only existence + non-random, references the foreign question into the actor's quiz in A,
and the actor can then **open/preview the quiz and read the foreign question's full text and answers**
— a cross-course read of course B's question-bank content. (It does not let them *write* into B, but
it discloses B's protected question content, which is exactly the "see … data in course B" the
maintainer prohibits.)

**Why the engine's Gate 2 does not catch it:** Gate 2 guards the *operating* context (course A, where
the actor is authorized). The question's home bank (course B) is a *different* context the skill
reaches into internally — and the operating-context model never sees it because the cross-context
target is the *course*, not the *question*.

**Exact fix:** in `add_by_ids()`, for every candidate `$qid`, resolve the question's bank/category
context and require the actor to be allowed to use it there before referencing it — e.g. derive the
question's `contextid` (via `question_bank_entries` → `question_categories.contextid`) and enforce
`has_capability('moodle/question:useall', $qcontext, $userid)` (or `:use`), dropping/erroring on any id
the user cannot use. Equivalently, restrict accepted ids to those whose category is one of
`question_bank_target_resolver::list_writable_targets($coursecontext, $userid)` for the operating
course (the resolver already does the capability + visibility filtering correctly). The `category`
and `generate` sources already go through that capability-filtered resolver
(`match_category`/`list_available_categories` → `list_writable_targets`,
quiz_question_service.php:142, 412-427) and are **not** affected — only the explicit-`ids` path is.

> Note: there is currently no user-facing way for the planner to *populate* `questionids` (the prompt
> guidance never asks for ids and the skills don't surface a chooser), so the *practical* exposure
> today is low. But the field is in the public schema (add_quiz_skill.php:179-184,
> update_quiz_skill.php:184-189) and accepted from input, so a crafted command reaches it. Treating it
> as HIGH on a security audit is correct; the realistic severity given no UI path is MEDIUM.

### H2 — course target resolved by raw id without a visibility/access check — **MEDIUM (cross-course existence/name disclosure; no write)**

**Files:** `.../services/security/operating_context_target_registry.php:107-152` (`resolve_course`).

**What it does:** when the target carries an explicit `courseid` (or a purely numeric `coursequery`),
resolution is `context_course::instance($id, IGNORE_MISSING)` with **no** `can_access_course()` /
visibility check (operating_context_target_registry.php:110-121). Only the free-text name path is
visibility-aware (it goes through `core_course_category::search_courses`, which respects the acting
user's visibility, lines 124-127).

**Impact:** *Writes* are still fully blocked — the operating context becomes the named course and Gate
2 denies the action there (the actor has no `manageactivities`/`question:add` in a course they can't
access). So this is **not** a write hole. The residual issue is an **information leak**: by passing
ids and reading back the differentiated denial/clarification messages (e.g. "no permission" for an
existing-but-forbidden course vs. "course not found" for a non-existent id), an actor can probe which
course ids exist and, in some ambiguous-name flows, learn course names. This is a low-grade
enumeration oracle, not data exfiltration.

**Exact fix (defense in depth):** in `resolve_course()`, after `context_course::instance($id)`, gate
the id path on visibility for the acting user — e.g. resolve the course and require
`can_access_course($course, $user, '', true)` (or at least `core_course_category::can_view_course_info`)
before returning `resolved()`, returning `not_found()` otherwise so the by-id and by-name paths leak
the same (minimal) information. The acting `$userid` is already threaded into
`operating_context_target_registry::resolve()` (line 73) but is unused on the course id branch.

---

## Engine-model confirmations (for the record)

- Operating context is computed centrally and carried to the executor:
  `preflight_pipeline.php:169, 260` set `operating_contextid`; `executor.php:181` reads it; the guard
  token binds `skill + operating_contextid + input` (`executor.php:235`,
  `preflight_execution_gate::verify_guard_token`).
- Gate 2 is enforced **twice** at the operating context: preflight (`preflight_pipeline.php:207`) and
  executor backstop (`executor.php:266`), via `native_capability_guard::missing_capabilities()`, which
  **fails closed** on an unresolvable context (native_capability_guard.php:66-70).
- `add_moduleinfo()` / `update_moduleinfo()` perform no capability check
  (`activity_creation_service.php:51, 88`) — consistent with Moodle core; all write authorization is
  the operating-context Gate 2 above, which is correctly scoped for the activity/quiz *hull*.
- Skills run in the actor's session and pass explicit `$userid` to every `has_capability()` call, so
  there is no ambient-`$USER` confusion in the audited paths.

## Summary table

| Skill | Verdict | Cross-course write blocked? | Residual issue |
|---|---|---|---|
| `course.add_activity` | SAFE | Yes (Gate 2 @ target) | — |
| `course.update_activity` | SAFE | Yes (Gate 2 @ target; cmid scoped to course) | — |
| `course.add_quiz` | SAFE (hull) | Yes (Gate 2 @ target) | H1 (`ids`); LOW: `mod/quiz:addinstance` not in declared caps |
| `course.update_quiz` | SAFE (quiz) | Yes (Gate 2 @ target; cmid scoped) | H1 (`ids`) |
| `question.generate_questions` | SAFE | Yes (Gate 2 @ target; resolver cap-filtered) | — |
| Shared: `quiz_question_service::add_by_ids` | **HOLE H1** | n/a (read leak) | references foreign questions without bank-context cap |
| Shared: `operating_context_target_registry::resolve_course` (by id) | **HOLE H2** | Yes (write blocked) | by-id resolution skips visibility (enumeration oracle) |
