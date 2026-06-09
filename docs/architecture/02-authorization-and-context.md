# 02 · Authorization & context

> **Scope.** How the engine authorizes a caller and resolves the Moodle context that
> scopes a conversation. Flowchart subgraph: `AUTHZ`.

Two ideas drive this chapter:

1. **One capability gates the agent**, and per-skill capabilities gate *what it may do*.
2. **The Moodle module `contextid` is the scope key** for everything stateful — threads,
   session allowances, confirmations.

**Files:** `classes/local/wbagent/services/security/authorization_service.php`,
`classes/local/wbagent/aiready.php`, `db/access.php`.

---

## 1. The authorization service

`authorization_service` is the single gate between an external function and the engine. It
exposes four methods (the first three are the flowchart's `AZ1`–`AZ3`):

| Method | Checks | On failure |
|--------|--------|------------|
| `is_agent_extension_installed(): bool` *(static)* | the plugin is installed and upgraded (via `core_plugin_manager`) | returns `false` (never throws) |
| `require_use_capability(int $userid, int $contextid): void` | plugin installed **and** `has_capability('bookingextension/agent:useaiinstructions', context)` | throws `required_capability_exception` (`nopermissions`) |
| `require_valid_context(int $contextid): void` | the context is an active **booking module** context (delegates to a private `require_booking_module_context()`) | throws `moodle_exception('invalidcontext')` or `moodle_exception('invalidcoursemodule', 'bookingextension_agent')` |
| `can_use(int $userid, int $contextid): bool` | the same as `require_use_capability` + valid context, but **safe** | returns `false` (catches all `Throwable`) |

The split between the throwing `require_*` methods and the boolean `can_use()` matters:
external functions call `can_use()` so they can return a clean `permission_denied` payload
instead of an exception page, while internal call sites use `require_*` to fail hard.

The private `require_booking_module_context()` resolves a module context, asserts it is a
`context_module`, and confirms it belongs to a `booking` course module — returning the
`context_module`. This is also where the **context-authority** rule is enforced: the agent
only ever operates inside a booking activity's module context.

---

## 2. Context authority & cmid resolution

The runtime carries the **module `contextid`** as the scope key, not a course id or a
booking instance id. `agent_runtime::resolve_cmid_from_contextid()` converts it to a cmid
when a subsystem needs the course-module:

```php
$ctx = context::instance_by_id($contextid, MUST_EXIST);
// must be a context_module → $ctx->instanceid is the cmid
```

Consequences that recur throughout the engine:

- A **thread** is unique per `(userid, contextid)` (ch. 03).
- A **session allowance** (auto-confirm) is keyed by `(userid, contextid)` (ch. 03 §6).
- A **confirmation** belongs to the thread in that context.

This is the `LG_CTX` legend in the flowchart: *"Runtime uses Moodle contextid as the scope
key."*

---

## 3. Capabilities (`db/access.php`)

The plugin declares two fixed capabilities plus a large, **generated** set of per-skill
capabilities.

### Fixed capabilities

| Capability | Type | Context | Default role |
|------------|------|---------|--------------|
| `bookingextension/agent:useaiinstructions` | write | `CONTEXT_MODULE` | `editingteacher` (allow) |
| `bookingextension/agent:debugskillselection` | write | `CONTEXT_SYSTEM` | `manager` (allow) |
| `bookingextension/agent:viewbenchmarks` | read | `CONTEXT_SYSTEM` | `manager` (allow) |
| `bookingextension/agent:managebenchmarks` | write | `CONTEXT_SYSTEM` | — (manual) |

`…:useaiinstructions` is **the** gate: without it `can_use()` is false and the chat panel
is inert.

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

These per-skill capabilities are what the [executor's releasability check](11-executor.md)
(`skill_executability_evaluator`, deny reason `missing_capability`) enforces at run time: a
user may *use* the agent yet still be blocked from a specific skill. The mapping from skill
to capability is part of the skill-governance picture — see
[operations/governance.md](../operations/governance.md).

---

## 4. Readiness (`aiready`)

`aiready` produces the readiness snapshot the chat panel shows *before* the user types, and
backs the readiness gate's reason mapping in [ch. 01 §3](01-entry-and-web-services.md). It
runs five sequential checks, each defensively wrapped:

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
