# Preflight Blueprint

Status: aktiv, auf das finale Zielbild harmonisiert.

## Zweck

Dieses Dokument beschreibt die kanonische Preflight-Architektur fuer den bookingextension_agent.

Es gilt ohne Migrationspfad und ohne Legacy-Kompatibilitaetslayer.

## Schichtenmodell

### Layer 1: Contract and Schema Gate

Prueft synchron und I/O-frei:

- task registration
- task activation
- task version supported
- task contract Pflichtfelder
- command envelope und input schema
- depends_on syntax

Fehlerverhalten:

- hard_block mit issue_codes wie SCHEMA_ERROR oder TASK_VERSION_UNSUPPORTED

### Layer 2: Domain Gate

Prueft read-only:

- permission
- context validity
- preconditions
- conflict
- task-local preflight

Ergebnisstatus:

- pass
- soft_block
- hard_block
- retry_hint

### Layer 3: Execution Gate

Zentrale Queue-State-Entscheidung und Retry-Steuerung:

- retry_count aus queue item
- backoff_ms = base_ms * 2^retry_count + jitter
- max_retries Exhaution -> hard_block max_retries_exceeded

Queue-State-Mapping:

- pass + autoconfirm -> ready
- pass + no autoconfirm -> blocked_confirmation
- soft_block -> blocked_confirmation
- retry_hint -> retry_waiting
- hard_block -> failed

## PreflightResult Kontrakt

```typescript
type PreflightResult = {
  status: 'pass' | 'soft_block' | 'hard_block' | 'retry_hint';
  issue_codes: string[];
  blocking_layer: 1 | 2 | 3 | null;
  retry_after_ms: number | null;
  retry_count: number;
  duration_ms: number;
  prepared_input: Record<string, unknown> | null;
};
```

## Audit

Jeder Durchlauf schreibt immutable audit events, inklusive pass.

Pflichtfelder:

- thread_id
- run_id
- queue_item_id
- taskname
- task_version
- layer
- status
- issue_codes
- retry_count
- retry_after_ms
- duration_ms

## Referenzen

- PREFLIGHT_FINAL_TARGET_FOR_REVIEW
- PREFLIGHT_IMPLEMENTATION_AGENT_RUNBOOK
- flowcharts/PREFLIGHT_FINAL_TARGET_FOR_REVIEW.mmd
