# Preflight Migration Status und Refactoring Plan (Stand 2026-05-24)

## Zweck

Dieses Dokument ersetzt den vorigen Planungsstand durch eine aktualisierte Soll-Ist-Bewertung und einen detaillierten Refactoring-Plan auf Basis des aktuellen Repository-Zustands.

Verwendete Referenzen:

- TASK_AUTHORING_AGENT_GUIDE.md
- CONTRACT_TASK_METADATA.md
- PREFLIGHT_FINAL_TARGET_FOR_REVIEW.md
- TASK_QUEUE_BLUEPRINT.mmd
- PREFLIGHT_FINAL_TARGET_FOR_REVIEW.mmd
- WBAGENT_RUNTIME_OVERVIEW.md
- WBAGENT_WORKFLOW_OVERVIEW.md

## Delta zum vorherigen Bericht

Folgende Aussagen aus aelteren Inventuren sind heute nicht mehr korrekt und wurden korrigiert:

1. Es existieren keine Task-validate-Methoden mehr in classes/local/wbagent/*/tasks.
2. Core-Tasks haben inzwischen flaechendeckend check_structure, aber weiterhin kein explizites preflight.
3. Wrappers unter booking/tasks fuer search_users/search_courses/recall_memory sind entfernt; diese Tasks liegen nur noch unter core/tasks.

## Aktueller Ist-Stand (faktisch)

### 1) Task-Lifecycle-Abdeckung

Gemessene Kennzahlen (Stand nach Phase 1, 2026-05-24):

- booking_tasks_total = 17
- core_tasks_total = 38
- booking_tasks_with_check = 15 (create_selflearning_option_task und create_slotbooking_option_task erben check_structure von create_option_task)
- booking_tasks_with_preflight = 17 (alle Booking-Tasks abgedeckt)
- core_tasks_with_check = 38
- core_tasks_with_explicit_preflight = 0 (preflight ist in core_task_base Zeile 149 implementiert; alle 37 konkreten Tasks erben es via Vererbung)
- core_task_base_has_preflight = 1 (Phase-1-Implementierung ueber Basisklasse, nicht per Einzeldatei)
- task_validate_methods = 0

Bewertung gegen Blueprint:

- Positiv: validate-only Taskpfade sind beseitigt.
- Positiv: booking_tasks_with_preflight = 17, alle Booking-Tasks vollstaendig abgedeckt.
- Positiv: Core-Tasks sind ueber core_task_base mit explizitem preflight versorgt; 38 identische Boilerplate-Ueberschreibungen wurden bewusst vermieden (DRY, minimal-invasiv).
- Noch offen: check_structure in Core-Tasks koennte je Task auf konkrete Pflichtfelder geschaerft werden (aktuell triviale Weiterleitung).

### 2) Preflight-Pipeline und Layer

Status:

- Zentraler Entrypoint preflight_pipeline ist aktiv und wird in der Routing-Entscheidung genutzt.
- Layer 1 (schema/version) ist vorhanden.
- Layer 2 (domain) klassifiziert aktuell primaer issue-codes, statt einheitliche fachliche Checks zu kapseln.
- Layer 3 (execution gate) ist vorhanden und mappt retry/backoff.

Bewertung:

- Solider Fortschritt in Richtung Single-Path.
- Noch nicht vollstaendig beim Zielbild eines klar separierten Domain-Gates.

### 3) Queue als Autoritaet

Status:

- Queue-Statusabbildung auf ready, blocked_confirmation und retry_waiting ist implementiert.
- Gleichzeitig bestehen weiterhin pending_intent-Pfade in conversation_store, agent_runtime und agent_decision_service.

Bewertung:

- Queue ist stark ausgebaut, aber nicht alleinige Wahrheit fuer den Mutation-Lifecycle.
- Es besteht weiterhin ein Dual-Truth-Risiko (Queue plus pending_intent).

### 4) Task-Provider-Contract

Status:

- task_contract_validator erzeugt und validiert Metadaten.
- Semantik im Code nutzt capabilities und active.
- Blueprint fordert capability und activation (bool|callable).

Bewertung:

- Funktional lauffaehig, aber semantische Drift gegen das Contract-Zielbild.
- Drittplugin-Onboarding bleibt dadurch uneinheitlich.

### 5) Wrapper- und Kompatibilitaetsflaechen

Status:

- Die bekannten booking-wrapper fuer search_users/search_courses/recall_memory sind entfernt.
- booking_task_provider als Kompatibilitaetsklasse bleibt ein moeglicher Restkandidat fuer weitere Vereinfachung.

Bewertung:

- Positiver Abbau von Redundanz.
- Restliche Kompatibilitaetspfad-Pruefung bleibt sinnvoll.

## Gap-Matrix (Soll gegen Ist)

1. Soll: readonly/core mit explizitem preflight pro Task
   Ist: core_task_base implementiert explizites preflight (Zeile 149); alle 37 konkreten Core-Tasks erben es. Per-File-Metrik core_tasks_with_explicit_preflight = 0, aber Vertragsdeckung ist vollstaendig. booking_tasks_with_preflight = 17 (alle abgedeckt).
   Prioritaet: Erledigt (Phase 1 abgeschlossen 2026-05-24)

2. Soll: Queue als Single Source of Truth fuer Mutation-Lifecycle
   Ist: pending_intent noch aktiv parallel zur Queue
   Prioritaet: Hoch

3. Soll: Provider-Contract capability/activation wie Blueprint
   Ist: active/capabilities im Validator und Registry-Pfaden
   Prioritaet: Hoch

4. Soll: Domain-Gate als eigenstaendige fachliche Schicht
   Ist: aktuell vor allem issue-code-Klassifizierung
   Prioritaet: Mittel

5. Soll: minimale Legacy-/Kompatibilitaetsflaeche
   Ist: einige Restpfade und grosse Entscheidungsklassen
   Prioritaet: Mittel

## Aktualisierter, detaillierter Refactoring-Plan

### Phase 0 - Baseline einfrieren (1 Tag)

Ziel:

- Reproduzierbare Ausgangsbasis fuer alle Folgephasen.

Arbeitspakete:

1. Aktuelle Contract- und Lifecycle-Metriken als Artefakt dokumentieren.
2. Relevante Contract-/Resilience-Tests als Pflicht-Gate markieren.
3. Migrationsentscheidungen (Breaking vs Non-Breaking) schriftlich fixieren.

DoD:

- Baseline-Metriken sind dokumentiert.
- Test-Gate fuer jede folgende Phase ist klar benannt.

### Phase 1 - Core/Readonly auf explizites preflight heben (2-4 Tage)

Ziel:

- Einheitlicher Task-Vertrag in allen Core-Tasks.

Arbeitspakete:

1. Fuer alle Dateien unter core/tasks explizites preflight implementieren (trivial erlaubt, aber explizit).
2. check_structure in Core-Tasks auf klare Pflichtfelder/Typen je Task schaerfen.
3. Sicherstellen, dass execute nur mit vorbereiteten Inputs arbeitet.
4. Vertrags-Tests fuer Core-Tasks erweitern (check_structure/preflight-Praesenz).

Betroffene Bereiche:

- classes/local/wbagent/core/tasks/*.php
- tests/agent/permanent/tasks/*
- tests/agent/permanent/contracts/*

DoD:

- core_task_base implementiert explizites preflight; alle konkreten Core-Tasks sind ueber Vererbung abgedeckt. Direkte Methodenimplementierung in 38 Einzeldateien wurde bewusst nicht gewaehlt (kein Boilerplate, DRY, minimal-invasiv).
- booking_tasks_with_preflight = 17 (alle Booking-Tasks abgedeckt).
- Keine Regression: Tests gruen (90 Tests, 967 Assertions, OK, Stand 2026-05-24).
- Status: Abgeschlossen.

### Phase 2 - Queue als alleinige Mutation-Wahrheit (3-5 Tage)

Ziel:

- Mutation-Lifecycle nur noch ueber Queue-Status, kein paralleler pending_intent-Primarpfad.

Arbeitspakete:

1. pending_intent in conversation_store auf read-only Kompatibilitaet reduzieren oder entfernen.
2. Confirmation-Flow auf queue_item_ids als primaeren Bezug umstellen.
3. blocked_confirmation, retry_waiting und ready als alleinige Runtime-Zustaende fuer Mutationen erzwingen.
4. ai_confirm_run auf queue-zentrierte Bestaetigung vereinfachen.

Betroffene Bereiche:

- classes/local/wbagent/conversation_store.php
- classes/local/wbagent/agent_decision_service.php
- classes/local/wbagent/agent_runtime.php
- classes/local/wbagent/queue/queue_manager.php
- classes/external/ai_confirm_run.php

DoD:

- Keine entscheidungskritische Nutzung von set_pending_intent/get_pending_intent mehr im Mutationshauptpfad.
- End-to-end-Confirmation laeuft ausschliesslich ueber Queue-Zustaende.

### Phase 3 - Contract-Semantik harmonisieren (2-3 Tage)

Ziel:

- Blueprint-konforme Contract-Felder capability/activation ohne Semantikbruch.

Arbeitspakete:

1. task_contract_validator auf capability/activation umstellen.
2. Backward-Compatibility-Bruecke fuer bestehende Metadaten (active/capabilities) zeitlich befristet einbauen.
3. task_registry-Zugriffe auf neue Felder umstellen.
4. Contract-Tests fuer Pflichtfelder und Fehlermeldungen erweitern.

Betroffene Bereiche:

- classes/local/wbagent/task_contract_validator.php
- classes/local/wbagent/task_registry.php
- tests/task_contract_validator_test.php
- tests/agent/permanent/contracts/*

DoD:

- Contract akzeptiert blueprint-konforme Felder.
- Regressionstests fuer alte und neue Feldnamen vorhanden.

### Phase 4 - Domain-Gate vertiefen (2-4 Tage)

Ziel:

- Schicht 2 als fachlich eigenstaendige Gate-Schicht statt reinem Code-Mapping.

Arbeitspakete:

1. preflight_domain_check_runner um klar getrennte Pruefsektionen erweitern (permission, context, conflict, precondition).
2. Issue-Code-Taxonomie pro Sektion vereinheitlichen.
3. Timeout/Retry-Hints aus realen Domain-Checks ableiten, nicht nur global klassifizieren.

Betroffene Bereiche:

- classes/local/wbagent/services/preflight_domain_check_runner.php
- classes/local/wbagent/services/preflight_pipeline.php
- task-spezifische preflight-Aufrufer

DoD:

- Domain-Gate-Entscheidung ist anhand klarer Sektionen auditierbar.
- Tests decken mindestens je einen hard_block, soft_block und retry_hint aus Domain-Checks ab.

### Phase 5 - Orchestrierungsvereinfachung und Restredundanz abbauen (3-5 Tage)

Ziel:

- Komplexitaet in grossen Runtime-Klassen reduzieren, Verhalten unveraendert halten.

Arbeitspakete:

1. agent_decision_service in klar getrennte Teilservices fuer ingest, preflight-decision, confirmation-routing aufteilen.
2. agent_runtime und loop_finalizer-Verantwortung entschaerfen (keine Doppelpfade fuer Abschluss/Synthese).
3. booking_task_support Redundanz bei _for_execute-Varianten abbauen, ohne Task-API zu brechen.

Betroffene Bereiche:

- classes/local/wbagent/agent_decision_service.php
- classes/local/wbagent/agent_runtime.php
- classes/local/wbagent/loop_finalizer.php
- classes/local/wbagent/booking/booking_task_support.php

DoD:

- Verantwortungen pro Klasse sind klar dokumentiert.
- Keine funktionale Regression in relevanten End-to-end-Agent-Tests.

### Phase 6 - Abschluss und Governance-Gates (1-2 Tage)

Ziel:

- Refactoring stabil abschliessen und dauerhaft absichern.

Arbeitspakete:

1. Abschliessende Soll-Ist-Matrix aktualisieren.
2. Contract-/Lifecycle-/Queue-Tests als verbindliche CI-Gates markieren.
3. Blueprint-Dokumente auf finalen Zustand synchronisieren.

DoD:

- Alle Gap-Punkte aus der Matrix sind geschlossen oder mit bewusstem Residualrisiko dokumentiert.
- CI scheitert bei Rueckfall in validate-only, fehlendem preflight oder Contract-Drift.

## Konkrete Priorisierung

1. Phase 1 und Phase 2 parallel vorbereiten, aber Phase 2 erst mergen, wenn Phase 1-Tests gruen sind.
2. Phase 3 direkt danach, weil sie Drittplugin-Vertraege stabilisiert.
3. Phase 4 anschliessend, um fachliche Gate-Qualitaet zu heben.
4. Phase 5 zuletzt als kontrolliertes Struktur-Refactoring.

## Test- und Review-Checkliste pro PR

1. Contract-Tests gruen.
2. Task-Lifecycle-Tests gruen.
3. Queue-Resilience-Tests gruen.
4. Keine neue parallele Wahrheit neben Queue fuer mutating flows.
5. Keine neue text-/tokenbasierte Steuerlogik fuer Runtime-Entscheidungen.

## Offene Risiken

1. Contract-Feldmigration kann externe Provider brechen, falls kein Uebergangsfenster definiert wird.
2. Queue-only-Umstellung kann Confirm-UX veraendern, wenn API-Payloads nicht synchron angepasst werden.
3. Core-preflight-Nachruestung kann kurzfristig viele kleine Testanpassungen ausloesen.

## Kurzfazit

Der aktuelle Zustand ist deutlich weiter als fruehere Berichte: validate-only ist entfernt, zentrale Preflight-Bausteine sind produktiv im Fluss, und Wrapper-Abbau ist bereits erfolgt. Die verbleibenden Kernarbeiten sind jetzt klar fokussiert: explizites preflight in Core-Tasks, Queue-only-Mutationswahrheit und Contract-Semantik-Harmonisierung.
