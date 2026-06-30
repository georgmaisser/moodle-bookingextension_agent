# Capability audit 03 — mod_booking R0 read skills

Scope: read-only (R0) booking skills under
`mod/booking/classes/local/wizard/options/skills/`, audited for cross-context /
cross-user / cross-instance capability fidelity. Read-only audit; nothing changed.

## Engine model — verified against code

Two execution entry points, **one** `$skill->execute()` call site
(`executor.php:278`):

- **R0 path bypasses preflight.** `agent_decision_service::handle_decision()`
  splits commands into `$readonlycommands` / `$mutatingcommands`
  (`agent_decision_service.php:726-738`). Read-only commands go through
  `execute_readonly_commands()` (`:1125`) which calls
  `executor->execute_commands()` directly (`:1179`). They never reach
  `handle_preflight()` / `preflight_pipeline->run()`. So a skill's own
  `run_preflight()` AND the preflight-pipeline Gate 2 (`preflight_pipeline.php:207`)
  are **skipped for R0**. The audit premise "R0 skips preflight" is **confirmed**.

- **The executor Gate 2 backstop is unconditional.** Before `execute()`, the
  executor calls `native_capability_guard::missing_capabilities($skill, ...)`
  (`executor.php:266`) — NOT gated on `is_read_only()`. `missing_capabilities()`
  reads `get_required_native_capabilities()` for **any** skill
  (`native_capability_guard.php:53-78`). Therefore a native capability declared by
  an R0 skill **IS enforced** — by the executor backstop, at the **operating
  context** (for R0 = the ambient cmid; R0 does no cross-context resolution).

**Consequence for the headline "R0 + native-cap = dead cap" footgun: FALSE in this
engine.** A native cap declared by an R0 skill is not silently ignored — the
executor backstop enforces it. The real residual risk is the *choice and context*
of that cap, plus skills that declare *no* cap and self-gate (or fail to).

The known-good pattern is visible in the sibling `core/skills/` diagnose family
(`diagnose_permissions_skill.php:249`, `diagnose_notifications_skill.php:213`):
explicit "Cross-user gate (R0 → here)" inside `execute()`.

---

## Per-skill verdicts

### diagnose_user_booking_skill.php — **NEEDS-VERIFICATION / LOW residual** (priority case)

- R0 (`:97`), declares `mod/booking:readresponses` (`:114-116`).
- `execute()` (`:311`) has **no in-skill capability check**. It resolves an
  arbitrary user (`resolve_target_userid` `:384` → `booking_skill_support::resolve_single_user`,
  which resolves any user site-wide by id/email/name with **no actor check**,
  `booking_skill_support.php:1173`) and returns that user's full booking history,
  submitted customform fields (`extract_customform_fields` `:795`), instance-wide
  totals, received-message log (`:651`), and **global** tool_certificate issues
  (`collect_user_certificates` `:900`).

- **Why it is NOT an open hole:** the declared `mod/booking:readresponses` is
  enforced by the executor backstop at the ambient cmid context
  (`executor.php:266`). An actor without `readresponses` in the in-scope booking
  instance is denied (`NO_NATIVE_CAPABILITY`) before `execute()` runs.
  `readresponses` is the correct Moodle cap for "may read other participants'
  booking responses", so the gate is semantically right.

- **Residual concerns (verify with Georg, then decide):**
  1. **No defense-in-depth self-gate.** Unlike its two `core/skills` siblings and
     unlike `diagnose_booking_issue`, this skill self-enforces nothing. It relies
     entirely on the executor backstop. If this skill were ever invoked off the
     executor path (e.g. a future direct caller, a test harness, or a refactor that
     moves R0 execution), it leaks. Recommend adding the same
     `if ($targetuserid !== $userid && !has_capability('mod/booking:readresponses',
     context_module::instance($cmid), $userid)) { return error; }` self-gate that
     the family already uses — cheap, and makes the skill safe-by-itself.
  2. **Global certificate list is wider than the cap's context.**
     `collect_user_certificates()` returns *all* tool_certificate issues for the
     target user across the whole site (`tool_certificate\certificate::get_issues_for_user`,
     `:917`), gated only by `readresponses` *in the actor's one booking instance*.
     The booking + message data is correctly instance-scoped (instance-wide report
     keys on `bookingid` from the ambient cmid, `:514`; message log filters on
     `courseid`, `:673`), but the cert section is not. An actor with `readresponses`
     in instance A can read a user's certificates earned anywhere. Likely acceptable
     for the USI/teacher persona, but flag it.
  3. **Cap context vs. data scope mismatch on no-cmid.** If the agent runs with no
     booking instance in scope (cmid resolves to 0), the operating context falls
     back to a non-module context; `missing_capabilities()` then checks
     `readresponses` at that context. `execute()` does NOT call
     `build_no_instance_scope_result()` (unlike the diagnose_booking/cancellation
     skills), so behaviour with cmid=0 is unguarded by the skill itself and depends
     entirely on where the backstop resolves the context. Recommend adding the
     no-instance-scope guard here too for consistency.

- **Attack trace (currently blocked):** Actor is a plain student in booking
  instance A, opens the agent there, asks "show me the booking history and
  certificates of max@example.com". `resolve_single_user` resolves max site-wide;
  but the executor backstop checks `mod/booking:readresponses` at instance A's
  module context, the student lacks it → `NO_NATIVE_CAPABILITY`, no data returned.
  **Blocked.** The same actor *with* `readresponses` in A would get max's data
  including max's global certificates (concern #2).

- **Verdict:** the named "dead native cap → PII leak" hole does **not** exist
  (cap is live). Reclassify as NEEDS-VERIFICATION for the residual cert-scope
  width and the missing defense-in-depth self-gate.

### diagnose_booking_issue_skill.php — **SAFE**

- R0 (`:48`). No native cap declared; **self-gates** in `execute()`:
  `if ($diagnosticuserid !== $userid && !$this->can_analyze_other_user($cmid))`
  (`:317`), where `can_analyze_other_user` checks `mod/booking:bookforothers` at the
  module context (`:728-731`).
- Option resolution is instance-scoped: explicit optionid verified against
  `bookingid = $cm->instance` (`resolve_option_id` `:797-812`); query resolution via
  `resolve_single_option($cmid, ...)` which is cmid-bound. Cross-instance optionid →
  error (`:809-812`).
- `build_no_instance_scope_result()` guard at top of `execute()` (`:296`).
- Cap choice note: uses `bookforothers` (act-on-behalf) rather than `readresponses`.
  Defensible for a "why can't X book" diagnosis, but worth a consistency decision
  across the diagnose family.

### diagnose_cancellation_issue_skill.php — **SAFE**

- R0 (`:49`). Same shape as diagnose_booking_issue: cross-user self-gate
  `mod/booking:bookforothers` at module context in `execute()` (`:310`, also `:224`),
  and option resolution verified against `bookingid = $cm->instance` (`:865`).
  No-instance-scope guard at top of `execute()` (`:284`).

### get_option_details_skill.php — **HOLE (cross-instance / system-wide leak via direct ids and title search)**

- R0 (`:63`). No native cap declared, no actor capability check anywhere in
  `execute()` (`:244`).
- **Direct id bypass:** in `resolve_target_option_ids()` (`:582`), `optionid` /
  `optionids` from the input are accepted **with no cmid scoping at all**
  (`:586-597`) and loaded via the global
  `singleton_service::get_instance_of_booking_option_settings()` (`:272`). An option
  id from *any* instance is returned.
- **System-context site-wide title search:** when cmid=0,
  `resolve_option_ids_for_system_context()` (`:650`) does a `LIKE` search across the
  **entire** `{booking_options}` table (`:667-670`) with no instance/visibility
  filter. (When cmid>0, query resolution correctly uses cmid-scoped
  `resolve_single_option`, `:612` — so the leak is the direct-id path and the cmid=0
  path.)
- **Data exposed:** `return_booking_option_information()` plus teachers, sessions,
  price, and (when requested) custom field values for an option the actor may have
  no access to. Teacher identities and custom fields can be PII / sensitive.
- **Attack trace:** Actor in instance A asks "give me all details about option
  1422" where 1422 lives in instance B (actor has no access). `optionid=1422`
  passes straight through `:586-589`, settings load globally, details returned.
  **Leaked.** Variant: from a non-booking page (cmid=0) "tell me everything about
  the First Aid Course" → site-wide title match across all instances.
- **Fix:** scope every resolved id to a booking instance the actor can access
  before loading settings. Concretely: for each candidate optionid, look up its
  `bookingid` → cm → `context_module`, and require the actor to be able to see that
  option (e.g. `has_capability('mod/booking:view', ...)` plus `uservisible`, or at
  minimum membership/visibility of the instance). For the cmid>0 case keep the
  existing `bookingid = $cm->instance` confinement and reject direct ids that don't
  belong to it. For cmid=0, restrict the title search to instances the actor can
  access (reuse the `list_accessible_booking_instances()` pattern from the base) or
  drop the system-wide search entirely.
- **Fallback design:** if the actor can't see the named option, return the standard
  "not found / which instance?" clarification rather than the option payload — never
  over-return.

### search_options_skill.php — **SAFE**

- R0 (`:39`). Strictly instance-scoped: `execute()` resolves cmid and returns
  `build_no_instance_scope_result()` when none (`:210-213`); `run_preflight` also
  guards (`:182-185`). Every lookup passes `$cmid`
  (`find_existing_options_by_exact_title($cmid,...)`, `search_option_candidates_for_preview($cmid,...)`),
  and `search_option_candidates` binds `bookingid = $booking->id` from the cmid
  (`booking_skill_support.php:813`). No direct-id bypass. Returns only id / title /
  link — no participant or PII data. Cross-instance search is not possible.

### analyze_rules_skill.php — **SAFE (low risk)**

- R0 (`:41`). Reads booking *rules* (admin configuration, not user PII).
- System-context fallback is gated on `mod/booking:editbookingrules` at system
  context (`user_can_analyze_system_rules`, `:188`; enforced in `execute()` `:261`
  and preflight `:216`).
- Minor note: the `cmid > 0` branch lists rules for the module context without an
  in-`execute()` capability check (`:259-260`). Acceptable because (a) rules are
  configuration, not cross-user data, and (b) the scope is the actor's own ambient
  instance. Not a hole; could add a `mod/booking:view`-level check for tidiness.

### list_option_properties_skill.php — **SAFE (static)**

- R0 (`:37`). Returns option *schema/property* metadata derived from create/update
  task schemas. No DB access, no option/user/instance data. Inert.

---

## Summary table

| Skill | Verdict | Cross-user gate | Cross-instance option | Native cap |
|---|---|---|---|---|
| diagnose_user_booking | NEEDS-VERIFICATION (low residual) | via executor backstop `readresponses` (no in-skill self-gate) | n/a (user-scoped; cert list is global) | `mod/booking:readresponses` — **enforced**, not dead |
| diagnose_booking_issue | SAFE | self-gate `bookforothers` @module | confined to `bookingid` | none |
| diagnose_cancellation_issue | SAFE | self-gate `bookforothers` @module | confined to `bookingid` | none |
| get_option_details | **HOLE** | n/a | **NOT confined** (direct ids + cmid=0 title search) | none |
| search_options | SAFE | n/a | confined to cmid `bookingid` | none |
| analyze_rules | SAFE (low) | system rules gated `editbookingrules` | n/a | none |
| list_option_properties | SAFE (static) | n/a | n/a | none |

## Corrected understanding of the footgun

The premise "an R0 skill that declares native caps has them silently ignored" is
**not true for this engine**: the executor's Gate 2 backstop (`executor.php:266`)
runs for every skill before `execute()` and enforces declared native caps. R0 only
skips the *preflight* copy of that gate, not the executor backstop. The genuine,
exploitable gap is **`get_option_details`, which declares no cap and does not scope
direct/system option ids to an instance the actor can access.**
