# Service Consolidation Implementation Plan

Stand: 2026-05-29
Scope: `classes/local/wbagent` Service-Konsolidierung gemaess `SERVICE_CONSOLIDATION_PREP.md`
Modus: Planung only (keine Umsetzung in diesem Dokument)

## 1. Zielbild und Guardrails

Ziel:
- Radikale Reduktion von Service-/Utility-Duplikaten
- Klare Ownership-Grenzen zwischen Runtime, Decision, Queue, Preflight, Catalog
- Unveraendertes Agent-Verhalten nach aussen

Nicht verhandelbare Guardrails:
- deterministisches Routing bleibt erhalten
- preflight-first fuer Mutationen bleibt erhalten
- confirmation-gated Mutationen ueber Queue bleiben erhalten
- keine task-spezifischen Heuristiken im Framework
- Sprache bleibt ueber language policy determiniert

## 2. Deliverables

1) Neue Konsolidierungs-Bausteine
- `shared_json_payload_extractor`
- `provider_routing_util`
- `localized_string_service`
- `trigger_result_util`
- `queue_status_policy`
- `queue_command_mapper`
- `pending_intent_service`
- `queue_transition_service`
- `preflight_contract_validator`
- `catalog_selection_service`

2) Verdrahtung in bestehenden Klassen
- `agent_runtime`
- `agent_decision_service`
- `orchestrator`
- `interpreter`
- `execution_feedback_service`
- `services/confirm_run_service`
- `queue/queue_manager`
- `services/preflight_pipeline`

3) Shrink-Artefakte
- Entfernung redundanter Helper in den Grossdateien
- vereinheitlichte Methodensignaturen
- dokumentierte Invarianten je Konsolidierungsmodul

4) Test- und Abnahme-Artefakte
- Contract-Test-Suite fuer Decision/Confirm/Queue/Preflight
- Snapshot-Tests fuer JSON-Extractor
- Regressionsmatrix fuer Sprach- und Issue-Code-Vertraege

## 3. Lieferstrategie (inkrementell, risikoarm)

Prinzip:
- Pro Phase: zuerst additive Einfuehrung, dann interne Umstellung, erst danach Altcode entfernen.
- Keine Big-Bang-Migration.
- Jede Phase endet mit messbarem Gate.

Branching-Vorschlag:
- Hauptbranch: `refactor/service-consolidation`
- Optional pro Phase Unterbranch: `refactor/service-consolidation-pX`

## 4. Phasenplan

### Phase 0 - Baseline, Contracts, Freeze

Ziel:
- abgesicherte Ausgangslage vor Eingriffen

Arbeitspakete:
- Contract-Baseline fuer Response-Payloads aus `agent_decision_service` und `confirm_run_service` festschreiben
- aktuelle Queue-Status-Transitionen dokumentieren (Quelle, Ziel, Trigger)
- Sprach-Fallback-Reihenfolge aus `language_policy_service` als Referenz einfrieren
- Test-Baseline (gruen/rot/flaky) protokollieren

Gate:
- Baseline-Dokument vorhanden und von Team akzeptiert
- Regressionskriterien fuer `response_type`, `issue_codes`, `pending_confirmation_code`, `queue_item_id` explizit definiert

### Phase 1 - Shared Utilities ohne Verhaltensaenderung

Ziel:
- harte Duplikate extrahieren, Verhalten 1:1 beibehalten

Arbeitspakete:
- `shared_json_payload_extractor` implementieren (Quelle: `interpreter` + `execution_feedback_service`)
- `provider_routing_util` implementieren (Quelle: `orchestrator` + `execution_feedback_service`)
- `localized_string_service` implementieren (Quelle: runtime/decision/executor/feedback helper)
- `trigger_result_util` implementieren (Quelle: `agent_runtime` + `agent_decision_service`)
- erste Call-Sites intern umstellen, alte Helper vorerst belassen (deprecated state)

Gate:
- keine Aenderung in externen API-Contracts
- JSON-Extraction Snapshot-Tests identisch vor/nach Umstellung
- keine neue Entscheidungspfad-Abweichung im Loop-Verhalten

### Phase 2 - Queue Mapping und Status-Policy zentralisieren

Ziel:
- eine Wahrheit fuer Queue-Status und Queue->Command Mapping

Arbeitspakete:
- `queue_status_policy` als Single Source of Truth fuer actionable/pickup-faehige Status
- `queue_command_mapper::from_queue_item(s)` einfuehren
- `agent_decision_service` Mapping auf `queue_command_mapper` umstellen
- `services/confirm_run_service` Mapping auf denselben Mapper umstellen
- bestehende Status-Whitelists in Decision/Confirm entfernen oder auf Policy delegieren

Gate:
- identisches Verhalten fuer `blocked_confirmation`, `retry_waiting`, `ready`, `failed`, `skipped`
- Queue-State-Transition Tests gruen
- keine Divergenz zwischen Decision- und Confirm-Mapping

### Phase 3 - Pending Intent und Queue Transition konsolidieren

Ziel:
- zentrale Transition-Regeln statt verteilter Speziallogik

Arbeitspakete:
- `pending_intent_service` als zentraler Zugriffspunkt fuer setzen/lesen/consumen
- `queue_transition_service` fuer standardisierte Transitionen
  - ready
  - retry_waiting
  - failed
  - skipped
- `agent_decision_service`, `confirm_run_service`, `queue_manager` auf Transition-Service verdrahten

Gate:
- `pending_confirmation_code` und `queue_item_id` bleiben in allen Pfaden stabil
- retry/backoff Metadaten bleiben unveraendert korrekt
- Doppelpfade fuer Transitionen sind entfernt

### Phase 4 - Preflight L1 Konsolidierung

Ziel:
- ein Einstiegspunkt fuer schema/version/deprecation

Arbeitspakete:
- `preflight_contract_validator` erstellen
- bestehende Komponenten integrieren:
  - `preflight_schema_validator`
  - `preflight_version_validator`
  - `task_version_policy`
- `preflight_pipeline` L1-Aufruf auf den neuen Einstieg umstellen
- Logging/Issue-Code-Mapping konsistent halten

Gate:
- identische Blockierung fuer schema/version Fehler wie vorher
- keine Aufweichung von `SCHEMA_ERROR`/`SCHEMA_UNAVAILABLE` Semantik
- L1/L2/L3 Contract-Tests gruen

### Phase 5 - Catalog/Embedding Fassade bilden

Ziel:
- Orchestrator nutzt genau einen Katalog-Einstieg

Arbeitspakete:
- `catalog_selection_service` als Fassade erstellen
- Subverantwortungen klar kapseln:
  - readiness
  - retrieval
  - rebuild scheduling
  - fallback selection
- `orchestrator` auf `catalog_selection_service` umstellen
- direkte Zugriffe auf einzelne Embeddings-Services reduzieren

Gate:
- Planner-Katalog bleibt stabil pro Turn
- keine Regression bei Fallback-Auswahl
- keine neuen re-embed Nebenpfade im Loop

### Phase 6 - Shrink und Signatur-Harmonisierung

Ziel:
- Konsolidierung netto sichtbar machen

Arbeitspakete:
- redundante private Helper entfernen
- tote Pfade in Runtime/Decision/Orchestrator/Confirm bereinigen
- Methodensignaturen harmonisieren (Input/Output Contracts unveraendert)
- Dokumentations-Update fuer neue Modulgrenzen

Gate:
- Reduktionsziel erreicht (siehe KPI-Sektion)
- keine Contract-Abweichung in End-to-End Pfaden
- Code-Review ohne verbleibende doppelte Kernhelper in den Hotspots

## 5. Backlog-Schnitt (Tickets)

Ticketgruppe A (Phase 1):
- A1 `shared_json_payload_extractor`
- A2 `provider_routing_util`
- A3 `localized_string_service`
- A4 `trigger_result_util`

Ticketgruppe B (Phase 2):
- B1 `queue_status_policy`
- B2 `queue_command_mapper`
- B3 Decision/Confirm Umstellung auf Mapper/Policy

Ticketgruppe C (Phase 3):
- C1 `pending_intent_service`
- C2 `queue_transition_service`
- C3 Queue/Decision/Confirm Verdrahtung

Ticketgruppe D (Phase 4):
- D1 `preflight_contract_validator`
- D2 Pipeline L1 Umstellung
- D3 Issue/Logging Regressionabsicherung

Ticketgruppe E (Phase 5):
- E1 `catalog_selection_service`
- E2 Orchestrator Integration
- E3 Embedding Subkomponenten entkoppeln

Ticketgruppe F (Phase 6):
- F1 Helper Cleanup
- F2 Signatur-Harmonisierung
- F3 Final Contract Sweep

## 6. Testplan je Phase

Pflichttests in jeder Phase:
- Decision Payload Contract Tests
- Confirm Payload Contract Tests
- Queue Transition Tests

Zusatztests pro Themenblock:
- Phase 1: JSON extraction snapshot tests (plain/fenced/mixed)
- Phase 2-3: Retry/backoff + blocked timeout regressions
- Phase 4: Preflight L1/L2/L3 matrix
- Phase 5: Catalog fallback + readiness matrix
- Phase 6: End-to-End non-regression suite

Definition of Done pro Phase:
- neue Tests vorhanden
- bestehende Contract-Tests gruen
- kein unbegruendeter Payload-Drift

## 7. KPI und Erfolgsmessung

Code-KPIs:
- Reduktion duplizierter Kernhelper in Hotspots (`agent_runtime`, `agent_decision_service`, `orchestrator`, `confirm_run_service`, `execution_feedback_service`)
- Reduktion der privaten Hilfsmethoden in Zieldateien um mindestens 25% (indikativ)

Verhaltens-KPIs:
- 0 Regressionen in `response_type` Vertrag
- 0 Regressionen in `issue_codes` Vertrag
- 0 Regressionen fuer Confirmation Queue IDs/Codes
- stabile Sprach-Fallback-Reihenfolge

Prozess-KPIs:
- jede Phase mit Gate-Abnahme
- keine parallel divergierenden Utility-Varianten nach Phase 3

## 8. Risiko-Management

Top-Risiken:
- schleichender Contract-Drift in Decision/Confirm Payloads
- unbeabsichtigte Aenderung in Queue-Transition Semantik
- Sprach-Fallback-Veraenderung durch Utility-Zentralisierung

Gegenmassnahmen:
- Golden Contracts vor jeder Phase
- Snapshot + Contract Tests als Merge-Gate
- stufenweise Umstellung (additiv -> verdrahten -> entfernen)

Rollback-Strategie:
- Pro Phase separate Commits mit klarer Rueckrollgrenze
- bei Gate-Fehlschlag nur aktuelle Phase zurueckrollen, nicht Gesamtvorhaben stoppen

## 9. Entscheidungs- und Review-Rhythmus

Cadence:
- Tech Review pro Phase-Start
- Contract Review pro Phase-Ende
- kurze Architekturfreigabe vor Phase 4 und Phase 5 (hoechste Integrationslast)

Pflichtartefakte pro Phase:
- Aenderungsprotokoll
- betroffene Invarianten
- Testnachweis
- offene Risiken + naechste Phase

## 10. Abnahmekriterien gesamt

Gesamtabnahme nur wenn alle Bedingungen erfuellt sind:
- alle sechs Phasen mit Gate bestanden
- kein Verstoss gegen Guardrails aus Abschnitt 1
- Konsolidierungsbausteine sind singulaere Wahrheiten (kein Rest-Duplikat in Kernpfaden)
- Agent-Gesamtkonzept bleibt funktional identisch:
  - deterministisch
  - preflight-first
  - confirmation-gated
  - queue-authoritative
