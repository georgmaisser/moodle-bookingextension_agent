# Refactoring Blueprint: Deterministische Discovery + 2-Call Planner

Stand: 2026-06-02
Scope: nur bookingextension_agent
Status: Zielbild-Blueprint + umsetzbarer Arbeitsplan (harte Schnitte erlaubt, kein Legacy-Schutz noetig)

## 1. Zweck

Dieses Dokument beschreibt die Umstellung von der aktuellen 3-LLM-Call-Planerstrecke
(discovery -> selection -> parameter_construction) auf ein Zielbild mit deterministischer Discovery
und nur noch 2 Planner-LLM-Calls vor dem Synchronizer.

Zielpipeline:

1. Deterministisch (Dualpfad):
	- mit Embeddings: semantische Family-Retrieval + Signal/Context-Gewichtung
	- ohne Embeddings: Family-Ableitung aus Moodle-Kontext + core families + Signal-Ranking
	-> N Families, M Tasks (hart budgetiert) in beiden Pfaden
2. LLM Call 1: Tool Selector (genau eine Taskwahl, command-bearing selector output, schlanke Contracts, keine Parameterschemata)
3. LLM Call 2: Constructor (genau eine Task, volles Schema, strukturierte Parameter)
4. LLM Call 3: Synchronizer (unveraendert)

## 2. Warum der Schnitt noetig ist

Aktuelles Problem:

- Discovery ist als LLM-Phase implementiert, erzeugt aber keinen klar abgegrenzten Mehrwert gegenueber Selection.
- Zwischen discovery-Call und selection-Call ist der Promptinhalt oft nahezu identisch.
- Dadurch entstehen doppelte Kosten, schwammige Verantwortungen und vermeidbare Contract-Verstoesse.

Architekturprinzip fuer das Zielbild:

- Discovery ist Retrieval/Ranking-Orchestrierung, nicht generative Planung.
- Selektion und Konstruktion bleiben LLM-Aufgaben, aber sauber getrennt.

## 3. Delta zur aktuellen Implementierung (nur echte Unterschiede)

A) Discovery-LLM-Phase faellt komplett weg:

- Entfernen von planner_discovery LLM invoke.
- Entfernen von discovery Interpreter-Parsing.
- Discovery liefert nur deterministische DTOs/Arrays fuer ranked families und slim task candidates.

B) Planner reduziert sich auf 2 LLM-Phasen:

- Selection (LLM 1): tool_selector-Fokus, genau eine Taskentscheidung, nur slim task contracts fuer M Tasks.
- Construction (LLM 2): parameter_constructor-Fokus, genau eine selektierte Task inkl. vollem Schema.

C) Prompt-/Routing-Profile werden zweiphasig:

- Kein discovery router prompt profile mehr.
- Nur selector profile und constructor profile.

D) Phase-Trace und Composer werden angepasst:

- Discovery trace wird aus planner_result/phase_trace entfernt.
- Planner-Ergebnis enthaelt nur selection + parameter_construction.

E) Hard cuts statt Legacy-Bruecken:

- Keine step_type Foldbacks.
- Kein discovery prompt fallback.
- Kein discovery action_class fallback fuer planner invoke.

## 4. Nicht-Ziele

- Keine Aenderung an Synchronizer-Logik (ausser Anbindung an neuen Planner-Output).
- Keine Aenderung an Decision-Service, Queue, Executor, Preflight-Verhalten.
- Keine Aenderung am selector->constructor Handoff-Semantikformat.

## 5. Flowchart-Refactor (AGENT_IMPLEMENTATION_FLOWCHART.mmd)

Zieldelta fuer [docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd](docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd):

1. Entfernen:
- DPLLM
- DINT
- Kanten zu/von DPLLM und DINT
- AIER -> DPLLM
- DNORM-Rueckkante zu DINT

2. Discovery-Block als deterministischer Block markieren:
- DISC_CTX, EMB_AVAIL, FEMB, FSIG, DISC_A/B/C, FRANK eindeutig als non-LLM Retrieval/Ranking labeln.

3. Neue Kette:
- Deterministischer Discovery-Block -> TASKLOAD -> TSEL -> SPLLM (LLM Call 1)

4. SPLLM-Label aktualisieren:
- source=planner_selection
- action_class=selector_pick_task
- Kontext: nur slim task contracts fuer M ranked tasks, keine Parameter-Schemata.
- Ausgabe: genau eine selektierte Task fuer den Constructor-Handoff.

5. CPLLM bleibt LLM Call 2:
- source=planner_construction
- single-task full schema.

6. ORCSVC-Label aktualisieren:
- selection(selector) -> construction(constructor-only)

7. PPB-Label aktualisieren:
- selector.md | constructor.md
- router_discovery.md entfernen.

8. LG_PLAN in LEGEND aktualisieren:
- deterministische discovery + zwei planner LLM calls + synchronizer.
- keine Discovery-LLM-Referenzen.

9. Styles/Farbkonventionen unveraendert lassen.

## 6. Implementierungs-Refactor (Code)

### 6.1 Orchestrator und Pipeline

Betroffene Hauptdateien:

- classes/local/wbagent/orchestrator.php
- classes/local/wbagent/services/orchestrator_routing_service.php
- classes/local/wbagent/services/orchestrator_prompt_profile_service.php
- classes/local/wbagent/services/phase_prompt_bundle_builder.php
- classes/local/wbagent/services/planner_result_composer.php

Vorgaben:

1. Discovery-Invoke entfernen:
- Kein llm_call_service::invoke() fuer discovery.
- Kein interpret_phase_output(..., discovery, ...).

2. Discovery-Ausgabe deterministisch liefern:
- ranked families
- hard-budgeted selected families/tasks
- slim task contracts fuer selection
- ohne embeddings ueber context/core/signal (deterministisch),
- mit embeddings ueber semantische Family-Retrieval plus context/signal weighting,
- alles ueber family_signal_ranker, family_ranker, optional family_embeddings_retrieval_service.

3. Selection-Invoke bleibt, aber Input strikt slim:
- USER + kurzer Kontext + slim task contracts fuer M Tasks.
- keine full schema payloads.
- Output ist ein echter Selector-Call: genau eine selektierte Task, keine finalen strukturierten Parameter.

4. Construction-Invoke bleibt single-task:
- exakt eine Task
- volles Schema
- Parameter-Contract-Validation wie bisher.

5. Composer/Trace reduzieren:
- phase_trace: nur selection + parameter_construction.
- planner_result: kein discovery-Phase-Payload.

### 6.2 Prompt-/Routing-Profiles

- Discovery prompt keys entfernen.
- Nur selection und parameter_construction prompt keys behalten.
- Routing-Fallbackketten auf 2 Planner-Phasen reduzieren.

### 6.3 Interpreter und Hooks

- Discovery-spezifische interpreter entry points entfernen.
- DNORM-Hooks nur fuer selection/construction belassen.

## 7. Hard-Cut Delete-Liste

Die folgenden Elemente werden im Zielbild entfernt (kein De-priorisieren):

1. planner_discovery source usage
2. router_discovery_family action usage
3. discovery prompt/profile references
4. discovery interpreter parsing path
5. discovery phase trace persistence
6. discovery-specific route policy tokens

## 8. Konfigurierbares Hard-Budget bleibt Pflicht

Discovery bleibt hart budgetiert und konfigurierbar:

- max families
- max tasks
- stage escalation limits

Diese Budgets duerfen nicht implizit werden und muessen weiterhin im Telemetriepfad sichtbar sein.

Zusaetzlich verpflichtend:

- Der no-embeddings Pfad (Moodle-Kontext + core families + signals) bleibt voll funktionsfaehig und darf nicht entfernt werden.
- Der selector erhaelt in beiden Pfaden deterministisch budgetierte, family-scoped Task-Kandidaten.

## 9. Telemetrie-Anpassung

Behalten (weiterhin sichtbar):

- catalogselectionmode
- discovery_stage
- confidence_score
- escalation_reason

Aenderung:

- discovery Telemetrie kommt aus deterministischem Retrieval-Block, nicht aus discovery LLM output.

## 10. Test- und Abnahmeplan

### 10.1 Pflichttests

1. Contract: kein discovery LLM invoke mehr erreichbar.
2. Contract: selection prompt enthaelt keine full parameter schemas.
3. Contract: selection output enthaelt genau eine selektierte Task (tool selector call), aber keine full parameter payload.
4. Contract: construction prompt enthaelt genau eine selektierte Task mit full schema.
5. Contract: planner_result/phase_trace ohne discovery phase.
6. Regression: synchronizer/decision/preflight/executor unveraendert.

### 10.2 DoD

Erfuellt, wenn:

1. Planner hat genau 2 LLM Calls (selection, construction).
2. Discovery ist voll deterministisch und hart budgetiert.
3. Synchronizer bleibt unveraendert dritte LLM-Stufe.
4. Keine Legacy-Discovery-LLM- oder Prompt-Pfade mehr im Code.

## 11. Umsetzungsreihenfolge (empfohlen)

1. Flowchart zuerst aktualisieren (Single Source of Truth).
2. Orchestrator: discovery invoke entfernen, deterministic handoff bauen.
3. Prompt-/Routing-Profiles auf 2 Phasen reduzieren.
4. Composer/phase_trace auf 2 Phasen umstellen.
5. Delete pass auf alte discovery-LLM-Reste.
6. Contracts/Integrationstests aktualisieren und gruen ziehen.

## 12. Risiken und Gegenmassnahmen

1. Risiko: Zu grosse slim task contract payload in Selection.
- Gegenmassnahme: explizites M-task Budget + harte pruning rules.

2. Risiko: Discovery-Deterministik verliert Recall in Randfaellen.
- Gegenmassnahme: low-score tail beibehalten und testbar machen.

3. Risiko: Alte Tests erwarten discovery phase trace.
- Gegenmassnahme: Tests gezielt auf 2-phasen planner_result migrieren.

## 13. Entscheidungsnotiz

Aufgrund fehlender produktiver Nutzung werden bewusst harte Schnitte gesetzt:

- kein Kompatibilitaetsmodus
- kein Legacy-Fallback fuer discovery LLM
- keine parallelen Alt-/Neu-Pfade

Das Ziel ist ein kleinerer, klarer und testbarer Planner-Kern mit deterministischer Discovery und eindeutigem
LLM-Einsatz nur dort, wo Generierung fachlich notwendig ist.

## 14. Ausfuehrbarer Implementierungsplan (Checklist)

Hinweis zur Nutzung:

- Jede Checkbox wird erst auf [x] gesetzt, wenn Code + Test + kurzer Nachweis vorliegen.
- Nach jedem abgeschlossenen Block wird ein kurzer Fortschrittsvermerk in Abschnitt 15 gepflegt.

### Block A - Baseline und Schutzgelander

- [x] A1 Baseline der relevanten Contracts gruen bestaetigen.
	Dateien/Tests: [tests/agent/contracts/phase3_selection_construction_contract_test.php](../../tests/agent/contracts/phase3_selection_construction_contract_test.php), [tests/agent/contracts/phase_prompt_bundle_builder_contract_test.php](../../tests/agent/contracts/phase_prompt_bundle_builder_contract_test.php), [tests/agent/contracts/orchestrator_routing_service_test.php](../../tests/agent/contracts/orchestrator_routing_service_test.php), [tests/agent/contracts/prompt_policy_builder_test.php](../../tests/agent/contracts/prompt_policy_builder_test.php).
	Abnahme: Ausgangsstand reproduzierbar, damit Delta klar messbar bleibt.

- [x] A2 Feature-Flag- und Fallback-Verhalten dokumentiert validieren.
	Dateien: [classes/local/wbagent/services/discovery/discovery_stage_controller.php](../../classes/local/wbagent/services/discovery/discovery_stage_controller.php), [classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php](../../classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php), [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php).
	Abnahme: dualer Discovery-Pfad (mit/ohne Embeddings) als Invariante vor Refactor bestaetigt.

### Block B - Discovery von LLM entkoppeln (Hard Cut)

- [x] B1 Discovery-LLM-Invoke entfernen.
	Datei: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php).
	Zielaenderung: in `run_discovery_phase()` kein `llm_call_service::invoke()` fuer Discovery mehr.
	Abnahme: kein `planner_discovery` source call mehr erreichbar.

- [x] B2 Discovery-Interpreterpfad entfernen.
	Dateien: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php), [classes/local/wbagent/interpreter.php](../../classes/local/wbagent/interpreter.php).
	Zielaenderung: kein `interpret_phase_output(..., discovery, ...)` und keine Discovery-spezifische Parse-Branch mehr.
	Abnahme: Discovery liefert nur deterministische Datenstruktur, kein LLM-Output-Parsing.

- [x] B3 Discovery-Ausgabe als deterministisches DTO stabilisieren.
	Dateien: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php), [classes/local/wbagent/services/discovery/context_prior_builder.php](../../classes/local/wbagent/services/discovery/context_prior_builder.php), [classes/local/wbagent/services/discovery/family_registry_service.php](../../classes/local/wbagent/services/discovery/family_registry_service.php), [classes/local/wbagent/services/discovery/family_signal_ranker.php](../../classes/local/wbagent/services/discovery/family_signal_ranker.php), [classes/local/wbagent/services/discovery/family_ranker.php](../../classes/local/wbagent/services/discovery/family_ranker.php), [classes/local/wbagent/services/discovery/discovery_stage_controller.php](../../classes/local/wbagent/services/discovery/discovery_stage_controller.php).
	Abnahme: Discovery liefert ranked families, selected families, slim task candidates, stage/confidence/escalation.

### Block C - Dualpfad mit/ohne Embeddings absichern

- [x] C1 Embeddings-Pfad (semantisch + Kontextgewichtung) erhalten.
	Dateien: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php), [classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php](../../classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php), [classes/local/wbagent/services/discovery/family_ranker.php](../../classes/local/wbagent/services/discovery/family_ranker.php).
	Abnahme: semantische Scores bleiben optionaler Input in Ranking, kombiniert mit signal/context.

- [x] C2 No-Embeddings-Pfad explizit funktionsfaehig halten.
	Dateien: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php), [classes/local/wbagent/services/discovery/core_family_set.php](../../classes/local/wbagent/services/discovery/core_family_set.php), [classes/local/wbagent/services/discovery/family_signal_ranker.php](../../classes/local/wbagent/services/discovery/family_signal_ranker.php).
	Abnahme: ohne Embeddings weiterhin family derivation aus Moodle-Kontext + core families + signals, ohne semantischen Call.

- [x] C3 Telemetrie-Invarianten beibehalten.
	Datei: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php).
	Abnahme: `catalogselectionmode`, `discovery_stage`, `confidence_score`, `escalation_reason` weiterhin gesetzt.

### Block D - Selection auf schlanke Kandidaten haerten

- [x] D1 Selection-Eingang auf slim contracts begrenzen.
	Dateien: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php), [classes/local/wbagent/services/selection/lazy_task_loader.php](../../classes/local/wbagent/services/selection/lazy_task_loader.php), [classes/local/wbagent/services/selection/task_selector.php](../../classes/local/wbagent/services/selection/task_selector.php).
	Abnahme: Selection-Prompt enthaelt keine full schemas.

- [x] D2 Promptvertrag der Selection-Phase nachziehen.
	Dateien: [classes/local/wbagent/services/phase_prompt_bundle_builder.php](../../classes/local/wbagent/services/phase_prompt_bundle_builder.php), [classes/local/wbagent/prompt_policy_builder.php](../../classes/local/wbagent/prompt_policy_builder.php).
	Abnahme: Selection ist ein echter Tool-Selector-Call und liefert genau eine Taskwahl, aber keine finalen strukturierten Parameter.

### Block E - Construction strikt Single-Task Full-Schema

- [x] E1 Handoff Selection -> Construction auf genau eine Task begrenzen.
	Datei: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php).
	Abnahme: Construction bekommt genau den vom Selector gewaehlten Task-Kontext.

- [x] E2 Full-Schema nur in Construction laden.
	Dateien: [classes/local/wbagent/services/construction/parameter_constructor.php](../../classes/local/wbagent/services/construction/parameter_constructor.php), [classes/local/wbagent/services/construction/parameter_contract_validator.php](../../classes/local/wbagent/services/construction/parameter_contract_validator.php).
	Abnahme: Konstruktion validiert strukturiert gegen Full-Schema, keine Schema-Aufblaehung in Selection.

### Block F - Routing, Prompt-Profile, Composer auf 2 Planner-Phasen umstellen

- [x] F1 Routing-Service ohne Discovery-Action-Routing fuer Planner-Invoke.
	Datei: [classes/local/wbagent/services/orchestrator_routing_service.php](../../classes/local/wbagent/services/orchestrator_routing_service.php).
	Abnahme: Planner-Routing nur noch Selection und Parameter-Construction.

- [x] F2 Prompt-Profile ohne Discovery-Key fuer Plannerphase.
	Datei: [classes/local/wbagent/services/orchestrator_prompt_profile_service.php](../../classes/local/wbagent/services/orchestrator_prompt_profile_service.php).
	Abnahme: kein aktiver Discovery-Prompt-Key im Plannerpfad.

- [x] F3 Phase-Prompt-Bundle auf 2 Planner-Phasen reduzieren.
	Datei: [classes/local/wbagent/services/phase_prompt_bundle_builder.php](../../classes/local/wbagent/services/phase_prompt_bundle_builder.php).
	Abnahme: lokale Output-Contracts sauber fuer Selection/Construction getrennt.

- [x] F4 Planner-Result-Composer ohne Discovery-Payload/Trace.
	Datei: [classes/local/wbagent/services/planner_result_composer.php](../../classes/local/wbagent/services/planner_result_composer.php).
	Abnahme: `planner_result` und `phase_trace` enthalten nur `selection` + `parameter_construction`.

### Block G - Delete Pass (alte Discovery-LLM-Reste entfernen)

- [x] G1 Symbolischer Delete-Pass auf Legacy-Reste.
	Dateien: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php), [classes/local/wbagent/services/orchestrator_routing_service.php](../../classes/local/wbagent/services/orchestrator_routing_service.php), [classes/local/wbagent/services/orchestrator_prompt_profile_service.php](../../classes/local/wbagent/services/orchestrator_prompt_profile_service.php), [classes/local/wbagent/services/phase_prompt_bundle_builder.php](../../classes/local/wbagent/services/phase_prompt_bundle_builder.php), [classes/local/wbagent/services/planner_result_composer.php](../../classes/local/wbagent/services/planner_result_composer.php).
	Abnahme: keine nutzbaren Referenzen auf `planner_discovery`, `router_discover_family`, Discovery-Interpreterpfad.

### Block H - Testabschluss und Freigabe

- [x] H1 Contracts fuer 2-Phasen-Planner aktualisieren/erganzen.
	Dateien/Tests: [tests/agent/contracts/phase_prompt_bundle_builder_contract_test.php](../../tests/agent/contracts/phase_prompt_bundle_builder_contract_test.php), [tests/agent/contracts/orchestrator_routing_service_test.php](../../tests/agent/contracts/orchestrator_routing_service_test.php), [tests/agent/contracts/prompt_policy_builder_test.php](../../tests/agent/contracts/prompt_policy_builder_test.php), [tests/agent/contracts/phase3_selection_construction_contract_test.php](../../tests/agent/contracts/phase3_selection_construction_contract_test.php), [tests/agent/contracts/planner_context_continuity_contract_test.php](../../tests/agent/contracts/planner_context_continuity_contract_test.php).
	Abnahme: Discovery-LLM-Call nicht mehr testbar erreichbar, Selection/Construction-Vertraege gruen.

- [x] H2 Dualpfad-Regressionstest mit und ohne Embeddings.
	Dateien/Tests: [tests/agent/contracts/family_embeddings_retrieval_service_test.php](../../tests/agent/contracts/family_embeddings_retrieval_service_test.php), plus neuer/angepasster Contract-Test in [tests/agent/contracts](../../tests/agent/contracts).
	Abnahme: Beide Pfade liefern deterministisch budgetierte Task-Kandidaten fuer Selection.

- [x] H3 Synchronizer/Decision unveraendert validieren.
	Dateien/Tests: [tests/agent/contracts/runtime_finalization_contract_test.php](../../tests/agent/contracts/runtime_finalization_contract_test.php), [tests/agent/contracts/finalization_classifier_contract_test.php](../../tests/agent/contracts/finalization_classifier_contract_test.php), [tests/agent/contracts/synchronizer_input_contract_test.php](../../tests/agent/contracts/synchronizer_input_contract_test.php).
	Abnahme: kein Verhaltensregress ausser geplantem Planner-Schnitt.

## 15. Fortschrittslog

Verwendung:

- Pro abgeschlossenem Checkbox-Item genau einen Eintrag ergaenzen.
- Format: Datum | Item | kurze Notiz | betroffene Dateien/Tests.

Vorlage:

- YYYY-MM-DD | B1 | Discovery-Invoke entfernt, grep-clean auf planner_discovery | orchestrator.php, ...

- 2026-06-02 | A1 | Baseline-Contracts ausgefuehrt und gruen (10 Tests, 27 Assertions) | phase3_selection_construction_contract_test.php, phase_prompt_bundle_builder_contract_test.php, orchestrator_routing_service_test.php, prompt_policy_builder_test.php
- 2026-06-02 | A2 | Discovery Dualpfad-Tests (Embeddings + Staging) gruen (18 Tests, 65 Assertions) | family_embeddings_retrieval_service_test.php, phase1_discovery_foundation_contract_test.php, phase2_discovery_staging_contract_test.php
- 2026-06-02 | B1 | Discovery-Invoke entfernt; kein planner_discovery/router_discover_family mehr im PHP-Code; Kern-Contracts weiter gruen (22 Tests, 74 Assertions) | orchestrator.php, phase1_discovery_foundation_contract_test.php, phase2_discovery_staging_contract_test.php, phase3_selection_construction_contract_test.php, phase_prompt_bundle_builder_contract_test.php
- 2026-06-02 | B2 | Discovery-Interpreterpfad entfernt; keine Discovery-Aufrufe von interpret_phase_output() mehr im Runtime-Code | orchestrator.php, interpreter.php
- 2026-06-02 | B3 | Deterministischen Discovery-DTO mit Telemetrie/Selected-Families stabilisiert; Contract/Integration-Suite gruen (59 Tests, 620 Assertions) | orchestrator.php, phase1_discovery_foundation_contract_test.php, phase2_discovery_staging_contract_test.php, phase3_selection_construction_contract_test.php, phase_prompt_bundle_builder_contract_test.php, integration_agent_framework_test.php
- 2026-06-02 | C1/C2/C3 | Embeddings-Pfad, no-embeddings Fallback und Discovery-Telemetrie-Invarianten verifiziert (23 Tests, 92 Assertions) | family_embeddings_retrieval_service_test.php, phase1_discovery_foundation_contract_test.php, phase2_discovery_staging_contract_test.php, routing_decision_log_service_contract_test.php, routing_decision_log_service_test.php
- 2026-06-02 | D1/D2 | Selection auf slim contracts gehaertet (Full-Schema nur Construction) und Selection-Promptvertraege testseitig fixiert (12 Tests, 32 Assertions) | phase_prompt_bundle_builder.php, phase_prompt_bundle_builder_contract_test.php, prompt_policy_builder_test.php, phase3_selection_construction_contract_test.php
- 2026-06-02 | E1/E2 | Construction-Handoff auf genau eine Task gehaertet; Construction-Allow-List jetzt single-task aus Selection-Kontext | orchestrator.php
- 2026-06-02 | F1/F2/F3/F4 | Planner auf 2 Phasen verdichtet: keine Discovery-Action-Routingpfade im Planner-Invoke, Prompt-Profile auf selection+construction, Composer ohne Discovery-Payload/Trace; Contracts/Integration gruen (48 Tests, 578 Assertions) | orchestrator_routing_service.php, orchestrator_prompt_profile_service.php, phase_prompt_bundle_builder.php, planner_result_composer.php, adaptive_task_catalog_service.php, orchestrator_prompt_profile_service_test.php, phase_prompt_bundle_builder_contract_test.php, integration_agent_framework_test.php, planner_context_continuity_contract_test.php, phase3_selection_construction_contract_test.php, orchestrator_routing_service_test.php
- 2026-06-02 | G1 | Legacy-Delete-Pass: keine Treffer mehr auf planner_discovery, router_discover_family oder aiinitialprompt_discovery in Klassen/Contracts | orchestrator.php, orchestrator_routing_service.php, orchestrator_prompt_profile_service.php, planner_result_composer.php
- 2026-06-02 | H1/H2/H3 | Abschluss-Abnahme fuer 2-Phasen-Planner, Dualpfad-Regression und Synchronizer/Finalization gruen (48 Tests, 131 Assertions) | phase_prompt_bundle_builder_contract_test.php, orchestrator_routing_service_test.php, prompt_policy_builder_test.php, phase3_selection_construction_contract_test.php, planner_context_continuity_contract_test.php, family_embeddings_retrieval_service_test.php, phase1_discovery_foundation_contract_test.php, phase2_discovery_staging_contract_test.php, runtime_finalization_contract_test.php, finalization_classifier_contract_test.php, synchronizer_input_contract_test.php
- 2026-06-02 | N1 | Selection-Handoff im Orchestrator auf explizite Single-Task-Selector-Entscheidung normalisiert: genau ein Command, `selected_task` verpflichtend, Parameterpayload aus Selection entfernt | orchestrator.php (`run_selection_phase()`, `normalize_selection_phase_output_for_handoff()`, `build_selection_contract_error_result()`), integration_agent_framework_test.php
- 2026-06-02 | N3 | Selection-Output-Contract als Tool-Selector verschaerft: erster Call ohne Parameterkonstruktion (`input` nur ausgelassen oder `{}`), Selection bleibt reine Taskwahl | phase_prompt_bundle_builder.php (`build_local_output_contract_block()`), phase_prompt_bundle_builder_contract_test.php
- 2026-06-02 | N4 | Prompt-Policies auf Selector/Constructor-Rollen abgeglichen: Selection als reine Taskwahl ohne Parameterbau, Construction constructor-only; Discovery-Routing nennt 2-Call-Rollentrennung explizit | prompt_policy_builder.php (`build_response_contract_policy()`, `build_routing_determinism_policy()`), prompt_policy_builder_test.php
- 2026-06-02 | N5 | Interpreter-Phase-Contract auf Selector/Constructor-Trennung gehaertet: Selection wird in `enforce_phase_contract()` validiert (single selector command + `selected_task`-Konsistenz), Construction bleibt single-command + allow-list | interpreter.php (`interpret_phase_output()`, `enforce_phase_contract()`), integration_agent_framework_test.php
- 2026-06-02 | N6 | Planner-Result/Trace auf Selection+Construction sichtbar gemacht: `selected_task` im Selection-Trace explizit asserted, Discovery nicht im `phase_trace` | planner_result_composer.php, integration_agent_framework_test.php
- 2026-06-02 | N7 | Blueprint und Flowchart auf identisches Rollen-Wording gezogen: `selection(selector) -> construction(constructor-only)` | DETERMINISTIC_DISCOVERY_TWO_CALL_REFACTOR_2026-06-02.md, flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd

## 16. Nachtrag: Selector-First / Constructor-Only Fixplan

Ziel dieses Nachtrags:

- Der erste Planner-Call ist ein klarer Tool-Selector-Call mit genau einer gewaehlten Task.
- Der zweite Planner-Call macht nur Parameterkonstruktion fuer genau diese Task.
- Die Dokumentation und der Code sollen diese Rollen explizit und testbar trennen.

### Offene Checkboxen

- [x] N1 Selection-Output als echte Taskwahl fest verdrahten.
	Datei: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php)
	Methode: `run_selection_phase()`
	Aenderung: `phase_output` und `selected_task` muessen eine explizite Tool-Selector-Entscheidung tragen; keine versteckte Mehrfach-Auswahl, keine Parameterpayload.
	Abnahme: Selection liefert genau eine gewaehlte Task fuer den Constructor-Handoff.

- [x] N2 Construction-Phase strikt auf die selektierte Task begrenzen.
	Datei: [classes/local/wbagent/orchestrator.php](../../classes/local/wbagent/orchestrator.php)
	Methode: `run_construction_phase()`
	Aenderung: `allowed_tasks` und Prompt-Kontext duerfen nur die vom Selector gewaehlte Task enthalten; keine Rueckfaelle auf weitere Katalogeintraege.
	Abnahme: Construction baut nur Parameter fuer genau eine Task.

- [x] N3 Phase-Prompt der Selection auf Tool-Selector trimmen.
	Datei: [classes/local/wbagent/services/phase_prompt_bundle_builder.php](../../classes/local/wbagent/services/phase_prompt_bundle_builder.php)
	Methode: `build_local_output_contract_block()`
	Aenderung: Selection-Contract muss den Selector als command-bearing Task-Wahl explizit beschreiben; Full-Schemas bleiben ausgeschlossen.
	Abnahme: erster Call ist als Tool-Selector lesbar und testbar.

- [x] N4 Routing-/Prompt-Policies an Selector/Constructor-Rollen angleichen.
	Datei: [classes/local/wbagent/prompt_policy_builder.php](../../classes/local/wbagent/prompt_policy_builder.php)
	Methode: `build_response_contract_policy()` und `build_routing_determinism_policy()`
	Aenderung: Routing-Text muss Selection als echte Taskwahl und Construction als Parameter-only-Phase beschreiben; keine widerspruechlichen Non-Command-Formulierungen.
	Abnahme: Prompt-Pflichten sind konsistent mit dem Tool-Selector-Zielbild.

- [x] N5 Interpreter-Contract an die Selector/Constructor-Trennung angleichen.
	Datei: [classes/local/wbagent/interpreter.php](../../classes/local/wbagent/interpreter.php)
	Methode: `enforce_phase_contract()`
	Aenderung: Selection muss den Selector-Output als explizite Taskwahl akzeptieren; Construction bleibt bei genau einem Command und dem Allow-List-Check.
	Abnahme: Phase-Contracts reflektieren die 2-Call-Rollen ohne stillen Semantikbruch.

- [x] N6 Planner-Result nur mit Selection- und Construction-Trace ausgeben.
	Datei: [classes/local/wbagent/services/planner_result_composer.php](../../classes/local/wbagent/services/planner_result_composer.php)
	Methode: `compose()`
	Aenderung: `phase_trace` bleibt auf `selection` + `parameter_construction` reduziert und traegt die selektierte Task sichtbar im Selection-Teil.
	Abnahme: Trace macht den Selector-Handoff fuer Debugging und Tests sichtbar.

- [x] N7 Flowchart und Blueprint-Text gleichziehen.
	Dateien: [docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd](docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd), [DETERMINISTIC_DISCOVERY_TWO_CALL_REFACTOR_2026-06-02.md](DETERMINISTIC_DISCOVERY_TWO_CALL_REFACTOR_2026-06-02.md)
	Aenderung: Selector-First/Constructor-Only-Formulierung muss in beiden Artefakten identisch sein.
	Abnahme: Dokumentation und Flowchart widersprechen sich nicht mehr.

- 2026-06-02 | N2 | Construction strikt auf selected_task gehaertet: in `run_construction_phase()` kein Fallback mehr auf Ranked-Task-Pool; Prompt-/Runtime-Kontext + `allowed_tasks` nur noch fuer Selector-Task; Fehlerpfad bei fehlendem `selected_task` hinzugefuegt (`CONTRACT_SELECTION_TASK_MISSING`) | orchestrator.php (`run_construction_phase()`, `build_construction_runtime_catalog_for_selected_task()`, `build_selector_handoff_error_result()`)
