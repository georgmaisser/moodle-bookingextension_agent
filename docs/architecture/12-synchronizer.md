# 12 · Synchronizer

> **Scope.** The final user-facing reply contract: turning a normalized result into a
> polished message without changing execution semantics. Flowchart subgraph: `SYNC`.

The agent separates *deciding what to do* from *writing the reply*. By the time the
synchronizer runs, the decision is already made and (if any) the command is already queued
or executed. The synchronizer only makes the **message** good — in the user's language,
faithful to what actually happened — and it is held to a strict contract so it can never
quietly change what the engine decided.

**Files:** `services/synchronizer_input_builder.php`, `synchronizer_prompt_builder.php`,
`synchronizer_routing_service.php`, `synchronizer_output_contract.php`; the call site is
`agent_runtime::apply_synchronizer_message_polish()` ([ch. 04](04-agent-runtime-and-loop.md)),
and the LLM pass is `orchestrator::process_synchronizer()` ([ch. 05 §8](05-planner-orchestrator.md)).

---

## 1. When it runs

Only for **`llm_polish`** finalization states (sufficient / clarification / safe domain
error — see [ch. 13](13-finalization-and-observations.md)). Template-only and direct-final
states never call it. This is what keeps the agent fast and safe: confirmations, schema
errors, and budget messages do not pay for an extra LLM call.

---

## 2. Input

`synchronizer_input_builder::build_observations(result, state)` assembles the context the
reply is grounded in — and nothing more (no skill discovery, no commands):

- **observations** from `agent_state` (or, as fallback, the `observation` field of each
  `loop_results` entry);
- a **source-result** observation (`FINAL_SOURCE_RESULT`: response_type, issue_codes,
  attempted_skills, message);
- a **phase-trace** observation (sanitized discovery/selection/construction snapshot — no
  full schemas);
- an **execution-feedback** observation (status counts + skills) on the confirm path.

---

## 3. Prompt & language

`synchronizer_prompt_builder` builds `[SYSTEM]` + optional `[SYSTEM_RUNTIME]` + message
history + `[OBSERVATION n]` blocks + an `[OUTPUT_CONTRACT]` + `[ASSISTANT]`. The output
contract encodes the hard rules directly in the prompt:

- return exactly one JSON object; **`commands = []` always** (the synchronizer may never
  emit commands);
- **fact priority**: completed observations are authoritative > completed commands > earlier
  assistant text; if an observation contradicts earlier assistant text, follow the
  observation;
- **pending steps**: if queued actions remain, say the agent will continue — **never**
  suggest a manual workaround.

The language contract: the reply follows the latest user message; *message quality >
routing detail* (the `SYNC_LANG` / `LG_LANG` rule).

---

## 4. Routing

`synchronizer_routing_service::call_synchronizer_step(orchestrator, …)` delegates to
`orchestrator::process_synchronizer()`, which prefers the Wunderbyte
`generate_agent_reply` action (wpr, the larger model tuned for replies) and falls back to
`generate_text`. It is a **single** LLM call.

---

## 5. The output contract (the safety net)

`synchronizer_output_contract::merge(sourceresult, syncresult)` decides whether to accept
the polished message. It operates in an enforcement mode (`observe` / `warn` / `enforce`,
default enforce) and:

**May refine** — the `message`, the `lang`, and presentation; it attaches
`sync_gate_status` / `sync_gate_reason` telemetry.

**Must reject (→ roll back to the planner/source output)** when the synchronizer would
change meaning:

| Reject reason | Trigger |
|---------------|---------|
| invented commands | sync output has non-empty `commands` |
| `SYNC_RESPONSE_TYPE_ERROR_REJECTED` | sync `response_type = error` |
| `SYNC_CONTRACT_ISSUE_REJECTED` | sync issue_codes contain `CONTRACT_*` |
| `SYNC_FACT_CONFLICT_REJECTED` | a fact in the source (e.g. the created `option_id`) is missing from the sync message |
| source-conflict reject | the source result is an error / failed postcondition |
| raw-excerpt / parse-failure reject | the sync message leaks a raw excerpt or parse error |

On any rejection the **source (planner) output is used unchanged** — the synchronizer can
only ever make a safe message nicer, never rewrite history. These rejection codes are
template-only finalization triggers themselves (see [ch. 13](13-finalization-and-observations.md)),
so a rejected polish degrades to a deterministic message.

**Risk-class requirements** are enforced one level up, in
`agent_runtime`/`finalization_classifier`: an R3 `sufficient` reply must carry an
`irreversibility_notice` (absent → keep planner output), and an R2 `sufficient` reply must
carry an `affected_scope_summary` (absent → tag `SYNC_AFFECTED_SCOPE_SUMMARY_MISSING`). See
[ch. 15](15-risk-classes.md).

---

## 6. Flowchart notes

> **✓ Confirmed:** `llm_polish`-only entry; observations-not-commands input; `commands = []`
> contract; prefer `generate_agent_reply` → `generate_text`; semantic-drift rollback to
> planner output; R3 irreversibility / R2 affected-scope requirements.

> Note: the `SCONTRACT` node intentionally summarizes the rule as "command-semantics drift →
> discard". The concrete rejection codes (`SYNC_FACT_CONFLICT_REJECTED`,
> `SYNC_RESPONSE_TYPE_ERROR_REJECTED`, `SYNC_CONTRACT_ISSUE_REJECTED`, …) live here and in
> [reference/issue-codes.md](../reference/issue-codes.md) — this is added detail, not a
> diagram discrepancy.
