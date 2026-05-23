# Preflight Implementierung Runbook fuer Agent-Ausfuehrung

## Zweck

Dieses Dokument ist ein echtes Umsetzungsdokument fuer einen Coding-Agenten.

Es ist bewusst direkt abarbeitbar und nutzt konkrete Klassen und Dateien im aktuellen Codebestand.

Wichtige Rahmenentscheidung:

- Keine Migration.
- Keine Rueckwaertskompatibilitaet fuer Legacy-Pfade.
- Veraltete Methoden und Parallelpfade werden aktiv entfernt.

## Harte Architekturentscheidungen

1. Es gibt genau einen Preflight-Pfad fuer mutating tasks.
2. Queue ist Single Source of Truth fuer mutating command lifecycle.
3. Legacy validate-basierte Parallelpfade werden geloescht.
4. V2-Shadow-Mechanik wird entfernt; V2 wird direkt die Runtime.
5. Task-Versionpruefung ist Pflicht in Layer 1.

## Ziel-DoD

Die Implementierung gilt erst als abgeschlossen, wenn:

- keine Runtime-Entscheidung mehr ueber legacy validate() laeuft,
- agent_decision_service nur noch den einen Preflight-Pfad nutzt,
- task version unsupported sauber hard_blocked und geloggt wird,
- queue statuses den echten Steuerpfad abbilden,
- obsolete Methoden und Feature-Flags entfernt sind,
- bookingextension_agent_testsuite gruen ist.

## Zielklassen und konkrete Anpassungen

### A. Bestehende Klassen, die zentral refactored werden

- classes/local/wbagent/agent_decision_service.php
- classes/local/wbagent/base_task.php
- classes/local/wbagent/task_preflight_result.php
- classes/local/wbagent/task_contract_validator.php
- classes/local/wbagent/task_registry.php
- classes/local/wbagent/services/preflight_schema_validator.php
- classes/local/wbagent/services/preflight_domain_check_runner.php
- classes/local/wbagent/services/preflight_execution_gate.php
- classes/local/wbagent/services/preflight_result_v2.php
- classes/local/wbagent/services/preflight_audit_logger.php
- classes/local/wbagent/queue/queue_manager.php
- classes/external/ai_send_message.php
- classes/external/ai_confirm_run.php
- settings.php
- lang/en/bookingextension_agent.php

### B. Neue Klassen fuer klare Verantwortung

- classes/local/wbagent/services/preflight_pipeline.php
  - einziger Entry fuer mutating preflight
  - orchestriert L1, L2, L3
- classes/local/wbagent/services/task_version_policy.php
  - liefert min_supported_version und deprecated Regeln pro task
- classes/local/wbagent/services/preflight_version_validator.php
  - evaluiert task.version gegen task_version_policy

## Veraltete Methoden und Pfade, die aktiv geloescht werden

Die folgenden Elemente sind in der Zielarchitektur nicht mehr erlaubt und werden entfernt:

### 1. Legacy-Validierungs-Shim

- base_task::validate(array $input, int $cmid)
- alle Aufrufe, die validate als fallback im Preflight verwenden

### 2. Doppelter Preflight-Pfad in agent_decision_service

- run_preflight_on_commands(...)
- run_preflight_pipeline_on_commands(...)
- evaluate_preflight_v2_result(...)
- infer_error_class_from_issue_codes(...)
- log_preflight_v2_shadow_comparison(...)

Diese Funktionen werden durch preflight_pipeline ersetzt.

### 3. V2-Shadow-Switching

- Konfigurationsflags fuer preflight_v2_enabled und preflight_v2_shadow_mode in settings.php und lang-Datei
- Shadow-Logging-Zweige in ai_send_message.php

### 4. Legacy-DTO-Doppelstruktur

- task_preflight_result als Runtime-Transportobjekt

Ziel: preflight_result_v2 wird das einzige Runtime-Result-Modell.

## Implementierungssequenz (verbindlich)

## Phase 1: Versionierungs- und Contract-Haertegrad in Layer 1

### Schritt 1.1: Task-Versionpolitik einfuehren

Dateien:

- neu: classes/local/wbagent/services/task_version_policy.php
- neu: classes/local/wbagent/services/preflight_version_validator.php
- anpassen: classes/local/wbagent/task_contract_validator.php

Ziele:

- version als Pflichtfeld konsistent erzwingen
- TASK_VERSION_UNSUPPORTED und TASK_VERSION_DEPRECATED issue codes standardisieren

### Schritt 1.2: Layer-1 Validator erweitern

Dateien:

- classes/local/wbagent/services/preflight_schema_validator.php
- classes/local/wbagent/services/preflight_result_v2.php

Ziele:

- task version check in Layer 1 integrieren
- blocking_layer sauber numerisch oder streng standardisiert halten

## Phase 2: Einziger Preflight-Pfad in der Runtime

### Schritt 2.1: preflight_pipeline bauen

Datei:

- neu: classes/local/wbagent/services/preflight_pipeline.php

Ziele:

- ein einziger Aufrufpunkt
- L1 -> L2 -> L3 deterministisch
- Rueckgabe immer preflight_result_v2 plus prepared_input

### Schritt 2.2: agent_decision_service umhaengen

Datei:

- classes/local/wbagent/agent_decision_service.php

Ziele:

- handle_preflight nutzt nur preflight_pipeline
- confirm-pfade nutzen denselben Pipeline-Aufruf
- alte preflight helper komplett entfernen

## Phase 3: Queue als Autoritaet

### Schritt 3.1: confirmation an queue items koppeln

Dateien:

- classes/external/ai_confirm_run.php
- classes/local/wbagent/queue/queue_manager.php

Ziele:

- bestaetigung nicht mehr ueber rohe pending command payloads
- queue_item_id als zentrale Referenz

### Schritt 3.2: retry_waiting lifecycle sauber machen

Dateien:

- classes/local/wbagent/queue/queue_manager.php
- classes/local/wbagent/agent_runtime.php

Ziele:

- pickup nur ueber can_pickup_now
- backoff und retry_count nur aus Queue-Feldern

## Phase 4: Task-Flaeche vereinheitlichen

### Schritt 4.1: Legacy validate aus Task-Vertrag entfernen

Dateien:

- classes/local/wbagent/interfaces/task_interface.php
- classes/local/wbagent/base_task.php

Ziele:

- kein validate fallback mehr
- Pflicht: check_structure plus preflight fuer mutating tasks

### Schritt 4.2: Booking-Tasks auf neuen Vertrag heben

Dateien:

- classes/local/wbagent/booking/tasks/*.php

Ziele:

- validate-only Tasks aktiv auf check_structure plus preflight umstellen
- execute immer mit prepared_input

### Schritt 4.3: Core-Tasks standardisieren

Dateien:

- classes/local/wbagent/core/tasks/*.php

Ziele:

- explizite check_structure Implementierung
- explizites readonly preflight Verhalten

## Phase 5: Konfigurations- und Dokumentbereinigung

### Schritt 5.1: veraltete Feature-Flags entfernen

Dateien:

- settings.php
- lang/en/bookingextension_agent.php

Entfernen:

- preflight_v2_enabled
- preflight_v2_shadow_mode

### Schritt 5.2: Dokumente angleichen

Dateien:

- docs/Blueprints/PREFLIGHT_FINAL_TARGET_FOR_REVIEW.md
- docs/Blueprints/PREFLIGHT_IMPLEMENTATION_BEST_PRACTICE_PLAN.md
- docs/Blueprints/flowcharts/*.mmd

Ziele:

- nur noch ein Runtime-Zielpfad beschrieben

## Test- und Abnahmereihenfolge

1. agent_architecture_contract_test
2. task_validation_matrix_test
3. booking_task_mutation_execute_service_test
4. ai_send_message_simulated_llm_test
5. confirmation_flow_real_llm_test
6. slotbooking_autoconfirm_real_llm_test
7. bookingextension_agent_testsuite komplett

## Harte Loesch-Checkliste vor Merge

Vor Merge muss geprueft und dokumentiert sein, dass folgende Elemente wirklich geloescht wurden:

- base_task::validate
- ungenutzte validate Overrides in Tasks
- agent_decision_service Legacy-Preflight-Helfer
- preflight_v2_shadow_mode code paths
- preflight_v2_enabled code paths
- task_preflight_result als Runtime-Transportmodell

Wenn einer dieser Punkte technisch noch verbleibt, ist der Merge zu blockieren.

## Hinweise fuer den Agentenlauf

- Kleine, sequenzielle PRs entlang der Phasen.
- Nach jeder Phase Tests ausfuehren.
- Keine Rueckwaertskompatibilitaetslayer nachbauen.
- Wenn veralteter Code nicht mehr gebraucht wird: loeschen, nicht umbenennen.
