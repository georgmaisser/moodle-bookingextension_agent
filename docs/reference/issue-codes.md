# Reference · Issue codes & error classes

> **Scope.** The catalog of `issue_codes` and `error_class` values the engine emits, what
> each means, and how it routes. `issue_codes` are structured routing signals on a result;
> `error_class` is the technical category of a provider/IO failure.

The [finalization classifier](../architecture/13-finalization-and-observations.md) reads
these to choose a reply strategy; the [preflight](../architecture/09-preflight-pipeline.md)
and [queue](../architecture/10-shadow-queue.md) layers read them to choose hold/retry/fail.

---

## 1. Finalization routing

These sets are authoritative for the reply strategy (precedence
**direct_final > template_only > llm_polish**).

### → direct_final (`DIRECT_ISSUE_CODES`)

| Code | Meaning |
|------|---------|
| `SCHEMA_ERROR` | command failed schema validation |
| `SCHEMA_UNAVAILABLE` | the command schema could not be loaded |
| `DEPENDENCY_CYCLE` | a queue dependency cycle |
| `CONTRACT_INVALID_RESPONSE_TYPE` | response type not in the allowed set |
| `CONTRACT_COMMANDS_REQUIRED` | a `skill_call`/`confirmation_request` had no commands |
| `CONTRACT_PHASE_RESPONSE_TYPE` / `CONTRACT_PHASE_COMMANDS_NOT_ALLOWED` / `CONTRACT_PHASE_SINGLE_COMMAND_REQUIRED` / `CONTRACT_PHASE_SKILL_NOT_ALLOWED` | a phase emitted output its contract forbids |

### → template_only (`TEMPLATE_ISSUE_CODES`)

| Code | Meaning |
|------|---------|
| `BUDGET_EXCEEDED` | the loop budget was exhausted |
| `BLOCKED_TIMEOUT` / `BLOCKED_CONFIRMATION_TIMEOUT` | a confirmation hold expired |
| `RETRY_EXHAUSTED` | retries used up |
| `PERMISSION_ERROR` | the user may not perform the action |
| `VALIDATION_ERROR` | required input missing/invalid |
| `CONTEXT_INVALID` | the request is invalid in this context |
| `CONTRACT_SELECTION_SKILL_MISSING` | selection produced no next skill |
| `SYNC_*` (see §4) | a synchronizer polish was rejected |

### → llm_polish

`response_type ∈ {sufficient, clarification}`, or `response_type = error` that is **not** a
structural failure and matches no direct/template rule.

---

## 2. Error classes (`error_class`)

Produced by `ai_error_classifier`; all five route to **template_only** finalization and feed
the [retry policy](../architecture/09-preflight-pipeline.md#4-layer-3--execution-gate-hint).

| Class | Meaning | Retryable? |
|-------|---------|-----------|
| `provider_timeout` | the provider timed out | yes (technical) |
| `transient_io` | a temporary connection problem | yes (technical) |
| `auth_failed` | provider authentication failed | no → circuit-breaker hard-block |
| `quota_exceeded` | provider quota/rate limit | no → circuit-breaker hard-block |
| `runtime_disabled` | AI runtime disabled | no |

---

## 3. Planner contract & loop-retry codes

| Code | Meaning | Effect |
|------|---------|--------|
| `CONTRACT_PARSE_ERROR` | construction JSON was invalid | **one** in-loop retry (RETRY_HINT) |
| `CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED` | selector emitted ≠ 1 command | **one** in-loop retry |
| `CONTRACT_SELECTION_SKILL_MISMATCH` | selected skill ≠ command skill | contract failure |
| `CONTRACT_UNKNOWN_RESPONSE_TYPE` | unrecognized response type | normalized |
| `RECOVERABLE_INPUT_ERROR` | construction input recoverable | preflight retry observation |
| `PLANNER_RETRY_DECISION` / `RETRY_DECISION_LAYER_PLANNER` / `RETRY_CATEGORY_TECHNICAL` | a planner retry was taken | tags the retried turn |
| `PLANNER_RETRY_BLOCKED_LAYER_COLLISION` | a non-planner retry was already active | planner retry suppressed |
| `LOOP_RETRY_EXHAUSTED` / `LOOP_RETRY_EXHAUSTED_<code>` | per-issue loop retry budget spent | finalize |

---

## 4. Retry & queue codes

| Code | Meaning |
|------|---------|
| `RETRY_WAITING` | the command is in a backoff window |
| `PREFLIGHT_RETRY_HINT` / `EXECUTION_RETRY_HINT` / `EXECUTION_EXCEPTION_RETRY_HINT` | a layer requested a retry |
| `RETRY_LAYER_LIMIT_EXCEEDED` / `RETRY_LAYER_COLLISION` | the 2-distinct-layers-per-error cap was hit |
| `R3_NO_RETRY` | an R3 command may not be retried |
| `QUEUE_SIGNATURE_REUSE` | an idempotent queue hit (reused existing item) |
| `EXECUTOR_ALREADY_EXECUTED` / `EXECUTOR_RUN_EXISTS` | the executor skipped an already-run idempotency key |
| `PROVIDER_CIRCUIT_OPEN_AUTH` / `PROVIDER_CIRCUIT_OPEN_QUOTA` | the provider circuit-breaker tripped |

---

## 5. Domain codes (provider-supplied)

Defined by the [issue-code provider](../architecture/16-support-services.md); soft-block codes
mean "confirmable".

| Code | Block type |
|------|-----------|
| `PERMISSION_ERROR`, `VALIDATION_ERROR`, `SCHEMA_ERROR`, `MISSING_*` | hard |
| `DOMAIN_CONFLICT` | soft (confirm to proceed) |
| `DUPLICATE_TITLE_CONFIRM_REQUIRED`, `DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED` | soft (the diagram's `PROVIDER_CONFIRMABLE_*`) |

A soft block is the typical path for the `override` input on a mutating skill.

---

## 6. Synchronizer rejection codes

When the synchronizer's polish is discarded and the planner output is used instead
([ch. 12](../architecture/12-synchronizer.md)):

`SYNC_RESPONSE_TYPE_ERROR_REJECTED`, `SYNC_CONTRACT_ISSUE_REJECTED`,
`SYNC_FACT_CONFLICT_REJECTED`, `SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED`,
`SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED`, `SYNC_COMMAND_PAYLOAD_REJECTED`,
`SYNC_RAW_EXCERPT_REJECTED`, `SYNC_EMPTY_MESSAGE`, `SYNC_AFFECTED_SCOPE_SUMMARY_MISSING`.

---

## 7. Entry-gate error strings

Returned by the [readiness gate](../architecture/01-entry-and-web-services.md#3-the-readiness-gate)
before the loop starts (these are lang strings, not issue codes):
`error_ai_subsystem_missing`, `error_ai_no_provider`, `error_ai_provider_inactive`,
`error_ai_course_disabled`, `error_ai_context_disabled`, `ai_provider_error`,
`permission_denied`.

---

_This catalog is compiled from the engine's emit sites; when in doubt, grep the literal code
to find where it is set._
