# Synchronizer Migration Path

## Ziel

Vom aktuellen Ist-Zustand mit halb vorhandenen Final-Reasoning-/Final-Synthesis-Relikten zu einer expliziten Architektur mit:

- planner-only loop fuer Routing, Commands und Execution-Entscheidungen
- deterministic finalization_classifier
- synchronizer als separater final user-facing layer
- mehr geloeschtem Altcode als neu hinzugefuegtem Code

## Kurzfassung

Leitregel: erst umhaengen, dann loeschen.

Wir bauen keinen zweiten grossen Orchestrator neben den bestehenden Code. Stattdessen extrahieren wir das Wiederverwendbare aus dem alten unvollstaendigen Synchronizer-Ansatz, verdrahten es neu, und loeschen danach die alten Finalisierungs-Pfade aus dem Planner.

## Ist-Befund

### Bereits sinnvoll wiederverwendbar

1. `llm_call_service`
- Unterstuetzt bereits `generate_agent_reply`.
- Soll bestehen bleiben als gemeinsamer LLM-Adapter fuer Planner und Synchronizer.

2. `assistant_state_guidance_service`
- Baut bereits Follow-up-/State-Bloecke.
- Kann als Input-Baustein fuer einen kuenftigen `synchronizer_input_builder` wiederverwendet werden.

3. Teile von `orchestrator.php`
- Die Prompt-Erzeugung fuer `generate_agent_reply` ist fachlich verwertbar.
- Die Verarbeitung von `planner_trace_history` und `append_planner_traces_and_observations()` ist als Synchronizer-Kontext brauchbar.

4. Test-Provider-Setup in `tests/agent/abstract_agent_testcase.php`
- Registrierung von `aiprovider_wunderbyte\\aiactions\\generate_agent_reply` bleibt nuetzlich.

### Klar alte / unvollstaendige Synchronizer-Relikte

1. alte Runtime-Policy-Logik (bereits geloescht)
- Kein produktiver Aufrufer mehr vorhanden.
- Historischer Altpfad aus der fruehen Migrationsphase.

2. Legacy-Finalisierungs-Step-Typen in `orchestrator.php`
- In der Runtime-Schleife derzeit nicht aktiv genutzt.
- Leben hauptsaechlich als Altstruktur im Orchestrator weiter.

3. Final-Reasoning-/Final-Synthesis-Verzweigungen in `orchestrator_routing_service.php`
- Heute Teil des Planner-Orchestrators, aber konzeptionell kuenftig Synchronizer-Zustaendigkeit.

4. Final-Reasoning-/Final-Synthesis-Verzweigungen in `orchestrator_prompt_profile_service.php`
- Ebenfalls Altstruktur aus der nicht fertig integrierten Zweiphasen-Idee.

5. Synthesis-/Final-Reasoning-Sonderfaelle in `prompt_policy_builder.php`
- Fuer den kuenftigen Zielzustand zu viel Finalisierungslogik im Planner.

6. Synthesis-/Final-Reasoning-Logik direkt in `orchestrator.php`
- Vermischt Planner- und Final-Reply-Zustaendigkeiten in einer sehr grossen Datei.

## Migrationsprinzipien

1. Keine Big-Bang-Umschaltung.
2. Erst neuen Synchronizer minimal einfuehren.
3. Planner-Runtime erst dann umhaengen, wenn Klassifikation testbar ist.
4. Nach jeder erfolgreichen Umhaengung den alten Pfad sofort entfernen.
5. Keine doppelte Dauerarchitektur: Alt- und Neulogik duerfen nur kurz parallel existieren.

## Zielstruktur nach Migration

### Planner behaelt
- `tool_call_parse`
- `simple_retrieval`
- planner prompt policies
- planner task catalog selection
- interpreter fuer planner JSON
- decision service
- preflight / queue / executor

### Neuer Finalization Layer bekommt
- `finalization_classifier`
- `synchronizer_input_builder`
- `synchronizer_service`
- `synchronize_template_only()`
- `synchronizer_routing_service`
- final JSON validation / rollback rule

### Planner verliert
- legacy finalization
- `final_synthesis`
- final reply routing branches
- synthesis prompt branches
- alte Runtime-Policy-Logik
- final-synthesis-only prompt policy branches

## Empfohlene Umsetzung in Phasen

### Phase 1: Deterministischen Finalization Classifier einfuehren

Neu hinzufuegen:
- `classes/local/wbagent/services/finalization_classifier.php`

Aufgabe:
- Eingabe: normalized result array
- Ausgabe: `direct_final`, `template_only`, `llm_polish`
- Regeln strikt gemass Flowchart-Matrix

Wichtig:
- Noch kein echter Synchronizer-LLM-Call
- Zunaechst nur classifier + Tests

Parallel loeschen / aufraeumen:
- Nichts Hartes loeschen in Phase 1
- Altpfad direkt entfernen, wenn wirklich ohne Referenzen

Tests:
- neue reine Unit-Tests fuer Klassifikation
- positive und negative Matrix-Faelle

### Phase 2: Minimalen Synchronizer ausserhalb des Planners einfuehren

Neu hinzufuegen:
- `classes/local/wbagent/services/synchronizer_service.php`
- optional `classes/local/wbagent/services/synchronizer_input_builder.php`

Reuse:
- `llm_call_service`
- `assistant_state_guidance_service`
- `planner_trace_history`-Kontext aus `orchestrator.php`
- `generate_agent_reply` action

Umsetzung:
- `synchronize_template_only()` zuerst
- danach `synchronize_llm_polish()`
- strikte Rollback-Regel: bei strukturellem Drift sofort Planner/Source-Result behalten

Noch nicht loeschen:
- Final-Synthesis-Prompttext im Orchestrator darf voruebergehend Quelle fuer Extraktion bleiben

### Phase 3: Runtime auf classifier + synchronizer umhaengen

Aendern:
- `agent_runtime.php`

Neue Abfolge:
- planner loop endet
- `finalization_classifier` entscheidet
- `direct_final` => sofort persistieren
- `template_only` => synchronizer template path
- `llm_polish` => synchronizer llm path

Nach erfolgreicher Umhaengung sofort entfernen:
- alle planner-internen Annahmen, dass Finalisierung ueber alte Finalisierungs-Step-Typen laeuft

### Phase 4: Alten unvollstaendigen Synchronizer-Code aus Planner entfernen

Dateien / Bereiche zum Aufraeumen:

1. alte Runtime-Policy-Logik (bereits geloescht)
- komplett loeschen

2. `classes/local/wbagent/orchestrator.php`
- alte Finalisierungs-Step-Typen loeschen
- `STEP_TYPE_FINAL_SYNTHESIS` loeschen
- `WB_ACTION_GENERATE_AGENT_REPLY` nur behalten, falls Orchestrator weiterhin shared constants liefern soll; sonst in neuen Synchronizer verschieben
- final reasoning / synthesis branches in `get_default_initial_prompt_template_for_action()` entfernen oder in Synchronizer extrahieren
- final reasoning blocks in `build_prompt()` entfernen
- `build_local_output_contract_block()` von final_synthesis entkoppeln
- alle nicht mehr benoetigten prompt/profile branches entfernen

3. `classes/local/wbagent/services/orchestrator_routing_service.php`
- finalreasoning/finalsynthesis properties entfernen
- routing nur noch fuer planner steps behalten
- final-reply routing in neuen `synchronizer_routing_service` verschieben

4. `classes/local/wbagent/services/orchestrator_prompt_profile_service.php`
- finalreasoning/finalsynthesis properties entfernen
- step-type Normalisierung nur fuer planner steps behalten
- final prompt config keys in Synchronizer-Service verschieben

5. `classes/local/wbagent/prompt_policy_builder.php`
- final_synthesis policy branches loeschen
- obsolete follow-up policy in Synchronizer/Input-Builder verschieben oder entfallen lassen
- Planner-Policy auf tool_call_parse + simple_retrieval reduzieren

6. `classes/local/wbagent/services/catalog/adaptive_task_catalog_service.php`
- recency branches fuer alte Finalisierungs-Step-Typen entfernen, falls nach Migration ungenutzt

7. Tests
- alte Testannahmen fuer Finalisierung entfernen
- `abstract_agent_testcase.php`: `generate_agent_reply` provider config behalten
- neue Tests fuer classifier + rollback + template-only path + llm-polish path ergaenzen

## Konkrete Delete-First-Kandidaten

Direkt loeschbar, sobald Phase 1 bestaetigt ist:
- alte Runtime-Policy-Logik

Direkt loeschbar, sobald Phase 3 laeuft:
- finale step-type constants in `orchestrator.php`
- final routing branches in `orchestrator_routing_service.php`
- final prompt branches in `orchestrator_prompt_profile_service.php`
- final_synthesis-Zweige in `prompt_policy_builder.php`

## Reihenfolge fuer minimales Risiko

1. `finalization_classifier` neu + Tests
2. `synchronize_template_only()` neu + Tests
3. `synchronize_llm_polish()` neu + Rollback-Tests
4. `agent_runtime.php` auf neuen Pfad umhaengen
5. alte Runtime-Policy-Logik loeschen
6. alte Finalisierungs-Step-Typen aus Orchestrator und Helper-Services entfernen
7. Tests und Fixtures vereinfachen

## Was bewusst NICHT doppelt gebaut werden soll

Nicht tun:
- keinen zweiten kompletten Orchestrator bauen
- keine alte Finalisierungs-Runtime reaktivieren
- keinen Planner mit embedded finalization policy behalten
- keine parallele Langzeitexistenz von altem Synthesis-Pfad und neuem Synchronizer

## Netto-Bilanz-Ziel

Ziel: mehr Code loeschen als neu hinzufuegen.

Realistische Erwartung ueber alle Phasen:

Hinzugefuegt:
- `finalization_classifier.php`
- `synchronizer_service.php`
- optional `synchronizer_input_builder.php`
- neue fokussierte Tests

Geloescht / geschrumpft:
- alte Runtime-Policy-Logik komplett
- alte Finalisierungs-Pfade in `orchestrator.php`
- entsprechende Verzweigungen in `orchestrator_routing_service.php`
- entsprechende Verzweigungen in `orchestrator_prompt_profile_service.php`
- synthesis/final branches in `prompt_policy_builder.php`
- unnoetige Testaltlasten

### Erwartete Netto-Bilanz

Konservativ geschaetzt:
- Neu: ca. 250 bis 450 Zeilen
- Geloescht: ca. 450 bis 800 Zeilen
- Netto: minus 200 bis minus 350 Zeilen

### Mindestregel fuer jede Phase

Jede Phase soll eine positive Delete-Bilanz anstreben:
- Wenn in einer Phase 120 Zeilen neu dazukommen, sollen in derselben oder direkt folgenden Phase mindestens 150 Zeilen Altlogik verschwinden.

## Definition von Erfolg

Die Migration ist erst fertig, wenn alle drei Aussagen wahr sind:

1. `agent_runtime.php` kennt keinen impliziten alten Finalisierungs-Pfad mehr.
2. Planner-Code enthaelt keine Finalisierungslogik mehr, ausser strukturierter Result-Erzeugung.
3. Der neue Synchronizer ist der einzige Ort fuer user-facing Nachbearbeitung, mit deterministischem classifier davor.
