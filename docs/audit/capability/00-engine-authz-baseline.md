# Engine Authorization Baseline — Cross-Context Capability Fidelity Audit

Audit date: 2026-06-30. Scope: the authorization machinery of `bookingextension_agent` (the
"wizard" engine) and the mod_booking skill provider. Read-only audit; nothing changed.

All file references are absolute. Line numbers are from the state read on the audit date.

---

## 0. TL;DR

- **Two gates, as documented, are real.**
  - **Gate 1** (agent governance cap `bookingextension/agent:skill_<name>` + name-derived cap +
    declared caps) is checked **only at the AMBIENT context**, in
    `skill_executability_evaluator::has_required_capabilities()`
    (`skill_executability_evaluator.php:182-219`). Nothing re-checks Gate 1 at the operating/target
    context.
  - **Gate 2** (the skill's declared native Moodle caps) is checked at the **OPERATING context** by
    `native_capability_guard::missing_capabilities()`
    (`native_capability_guard.php:53-79`), in **two** places: preflight
    (`preflight_pipeline.php:207`) and the executor backstop (`executor.php:266`).
- **R0 (read-only) skips preflight entirely** (decision service routes R0 straight to
  `execute_readonly_commands` → `executor::execute_commands`, `agent_decision_service.php:726-736`).
  But the **executor Gate 2 backstop (`executor.php:266`) is NOT gated on `is_read_only()`** — it
  runs for every skill. So an R0 that declares native caps is **still enforced at execution time**.
  The "silently ignored" hypothesis is **only half true**: ignored at preflight, enforced at the
  executor. The real residual risk is different (see Risk #2).
- **Operating context = ambient context** unless a skill opts in via
  `supports_target_context()` + `get_target_selector()` (`skill_operating_context_resolver.php:96-100`).
  Only **one** shipped mutating skill opts in: `mod_booking.create_option`
  (`create_option_skill.php:39 use module_targeted_skill`). All other mutating booking skills run at
  the ambient context, so their native caps are correctly checked there.
- **Cross-instance INVALID_OPTIONID scoping claim: CONFIRMED.** Every option lookup is scoped to the
  resolved cmid's `bookingid` (`booking_skill_support.php:939`, `:307-310`, `:329`,
  `search_option_candidates` is `cmid`-bound). An explicit numeric optionid belonging to another
  instance fails `record_exists()` and falls through to a `cmid`-scoped title search →
  `OPTION_NOT_FOUND` / `OPTION_AMBIGUOUS`. Cross-instance reach by id is not possible from a
  non-opted-in skill.

---

## 1. "What is enforced WHERE" table

Legend: AMB = ambient (chat/thread) context; OP = operating context (= AMB unless skill opts in).
"self" = the skill's own `has_capability()`/`require_native_capabilities()` inside `execute()`/`preflight()`.

| Path / risk class | Gate 1 (governance + name cap + declared caps) | Gate 2 (native caps) | Self-check obligation |
|---|---|---|---|
| **R0 read-only** (skips preflight) | Executor only, at **AMB** (`executor.php:157`, `evaluate_skill($contextid)`) | **Executor only**, at **OP** (`executor.php:266`). NOT run in preflight. | R0 must still self-gate any *data-visibility* filtering it wants — native caps are enforced if declared, but most R0 declare **none** and core read helpers apply **no** visibility filter (Risk #2). |
| **R1/R2/R3 mutating** | Preflight: none directly; Executor: at **AMB** (`executor.php:157`). Gate 1 evaluation happens in the executor's `evaluate_skill`, ambient only. | Preflight at **OP** (`preflight_pipeline.php:207`) **and** Executor backstop at **OP** (`executor.php:266`). | Skill *should* also self-check via `require_native_capabilities($operatingcontext, $userid)` (`base_skill.php:180`) or `require_native_capability($cap,$cmid,$userid)` (`booking_skill_base.php:1006`), but the engine no longer depends on it — the two central Gate 2 checks are authoritative. |
| **Mutating + opts into target context** (only `create_option` today) | Same as above — Gate 1 still AMB only. | OP = the **resolved target** module context (`skill_operating_context_resolver.php:70-88`). Gate 2 correctly re-checked there. | Opt-in safety rule (`base_skill.php:121-126`): a skill may opt in only if its cap binds to the passed `$contextid`. |

Where Gate 1 lives: `skill_executability_evaluator::evaluate_skill()` is called from the executor
(`executor.php:157`) for *every* command (R0 and mutating). Its capability check
(`has_required_capabilities`, line 182) builds the name-derived cap
`<component>:skill_<name>` from the skill name itself (line 184-195, not trusted from metadata),
appends declared caps, and checks them all at `context::instance_by_id($contextid)` — the **ambient**
context (line 203). There is no second Gate-1 evaluation at the operating context.

---

## 2. Exact control flow (code-cited)

### 2.1 Mutating command (R1/R2/R3) — full path

1. Decision service splits commands by risk class; mutating ones are confirmation-gated and never
   auto-executed (`agent_decision_service.php:738-748`, `split_commands_by_mutability:1465`).
2. On confirm, `handle_preflight` → `preflight_pipeline::run()` (`agent_decision_service.php:837`).
3. In `preflight_pipeline::run()` per command, the order is exactly:
   a. schema/contract validation (`preflight_pipeline.php:126-154`);
   b. skill lookup + deanonymize input (`:156-166`);
   c. **operating-context resolve**: `operatingresolver->resolve($skill,$input,$ambient,$userid)->id()`
      (`:169`). For a non-opted-in skill this returns the **ambient** context
      (`skill_operating_context_resolver.php:71-73`). On an opted-in skill with an unresolvable target
      it throws `context_target_unresolved_exception` → `CONTEXT_TARGET_UNRESOLVED` clarification
      (`preflight_pipeline.php:170-202`);
   d. **Gate 2 native check**: `native_capability_guard::missing_capabilities($skill,$operatingcontextid,$userid)`
      (`:207`). Non-empty → `NO_NATIVE_CAPABILITY`, command skipped, no guard token (`:208-214`);
   e. **`$skill->preflight($input,$operatingcontextid,$userid)`** (`:216`) — domain resolution
      (entities, conflicts) at the operating context;
   f. on pass, the resolved `operating_contextid` is carried on the command (`:260`) and a guard token
      is later bound to it (`agent_decision_service.php:1043-1050`).
4. Executor (`executor::execute_commands`, after the user confirms): re-resolves context
   (`:94`), re-asserts use-capability + valid context (`:99-100`), then per command:
   - Gate 1 re-evaluation at ambient via `evaluate_skill` (`:157`);
   - module-target fail-closed for mutating module-targeted skills (`:191-203`);
   - structural guard (`:207`);
   - guard-token presence + match at the operating context (`:222-245`);
   - **Gate 2 backstop** `native_capability_guard::missing_capabilities(...,$operatingcontextid,...)`
     (`:266`);
   - `$skill->execute($input,$operatingcontextid,$userid)` (`:278`).

So Gate 2 is enforced **twice** at the operating context (preflight + executor); Gate 1 **twice** at
the ambient context. The guard token binds skill+operatingcontext+input so a replayed/crafted command
cannot retarget (`executor.php:235`).

### 2.2 R0 read-only command — the divergent path

- `handle_command_routing` enqueues R0 as `readonly` and calls `execute_readonly_commands`
  (`agent_decision_service.php:674-684`, `:726-736`). That method deanonymizes, creates a run, and
  calls `executor::execute_commands` directly (`:1179-1186`). **Preflight is never invoked for R0.**
- Therefore `preflight_pipeline.php:207` Gate 2 is NOT reached for R0.
- BUT the executor backstop (`executor.php:266`) **is** reached, and it is **not** wrapped in an
  `is_read_only()` guard. So a declared native cap on an R0 *is* enforced — just later and without a
  clean preflight denial. (Contrast: the guard-token block at `executor.php:222-245` *is* gated on
  `!is_read_only()`, so R0 carries no guard token, which is correct.)

### 2.3 Operating-context / target resolution (scope cascade)

- `skill_operating_context_resolver::resolve()` (`skill_operating_context_resolver.php:70`): if the
  skill does not expose `supports_target_context()==true` + `get_target_selector()`, returns ambient
  unchanged. Otherwise builds a `target_selector` and delegates to
  `context_resolver::resolve_operating_context()`.
- `context_resolver::resolve_operating_context()` (`context_resolver.php:95`): empty non-module
  selector → ancestor-of-required-level walk (`resolve()`, `:55`); otherwise →
  `operating_context_target_registry::resolve()` (`:109-115`); unresolved →
  `context_target_unresolved_exception` (never silent ambient fallback).
- `operating_context_target_registry::resolve()` (`operating_context_target_registry.php:73`):
  `CONTEXT_MODULE` → `module_target_resolver`; `CONTEXT_COURSE` → core `resolve_course`
  (visibility-aware `core_course_category::search_courses`); other levels → duck-typed providers (none
  registered by default).
- `module_target_resolver::resolve()` (`module_target_resolver.php:60`) scope cascade:
  explicit cmid (visibility-gated via `uservisible`, `:134-151`) → ambient instance if already inside
  a matching module (`:76-78`) → **ambient course first** (one visible match → resolved; several →
  ambiguous; none → fall through) → **site-wide** (one → resolved; several → ambiguous; none →
  not_found) (`:80-93`). All candidate collection is visibility-filtered through
  `can_access_course` + `uservisible` (`:217-244`). **Resolution itself is not a privilege grant** —
  Gate 2 is enforced afterward by the caller.

This is correct and consistently fail-closed: ambiguous/not-found/unsupported all surface as a
clarification, never a silent default to a broader context.

---

## 3. Structural gaps in the machinery itself

Distinguishing *machinery gap* (engine permits the hole) from *skill must opt in* (engine is correct,
a skill is responsible).

### Risk #1 — Gate 1 (governance cap) is never re-checked at the operating context — STRUCTURAL — **MEDIUM**

`skill_executability_evaluator::has_required_capabilities()` checks the governance cap, the
name-derived cap, and declared Gate-1 caps **only at the ambient context**
(`skill_executability_evaluator.php:203`, `:213`). When a skill opts into cross-context execution
(`create_option` today), the **operating** context can be a *different course/instance* than the
ambient one, yet Gate 1 is only verified at ambient. Cross-context safety then rests **entirely** on
Gate 2 (the native cap, re-checked at OP). For `create_option` that is fine — it declares
`mod/booking:addoption` and Gate 2 re-checks it at the target module (`create_option_skill.php:57`).
But the *governance* cap `bookingextension/agent:skill_create_option` is only proven at the ambient
context; a site could in principle grant the agent-skill cap narrowly (e.g. only in course A) and the
engine would still allow the write into course B as long as the native `mod/booking:addoption` holds
in B. **The engine relies on Gate 2 alone to bound cross-context writes.** The design comment at
`base_skill.php:121-126` acknowledges this ("a skill relying only on the ambient-checked governance
capability must NOT opt in"), making it a *documented* constraint — but the engine does **not enforce**
that constraint. It is a latent footgun for any future opt-in skill whose only meaningful gate is the
governance cap.

Files: `skill_executability_evaluator.php:182-219`; `base_skill.php:121-126`.

### Risk #2 — R0 native caps ignored at preflight, AND R0 read helpers apply no visibility filter — STRUCTURAL (partial) — **HIGH**

Two compounding facts:

1. R0 skips preflight, so `native_capability_guard` at `preflight_pipeline.php:207` never runs for R0.
   The executor backstop (`executor.php:266`) *does* run, so a **declared** R0 native cap is still
   enforced — but the failure surfaces as a raw execution error rather than a clean preflight denial,
   and the asymmetry is an easy thing for a skill author to misjudge (the docstring at
   `base_skill.php:166` says "preflight enforces them", which is **false for R0**).
2. **The bigger issue:** the shipped R0 read skills declare **no** native caps at all
   (`search_users_skill.php:38`, `get_current_user_skill.php:38` both `parent::__construct(true, R0)`
   with no caps), and the shared read helpers in `core_skill_base` apply **no capability or visibility
   filter**:
   - `search_user_candidates_for_preview()` calls `search_users(0,0,$query,...)`
     (`core_skill_base.php:547`) — site-wide, no `accesscontext`;
   - `resolve_userid()` resolves any user by email/id with no membership check
     (`core_skill_base.php:159-182`);
   - `build_user_payload()` returns **full PII** — email, address, phone1/2, idnumber, institution,
     department, lang, all custom profile fields, and **every role assignment** site-wide
     (`core_skill_base.php:344-394`, roles via `build_user_roles_payload` `:457-497`).

   Combined: an R0 user-search/profile skill, gated by Gate 1 only at the *ambient* context, can return
   PII for arbitrary site users the actor would not be able to see in the normal UI. The engine offers
   **no** central read-side visibility gate — it is entirely each R0 skill's responsibility to
   self-filter, and the base helpers do not. This is a machinery-level gap because the engine's
   "R0 is safe because it's read-only" assumption is not backed by any read-side authorization.

Files: `agent_decision_service.php:726-736`; `executor.php:266`; `core_skill_base.php:159-182`,
`:344-394`, `:457-497`, `:525-559`; `search_users_skill.php:38`. (The per-skill auditors must
confirm whether `search_users_skill`/profile skills add their own `has_capability` gate; the **shared
base** does not.)

### Risk #3 — Gate 2 depends on each skill DECLARING its native caps; nothing forces a mutating skill to declare any — STRUCTURAL — **MEDIUM**

`native_capability_guard::missing_capabilities()` returns `[]` (allow) when the skill declares no
native caps (`native_capability_guard.php:54-63`). So Gate 2 is **only as strong as the declaration**.
A mutating (R1/R2/R3) skill that ships with an empty `get_required_native_capabilities()` passes Gate 2
unconditionally and is bounded **only** by Gate 1 (the ambient governance cap) plus whatever the skill
self-checks. There is **no engine assertion** that a non-readonly skill must declare ≥1 native cap.
Today every shipped mutating booking skill declares one (`book_users`→`bookforothers`,
`update_option`→`addeditownoption`, `create_option`→`addoption`, `configure_booking_instance`→
`updatebooking`, rules→`editbookingrules`, `bulk_update`→`addeditownoption`), so the live surface is
covered — but the safety is by convention, not construction. A third-party or future mutating skill
that forgets the declaration silently loses Gate 2.

Files: `native_capability_guard.php:54-63`; `booking_skill_base.php:481-523` (declaration is a
constructor arg, defaulting to `[]`).

### Risk #4 — Name-derived Gate-1 capability must be defined or the skill is denied (this is a STRENGTH, noted for completeness) — **LOW**

`has_required_capabilities` builds `<component>:skill_<name>` and **fails closed** if the component is
missing (`skill_executability_evaluator.php:192-195`) or the cap is not registered
(`:210` `get_capability_info($capability)` false → deny). This is correct and bypass-resistant: a
skill cannot ship without its name cap being enforced even if its metadata lies. No gap; flagged so the
per-skill auditors know the name-cap is engine-derived, not metadata-trusted.

### Risk #5 — `create_option` is the lone cross-context opt-in; its safety rides on Gate 2 only — SKILL-LEVEL (engine correct) — **LOW/INFO**

The engine machinery is correct here: `create_option` opts in via `module_targeted_skill`
(`create_option_skill.php:39`), declares `mod/booking:addoption`, and the executor fails closed if the
operating context is not a `context_module` for a mutating module-targeted skill
(`executor.php:191-203`). The only residual is Risk #1 (governance cap only proven at ambient). Per-skill
auditor should confirm `create_option`'s `get_target_selector` cannot be coerced to a module the actor
cannot see — but the resolver already visibility-gates candidates (`module_target_resolver.php:217-244`),
so this is low.

---

## 4. Cross-instance INVALID_OPTIONID scoping — VERDICT: CONFIRMED

Claim: resolving an option from another instance returns INVALID_OPTIONID / not-found, preventing
cross-instance reach.

Evidence (`booking_skill_support.php`):

- **Single-option resolve** `resolve_single_option($cmid,$optionquery,$when)` (`:925`):
  - numeric query → `record_exists('booking_options', ['id'=>query, 'bookingid'=>(int)$cm->instance])`
    (`:939`). The `bookingid` is derived from the **resolved cmid**, so an id from another instance
    fails this check and falls through;
  - title path → `find_existing_options_by_exact_title($cmid,...)` and
    `search_option_candidates($cmid,...)` (`:948`, `:965`) — both **`cmid`-scoped**;
  - no match → `OPTION_NOT_FOUND`; many → `OPTION_AMBIGUOUS`. There is **no** code path that resolves a
    raw optionid without the `bookingid` filter.
- **Bulk resolve** `resolve_bulk_option_ids($cmid,$input,$userid)` (`:294`):
  - explicit `optionids[]` are each validated with
    `record_exists('booking_options',['id'=>$id,'bookingid'=>(int)$cm->instance])` (`:307-310`); ids
    from other instances are dropped;
  - `optionquery` → `search_option_candidates($cmid,...)` (cmid-scoped, `:329`);
  - `apply_to_all` → `get_records('booking_options',['bookingid'=>(int)$cm->instance])` (`:334`).
- `search_option_candidates` builds a `bookingoptions_wbtable("cmid_{$cmid} ...")` bound to the cmid
  (`:799-810`), so even fuzzy search cannot leak another instance's options.

The `$cmid` itself comes from `resolve_cmid_from_context_or_cmid($operatingcontextid)`
(`booking_skill_base.php:768-771`, `:843-854`), i.e. the operating (= ambient, for the non-opted-in
mutating skills) module context. So an actor chatting inside instance A cannot, through any
non-opted-in booking skill, resolve or mutate an option belonging to instance B — the lookup is hard
bound to A's `bookingid`. **Claim confirmed.** (The scoping is to the *resolved* cmid; the only way to
target B is `create_option`'s explicit, visibility-gated opt-in path, which then re-checks
`mod/booking:addoption` in B.)

---

## 5. Summary of engine-level findings for the per-skill auditors

Ground truth to assume:

1. Gate 1 = ambient-only; Gate 2 = operating-context, enforced in preflight (mutating) and executor
   (all). For R0, Gate 2 runs at the executor only.
2. R0 has **no central read-side visibility gate** — each R0 skill must self-filter; the
   `core_skill_base` read helpers do not. Treat any R0 that returns user PII or cross-course data as
   needing its own `has_capability`/membership check.
3. A mutating skill with empty `get_required_native_capabilities()` has **no Gate 2** — flag any such
   skill.
4. Cross-context writes are bounded by Gate 2 (native cap at the target) only; the governance cap is
   not re-checked at the target. A skill may opt into target context **only** if its native cap is the
   meaningful gate.
5. Option resolution is hard-scoped to the resolved cmid's `bookingid` — cross-instance option reach
   is not possible without the explicit, visibility-gated `module_targeted_skill` opt-in.
