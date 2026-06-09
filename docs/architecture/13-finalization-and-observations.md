# 13 · Finalization classifier & the observation loop

> **Scope.** The deterministic classifier that picks a reply strategy, and the observation
> machinery that feeds one loop step into the next. Flowchart subgraphs: `RUNTIME`
> (finalization) + `OUTCOMES`.

Two cross-cutting mechanisms live here. The **finalization classifier** decides *how* the
reply is produced — from result metadata only, never by LLM intuition. The **observation
builder** decides *what the next loop step sees* — a compact, anonymized summary of what
just happened.

**Files:** `services/finalization_classifier.php`, `services/finalization_template_service.php`,
`queue/observation_builder.php`, `result_payload_summarizer.php`, `privacy_anonymizer.php`,
`loop_finalizer.php`, `services/runtime_step_analysis_service.php`.

---

## 1. Three strategies

`finalization_classifier::classify(result)` returns one of:

- `STRATEGY_DIRECT_FINAL` — use the result as-is (structured/risky/non-message states);
- `STRATEGY_TEMPLATE_ONLY` — fill a deterministic template message (no LLM);
- `STRATEGY_LLM_POLISH` — run the [synchronizer](12-synchronizer.md).

The decision uses **only** normalized result metadata: `response_type`, whether the result
has commands, `issue_codes`, `error_class`, and a `structural_failure` flag. This is the
`LG_CLASS` rule: *no LLM routing by intuition; classification must be testable from
normalized result metadata only.*

---

## 2. The classifier matrix

Evaluated as a precedence cascade (the `LG_MATRIX` legend), matching the code exactly:

| # | Condition | Strategy |
|---|-----------|----------|
| 1 | `has_commands` is true | **direct_final** |
| 2 | `response_type ∈ {confirmation_request, confirm_pending, skill_call}` | **direct_final** |
| 3 | `issue_codes ∩ DIRECT_ISSUE_CODES ≠ ∅` | **direct_final** |
| 4 | `issue_codes ∩ TEMPLATE_ISSUE_CODES ≠ ∅` | **template_only** |
| 5 | `error_class ∈ TEMPLATE_ERROR_CLASSES` | **template_only** |
| 6 | `response_type ∈ {sufficient, clarification}` | **llm_polish** |
| 7 | `response_type = error` and **not** `structural_failure` | **llm_polish** |
| 8 | default | **direct_final** |

Precedence is **direct_final > template_only > llm_polish**. The code sets:

- **DIRECT_ISSUE_CODES** — `SCHEMA_ERROR`, `SCHEMA_UNAVAILABLE`, `DEPENDENCY_CYCLE`,
  `CONTRACT_INVALID_RESPONSE_TYPE`, `CONTRACT_COMMANDS_REQUIRED`, plus the `CONTRACT_PHASE_*`
  family.
- **TEMPLATE_ISSUE_CODES** — `BUDGET_EXCEEDED`, `BLOCKED_TIMEOUT`, `RETRY_EXHAUSTED`,
  `PERMISSION_ERROR`, `VALIDATION_ERROR`, `CONTEXT_INVALID`, `CONTRACT_SELECTION_SKILL_MISSING`,
  and the `SYNC_*` rejection codes (so a rejected polish degrades to a template).
- **TEMPLATE_ERROR_CLASSES** — `provider_timeout`, `transient_io`, `auth_failed`,
  `quota_exceeded`, `runtime_disabled`.

---

## 3. Risk-class requirements

`requires_irreversibility_notice(result)` → true when `response_type = sufficient` **and**
the explicit risk class is **R3**. `requires_affected_scope_summary(result)` → true for a
**R2** `sufficient`. These gate the synchronizer merge (see [ch. 12](12-synchronizer.md) and
[ch. 15](15-risk-classes.md)).

---

## 4. Template service

`finalization_template_service::resolve_message(result)` maps a template-only result to a
human message: first a localized string for the issue code
(e.g. `PERMISSION_ERROR → error_ai_permission_denied`), else a built-in English fallback,
else an error-class message (e.g. `quota_exceeded → error_ai_provider_quota_exceeded`),
optionally appending raw error details. This is why budget/permission/provider errors get a
clear, consistent message with no model call.

---

## 5. Observations: feeding the next step

When a step produces an `execution_result`, the runtime turns it into an **observation** —
the only thing the next planner turn sees about what happened:

- `result_payload_summarizer::for_observation(results, step)` and
  `observation_builder::build_observation()` produce a **compact** string (bulk results are
  summarized, not dumped);
- `privacy_anonymizer::anonymize_value_for_llm()` masks PII **before** it re-enters a prompt
  (`deanonymize_for_display()` reverses it for the user);
- the observation is appended to `agent_state` and carried into the next
  `orchestrator::process()` call.

This loop — execute → summarize → anonymize → observe → re-plan — is what lets a multi-step
request progress on facts ("found 3 options: …") rather than guesses.

---

## 6. Terminal outcomes

The `OUTCOMES` subgraph distinguishes how a step ends:

| Outcome | Meaning |
|---------|---------|
| `FINAL_OUT` | a final normalized response persisted (clarification / confirmation_request / sufficient / error / skill_call) |
| `SKIP_OUT` | `logical_skip` — a non-failure dependency skip (continues with an observation) |
| `IDEM_OUT` | `idempotent_skip` — already executed (terminal) |
| `FAIL_OUT` | `failed` — hard_block / budget_exceeded / retry_exhausted / dependency_cycle (terminal) |

`runtime_step_analysis_service` and `loop_finalizer` support the runtime's bookkeeping
around these.

---

## 7. Flowchart notes

> **✓ Confirmed:** the `LG_MATRIX` precedence cascade matches the code rule-for-rule, with
> the issue-code/error-class sets above. The code additionally routes the `CONTRACT_PHASE_*`
> and `SYNC_*` codes (richer than the diagram's named subsets) — *candidate detail.*
