# Operations · Observability & debugging

> **Scope.** How to see what the agent did: LLM debug logs, phase traces, routing decision
> logs, preflight audit, and the thread debug endpoint.

**Files:** `llm_debug_logger.php`, `services/telemetry/routing_decision_log_service.php`,
`services/preflight_audit_logger.php`, `external/ai_get_thread_debug_logs.php`,
`services/runtime_step_analysis_service.php`, `Blueprints/observability_queries.md`.

---

## 1. LLM debug logs

Set **`aidebugmode`** (admin setting) to capture every raw LLM exchange.
`llm_debug_logger::is_enabled()` reads it; `log_exchange()` writes a row to the table

```
local_wizard_ai_llm_debug   →  physical: m_local_wizard_ai_llm_debug
```

Each row records `threadid`, `userid`, `contextid`, a `source` label (which call site:
selection / construction / synchronizer / feedback / followup), the raw request and response
text, a `success` flag, an optional error message, and `timecreated`. `log_exchange_always()`
forces a row regardless of the flag (for hard failures).

**Reading them.** The `ai_get_thread_debug_logs` web service (`limit` clamped 1–500) surfaces
them to the UI; for SQL analysis the queries in
[`Blueprints/observability_queries.md`](../Blueprints/observability_queries.md) filter the
table by thread. A typical thread analysis selects `source`, `success`, and the request/
response text ordered by `timecreated`.

---

## 2. Phase traces

Every turn records its discovery/selection/construction trace. The canonical store keys are
`phase_trace` (latest) and `planner_trace_history`; `agent_runtime` additionally accumulates
`phase_trace_loop_history` (capped at `MAX_LOOP_STEPS`) for per-loop-step telemetry. These
live in the thread's `metadatajson` (see
[ch. 03 §5](../architecture/03-conversation-store.md#5-thread-metadata)) and are echoed to the
UI as `phasetracejson`.

---

## 3. Routing decision log

`routing_decision_log_service` records *why discovery routed the way it did* — into thread
metadata, not a table. It captures normalized telemetry (catalog selection mode, embedding
path, discovery stage, confidence, escalation reason), a **shadow** decision (the parallel
family-discovery path), and a live-vs-shadow embeddings comparison, plus the feature-flag
snapshot. Keys: `routing_decision_telemetry`, `routing_shadow_decision`,
`routing_embeddings_comparison`, and a rolling `routing_decision_log` (≤ 50 entries). This is
how you tell whether the semantic or the deterministic path produced a given selection.

---

## 4. Preflight audit

With `preflight_audit_enabled` on, `preflight_audit_logger::append()` writes a structured
event per preflight evaluation (skill, layer, status, reason code, issue codes, retry/timing,
error class) into the `_preflight_audit_log` thread-metadata key — a full trail of why a
mutation was allowed, blocked, or retried ([ch. 09 §6](../architecture/09-preflight-pipeline.md#6-audit)).

---

## 5. Runtime step analysis

`runtime_step_analysis_service` supports the runtime's per-step bookkeeping (what each loop
step did, the attempt budget). Combined with the phase trace it reconstructs a full turn:
discovery → selection → construction → decision → preflight → queue → execute → reply.

---

## 6. Where to look for what

| Question | Source |
|----------|--------|
| What exactly did the model receive/return? | `aidebugmode` → `local_wizard_ai_llm_debug` |
| Why was *this* skill chosen? | phase trace + routing decision log + [skill-selection debug](governance.md#4-skill-selection-debugging) |
| Why was a mutation blocked/retried? | `_preflight_audit_log` (preflight) + queue retry metadata ([ch. 10](../architecture/10-shadow-queue.md)) |
| Did a regression slip in? | the [benchmark](benchmarking.md) trend + CI gate |
