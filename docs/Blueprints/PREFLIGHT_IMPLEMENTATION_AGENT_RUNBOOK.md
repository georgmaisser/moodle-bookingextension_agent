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

Definition of Done:

- task_version_policy ist vorhanden und liefert pro task deterministisch min_supported_version sowie optional deprecation-Hinweise.
- preflight_version_validator erzeugt bei nicht unterstuetzter Version hard_blocking issues mit TASK_VERSION_UNSUPPORTED.
- task_contract_validator bewertet fehlende oder ungueltige Versionen konsistent als Contract-Verletzung.

Akzeptanzkriterien:

- Bei task.version < min_supported_version wird das Kommando in Layer 1 geblockt.
- Bei task.version im Deprecated-Bereich wird mindestens ein nicht-blockierender Hinweis (TASK_VERSION_DEPRECATED) erzeugt.
- Die issue_codes sind ueber alle betroffenen Validatoren identisch geschrieben und dokumentiert.

### Schritt 1.2: Layer-1 Validator erweitern

Dateien:

- classes/local/wbagent/services/preflight_schema_validator.php
- classes/local/wbagent/services/preflight_result_v2.php

Ziele:

- task version check in Layer 1 integrieren
- blocking_layer sauber numerisch oder streng standardisiert halten

Definition of Done:

- preflight_schema_validator ruft die Versionspruefung verpflichtend auf.
- preflight_result_v2 enthaelt eine eindeutig auswertbare blocking_layer Information fuer Layer-1-Blocks.
- Es gibt keine Codepfade, in denen Version-Fehler erst in Layer 2 oder Layer 3 erkannt werden.

Akzeptanzkriterien:

- Ein Command mit ungueltiger Version scheitert vor Domain-Checks.
- Ein Command mit gueltiger Version durchlaeuft Layer 1 ohne falschen Block.
- Telemetrie/Audit-Eintrag zeigt bei Block klar den Layer-1-Ursprung.

## Phase 2: Einziger Preflight-Pfad in der Runtime

### Schritt 2.1: preflight_pipeline bauen

Datei:

- neu: classes/local/wbagent/services/preflight_pipeline.php

Ziele:

- ein einziger Aufrufpunkt
- L1 -> L2 -> L3 deterministisch
- Rueckgabe immer preflight_result_v2 plus prepared_input

Definition of Done:

- preflight_pipeline existiert als zentrale Orchestrierungsklasse fuer mutating preflight.
- Die Reihenfolge L1 -> L2 -> L3 ist im Code explizit und nicht konfigurationsabhaengig.
- Rueckgabeobjekt ist fuer alle mutating tasks einheitlich (preflight_result_v2, prepared_input).

Akzeptanzkriterien:

- Jede mutating task wird ueber denselben Pipeline-Entry verarbeitet.
- Bei Layer-1-Block findet keine Ausfuehrung von Layer 2 oder Layer 3 statt.
- Bei success sind prepared_input Daten fuer execute ohne erneute Strukturableitung nutzbar.

### Schritt 2.2: agent_decision_service umhaengen

Datei:

- classes/local/wbagent/agent_decision_service.php

Ziele:

- handle_preflight nutzt nur preflight_pipeline
- confirm-pfade nutzen denselben Pipeline-Aufruf
- alte preflight helper komplett entfernen

Definition of Done:

- agent_decision_service delegiert Preflight fuer mutating commands ausschliesslich an preflight_pipeline.
- Confirm- und Initialpfad verwenden denselben technischen Einstieg.
- Alle Legacy-Helferfunktionen aus dem Preflight-Kontext sind entfernt.

Akzeptanzkriterien:

- Es gibt keinen verbleibenden Runtime-Aufruf auf run_preflight_on_commands oder run_preflight_pipeline_on_commands.
- Confirm-Flow und Initial-Flow erzeugen fuer denselben Input identische Preflight-Entscheidungen.
- Code-Suche nach infer_error_class_from_issue_codes und log_preflight_v2_shadow_comparison liefert keine aktiven Runtime-Treffer.

## Phase 3: Queue als Autoritaet

### Schritt 3.1: confirmation an queue items koppeln

Dateien:

- classes/external/ai_confirm_run.php
- classes/local/wbagent/queue/queue_manager.php

Ziele:

- bestaetigung nicht mehr ueber rohe pending command payloads
- queue_item_id als zentrale Referenz

Definition of Done:

- ai_confirm_run arbeitet mit queue_item_id als primaerem Identifikator.
- queue_manager stellt die noetigen Lookup- und Zustandsuebergaenge ueber Queue-Identitaet bereit.
- Bestaetigungen ohne gueltige queue_item_id werden konsistent abgewiesen.

Akzeptanzkriterien:

- Ein Confirm-Request ohne queue_item_id kann keinen mutating Lauf starten.
- Ein Confirm-Request mit fremder oder unzulaessiger queue_item_id wird mit klarer Fehlerklasse abgelehnt.
- Audit-Log und Queue-Historie referenzieren dieselbe queue_item_id.

### Schritt 3.2: retry_waiting lifecycle sauber machen

Dateien:

- classes/local/wbagent/queue/queue_manager.php
- classes/local/wbagent/agent_runtime.php

Ziele:

- pickup nur ueber can_pickup_now
- backoff und retry_count nur aus Queue-Feldern

Definition of Done:

- agent_runtime nutzt fuer Wiederaufnahme ausschliesslich can_pickup_now.
- retry_count, next_retry_at und backoff werden nur ueber Queue-Daten gesteuert.
- Es gibt keine versteckten In-Memory-Retry-Regeln ausserhalb der Queue.

Akzeptanzkriterien:

- Ein Item im Status retry_waiting wird vor Erreichen von next_retry_at nicht ausgefuehrt.
- Nach Erreichen von next_retry_at ist Pickup deterministisch moeglich.
- retry_count steigt bei wiederholtem Fehler reproduzierbar und entspricht den Queue-Feldern.

## Phase 4: Task-Flaeche vereinheitlichen

### Schritt 4.1: Legacy validate aus Task-Vertrag entfernen

Dateien:

- classes/local/wbagent/interfaces/task_interface.php
- classes/local/wbagent/base_task.php

Ziele:

- kein validate fallback mehr
- Pflicht: check_structure plus preflight fuer mutating tasks

Definition of Done:

- task_interface enthaelt keinen legacy validate Vertrag mehr.
- base_task enthaelt keinen validate Fallback-Pfad mehr.
- Mutating Tasks folgen verpflichtend check_structure + preflight.

Akzeptanzkriterien:

- Code-Suche auf base_task::validate und validate-Override-Treffer in Task-Klassen liefert keine aktiven Runtime-Verwendungen.
- Ein mutating task ohne preflight-Unterstuetzung faellt in Tests sofort als Vertragsverletzung auf.
- Readonly Tasks bleiben ohne mutating-preflight korrekt funktionsfaehig.

### Schritt 4.2: Booking-Tasks auf neuen Vertrag heben

Dateien:

- classes/local/wbagent/booking/tasks/*.php

Ziele:

- validate-only Tasks aktiv auf check_structure plus preflight umstellen
- execute immer mit prepared_input

Definition of Done:

- Alle booking/tasks nutzen check_structure und mutating preflight dort, wo mutierende Operationen stattfinden.
- execute verarbeitet keine rohen LLM-Inputs mehr, sondern prepared_input.
- Inkonsistente Feldableitungen zwischen preflight und execute sind entfernt.

Akzeptanzkriterien:

- Fuer repraesentative Booking-Mutationen laufen Preflight und Execute mit demselben prepared_input stabil durch.
- Fehlende Pflichtfelder werden vor execute abgefangen.
- Bestehende Booking-Task-Tests sind gruen und decken mindestens einen negativen Preflight-Fall pro mutating Task-Kategorie ab.

### Schritt 4.3: Core-Tasks standardisieren

Dateien:

- classes/local/wbagent/core/tasks/*.php

Ziele:

- explizite check_structure Implementierung
- explizites readonly preflight Verhalten

Definition of Done:

- Core-Tasks implementieren check_structure explizit und einheitlich.
- Readonly-Verhalten ist pro Task klar markiert und wird nicht ueber implizite Defaults abgeleitet.
- Task-Metadaten sind fuer Registry/Diagnostik konsistent auswertbar.

Akzeptanzkriterien:

- Readonly Core-Tasks passieren Preflight ohne mutating-spezifische Blocking-Checks.
- Mutating Core-Tasks werden identisch zur Booking-Task-Logik ueber die Pipeline geprueft.
- Registry-Ausgabe zeigt kein uneinheitliches Verhalten bei gleicher Task-Art.

## Phase 5: Konfigurations- und Dokumentbereinigung

### Schritt 5.1: veraltete Feature-Flags entfernen

Dateien:

- settings.php
- lang/en/bookingextension_agent.php

Entfernen:

- preflight_v2_enabled
- preflight_v2_shadow_mode

Definition of Done:

- Beide Feature-Flags sind aus settings.php und Sprachdateien entfernt.
- Runtime-Code enthaelt keine Verzweigungen mehr, die auf diese Flags reagieren.
- Default-Verhalten ist die direkte V2-Runtime ohne Umschalter.

Akzeptanzkriterien:

- Code-Suche nach preflight_v2_enabled und preflight_v2_shadow_mode ergibt keine aktiven Konfigurations- oder Runtime-Treffer.
- Admin-Settings zeigen keine Schalter mehr fuer V2/Shadow.
- Preflight-Verhalten bleibt in Tests unveraendert reproduzierbar ohne Flag-Abhaengigkeit.

### Schritt 5.2: Dokumente angleichen

Dateien:

- docs/Blueprints/PREFLIGHT_FINAL_TARGET_FOR_REVIEW.md
- docs/Blueprints/PREFLIGHT_IMPLEMENTATION_BEST_PRACTICE_PLAN.md
- docs/Blueprints/flowcharts/*.mmd

Ziele:

- nur noch ein Runtime-Zielpfad beschrieben

Definition of Done:

- Blueprint- und Flowchart-Dokumente beschreiben ausschliesslich den finalen Single-Path-Preflight.
- Verweise auf Legacy-Shim oder Shadow-Betrieb sind entfernt.
- Benannte Komponenten und Klassen stimmen mit der implementierten Struktur ueberein.

Akzeptanzkriterien:

- Alle genannten Ziel-Dokumente enthalten keine widerspruechlichen Architekturbeschreibungen.
- Diagramme und Text benennen denselben Entry-Point fuer mutating preflight.
- Ein technischer Reviewer kann die Laufzeitentscheidung nur aus den Dokumenten korrekt rekonstruieren.

## Test- und Abnahmereihenfolge

1. agent_architecture_contract_test
2. task_validation_matrix_test
3. booking_task_mutation_execute_service_test
4. ai_send_message_simulated_llm_test
5. confirmation_flow_real_llm_test
6. slotbooking_autoconfirm_real_llm_test
7. bookingextension_agent_testsuite komplett

Abnahmebedingung fuer jede Stufe:

- Die naechste Teststufe wird erst gestartet, wenn die vorherige vollstaendig gruen ist.
- Bei Regression wird die Phase als nicht bestanden markiert und nicht weiter eskaliert.
- Testprotokoll pro Phase muss die geprueften Kriterien aus diesem Runbook referenzieren.

## Harte Loesch-Checkliste vor Merge

Vor Merge muss geprueft und dokumentiert sein, dass folgende Elemente wirklich geloescht wurden:

- base_task::validate
- ungenutzte validate Overrides in Tasks
- agent_decision_service Legacy-Preflight-Helfer
- preflight_v2_shadow_mode code paths
- preflight_v2_enabled code paths
- task_preflight_result als Runtime-Transportmodell

Wenn einer dieser Punkte technisch noch verbleibt, ist der Merge zu blockieren.

Abschluss-DoD fuer den Merge:

- Loesch-Checkliste vollstaendig erfuellt und gegen Code-Suche verifiziert.
- Architekturelle Akzeptanzkriterien aus den Phasen 1 bis 5 sind als erledigt dokumentiert.
- bookingextension_agent_testsuite ist gruen und es bestehen keine offenen hard_blocker im Audit.

## Hinweise fuer den Agentenlauf

- Kleine, sequenzielle PRs entlang der Phasen.
- Nach jeder Phase Tests ausfuehren.
- Keine Rueckwaertskompatibilitaetslayer nachbauen.
- Wenn veralteter Code nicht mehr gebraucht wird: loeschen, nicht umbenennen.

## Aktuelle Restarbeiten nach Ist-Soll-Audit (priorisiert)

Diese Reihenfolge ist verbindlich fuer die verbleibenden Luecken und so geschnitten,
dass sie mit minimal-invasiven Patches umgesetzt werden kann.

### R1 (kritisch): Legacy validate Vertrag endgueltig entfernen

Betroffene Dateien:

- classes/local/wbagent/interfaces/agent_task_provider.php
- classes/local/wbagent/booking/tasks/*.php
- classes/local/wbagent/core/tasks/*.php
- classes/local/wbagent/booking/booking_task_support.php

Patch-Strategie:

- Interface-Methode validate(...) aus agent_task_provider entfernen.
- Runtime-Aufrufe auf provider->validate(...) auf check_structure + preflight umstellen.
- Task-spezifische validate(...) Methoden loeschen, sobald keine Runtime-Referenz mehr existiert.

Abnahme-Check:

- Code-Suche nach "function validate(array $input, int $cmid): array" in Task-Klassen liefert 0 aktive Runtime-Treffer.
- Code-Suche nach "->validate(" im wbagent-Runtime-Pfad liefert 0 Treffer.

### R2 (kritisch): task_preflight_result als Runtime-Modell entfernen

Betroffene Dateien:

- classes/local/wbagent/interfaces/task_interface.php
- classes/local/wbagent/base_task.php
- classes/local/wbagent/task_preflight_result.php
- classes/local/wbagent/booking/tasks/*.php
- classes/local/wbagent/core/tasks/*.php

Patch-Strategie:

- task_interface::preflight(...) Rueckgabetyp auf preflight_result_v2 umstellen.
- base_task::preflight(...) entsprechend anpassen.
- Task-Implementierungen in booking/core auf preflight_result_v2 migrieren.
- task_preflight_result.php erst loeschen, wenn keine Referenz mehr besteht.

Abnahme-Check:

- Code-Suche nach "task_preflight_result" im classes-Baum liefert 0 Treffer.
- preflight_pipeline und Executor arbeiten nur noch mit preflight_result_v2.

### R3 (hoch): Decision-Service Wrapper-Preflight aufloesen

Betroffene Datei:

- classes/local/wbagent/agent_decision_service.php

Patch-Strategie:

- run_preflight_pipeline_on_commands(...) entfernen.
- Alle Aufrufe direkt auf $this->preflightpipeline->run(...) routen.

Abnahme-Check:

- Code-Suche nach "run_preflight_pipeline_on_commands(" liefert 0 Treffer.
- handle_confirm_pending und handle_preflight nutzen denselben direkten Pipeline-Entry.

### R4 (hoch): Confirm strikt queue_item_id-zentriert machen

Betroffene Dateien:

- classes/external/ai_confirm_run.php
- classes/local/wbagent/queue/queue_manager.php

Patch-Strategie:

- execute_parameters() auf queue_item_id statt commands umstellen.
- execute() startet nur noch fuer explizit angeforderte queue_item_id.
- Fallback auf pendingintent['commands'] entfernen.

Abnahme-Check:

- Confirm ohne queue_item_id wird abgewiesen.
- Confirm mit fremder/unzulaessiger queue_item_id wird abgewiesen.
- Runtime-Pfad nutzt keine rohe Commands-Payload fuer Confirm.

### R5 (mittel): retry_waiting Lifecycle in agent_runtime konsolidieren

Betroffene Dateien:

- classes/local/wbagent/agent_runtime.php
- classes/local/wbagent/queue/queue_manager.php

Patch-Strategie:

- Pickup in agent_runtime ausschliesslich ueber queue_manager::can_pickup_now(...).
- retry_count/next_retry_at nur aus Queue-Metadaten lesen und fortschreiben.

Abnahme-Check:

- In agent_runtime sind can_pickup_now, retry_waiting, next_retry_at, retry_count aktiv verdrahtet.
- Kein paralleler In-Memory-Retry-Mechanismus verbleibt.

### R6 (mittel): Dokumente auf Single-Path finalisieren

Betroffene Dateien:

- docs/Blueprints/PREFLIGHT_IMPLEMENTATION_BEST_PRACTICE_PLAN.md
- docs/Blueprints/PREFLIGHT_FINAL_TARGET_FOR_REVIEW.md
- docs/Blueprints/flowcharts/*.mmd

Patch-Strategie:

- Legacy-/Dual-Path-Beschreibungen entfernen.
- Nur finalen Runtime-Single-Path dokumentieren.

Abnahme-Check:

- Keine Legacy-Referenzen auf run_preflight_on_commands, run_preflight_pipeline_on_commands, preflight_v2_shadow_mode, preflight_v2_enabled.

## Umsetzungsreihenfolge fuer kleine PRs

1. PR-A: R3 + R6 (klein, risikoarm, sofort validierbar)
2. PR-B: R4 + gezielte Confirm-Tests
3. PR-C: R5 + Queue-Retry-Tests
4. PR-D: R1 + R2 zusammen, inkl. grossflachiger Task-Migration

## Test-Gates je PR

- PR-A: agent_architecture_contract_test, task_validation_matrix_test
- PR-B: ai_send_message_simulated_llm_test, confirmation_flow_real_llm_test
- PR-C: booking_task_mutation_execute_service_test + retry-spezifische Queue-Tests
- PR-D: bookingextension_agent_testsuite komplett
