# Reference · Glossary

> **Scope.** Terms of art used across this corpus, each with a one-line definition and a link
> to the chapter that covers it. Alphabetical.

- **Adhoc run** — a confirmed run executed asynchronously by the `execute_ai_run_adhoc`
  worker instead of inline. → [tasks](../operations/tasks-and-async.md)
- **Affected-scope summary** — the summary an R2 `sufficient` reply must include.
  → [15](../architecture/15-risk-classes.md)
- **Agent state** — the in-memory, never-persisted state of one `run_loop()` (observations +
  phase caches). → [04](../architecture/04-agent-runtime-and-loop.md)
- **Anonymizer** — masks PII before it reaches the model; reverses it for display.
  → [16](../architecture/16-support-services.md)
- **Attempt budget** — the global view of retry/attempt state across loop/preflight/queue/
  execution. → [04](../architecture/04-agent-runtime-and-loop.md)
- **Blocked confirmation** — a queue status for a mutation awaiting user confirmation, with a
  risk-based TTL. → [10](../architecture/10-shadow-queue.md)
- **Confirmation request** — the response type a mutating skill call is promoted to before it
  may run. → [08](../architecture/08-decision-service.md)
- **Constructor** — planner LLM call 2: builds the selected skill's parameters.
  → [07](../architecture/07-selection-and-construction.md)
- **Context prior** — current cm/course/page/user signals used as a ranking prior, never a
  hard filter. → [06](../architecture/06-discovery-families-embeddings.md)
- **Conversation store** — the DB-backed store of threads, messages, runs, and metadata.
  → [03](../architecture/03-conversation-store.md)
- **Corpus** — a documentation tree addressed by `(corpus_id, relpath)` and served by
  `core.explain_docs`. → [skills](../skills/README.md)
- **Decision service** — deterministic router between a planner result and the safety
  pipeline. → [08](../architecture/08-decision-service.md)
- **Discovery (Stage A/B/C)** — the no-LLM phase ranking skill families with bounded
  escalation. → [06](../architecture/06-discovery-families-embeddings.md)
- **Execution guard token** — a `sha256(skill:context:prepared_input)` the executor verifies
  so no second full preflight is needed. → [11](../architecture/11-executor.md)
- **Family** — a `<namespace>.<family>` grouping of skills; the unit of discovery ranking.
  → [06](../architecture/06-discovery-families-embeddings.md)
- **Finalization classifier** — picks the reply strategy (direct_final / template_only /
  llm_polish) from result metadata. → [13](../architecture/13-finalization-and-observations.md)
- **Idempotency** — queue dedupes by input signature; executor skips already-executed runs.
  → [10](../architecture/10-shadow-queue.md) / [11](../architecture/11-executor.md)
- **Irreversibility notice** — the notice an R3 `sufficient` reply must include.
  → [15](../architecture/15-risk-classes.md)
- **Issue code** — a structured routing signal on a result. → [issue-codes](issue-codes.md)
- **Lazy skill loader** — loads the full schema of only the selected skill.
  → [07](../architecture/07-selection-and-construction.md)
- **Loop budget** — `MAX_LOOP_STEPS = 6`; the cap on planning steps per turn.
  → [04](../architecture/04-agent-runtime-and-loop.md)
- **`next_step_intent`** — the planned next step; appended to the embedding query so short
  confirmations stay on-task. → [06](../architecture/06-discovery-families-embeddings.md)
- **Observation** — a compact, anonymized summary of a step's result fed into the next step.
  → [13](../architecture/13-finalization-and-observations.md)
- **Pending intent** — the bridge between a proposed mutation and the user's confirmation.
  → [03](../architecture/03-conversation-store.md)
- **Phase trace** — the per-turn discovery/selection/construction snapshot for telemetry.
  → [observability](../operations/observability.md)
- **`planned` (placeholder)** — an intent-only queue item for a future multi-step skill.
  → [10](../architecture/10-shadow-queue.md)
- **`planned_steps[]`** — the selector's list of future steps on a multi-step first turn.
  → [07](../architecture/07-selection-and-construction.md)
- **Planner** — the orchestrator's discovery → selection → construction pipeline.
  → [05](../architecture/05-planner-orchestrator.md)
- **Preflight (L1/L2/L3/L3-EXT)** — layered, risk-gated validation/prepare that never
  executes. → [09](../architecture/09-preflight-pipeline.md)
- **`preflight_result_v2`** — the single DTO every preflight returns.
  → [09](../architecture/09-preflight-pipeline.md)
- **Readiness gate** — the auth/subsystem/provider/context check before the loop starts.
  → [01](../architecture/01-entry-and-web-services.md)
- **Risk class (R0–R3)** — the single declaration driving confirmation/retry/preflight/reply.
  → [15](../architecture/15-risk-classes.md)
- **Selector** — planner LLM call 1: picks exactly one skill.
  → [07](../architecture/07-selection-and-construction.md)
- **Session allowance (auto-confirm)** — a per-(user, context) allowance that lets a
  confirmation auto-fire. → [03](../architecture/03-conversation-store.md)
- **Shadow queue** — the metadata-backed command queue (sequencing, idempotency, holds,
  retries, deps). → [10](../architecture/10-shadow-queue.md)
- **Skill** — one capability the agent can invoke. → [14](../architecture/14-skill-layer.md) /
  [skills](../skills/README.md)
- **Skill contract / prompt contract** — the routing-facing description of a skill (intent,
  anchors, family, risk_class, …). → [14](../architecture/14-skill-layer.md)
- **Spawn** — child commands with dependencies; a schema/contract path, runtime enqueue
  optional. → [10](../architecture/10-shadow-queue.md)
- **Synchronizer** — the final reply pass; refines the message without changing semantics.
  → [12](../architecture/12-synchronizer.md)
- **Trigger** — a server-derived signal about what a turn is (e.g. lookup); never routes to a
  skill. → [16](../architecture/16-support-services.md)
- **wizard** — the engine's internal name (Wunderbyte agent); the `local/wizard` namespace.
  → [overview](../architecture/README.md)
