# Implementierungscheckliste: Split-Pipeline (Discovery -> Selection -> Parameter Construction)

Stand: 2026-06-02
Scope: nur mod/booking/bookingextension/agent
Migrationspolitik: keine Ruecksicht auf Migration oder Backward Compatibility. Alte Pfade werden entfernt.

## 0. Verifizierter Ist-Stand Family-Ranking (Codecheck 2026-06-02)

### Bereits vorhanden (verifiziert)
- [x] Family-Ranking-Services implementiert:
	- classes/local/wbagent/services/discovery/family_registry_service.php
	- classes/local/wbagent/services/discovery/family_signal_ranker.php
	- classes/local/wbagent/services/discovery/family_ranker.php
	- classes/local/wbagent/services/discovery/discovery_stage_controller.php
	- classes/local/wbagent/services/discovery/discovery_budget_policy.php
	- classes/local/wbagent/services/discovery/discovery_confidence_policy.php
- [x] Family-Embeddings implementiert:
	- classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php
	- classes/local/wbagent/services/embeddings/family_embeddings_index_service.php
- [x] Runtime-Flags vorhanden (family_discovery_enabled, staged_discovery_enabled, family_embeddings_enabled, synchronizer_strict_contract) in classes/local/wbagent/config/runtime_feature_flags.php
- [x] Stage-A/B/C-Logik vorhanden und im Shadow-Telemetriepfad verdrahtet
- [x] Contract-Tests fuer Family-Bausteine vorhanden

### Noch offen (aus Ist-Befund)
- [ ] Family-Ranking aus Shadow/Teilpfad in vollstaendigen Live-Phasenpfad ueberfuehren
- [ ] embeddingstatus=shadow_only als Endzustand entfernen
- [ ] Discovery A/B/C als echte Live-Entscheidungskette erzwingen

## 1. Verbindliche Zielarchitektur (Pflicht)

- [ ] Planner in drei harte Phasen trennen: Discovery -> Selection -> Parameter Construction
- [ ] Doppelpfad in Discovery explizit erhalten:
	- mit aiprovider_wunderbyte + verfuegbaren Embeddings = semantische Family-Suche aktiv
	- ohne Embeddings/Provider = slim non-semantische Family-Discovery aktiv
- [ ] Synchronizer strikt als finaler Message-Polish ohne Command-Mutation
- [ ] Decision/Preflight/Queue/Executor deterministisch belassen
- [ ] Bestehendes Family-Ranking uebernehmen und live verdrahten (nicht neu bauen)
- [ ] Monolithische Planner- und Prompt-Pfade vollstaendig entfernen

## 2. Klassenweise Umsetzungscheckliste

## 2.1 Entry und Runtime

### classes/external/ai_send_message.php
- [ ] Neue Phasen-Telemetrie im Entry-Kontext mitgeben
- [ ] Keine Legacy-Loeschung noetig

### classes/local/wbagent/agent_runtime.php
- [ ] run_internal auf orchestrator-Phasenlauf umstellen
- [ ] phase_trace pro loop_step (A/B/C, route, issue_codes) persistieren
- [ ] Semantische Planner-Entscheidungen aus Runtime entfernen
- [ ] Versteckte Planner-Heuristiken entfernen

### classes/local/wbagent/agent_state.php
- [ ] Cache-Struktur phase-aware machen (discovery/selection/construction)
- [ ] Cache in family_cache/selected_task_cache/params_cache trennen
- [ ] Alten monolithischen planner_catalog_cache payload entfernen

### classes/local/wbagent/conversation_store.php
- [ ] planner_trace_history pro Turn konsistent persistieren
- [ ] phase_trace (discovery/selection/construction) als auswertbare Metadaten persistieren
- [ ] Legacy-Metadatenformate ohne Phasenbezug entfernen

## 2.2 Orchestrator und Routing

### classes/local/wbagent/orchestrator.php
- [ ] process() in run_discovery_phase()/run_selection_phase()/run_construction_phase() aufteilen
- [ ] Pro Phase eigenen Prompt-Build und eigenen invoke()-Call erzwingen
- [ ] Discovery mit Family-Ranking als Pflichtschritt vor Selection ausfuehren
- [ ] Unified planner_result aus drei Phasen zusammensetzen
- [ ] Monolithischen Einmal-Call-Pfad entfernen
- [ ] Legacy-Mischlogik Taskwahl+Parametrisierung in einem Output entfernen

### classes/local/wbagent/services/orchestrator_routing_service.php
- [ ] API auf resolve_action_class_for_phase(phase) umstellen
- [ ] Fallback-Ketten je Phase trennen
- [ ] routepolicy auf phase-spezifische Tokens umstellen
- [ ] step_type-only Routing als Hauptsteuerung entfernen
- [ ] Implizite OpenAI-Step-Heuristik fuer Planner entfernen

### classes/local/wbagent/services/orchestrator_prompt_profile_service.php
- [ ] Phase-Profile (discovery/selection/parameter_construction) einfuehren
- [ ] Getrennte Config-Keys und Defaults pro Phase einfuehren
- [ ] step-type Foldback auf tool_call_parse entfernen

### classes/local/wbagent/services/phase_prompt_bundle_builder.php (neu oder bestehende Logik extrahieren)
- [ ] Prompt-Build strikt phasengetrennt (discovery/selection/construction) kapseln
- [ ] Keine gemischten Legacy-Prompts in einem Builder-Pfad zulassen

### classes/local/wbagent/services/llm/llm_call_service.php
- [ ] Als einzige LLM-Call-Schicht beibehalten
- [ ] source-Konvention fuer Phasen vereinheitlichen (z.B. p=disc/sel/cons)
- [ ] Provider-spezifische Instanziierung ausserhalb der Klasse entfernen

## 2.3 Discovery und Family Ranking

### classes/local/wbagent/services/discovery/family_registry_service.php
- [ ] Als verbindlichen Discovery-Einstieg setzen
- [ ] Stage-A/B/C Expansion mit discovery_stage_controller/discovery_budget_policy live verdrahten
- [ ] Full-Task-Dump-Fallback ohne Family-Vorselektion entfernen

### classes/local/wbagent/services/discovery/family_signal_ranker.php
- [ ] Als language-agnostische Basis beibehalten
- [ ] Gewichte zentral konfigurierbar machen
- [ ] Sprachspezifische Keyword-/Token-Routingregeln entfernen

### classes/local/wbagent/services/discovery/family_ranker.php
- [ ] Als autoritative Ranking-Stelle setzen
- [ ] Low-score tail kontrolliert an Selection weiterreichen
- [ ] Ad-hoc Scoring ausserhalb des Dienstes entfernen

### classes/local/wbagent/services/embeddings/family_embeddings_retrieval_service.php
- [ ] In Discovery-Phase verbindlich einhaengen (wenn Embeddings verfuegbar)
- [ ] Nur aktivieren, wenn aiprovider_wunderbyte + Embeddings-Readiness beide true sind
- [ ] ranking output live wirksam machen (kein reines shadow_only)
- [ ] Embedding-TopK als isolierte Nebenrechnung ohne Phasenwirkung entfernen

### classes/local/wbagent/services/discovery/context_prior_builder.php
- [ ] Als verpflichtenden Ranking-Prior verwenden
- [ ] Harte Context-Filter (Ausschluss statt Ranking) entfernen

### classes/local/wbagent/services/embeddings/embeddings_readiness_service.php
- [ ] Gate fuer Wunderbyte+Embeddings-Verfuegbarkeit explizit im Discovery-Pfad nutzen
- [ ] Bei false deterministisch auf slim non-semantische Family-Discovery fallen
- [ ] Telemetrie fuer Pfadwahl (with_embeddings vs no_embeddings) konsistent schreiben

## 2.4 Selection und Construction

### classes/local/wbagent/services/selection/lazy_task_loader.php
- [ ] Nur Tasks aus gerankten Families laden
- [ ] Erst schlanke Contracts, volles Schema nur on-demand laden
- [ ] Globales Voll-Laden aller Task-Schemata entfernen

### classes/local/wbagent/services/selection/task_selector.php
- [ ] Als eigene Selection-Phase nutzen
- [ ] Genau eine selektierte Task + version liefern
- [ ] Implizite Task-Normalisierung ausserhalb des Selectors entfernen

### classes/local/wbagent/services/construction/parameter_constructor.php
- [ ] Als exklusive Parameter-Phase nutzen
- [ ] Task-Wahl in Construction verhindern
- [ ] Selection-fremde Plausibilitaetsentscheidungen entfernen

### classes/local/wbagent/services/construction/parameter_contract_validator.php
- [ ] Als verpflichtenden Abschluss jeder Construction-Phase setzen
- [ ] recoverable input sauber auf clarification/retry_hint mappen
- [ ] Doppelte spaetere Schema-Checks entfernen

## 2.5 Interpreter

### classes/local/wbagent/interpreter.php
- [ ] Auf interpret_phase_output(raw, phase, context) umbauen
- [ ] Phase-Contracts (Discovery/Selection/Construction) explizit normalisieren
- [ ] Unified planner result erst nach Phasen-Interpretation komponieren
- [ ] Sicherheitsgrenzen (strict JSON, allowed response_type) beibehalten
- [ ] Monolithische Annahme "Taskwahl + Input-Finalisierung in einer Antwort" entfernen

### classes/local/wbagent/services/planner_result_composer.php (neu oder bestehende Logik extrahieren)
- [ ] Drei Phasenoutputs deterministisch zu einem planner_result komponieren
- [ ] planner_trace_history + phase_trace zentral schreiben
- [ ] Keine nachgelagerte implizite Re-Komposition in Runtime/Decision zulassen

## 2.6 Decision, Preflight, Queue, Executor

### classes/local/wbagent/services/decision/agent_decision_service.php
- [ ] Beibehalten, aber nur Inputs aus neuer Pipeline konsumieren
- [ ] Planner-spezifische Heuristik hier ausschliessen
- [ ] Rueckwirkende Korrekturlogik fuer alte Planner-Formate entfernen

### classes/local/wbagent/services/preflight_pipeline.php
- [ ] Als einzige Preflight-Quelle fuer Mutationen erzwingen
- [ ] Planner-Entscheidung strikt durch diese Pipeline validieren
- [ ] Parallele/duplizierte Preflight-Pfade entfernen

### classes/local/wbagent/queue/queue_manager.php
- [ ] Unveraendert beibehalten
- [ ] Optional phase_trace-Metadaten je queue item erweitern
- [ ] Legacy-Queue-Strukturen nebenher ausschliessen

### classes/local/wbagent/executor.php
- [ ] Unveraendert beibehalten
- [ ] Keine fachliche Neuinterpretation von Commands zulassen

## 2.7 Synchronizer und Finalisierung

### classes/local/wbagent/services/finalization_classifier.php
- [ ] Als Pflicht-Gate unveraendert beibehalten
- [ ] Heuristische LLM-Finalisierungsentscheidung entfernen

### classes/local/wbagent/services/synchronizer_routing_service.php
- [ ] Vom Planner-Step-Konzept entkoppeln
- [ ] Dedizierten synchronizer invoke-Pfad nutzen (eigener prompt/profile/action)
- [ ] Reuse von simple_retrieval als Synchronizer-Ersatz entfernen

### classes/local/wbagent/services/synchronizer_prompt_builder.php (neu oder bestehende Logik extrahieren)
- [ ] Eigenes Synchronizer-Profil kapseln (kein Planner-Prompt-Reuse)
- [ ] Sprach-/Präsentationsregeln nur im Synchronizer-Build pflegen

### classes/local/wbagent/services/synchronizer_input_builder.php
- [ ] phase_trace (A/B/C) und execution_feedback standardisiert einbauen
- [ ] Task-Discovery-Daten aus Sync-Input fernhalten

### classes/local/wbagent/services/synchronizer_output_contract.php
- [ ] Als Pflicht-Contract unveraendert erzwingen
- [ ] Bypass-Pfade fuer Sync-Output entfernen

## 3. Harte Altcode-Entfernung (ohne Migration)

- [ ] Monolithische Planner-Pfade (Task-Auswahl + Parameterbau in einem Schritt) entfernen
- [ ] step_type-only Planner-Normalisierung als Hauptsteuerung entfernen
- [ ] Prompt-Templates fuer alte Ein-Schritt-Entscheidung entfernen
- [ ] Legacy-Fallbacks entfernen, die Discovery ueberspringen und Full-Task-Catalog direkt in einen Planner-Call kippen
- [ ] ad-hoc Family-Scoring ausserhalb Discovery-Services entfernen
- [ ] Synchronizer-Routing ueber Planner-Step-Reuse entfernen

## 4. Umsetzungsreihenfolge mit Gate-Kriterien

- [ ] Phase-Enums und Prompt-Profile einfuehren
	- Gate: routing und prompt-profile laufen auf discovery/selection/construction statt tool_call_parse/simple_retrieval
- [ ] Orchestrator process() in drei Phasenmethoden aufteilen
	- Gate: drei getrennte invoke()-Calls mit eigenem source-Tag
- [ ] Discovery auf Family Registry + Family Ranking live umstellen
	- Gate: Selection erhaelt nur family-begrenzte Task-Menge
- [ ] Selection + Construction strikt trennen
	- Gate: Construction sieht genau eine selektierte Task
- [ ] Interpreter auf phase contracts umbauen
	- Gate: interpret_phase_output() liefert je Phase valides DTO
- [ ] Synchronizer vom Planner-Step entkoppeln
	- Gate: eigener Synchronizer-Routepfad mit generate_agent_reply und generate_text fallback
- [ ] Legacy-Code entfernen
	- Gate: kein monolithischer Planner-Pfad mehr erreichbar

## 5. Abnahme-Checkliste (technisch)

- [ ] LLM-Debug-Log zeigt drei getrennte Planner-Calls (discovery, selection, construction)
- [ ] Family-Ranking-Output ist in Discovery-Telemetrie sichtbar und beeinflusst Folgephase
- [ ] Zwei Discovery-Modi sind testbar aktiv:
	- mit Wunderbyte+Embeddings -> semantische Family-Suche
	- ohne Embeddings/Provider -> slim non-semantischer Family-Pfad
- [ ] Kein Codepfad erzeugt Task-Wahl und finale Parameter im selben Phase-Output
- [ ] Decision/Preflight/Queue/Executor bleiben deterministisch unveraendert
- [ ] Synchronizer erzeugt nur Message-Polish und nie Commands
- [ ] planner_trace_history und phase_trace sind pro Turn persistent und konsistent
- [ ] bookingextension_agent_testsuite laeuft ohne Regression

## 6. Nicht-Ziele (Explizit ausgeschlossen)

- [ ] Keine Migration alter Datenstrukturen implementieren
- [ ] Keine Rueckwaertskompatibilitaet fuer alte Planner-JSON-Formate einbauen
- [ ] Keine Parallel-Loesung mit gleichzeitig aktivem Alt- und Neu-Pfad akzeptieren

## 7. Startfreigabe (letzter Tiefencheck)

### 7.1 Ist-Code-Abdeckung gegen Zielplan (verifiziert)
- [x] Kern-Orchestrierung vorhanden und analysiert: orchestrator.php, interpreter.php, agent_runtime.php
- [x] Discovery/Family-Bausteine vorhanden und analysiert: family_registry_service, family_signal_ranker, family_ranker, context_prior_builder, discovery_stage_controller/policies
- [x] Dualer Embeddings-Pfad vorhanden und analysiert: embeddings_readiness_service + family_embeddings_retrieval_service
- [x] Getrennter Selection/Construction-Baustein vorhanden und analysiert: task_selector + parameter_constructor + parameter_contract_validator
- [x] planner_trace_history im Ist-Code nachgewiesen

### 7.2 Noch nicht im Zielzuschnitt nachgewiesen (vor Start einplanen)
- [ ] Explizite Orchestrator-Methoden run_discovery_phase()/run_selection_phase()/run_construction_phase()
- [ ] Explizite Interpreter-Schnittstelle interpret_phase_output(raw, phase, context)
- [ ] Explizite Bausteine phase_prompt_bundle_builder, planner_result_composer, synchronizer_prompt_builder
- [ ] Durchgaengige phase_trace-Persistenz (gleiches Schema in Runtime/Store/Sync)

### 7.3 Startentscheidung
- [x] GO fuer Umsetzung ist gegeben: Analyse ist tief genug, um mit dem Refactor kontrolliert zu starten
- [ ] GO-Live nur nach Erfuellung der Gate-Kriterien aus Abschnitt 4 und Abnahme aus Abschnitt 5

### 7.4 Harte Vorstart-Checks fuer Sprint-Start
- [ ] Baseline-Testlauf bookingextension_agent_testsuite dokumentieren (aktuellen roten/gruenen Stand festhalten)
- [ ] Reihenfolge fixieren: zuerst Orchestrator-Phasensplit, dann Interpreter-Phasenkontrakte, dann Composer/Prompt-Builder, danach Legacy-Entfernung
- [ ] Doppelpfad (mit/ohne Embeddings bzw. mit/ohne aiprovider_wunderbyte) als nicht verhandelbares Akzeptanzkriterium im PR-Template festhalten
- [ ] Nach jedem Teilpaket Telemetrie pruefen: planner_trace_history + phase_trace + Pfadwahl with_embeddings/no_embeddings

## 8. Umsetzungsstart (sofort ausführbar)

### 8.1 Paketplan fuer schnelle Umsetzung

#### Paket 1: Orchestrator-Phasensplit ohne Legacy-Loeschung
- [ ] orchestrator.php: run_discovery_phase(), run_selection_phase(), run_construction_phase() einfuehren
- [ ] orchestrator_routing_service.php: phase-basierte API parallel zu alter API bereitstellen
- [ ] orchestrator_prompt_profile_service.php: phase-Profile discovery/selection/parameter_construction einziehen
- [ ] Gate fuer Paket 1:
	- process() ruft drei getrennte invoke()-Calls auf
	- Doppelpfad bleibt erhalten (wunderbyte+embeddings vs slim no-embeddings)
	- Kein Legacy-Code geloescht

#### Paket 2: Interpreter-Phasenkontrakte + Composer
- [ ] interpreter.php: interpret_phase_output(raw, phase, context) einfuehren
- [ ] services/planner_result_composer.php neu anlegen oder Logik extrahieren
- [ ] conversation_store.php: planner_trace_history und phase_trace schema-konsistent schreiben
- [ ] Gate fuer Paket 2:
	- Unified planner_result wird nur noch aus 3 Phasen komponiert
	- Kein Pfad erzeugt Task-Wahl und finale Parameter in einer Antwort

#### Paket 3: Synchronizer entkoppeln
- [ ] services/synchronizer_prompt_builder.php neu anlegen oder Logik extrahieren
- [ ] synchronizer_routing_service.php auf dedizierten Sync-Invoke-Pfad finalisieren
- [ ] synchronizer_input_builder.php auf phase_trace + execution_feedback standardisieren
- [ ] Gate fuer Paket 3:
	- Synchronizer nutzt kein Planner-Step-Reuse
	- Synchronizer mutiert niemals Commands

#### Paket 4: Legacy-Entfernung und Cleanup
- [ ] Monolithische Planner-Pfade entfernen
- [ ] step_type-only Routing als Hauptsteuerung entfernen
- [ ] Alte Ein-Schritt-Prompt-Templates entfernen
- [ ] Gate fuer Paket 4:
	- Kein alter Einmal-Call-Pfad erreichbar
	- Abnahme aus Abschnitt 5 komplett gruen

### 8.2 Test- und Telemetrie-Takt pro Paket
- [ ] Nach jedem Paket: bookingextension_agent_testsuite laufen lassen und Ergebnis dokumentieren
- [ ] Nach jedem Paket: Telemetriebelege sichern (planner_trace_history, phase_trace, with_embeddings/no_embeddings)
- [ ] Bei rotem Teststand nur regressionsrelevante Delta-Tests behandeln; bestehende Fremdfehler separat protokollieren

### 8.3 Sofortiger Start-Task (naechster Commit)
- [ ] Paket 1 starten mit minimal-invasiver Methodenextraktion in orchestrator.php
- [ ] Nur Verdrahtung, keine Legacy-Loeschung im ersten Commit
- [ ] Commit-Gate: Flowchart-Konsistenz und Doppelpfad explizit im PR-Text bestaetigt

---
Diese Checkliste ist als Arbeitsboard gedacht: Family-Ranking bleibt Kernbestandteil, Legacy-Monolith wird vollstaendig entfernt.