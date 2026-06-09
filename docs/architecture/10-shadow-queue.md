# 10 · Shadow queue

> **Scope.** The DB-backed command queue that sequences, dedupes, blocks-for-confirmation,
> retries, and tracks dependencies between commands. Flowchart subgraph: `QUEUE`.

The queue is called *shadow* because it mirrors the planner's proposed commands without the
planner owning their execution. It is the single place that enforces "one mutation at a
time per thread", idempotency, confirmation holds, retry backoff, and dependency ordering.

**Files:** `queue/queue_manager.php`, `queue/observation_builder.php`,
`services/queue_status_policy.php`, `services/queue_transition_service.php`,
`services/queue_command_mapper.php`, `services/spawn_contract_service.php`.

---

## 1. Enqueue

`enqueue_command(threadid, runid, stepid, command, mutability, status, dependson = [])`:

1. **DAG check** — `validate_depends_on_is_dag()` (when `queue_dag_validation_enabled`):
   a cycle creates a `failed` item with `DEPENDENCY_CYCLE`.
2. **Input signature** — `build_input_signature_details(skill, input)`.
3. **Idempotency** — if a **non-terminal** item with the **same signature** exists, it is
   reused (`idempotency_reason = QUEUE_SIGNATURE_REUSE`) instead of duplicated.

---

## 2. Statuses

`queue_status_policy` defines the lifecycle:

| Status | Meaning |
|--------|---------|
| `ready` | may be picked up now |
| `blocked_confirmation` | waiting for user confirmation |
| `planned` | placeholder for a future multi-step skill (intent only) |
| `retry_waiting` | retry scheduled, backoff not yet elapsed |
| `running` | currently executing (max 1 per thread) |
| `succeeded` / `failed` / `skipped` | **terminal** |

`queued` is also an actionable intermediate. Pickup-ready = `ready` ∪ `retry_waiting`.

---

## 3. Confirmation holds & TTLs

`blocked_confirmation` items carry a `blocked_expires_at`, set by
`resolve_blocked_ttl_seconds(riskclass)` (when `queue_blocked_ttl_enabled`):

| Risk | TTL |
|------|-----|
| **R1** | **900 s** |
| **R2** | **300 s** |
| **R3** | **900 s** (manual only — never auto-consumed) |

`fail_expired_blocked_items()` flips expired holds to `failed` with
`BLOCKED_CONFIRMATION_TIMEOUT`. A user confirmation via `ai_confirm_run` transitions the
item to `ready`. The "manual only" nature of R3 is **not** a TTL property — it is enforced
in `queue_transition_service` (R3 may never become `retry_waiting`; it fails with
`R3_NO_RETRY`).

---

## 4. Planned placeholders

The selector, on the first turn of a multi-step request, enqueues **`planned`**
placeholders — intent strings only, no skill or parameters
(`enqueue_placeholder(threadid, runid, stepid, intent)`, skill `__placeholder__`). While any
exist, `has_planned_placeholders()` is true, which keeps the confirm path in **follow-up**
mode (`CONF_FOLLOW`). When the real skill for a step is enqueued,
`consume_next_placeholder()` marks one placeholder `succeeded`. This is how "create an option
**and** book three users" stays one coherent multi-step plan.

---

## 5. Running, pickup & retries

- `try_mark_running(threadid, queueitemid)` is **atomic**: a DB transaction with a
  `FOR UPDATE` lock ensures **at most one running item per thread**.
- `can_pickup_now(item, now, items)` requires a pickup-ready status, an expired
  `blocked_expires_at`, an elapsed `next_retry_at`, and all dependencies `succeeded`.
- Retries persist rich metadata: `retry_layer`, `retry_origin`, `retry_reason`,
  `retry_attempt`, `retry_hint_category`, and `retry_layers` (a *list* of distinct layers,
  capped at `MAX_RETRY_LAYERS_PER_ERROR_CLASS = 2` — a third layer for the same error class
  is blocked with `RETRY_LAYER_LIMIT_EXCEEDED` / `RETRY_LAYER_COLLISION`). This cap is the
  cross-layer collision guard that the [runtime loop](04-agent-runtime-and-loop.md) also
  respects.

---

## 6. Dependencies & spawn

`dependencies_succeeded()` is satisfied only when **every** `depends_on` item is `succeeded`.
A producer's outputs bind to a child's input (`output_bindings`) before the child's late
preflight; an unsatisfiable dependency yields a logical `skipped`. `spawn_commands` (planned
child commands with `depends_on` + artifact refs) are validated by the schema and
`spawn_contract_service`, but the **runtime enqueue path is optional** — today this is mainly
a schema/normalization contract (the `LG_SPAWN_RULE` legend), with multi-step work driven by
the planned-placeholder mechanism instead.

---

## 7. Flowchart notes

> **✓ Confirmed:** blocked TTLs R1=900 / R2=300 / R3=900; atomic single-running-item;
> idempotency by input signature; planned placeholders drive `CONF_FOLLOW`; R3 no retry;
> retry-layer cap of 2.

> **✓ Code name (corrected).** `Q_FAIL_TTL` now uses the real issue code
> `BLOCKED_CONFIRMATION_TIMEOUT` (was `BLOCKED_TIMEOUT`). Note: DAG validation and blocked-TTL
> are both gated by config flags (`queue_dag_validation_enabled`, `queue_blocked_ttl_enabled`)
> and can be disabled.
