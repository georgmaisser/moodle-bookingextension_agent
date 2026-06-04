# Retry Reliability Rollout Plan

## Feature Flag Strategy

- Keep runtime retry orchestration behind existing agent runtime flags.
- Introduce policy-compatible behavior without changing UI or endpoint contracts.
- Treat retry policy as additive-safe: if policy metadata is missing, transition to hard-block with issue code.

## Fallback for Unexpected Issue Codes

- Unknown retry categories are terminalized with `RETRY_HINT_CATEGORY_UNDEFINED`.
- Non-retryable categories are terminalized with `RETRY_CATEGORY_NOT_ALLOWED`.
- Provider auth/quota issues open circuit breaker (`PROVIDER_CIRCUIT_OPEN_AUTH`, `PROVIDER_CIRCUIT_OPEN_QUOTA`).

## Monitoring Plan

Track these metrics per deployment wave:

- Retry rate per layer (`planner`, `preflight`, `queue`, `execution`).
- Terminal reasons frequency (`retry_hint_category_undefined`, `retry_layer_limit_exceeded`, provider circuit-open).
- Distribution of `retry_hint_category` values.
- Loop exhaustion ratio (`LOOP_RETRY_EXHAUSTED*`, `BUDGET_EXCEEDED`).

## Rollback Criteria

Rollback/disable policy-tightening when one of these triggers is met:

- `RETRY_HINT_CATEGORY_UNDEFINED` spikes beyond baseline and blocks valid production intents.
- Regression in successful mutating flows after rollout.
- Provider circuit-open false positives materially increase failed runs.
- Execution queue stagnation increases due to retry-collision guards.
