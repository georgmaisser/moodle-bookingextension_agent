# 04 · Agent runtime & the agent loop

> **Scope.** `agent_runtime` — the loop coordinator that drives plan → decide → observe →
> repeat, the loop budget, framework retry hints, and final persistence. Flowchart
> subgraph: `RUNTIME`.

`agent_runtime` is intentionally thin: it owns *loop steering and service delegation only*.
It does not plan (that's the [orchestrator](05-planner-orchestrator.md)), route (the
[decision service](08-decision-service.md)), or write the reply (the
[synchronizer](12-synchronizer.md)). It decides **how many times** to run a step, **when to
stop**, and **how to persist** the final answer.

**Files:** `classes/local/wbagent/agent_runtime.php`, `agent_state.php`,
`services/attempt_budget_dto.php`, `result_payload_summarizer.php`.

---

## Table of contents

1. [Entry points](#1-entry-points)
2. [The step machine](#2-the-step-machine)
3. [The loop budget](#3-the-loop-budget)
4. [Framework retry hints](#4-framework-retry-hints)
5. [`agent_state` — in-memory loop state](#5-agent_state--in-memory-loop-state)
6. [The attempt budget DTO](#6-the-attempt-budget-dto)
7. [Finalization & persistence](#7-finalization--persistence)
8. [Response-contract enforcement](#8-response-contract-enforcement)
9. [Flowchart notes](#9-flowchart-notes)

---

## 1. Entry points

| Method | Use |
|--------|-----|
| `run(threadid, contextid, userid)` | a single internal step then finalize (used by direct/confirm paths) |
| `run_loop(threadid, contextid, userid, maxsteps = 0)` | the multi-step loop; `maxsteps = 0` uses `MAX_LOOP_STEPS = 6` |
| `finalize_terminal_result(threadid, result)` | finalize an externally-prepared terminal result |

`run_loop()` is the primary path that `ai_send_message` invokes. It builds an
`agent_state`, then runs a bounded `for` loop.

---

## 2. The step machine

Each iteration calls the private `run_internal()`, which does **plan + decide with no
persistence**:

```
run_internal()
  → orchestrator::process(threadid, cmid, userid, observations, state)   // plan
  → trigger normalization (used_triggers, response_type)
  → agent_decision_service::process(result, …)                          // decide/route
  → re-attach planner context (phase_trace, planner_result)
```

The loop then branches on the result's `response_type`:

- **`execution_result`** — a read-only or executed command produced output. The runtime
  summarizes it into an **observation** (`result_payload_summarizer::for_observation()`),
  records it on the state (`state->record_step(commands, results, observation)`), checks the
  budget, and **continues** to the next step. This is how a multi-step request advances:
  step 1 searches, step 2 acts on what it found, etc.
- **anything else** (clarification / confirmation_request / confirm_pending / sufficient /
  error) — a **terminal planner result**. The loop runs the retry-hint logic (below) and
  otherwise finalizes and returns.

Every step decorates the result with bookkeeping: `loop_step`, `loop_max_steps`, and an
`attempt_budget` snapshot (§6), and persists a phase-trace snapshot into
`phase_trace_loop_history` thread metadata for telemetry.

---

## 3. The loop budget

The budget is deliberately simple and deterministic:

```php
const MAX_LOOP_STEPS = 6;

private function budget_guard_allows_next_llm_call(int $step, int $limit): bool {
    return ($step + 1) < $limit;
}
```

When an `execution_result` step wants to continue but the guard is false, or the `for`
loop runs out, the runtime returns a deterministic **budget-exceeded** result
(`build_budget_exceeded_result()`): `response_type = error`, issue code `BUDGET_EXCEEDED`,
and a localized "I had to stop, please simplify" message. Budget-exceeded is a
template-only finalization (no LLM polish — see [ch. 13](13-finalization-and-observations.md)).

---

## 4. Framework retry hints

Two *planner contract* failures are recoverable with a single nudge, handled entirely in
the loop without leaving the budget:

```php
const LOOP_RETRYABLE_ISSUE_CODES = [
    'CONTRACT_PARSE_ERROR',                          // construction JSON was not valid
    'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED',    // selector emitted the wrong shape
];
const LOOP_MAX_RETRIES_PER_ISSUE = 1;
```

When a terminal `error` result carries one of these codes, the runtime appends a precise
`RETRY_HINT` observation (`build_framework_retry_observation()`) and continues for one more
step — giving the model an exact correction ("return exactly one valid JSON object", "emit
exactly one direct command object"). Two guards prevent misuse:

- **Collision guard** (`has_active_non_planner_retry_signal`): if a queue/preflight/execution
  retry is already in flight (`RETRY_WAITING`, `PREFLIGHT_RETRY_HINT`,
  `EXECUTION_RETRY_HINT`, …), the loop does **not** add a planner retry — it finalizes with
  `PLANNER_RETRY_BLOCKED_LAYER_COLLISION`. Only one retry layer acts at a time.
- **R3 blocker** (`has_r3_retry_blocker`): if the result, its `risk_class`, any
  `queue_risk_classes`, any command, or an `R3_NO_RETRY` code indicates an R3 (irreversible)
  command, **no** planner retry happens. R3 never auto-retries (see [ch. 15](15-risk-classes.md)).

Once the per-issue budget is spent, the result is tagged `LOOP_RETRY_EXHAUSTED` and
finalized. These two retryable codes, the collision signals, and the planner-retry codes
are catalogued in [reference/issue-codes.md](../reference/issue-codes.md).

---

## 5. `agent_state` — in-memory loop state

`agent_state` is a value object that lives only for one `run_loop()` call and is **never
persisted**. It tracks:

- **observations** — the ordered plain-text summaries fed back into the next planner turn
  (`get_observations()`, `record_step()`, `append_observation()`).
- **steps** — full step records (commands, results, observation) for debugging.
- **per-run phase caches** — `familycache`, `selectedskillcache`, `paramscache`, keyed by a
  fingerprint, so a repeated identical phase within one loop can reuse its result instead of
  re-calling the model.
- **`extract_observed_command_signatures()`** — the set of `skill|inputhash` signatures
  already executed, used as a loop guard against redundant same-signature re-calls when an
  observation already exists.

It is created with `agent_state::make(limit)` and can be `make_resumed(limit, observations)`
to continue a loop that previously hit the step limit (the `_loop_resume` path).

---

## 6. The attempt budget DTO

`attempt_budget_dto` is the **global view** of retry/attempt state across all layers,
attached to every step result as `attempt_budget`. It tracks `loop_attempts`,
`preflight_retries`, `execution_retries`, `queue_retries`, a `hard_limit`, and an
`exhausted_reason`, and exports `remaining_llm_calls = hard_limit − loop_attempts`.

It can be built from loop context (`from_loop(loopstep, hardlimit)`) or from a queue item's
retry metadata (`from_queue_item(item, hardlimit)`). This single DTO is what lets the UI and
telemetry reason about "how much budget is left" no matter which layer consumed it — the
flowchart's `ATTB` node.

---

## 7. Finalization & persistence

Every terminal result flows through one funnel, `finalize_and_persist_result()`:

```
attach loop results (if state)
  → apply_finalization_strategy()        // classify → template / synchronizer / direct
  → enforce_final_response_contract()    // invariants (§8)
  → message_persistence_service::persist_assistant_message()
```

`apply_finalization_strategy()` calls the [finalization classifier](13-finalization-and-observations.md)
and routes to one of:

- **template-only** (`apply_template_only_finalization`) — use the planner message if
  present, else `finalization_template_service::resolve_message()`, else a localized
  fallback.
- **LLM polish** (`apply_synchronizer_message_polish`) — call the
  [synchronizer](12-synchronizer.md) via `synchronizer_routing_service::call_synchronizer_step()`,
  then merge **only** message refinements through `synchronizer_output_contract::merge()`.
  R3 results require an `irreversibility_notice` (absent → keep planner output); R2 results
  require an `affected_scope_summary` (absent → tag `SYNC_AFFECTED_SCOPE_SUMMARY_MISSING`).
- **direct** — return the result unchanged.

---

## 8. Response-contract enforcement

`enforce_final_response_contract()` is the last gate before persistence. It guarantees the
persisted assistant message is well-formed regardless of what the model produced:

- **Allowed response types** (`ALLOWED_FINAL_RESPONSE_TYPES`): `skill_call`,
  `confirmation_request`, `confirm_pending`, `clarification`, `sufficient`, `error`,
  `execution_result`. Anything else → coerced to `clarification` + `CONTRACT_INVALID_RESPONSE_TYPE`.
- **Command shape**: a single command object is wrapped into a list; `skill_call` /
  `confirmation_request` with no commands → coerced to `clarification` +
  `CONTRACT_COMMANDS_REQUIRED`; non-command response types have their `commands` cleared.
- **Message hygiene**: markdown fences stripped; empty message → a localized fallback
  (`build_contract_fallback_message()`).
- **Array invariants**: `ambiguities`, `ambiguity_options`, `errors`, `attempted_skills`,
  `issue_codes`, `used_triggers`, `results` are forced to arrays; `pending_confirmation_code`
  to a string; `lang` resolved via `language_policy_service`.

This is why downstream consumers (the UI, the store) can trust the shape of a persisted
message unconditionally.

---

## 9. Flowchart notes

> **⚠ `LOOP_STEP` step-message attribution.** The node says the loop head runs
> `clear_step_messages()` + `add_step_message(next_step_intent)`. Verified against code:
> `clear_step_messages()` runs **once** in `ai_send_message::execute()` before the loop;
> `add_step_message()` runs in **`orchestrator::process()`** (orchestrator.php ~line 389),
> once per `process()` call, using the selector's `next_step_intent`. Neither is in
> `agent_runtime::run_loop()`. *Candidate correction — move these labels from `LOOP_STEP`
> to the entry node and the orchestrator.*

> **✓ Two planner LLM calls confirmed.** The `LG_PLAN` invariant ("exactly two planner LLM
> calls") holds: `orchestrator::process()` issues a planner chat call only in
> `run_selection_phase()` (orchestrator.php:1057) and `run_construction_phase()`
> (orchestrator.php:1292). Discovery makes no planner chat call (only `invoke_embeddings` at
> :687); the third `invoke()` at :489 is the **synchronizer**, a separate pass. Note: on a
> non-`skill_call` selection (clarification/sufficient/error) construction is skipped, so a
> turn can have **one** planner call — "two" is the maximum, not a fixed count.

> **⚠ `phase_trace_loop_history` vs `phase_trace`.** `agent_runtime::persist_phase_trace_for_loop_step()`
> writes a `phase_trace_loop_history` metadata array (capped at `MAX_LOOP_STEPS`), separate
> from the store's canonical `phase_trace` / `planner_trace_history` keys (see
> [ch. 03 §5](03-conversation-store.md)). The flowchart's `CS15` lists only
> `next_step_intent`. *Candidate flowchart addition: the phase-trace telemetry keys.*

See [reference/flowchart-guide.md](../reference/flowchart-guide.md) for the consolidated log.
