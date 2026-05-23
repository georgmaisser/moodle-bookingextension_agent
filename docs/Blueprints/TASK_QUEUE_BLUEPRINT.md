# Task Queue Blueprint

Status: aktiv, finalisiertes Zielbild ohne Migrationspfad.

## Ziel

Deterministische queue-getriebene Ausfuehrung fuer den agentic loop.

## Leitprinzipien

1. Queue ist Single Source of Truth fuer mutating lifecycle.
2. Planner ist deklarativ, Runtime ist deterministisch.
3. Readonly und mutating sind strikt getrennt.
4. Keine Legacy-Sonderpfade und keine Shadow-Runtime.

## Queue Item Mindestmodell

- queue_item_id
- thread_id
- run_id
- step_id
- task
- task_version
- input
- prepared_input
- input_signature
- mutability
- depends_on
- status
- retry_count
- next_retry_at
- preflight_retry_count
- retry_after_ms
- backoff_ms
- blocked_expires_at
- issue_codes
- error_class
- last_error_message
- created_at
- updated_at

## Statusmaschine

Zulaessige Status:

- queued
- ready
- running
- succeeded
- failed
- retry_waiting
- blocked_confirmation
- skipped
- waiting_for_dependency

Invarianten:

- max 1 running pro thread_id
- failed ist terminal
- skipped ist terminal
- blocked_confirmation bleibt bis confirm oder TTL-fail

## Scheduling Regeln

1. Queue ingest validiert task contract, task version und depends_on syntax.
2. Wenn depends_on nicht erfuellt: waiting_for_dependency.
3. Readonly ohne offene Dependencies geht nach ready.
4. Mutating laeuft immer durch Preflight L1-L3.
5. Scheduler lock vor ready -> running.

## Confirmation Regeln

- Standard: mutating mit pass oder soft_block in blocked_confirmation.
- Autoconfirm: pass direkt in ready.
- Confirmation referenziert queue_item_id, nicht rohe command payloads.

## Retry Regeln

- retry_hint -> retry_waiting mit persisted backoff.
- pickup erst wenn next_retry_at erreicht.
- max_retries_exceeded -> failed.

## Repair Loop

- execution result wird vom observation builder verdichtet.
- repair needed erzeugt append-only planner step.
- vor jedem planner re-entry muss budget guard greifen.

## Versionierung

- task version ist Pflicht im Queue item.
- unsupported task version blockt vor Domain Gate.
- deprecated task version wird strukturiert gemeldet.

## Freigabekriterien

- Kein Legacy validate-Fallback in Runtime-Pfaden.
- Kein preflight_v2 Shadow oder dualer Pfad.
- Queue steuert mutating lifecycle vollstaendig.
- Audit stream deckt alle Preflight-Entscheidungen ab.

## Referenzen

- PREFLIGHT_FINAL_TARGET_FOR_REVIEW
- PREFLIGHT_IMPLEMENTATION_AGENT_RUNBOOK
- flowcharts/TASK_QUEUE_BLUEPRINT.mmd
