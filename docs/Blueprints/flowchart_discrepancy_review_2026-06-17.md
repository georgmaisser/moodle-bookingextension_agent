# Flowchart discrepancy review — agent commits 2026-06-10 … 2026-06-17

> **Purpose.** Cross-check the past week's `bookingextension_agent` commits against the primary
> architecture doc [`flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd`](flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd)
> and list where the diagram now diverges from the code.
>
> **Policy note.** Per the project's flowchart policy, the flowchart is the authoritative
> architecture doc; **discrepancies are to be discussed with Georg, not silently aligned.** This
> report changes neither the code nor the flowchart — it only records findings and recommendations.
>
> Line/node references are against the flowchart as of this date (685 lines).

## Summary

| # | Area | Commit(s) | Flowchart node | Severity | Action |
|---|------|-----------|----------------|----------|--------|
| 1 | AI execution mode (adhoc/async) removed | `69f295a` | `ADHOC` (l.20), edges l.296/301, `LG_ADHOC` (l.600 + style l.670) | **High** | Flowchart shows a path that no longer exists → remove |
| 2 | Preflight audit logging retired | `e9695d4` | `PAL` (l.190) | Medium | Flowchart shows an active (conditional) step that is now inert → remove/mark retired |
| 3 | Anonymizer behaviour expanded | `58ea94e`, `d61dca0` | `ANON` (l.253), `OBS_ACCUM` (l.63) | Medium | Node is now incomplete (missing re-anchoring + two fail-closed gates) → expand |
| 4 | Queue DAG validation + blocked-TTL no longer toggleable | `e9695d4` | `Q_DAG`/`Q_DAGFAIL`/`Q_BLOCKED`/`Q_FAIL_TTL` | None (improves alignment) | Flowchart already depicted these as unconditional → no change |
| 5 | Memory/recall skill namespace `core.*` vs `wbagent.*` | (adjacent) | l.119, l.581 | Low | Naming mismatch, likely predates this week → verify & correct names |
| 6 | New `wbagent.scaffold_skill`; trial/onboarding subsystem | `2883775`, trial commits | — | Low / out of scope | Below the runtime flowchart's granularity → note only |

---

## 1. AI execution mode (adhoc) — flowchart depicts a removed path  ·  **High**

**Change.** `69f295a` removed the `aiexecutionmode` setting and the entire adhoc/async execution
path: a confirmed run now **always executes inline** in `confirm_run_service`. The
`execute_ai_run_adhoc` task class was deleted; the `responseType === 'queued'` branch was removed
from the chat JS.

**Flowchart still shows it:**
- Node `ADHOC` (l.20): `"execute_ai_run_adhoc\nqueued by confirm_run_service\n(process confirmed run async)"`.
- Edge l.296: `ACR -->|"executionmode=adhoc\nqueue_adhoc_task"| ADHOC`.
- Edge l.301: `ADHOC -->|"process queued run"| EXC`.
- Legend `LG_ADHOC` (l.600) + style (l.670).

**Discrepancy.** The diagram presents a queued/async execution branch that no longer exists. The
direct path (`ACR -->|allow_session=true| CS11`, `consume pending intent → CS13`) is unchanged and
still correct.

**Recommendation (for Georg).** Remove the `ADHOC` node, both adhoc edges (l.296, l.301) and the
`LG_ADHOC` legend + style. The run-status `queued` is now produced only by the **skill-step queue**
(`Q_*` nodes), which is a different concept and stays.

## 2. Preflight audit logging retired — flowchart shows it active  ·  **Medium**

**Change.** `e9695d4` removed the `preflight_audit_enabled` admin setting. `preflight_audit_logger::append()`'s
gate is now always false (the setting is gone), so nothing is written; call sites are inert no-ops.
The logger class and call sites were intentionally **kept** (low-risk no-ops), so the code still
contains the method.

**Flowchart still shows it as an active step:**
- Node `PAL` (l.190): `"preflight_audit_logger::append()\n(if preflight_audit_enabled)\n→ conversation_store thread metadata"`.

**Discrepancy.** The node depicts a conditional audit-write that no longer happens (the condition is
permanently false; the feature wrote to thread metadata and was never surfaced).

**Recommendation (for Georg).** Either remove the `PAL` node (and its edges), or relabel it as
"retired (no-op)". Decide together whether the dormant logger code should also be physically deleted
(separate, larger cleanup — see commit message of `e9695d4`).

## 3. Anonymizer behaviour expanded — `ANON` node incomplete  ·  **Medium**

**Change.** Two privacy commits added behaviour the diagram does not show:
- `58ea94e`: **cross-thread re-anchoring** of recalled tokens (`reanchor_value_for_thread()`) — when
  `recall_memory` surfaces content anonymized under another thread's token map, its `ANON_USER_*`
  tokens are re-minted into the current thread's map (token-to-token, no clear text), so display
  de-anonymization resolves them. Plus a **fail-closed display gate**: `deanonymize_message_for_display()`
  now replaces any unresolved `ANON_USER_*` token with a neutral label instead of leaking it.
- `d61dca0`: a **fail-closed command-input gate** — the executor refuses to run a skill when a
  command parameter still carries an unresolved `ANON_USER_*` token (`has_unresolved_anon_tokens()`
  → `RECOVERABLE_INPUT_ERROR`), so the planner restates instead of acting on a placeholder.

**Flowchart still shows only the basics:**
- Node `ANON` (l.253): `"privacy_anonymizer\nanonymize_value_for_llm()\ndeanonymize_for_display()"`.
- `OBS_ACCUM` (l.63) references `anonymize_value_for_llm()` (still correct).

**Discrepancy.** `ANON` omits re-anchoring and both fail-closed gates; `deanonymize_for_display()` is
a stale shorthand for `deanonymize_message_for_display()` and does not convey the redaction
behaviour. The executor's fail-closed input gate is not represented in the run loop either.

**Recommendation (for Georg).** Expand `ANON` to list `reanchor_value_for_thread()` and the
fail-closed redaction; consider an executor-side edge for the unresolved-token → `RECOVERABLE_INPUT_ERROR`
gate.

## 4. Queue DAG validation + blocked-confirmation TTL no longer toggleable — no change needed

**Change.** `e9695d4` removed the `queue_dag_validation_enabled` and `queue_blocked_ttl_enabled`
settings; both behaviours are now **unconditional** (always validate the depends_on DAG; always
expire stale `blocked_confirmation` items). `aigovernancestrictmode` was removed from the admin UI
(the registry still reads it, default off, for CI).

**Flowchart.** `Q_ENQUEUE`→`Q_DAG`→`Q_DAGFAIL` (l.197/200/201) and `Q_BLOCKED`/`Q_FAIL_TTL`
(l.209/213) already depict these as unconditional steps — there was never a toggle node. `aigovernancestrictmode`
does not appear in the flowchart at all.

**Discrepancy.** None. Removing the toggles makes the code match the diagram. **No flowchart change.**

## 5. Memory/recall skill namespace: `core.*` vs `wbagent.*`  ·  **Low (likely pre-existing)**

**Observation.** The flowchart labels the memory/recall skills `core.recall_memory`, `core.remember`,
`core.forget`, `core.list_memories` (l.119, l.581). The code uses the **`wbagent.`** namespace
(`wbagent.recall_memory`, `wbagent.remember`, …) — reinforced this week by `wbagent.scaffold_skill`.

This week's recall change (`f5f31fe`, temporal context in the observation) did **not** rename
anything, so this mismatch most likely **predates** the review window. Flagged here because it sits
right next to this week's recall work.

**Recommendation (for Georg).** Verify the intended namespace and correct the names in the flowchart
(and/or the `LG_MEM` legend) if `wbagent.*` is canonical.

## 6. Below-granularity / out-of-scope items (note only)

- **`wbagent.scaffold_skill`** (`2883775`, `3b0e219`, `ff470cd`): a new third-party-scaffolding skill.
  The flowchart enumerates skill *families/legends*, not individual skills, so no node is required; it
  fits conceptually under `LG_3P` ("Third-party onboarding"). No deviation.
- **Trial/onboarding subsystem** (`e85cbdb`, `9e53fa1`, `adc30f8`, `3d0671e`, `1994573`, `3b70c0e`,
  `67c1848`, usage bar `24ea8e8`, navbar reuse `a72220f`): provisioning a provider from a trial key,
  GDPR consent, abuse caps, one-time keys, endpoint detection, credit bar. The runtime flowchart only
  references `activate_trial_context` in the availability layer (`AZ4`, l.31); the **provisioning/onboarding
  flow itself is not depicted** (it is outside the request-runtime scope). Consider a *separate* trial
  diagram rather than expanding this one.
- **Settings hardcodes** (`67c1848` trial endpoint, `db79170` licensekey note): configuration only, no
  runtime-flow impact.
- **Discovery/planning** (`ab3b171` context as ranking prior; `9299077` named target distinct from
  context): the flowchart's `LG_PLAN` (l.597) already states "Moodle context acts as ranking prior, not
  hard filter" and `FRANK` (l.98) folds `context_prior` into the signal — and `ad038c1` (2026-06-15)
  explicitly updated the discovery flowchart. These appear **already reflected**; no deviation found.

---

## Recommended next steps
1. Decide with Georg on findings **1–3** (the real deviations) and apply the agreed flowchart edits.
2. Confirm the memory/recall namespace (finding 5) and fix the labels.
3. Optionally start a dedicated trial/onboarding diagram (finding 6) instead of overloading the
   runtime flowchart.
