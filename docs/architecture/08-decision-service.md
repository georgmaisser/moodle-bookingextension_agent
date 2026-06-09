# 08 · Decision service

> **Scope.** `agent_decision_service::process()` — the deterministic router between a parsed
> planner result and the safety pipeline. Flowchart subgraph: `DECIDSVC`.

The decision service is where the engine stops trusting the model and starts enforcing
policy. The planner *proposed* something; the decision service decides — with no LLM — what
is allowed to happen to it: answer directly, ask for clarification, block a lookup that
tried to mutate, or promote a mutation to a confirmation and hand it to preflight.

**Files:** `services/decision/agent_decision_service.php`, `services/pending_intent_service.php`,
`services/pending_queue_command_service.php`, `message_trigger_registry.php`,
`services/queue_command_mapper.php`, `services/queue_transition_service.php`.

---

## 1. Entry

```php
process(array $result, int $threadid, int $cmid, int $userid,
        string $outputlang, int $previewoptionid): array
```

It runs an **ordered, deterministic guard chain**, then routes by `response_type`.

---

## 2. The guard chain

Evaluated in this exact order (each guard short-circuits to a clarification):

| # | Guard | Trigger | Outcome |
|---|-------|---------|---------|
| 1 | **Preview** | `core.is_preview_request` trigger **and** `previewoptionid > 0` | clarification carrying the preview message |
| 2 | **Pending block** | a pending intent exists **and** the new message is an unrelated intent (`should_block_new_intent_while_pending`) | clarification: resolve the pending confirmation first |
| 3 | **Lookup guard** | `core.is_lookup_request` trigger **and** the result has mutating commands | clarification: a lookup must not mutate |
| 4 | **Risk promotion** | mutating commands **and** `response_type == skill_call` | promote to `confirmation_request` |

Triggers come from the server-side `message_trigger_registry` (no trigger→skill routing —
the server *derives* `core.is_lookup_request` etc.; see [ch. 16](16-support-services.md)).
Each guard contributes to a `safety_context` (issue codes + route hints + pending policy).

---

## 3. Resolving risk

A command's risk class is resolved by `resolve_command_risk_class()`:

1. an explicit `risk_class` on the command, if valid; else
2. the skill's declared `get_risk_class()` from the registry; else
3. **`R3`** — an unknown skill is treated as the most dangerous (fail-safe default).

Commands are split into `readonly` (R0) and `mutating` (R1/R2/R3) by
`split_commands_by_risk_class()`.

---

## 4. Routing by `response_type`

| response_type | Handler | Effect |
|---------------|---------|--------|
| `confirm_pending` | `handle_confirm_pending()` | rebuild the pending mutation from the queue, **re-run preflight**, refresh prepared_input + guard tokens |
| `skill_call` / `confirmation_request` | `handle_command_routing()` | enqueue + (for confirmations) `handle_preflight()` |
| `clarification` / `sufficient` / `error` | — | persist or clear the pending-intent pointer; finalize |

### `handle_command_routing()` by risk

| Risk | Queue status | Execution |
|------|--------------|-----------|
| **R0** | enqueued as `readonly` → `ready` | executed **synchronously now** (`execute_readonly_commands()`); no confirmation |
| **R1** | `mutating` → preflight → ready (if session-allow / autoconfirm) **or** `blocked_confirmation` | confirmation-gated |
| **R2** | `mutating` → `blocked_confirmation` **always**, regardless of autoconfirm | explicit confirmation forced |
| **R3** | `mutating` → `blocked_confirmation`, manual only | no session-allow; no execution retry |

The R0 path is what makes "search for Yoga options" feel instant: read-only commands never
queue for confirmation, they run inline and produce an observation for the next loop step.

---

## 5. Preflight handoff

`handle_preflight()` runs the [preflight pipeline](09-preflight-pipeline.md) on the
confirmation's commands, then forwards the result and the per-item risk class to
`queue_transition_service::apply_preflight_decision(…, autoconfirmmode)`. That service maps
the preflight status + risk class to a queue transition:

- `pass` + (R1 with session-allow/autoconfirm) → `ready`;
- `pass`/`soft_block` otherwise → `blocked_confirmation`;
- `retry_hint` → `retry_waiting` (but **R3 → `failed` with `R3_NO_RETRY`**);
- `hard_block` → `failed`.

It also writes the **prepared input** and the **guard token** onto the queue item (see
[ch. 11](11-executor.md)).

---

## 6. Pending bookkeeping

When the turn ends as a `confirmation_request` with commands,
`persist_pending_intent_pointer()` stores a pending intent (queue item ids + their risk
classes) and a confirmation code via `pending_intent_service::set()`. Otherwise the pending
intent is cleared. This is the state that `ai_confirm_run` / `ai_discard_pending` later
consume, and that guard #2 reads.

---

## 7. Flowchart notes

> **✓ Guard chain confirmed** — preview → pending → lookup → promotion, in that order, with
> the exact trigger names. Risk-routed command handling (R0 inline / R1 session-allow /
> R2 forced / R3 manual) matches.

> **✓ Unsafe-default rule (annotated).** `resolve_command_risk_class()` treats an unknown
> skill as **R3** (fail-safe). The `D_PROMOTE` node now states this invariant.

See [reference/flowchart-guide.md](../reference/flowchart-guide.md).
