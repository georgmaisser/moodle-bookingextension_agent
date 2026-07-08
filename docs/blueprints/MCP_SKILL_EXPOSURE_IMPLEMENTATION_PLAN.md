# Implementation plan: MCP facade for agent skills

Status: plan (2026-07-08), based on `MCP_SKILL_EXPOSURE.md`. No code yet.

Goal: expose the wizard skill catalog as MCP tools for Claude via a **generic**
facade — three web service functions total (`mcp_list_tools`, `mcp_call_tool`,
`mcp_confirm_tool`), a thin stdio bridge, and zero changes to any skill.

## 0. Design constraints (binding)

1. **Engine dual-track safety.** All MCP substance lives in
   `classes/local/wizard/services/mcp/` — the namespace subtree that moves 1:1
   in the planned `local_wizard` extraction. The external-function classes are
   ~30-line shims. Every shim starts with
   `authorization_service::check_use_readiness()` whose first gate is
   `is_agent_engine_active()` (`authorization_service.php:102`,
   `primary_engine_takes_over()` at `:84`), so when `local_wizard` takes over,
   this plugin's MCP surface self-disables exactly like the chat entries. The
   bridge auto-discovers the active function prefix (try `local_wizard_mcp_*`,
   fall back to `bookingextension_agent_mcp_*`) via
   `core_webservice_get_site_info`, so clients survive the engine cut. MCP
   *tool* names come from `skill->get_name()` and never change.
2. **No `require_sesskey()`** in the new functions — they must work through
   `/webservice/rest/server.php` with a WS token. Security comes from the
   token + capability + the engine's own gates, not from CSRF protection.
3. **No skill changes.** Validation, capabilities and previews are consumed
   through the existing contract (`get_schema()`, `check_structure()`,
   `preflight()`, `describe_proposed_action()`).
4. **Never touch the chat thread.** Queue items live in thread metadata
   (`_skill_queue_items`), so MCP must use its own thread rows (see WP1.3) —
   sharing the active chat thread would corrupt the chat queue.
5. **George's conventions:** English commits, path-limited `git commit -- …`,
   separate `Version (YYYYMMDDXX)` bump commits, `phpcs --standard=moodle`
   0/0, PHPDoc checked with moodle-local_moodlecheck (no commas in `@param`
   generics — use `object[]`).

---

## Phase 1 — read-only surface (R0 skills, single-shot calls)

### WP1.1 Capability + web service plumbing

**`db/access.php`** — one new capability:

```php
'bookingextension/agent:mcpaccess' => [
    'captype' => 'read',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes' => [],           // explicit grant only, no defaults
    'riskbitmask' => RISK_PERSONAL,
],
```

No archetype defaults on purpose (and archetype changes would not reach
existing sites anyway): an admin must consciously grant MCP access.

**`db/services.php`** — three function entries (pattern of
`bookingextension_agent_ai_poll_thread`, `db/services.php:61`) **without**
`'ajax' => 1` mattering for us (keep it for parity), plus a **second, separate
service** so tokens for MCP never unlock the chat functions:

```php
'Booking AI Agent MCP' => [
    'functions' => [
        'bookingextension_agent_mcp_list_tools',
        'bookingextension_agent_mcp_call_tool',
        'bookingextension_agent_mcp_confirm_tool',   // phase 2, registered early
    ],
    'restrictedusers' => 1,   // admin explicitly authorises users
    'enabled' => 0,           // opt-in
    'shortname' => 'bookingextension_agent_mcp',
],
```

`capabilities` per function: `bookingextension/agent:mcpaccess` (list/call) —
the per-skill capability gates stay inside the engine (Gate 1+2).

**Version bump** as its own commit per convention (current `2026070708`,
component `bookingextension_agent`).

### WP1.2 Tool catalog service

New: `classes/local/wizard/services/mcp/mcp_tool_catalog_service.php`

```php
public function __construct(skill_registry $registry, skill_executability_evaluator $evaluator);
public function get_tools(int $userid, int $contextid): array;        // MCP tool defs
public static function tool_name_for(string $skillname): string;      // '.' -> '_'
public function skill_for_tool_name(string $toolname): ?string;       // reverse lookup via catalog scan
```

Per skill (source: `skill_registry::get_skills()` filtered through
`skill_executability_evaluator::evaluate_skill($name, $userid, $contextid)`
(`skill_executability_evaluator.php:61`) — only `executable_state === 'allow'`
skills are listed):

- `name`: `tool_name_for(get_name())` (`course.search_courses` →
  `course_search_courses`; Claude tool-name charset `[a-zA-Z0-9_-]`).
- `description`: `get_schema()['description']`, appended with up to 3
  `example_utterances`.
- `inputSchema`: JSON Schema object built from `get_schema()['properties']` —
  copy `type`/`description` per property, collect per-property
  `required: true` flags into a top-level `required` array,
  `additionalProperties: false`.
- `annotations`: `readOnlyHint` from `is_read_only()`,
  `destructiveHint`/`title` from risk class (`R2`/`R3` → destructive).

**Exposure policy** (config `bookingextension_agent/mcpexposedskills`, a
skill-name allowlist edited on the governance page, see WP2.2): default
excludes the thread-coupled and PII-sensitive skills —
`wizard.remember`, `wizard.recall_memory`, `wizard.list_memories`,
`wizard.forget`, `wizard.search_skills`, `wizard.list_skills`,
`wizard.recreate_skill_catalog`, `core.search_users`,
`question.generate_questions`. Defaults expose the 10 remaining R0 skills.
(`question.generate_questions` joins the surface in phase 2 via WP2.4 —
decision 4; `core.search_users` stays an explicit admin opt-in.)

### WP1.3 Headless execution service

New: `classes/local/wizard/services/mcp/mcp_execution_service.php`

```php
public function __construct(skill_registry $registry, conversation_store $store, authorization_service $authz);
public function call_tool(string $toolname, array $args, int $contextid, int $userid, string $idempotencykey): array;
```

R0 call path (mutating path added in phase 2):

1. Resolve skill via catalog service; unknown tool → structured error.
2. `skill_executability_evaluator::evaluate_skill()` — deny → error with
   `deny_reason` (`requires_pro`, missing capability, inactive, …).
3. `skill->check_structure($args)` — issue codes → MCP tool error
   (`isError: true`, codes in `structuredContent`).
4. **MCP thread**: dedicated per user+context, marked in `metadatajson`
   (`_channel: 'mcp'`). The tables have no channel column
   (`bx_agent_ai_threads`: `id, userid, contextid, status, metadatajson, …`),
   and `get_or_create_thread()` (`conversation_store.php:84`) would return the
   user's *chat* thread — so this WP adds one small store method
   `get_or_create_channel_thread(int $userid, int $contextid, string $channel)`
   that filters active threads on the metadata marker.
   This is the plan's only engine-file touch (additive, one method) —
   **approved by George 2026-07-08** (decision 1).
5. `preflight_pipeline::run($commands, $threadid, $contextid, $userid)`
   (`preflight_pipeline.php:91`) with a single command
   `['skill' => $name, 'input' => $args]`. Non-`pass` → structured error from
   `issue_codes` / `blocking_layer`.
6. `conversation_store::create_run($threadid, $userid, $contextid,
   $idempotencykey, $commands)` (`conversation_store.php:518`), status →
   `running`. `$idempotencykey` comes from the WS parameter (bridge sends a
   UUID per request; retries reuse it and hit the
   `run_exists_other_than` guard, `executor.php:104`).
7. `executor::execute_commands($preparedcommands, $contextid, $userid,
   $idempotencykey, $runid)` (`executor.php:93`) — commands carry `skill`,
   `input` (= prepared input from step 5) and `operating_contextid`; R0 needs
   no guard token (executor only demands it for `!is_read_only()`,
   `executor.php:223`).
8. Run → `completed` with results; map result to the MCP shape:

```php
return [
    'content' => [['type' => 'text', 'text' => $result['usermessage'] ?: $result['observation_full']]],
    'structuredcontent' => $payload,   // skill-specific arrays: courses, users, resultid, …
    'iserror' => $result['status'] !== 'executed',
];
```

9. Trigger the audit event (WP1.6).

### WP1.4 External function shims

New: `classes/external/mcp_list_tools.php`, `classes/external/mcp_call_tool.php`
(boilerplate per `ai_poll_thread.php`: `external_api`, `strict_types`,
`validate_parameters`, `context::instance_by_id` + `validate_context`,
`check_use_readiness` first — **no** `require_sesskey`; additionally
`require_capability('bookingextension/agent:mcpaccess', $context)`).

```php
// mcp_list_tools
execute_parameters(): ['contextid' => PARAM_INT]
execute(int $contextid): array          // ['toolsjson' => json_encode($tools)]
execute_returns(): ['toolsjson' => external_value(PARAM_RAW)]

// mcp_call_tool
execute_parameters(): ['contextid' => PARAM_INT, 'toolname' => PARAM_ALPHANUMEXT,
                       'argsjson' => PARAM_RAW, 'idempotencykey' => PARAM_ALPHANUMEXT]
execute(...): array                     // ['resultjson' => json_encode($mcpresult)]
```

JSON-blob returns (`PARAM_RAW`) are deliberate: tool results are dynamically
shaped, and the bridge re-emits them as MCP content anyway — per-skill typed
`external_returns` structures would reintroduce the per-skill-endpoint problem.

### WP1.5 stdio bridge

New: `tools/mcp-bridge/` (Node ≥ 18, `@modelcontextprotocol/sdk`, ~200 lines,
own `package.json`, listed in `thirdpartylibs.xml` if vendored).

- Env: `MOODLE_URL`, `MOODLE_WSTOKEN`, optional `MOODLE_CONTEXTID` (default:
  **system context** — decision 2), optional `MOODLE_WS_PREFIX` (skip
  auto-discovery).
- Startup: `core_webservice_get_site_info` → pick function prefix
  (`local_wizard_mcp_*` if present, else `bookingextension_agent_mcp_*`) —
  the dual-track answer.
- `tools/list` → `mcp_list_tools`, cache per session.
- `tools/call` → `mcp_call_tool` with a fresh UUID idempotency key; map
  `resultjson` to MCP content blocks / `structuredContent` / `isError`.
- README with `claude_desktop_config.json` and `claude mcp add` examples plus
  the Moodle-side setup checklist (enable web services + REST, enable the MCP
  service, authorise user, grant capability, mint token).

### WP1.6 Audit event

New: `classes/event/mcp_tool_called.php` (`\core\event\base`, CRUD `r` for
phase 1, `other = ['skill' => …, 'status' => …, 'runid' => …]`), triggered in
`mcp_execution_service` after execution — closes the "no Moodle events on
skill execution" gap for the programmatic surface from day 1.

### WP1.7 Tests (PHPUnit, patterns from existing suite)

- `tests/mcp/mcp_tool_catalog_test.php` (extends
  `abstract_agent_testcase`, `tests/agent/abstract_agent_testcase.php`):
  name mapping round-trip, JSON-schema shape, exposure allowlist, evaluator
  filtering (user without skill capability sees no tool), annotations.
- `tests/mcp/mcp_call_tool_external_test.php` (pattern:
  `tests/thread_idor_external_test.php`): happy path
  `course_search_courses` as teacher; deny paths — missing `mcpaccess`
  capability, unknown tool, `check_structure` violation, engine inactive
  (mock `primary_engine_takes_over`), idempotent replay (same key → no second
  run).
- phpcs + PHPDoc clean.

**Phase 1 estimate:** ~5 new PHP classes (+1 store method), 1 event, bridge
script, 2 test files. No orchestrator/interpreter/prompt changes.

---

## Phase 2 — mutating skills (two-call confirm)

### WP2.1 Confirm flow

Extend `mcp_execution_service::call_tool()` for `!is_read_only()` skills
(reusing the queue machinery, mirroring what `agent_decision_service` does for
the chat path):

1. Config gate `mcpallowmutations` (default 0) + the existing licence gate
   (evaluator already denies mutating without PRO/WB-LLM) + `is_skill_active`
   (mutating skills are admin-enabled via the governance page,
   `skill_registry::is_skill_active`, `skill_registry.php:354`).
2. `preflight_pipeline::run()`; on pass:
   `queue_manager::enqueue_command($threadid, 0, $stepid, $command,
   'mutating', 'planned')` (`queue_manager.php:97`) →
   `queue_manager::set_prepared_input($threadid, $qid, $contextid,
   $preparedinput, $operatingcontextid)` (`queue_manager.php:324` — this also
   builds the `guard_token` via
   `preflight_execution_gate::build_guard_token`, `:351`) →
   `queue_transition_service::to_blocked_confirmation(…,
   'MCP_AWAITING_CONFIRMATION')` (`queue_transition_service.php:236`).
3. `pending_intent_service::set($threadid, $userid, $contextid,
   ['queue_item_ids' => [$qid]])` (`pending_intent_service.php:88`) → returns
   the `confirmationcode` (store TTL 900 s, `conversation_store.php:708`).
4. Response = MCP result containing the preview
   (`skill->describe_proposed_action($preparedinput)` rows +
   `preflight` summary), plus `structuredContent`:
   `{pending: true, queueitemid, confirmationcode, expiresin: 900}` and an
   instruction text telling the model to ask the human and then call
   `mcp_confirm_tool`.

New shim `classes/external/mcp_confirm_tool.php`:

```php
execute_parameters(): ['contextid' => PARAM_INT, 'threadid' => PARAM_INT,
                       'queueitemid' => PARAM_ALPHANUMEXT, 'confirmationcode' => PARAM_ALPHANUMEXT]
```

Flow: readiness + capability + thread ownership
(`conversation_store::thread_belongs_to_user`, as in `ai_confirm_run.php:128`)
+ **verify `confirmationcode` against the stored pending intent** (stricter
than the chat UI, which never displays the code — over MCP the code is the
proof that the confirming call saw the preview response), then delegate to
`confirm_run_service::confirm($contextid, 0, $threadid, $userid, $queueitemid,
false)` (`confirm_run_service.php:91`) — which consumes the intent, moves the
item `to_ready('CONFIRMATION_ACCEPTED')`, creates the run and drives the
executor with guard-token verification (`executor.php:223–245`). Map the
completion feedback to the MCP result shape. `allowsession` stays hard-false
over MCP (no session-wide confirm suppression).

### WP2.2 Policy & governance

- Settings (settings.php, near `agent_enabled` `:109`): `mcpallowmutations`
  (checkbox, default 0), `mcpratelimit` (int, calls/user/minute, default 30 —
  enforced in `mcp_execution_service` with a MUC cache counter, issue code
  `MCP_RATE_LIMITED`).
- Governance page (`skill_governance.php`): an "exposed via MCP" column
  editing `mcpexposedskills`, so per-skill MCP exposure sits next to the
  existing per-skill activation toggles (`aiskillenabled_*`,
  `skill_registry.php:391`).
- Event upgraded: CRUD `c/u/d` for mutating calls; add
  `mcp_tool_confirmed` event.
- PII decision from the blueprint §6 lands here: `core.search_users` stays
  out of the default allowlist; enabling it is an explicit admin act on the
  governance page.

### WP2.3 Make `question.generate_questions` MCP-capable (decision 4)

The skill instantiates `conversation_store` directly (attachment/thread
lookups) instead of receiving a thread id — the only R2 skill with a hidden
thread dependency. Deliberate exception to constraint §0.3 (one approved
skill change): give the skill the same duck-typed
`set_runtime_threadid(int $threadid)` setter the executor already injects
into skills that declare it (`executor.php:249`), and route its internal
`conversation_store` reads through that thread id. Over MCP this resolves to
the `_channel: 'mcp'` thread, in chat to the chat thread — no behaviour
change for the existing path. Attachment handling over MCP (PDF input for
question generation) needs `mcp_call_tool` to accept an optional
`draftitemid`/file payload — scoped as part of this WP, mirroring what
`ai_upload_attachment` does for chat. Then remove the skill from the default
exclusion list once `mcpallowmutations` is on.

### WP2.4 Tests

Contract test mirroring `tests/agent/contracts/ai_confirm_run_contract_test.php`:
full two-call cycle for `course.update_activity` (preview → confirm →
mutation verified in DB + `observation_full` read-back); guard-token mismatch
(tampered prepared input → `EXECUTION_GUARD_MISMATCH`); wrong/expired
`confirmationcode`; TTL expiry (`fail_expired_blocked_items`); ownership
violation (other user confirms → deny); `mcpallowmutations=0` → deny;
no-PRO licence → `requires_pro` deny. Plus a `generate_questions` cycle over
the MCP thread (WP2.3) with attached PDF fixture.

**Phase 2 estimate:** 1 new shim, ~150 lines in `mcp_execution_service`,
settings + governance column, 2 events, the `generate_questions` runtime
thread-id refactor, 1 contract test file. Beyond that, no engine-core
changes — everything goes through existing queue/confirm services.

---

## Phase 3 — remote connector (outline only, separate plan when due)

Streamable-HTTP MCP endpoint (`mcp.php`, JSON-RPC 2.0, official PHP MCP SDK)
delegating to the same `services/mcp/` classes; auth via bearer→WS-token
mapping or an external IdP/gateway (Moodle is no OAuth 2.1 AS). Only worth
planning in detail once phases 1–2 are deployed and the `local_wizard` cut is
done — the endpoint should be born in the surviving engine.

## Commit sequence

1. `MCP facade: capability, web service and tool catalog` (WP1.1+1.2 + tests)
2. `MCP facade: headless read-only tool execution` (WP1.3+1.4+1.6 + tests)
3. `MCP facade: stdio bridge for Claude clients` (WP1.5)
4. `Version (YYYYMMDDXX)` — separate, version.php + CHANGES.md only
5. Phase 2 analogously (confirm flow / governance / tests / version bump)

All commits English, path-limited, trailer
`Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## Decisions (resolved by George, 2026-07-08)

1. **Engine touch**: the additive `conversation_store` method
   (`get_or_create_channel_thread`) is approved — no facade-owned thread
   writer needed.
2. **Default context** when the client passes none: **system context**.
3. **Bridge placement**: in-repo, `tools/mcp-bridge/`.
4. **`question.generate_questions`**: made MCP-capable in phase 2 → WP2.3.

The plan is unblocked; implementation can start with WP1.1.

