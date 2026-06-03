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

Statusupdate 2026-06-02 (Family-Ranking):
- Die Family-Ranking-Bausteine sind bereits implementiert und testabgedeckt.
- Offener Schwerpunkt ist die vollstaendige Live-Verdrahtung in den Planner-Phasenpfad.
- Einordnung "Neu" in Abschnitt B/C ist historisch und wird unten auf den realen Status korrigiert.

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

- Behalten/Refactor: classes/local/wbagent/services/discovery/context_prior_builder.php
- Behalten/Refactor: classes/local/wbagent/services/discovery/family_registry_service.php
- Behalten/Refactor: classes/local/wbagent/services/discovery/core_family_set.php
- Behalten/Refactor: classes/local/wbagent/services/discovery/family_signal_ranker.php
- Behalten/Refactor: classes/local/wbagent/services/discovery/family_ranker.php
- Behalten/Refactor: classes/local/wbagent/services/discovery/discovery_stage_controller.php
  Aufgabe: Stage A/B/C Gate-Entscheidungen mit Budget- und Confidence-Regeln
- Behalten/Refactor: classes/local/wbagent/services/discovery/discovery_budget_policy.php
  Aufgabe: harte Obergrenzen je Stage
- Behalten/Refactor: classes/local/wbagent/services/discovery/discovery_confidence_policy.php
  Aufgabe: eindeutige Schwellwerte fuer Eskalation
- Behalten/Refactor: classes/local/wbagent/dto/discovery_result.php

### C. Embeddings auf Family-Ebene

- Refactor: classes/local/wbagent/services/embeddings/embeddings_readiness_service.php
  Ziel: readiness fuer Family-Embeddings nutzbar machen
- Behalten/Refactor: classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php
- Behalten/Refactor: classes/local/wbagent/services/embeddings/family_embeddings_index_service.php
  Aufgabe: Build/Refresh/Status des Family-Index
- Neu (optional): classes/local/wbagent/dto/family_embedding_hit.php
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
- [x] Architektur-Merkmale als Runtime-Flags definieren
  Beispiel: family_discovery_enabled, staged_discovery_enabled, synchronizer_strict_contract
- [x] Telemetrie-Grundfelder definieren
  Beispiel: catalogselectionmode, discovery_stage, confidence_score, escalation_reason
- [ ] DoD fuer Phase 0:
  Baseline reproduzierbar, Flags vorhanden, Messung vorbereitet

Ergebnisstand (2026-06-01):

- `runtime_feature_flags.php`: zentrale Flag-Quelle mit family/staged/synchronizer Flags
- `routing_decision_log_service.php`: Telemetrie-Schema mit catalogselectionmode, discovery_stage, confidence_score, escalation_reason
- `runtime_feature_flags_test.php`: zentrale Flag-Quelle und Fail-closed-Verhalten contract-getestet
- `routing_decision_log_service_contract_test.php`: Telemetrie-Normalisierung und Shadow-Result abgesichert

## Phase 1: Family-Discovery Fundament

- [x] context_prior_builder implementieren
- [x] family_registry_service implementieren
- [x] core_family_set implementieren
- [x] task_family_contract einfuehren und fuer Kern-Tasks mappen
- [x] discovery_result DTO einfuehren
- [x] Unit-Tests fuer Mapping Domain/Context -> Family
- [x] DoD fuer Phase 1:
  Family-Kandidaten werden deterministisch ohne Embeddings geliefert

Ergebnisstand (2026-06-01):

- `context_prior_builder.php`: Kontextprior für Ranking-only Signale
- `family_registry_service.php`: deterministische Family-Kandidaten aus Prompt-Contracts
- `core_family_set.php`: stabile Kernfamilien fuer robuste Mindestabdeckung
- `task_family_contract.php`: Family-Metadaten werden aus Tasknamen/Contracts normalisiert
- `discovery_result.php`: Family-/Context-/Core-Ergebnis als DTO
- `phase1_discovery_foundation_contract_test.php`: Family-Discovery-Fundament contract-getestet

## Phase 2: Stage-A/B/C Steuerung ohne Embeddings

- [x] discovery_stage_controller implementieren
- [x] discovery_budget_policy implementieren
- [x] discovery_confidence_policy implementieren
- [x] family_signal_ranker implementieren (sprachagnostisch)
- [x] family_ranker implementieren
- [x] Stage A -> B -> C Eskalationslogik aktivieren
- [x] Tests fuer Eskalation, Budgetgrenzen und Stop-Regeln
- [ ] DoD fuer Phase 2:
  Vollstaendiger Discovery-Pfad funktioniert robust ohne Embeddings

Ergebnisstand (2026-06-01):

- `phase2_discovery_staging_contract_test.php`: 5/5 Tests gruen
- `routing_decision_log_service_contract_test.php`: 3/3 Tests gruen
- `integration_agent_framework_test.php` (Smoke): 20/20 Tests gruen
- Shadow-Discovery nutzt jetzt echte A/B/C-Policies, beeinflusst aber weiterhin nicht den Live-Pfad

## Phase 3: Selection und Parameter Construction trennen

- [x] lazy_task_loader implementieren
- [x] task_selector implementieren
- [x] task_selection_overlap_policy implementieren
- [x] parameter_constructor implementieren
- [x] parameter_contract_validator implementieren
- [x] interpreter auf Phasenmodell umstellen
- [x] End-to-End Tests fuer discovery -> selection -> construction
- [x] DoD fuer Phase 3:
  Keine kombinierte Task+Parameter-Entscheidung mehr in einem Schritt

Ergebnisstand (2026-06-01):

- `phase3_selection_construction_contract_test.php`: 3/3 Tests gruen
- `integration_agent_framework_test.php` (Smoke): 20/20 Tests gruen
- Interpreter nutzt jetzt task_selector -> lazy_task_loader -> parameter_constructor -> parameter_contract_validator

## Phase 4 Vorbereitung: Family-Level Embeddings sauber andocken

- [x] embeddings_readiness_service als Family-Level-Gate dokumentieren
- [x] family_embeddings_retrieval_service Contract festziehen
- [x] family_embeddings_index_service Contract und Rebuild-Semantik festziehen
- [x] Shadow-Metriken fuer mit vs ohne Embeddings festlegen
- [x] Fallback-Kette family_ranker -> signal_ranker ohne Verhaltensbruch dokumentieren
- [x] DoD fuer Phase 4-Vorbereitung:
  Family-Level-Embeddings sind nur Zusatzsignal, Live-Routing bleibt unveraendert

## Phase 4: Embeddings optional andocken (Family-Level)

- [x] family_embeddings_retrieval_service implementieren
- [x] family_embeddings_index_service implementieren
- [x] embeddings_readiness_service auf Family-Level verdrahten
- [x] readiness fuer Family-Embeddings verdrahten
- [x] Embeddings nur als Zusatzsignal in family_ranker einspeisen
- [x] Fallback bei Nichtverfuegbarkeit: unveraendert auf signal_ranker
- [x] Metriken vergleichen: mit vs ohne Embeddings
- [x] DoD fuer Phase 4:
  Embeddings verbessern Ranking, sind aber nie Single Point of Failure

Ergebnisstand (2026-06-01):

- `runtime_feature_flags.php`: `family_embeddings_enabled` hinzugefuegt
- `family_embeddings_retrieval_service.php`: Family-Scores und Task-Boost implementiert
- `orchestrator.php`: Family-Embeddings-Boost hinter Flag verdrahtet
- `runtime_feature_flags_test.php`: Flag-Contract erweitert
- `family_embeddings_retrieval_service_test.php`: Helper-Contract abgesichert
- `routing_decision_log_service.php`: Live-vs-Shadow-Embeddingsvergleich wird als Telemetrie gespeichert
- `routing_decision_log_service_test.php`: Vergleichsmetrik contract-getestet

## Phase 5: Planner-Orchestrator entschlacken

- [x] final_synthesis_policy aus prompt_policy_builder auslagern
- [x] final_synthesis_policy als separaten Contract-Test absichern
- [x] planner/final routing in orchestrator_routing_service trennen
- [x] planner/runtime prompt profile helpers trennen
- [x] planner/runtime policies im prompt_policy_builder trennen
- [x] runtime policy block ohne doppelte Follow-up-Policy korrigieren
- [x] orchestrator nutzt wieder einen einzigen Routing-Einstieg
- [x] ungenutzte Final-Config- und Runtime-Wrapper entfernt
- [x] Routing-Helfer auf internen Scope reduziert
- [x] ungenutzte Action-Config-Hilfe entfernt
- [x] orchestrator.php auf reine Planner-Phasen reduzieren
- [x] orchestrator_routing_service auf 3 Planner-Phasen begrenzen
- [x] orchestrator_prompt_profile_service von Legacy-Finalzweigen bereinigen
- [x] prompt_policy_builder von Legacy-Finalzweigen bereinigen
- [x] Alte direkte Task-Top-K-Hauptpfade deaktivieren
- [x] DoD fuer Phase 5:
  Planner ist fachlich klar getrennt und ohne Legacy-Finallogik

Ergebnisstand (2026-06-01):

- `prompt_policy_builder.php`: Final-Synthesis-Policy aus dem allgemeinen Policy-Build ausgelagert
- `orchestrator.php`: Final-Synthesis-Policy wird im Final-Prompt-Pfad separat angehaengt
- `prompt_policy_builder_test.php`: Trennung von Planner-Policies und Final-Synthesis-Policy abgesichert
- `orchestrator_routing_service.php`: Planner- und Final-Routing als getrennte Pfade eingefuehrt
- `orchestrator_prompt_profile_service_test.php`: Runtime- und Planner-Profilhilfe getrennt abgesichert
- `prompt_policy_builder.php`: Planner- und Runtime-Policy-Pfade voneinander getrennt
- `prompt_policy_builder_test.php`: Runtime-Policy ohne doppelte Follow-up-Policy abgesichert
- `orchestrator.php`: einzelner Routing-Einstieg fuer Planner- und Final-Faelle genutzt
- `orchestrator_prompt_profile_service.php`: ungenutzte Final-Config-Hilfe entfernt
- `adaptive_task_catalog_service.php`: final-synthesis-spezifischer Top-K-Cutoff entfernt
- `adaptive_task_catalog_service.php`: Nicht-Planer-Steps nutzen jetzt denselben Recency-Cutoff
- `prompt_policy_builder.php`: Final-Policy- und Follow-up-Policy-Text aus dem Shared Builder entfernt
- `orchestrator.php`: Final-Policy-Text lokal im Orchestrator gekapselt
- `orchestrator_routing_service.php`: nur noch Planner-Phasen im Routing-Service
- `orchestrator.php`: Final-Provider-Status ohne finalen Routing-Zweig verdrahtet
- `orchestrator_prompt_profile_service.php`: nur noch Planner-Normalisierung und Planner-Config-Keys
- `orchestrator.php`: direkte Task-Top-K-Auswahl nur noch als Shadow-Telemetrie ohne Routingwirkung
- `orchestrator.php`: Final-Synthese-Branches aus Prompt- und Output-Contract entfernt
- `orchestrator.php`: Planner-only Zustand im Promptaufbau und Output-Contract erzwungen
- `prompt_policy_builder.php`: Runtime-Policy-Wrapper entfernt
- `orchestrator_routing_service.php`: split routing methods auf internen Scope reduziert
- `orchestrator_prompt_profile_service.php`: ungenutzte action config helper entfernt

## Phase 6: Synchronizer finalisieren

- [x] finalization_classifier-Regeln final absichern
- [x] synchronizer_input_builder finalisieren
- [x] synchronizer_routing_service finalisieren
- [x] synchronizer_output_contract finalisieren
- [x] template_only und llm_polish Pfad komplett absichern
- [x] Tests fuer Drift-Reject und Rollback auf Source-Result
- [x] DoD fuer Phase 6:
  Einziger user-facing Finalpfad ist classifier -> synchronizer

Ergebnisstand (2026-06-01):

- `synchronizer_input_builder.php`: observations + source-result context materialisiert
- `synchronizer_routing_service.php`: runtime finalization route aus dem orchestrator ausgelagert
- `synchronizer_output_contract.php`: sync merge und drift rejection in eigener Service-Schicht
- `agent_runtime.php`: classifier -> synchronizer flow ueber Services verdrahtet

## Phase 7: Altpfade entfernen und aufraeumen

Ergebnisstand (2026-06-01):

- `finalization_classifier.php`: deterministic finalization rules contract-tested
- `finalization_template_service.php`: template-only fallback messages contract-tested
- `runtime_finalization_contract_test.php`: rollback on response-type drift covered
- `orchestrator.php`: legacy final reasoning guidance removed from explain_text prompt block
- `agent_runtime.php`: legacy final_synthesis step routed to simple_retrieval finalization flow
- `obsolete/synchronizer_migration_path.md`: status updated to current planner-only/finalization-classifier snapshot
- `tests/agent/contracts/ai_confirm_run_contract_test.php`: legacy finalization fixture moved to current retrieval finalizer source markers
- `tests/agent/contracts/orchestrator_prompt_profile_service_test.php`: legacy finalization fixture renamed to neutral input
- `tests/agent/contracts/prompt_policy_builder_test.php`: legacy finalization fixture renamed to neutral input

- [x] Legacy-Final-Synthesis/FInal-Reasoning-Konstanten entfernen
- [x] Tote Branches in Orchestrator und Prompt-Policies entfernen
- [x] Nicht mehr genutzte Task-Top-K-Hauptpfade entfernen
- [x] Veraltete Tests/Fixtures bereinigen
- [x] Dokumentation und Flowcharts auf finalen Stand bringen
- [x] DoD fuer Phase 7:
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
  - [x] Felder erscheinen stabil in Thread-Metadaten.
  - [x] Fehlende Werte sind als null/unknown klar normalisiert.
  - [x] Integrations-Test fuer Persistenz pro Turn vorhanden.
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
  - [x] Shadow-Pfad produziert Telemetrie, aber keine Verhaltensaenderung.
  - [x] Rollback ist ein Flag-Flip ohne Code-Revert.
  - [x] Smoke-Test fuer alte Hauptfaelle gruen.
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
  - [x] Jeder Kern-Task liefert gueltige Family-Metadaten.
  - [x] Validation scheitert klar bei fehlender Family.
  - [x] Unit-Tests fuer positive/negative Contract-Faelle.
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
  - [x] Kontexte liefern reproduzierbare Family-Sets.
  - [x] Keine Sprachheuristik fuer Routing verwendet.
  - [x] Unit-Tests fuer Mapping je Kontexttyp.
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
  - [x] Core-Familien werden immer in Stage A aufgenommen.
  - [x] Konfigurierbare, kleine Obergrenze fuer Core-Liste.
  - [x] Tests gegen unbeabsichtigtes Anwachsen.
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
  - [x] Prior wird im Discovery-Result explizit ausgegeben.
  - [x] Kein Kandidat wird nur wegen Prior ausgeschlossen.
  - [x] Unit-Tests fuer Prior-Berechnung.
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

- [x] Flags stabil
- [x] Telemetrie stabil
- [x] keine Verhaltensaenderung im Live-Pfad

Gate B (nach P1-S2):

- [x] Family-Contracts valide
- [x] Family-Registry deterministisch
- [x] Shadow-Discovery liefert plausible Kandidaten

Gate C (nach P1-S4):

- [x] Context-Prior wirkt nur als Ranking-Prior
- [x] Core-Set und Family-Registry liefern robuste Stage-A-Basis
- [x] Freigabe fuer Phase 2 (Stage A/B/C Controller) erteilt

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

- [x] P0-S1 abgeschlossen
- [x] P0-S2 abgeschlossen
- [x] P0-S3 abgeschlossen
- [x] Gate A bestanden
- [x] P1-S1 abgeschlossen
- [x] P1-S2 abgeschlossen
- [x] Gate B bestanden
- [x] P1-S3 abgeschlossen
- [x] P1-S4 abgeschlossen
- [x] Gate C bestanden

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

### Konkreter PR-Scope: P0-S1 (Feature-Flag-Grundgeruest)

Ziel von P0-S1:

- zentrale Runtime-Feature-Flags einfuehren
- bestehendes Verhalten unveraendert lassen (safe default)
- technische Basis fuer P0-S2/P0-S3 schaffen

Branch-Empfehlung:

- `feature/wbagent-p0-s1-runtime-flags`

#### A. Dateien (konkret)

Neu:

- `classes/local/wbagent/config/runtime_feature_flags.php`

Anpassen (minimal):

- `classes/local/wbagent/agent_runtime.php`
- `classes/local/wbagent/orchestrator.php`
- `classes/local/wbagent/conversation_store.php`
- `classes/local/wbagent/services/orchestrator_routing_service.php`

Tests (neu):

- `tests/agent/contracts/runtime_feature_flags_test.php`

#### B. Minimal-Diff-Strategie je Datei

1. `classes/local/wbagent/config/runtime_feature_flags.php`

- neue zentrale Resolver-Klasse fuer Flags, z. B. `runtime_feature_flags`
- nur statische, rein lesende API
- Flags fuer P0/P1 bereits enthalten, alle standardmaessig `false`
  - `family_discovery_enabled`
  - `staged_discovery_enabled`
  - `synchronizer_strict_contract`

2. `classes/local/wbagent/agent_runtime.php`

- nur Einbindung des zentralen Resolvers
- keine Verhaltensaenderung, nur vorbereitende Lesepunkte
- optionale Debug-Metadaten: aktive Flag-Snapshot-Werte (read-only)

3. `classes/local/wbagent/orchestrator.php`

- nur Einbindung des zentralen Resolvers
- aktuelle Legacy-Entscheidungslogik bleibt unveraendert
- keine neuen Routingzweige in P0-S1 aktivieren

4. `classes/local/wbagent/conversation_store.php`

- optionaler persistenter Slot fuer Flag-Snapshot je Run/Thread-Meta
- kein Einfluss auf Entscheidungslogik

5. `classes/local/wbagent/services/orchestrator_routing_service.php`

- nur zentralen Resolver konsumieren (keine eigene Flag-Quelle)
- Verhalten bleibt identisch, solange Flags default `false` sind

6. `tests/agent/contracts/runtime_feature_flags_test.php`

- Testfall `default_values_are_safe`
- Testfall `known_flags_can_be_resolved`
- Testfall `unknown_flag_returns_false`
- Testfall `consumer_classes_read_same_flag_source`

#### C. Verbindliche Nicht-Ziele (P0-S1)

- keine Umstellung auf Family-Discovery
- keine neue Stage-A/B/C-Routinglogik
- keine Embeddings-Aenderung
- keine Finalization-Aenderung

#### D. Merge-Checkliste fuer P0-S1

- [x] neue Flag-Klasse vorhanden und zentral genutzt
- [x] alle Flags per Default auf `false`
- [x] keine Verhaltensaenderung in bestehenden Regression-Tests
- [x] neue Unit-Tests fuer Flag-Resolver gruen
- [ ] Code-Review bestaetigt: nur vorbereitende Infrastruktur

#### E. Testausfuehrung (Mindestset)

1. Gezielter Test fuer neue Flag-Klasse
2. Bestehende Agent-Contract-Tests (Smoke)
3. Ein repraesentativer Multi-Step-Test aus `tests/agent/real_llm_multistep/` (falls in CI vorhanden)

Beispiel-Kommandos (an eure CI/Repo-Runner anpassen):

```bash
vendor/bin/phpunit public/mod/booking/bookingextension/agent/tests/agent/contracts/runtime_feature_flags_test.php
vendor/bin/phpunit public/mod/booking/bookingextension/agent/tests/agent/contracts
```

Ergebnisstand (2026-06-01):

- `runtime_feature_flags_test.php`: 4/4 Tests gruen
- `routing_decision_log_service_contract_test.php`: 3/3 Tests gruen
- `integration_agent_framework_test.php` (Smoke): 20/20 Tests gruen
- PHPUnit meldet in den Laeufen bestehende Deprecations (nicht durch P0 verursacht)

#### F. Rollback-Plan

- Sofort-Rollback ohne Revert: alle neuen Flags auf `false` belassen
- Falls technische Stoerung in Flag-Resolver: PR revertiert nur P0-S1-Dateien
- Keine Datenmigration notwendig

#### G. Exit-Kriterium fuer Start von P0-S2

P0-S2 darf erst starten, wenn:

- [x] P0-S1 gemerged ist
- [x] Flag-Resolver in allen Zielklassen konsistent referenziert wird
- [x] kein Verhalten im Legacy-Pfad geaendert wurde

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
