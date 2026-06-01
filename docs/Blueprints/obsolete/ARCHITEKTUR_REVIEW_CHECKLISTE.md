# Architektur-Review-Checkliste (vor Code-Review)

Zweck: Diese Checkliste dient als Arbeitsdokument fuer den Review des Agent-Frameworks auf Basis des Flowcharts, bevor Produktivcode bewertet oder angepasst wird.

Scope: mod/booking/bookingextension/agent

## Gesamtstatus

- [x] Punkt 1 abgeschlossen
- [x] Punkt 2 abgeschlossen
- [x] Punkt 3 abgeschlossen
- [x] Punkt 4 abgeschlossen
- [x] Punkt 5 abgeschlossen
- [x] Punkt 6 abgeschlossen
- [x] Punkt 7 abgeschlossen
- [x] Punkt 8 abgeschlossen
- [x] Punkt 9 abgeschlossen
- [x] Punkt 10 abgeschlossen

---

## Bewertungsrahmen (pro Punkt)

Beantworte fuer jeden Bereich die 5 Abschlussfragen mit Ja oder Nein:

1. Ist die aktuelle Verantwortlichkeit eindeutig?
2. Existiert Doppelzustaendigkeit?
3. Wuerde ein neuer Entwickler die Logik innerhalb von 5 Minuten verstehen?
4. Entsteht bei Erweiterungen wahrscheinlich ein weiterer Sonderfall?
5. Kann die Komponente vereinfacht werden, ohne Funktionalitaet zu verlieren?

Refactoring-Regel:

- Wenn mindestens 2 Antworten auf die Abschlussfragen negativ ausfallen, als Refactoring-Kandidat markieren.

Vorlage:

- Q1 Eindeutig: [ ] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [ ] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [ ] Ja [ ] Nein
- Refactoring-Kandidat: [ ] Ja [ ] Nein
- Notizen:

---

## 1) D_CONFIRM vs D_MUTATE

### Flowchart-Erstbefund

- D_CONFIRM und D_MUTATE laufen beide in PP_RUN.
- confirmation_request wirkt im Diagramm wie ein separater Response-Typ mit eigener Route.
- Hohe Naehe der beiden Pfade, potenzielle Doppelpflege.

### Checkpoints

- [x] Entscheidungseintritt fuer response_type = confirmation_request dokumentieren.
- [x] Entscheidungseintritt fuer response_type = task_call mutating dokumentieren.
- [x] Nachweisen, ob beide denselben Preflight-Pfad und dieselbe Statusableitung verwenden.
- [x] Pruefen, ob Confirmation als Preflight-Ergebnis (soft_block/blocked_confirmation) modellierbar ist.
- [x] Migrationsskizze fuer einen gemeinsamen Mutationspfad notieren.

### Erwartetes Zielbild

- Ein gemeinsamer Mutationspfad.
- Confirmation als Zustand/Ergebnis, nicht als separater Hauptpfad.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Reale Route ist bereits teilweise zusammengefuehrt. Mutierende task_call werden auf confirmation_request gehoben (agent_decision_service::process), dann einheitlich ueber handle_preflight verarbeitet. confirm_pending fuehrt ebenfalls in confirmation_request mit erneuter Preflight-Validierung. Der semantische Split bleibt jedoch in Prompt-Contract + Response-Type-Modell bestehen (orchestrator + initial_system_prompt). Vereinfachung moeglich: mutating task_call als ein Hauptpfad mit confirmation als Queue/Preflight-Zustand.

---

## 2) Reihenfolge D_DUPL, D_AMBIG, D_MUT_GUARD

### Flowchart-Erstbefund

- Die Kette D_CONF -> D_DUPL -> D_AMBIG -> D_MUT_GUARD liegt vor D_ROUTE.
- Dadurch durchlaufen Read-Only-Pfade implizit mutierende Sicherheitsstufen.

### Checkpoints

- [x] Read-Only-Requests identifizieren, die unnutz durch Duplicate/Ambiguity/Guard laufen.
- [x] Duplicate-Checks auf Mutationen begrenzen oder bewusst begruenden.
- [x] Ambiguity-Resolution nach Task-Art segmentieren.
- [x] Mutation Guard nur fuer mutierende Kontexte validieren.
- [x] Routing-Skizze erstellen: ReadOnly direkt zu Execute, Mutating ueber Safety-Kette.

### Erwartetes Zielbild

- ReadOnly: direkte Execution.
- Mutating: Duplicate -> Ambiguity -> Mutation Guard -> Preflight.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [ ] Ja [x] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [ ] Ja [x] Nein
- Notizen: Das Flowchart ist hier veraltet. Im Code gibt es keine explizite D_DUPL->D_AMBIG->D_MUT_GUARD-Kette mehr. Die zentrale Reihenfolge ist: response_type normalisieren, mutating task_call nach confirmation_request heben, split in readonly/mutating, readonly direkt ausfuehren, mutating preflighten. Lookup-Mutation-Guard ist als einzelner Schutz vorhanden (core.is_lookup_request + mutating commands).

---

## 3) Retry-Systeme konsolidiert betrachten

### Flowchart-Erstbefund

- Planner-Retry (D_RETRY), Queue-Retry (Q_RETRY), Execution-Transient und Budget-Check sind verteilt.
- Kein expliziter globaler attempt_budget-Knoten sichtbar.

### Checkpoints

- [x] Alle Retry-Arten und Zaehler pro Turn inventarisieren.
- [x] Kombinierte Worst-Case-Anzahl von Wiederholungen pro Turn herleiten.
- [x] Globales Attempt-Budget definieren (inkl. Planner, Queue, Executor, LLM).
- [x] Sichtbarkeit im State/Telemetry planen (ein Counter, mehrere Quellen).
- [x] Abbruchstrategie bei Budget-Erschoepfung harmonisieren.

### Erwartetes Zielbild

- Zentraler attempt_budget-Mechanismus ueber alle Retry-Arten.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Es existieren mehrere Retry-Ebenen ohne zentralen Gesamtzaehler: runtime loop budget (MAX_LOOP_STEPS), preflight retry_hint (preflight_execution_gate MAX_RETRIES), queue retry_waiting Metadaten, execution retry in confirm_run_service via build_retry_decision. Kein globaler attempt_budget ueber alle Ebenen.

---

## 4) Position von GOAL_CHECK

### Flowchart-Erstbefund

- GOAL_CHECK haengt direkt an OB_OUT (Observation-String).
- Bezug auf Planungszustand ist nicht explizit modelliert.

### Checkpoints

- [x] Pruefen, welche Inputs Goal Detection tatsaechlich verwendet.
- [x] Nachweisen, ob agent_state/planner_state einbezogen wird.
- [x] Begruendbare Kriterien fuer Ziel erreicht versus weiter planen dokumentieren.
- [x] Deterministische Entscheidungsbasis definieren (z. B. state + observations).
- [x] Risikoanalyse fuer fruehes/zu spaetes Stoppen erstellen.

### Erwartetes Zielbild

- Goal Detection auf agent_state plus observations statt observations-only.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [ ] Ja [x] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [ ] Ja [x] Nein
- Refactoring-Kandidat: [ ] Ja [x] Nein
- Notizen: Expliziter GOAL_CHECK-Knoten existiert im Runtime-Code nicht mehr. Die Stop-Entscheidung erfolgt ueber response_type (execution_result vs nicht), budget_guard_allows_next_llm_call und Sufficiency-Regeln aus Prompt/Policy. agent_state wird fuer Observations/loop_results verwendet, aber kein isolierter Goal-Detector.

---

## 5) REFRESH-Position validieren

### Flowchart-Erstbefund

- FINAL_OUT -> REFRESH -> ENFORCE -> MPS koppelt Queue-Lifecycle an User-Response-Lifecycle.

### Checkpoints

- [x] Begruenden, warum Refresh nach finaler Antwort liegt.
- [x] Pruefen, ob Final Response fachlich vom Refresh abhaengt.
- [x] Alternative Reihenfolge evaluieren (Refresh vor Finalisierung oder entkoppelt).
- [x] Separates Lifecycle-Diagramm fuer Queue versus User-Reply skizzieren.
- [x] Seiteneffekte auf Konsistenz und Latenz bewerten.

### Erwartetes Zielbild

- Klare Trennung von Queue-Lifecycle und User-Response-Lifecycle.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [ ] Ja [x] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [ ] Ja [x] Nein
- Refactoring-Kandidat: [ ] Ja [x] Nein
- Notizen: Der im Flowchart gezeigte REFRESH-Schritt nach FINAL_OUT ist im aktuellen Runtime-Pfad nicht vorhanden. Persistenz laeuft ueber finalize_and_persist_result direkt in message_persistence_service; Queue-Refresh ist kein finaler Response-Zwangsschritt.

---

## 6) Konsistenz Thread Metadata

### Flowchart-Erstbefund

- CS7/CS8 modellieren generische Metadata.
- CS14 markiert user_input_lang als besonderen Zustand.

### Checkpoints

- [x] Datenmodell fuer user_input_lang eindeutig festlegen (Metadata oder eigener State).
- [x] Lese-/Schreibstellen von user_input_lang vollstaendig erfassen.
- [x] Konfliktregeln festlegen, falls metadata und state divergieren.
- [x] API-Vertrag fuer Sprachquelle pro Turn dokumentieren.
- [x] Migration/Kompatibilitaet fuer bestehende Threads festhalten.

### Erwartetes Zielbild

- Entweder vollstaendig Metadata oder eigener First-Class-State, nicht beides.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [ ] Ja [x] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [ ] Ja [x] Nein
- Refactoring-Kandidat: [ ] Ja [x] Nein
- Notizen: user_input_lang ist aktuell normales Thread-Metadata (conversation_store get/set_thread_metadata_value), ausgewertet in language_policy_service mit klarer Prioritaetskette. Kein separater First-Class-State im Runtime-Modell gefunden.

---

## 7) Verantwortlichkeiten im Interpreter

### Flowchart-Erstbefund

- Interpreter macht parse, classify, check_structure, normalize.
- Gleichzeitig existiert domain_normalizer_hook.
- Moegliche Mischverantwortung bei Transformationen.

### Checkpoints

- [x] Eindeutig festlegen, wo Parsing endet.
- [x] Eindeutig festlegen, wo Normalisierung beginnt und endet.
- [x] Alle Wertveraenderungen je Stage dokumentieren.
- [x] Schnittstelle Interpreter zu Domain-Hook als Vertrag definieren.
- [x] Nachvollziehbarkeit sichern: fuer jedes Feld Herkunft und Mutation protokollierbar.

### Erwartetes Zielbild

- Entweder klarer Mehrstufenpfad (Interpreter -> DTO Normalizer -> Transformation) oder vollstaendig normalisierte Interpreter-Ausgabe.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Interpreter ist stark gemischt: parse/sanitize, response-healing, command-normalisierung, stage-3 check_structure, Datumsnormalisierung, task_input_normalizer-Delegation ueber task_registry. Die Hook-Schnittstelle ist da, aber die Transformationsgrenzen sind fuer neue Entwickler schwer sofort nachvollziehbar.

---

## 8) Doppelte Idempotenzschichten

### Flowchart-Erstbefund

- Queue: gleiche Signatur liefert existierenden Eintrag.
- Executor: run_exists_other_than prueft erneut gegen run/idempotency key.
- Doppelter Schutz ist plausibel, aber Abgrenzung unklar.

### Checkpoints

- [x] Fehlerklasse und Zweck jeder Idempotenzschicht separat beschreiben.
- [x] Nachweisen, ob beide Schichten unabhaengig triggern koennen.
- [x] Prioritaet und erwartete Reihenfolge bei Kollisionen dokumentieren.
- [x] Operator-Transparenz verbessern (warum nicht ausgefuehrt?).
- [x] Diagnoseausgaben harmonisieren (gleiche Terminologie fuer Skip/Already Executed).

### Erwartetes Zielbild

- Klare Verantwortlichkeit und Abgrenzung beider Schichten.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Queue-Idempotenz (input_signature in enqueue_command) und Executor-Idempotenz (run_exists_other_than) schuetzen verschiedene Ebenen, koennen aber beide Nicht-Ausfuehrung verursachen. Die technische Trennung ist sinnvoll, die Diagnose fuer Betreiber ist jedoch nicht einheitlich und kann verwirren.

---

## 9) Behandlung von Q_SKIP

### Flowchart-Erstbefund

- Q_SKIP fuehrt direkt zu FAIL_OUT.
- Skip wird damit semantisch wie Failure behandelt.

### Checkpoints

- [x] Skip-Semantik definieren: erwartet, neutral oder fehlerhaft.
- [x] Pruefen, ob Skip als Observation in Replan fliessen sollte.
- [x] Auswirkungen auf User-Kommunikation und Monitoring bewerten.
- [x] Unterscheidung zwischen terminal fail und logical skip im Outcome-Modell festlegen.
- [x] Rueckwaertskompatibilitaet fuer bestehende Auswertungen pruefen.

### Erwartetes Zielbild

- Q_SKIP bevorzugt als Observation -> Replan, nicht pauschal als Fail.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Q_SKIP->FAIL_OUT ist im Code nicht 1:1 als Knoten abgebildet, aber Skip wird de facto als Failure behandelt (execution_result_has_failures zaehlt skipped als Fehler; dependents werden mit to_skipped markiert). Ein eigenstaendiger Replan-Pfad fuer logical skip ist nicht explizit.

---

## 10) Safety-Logik buendeln

### Flowchart-Erstbefund

- Duplicate, Ambiguity, Mutation Guard und Confirmation sind verteilt.
- Tendenz zu weiterer Fragmentierung bei neuen Sonderfaellen.

### Checkpoints

- [x] Alle Safety-Pruefungen als Pipeline-Inventar erfassen.
- [x] Reihenfolge und Ein-/Ausgangsvertraege je Safety-Schritt definieren.
- [x] Einheitlichen Safety-Kontext (Input/Output DTO) entwerfen.
- [x] Erweiterungsregeln fuer neue Safety-Cases festlegen.
- [x] Positionierung vor Preflight und Execution eindeutig absichern.

### Erwartetes Zielbild

- Gemeinsame Safety Pipeline vor Preflight/Execution.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Safety ist funktional vorhanden, aber verteilt ueber interpreter, decision_service, preflight_pipeline, queue_transition_service und confirm_run_service. Ohne zentrales Safety-Pipeline-DTO steigt das Risiko fuer neue Sonderfaelle in mehreren Schichten.

---

## Konsolidierter Review-Status

- Refactoring-Kandidaten: Punkt 1, 3, 7, 8, 9, 10.
- Kein akuter Refactoring-Zwang nach aktuellem Stand: Punkt 2, 4, 5, 6.
- Wichtigster Meta-Befund: Das Flowchart bildet den aktuellen Runtime-Code nur teilweise ab (insbesondere D_DUPL/D_AMBIG-Kette, GOAL_CHECK-Node, REFRESH-Pfad). Vor Code-Refactoring sollte das Diagramm zuerst auf Ist-Stand gebracht werden.

---

## Priorisierung fuer den Start

Empfohlene Reihenfolge fuer den Code-Review:

1. Punkt 1 (D_CONFIRM vs D_MUTATE)
2. Punkt 2 (Safety-Reihenfolge vor D_ROUTE)
3. Punkt 3 (Retry-Budget)
4. Punkt 9 (Q_SKIP-Semantik)
5. Punkt 8 (Idempotenz-Abgrenzung)
6. Punkt 7 (Interpreter-Verantwortung)
7. Punkt 6 (user_input_lang Modell)
8. Punkt 5 (REFRESH-Entkopplung)
9. Punkt 4 (Goal Detection Inputs)
10. Punkt 10 (finale Safety-Pipeline Konsolidierung)

Hinweis: Bei jedem Punkt zuerst Ist-Vertrag dokumentieren, dann erst Refactoring-Vorschlag ableiten.