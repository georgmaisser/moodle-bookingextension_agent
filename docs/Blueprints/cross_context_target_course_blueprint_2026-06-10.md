# Cross-Context Execution (Target Course) — Blueprint & Implementation Concept

**Date:** 2026-06-10
**Author:** Claude (design requested by Georg)
**Status:** Blueprint — not yet implemented
**Option chosen:** **C** — generic operating-context resolution (cross-course execution),
risk-gated confirmation for mutations, deep-link as a non-disruptive completion. Redirect
("jump there and continue") kept as an optional complement, not the primary path.
**Related:** `engine_boundary_cleanup_2026-06-10.md` (engine stays domain-agnostic),
`reference/flowchart-guide.md`, `AGENT_IMPLEMENTATION_FLOWCHART.mmd` (subgraphs `DECIDSVC`,
`PREFLIGHT`, `EXEC`, `SECURITY`), thread 237 (the trigger).

---

## 1. Motivation

In thread 237 the user asked, from a booking module, to *create questions in another course*
("im Kurs Booking zwei Fragen erstellen"). Two things went wrong:

1. The planner invented an unsupported `target_courseid` / `courseid` parameter to express
   "do this elsewhere" — the skill schema has no such field, so it was dropped.
2. The agent is bound to the **ambient context** it was opened in (the booking module). It has
   no first-class way to target a different course, so it either fails (`NO_SOURCE`,
   `CONTRACT_PHASE_SKILL_NOT_ALLOWED`) or silently operates on the wrong context.

Georg's requirement: *"if you have the right, you should be able to say: create something in
this or that course — without being in it."* That is fundamentally an **operating-context +
capability** problem, and the engine already models both — it just never wires a *target* into
them.

---

## 2. What already exists (grounded inventory)

| Building block | Where | State |
|----------------|-------|-------|
| Ambient context binding | `conversation_store` thread keyed by `(userid, contextid)` | Wired. One thread = one context. |
| Operating-context DTO | `dto/agent_context.php` (`level()`, `moodle_context()`, `with_context()`) | Exists. |
| Context resolver | `services/security/context_resolver.php::resolve(ambient, requiredlevel)` | Exists but **only walks UP** to an ancestor of the required level. Cannot target a *sibling* course. **Not called** from preflight/executor yet. |
| Required context level | `base_skill::get_required_context_level()` (default `CONTEXT_MODULE`; `generate_questions` = `CONTEXT_COURSE`) | Declared, but skills resolve their course context **inline** (`$context->get_course_context()`), not via the resolver. |
| Gate 2 (native cap) | `base_skill::require_native_capabilities($operatingcontext, $userid)` → `require_capability(...)` | Wired, but called with the **ambient** context, not a resolved operating context. |
| Preflight | `preflight_pipeline::run()` → `skill::preflight($input, $contextid, $userid)` with **ambient** `$contextid` | Wired. |
| Risk gating / confirmation | `agent_decision_service`: read-only executes immediately; mutating `skill_call` → `confirmation_request`; `D_PROMOTE` risk classes R1 (session-allow TTL 900s) / R2 (always explicit) / R3 (manual). | Wired. |
| Execution gate | `preflight_execution_gate::verify_guard_token()` = `sha256(skill:context:prepared_input)`; `executor` runs `skill::execute($input, $contextid, $userid)` | Wired, **ambient** context. |
| Course resolution | `core.search_courses` (query → courseid, shortname, URL) | Wired (core skill — courses are a `core.*` concern). |
| Deep-link precedent | `generate_questions` returns `question_bank_url`, surfaced in the preview | Wired (per-skill, ad hoc). |

**Conclusion:** the *mechanism* for "operate at a resolved context and re-check the permission
there" is 70% present. The gaps are: (a) no **target** selector, (b) the resolver can't target a
sibling course, (c) the operating context is never threaded through preflight/executor/guard,
(d) confirmation doesn't surface the target, (e) no standard deep-link contract.

---

## 3. Design (Option C)

### 3.1 Principles
- **Engine stays domain-agnostic** (per `engine_boundary_cleanup`). The engine knows *context
  levels* and a *generic target selector*; it does **not** know "course" or "booking". Turning a
  human target ("course Booking") into a concrete context is done by a **resolver the domain/core
  layer supplies**, called generically.
- **No privilege escalation.** Resolving *which* context an operation targets is separate from
  *may the user do it there*. Gate 2 (`require_capability`) is re-evaluated **at the resolved
  operating context** — this is exactly "wenn man das Recht hat".
- **Risk-graded UX.** Read-only cross-context → run silently. Mutating cross-context → the
  existing `confirmation_request` flow, enriched with the target so the user sees *where*.
- **Non-disruptive completion.** Cross-context mutations return a standard **deep-link**; the UI
  offers "open there". The disruptive redirect/"jump there" becomes an optional *follow-on*, not a
  prerequisite.

### 3.2 The operating-context resolution (engine, generic)

Extend `context_resolver` with a target-aware resolution, keeping the current ancestor-walk as the
default:

```
resolve_operating_context(agent_context $ambient, int $requiredlevel, ?target_selector $target): agent_context
  1. if $target === null:
       return resolve($ambient, $requiredlevel)          // today's ancestor walk (unchanged)
  2. else:
       $ctx = operating_context_target_registry::resolve($target, $requiredlevel, $userid)
       if $ctx === null: throw target_unresolved            // → clarification, never a silent fallback
       return $ambient->with_context($ctx)
```

`target_selector` is a tiny value object: `{ level: CONTEXT_COURSE, query: string|null,
id: int|null }`. The engine never hardcodes "course"; the **level** drives which resolver is used.

`operating_context_target_registry` maps a context level → a resolver:
- `CONTEXT_COURSE` → a **core** resolver backed by the same lookup `core.search_courses` uses
  (courses are a core concept, so this can live in the engine's core layer without violating the
  domain boundary).
- Other levels (e.g. a future "target module") → a **provider-supplied** resolver, discovered the
  same duck-typed way as `booking_readiness_provider` / `get_result_preview` (no compile-time
  domain dependency).

### 3.3 Skill contract: declaring target support

A skill opts in generically in `get_schema()`:

```php
'target_context' => [
    'supported' => true,
    'level'     => CONTEXT_COURSE,   // the scope the target names
],
```

and exposes input fields the planner can fill (names are the skill's choice, mapped to the
selector in one place):

```php
'coursequery' => [ 'type' => 'string',  'description' => 'Target course by name/shortname when not the current one. Resolve via core.search_courses if only a name is known.' ],
'courseid'    => [ 'type' => 'integer', 'description' => 'Target course id when already known.' ],
```

The engine reads `target_context.supported`; if set, it builds a `target_selector` from the
declared input fields (a tiny per-skill `get_target_selector(array $input): ?target_selector`
duck-typed method, defaulting to "course query/id" via a base helper). **Skills that don't declare
it behave exactly as today** (ambient/ancestor only) — fully backward compatible.

### 3.4 Threading the operating context through the pipeline

The operating context is resolved **once**, early (decision-service preflight), and carried on the
command so preflight, the guard token and the executor all agree:

1. **Decision service / preflight** (`preflight_pipeline::run`): before `skill->preflight(...)`,
   resolve the operating context for the skill's required level + its target selector. Run
   `skill->preflight($input, $operatingcontextid, $userid)` and enforce **Gate 2 at the operating
   context**. Persist `operating_contextid` onto the queued command.
2. **Guard token** (`preflight_execution_gate`): the `context` component of
   `sha256(skill:context:prepared_input)` becomes the **operating** contextid — so a command
   resolved for course X can only execute against course X.
3. **Executor**: `skill->execute($input, $operatingcontextid, $userid)`. Skills already accept a
   `$contextid`; passing the operating context means course-scoped skills stop having to walk up
   themselves (and `generate_questions` drops its inline `get_course_context()` in favour of the
   passed context).

`agent_context` (already a DTO) becomes the carrier; the thread's ambient context is unchanged —
only the *operating* context per command differs.

### 3.5 Risk-gated confirmation (reuses `D_PROMOTE`)

No new state machine. The existing mutating-gate is enriched:
- **Read-only + cross-context** → executes immediately (today's read-only path), no confirmation.
- **Mutating + cross-context** → existing `skill_call → confirmation_request` promotion, but the
  confirmation payload gains `operating_context_label` (e.g. the target course fullname) so the
  synchronizer renders *"I'll create 2 questions in the question bank of course **Booking** — ok?"*.
- A cross-context mutation **never** uses an R1 session-allow that was granted for the ambient
  context: include the operating contextid in the pending-intent signature so a session-allow is
  scoped to (skill, operating context).

### 3.6 Deep-link contract (generalises the existing ad-hoc URL)

Standardise an optional result field every skill may return:

```php
'result_deeplink' => [ 'url' => string, 'label_key' => string /* lang string */ ],
```

The synchronizer, when present, appends a non-disruptive *"open there"* link. `generate_questions`
maps its existing `question_bank_url` onto this. This is the lightweight form of Georg's
"redirect" idea — the user is offered the jump, not force-navigated.

### 3.7 Redirect / "continue the agent there" (optional follow-on, out of MVP)

A heavier UX where the agent navigates to the target page and **resumes**. Because the thread is
keyed by `(userid, contextid)`, resuming in another context means a *different* thread, so it needs
a **handoff token** (carry `next_step_intent` + pending command across the navigation and re-seed
the new thread). Documented here as a deliberate **Phase 4 / later** item; Option C does not depend
on it. Cross-context execution (3.2–3.6) already satisfies the requirement without a page reload.

---

## 4. Flowchart changes (`AGENT_IMPLEMENTATION_FLOWCHART.mmd`)

Per the flowchart policy these are **proposed** — to confirm with Georg before editing the `.mmd`.

1. **New node in `PREFLIGHT` (Layer 2), before `PF_L2P`:**
   `PF_CTX["context_resolver::resolve_operating_context(ambient, required_level, target_selector?)\n→ operating context (ambient/ancestor by default; named target if supported)\n→ target_unresolved ⇒ clarification"]`
   Edge: `L1 → PF_CTX → PF_L2P`. Annotate `PF_L2P` to read `skill::preflight(input, OPERATING contextid, userid)`.

2. **Annotate Gate 2:** wherever `require_native_capabilities` appears, add
   *"checked at the OPERATING context (may be a different course) — no escalation"*.

3. **`EXC_GUARD`:** change the hash note to `sha256(skill:OPERATING_context:prepared_input)`.
   **`EXC_RUN`:** `skill::execute(prepared_input, OPERATING contextid, userid)`.

4. **`D_PROMOTE`:** add *"cross-context mutation ⇒ confirmation carries operating_context_label;
   session-allow scoped to (skill, operating context)"*.

5. **`SYNC_GATE` / outcomes:** note that a result may carry `result_deeplink` rendered as an
   "open there" affordance.

6. **Legend:** add `LG_CTX` — *"Ambient context (thread) ≠ operating context (per command).
   Target resolution picks the context; Gate 2 re-checks the permission there."*

A matching entry goes into `reference/flowchart-guide.md` once applied.

---

## 5. Implementation concept (phased)

### Phase 0 — groundwork (no behaviour change) — ✅ DONE (commit `b9268b4`)
- ✅ Add `dto/target_selector.php` (value object).
- ✅ Extend `context_resolver` with `resolve_operating_context(...)`; keep `resolve(...)` as the
  no-target path. Unit tests: ambient ≥ required → unchanged; ancestor walk → unchanged; explicit
  target → resolves; unresolved target → throws/typed failure. (7 tests green.)
- ✅ Add `operating_context_target_registry` with the core `CONTEXT_COURSE` resolver (reuse the
  `core.search_courses` lookup) + a duck-typed provider hook for other levels
  (`operating_context_target_provider_interface`).
- ✅ Also added: `dto/context_target_resolution.php`, `context_target_unresolved_exception`.

### Phase 1 — thread the operating context (engine)
**1a — resolver glue (no hot-path change): ✅ DONE (commit `b147e39`)**
- ✅ `skill_operating_context_resolver`: maps a skill+input+ambient context to its operating
  context. Duck-typed (`supports_target_context()` / `get_target_selector()` /
  `get_target_context_level()` optional on skills, mirroring `get_result_preview`), so it returns
  the ambient context unchanged for every skill today. Unit-tested (3 cases: non-opt-in → ambient,
  opt-in + target → cross-context, opt-in + empty selector → ambient).

**1b — wire into the hot path (behaviour-preserving when operating == ambient): ✅ DONE (commit `f27092d`)**
- ✅ `preflight_pipeline::run()`: resolves operating context per command (via
  `skill_operating_context_resolver`), calls `skill->preflight` at it (the skill's own capability
  check then runs at the operating context = Gate 2), stores `operating_contextid` on the prepared
  command; an unresolvable target yields `CONTEXT_TARGET_UNRESOLVED`.
- ✅ `agent_decision_service::apply_execution_guard_tokens()`: builds the guard token with the
  operating contextid.
- ✅ `executor`: verifies guard + `skill->execute()` against the operating contextid; Gate 1
  governance `evaluate_skill` stays at the ambient context.
- ✅ Back-compat verified: no skill opts in yet → operating == ambient → identical guard tokens;
  r3-skill e2e (guard+executor) + generate_questions + full plugin suite green.
- ✅ Flowchart updated (`PF_L2P`, `EXC_GUARD`, `EXC_RUN`, new `LG_CTX`) + flowchart-guide entry.
- ⏳ **Deferred to Phase 2 (only needed once operating ≠ ambient):** persist `operating_contextid`
  through the queue (enqueue/dequeue + `queue_command_mapper`) so async confirmed runs target the
  same context. Today the executor falls back to the ambient context, which equals the operating
  context, so the queued path stays correct.

### Phase 2 — skill contract + queue persistence + confirmation + deep-link
**2a — base_skill target-context contract (defaults): ✅ DONE (commit `526f7f4`)**
- ✅ `base_skill`: `supports_target_context(): bool` (false), `get_target_context_level(): int`
  (= required level), `get_target_selector(array $input): ?target_selector` (null). Formalises the
  duck-typed hooks; every skill now exposes them returning "no target", so behaviour is unchanged
  (operating == ambient). Skills opt in by overriding (Phase 3). Tests green.

**2b — queue persistence of `operating_contextid`: ✅ DONE (commit `<this>`)**
- ✅ The shadow queue is thread metadata (no DB schema change): `enqueue_command` stores
  `operating_contextid` on the queue item (from the command, fallback ambient);
  `set_prepared_input` now builds the guard token at the item's operating context (the second
  guard-build site); `queue_command_mapper::from_queue_item` carries `operating_contextid` back
  into the command so the executor targets it. Behaviour-preserving (operating == ambient today);
  r3-skill e2e (enqueue→guard→executor) green. Full cross-context e2e arrives with the first
  adopter in Phase 3.

**2c — confirmation transparency + lang strings: ✅ DONE (commit `<this>`)**
- ✅ `agent_decision_service`: when a mutating command's operating context differs from the
  ambient one, the confirmation message gains a clear "this will be carried out in: <course>"
  note and an `operating_context_label` field (`build_operating_context_note()`). Empty today (no
  skill opts in) → behaviour-preserving; activates with the Phase 3 adopter. r3 e2e green.
- ✅ Lang strings (en+de): `agent_confirm_operating_context_note` + the previously deferred
  `error_context_target_unresolved`; the exception now uses the real string.
- ⏳ **Deferred (polish, not blocking Phase 3):** a generic `result_deeplink` contract +
  synchronizer rendering. `generate_questions` already surfaces a question-bank URL via its
  preview, so the "open there" affordance largely exists; generalising it is a later cleanup.
- ⏳ **Deferred to a follow-up:** scope the session-allow signature by operating contextid (so an
  R1 session-allow in one course cannot authorise another). Low risk today (no cross-context
  adopter yet); tracked for when a cross-context R1 skill appears.

### Phase 3 — first adopter: `generate_questions`
- Add `coursequery` / `courseid` inputs + `target_context` declaration.
- Drop the inline `get_course_context()`; rely on the passed operating context.
- Map `question_bank_url` → `result_deeplink`.
- The planner's previously-invented `target_courseid` becomes a real, resolved, permission-checked
  field. Lang strings (en+de) for the confirmation + deep-link label.

### Phase 4 — (optional, later) redirect/handoff
- Frontend "continue there" affordance + cross-context thread handoff token. Not required for C.

**Files touched (engine, `bookingextension_agent`):** `dto/target_selector.php` (new),
`services/security/context_resolver.php`, `services/security/operating_context_target_registry.php`
(new), `services/preflight_pipeline.php`, `services/preflight_execution_gate.php` (guard hash),
`executor.php`, `base_skill.php`, `services/decision/agent_decision_service.php`, synchronizer
(deep-link render), `interfaces/` (new provider interface for non-course targets).
**Files touched (domain, `mod_booking`):** `core/skills/generate_questions_skill.php` (lives in the
agent plugin core layer), and any future course-scoped skills; lang en+de.

---

## 6. Security & correctness

- **Gate 1** (agent governance capability) stays at the ambient/teacher level: who may use the
  skill at all.
- **Gate 2** (`require_capability`) moves to the **operating** context: who may do *this* in *that*
  course. This is the load-bearing check for cross-course — a teacher in course A with no
  `moodle/question:add` in course B is correctly blocked there.
- **Guard token** bound to operating context prevents a confirmed-for-course-A command from being
  replayed against course B.
- **Target never silently falls back:** an unresolved/ambiguous target yields a clarification
  ("which course?"), never the ambient context — avoids creating data in the wrong place.
- **Session-allow** (R1 TTL) scoped to (skill, operating context) so a quick re-confirm in one
  course can't authorise another.

---

## 7. Decisions (2026-06-10, Georg) & open items

1. **✅ Decided — confirmation granularity:** read-only cross-context runs **silently**, mutating
   cross-context **confirmed**. Read-only is *not* additionally gated: the per-context capability
   checks (Gate 2 at the operating context) are the protection against unwanted access.
   **→ Follow-up task (own work item, see §8):** audit that every skill *actually* implements its
   context-specific capability checks correctly at the operating context — silent read-only
   cross-context is only safe if that holds.
2. **✅ Decided — course resolver location:** **core** resolver for `CONTEXT_COURSE` (courses are a
   `core.*` concern), **provider hook** for other target levels. Keeps the engine generic and the
   common case dependency-free.
3. **Ambiguous target by name:** reuse the `core.search_courses` disambiguation (list + ask), like
   options/users elsewhere.
4. **Scope cap:** MVP = single target per command; multi-target ("in courses A and B") via the
   existing multi-step planning.

---

## 8. Follow-up: per-skill capability-check audit (prerequisite for silent read-only cross-context)

Because read-only cross-context will run **without** a confirmation, the safety rests entirely on
each skill enforcing the right capability at the **operating** context. Before (or alongside)
enabling cross-context for any read-only skill, audit every skill for:

- Declares the correct `get_required_native_capabilities()` for what it reads/writes (e.g. a skill
  reading other users' data must require `mod/booking:readresponses` or equivalent — like the new
  `diagnose_user_booking`), **and**
- Does not perform its own DB reads that bypass that check at a context other than the one Gate 2
  validated, **and**
- When it resolves a course/module itself, it does so at the operating context (not the ambient
  one) once Phase 1 threads the operating context through.

Deliverable: a short matrix (skill × native caps × read/write scope × cross-context-safe?) kept in
this blueprint or `docs/skills/`. Skills that fail the audit must not opt into `target_context`
until fixed.

---

## 9. Test plan

- **Unit:** `context_resolver::resolve_operating_context` (no target / ancestor / explicit id /
  query / unresolved / ambiguous); guard-token binding to operating context; target_selector build
  from skill input.
- **Capability:** teacher with `moodle/question:add` in target course → allowed; without → Gate 2
  blocks at the operating context.
- **Decision flow:** read-only cross-context executes; mutating cross-context →
  `confirmation_request` carrying the course label; confirm → executes in the target.
- **Back-compat:** full existing booking skill suite with no target selector — identical results
  and identical guard tokens.
- **End-to-end (thread-237 reproduction):** from a booking module, "create 2 questions in course
  Booking" → resolves course → confirm → questions created in *Booking*'s question bank → deep-link
  returned.
