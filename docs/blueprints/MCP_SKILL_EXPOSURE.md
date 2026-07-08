# Blueprint: Exposing agent skills as MCP tools for Claude

Status: analysis / feasibility study (2026-07-08). No implementation yet.

## 1. Question

Can the skills anchored in `bookingextension_agent` (the wizard engine under
`classes/local/wizard/`) be exposed to Claude via MCP (Model Context Protocol),
so that an external Claude client — claude.ai custom connectors, Claude Code,
Claude Desktop, or an API-side MCP connector — can call them as tools, with
Claude taking over the planner role that our own LLM loop plays today?

**Short answer: yes, and with less friction than expected.** The skill contract
is already ~90 % MCP-shaped. The two real gaps are (a) a token-capable,
sesskey-free entry layer (today every skill-triggering endpoint is bound to a
browser session), and (b) a headless execution path that drives
preflight → guard token → executor without the chat thread machinery. Both are
buildable on top of existing services without touching any skill.

## 2. What we have today (verified against code)

### 2.1 Skill contract — effectively an MCP tool definition already

Every skill implements `skill_interface`
(`classes/local/wizard/interfaces/skill_interface.php`):

```php
public function get_name(): string;          // "course.update_activity"
public function get_schema(): array;         // machine-readable parameter schema
public function get_risk_class(): string;    // R0..R3 (dto/skill_risk_class.php)
public function is_read_only(): bool;
public function check_structure(array $input): array;                       // pure, no DB/IO
public function preflight(array $input, int $contextid, int $userid): preflight_result_v2;
public function execute(array $preparedinput, int $contextid, int $userid): array;
```

`get_schema()` returns `version`, `description`, `readonly`,
`example_utterances` and a `properties` map with `type` / `description` /
`required` per parameter — directly mappable to an MCP `inputSchema`
(JSON Schema). The catalog currently holds **22 skills: 16× R0 (read-only),
6× R2 (mutating)**; R1/R3 are defined but unused.

Crucially, `execute()` receives only `(preparedinput, contextid, userid)` —
no thread id, no run object, no LLM handle. Skills are near-pure functions.
Only two skills reach into conversation state (`wizard.recall_memory` via a
duck-typed `set_runtime_threadid()`, and `question.generate_questions` which
instantiates `conversation_store` directly); all `core.*` and `course.*`
skills are thread-free.

### 2.2 Where the coupling actually sits

The only caller of `skill->execute()` is `executor::execute_commands()`
(`executor.php:278`), reached from exactly two places:

- `agent_decision_service::execute_readonly_commands()` — R0, auto-executed;
- `confirm_run_service::confirm()` — mutating, after user confirmation.

The executor needs: commands with `prepared_input` (+ `guard_token` for
mutating skills, verified by `preflight_execution_gate::verify_guard_token()`
binding token ↔ skill ↔ operating context ↔ input), a `runid` +
`idempotencykey` (idempotency guard via `conversation_store`), and a
`threadid` (only for ANON-token deanonymisation and runtime injection).
It never calls an LLM.

Argument validation is already LLM-independent:
`parameter_contract_validator::validate()` → `skill->check_structure()` →
`skill->preflight()` — an external client supplying JSON args goes through
the identical deterministic pipeline. The benchmark harness proves the
pattern: its `deterministic` tier stubs the LLM selector response with a fixed
`{skill, input}` JSON (`benchmark/abstract_benchmark_scenario.php`,
`benchmark_run_service.php`) and runs the rest of the engine headless — an
MCP client is structurally the same thing as that stub, with Claude producing
the `{skill, input}` instead.

### 2.3 Authorization — all enforced engine-side, none in the skill

- **Gate 1** `skill_executability_evaluator::evaluate_skill()` — registered/
  active, licence gate, name-derived capability
  `bookingextension/agent:skill_<name>` (derived by the engine, not read from
  metadata), context validity.
- **Gate 2** `native_capability_guard::missing_capabilities()` — the skill's
  declared native Moodle capabilities checked at the *operating* context,
  fail-closed. Enforced twice: in the preflight pipeline (no guard token
  issued on deny) and as an executor backstop immediately before `execute()`.
- **Licence/readonly gate** `agent_access_service::has_full_access()` —
  mutating skills require PRO licence or WB-LLM endpoint, otherwise
  `DENY_REQUIRES_PRO`.
- **Governance**: R0 skills are active out-of-the-box; mutating skills stay
  disabled until an admin enables them (`skill_registry::is_skill_active()`).

Because none of this lives in the skills, an MCP facade that funnels calls
through evaluator + preflight pipeline + executor inherits the complete
security model for free.

### 2.4 The blocking finding: no token-capable entry point exists

There is **no** web service that executes a skill directly. The only
skill-triggering entry is `ai_send_message` (natural-language →
LLM planning → skills), and every skill-triggering/confirming endpoint
(`ai_send_message`, `ai_confirm_run`, uploads, provider config) calls
`require_sesskey()` — binding it to a logged-in browser session. Although the
service is declared `enabled => 1`, a pure REST-token client (which is what
any MCP bridge would be) cannot supply a sesskey, so today's WS surface is
unusable for MCP. Only the sesskey-free read-only endpoints (`ai_poll_thread`,
`ai_get_doc_content`) would be token-reachable, and they trigger no skills.

Side observations relevant for an external surface: skill executions emit
**no Moodle audit events** (only internal `runs`/`queue` tables), and there is
**no per-user rate limiting** on the entry points — both acceptable inside the
chat UI, both worth adding when opening a programmatic surface.

## 3. Mapping skills → MCP tools

| Skill contract | MCP tool field | Notes |
|---|---|---|
| `get_name()` `course.update_activity` | `name` | Claude API tool names must match `^[a-zA-Z0-9_-]{1,128}$` → map `.` to `_` (`course_update_activity`), keep a reverse map. |
| `get_schema()['description']` + `example_utterances` | `description` | Already written for an LLM planner; reusable verbatim. |
| `get_schema()['properties']` | `inputSchema` | Translate `required` per-property flags into a JSON-Schema `required: []` array; types map 1:1. No `enum`/`min`/`max` exist — fine, `check_structure()`/`preflight()` stay the source of truth and their issue codes become MCP tool errors. |
| `is_read_only()` / `get_risk_class()` | `annotations.readOnlyHint`, `annotations.destructiveHint` | R0 → `readOnlyHint: true`; R2 → `destructiveHint: true`. |
| `execute()` result | `content` + `structuredContent` | `usermessage`/`observation_full` → text content block; structured payloads (`courses`, `users`, `updated_cmid`, …) → `structuredContent`. `observation_full` is exactly the "what the model should observe" channel MCP tool results are for. |
| `describe_proposed_action()` rows | confirmation preview | Feeds the two-step confirm flow (below). |
| `preflight_result_v2` issue codes | tool error payload | `status`, `issuecodes`, `blockinglayer` are structured and machine-readable. |

Catalog filtering: `get_prompt_contracts_for_context()` already returns only
skills executable for a given user/context via the evaluator — `tools/list`
should reuse it so Claude never sees tools it cannot call. The RAG discovery
layer (`wizard.search_skills`) becomes unnecessary over MCP: 22 tool
definitions fit comfortably in Claude's context, so the full list can be
served statically. The `wizard.*` meta-skills (list/search skills, scaffold,
memory) should mostly be excluded from the MCP surface — they solve problems
of our internal engine (prompt budget, thread memory), not the client's.

## 4. The two-phase problem: preflight/confirm vs. stateless `tools/call`

Our mutating flow is deliberately two-phase: preflight produces
`prepared_input` + `guard_token`, a pending intent is persisted, the user
confirms, then the executor runs. MCP `tools/call` is single-shot. Three ways
to bridge this, in order of preference:

1. **Two-call confirm pattern (recommended).** A mutating tool call without
   confirmation returns the preview (`describe_proposed_action()` rows +
   preflight summary) plus a server-issued `confirmationid` referencing the
   persisted pending intent (queue item). The client calls again with
   `{confirm: "<confirmationid>"}` to execute. This reuses the existing
   queue/pending-intent/guard-token machinery almost unchanged, keeps the
   authorisation decision server-side, and works with every MCP client. In
   Claude's UX the model shows the preview and the human says "yes" in chat —
   the human-in-the-loop property is preserved.
2. **MCP elicitation.** The spec (2025-06-18) supports server-initiated
   elicitation mid-call; client support is still uneven, so treat it as a
   later enhancement, not the foundation.
3. **Client-side approval only** (Claude's built-in tool-approval prompt) —
   not sufficient alone: it proves nothing to the server and would mean
   executing R2 skills on the first call. Rejected.

Read-only skills (16 of 22) need none of this — single-shot `tools/call`
mapping directly onto the R0 auto-execute path.

## 5. Architecture options

### Option A — MCP facade as new Moodle external functions + thin bridge (recommended start)

New token-capable external functions (no `require_sesskey()`; proper WS
service the admin enables and issues tokens for, capability-gated with
`bookingextension/agent:useaiinstructions` + per-skill gates as today):

- `..._mcp_list_tools(contextid)` → executable skills as MCP tool defs
  (name, description, inputSchema, annotations), via
  `skill_registry` + `skill_executability_evaluator`.
- `..._mcp_call_tool(contextid, toolname, argsjson)` → for R0: structural
  validation → preflight → executor (synthetic headless run: own
  `channel = 'mcp'` thread/run rows so the idempotency guard and the
  `runs`/`queue` audit trail keep working) → result mapped to
  content/structuredContent. For R2: preflight → persist pending intent →
  return preview + `confirmationid`.
- `..._mcp_confirm_tool(contextid, confirmationid)` → `confirm_run_service`
  semantics (ownership check like `thread_belongs_to_user`, TTL on the
  pending intent) → executor.

On top of that, a thin **stdio MCP bridge** (small Node or PHP-CLI process,
~200 lines) that speaks MCP to Claude Code / Claude Desktop and translates
`tools/list`·`tools/call` into `/webservice/rest/server.php` calls with a
Moodle WS token. The bridge holds no logic — every decision stays in Moodle.

The user identity is the token owner; Moodle's WS layer sets `$USER` from the
token, and we pass `$USER->id` as the injected `userid`, exactly as the chat
entry does. `contextid` becomes an explicit tool parameter (default: a
configured landing context, e.g. system or a course), mirroring the ambient
context of the chat UI.

Effort: the facade is essentially wiring existing services; the main genuinely
new piece is the headless run creation (bypassing `orchestrator` /
`agent_decision_service`, calling the preflight pipeline and
`executor::execute_commands()` directly — the executor already accepts exactly
that, it just has no caller outside the engine yet).

### Option B — native remote MCP endpoint inside the plugin

A streamable-HTTP MCP endpoint served by Moodle itself (e.g.
`bookingextension/agent/mcp.php`, JSON-RPC 2.0; the official PHP MCP SDK can
carry the protocol). This is what claude.ai **custom connectors** need for
org-wide, no-local-install use. The hard part is not MCP but **auth**: remote
connectors expect OAuth 2.1 (with dynamic client registration), and Moodle
core is an OAuth *client*, not an authorization server. Realistic paths:
front the endpoint with an external IdP/gateway that maps to Moodle WS tokens,
or start with header-based bearer tokens (works for Claude Code/API MCP
connector with custom headers, not for claude.ai connectors). Treat B as
phase 3, built on the same internal facade as A.

### Option C — reuse the existing chat/AJAX web services

Not viable: sesskey-bound, and semantically wrong — it would put Claude in
front of our own LLM loop (an LLM prompting an LLM) instead of exposing the
skills themselves.

## 6. Cross-cutting concerns to decide before building

- **Licence gate**: `has_full_access()` applies unchanged — without PRO/WB-LLM
  an MCP client gets read-only tools only. That is arguably the correct
  productisation anyway (MCP read-only surface free, mutations PRO).
- **Privacy / anonymizer**: the chat path anonymises user text before it
  reaches an external LLM. Over MCP the *user talks to Claude directly*, so
  our anonymizer is out of the loop by design — but skill *results* (e.g.
  `core.search_users`) flow to Anthropic as tool results. Needs an explicit
  decision: either accept (admin opts in by enabling the MCP service), gate
  PII-returning skills behind a setting, or run result payloads through the
  anonymizer token mechanism. Do not silently expose `core.search_users` etc.
- **Auditing**: add a Moodle event (`skill_executed_via_mcp` or a general
  `skill_executed`) in the facade — the current engine writes only internal
  tables, which is too little for an external programmatic surface.
- **Rate limiting**: none exists today; add a simple per-user/per-window
  counter in the facade before exposing mutations.
- **Thread-coupled skills**: exclude `wizard.recall_memory` /
  `wizard.forget` / `wizard.remember` / `wizard.list_memories` from the MCP
  surface (their memory is a chat-thread concept); `question.generate_questions`
  needs its direct `conversation_store` use abstracted or a synthetic thread.
- **Engine coexistence / extraction**: the facade must live behind the same
  chokepoints as the chat entries (`check_use_readiness`, engine-active check)
  so the planned `local_wizard` extraction (namespace already shaped for it)
  carries the MCP surface along instead of orphaning it in
  `bookingextension_agent`.
- **Tool-name mapping**: `.` → `_` plus a reverse map in the facade; keep
  `get_name()` canonical internally.

## 7. Suggested phasing

1. **Phase 1 — read-only MCP (low risk, high demo value).**
   `mcp_list_tools` + `mcp_call_tool` for R0 skills, token WS service, stdio
   bridge for Claude Code/Desktop. No confirm flow needed. Reuses evaluator,
   preflight, executor with a synthetic run. Deliverable: Claude answering
   "which courses match X / diagnose user Y in course Z" against a live site.
2. **Phase 2 — mutating skills with two-call confirm.**
   Pending-intent-backed `confirmationid`, `mcp_confirm_tool`, audit event,
   rate limit, PII decision from §6 implemented.
3. **Phase 3 — remote connector.**
   Streamable-HTTP endpoint + OAuth story (external IdP/gateway), enabling
   claude.ai custom connectors without local bridge installs.

## 8. Verdict

The skill layer was built engine-agnostic on purpose (final wrappers keeping
DTOs out of skill signatures, duck-typed service injection, injected
`contextid`/`userid` instead of globals), and that pays off here: **no skill
needs to change** to be exposed via MCP. All required security machinery
(two gates, licence gate, guard tokens, preflight) is engine-side and
reusable. What is missing is precisely one layer — a sesskey-free,
token-authenticated facade with a headless path into the executor and a
two-call confirm bridge for mutating skills — plus policy decisions on PII,
auditing and rate limiting. Phase 1 is a small, self-contained addition;
nothing found in the analysis is a structural blocker.
