# 02 · Authorization & context

> **Scope.** How the engine authorizes a caller and resolves the Moodle context that
> scopes a conversation. Flowchart subgraph: `AUTHZ`.

Two ideas drive this chapter:

1. **One capability gates the agent**, and per-skill capabilities gate *what it may do*.
2. **The Moodle `contextid` is the scope key** for everything stateful — threads,
   session allowances, confirmations. Since the context consolidation the engine is
   context-level-agnostic: module, course and system contexts are all valid hosts.

**Files:** `classes/local/wizard/services/security/authorization_service.php`,
`classes/local/wizard/aiready.php`, `db/access.php`.

---

## 1. The authorization service

`authorization_service` is the single gate between an external function and the engine. It
exposes these methods (the first three are the flowchart's `AZ1`–`AZ3`):

| Method | Checks | On failure |
|--------|--------|------------|
| `is_agent_extension_installed(): bool` *(static)* | the plugin is installed and upgraded (via `core_plugin_manager`) | returns `false` (never throws) |
| `require_use_capability(int $userid, int $contextid): void` | plugin installed **and** `has_capability('bookingextension/agent:useaiinstructions', context)` | throws `required_capability_exception` (`nopermissions`) |
| `require_valid_context(int $contextid): void` | the context exists and is one of `CONTEXT_MODULE`, `CONTEXT_COURSE`, `CONTEXT_COURSECAT`, `CONTEXT_USER`, `CONTEXT_SYSTEM` — user contexts host the dashboard for the navbar entry point (delegates to a private `resolve_valid_context()`) | throws `moodle_exception('invalidcontext')` |
| `require_capability_at(int $userid, context $operatingcontext, string $capability): void` | re-checks a capability at a (possibly switched) operating context (available helper; currently no runtime caller — Gate 2 is enforced via `native_capability_guard` in preflight) | throws `required_capability_exception` |
| `can_use(int $userid, int $contextid): bool` | the same as `require_use_capability` + valid context, but **safe** | returns `false` (catches all `Throwable`) |

The split between the throwing `require_*` methods and the boolean `can_use()` matters:
external functions call `can_use()` so they can return a clean `permission_denied` payload
instead of an exception page, while internal call sites use `require_*` to fail hard.

The private `resolve_valid_context()` resolves the context and asserts its level is in the
allowed set — there is **no** booking-module assertion anymore: the agent may be hosted by
any module, course or system context. Whether a given *skill* may run in that context is a
separate question, answered by per-skill capabilities (§3) and each skill's native-capability
preflight check (Gate 2).

---

## 2. Context authority & `agent_context`

The runtime carries the **`contextid`** as the scope key, not a course id, cmid or a
booking instance id. The value object `dto\agent_context` is the single carrier for
"where am I running": built once at the entry point via
`agent_context::from_contextid()` (after `authorization_service::require_valid_context()`),
it exposes the context id, level and display name, and resolves module details **lazily and
optionally**:

```php
$ctx = agent_context::from_contextid($contextid);
$ctx->id();                  // the authoritative scope key
$ctx->is_module('booking');  // false outside a booking module — no exception
$ctx->cmid();                // ?int — null outside a module context
$ctx->display_name();        // generic context name for prompts/UI
```

There is no `resolve_cmid_from_contextid()` anymore; nothing in the engine assumes a
course-module behind the context.

Consequences that recur throughout the engine:

- A **thread** is unique per `(userid, contextid)` (ch. 03).
- A **session allowance** (auto-confirm) is keyed by `(userid, contextid)` (ch. 03 §6).
- A **confirmation** belongs to the thread in that context.

This is the `LG_CTX` legend in the flowchart: *"Runtime uses Moodle contextid as the scope
key."*

### Ambient vs operating context

The thread's `contextid` is the **ambient** context. A single command may nonetheless act on
a *different* instance — a module-targeting skill can name another activity (e.g. via an
`activityquery`), so the context it actually mutates is resolved per command. That resolved
context is the **operating context**. The split matters for authorization:

- **Gate 1** — the agent *use* capability (`require_use_capability`, §1) — is enforced once at
  the **ambient** entry point.
- **Gate 2** — the skill's native per-skill capabilities (§3) — is enforced at the **operating
  context**, centrally in preflight, *after* the target is resolved. See
  [ch. 09 §2b](09-preflight-pipeline.md#2b-operating-context-resolution--gate-2-per-command)
  for the resolver (`skill_operating_context_resolver`), the scope cascade, the
  `CONTEXT_TARGET_UNRESOLVED` clarification, and `native_capability_guard`. This is the
  `LG_OPCTX` legend.

---

## 3. Capabilities (`db/access.php`)

The plugin declares several fixed capabilities plus a large, **generated** set of per-skill
capabilities.

### Fixed capabilities

| Capability | Type | Context | Default role |
|------------|------|---------|--------------|
| `bookingextension/agent:useaiinstructions` | write | `CONTEXT_MODULE` | `editingteacher` (allow) |
| `bookingextension/agent:ignoreaiavailability` | read | `CONTEXT_COURSE` | `manager` (allow); site admins implicitly | 
| `bookingextension/agent:debugskillselection` | write | `CONTEXT_SYSTEM` | `manager` (allow) |
| `bookingextension/agent:managegovernance` | write (`RISK_CONFIG`) | `CONTEXT_SYSTEM` | `manager` (allow) |
| `bookingextension/agent:viewbenchmarks` | read | `CONTEXT_SYSTEM` | `manager` (allow) |
| `bookingextension/agent:managebenchmarks` | write | `CONTEXT_SYSTEM` | — (manual) |
| `bookingextension/agent:runbenchmarks` | write (`RISK_CONFIG`) | `CONTEXT_SYSTEM` | — (admin-only; delegable) |

`…:useaiinstructions` is **the** gate: without it `can_use()` is false and the chat panel
is inert. `…:ignoreaiavailability` belongs to the availability layer (§3a below).

The four `CONTEXT_SYSTEM` caps gate the admin-style pages and actions: `debugskillselection`
the selection-debug page, `managegovernance` the skill-governance page (inspect contracts,
enable/disable skills, rebuild the embedding catalog), `viewbenchmarks` the benchmark report,
`managebenchmarks` the baseline-pin **write** on it, and `runbenchmarks` the "Run benchmark"
button (a live run issues real LLM calls, so it is admin-only by default). These pages were
previously gated on `moodle/site:config`; each now has its own **delegable** capability so a
manager can be granted the page without full site config. The page itself calls
`admin_externalpage_setup()` only when the user holds `moodle/site:config` (so the admin tree
renders for admins) and otherwise sets the page up manually — the real gate is always the
`require_capability()` on the cap above; admins still pass implicitly via
`moodle/site:doanything`.

### 3a. The availability layer (`enableaitools` toggles + bypass)

Distinct from permissions, the agent honours Moodle core's **AI availability toggles**:
the per-course `enableaitools` field and the per-course-module `enableaitools` field.
These express *"AI should not be used here"* — a steering instrument aimed at
non-privileged users, not a rights system. The full design rationale and the staged
rollout (admin → teacher → student) live in
[`agent_permissions_concept_2026-06-10.md`](../Blueprints/agent_permissions_concept_2026-06-10.md).

Enforcement is centralised in `orchestrator::get_runtime_provider_status()` and works
like this for the **current user** (`$USER` — the status is always computed inside a
user-facing request):

1. `has_capability('bookingextension/agent:ignoreaiavailability', $context)` —
   **bypass holders skip both toggles entirely.** Site admins pass implicitly
   (`moodle/site:doanything`); managers hold it by default; an admin can grant it per
   course/category to selected trusted teachers.
2. Without the bypass, the **course toggle** is read via the enclosing course context
   (`get_course_context(false)`; no enclosing course — dashboard, system — means no
   course toggle applies) and the **module toggle** only inside a module context.
3. The result feeds `courseenabled` / `contextenabled` / `runtimeavailable`, which both
   the readiness panel (`aiready`) and the entry points (`ai_send_message`,
   `activate_trial_context`) consume — display and behaviour stay consistent by
   construction. The status array carries `availabilitybypassed` so the readiness
   panel can label skipped toggles honestly ("not restricted for your role") instead
   of pretending they are enabled.

Net effect per audience: **teachers** (no bypass) can use the agent only in courses
where they hold `useaiinstructions` *and* AI is allowed; **admins/managers** are never
blocked by availability toggles. Note the toggle's direction: core defaults
`enableaitools` to *enabled* (opt-out per course), so restricting teachers to selected
courses means disabling the toggle elsewhere.

### Per-skill capabilities

`db/access.php` programmatically defines a capability per skill, named
`bookingextension/agent:skill_{skillsuffix}`, each carrying
`RISK_DATALOSS | RISK_XSS`, assigned by audience:

- **teacher skills** (`teacher` + `editingteacher`, module level) — the everyday set
  (e.g. `booking_book_users`, `booking_create_option`, `booking_update_option`,
  `booking_search_options`, `core_search_users`, …).
- **manager skills** (`manager`, module level) — higher-impact configuration
  (e.g. `booking_configure_booking_instance`, `booking_create_rule_from_template`,
  `booking_core_send_user_message`, …).
- **admin-only skills** (no default archetype, manual assignment) — e.g.
  `booking_create_user`.

> **Per-target visibility on top of the cap.** Holding a skill's capability is necessary but
> not always sufficient for *whose* data is returned. `core.search_users` additionally filters
> every free-text-matched candidate through `user_can_view_profile()` and drops users the actor
> shares no context with, so the module-level cap cannot be used to enumerate site-wide PII (a
> student in the actor's own course is still returned). Read skills should follow this pattern:
> the capability gates the action, Moodle's profile/identity visibility gates the exposure.

These per-skill capabilities are what the [executor's releasability check](11-executor.md)
(`skill_executability_evaluator`, deny reason `missing_capability`) enforces at run time: a
user may *use* the agent yet still be blocked from a specific skill. The mapping from skill
to capability is part of the skill-governance picture — see
[operations/governance.md](../operations/governance.md).

### 3b. Full-access vs. readonly (the PRO / WB-LLM gate)

Capabilities decide *who* may run *which* skill; a separate **license gate** decides whether
the site may run the *write/PRO* skills at all. `agent_access_service::has_full_access()`
returns true when either:

- the primary enabled AI provider's configured action **endpoint is a Wunderbyte LLM host**
  (`agent_access_service::is_wunderbyte_host()` — a site driving the WB gateway gets full
  access), or
- the site holds a **PRO license** (`wb_license`, products `wbagent` / `bookingagent`).

Without full access the agent runs in **readonly mode**: PRO/write skills are filtered out of
discovery and, as a backstop, the executor denies them with `DENY_REQUIRES_PRO`
([ch. 11 §3](11-executor.md#3-releasability)). This is orthogonal to the per-skill capability
check — a skill must pass *both* the license gate and the user's capability.

---

## 4. Readiness (`aiready`)

`aiready` produces the readiness snapshot the chat panel shows *before* the user types, and
backs the readiness gate's reason mapping in [ch. 01 §3](01-entry-and-web-services.md).
It is constructed context-agnostically as `new aiready(int $contextid, int $userid)`;
booking-specific extras (module config URL, module AI toggle fallback, booking statistics
via the duck-typed `mod_booking\…\booking_readiness_provider`) only apply when the context
is a booking module — every other context level gets neutral values and a generic welcome
string. The course/module toggle rows are only rendered where the respective toggle
exists (course row needs an enclosing course, module row a module context), and for
availability-bypass holders (§3a) their detail text says the toggles do not apply to
this user rather than claiming they are enabled. It runs five sequential checks, each
defensively wrapped:

| # | Check | Source | Default if core too old |
|---|-------|--------|-------------------------|
| 1 | provider configured | `core_ai` manager has provider instances | false |
| 2 | provider active | at least one enabled provider (native, or legacy Wunderbyte via OpenAI-compat) with the needed actions | false |
| 3 | course enabled | `is_ai_tools_enabled_in_course()` if present | true |
| 4 | context enabled | module `enableaitools` toggle (Wunderbyte path) or `is_action_available_in_context()` (native) | false |
| 5 | user capability | `authz->can_use(userid, contextid)` | false |

The overall verdict is:

```
readyforchat = provideractive AND courseenabled AND contextenabled AND hascapability
```

and the failure reason (`subsystem_missing`, `no_provider`, `provider_inactive`,
`actions_missing`, `course_disabled`, `context_disabled`, `exception_thrown`) is what the
entry gate turns into an `error_ai_*` string.

---

## See also

- [01 · Entry layer](01-entry-and-web-services.md) — where these checks are invoked.
- [03 · Conversation store](03-conversation-store.md) — the `(userid, contextid)`-scoped
  state.
- [11 · Executor](11-executor.md) — per-skill capability enforcement at run time.
- [15 · Risk classes](15-risk-classes.md) — why `RISK_DATALOSS` skills also gate on
  confirmation.
