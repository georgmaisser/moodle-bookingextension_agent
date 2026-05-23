# WBAgent Workflow Uebersicht

## Zweck

Diese Datei beschreibt den aktuellen Soll-Workflow auf Architektur-Ebene.

Sie ist als Blueprint-Dokument eingeordnet, weil sie Runtime-Verantwortungen und Flussgrenzen beschreibt.

## Einordnung

- Runtime-Zielbild: PREFLIGHT_FINAL_TARGET_FOR_REVIEW
- Umsetzungsreihenfolge: PREFLIGHT_IMPLEMENTATION_AGENT_RUNBOOK

## End-to-End Flow (Soll)

```mermaid
flowchart TD
    A[User message] --> B[ai_send_message]
    B --> C[authorization_service]
    C --> D[conversation_store add user message]
    D --> E[orchestrator process]
    E --> F[interpreter normalize output]
    F --> G[agent_decision_service]

    G --> H{response_type}
    H -->|clarification or error| I[store assistant message]
    H -->|readonly task_call| J[queue ready readonly]
    H -->|mutating intent| K[queue mutating preflight]

    K --> L[preflight_pipeline L1 L2 L3]
    L --> M{preflight status}
    M -->|pass autoconfirm| N[ready]
    M -->|pass no autoconfirm| O[blocked_confirmation]
    M -->|soft_block| O
    M -->|retry_hint| P[retry_waiting]
    M -->|hard_block| Q[failed]

    J --> R[scheduler lock]
    N --> R
    R -->|slot free| S[executor execute]
    R -->|slot busy| T[stay ready]
    T --> R

    S --> U[observation builder]
    U --> V{repair needed}
    V -->|yes| W[planner append step]
    V -->|no| X{goal reached}
    W --> E
    X -->|yes| Y[final synthesis]
    X -->|no| W
```

## Richtlinien

- Einziger mutating Preflight-Einstieg ist preflight_pipeline.
- Queue ist Single Source of Truth fuer mutating lifecycle.
- Keine Legacy validate-Fallbacks.
- Keine Shadow-Mode-Pfade.
