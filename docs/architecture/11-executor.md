# 11 · Executor

> **Scope.** `executor.php` — the only place a skill actually runs. Flowchart subgraph:
> `EXEC`.

By the time a command reaches the executor it has already been planned, decided, preflighted
and (if mutating) confirmed. The executor's job is narrow and defensive: re-check that the
caller may still run this skill, verify the prepared input hasn't been tampered with, run
`skill::execute()`, and report one of three outcomes. It does **no** planning and **no**
second full preflight.

**Files:** `executor.php`, `skill_executability_evaluator.php`,
`services/execution/execution_feedback_service.php`,
`services/execution_observation_ledger.php`,
`services/completed_command_history_service.php`, `task/execute_ai_run_adhoc.php`.

---

## 1. Entry & flow

```php
execute_commands(array $commands, int $contextid, int $userid,
                 string $idempotencykey, int $runid): array
```

Per call: resolve context → **re-authorize** (`require_use_capability` +
`require_valid_context`, always re-checked) → idempotency guard → then, per command:
releasability check → structural sanity → execution-guard verify (mutating only) →
`skill::execute()` → enrich result.

---

## 2. Idempotency

`store->run_exists_other_than(idempotencykey, runid)` — if a *different* run already executed
this idempotency key, the whole call returns a single `skipped` result
(`EXECUTOR_ALREADY_EXECUTED`, `idempotency_reason = EXECUTOR_RUN_EXISTS`). This is the
executor half of the [idempotency split](10-shadow-queue.md): the queue dedupes by input
signature, the executor dedupes by already-executed run.

---

## 3. Releasability

`skill_executability_evaluator::evaluate_skill(skillname, userid, contextid)` gates each
command, in order — registry → runtime → active → capability → context. Deny reasons:

| Reason | Condition |
|--------|-----------|
| `DENY_NOT_REGISTERED` | skill not in the registry |
| `DENY_RUNTIME_DISABLED` | the agent extension is not installed/enabled |
| `DENY_INACTIVE` | `registry->is_skill_active()` is false |
| `DENY_MISSING_CAPABILITY` | the user lacks the skill's per-skill capability ([ch. 02 §3](02-authorization-and-context.md)) |
| `DENY_CONTEXT_INVALID` | the context is not valid for this skill |

A deny ends the command as `failed` — this is the last line of defense even though the
decision service already routed by risk.

---

## 4. The execution guard (no second preflight)

This resolves the diagram's `EXC_GUARD` node. There is **no `execution_guard` class**. For a
mutating command the executor calls:

```php
preflight_execution_gate::verify_guard_token(
    string $guardtoken, string $skillname, int $contextid, array $preparedinput
): bool
```

which is a constant-time `hash_equals` against a token rebuilt from the prepared input:

```php
token = sha256( skillname : contextid : json(normalized prepared_input) )
```

The token was computed during preflight and stored on the queue item. Verifying it proves
the prepared input is **byte-for-byte** the input that preflight approved — so the executor
can skip a second full preflight while still guaranteeing nothing was altered between
confirmation and execution. A mismatch fails the command.

> **⚠ Flowchart note.** `EXC_GUARD` names
> `execution_guard::verify(prepared_input, execution_guard_token, contextid, userid)`. The
> real method is `preflight_execution_gate::verify_guard_token(guardtoken, skillname,
> contextid, prepared_input)` — class is `preflight_execution_gate`, there is no `userid`
> parameter, and the skill name participates in the token. *Candidate correction.*

---

## 5. Running a skill & the three outcomes

`skill::execute(prepared_input, contextid, userid)` is called with the **prepared** input
from preflight (the skill must not redo heavy resolution). Its result `status` yields one of:

| Outcome | Result | Queue effect |
|---------|--------|--------------|
| **succeeded** | result / `produced_outputs` / follow-up suggestion | `succeeded` |
| **transient error** | `issue_codes` technical | `retry_waiting` (backoff + jitter) — but **R3 → `failed`/`R3_NO_RETRY`**) |
| **domain error** | error detail | `failed` + an observation |

The categorization into retry vs. fail is applied by
[`queue_transition_service`](10-shadow-queue.md) and the feedback service, not by the
executor itself.

---

## 6. Confirm-run terminalization

After a confirmed run, `execution_feedback_service` decides whether the turn is **terminal**
(no next mutating item, no pending follow-up, no planned placeholders → finalize) or a
**follow-up** (an error/retry, a next mutating item, or remaining planned placeholders →
continue the loop, `CONF_FOLLOW`). It also sanitizes results for the client (keeping skill,
status, detail, resultid, links) and feeds the execution feedback to the
[synchronizer](12-synchronizer.md) as observation context.

The `execution_observation_ledger` and `completed_command_history_service` record what ran,
for observations and for the compact bulk-result view.

---

## 7. Async execution: `execute_ai_run_adhoc`

When the confirm path chooses `executionmode = adhoc`, the confirmed run is processed by the
`execute_ai_run_adhoc` ad-hoc task instead of synchronously: it loads the run, marks it
`running`, calls `execute_commands()`, builds completion feedback, marks the run
`completed`/`failed`, and appends the assistant message to the thread. This decouples a
long-running mutation from the web request. See
[operations/tasks-and-async.md](../operations/tasks-and-async.md).

---

## 8. Spawn

`spawn_commands` (child commands with dependencies) are recognized at the schema/contract
level (`spawn_contract_service`), but the runtime enqueue path is **optional** — the executor
does not itself spawn. See [ch. 10 §6](10-shadow-queue.md#6-dependencies--spawn).

---

## See also

- [08 · Decision service](08-decision-service.md) — how a command reaches the executor.
- [09 · Preflight pipeline](09-preflight-pipeline.md) — where the prepared input + guard
  token are produced.
- [10 · Shadow queue](10-shadow-queue.md) — the statuses the outcomes map to.
