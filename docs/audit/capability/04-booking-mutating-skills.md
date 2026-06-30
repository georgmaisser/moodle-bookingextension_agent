# Capability Audit 04 — mod_booking mutating skills (cross-context / cross-instance write authorization)

Date: 2026-06-30
Scope: the mutating booking skills under
`mod/booking/classes/local/wizard/options/skills/` plus their shared base + execute path
(`booking_skill_base.php`, `booking_skill_mutation_execute_service.php`, `booking_skill_support.php`),
audited against the engine's Gate-2 native-capability model.

CONCERN: a user with write rights in booking instance **A** (the ambient context) must not be able
to mutate (create/update options, book users, change rules, configure) an instance **B** / course
they cannot access.

---

## 0. Engine model — verified ground truth

The brief's model holds. Verified flow:

1. `preflight_pipeline::run()` resolves the **operating context** per command:
   `skill_operating_context_resolver::resolve($skill, $input, $ambient, $userid)`
   (`preflight_pipeline.php:169`).
   - A skill opts into a non-ambient target only by exposing `supports_target_context()` +
     `get_target_selector()` (`skill_operating_context_resolver.php:96-100`). A skill that exposes
     neither — **every tgt:0 booking skill** — returns the **ambient** context unchanged
     (`skill_operating_context_resolver.php:71-73`).
2. Gate 2 (central) runs at the operating context:
   `native_capability_guard::missing_capabilities($skill, $operatingcontextid, $userid)`
   (`preflight_pipeline.php:207`). The guard resolves the context with `MUST_EXIST` and
   **fails closed** if it cannot (`native_capability_guard.php:65-70`); checks every declared cap
   via `has_capability($cap, $context, $userid)` (`:73-77`).
3. `$skill->preflight($input, $operatingcontextid, $userid)` runs at that same context
   (`preflight_pipeline.php:216`).
4. The executor re-runs the **same** Gate-2 guard at `operating_contextid` immediately before
   `execute()` as the authoritative backstop (`executor.php:266`, then `:278`).
5. `booking_skill_base::execute()` / each skill's `execute()` derive the cmid strictly from the
   passed (operating) context via `resolve_cmid_from_context_or_cmid()`
   (`booking_skill_base.php:768-771`, `:843-854`) — it only accepts a `context_module`'s instanceid,
   else 0.

For **tgt:0** skills, operating context == ambient context, so Gate 2 + cmid both bind to the
ambient instance. Cross-instance safety for tgt:0 skills therefore rests on the **option/rule
resolver being scoped to the ambient cmid** — which is the linchpin the brief flagged. It holds
(see §4).

**Guard token** (`preflight_execution_gate.php:136-163`): `sha256(skill : operating_contextid :
json(preparedinput))`. Keyless — an integrity check, not a MAC. It is **not** a client-forgeable
hole because the WS confirm path never accepts commands from the client: `ai_confirm_run` takes a
`queue_item_id` and rehydrates the server-persisted, server-preflighted commands
(`ai_confirm_run.php:153-160`, params `:59-66` carry no commands). The same WS entry enforces
context validity + readiness (`ai_confirm_run.php:100-101,103`) and **thread ownership**
(`:127-130`). Guard token + `operating_contextid` are issued server-side after preflight
(`agent_decision_service.php:1045-1050`).

---

## Per-skill verdicts

| Skill | Risk | tgt | Native cap (Gate 2) | Verdict |
|---|---|---|---|---|
| create_option | R2 | 1 (module) | `mod/booking:addoption` | SAFE |
| create_slotbooking_option | R2 | 1 (module) | inherits addoption | SAFE |
| create_selflearning_option | R2 | 1 (module) | inherits addoption | SAFE |
| update_option | R2 | 0 | `mod/booking:addeditownoption` | SAFE (1 defense-in-depth gap) |
| update_option_trainer | R2 | 0 | `mod/booking:addeditownoption` | SAFE |
| bulk_update_options | R2 | 0 | `mod/booking:addeditownoption` | SAFE |
| book_users | R3 | 0 | `mod/booking:bookforothers` | SAFE (brief's "nat:0" premise is wrong) |
| configure_booking_instance | R2 | 0 | `mod/booking:updatebooking` | SAFE |
| add_price_category | R2 | 0 | `moodle/site:config` | SAFE |
| create_rule_from_template | R2 | 0 | `mod/booking:editbookingrules` | SAFE |
| update_rule_from_template | R2 | 0 | `mod/booking:editbookingrules` | SAFE |

No BLOCKER/HIGH holes found. One MEDIUM defense-in-depth gap (update_option execute path) and two
LOW observations are recorded below.

---

## 1–6. Detailed traces

### create_option / create_slotbooking_option / create_selflearning_option (tgt:1 module target)

- **Target resolution**: uses the `module_targeted_skill` trait
  (`create_option_skill.php:39`; `module_targeted_skill.php:44`). `supports_target_context()=true`,
  `get_target_context_level()=CONTEXT_MODULE`, selector built from `cmid`/`activityquery`
  (`module_targeted_skill.php:48-89`). The engine resolves the operating context to the named
  instance (course-first, then site, auto-pick when unique).
- **Capability at the resolved target**: Gate 2 checks `mod/booking:addoption`
  (`create_option_skill.php:57`) at the **operating** context in both the pipeline
  (`preflight_pipeline.php:207`) and executor (`executor.php:266`). The skill's own preflight/execute
  derive the cmid from the **passed operating context** and re-check addoption there
  (`create_option_skill.php:695,705` and `:1452-1453`). So creating an option in instance B requires
  `addoption` at B — the cross-context jump is itself capability-gated. **SAFE.**
- The slot/selflearning subclasses forward the operating context unchanged
  (`create_slotbooking_option_skill.php:227,243`; `create_selflearning_option_skill.php:140,162`).
  Same authorization. SAFE. (slotbooking additionally gated on PRO,
  `create_slotbooking_option_skill.php:216`.)

### update_option (tgt:0)

- **Target resolution**: ambient cmid (`update_option_skill.php:311`). Explicit `optionid` is
  verified to belong to the ambient instance — `record_exists('booking_options', id + bookingid =
  cm->instance)` → else `INVALID_OPTIONID` (`update_option_skill.php:408-433`). The query path uses
  the cmid-scoped resolver `booking_skill_support::resolve_single_option($cmid, ...)`
  (`:371`), which is locked to `bookingid = cm->instance` (see §4).
- **Capability**: `mod/booking:addeditownoption` declared (`:41`); Gate 2 at ambient + own re-check
  at ambient cmid (`:312`).
- **Cross-instance attack**: actor in A names option in B by query → resolver returns
  `OPTION_NOT_FOUND` (option not in A). Names B's optionid explicitly → `INVALID_OPTIONID`
  (`:418`). **Blocked at resolution.**
- **MEDIUM defense-in-depth gap** — the execute service trusts a pre-supplied optionid: in
  `booking_skill_mutation_execute_service`, the single-option update branch sets
  `$data->id = (int)$input['optionid']` **without re-verifying it against `cm->instance`**
  (`booking_skill_mutation_execute_service.php:668-669`); `preflight_validate()` only resolves an
  optionid when it is *empty* (`:1171`) and never re-scopes a present one. The last DB write is
  `booking_option::update($itemdata, $context)` (`:971`), and core `booking_option::update()` does
  **not** verify the option's existing `bookingid` against `$data->bookingid` or the passed
  `$context` (`booking_option.php:4886-4920`). So the ONLY thing scoping a single update's optionid
  is the skill preflight `INVALID_OPTIONID` check. Not currently exploitable (WS path is not
  client-replayable; the planner/LLM cannot inject a forged resolved optionid past the server-side
  preflight that scoped it), but it is a single-point-of-failure: contrast bulk (re-resolves scoped
  at execute, §below) and update_rule (explicit context guard, §update_rule). Mitigating factor: the
  option's `bookingid` is only ever changed by the `moveoption` field, which is **not** an
  agent-exposed field (`grep moveoption` over `classes/local/wizard/` = no hits), so the worst case
  is in-place mutation of a B-option, not a move into A.

### update_option_trainer (tgt:0)

- Symmetric to update_option. Ambient cmid (`update_option_trainer_skill.php:240`); native cap
  `addeditownoption` re-checked at ambient (`:241`); explicit optionid scoped to `cm->instance` →
  `INVALID_OPTIONID` (`:283-300`); query path via cmid-scoped `resolve_single_option`
  (`:265-269`). Trainer (teacherquery/teacherids) resolves users **site-wide**, but the write target
  is an A-instance option, so no cross-instance write. **SAFE.**

### bulk_update_options (tgt:0)

- Ambient cmid (`bulk_update_options_skill.php:261`); native cap `addeditownoption` at ambient
  (`:262`). Explicit optionids validated against `cm->instance` → `INVALID_OPTION_ID`
  (`:301`). **More defensive than single update**: the execute path re-resolves via
  `resolve_bulk_option_ids_for_execute($cmid, …)`
  (`booking_skill_mutation_execute_service.php:703`), which scopes **every** path (explicit ids,
  query, apply_to_all) to `bookingid = cm->instance`
  (`booking_skill_support.php:294-343`, lines 309/329/334). `apply_to_all` cannot escape the ambient
  instance. **SAFE.**

### book_users (R3, tgt:0) — the brief's RED FLAG

- **The brief's "nat:0 / selfcap:0" premise is incorrect.** book_users declares the native
  capability `mod/booking:bookforothers` (`book_users_skill.php:44`). Full authorization:
  1. Gate 1 (skill governance) at ambient.
  2. Gate 2 central — `bookforothers` at the operating (ambient) context, enforced in the pipeline
     (`preflight_pipeline.php:207`) **and** the executor backstop (`executor.php:266`).
  3. The skill's own preflight re-checks `bookforothers` at the resolved ambient cmid
     (`book_users_skill.php:294`).
  4. Booking conditions (`bo_info::get_condition_results`) run per user with the privileged
     (`true`) recheck so only true hard blockers stop a booking; soft-only blockers raise a
     confirmation issue (`book_users_skill.php:388-434`).
- **Target resolution is cmid-scoped**: `resolve_option_id()` checks an explicit optionid against
  `bookingid = cm->instance` (`book_users_skill.php:648`) and routes the query path through
  `resolve_single_option($cmid, …)` (`:686`). In `execute()`, a pre-supplied `resolvedoptionid` is
  trusted (`:501`) — but it is produced only by the cmid-scoped preflight above and carried under
  the server-issued guard token. `book_users_for_option()` itself has no separate context check
  (`booking_skill_support.php:1692`) — it loads the option by id and runs the bookit flow in the
  actor's session — so the scoping defense is entirely the option resolver + the `bookforothers`
  gate at the ambient context.
- **Cross-instance attack**: actor with `bookforothers` in A names an option in B → `OPTION_NOT_FOUND`
  / `INVALID` at resolution (option not in A's instance). Cannot book into B. **SAFE.** No
  R3-irreversibility hole: there is a real native capability gate, not just the Gate-1 skill cap.

### configure_booking_instance (tgt:0)

- **Instance-scoped only**, no cross-instance concept. Bookingid is derived strictly from the
  ambient cmid: `$cm = get_coursemodule_from_id('booking', $cmid); $bookingid = $cm->instance`
  (`configure_booking_instance_skill.php:378-383`) — never from input. Native cap
  `mod/booking:updatebooking` declared (`:169`); Gate 2 at ambient + own check at ambient module
  context (`:319-320`). Update persists via `booking_update_instance()` on the ambient record
  (`:504`). **SAFE.**

### add_price_category (R2, tgt:0)

- **Site-scoped** (price categories are global). Native cap `moodle/site:config` declared
  (`add_price_category_skill.php:38`); Gate 2 checks it at the ambient module context (capability
  walks up to system), and the skill additionally checks `moodle/site:config` at `context_system`
  in both preflight and execute (`:182,253`). No instance/optionid concept → no cross-instance
  surface. Requires site admin. **SAFE.**

### create_rule_from_template (R2, tgt:0)

- Ambient cmid (`create_rule_from_template_skill.php:201`); native cap
  `mod/booking:editbookingrules` at ambient (`:202`). Execute attaches the rule to the ambient
  instance: `$contextid = $this->ruleservice->get_module_contextid($cmid)`
  (`:425`) → `create_rule_from_template($contextid, …)`. Templates are read-only sources. No
  cross-instance write. **SAFE.**

### update_rule_from_template (R2, tgt:0) — best-coded path

- Ambient cmid (`update_rule_from_template_skill.php:193`); native cap `editbookingrules` at ambient
  (`:194`). Rule resolution is scoped to the ambient module context:
  `resolve_rule($contextid = get_module_contextid($cmid), ruleid, rulequery)` (`:209-215`).
- **Defense-in-depth at the service level**: even a pre-supplied/forged `ruleid` is rejected —
  `update_rule_from_template()` re-fetches the rule and refuses if
  `(int)$record->contextid !== $contextid` (`booking_rules_agent_service.php:448-453`). This is the
  context guard the single update_option path is missing. **SAFE.**

---

## Holes & recommendations

- **[MEDIUM] update_option single-update execute path does not re-scope a trusted optionid.**
  `booking_skill_mutation_execute_service.php:668-669` sets `$data->id = $input['optionid']` with no
  `bookingid = cm->instance` check; `preflight_validate()` (`:1171`) only resolves an *empty*
  optionid; core `booking_option::update()` has no cross-instance guard (`booking_option.php:4886+`).
  Not currently exploitable (server-side preflight scopes it; WS confirm is not client-replayable),
  but it is the only mutating path relying on a single upstream check.
  **Fix**: in the execute service's update branch (and/or `persist_and_verify_single_option`), assert
  `$DB->record_exists('booking_options', ['id' => $data->id, 'bookingid' => (int)$cm->instance])`
  before `booking_option::update()` — mirroring update_rule's `:448` guard and bulk's
  `resolve_bulk_option_ids_for_execute` scoping. Cheap, removes the single-point reliance.

- **[LOW] `book_users_for_option()` / `booking_option::update()` carry no own context assertion.**
  Both trust the optionid handed to them. Defensible (resolution upstream is scoped, actor session),
  but a future caller that skips the scoped resolver inherits no protection. Consider a thin
  `assert_option_in_instance($optionid, $bookingid)` helper used by every execute branch.

- **[LOW] Guard token is keyless (`preflight_execution_gate.php:139`).** Safe today only because the
  WS confirm path rehydrates server-persisted commands rather than accepting them from the client
  (`ai_confirm_run.php:153-160`). If any future entry point accepts client-supplied commands +
  tokens, this becomes forgeable. Keep it strictly internal, or HMAC it with a site secret.

## Conclusion

Every mutating booking skill is correctly gated: each declares a real native Moodle capability that
`native_capability_guard` enforces at the operating context (ambient for tgt:0, the resolved module
for the create family), in both the preflight pipeline and the executor backstop. Cross-instance
reach for tgt:0 skills is blocked at option/rule **resolution**, which is uniformly scoped to the
ambient `bookingid = cm->instance`. The brief's two red flags do not hold: book_users **does** carry
a native cap (`mod/booking:bookforothers`), and the cross-instance update_option attack is blocked
by `INVALID_OPTIONID` at preflight. The only substantive finding is a MEDIUM defense-in-depth gap in
the single-update execute path that should be hardened to match the bulk and rule paths.
