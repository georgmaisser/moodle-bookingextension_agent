# 09 · Preflight pipeline (v2)

> **Scope.** The layered validation/preparation that runs before a mutation can be queued or
> executed. Flowchart subgraph: `PREFLIGHT`.

Preflight answers one question: *can this command run safely right now, and what is the
exact prepared input if so?* It **never executes** — it returns a single
`preflight_result_v2` DTO. How deep it goes is determined by the command's
[risk class](15-risk-classes.md).

**Files:** `services/preflight_pipeline.php`, `preflight_schema_validator.php`,
`preflight_version_validator.php`, `preflight_domain_check_runner.php`,
`preflight_execution_gate.php`, `preflight_error_classifier.php`,
`preflight_contract_validator.php`, `preflight_result_v2.php`, `preflight_audit_logger.php`,
`retry_policy_service.php`, `interfaces/external_dependency_checker_interface.php`,
`services/noop_external_dependency_checker.php`.

---

## 1. Risk class → active layers

`preflight_pipeline::run(commands, threadid, contextid, userid)` resolves the batch's
highest risk class and activates layers accordingly:

| Risk | L1 schema+version | L2 domain prepare | L3 execution gate | L3-EXT external dep. |
|------|:--:|:--:|:--:|:--:|
| **R0** | — | — | — | — |
| **R1** | ✓ | ✓ | — | — |
| **R2** | ✓ | ✓ | ✓ | — |
| **R3** | ✓ | ✓ | ✓ | ✓ |

L3 additionally only fires when the error class is *retryable*; the external dependency
check fires only for R3 commands.

---

## 2. Layer 1 — schema + version

`preflight_schema_validator::validate()` checks the command against `command_schema.json`,
and `preflight_version_validator` checks the skill version (`skill_version_policy`). A
failure is a **hard block** with `SCHEMA_ERROR` (malformed/missing field) or
`SCHEMA_UNAVAILABLE` (schema not loadable); `blockinglayer = schema`. A missing skill
registration surfaces as `SKILL_NOT_REGISTERED`.

---

## 3. Layer 2 — domain prepare

This is where the skill itself participates: `skill::preflight(input, contextid, userid)`
does the DB-dependent preparation and validation (resolve the option, check the user may
write it, …) and returns a `preflight_result_v2`. `preflight_domain_check_runner::run()`
then classifies the issue codes:

- **hard block**: `PERMISSION_ERROR`, `VALIDATION_ERROR`, `SCHEMA_ERROR`, any `MISSING_*`;
- **soft block** (→ confirmation needed): `DOMAIN_CONFLICT` (the engine-generic confirmable
  code) plus the **provider-supplied** confirmable codes from
  `issue_code_provider_interface::get_prevalidation_confirmable_issue_codes()` — for the
  booking provider, `DUPLICATE_TITLE_CONFIRM_REQUIRED` /
  `DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED`. The runner holds no booking-specific codes of its
  own (it injects the provider, defaulting to `booking_issue_code_provider`, mirroring
  `agent_decision_service`).

Late-bound dependency outputs are allowed here, so a command that depends on a prior step's
artifact can still prepare. The prepared input it returns is what the executor will run
verbatim — the skill must not redo this work in `execute()`.

---

## 4. Layer 3 — execution gate hint

`preflight_execution_gate::evaluate(error_class, retry_count, issue_codes)` decides whether
a *technical* failure should be retried, using verified constants:

```php
BASE_MS = 500   JITTER_MS = 200   MAX_RETRIES = 4   MAX_BACKOFF_MS = 4000
backoff = min(BASE_MS * 2^retrycount + rand(0, JITTER_MS), MAX_BACKOFF_MS)
```

It delegates categorization to `retry_policy_service`:

| Category | Retryable? |
|----------|-----------|
| `TECHNICAL` (timeout, transient IO, parse, execution-guard) | ✓ |
| `EXTERNAL_DEPENDENCY` (auth, quota, rate-limit, provider) | ✓ |
| `DOMAIN` (validation, conflict, permission) | ✗ |
| *undefined* | ✗ → hard_block |

A **provider circuit-breaker** overrides this: `auth`/`quota` signals hard-block
(`PROVIDER_CIRCUIT_OPEN_AUTH` / `_QUOTA`); only `timeout`/`transient` yield a `retry_hint`.

### Layer 3-EXT — external dependency check (R3 only)

For R3 commands the pipeline calls `external_dependency_checker_interface::check()` (the
shipped default is `noop_external_dependency_checker`). A provider implements this to verify
that a webhook is reachable or a payment provider is ready, hard-blocking when it is not.

---

## 5. The result DTO

`preflight_result_v2` is the single output, with readonly fields:

| Field | Values / meaning |
|-------|------------------|
| `status` | `pass` / `soft_block` / `hard_block` / `retry_hint` |
| `issuecodes` / `issues` | structured signals |
| `blockinglayer` | `schema` / `domain` / `execution_gate` / `''` |
| `retryafterms` / `retrycount` / `durationms` | retry + timing |
| `preparedinput` | the exact input the executor will run |

> **⚠ Flowchart note.** The `PRV2` node lists `execution_guard_token` as a field of
> `preflight_result_v2`. It is **not** on the DTO; the guard token is built from the
> prepared input (`preflight_execution_gate::build_guard_token(skill, contextid,
> prepared_input)`) and persisted on the **queue item**, then verified by the executor
> (see [ch. 11](11-executor.md)). *Candidate correction.*

---

## 6. Audit

When `preflight_audit_enabled` is set, `preflight_audit_logger::append()` writes a structured
event (skill, layer, status, reason, issue codes, retry/timing, error class) into thread
metadata under `_preflight_audit_log`. See
[operations/observability.md](../operations/observability.md).

---

## 7. Flowchart notes

> **✓ Confirmed:** risk→layer gating (R0 none / R1 L1+L2 / R2 +L3 / R3 +external);
> L3 constants 500/200/4/4000; retryable categories TECHNICAL + EXTERNAL_DEPENDENCY;
> circuit-breaker auth/quota → hard_block.

> **✓ Soft-block codes — leak inverted.** The diagram's generic `PROVIDER_CONFIRMABLE_*` is
> now literally accurate: `preflight_domain_check_runner` no longer hardcodes the
> booking-specific `DUPLICATE_TITLE_*` codes — it injects `issue_code_provider_interface` and
> reads `get_prevalidation_confirmable_issue_codes()`, keeping only the engine-generic
> `DOMAIN_CONFLICT` itself. This removed a domain leak into the engine (one of the "5 leaks").
> Behaviour-neutral; covered by `preflight_layers_contract_test`.
