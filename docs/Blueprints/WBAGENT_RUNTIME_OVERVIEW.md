# WBAgent Runtime Overview

## Zweck

Diese Datei dokumentiert die Runtime-Komponenten auf Systemniveau.

## Komponenten

```mermaid
flowchart LR
    UI[UI layer]

    subgraph API[External API]
        SEND[ai_send_message]
        CONFIRM[ai_confirm_run]
        POLL[ai_poll_thread]
    end

    subgraph CORE[wbagent core]
        AUTH[authorization_service]
        ORCH[orchestrator]
        INTP[interpreter]
        DEC[agent_decision_service]
        PIPE[preflight_pipeline]
        EXEC[executor]
        QUEUE[queue_manager]
        STORE[conversation_store]
    end

    subgraph TASKS[Task providers]
        REG[task_registry]
        BOOK[booking tasks]
        CORETASKS[core tasks]
    end

    UI --> SEND
    UI --> CONFIRM
    UI --> POLL

    SEND --> AUTH
    SEND --> ORCH
    ORCH --> INTP
    INTP --> DEC
    DEC --> PIPE
    DEC --> QUEUE
    DEC --> EXEC
    CONFIRM --> QUEUE
    CONFIRM --> EXEC

    REG --> BOOK
    REG --> CORETASKS
    DEC --> REG
    EXEC --> REG
    DEC --> STORE
    QUEUE --> STORE
```

## Verbindliche Architekturregeln

- Mutating Entscheidungen gehen ueber preflight_pipeline.
- Queue-Status steuert Ausfuehrbarkeit fuer mutating items.
- Task-Versionpruefung ist Layer-1-Pflicht.
- Planner/Synthesizer-Vertrag ist verbindlich:
    - Planner steuert nur Loop/Task-Entscheidungen (task_call, confirmation_request, clarification, sufficient).
    - User-facing Formulierung finaler Antworten erfolgt ueber `final_synthesis`.
    - Planner-Entscheidungen werden im Synthese-Schritt nicht stillschweigend ueberschrieben.
- `loop_finalizer` ist nur ein Safety-Net gegen unproduktive Loop-Wiederholungen.
    - Keine task-name-basierten Sonderregeln im Framework.
    - Keine plugin-spezifischen String-Matches zur Laufzeitsteuerung.
