# 03 · Conversation store

> **Scope.** The DB-backed store of threads, messages, runs, pending intents, per-thread
> metadata, session allowances, step messages, and LLM debug entries. Flowchart subgraph:
> `CSTORE`.

`conversation_store` is the engine's memory. Almost every other subsystem reads or writes
through it, and it is the only place that touches the agent's conversation tables. It
implements `agent_conversation_store` (the interface the rest of the engine depends on, so
the store can be swapped in tests).

This chapter is organized by concern, matching how the other chapters reference it.

---

## 1. Threads

A **thread** is one conversation, unique per `(userid, contextid)` while active.

| Method | Behavior |
|--------|----------|
| `get_or_create_thread(userid, contextid, bookingid)` | return the active thread or insert a new `status='active'` one |
| `get_active_thread(userid, contextid)` | the active thread or `null` |
| `create_fresh_thread(userid, contextid, bookingid)` | archive all active threads for the pair, then create a new one (the "start over" path used by `ai_privacy_precheck?forcenewthread=1`) |
| `get_thread(threadid)` | a single thread, or `null` |
| `get_last_thread_for_user(userid, contextid)` | the previous (archived/non-active) thread, for history |
| `get_user_threads_by_date_window(userid, contextid, from, to)` | thread ids with messages in a time window, dual-fenced on `thread.userid` and `message.userid` |

Threads carry a `metadatajson` blob — the backing store for §5 below.

---

## 2. Messages

| Method | Behavior |
|--------|----------|
| `add_message(threadid, role, content, structured = [])` | append a message; `role ∈ {user, assistant, system, step}`; `structured` is JSON-encoded into `structuredjson`; returns the new id |
| `get_messages(threadid)` | all messages (including `step`), oldest first |
| `get_recent_messages(threadid, limit)` | the last *N* messages **excluding** `step`, oldest first — this is what feeds the planner prompt |
| `get_user_messages_for_thread(userid, threadid, from?, to?, query?)` | user-fenced history with optional date window and full-text search |

The distinction between `get_recent_messages` (no `step`) and `get_messages` (with `step`)
is important: progress bubbles must never leak into the model's conversation history.

---

## 3. Runs

A **run** records a confirmed/executed set of commands and is the unit of idempotency.

| Method | Behavior |
|--------|----------|
| `create_run(threadid, userid, contextid, idempotencykey, commands)` | insert a `status='pending'` run with the commands as JSON |
| `update_run_status(runid, status, results = [])` | move through `pending → queued → running → completed/failed`; store per-command results |
| `get_run(runid)` / `get_latest_run(threadid)` | fetch |
| `run_exists(idempotencykey)` | any run with this key exists? |
| `run_exists_other_than(idempotencykey, runid)` | a run with this key exists **excluding** `runid`? |

`run_exists_other_than` is the executor's idempotency check (flowchart `EXC_IDEM`): if a
*different* run already executed the same idempotency key, the current one is skipped as
already-executed. See [ch. 11](11-executor.md).

---

## 4. Pending intent

A **pending intent** is the bridge between "the agent proposed a mutation" and "the user
confirmed it". It lives in thread metadata under `pending_intent`.

| Method | Behavior |
|--------|----------|
| `set_pending_intent(threadid, intentkey, userid=0, contextid=0, metadata=[], ttl=900)` | store the intent; mint a 6-digit confirmation code (`C…`); checksum the queue item ids; `state='pending'`; default TTL **900 s** |
| `get_pending_intent(threadid)` | return it only if it exists, has queue items, is `pending`, and is not expired (auto-clears on expiry) |
| `consume_pending_intent(threadid, userid=0, contextid=0)` | atomically fetch **and** clear, with optional `(userid, contextid)` gates (0 = skip the check) |
| `clear_pending_intent(threadid)` | drop it (after confirmation, or when an unrelated new message arrives) |

The stored structure includes `intentkey`, `checksum`, `expiresat`, `state`, `userid`,
`contextid`, `confirmationcode`, and the `queue_item_ids` it authorizes. The
[decision service](08-decision-service.md) uses it to detect "user sent something unrelated
while a confirmation was pending", and `ai_confirm_run` / `ai_discard_pending` consume it.

---

## 5. Thread metadata

A generic key/value store on the thread (`get_thread_metadata_value` /
`set_thread_metadata_value`), serialized in `metadatajson`. The **well-known keys**:

| Key | Written by | Purpose |
|-----|-----------|---------|
| `pending_intent` | store (§4) | the pending confirmation |
| `next_step_intent` | `orchestrator` | the planned next step; appended to the embedding query so short confirmations ("ja"/"ok") don't mis-route (see [ch. 06](06-discovery-families-embeddings.md)) |
| `phase_trace` | store (`set_phase_trace`) | the latest discovery/selection/parameter_construction trace |
| `planner_trace_history` | store (`set_planner_trace_history`) | ordered planner decision traces |
| `_confirm_previews` | `ai_send_message` | preview descriptor payloads for the confirmation UI |
| `user_input_lang` / `last_output_lang` | language policy | authoritative turn language / last reply language |
| `routing_embeddings_comparison` | telemetry | embedding-routing comparison data |

> **⚠ Flowchart note.** The flowchart's `CS15` describes a single `next_step_intent`
> metadata key and `agent_runtime` references a `phase_trace_loop_history` key
> (`persist_phase_trace_for_loop_step`). In the store, the canonical keys are
> `phase_trace` and `planner_trace_history`; whether `phase_trace_loop_history` is a third
> live key or a rename needs confirmation. *Raised as an open question — see
> [flowchart-guide](../reference/flowchart-guide.md).*

Convenience writers `set_phase_trace()` (normalizes to the three canonical phase keys) and
`set_planner_trace_history()` (drops empties) wrap the raw metadata API.

---

## 6. Session allowances (auto-confirm)

This is the mechanism behind one-click / no-click confirmation.

| Method | Behavior |
|--------|----------|
| `allow_confirmation_for_session(userid, contextid, expiresat=null)` | add `contextid` to the user's allowlist; **default TTL 12 h** (43200 s) |
| `is_confirmation_allowed_for_session(userid, contextid)` | is `contextid` allowed and unexpired? |
| `allow_confirmation_for_thread` / `is_confirmation_allowed_for_thread` / `clear_confirmation_allowance` | backward-compatible wrappers that accept a `threadid` but **ignore it** |

Key facts:

- **Scope key is `contextid` only** (per user). The allowlist is stored in user
  preferences (`bookingextension_agent_ai_confirmation_session_allowlist`) as
  `contextid → {contextid, threadid, expiresat}`, and is pruned of expired entries on every
  load.
- The `_thread` variants exist only for call-site compatibility; they delegate to the
  session-scoped logic.

> **⚠ Flowchart note — two different TTLs.** The flowchart's `LG_AUTO` / `LG_RISK_CONF`
> describe an R1 session allowance with a **900 s** TTL. The store's
> `allow_confirmation_for_session` default is **43200 s (12 h)**. The 900 s value in the
> diagram matches the **pending-intent** TTL (§4) and the **queue `blocked_expires_at`**
> windows ([ch. 10](10-shadow-queue.md)), not the session allowance. Whether the confirm
> path passes an explicit shorter `expiresat` for R1 needs confirmation. *Open question.*

---

## 7. Step messages

Ephemeral progress bubbles (role `step`), surfaced by `ai_poll_thread`.

| Method | Behavior |
|--------|----------|
| `add_step_message(threadid, stepnum, label, skill='')` | write a progress label (with a skill name for the UI icon); not part of history |
| `clear_step_messages(threadid)` | delete all `step` messages for the thread |
| `get_step_messages_since(threadid, sinceid)` | delta read for the poller |

**Who actually calls these** (this resolves a flowchart attribution error):

- `clear_step_messages()` is called **once** in `ai_send_message::execute()` *before* the
  loop starts — not per loop step.
- `add_step_message()` is called once in `ai_send_message::execute()` (the initial
  "thinking" placeholder) and then **inside `orchestrator::process()`** for each
  discovery/selection/construction phase, emitting the resolved `next_step_intent` label.

> **⚠ Flowchart note.** The `LOOP_STEP` node attributes
> `clear_step_messages() + add_step_message(next_step_intent)` to the agent loop head in
> `agent_runtime::run_loop()`. Neither call is there: clearing happens once at the entry in
> `ai_send_message`, and per-step writes happen in `orchestrator::process()`. *Candidate
> flowchart correction — see [ch. 04](04-agent-runtime-and-loop.md) and the discrepancy
> log.*

---

## 8. LLM debug entries

| Method | Behavior |
|--------|----------|
| `add_llm_debug_entry(threadid, userid, contextid, source, requesttext, responsetext, success, errormessage='')` | record a raw LLM exchange when debug mode is on |
| `get_llm_debug_entries(threadid, limit=100)` | read them back, oldest first |

This is the data behind `ai_get_thread_debug_logs` and the observability tooling — see
[operations/observability.md](../operations/observability.md), which also confirms the
backing table name.

---

## See also

- [02 · Authorization & context](02-authorization-and-context.md) — the `(userid,
  contextid)` scope.
- [04 · Runtime & loop](04-agent-runtime-and-loop.md) — the consumer of observations and
  metadata.
- [08 · Decision service](08-decision-service.md) — pending-intent routing.
- [developer-guides/data-model-and-db.md](../developer-guides/data-model-and-db.md) — the
  underlying tables.
