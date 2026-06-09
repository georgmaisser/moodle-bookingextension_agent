# Operations · Tasks & async execution

> **Scope.** Scheduled and ad-hoc tasks, and the async path for confirmed runs.

**Files:** `classes/task/*`, `db/tasks.php`, `services/confirm_run_service.php`.

---

## 1. The adhoc run worker — `execute_ai_run_adhoc`

When `aiexecutionmode = adhoc` (or the confirm path chooses it), a confirmed run is executed
**asynchronously** instead of inline. `confirm_run_service` queues this ad-hoc task with
`{runid, userid, contextid, idempotencykey}`. The task:

1. loads the run from `conversation_store`; skips if already completed (idempotency);
2. resolves context, marks the run `running`;
3. runs `executor::execute_commands()` on the run's JSON commands;
4. builds completion feedback (`execution_feedback_service`);
5. marks the run `completed`/`failed` and appends the assistant message to the thread.

This decouples a long-running mutation from the web request. The synchronous alternative
(`direct` mode) runs the same executor inline during `ai_confirm_run`. See
[ch. 11 §7](../architecture/11-executor.md#7-async-execution-execute_ai_run_adhoc).

---

## 2. Embeddings rebuilds (ad-hoc)

| Task | Rebuilds | Custom data |
|------|----------|-------------|
| `rebuild_skill_catalog_embeddings_adhoc` | the skill-catalog vector index (`family_embeddings_index_service::rebuild_catalog()`) | `{model?, dimensions?, force?}` |
| `rebuild_docs_embeddings_adhoc` | the docs corpora index (`docs_embeddings_index_service::rebuild()`) | `{model?, dimensions?, force?}` |

These are queued automatically when a readiness check finds the index missing/stale (see
[ch. 06 §8](../architecture/06-discovery-families-embeddings.md#8-embeddings-infrastructure)),
or manually from the [governance page](governance.md). Both report created/updated/deleted
counts.

---

## 3. Scheduled cleanup

| Task | Schedule | Does |
|------|----------|------|
| `cleanup_attachment_temp_files_adhoc` | every 15 min | delete upload temp files older than the token TTL (30 min) — a safety net on top of per-token invalidation |
| `cleanup_old_benchmark_runs_task` | configurable | purge benchmark runs older than `benchmark_retention_days` (baselines preserved) |

---

## 4. Sync vs. adhoc decision

`confirm_run_service` chooses synchronous execution vs. the worker based on
`aiexecutionmode` (and run characteristics). Either way the **executor** is the single place
a skill runs, and idempotency (`run_exists_other_than`) guarantees a retried or duplicated
confirmation never double-applies. See [configuration](configuration.md) for the mode
setting.
