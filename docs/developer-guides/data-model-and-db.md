# Developer guide · Data model & database

> **Scope.** The agent's own database tables, where conversational state actually lives, and
> the install-only rollout policy.

## 1. Install-only rollout

New schema for the agent ships via [`db/install.xml`](../../db/install.xml) **only** — there
are no `upgrade.php` migrations for the agent's own tables (the `LG_DB` legend). For a
contributor this means: change `install.xml`, bump `version.php`, and rely on a clean
(re)install of the plugin tables rather than incremental migration steps.

## 2. Table prefix

All agent tables use the **`local_wbagent_`** prefix (a legacy of the engine namespace),
**not** `bookingextension_agent_`. With the Moodle DB prefix `m_`, the physical table for
LLM debug logs is e.g. `m_local_wbagent_ai_llm_debug`.

## 3. Conversation tables

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `local_wbagent_ai_threads` | one conversation per (user, context) | `id`, `userid`, `contextid`, `bookingid`, `status` (`active`/archived), `metadatajson`, `timecreated`, `timemodified` |
| `local_wbagent_ai_messages` | messages in a thread | `id`, `threadid`→threads, `userid`, `role` (`user`/`assistant`/`system`/`step`), `content`, `structuredjson`, `timecreated` |
| `local_wbagent_ai_runs` | a confirmed/executed command set (unit of idempotency) | `id`, `threadid`, `userid`, `contextid`, `status` (`pending`→`completed`/`failed`), `idempotencykey` (sha256), `commandsjson`, `timecreated`, `timemodified` |
| `local_wbagent_ai_llm_debug` | raw LLM exchanges (debug mode) | `id`, `threadid`, `userid`, `contextid`, `source`, `success`, request/response text, `timecreated` |

## 4. Where the queue, pending intent, and traces live

There is **no queue table**. The [shadow queue](../architecture/10-shadow-queue.md) is
persisted **inside the thread's `metadatajson`** (`queue_manager` reads/writes the
`META_QUEUE_ITEMS` key via `conversation_store::get/set_thread_metadata_value`). The atomic
"one running item per thread" lock is a `SELECT … FOR UPDATE` on
`local_wbagent_ai_threads`.

The same `metadatajson` blob also holds the well-known keys documented in
[ch. 03 §5](../architecture/03-conversation-store.md#5-thread-metadata): `pending_intent`,
`next_step_intent`, `phase_trace`, `planner_trace_history`, `_preflight_audit_log`,
`_confirm_previews`, routing telemetry, language keys, and the queue sequence counter.

> **Design consequence.** A thread row is the single transactional unit for a conversation:
> messages and runs reference it, and its `metadatajson` carries the queue, pending intent,
> and traces. This keeps a turn's mutable state co-located and lockable.

## 5. Benchmark tables

| Table | Purpose |
|-------|---------|
| `local_wbagent_benchmark_runs` | one row per benchmark run (model, success rate, tokens, cost, duration, baseline flags) |
| `local_wbagent_benchmark_scenarios` | per-scenario results (passed, json_valid, contract_compliant, expected/actual, tokens) |
| `local_wbagent_benchmark_metrics` | aggregated metric snapshots per run (key/value/unit/scenario_class) |
| `local_wbagent_benchmark_baselines` | pinned baselines for regression comparison |

See [operations/benchmarking.md](../operations/benchmarking.md).

## 6. Caches (`db/caches.php`)

| Cache | Mode | TTL | Holds |
|-------|------|-----|-------|
| `aiprivacynames` | application | 900 s | privacy name mappings |
| `aiwaitstate` | session | 60 s | conversation state during polling |
| `aiwaitmailbox` | session | 60 s | long-poll mailbox results |
| `trialnonce` | application | 600 s | trial challenge nonces |
| `attachment_tokens` | application | 1800 s | token → temp-file path (30-min window) |

## 7. Capabilities & tasks

Capabilities are in [`db/access.php`](../architecture/02-authorization-and-context.md#3-capabilities-dbaccessphp);
scheduled/ad-hoc tasks in [`db/tasks.php`](../operations/tasks-and-async.md).

## 8. Entity sketch

```
local_wbagent_ai_threads (1) ──< local_wbagent_ai_messages (N)
        │  └─ metadatajson: { queue items, pending_intent, traces, … }
        └──< local_wbagent_ai_runs (N) ── idempotencykey
local_wbagent_ai_llm_debug ── threadid (debug mode)
local_wbagent_benchmark_runs (1) ──< scenarios / metrics ; baselines
```
