# Refactoring-Plan: Vom Status Quo zum Zielbild

Stand: 2026-06-01
Kontext: bookingextension_agent

## Ziel dieses Dokuments

Dieses Dokument definiert den konkreten Migrationspfad vom aktuellen Zustand zur Zielarchitektur mit:

- strikt phasengetrennter Planner-Pipeline (Discovery -> Selection -> Parameter Construction)
- Family-first Routing statt direktem Task-Top-K als Hauptstrategie
- optionalem Embeddings-Pfad mit robustem No-Embeddings-Fallback
- klar getrenntem Synchronizer-Layer fuer user-facing Finalisierung
- deterministischen Guardrails (Preflight, Queue, Execution, Final Contract)

## Leitprinzipien

1. Kein Big-Bang. Migration in kleine, testbare Phasen.
2. Erst verdrahten, dann Altcode loeschen.
3. Planner entscheidet nur Routing/Task/Parameter, keine finale Textpolitur.
4. Synchronizer darf nie Commands oder Ausfuehrungssemantik aendern.
5. Keine sprachspezifischen Keyword-Listen fuer Routinglogik.
6. Jede Phase hat messbare Exit-Kriterien und Delete-Bilanz.

## Zielarchitektur auf einen Blick

1. Discovery auf Family-Ebene:
   context_prior + family_registry + core_family_set
2. Optionaler Embeddings-Boost:
   nur fuer Family-Ranking, nie als alleinige Task-Entscheidung
3. Gestufte Erweiterung:
   Stage A (Context+Core) -> Stage B (Adjacent) -> Stage C (Global Slim, harte Grenze)
4. Danach erst Task-Ebene:
   lazy_task_loader -> task_selector -> parameter_constructor -> parameter_contract_validator
5. Finalisierung getrennt:
   finalization_classifier -> synchronizer_template_only oder synchronizer_llm_polish

## Klassen- und Service-Matrix (vollstaendig fuer die Migration)

Hinweis zur Einordnung:
- Behalten: bleibt fachlich erhalten
- Refactor: bleibt, aber Verantwortung/Signaturen werden angepasst
- Neu: muss neu angelegt werden
- Entfernen: Altpfad nach Umschaltung entfernen

### A. Entry, Runtime, Orchestrierung

- Behalten: classes/external/ai_send_message.php
- Behalten: classes/external/ai_confirm_run.php
- Behalten: classes/external/ai_discard_pending.php
- Behalten: classes/external/ai_poll_thread.php
- Behalten: classes/external/ai_render_command_preview.php
- Refactor: classes/local/wbagent/agent_runtime.php
  Ziel: klarer Runtime-Loop mit explizitem Übergang in finalization_classifier/synchronizer
- Refactor: classes/local/wbagent/orchestrator.php
  Ziel: nur Planner-Phasen, keine Legacy-Finalization-Zweige
- Refactor: classes/local/wbagent/services/orchestrator_routing_service.php
  Ziel: nur Discovery/Selection/Construction-Routing
- Refactor: classes/local/wbagent/services/orchestrator_prompt_profile_service.php
  Ziel: nur Planner-Profiles, Final-Branches entfernen
- Refactor: classes/local/wbagent/prompt_policy_builder.php
  Ziel: nur Planner-Policies

### B. Discovery (Family-first)

- Neu: classes/local/wbagent/services/discovery/context_prior_builder.php
- Neu: classes/local/wbagent/services/discovery/family_registry_service.php
- Neu: classes/local/wbagent/services/discovery/core_family_set.php
- Neu: classes/local/wbagent/services/discovery/family_signal_ranker.php
- Neu: classes/local/wbagent/services/discovery/family_ranker.php
- Neu: classes/local/wbagent/services/discovery/discovery_stage_controller.php
  Aufgabe: Stage A/B/C Gate-Entscheidungen mit Budget- und Confidence-Regeln
- Neu: classes/local/wbagent/services/discovery/discovery_budget_policy.php
  Aufgabe: harte Obergrenzen je Stage
- Neu: classes/local/wbagent/services/discovery/discovery_confidence_policy.php
  Aufgabe: eindeutige Schwellwerte fuer Eskalation
- Neu: classes/local/wbagent/dto/discovery_result.php

### C. Embeddings auf Family-Ebene

- Refactor: classes/local/wbagent/services/embeddings/embeddings_readiness_service.php
  Ziel: readiness fuer Family-Embeddings nutzbar machen
- Neu: classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php
- Neu: classes/local/wbagent/services/embeddings/family_embeddings_index_service.php
  Aufgabe: Build/Refresh/Status des Family-Index
- Neu: classes/local/wbagent/dto/family_embedding_hit.php
- Refactor: classes/local/wbagent/services/embeddings/embeddings_retrieval_service.php
  Ziel: Task-top-k nicht mehr als primäre Planner-Strategie
- Entfernen oder de-priorisieren nach Umschaltung: direkte Task-Top-K-Routingpfade als Hauptentscheidungsweg

### D. Selection und Parameter Construction

- Neu: classes/local/wbagent/services/selection/lazy_task_loader.php
- Neu: classes/local/wbagent/services/selection/task_selector.php
- Neu: classes/local/wbagent/services/selection/task_selection_overlap_policy.php
- Neu: classes/local/wbagent/services/construction/parameter_constructor.php
- Neu: classes/local/wbagent/services/construction/parameter_contract_validator.php
- Neu: classes/local/wbagent/dto/task_selection_result.php
- Neu: classes/local/wbagent/dto/parameter_construction_result.php
- Refactor: classes/local/wbagent/interpreter.php
  Ziel: phasenbasierte Interpretation und Normierung

### E. Synchronizer und Finalisierung

- Behalten: classes/local/wbagent/services/finalization_classifier.php
- Neu oder erweitern: classes/local/wbagent/services/synchronizer_service.php
- Neu oder erweitern: classes/local/wbagent/services/synchronizer_input_builder.php
- Neu oder erweitern: classes/local/wbagent/services/synchronizer_routing_service.php
- Neu oder erweitern: classes/local/wbagent/services/synchronizer_output_contract.php
- Refactor: classes/local/wbagent/agent_runtime.php
  Ziel: classifier-basierter final path als einziger user-facing Abschluss
- Entfernen: Legacy-Final-Synthesis/FInal-Reasoning-Pfade im Planner

### F. Decision, Preflight, Queue, Executor (Guardrails)

- Behalten: classes/local/wbagent/services/agent_decision_service.php
- Behalten/Refactor: classes/local/wbagent/services/preflight_pipeline.php
- Behalten: classes/local/wbagent/queue/queue_manager.php
- Behalten: classes/local/wbagent/executor.php
- Behalten: classes/local/wbagent/task_executability_evaluator.php
- Neu (optional, empfohlen): classes/local/wbagent/services/preflight/preflight_result_mapper.php
  Aufgabe: einheitliche Fehler- und Retry-Mappings

### G. Task Layer und Contracts

- Behalten: classes/local/wbagent/task_registry.php
- Behalten: classes/local/wbagent/task_registry_factory.php
- Behalten: classes/local/wbagent/task_contract_validator.php
- Behalten: classes/local/wbagent/task_interface.php
- Behalten: classes/local/wbagent/base_task.php
- Behalten: classes/local/wbagent/booking_task_base.php
- Behalten: classes/local/wbagent/core_task_base.php
- Refactor: Prompt-Contracts der Tasks
  Ziel: Family-Zuordnung explizit und maschinenlesbar
- Neu: classes/local/wbagent/contracts/task_family_contract.php
  Aufgabe: eindeutige Family-Metadaten je Task

### H. Support, Sprache, Trigger, Observability

- Behalten: classes/local/wbagent/services/language_policy_service.php
- Behalten: classes/local/wbagent/services/message_trigger_registry.php
- Behalten: classes/local/wbagent/services/privacy_anonymizer.php
- Refactor: classes/local/wbagent/conversation_store.php
  Ziel: persistente planner_trace_history und discovery telemetry
- Neu: classes/local/wbagent/services/telemetry/discovery_telemetry_service.php
- Neu: classes/local/wbagent/services/telemetry/routing_decision_log_service.php
- Neu: classes/local/wbagent/dto/discovery_trace_entry.php

## Migrationspfad mit Checkboxen

## Phase 0: Baseline und Safety Net

- [ ] Baseline-Branch und Rollback-Strategie festlegen
- [ ] Bestehende Testsuite gruene Basis herstellen
- [ ] Architektur-Merkmale als Runtime-Flags definieren
  Beispiel: family_discovery_enabled, staged_discovery_enabled, synchronizer_strict_contract
- [ ] Telemetrie-Grundfelder definieren
  Beispiel: catalogselectionmode, discovery_stage, confidence_score, escalation_reason
- [ ] DoD fuer Phase 0:
  Baseline reproduzierbar, Flags vorhanden, Messung vorbereitet

## Phase 1: Family-Discovery Fundament

- [ ] context_prior_builder implementieren
- [ ] family_registry_service implementieren
- [ ] core_family_set implementieren
- [ ] task_family_contract einfuehren und fuer Kern-Tasks mappen
- [ ] discovery_result DTO einfuehren
- [ ] Unit-Tests fuer Mapping Domain/Context -> Family
- [ ] DoD fuer Phase 1:
  Family-Kandidaten werden deterministisch ohne Embeddings geliefert

## Phase 2: Stage-A/B/C Steuerung ohne Embeddings

- [ ] discovery_stage_controller implementieren
- [ ] discovery_budget_policy implementieren
- [ ] discovery_confidence_policy implementieren
- [ ] family_signal_ranker implementieren (sprachagnostisch)
- [ ] family_ranker implementieren
- [ ] Stage A -> B -> C Eskalationslogik aktivieren
- [ ] Tests fuer Eskalation, Budgetgrenzen und Stop-Regeln
- [ ] DoD fuer Phase 2:
  Vollstaendiger Discovery-Pfad funktioniert robust ohne Embeddings

## Phase 3: Selection und Parameter Construction trennen

- [ ] lazy_task_loader implementieren
- [ ] task_selector implementieren
- [ ] task_selection_overlap_policy implementieren
- [ ] parameter_constructor implementieren
- [ ] parameter_contract_validator implementieren
- [ ] interpreter auf Phasenmodell umstellen
- [ ] End-to-End Tests fuer discovery -> selection -> construction
- [ ] DoD fuer Phase 3:
  Keine kombinierte Task+Parameter-Entscheidung mehr in einem Schritt

## Phase 4: Embeddings optional andocken (Family-Level)

- [ ] family_embeddings_retrieval_service implementieren
- [ ] family_embeddings_index_service implementieren
- [ ] readiness fuer Family-Embeddings verdrahten
- [ ] Embeddings nur als Zusatzsignal in family_ranker einspeisen
- [ ] Fallback bei Nichtverfuegbarkeit: unveraendert auf signal_ranker
- [ ] Metriken vergleichen: mit vs ohne Embeddings
- [ ] DoD fuer Phase 4:
  Embeddings verbessern Ranking, sind aber nie Single Point of Failure

## Phase 5: Planner-Orchestrator entschlacken

- [ ] orchestrator.php auf reine Planner-Phasen reduzieren
- [ ] orchestrator_routing_service auf 3 Planner-Phasen begrenzen
- [ ] orchestrator_prompt_profile_service von Legacy-Finalzweigen bereinigen
- [ ] prompt_policy_builder von Legacy-Finalzweigen bereinigen
- [ ] Alte direkte Task-Top-K-Hauptpfade deaktivieren
- [ ] DoD fuer Phase 5:
  Planner ist fachlich klar getrennt und ohne Legacy-Finallogik

## Phase 6: Synchronizer finalisieren

- [ ] finalization_classifier-Regeln final absichern
- [ ] synchronizer_input_builder finalisieren
- [ ] synchronizer_routing_service finalisieren
- [ ] synchronizer_output_contract finalisieren
- [ ] template_only und llm_polish Pfad komplett absichern
- [ ] Tests fuer Drift-Reject und Rollback auf Source-Result
- [ ] DoD fuer Phase 6:
  Einziger user-facing Finalpfad ist classifier -> synchronizer

## Phase 7: Altpfade entfernen und aufraeumen

- [ ] Legacy-Final-Synthesis/FInal-Reasoning-Konstanten entfernen
- [ ] Tote Branches in Orchestrator und Prompt-Policies entfernen
- [ ] Nicht mehr genutzte Task-Top-K-Hauptpfade entfernen
- [ ] Veraltete Tests/Fixtures bereinigen
- [ ] Dokumentation und Flowcharts auf finalen Stand bringen
- [ ] DoD fuer Phase 7:
  Neue Architektur ist exklusiv aktiv, Altlogik entfernt

## Teststrategie pro Phase

- [ ] Unit-Tests fuer alle neuen Discovery-Policies
- [ ] Unit-Tests fuer task_selector und parameter_contract_validator
- [ ] Unit-Tests fuer finalization_classifier und synchronizer_output_contract
- [ ] Integrations-Tests fuer Runtime-Loop mit Queue/Preflight/Executor
- [ ] Regression-Tests fuer Klarstellungsdialoge ueber mehrere Turns
- [ ] Fehlerfalltests: fehlende Embeddings, stale Index, low confidence, budget exceeded

## Deployment- und Rollout-Pfad

- [ ] Feature Flags standardmaessig ausliefern (safe by default)
- [ ] Erst Shadow-Metriken sammeln (neue Discovery-Entscheidung nur loggen)
- [ ] Dann kontrollierte Aktivierung pro Kontexttyp
- [ ] Danach globale Aktivierung mit engmaschigem Monitoring
- [ ] Nach Stabilitaetsfenster: Legacy-Code final entfernen

## Exit-Kriterien fuer das Gesamtprojekt

- [ ] Discovery laeuft deterministisch ueber Stage A/B/C mit klaren Schwellen
- [ ] Keine sprachspezifischen Keyword-Router fuer Taskwahl aktiv
- [ ] Embeddings sind optionales Ranking-Signal, nicht Haupt-Routinggrundlage
- [ ] Selection und Parameter Construction sind klar getrennt
- [ ] Synchronizer aendert nie Commands oder Ausfuehrungssemantik
- [ ] Legacy-Finalpfade und alte Task-Top-K-Hauptpfade sind entfernt
- [ ] Netto-Loeschbilanz positiv (mehr Altcode entfernt als neu hinzugefuegt)

## Empfohlene Reihenfolge fuer Commits

1. Discovery-Fundament (Family-Contracts, Registry, DTOs)
2. Stage-Controller und Policies (A/B/C ohne Embeddings)
3. Selection/Construction Split
4. Family-Embeddings Integration (optional)
5. Orchestrator-Slimming und Legacy-Branch Cleanup
6. Synchronizer-Haertung und finale Umschaltung
7. Delete-Pass + Test/Docs Finalisierung

## Arbeitsboard: Direkter Start mit Phase 0 und Phase 1

Ziel dieses Arbeitsboards:

- sofortige Umsetzbarkeit in kleinen PR-Slices
- klare Abnahme je Slice
- minimales Risiko durch fruehe Messbarkeit und Rollback

### Board-Regeln

1. Jeder Slice ist in 1 bis 2 Tagen lieferbar.
2. Jeder Slice hat eigene Tests und klare DoD.
3. Kein Slice darf Legacy-Verhalten ohne Feature-Flag ersetzen.
4. Nach jedem Slice: kurzer Telemetrie-Check und Dokument-Update.

### Phase 0 in PR-Slices (Baseline und Safety Net)

#### P0-S1: Feature-Flag-Grundgeruest

- Scope:
  Runtime-Flags zentral einfuehren und lesbar machen.
- Zielklassen:
  classes/local/wbagent/agent_runtime.php
  classes/local/wbagent/orchestrator.php
  classes/local/wbagent/conversation_store.php
  classes/local/wbagent/services/orchestrator_routing_service.php
- Neue Artefakte (falls noetig):
  classes/local/wbagent/config/runtime_feature_flags.php
- Abnahme:
  - [ ] Flags sind zentral definiert.
  - [ ] Default ist sicher (altes Verhalten bleibt aktiv).
  - [ ] Unit-Test fuer Flag-Auswertung vorhanden.
- Risiko:
  inkonsistente Flag-Namen zwischen Services.
- Gegenmassnahme:
  eine einzige zentrale Flag-Konstante/Resolver-Klasse.

#### P0-S2: Telemetrie-Mindestfelder

- Scope:
  Discovery- und Routing-Entscheidungen strukturiert loggen.
- Zielklassen:
  classes/local/wbagent/conversation_store.php
  classes/local/wbagent/agent_runtime.php
  classes/local/wbagent/orchestrator.php
- Neue Artefakte:
  classes/local/wbagent/services/telemetry/routing_decision_log_service.php
- Pflichtfelder:
  catalogselectionmode, discovery_stage, confidence_score, escalation_reason
- Abnahme:
  - [ ] Felder erscheinen stabil in Thread-Metadaten.
  - [ ] Fehlende Werte sind als null/unknown klar normalisiert.
  - [ ] Integrations-Test fuer Persistenz pro Turn vorhanden.
- Risiko:
  zu viel unstrukturierter Freitext statt fester Felder.
- Gegenmassnahme:
  nur enum/string-feste Schluessel zulassen.

#### P0-S3: Rollback- und Schattentest-Pfad

- Scope:
  Neue Discovery-Entscheidungen erst shadow-only berechnen, ohne Live-Routing zu beeinflussen.
- Zielklassen:
  classes/local/wbagent/orchestrator.php
  classes/local/wbagent/agent_runtime.php
  classes/local/wbagent/services/orchestrator_routing_service.php
- Abnahme:
  - [ ] Shadow-Pfad produziert Telemetrie, aber keine Verhaltensaenderung.
  - [ ] Rollback ist ein Flag-Flip ohne Code-Revert.
  - [ ] Smoke-Test fuer alte Hauptfaelle gruen.
- Risiko:
  versehentliche Kopplung von Shadow-Output an produktiven Pfad.
- Gegenmassnahme:
  explizite Trennung in DTOs: live_result und shadow_result.

### Phase 1 in PR-Slices (Family-Discovery Fundament)

#### P1-S1: Task-Family Contract einfuehren

- Scope:
  Family-Zuordnung maschinenlesbar pro Task-Contract machen.
- Zielklassen:
  classes/local/wbagent/task_contract_validator.php
  classes/local/wbagent/task_registry.php
  classes/local/wbagent/task_registry_factory.php
- Neue Artefakte:
  classes/local/wbagent/contracts/task_family_contract.php
- Abnahme:
  - [ ] Jeder Kern-Task liefert gueltige Family-Metadaten.
  - [ ] Validation scheitert klar bei fehlender Family.
  - [ ] Unit-Tests fuer positive/negative Contract-Faelle.
- Risiko:
  Bestands-Tasks ohne Family brechen Registrierung.
- Gegenmassnahme:
  kontrollierte Migration: Warnmodus -> Fehlermodus per Flag.

#### P1-S2: Family Registry Service

- Scope:
  Domain/Context zu Family-Kandidaten deterministisch aufbauen.
- Neue Artefakte:
  classes/local/wbagent/services/discovery/family_registry_service.php
  classes/local/wbagent/dto/discovery_result.php
- Abnahme:
  - [ ] Kontexte liefern reproduzierbare Family-Sets.
  - [ ] Keine Sprachheuristik fuer Routing verwendet.
  - [ ] Unit-Tests fuer Mapping je Kontexttyp.
- Risiko:
  zu grobe Kontextabbildung erzeugt Over-Inclusion.
- Gegenmassnahme:
  context_prior als Prior statt Hard-Filter.

#### P1-S3: Core Family Set

- Scope:
  immer aktive Basisfamilien fuer robuste Mindestabdeckung einziehen.
- Neue Artefakte:
  classes/local/wbagent/services/discovery/core_family_set.php
- Abnahme:
  - [ ] Core-Familien werden immer in Stage A aufgenommen.
  - [ ] Konfigurierbare, kleine Obergrenze fuer Core-Liste.
  - [ ] Tests gegen unbeabsichtigtes Anwachsen.
- Risiko:
  Core-Set wird zu gross und verwaessert Ranking.
- Gegenmassnahme:
  harte Max-Groesse und Review-Gate bei Erweiterungen.

#### P1-S4: Context Prior Builder

- Scope:
  Kontextsignale als Ranking-Prior bereitstellen, nicht als harte Ausschlussregel.
- Neue Artefakte:
  classes/local/wbagent/services/discovery/context_prior_builder.php
- Abnahme:
  - [ ] Prior wird im Discovery-Result explizit ausgegeben.
  - [ ] Kein Kandidat wird nur wegen Prior ausgeschlossen.
  - [ ] Unit-Tests fuer Prior-Berechnung.
- Risiko:
  Prior wird versehentlich als Filter missbraucht.
- Gegenmassnahme:
  API-Vertrag: nur score_boost, keine filter_if_not_match-Option.

### Reihenfolge fuer unmittelbaren Start

1. P0-S1
2. P0-S2
3. P0-S3
4. P1-S1
5. P1-S2
6. P1-S3
7. P1-S4

### Abnahme-Gates nach jedem zweiten Slice

Gate A (nach P0-S2):

- [ ] Flags stabil
- [ ] Telemetrie stabil
- [ ] keine Verhaltensaenderung im Live-Pfad

Gate B (nach P1-S2):

- [ ] Family-Contracts valide
- [ ] Family-Registry deterministisch
- [ ] Shadow-Discovery liefert plausible Kandidaten

Gate C (nach P1-S4):

- [ ] Context-Prior wirkt nur als Ranking-Prior
- [ ] Core-Set und Family-Registry liefern robuste Stage-A-Basis
- [ ] Freigabe fuer Phase 2 (Stage A/B/C Controller) erteilt

## Sprintplan: Phase 0 und 1 in einem Sprint

Sprintziel:

- Phase 0 und Phase 1 fachlich und technisch abschliessen
- alle P0/P1-Slices mit Abnahme durchziehen
- Freigabe fuer Phase 2 (Stage A/B/C Controller) erreichen

Zeitbox:

- 10 Arbeitstage (1 Sprint)
- WIP-Limit: maximal 2 parallele PRs
- Merge-Regel: erst gruen, dann naechster Slice

### Sprint-Backlog (verbindlich)

- [ ] P0-S1 abgeschlossen
- [ ] P0-S2 abgeschlossen
- [ ] P0-S3 abgeschlossen
- [ ] Gate A bestanden
- [ ] P1-S1 abgeschlossen
- [ ] P1-S2 abgeschlossen
- [ ] Gate B bestanden
- [ ] P1-S3 abgeschlossen
- [ ] P1-S4 abgeschlossen
- [ ] Gate C bestanden

### Tagesplan (empfohlener Ablauf)

Tag 1:

- Kickoff, Scope-Freeze fuer P0/P1
- P0-S1 Implementierung starten

Tag 2:

- P0-S1 Tests + Merge
- P0-S2 Implementierung starten

Tag 3:

- P0-S2 Tests + Merge
- Telemetrie-Check und Gate A vorbereiten

Tag 4:

- P0-S3 Implementierung + Smoke-Tests
- Gate A final abhaken

Tag 5:

- P1-S1 Implementierung starten
- Vertrags-Validierung fuer Family-Metadaten stabilisieren

Tag 6:

- P1-S1 Tests + Merge
- P1-S2 Implementierung starten

Tag 7:

- P1-S2 Tests + Merge
- Gate B final abhaken

Tag 8:

- P1-S3 Implementierung + Tests

Tag 9:

- P1-S4 Implementierung + Tests

Tag 10:

- Gate C final abhaken
- Sprint-Abschluss: Dokumentation, offene Restpunkte, Freigabe fuer Phase 2

### PR-Template je Slice (Pflicht)

Jede PR fuer einen Slice enthaelt mindestens:

1. Scope und Nicht-Scope
2. Feature-Flag-Verhalten (default/off/on)
3. Testliste (unit/integration/smoke)
4. Telemetrie-Auszug (vorher/nachher)
5. Rollback-Anweisung (reiner Flag-Flip oder revert)

### Harte Sprint-Abnahmekriterien

- [ ] Kein produktiver Verhaltensbruch im Legacy-Pfad
- [ ] Alle neuen Pfade sind hinter Flags steuerbar
- [ ] Telemetrie-Felder sind konsistent und auswertbar
- [ ] Family-Contracts fuer Kern-Tasks sind valide
- [ ] Family-Registry liefert reproduzierbare Kandidaten
- [ ] Context-Prior ist ausschliesslich Ranking-Prior

### Sprint-Risiken und Trigger fuer Re-Planung

Re-Planung innerhalb des Sprints ist verpflichtend, wenn einer der folgenden Trigger eintritt:

- mehr als 2 rote Builds in Folge beim gleichen Slice
- unerwartete Seiteneffekte auf Live-Routing im Shadow-Modus
- Family-Contract-Migration blockiert mehr als 20 Prozent der Kern-Tasks
- Telemetrie nicht reproduzierbar ueber zwei identische Testlaeufe

### Sprint-Definition of Done (P0+P1)

Der Sprint gilt nur dann als erfolgreich abgeschlossen, wenn:

1. Alle P0- und P1-Slices gemerged sind.
2. Gate A, Gate B und Gate C jeweils vollstaendig abgehakt sind.
3. Keine offenen blocker/high Risiken in P0/P1 verbleiben.
4. Phase-2-Start mit klarer Eingangslage moeglich ist.

## Risiken und Gegenmassnahmen

1. Risiko: Zu aggressive Budgetgrenzen in Stage A
   Gegenmassnahme: konservative Defaults und Telemetrie-gestuetztes Tuning
2. Risiko: Family-Mapping anfangs unvollstaendig
   Gegenmassnahme: Core-Family-Set als Sicherheitsnetz plus Stage-B-Eskalation
3. Risiko: Regression bei mehrstufigen Klarstellungen
   Gegenmassnahme: explizite Multi-Turn-Regressionssuite
4. Risiko: Embeddings-Index stottert oder ist stale
   Gegenmassnahme: harter No-Embeddings-Fallback ohne Verhaltensbruch
5. Risiko: Legacy-Zweige bleiben unbemerkt aktiv
   Gegenmassnahme: Feature-Flag-Audits und Delete-Gates pro Phase

## Definition of Done

Das Refactoring gilt als abgeschlossen, wenn:

1. Der Runtime-Pfad nur noch ueber die neue Planner- und Synchronizer-Trennung laeuft.
2. Die Stage-A/B/C-Discovery in Produktion aktiv ist und beobachtbar stabil bleibt.
3. Alle Legacy-Final- und direkten Task-Top-K-Hauptpfade entfernt sind.
4. Test- und Dokumentationsstand konsistent mit der neuen Architektur ist.
