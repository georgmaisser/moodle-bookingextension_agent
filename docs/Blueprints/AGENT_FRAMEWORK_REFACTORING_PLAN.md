# Agent Framework Refactoring Plan

Stand: 2026-05-25
Scope: /mod/booking/bookingextension/agent
Status: In Umsetzung, Teilfortschritt codebasiert verifiziert

Hinweis:
- Haken sind in diesem Dokument nur dort gesetzt, wo der Stand direkt ueber Code, Signaturen, Call-Sites oder Dateistatus belegt ist.

## 1. Ziel und Entscheidung

Ziel ist ein deterministisches, erweiterbares Agent-Framework ohne heuristische Nebenpfade.

Architektur-Ziele:
- Keine Task-Erkennung per Fallback-Logik im Framework.
- Explizite Task-Contracts fuer den Planner.
- Preflight nur noch als v2-Contract, keine Legacy-Doppelausgabe.
- Execution-Guard statt zweitem Voll-Preflight.
- Subtasks als Queue-Primitive via spawn_commands + depends_on.
- Interne Agent-Vertraege verwenden Moodle contextid als autoritative Scope-ID fuer Runtime, Queue, Confirmation, Preflight und Execution.
- Third-Party-Onboarding: neue Tasks ueber task_provider_interface, expliziten Prompt-Contract, Namespace, Version und Capability-Deklaration ohne Framework-Eingriff einhaengbar.
- Sprachverhalten: LLM-generierte und deterministische Framework-Antworten folgen der Sprache der letzten User-Anfrage (z.B. zh/de/en), ohne sprachspezifische Routing-Heuristiken.
- Mutationen sind standardmaessig bestaetigungspflichtig; Session-Autoconfirm wird ueber ai_send_message und ai_confirm_run erlaubt und ist an userid + contextid gebunden.
- Task-Freischaltung mehrstufig: Runtime-Enablement + Aktiv-Flag + Context-Validierung + task-spezifische Capabilities.
- Zielbild fuer komplexe Moodle-Workflows: robuste Multi-Step- und Spawn-Ketten ueber mehrere Domainen mit Artefaktreferenzen, Output-Bindings und spaetem Preflight fuer abhaengige Schritte.

Entscheidung:
- Kein Full-Rewrite von Null.
- Harte Kern-Refaktorierung im bestehenden Code mit aggressivem Entfernen alter Pfade.

Datenbankschema-Entscheidung (verbindlich fuer diese Welle):
- Keine Migrationspfade und kein Backfill.
- Keine Anpassungen in upgrade.php.
- Neue DB-Struktur wird direkt in install.xml definiert.
- Refactoring und Tests laufen gegen die neue install.xml-Basis.

## 2. Vollstaendige Inventur (durchgefuehrt)

Audit-Ergebnis:
- Dateien in classes/tests/docs: 220
- Klassen/Interfaces/Traits (plus Python-Modelklassen): 136

Inventurquellen:
- Automatische Dateiliste via find ueber classes, tests, docs.
- Symbol-Liste via grep auf class/interface/trait.
- Referenzpruefung kritischer Kandidaten via Textsuche.

Hinweis:
- Alle Dateien und Klassen wurden gesichtet.
- Im Plan sind nur die Dispositionen aufgefuehrt, die Aenderungsbedarf haben.
- Nicht genannte Komponenten bleiben zunaechst erhalten.

## 3. Komponenten, die wir nicht mehr benoetigen

### 3.1 Remove Now (hohe Sicherheit)

1) recovery_enrichment_service
- Datei: classes/local/wbagent/recovery_enrichment_service.php
- Grund: Verletzt das Ziel "deterministische Routing-Pfade" und erzeugt heuristische Task-Promotion.
- Aktuelle Kopplung: classes/local/wbagent/agent_decision_service.php
- Status im Zielbild: komplett entfernen.

2) agent_task_provider Interface (duplizierter Legacy-Vertrag)
- Datei: classes/local/wbagent/interfaces/agent_task_provider.php
- Grund: Keine produktive Referenz, task_provider_interface ist der aktive Vertrag.
- Status im Zielbild: entfernen.

3) booking_rules_agent_service (derzeit unbenutzt)
- Datei: classes/local/wbagent/booking/support/booking_rules_agent_service.php
- Grund: Keine produktive Referenz gefunden.
- Status im Zielbild: entfernen oder nur behalten, wenn in Phase 0 ein echter Callsite-Nachweis auftaucht.

4) Dead Imports auf nicht existente Task-Klassen
- Datei: classes/local/wbagent/core/tasks/list_actions_task.php
- Problem: Imports auf booking task classes, die im aktuellen Framework nicht existieren.
- Status im Zielbild: sofort bereinigen.

### 3.2 Remove Later (nach Stabilisierung)

1) planner_service
- Datei: classes/local/wbagent/planner_service.php
- Aktuell genutzt in: classes/local/wbagent/agent_decision_service.php
- Grund: Semantische Input-Enrichment-Logik kollidiert mit explizitem Preflight-v2-Prepare-Vertrag.
- Ziel: Nach Umstellung auf strict preflight prepared_input entfernen.

2) task_trigger_provider_interface taskbasierte Trigger
- Datei: classes/local/wbagent/interfaces/task_trigger_provider_interface.php
- Grund: Trigger sollen als Signalnormalisierung bestehen bleiben, aber nicht mehr als task->trigger Routingmechanik.
- Ziel: schrittweise Entkopplung, danach Interface/Implementierungen aus Tasks entfernen.

3) task_discovery Trigger-Provider-Zweig
- Datei: classes/local/wbagent/task_discovery.php
- Grund: Trigger-Provider-Zweig wird mit expliziten planner contracts obsolet.
- Ziel: Task-Discovery fuer Provider behalten, Trigger-Discovery entfernen.

4) adaptive_task_catalog_service (falls komplett durch planner_catalog_service ersetzt)
- Datei: classes/local/wbagent/adaptive_task_catalog_service.php
- Grund: Teilweise embeddings/heuristiklastig.
- Ziel: nur behalten, wenn strikt auf explizite Contracts reduziert.

5) embeddings optionale Schicht (produktstrategisch)
- Dateien:
  - classes/local/wbagent/embeddings_retrieval_service.php
  - classes/local/wbagent/embeddings_catalog_builder_service.php
  - classes/local/wbagent/embeddings_csv_repository.php
  - classes/local/wbagent/embeddings_readiness_service.php
  - classes/local/wbagent/embeddings_action_config_resolver.php
- Grund: optionaler Featurepfad, nicht Kernpfad des deterministischen Agent-Frameworks.
- Ziel: Entscheidung nach Stabilisierung der 3 Kernszenarien.

### 3.3 Test-/Dokumentationsartefakte, die nachziehen muessen

1) Recovery-spezifische Architekturannahmen
- Datei: tests/agent/permanent/contracts/agent_architecture_contract_test.php
- Grund: Enthaltene Erwartung auf UNKNOWN_TYPE-Recovery passt nicht mehr zum Zielbild.
- Status: bei Implementierung der neuen Decision-Regeln anpassen/ersetzen.

2) Falsch platzierte Integrationstests im Klassenbaum
- Datei: classes/local/wbagent/tests/integration_agent_framework_test.php
- Grund: Testdatei unter classes statt tests.
- Status: im Refactoring in tests/agent verschieben oder entfernen.

## 4. Keep Core (bleibt als Kern bestehen)

Diese Bereiche sind tragfaehig und bleiben als Basis erhalten:
- Runtime/Orchestrierung/Interpreter/Executor
- Queue-Manager inkl. depends_on DAG
- Preflight v2 Pipeline (nach Legacy-Rueckgabeabbau)
- Registry/Provider-Mechanik
- Conversation Store, Authorization, Privacy
- DTOs und Summarizer (sofern nicht gegen neue Contracts verstossend)

Leitregel fuer Plan/Execution:
- Planner-first bei Ambiguitaet oder fehlender Entity-Aufloesung (z.B. mehrere Treffer fuer "Billy"): erst lookup/clarification, keine stille Auto-Auswahl.
- Spawn-only fuer deterministische Folgeschritte mit gebundenen Outputs und erfolgreichem late-preflight.

## 5. Refactoring-Checkliste zum Abarbeiten

## Phase 0.5 - Produktanforderungen als Contracts (neu)
- [ ] Architektur-Contracts fuer die 6 Muss-Anforderungen dokumentieren: Contextid-Authority, Third-Party-DX, Sprachtreue, Confirmation-Default, Capability-Matrix, Complex-Scenario-Faehigkeit.
- [ ] Privacy wird als erfuellt betrachtet (Schalter vorhanden); keine Kernarchitektur-Aenderung in dieser Welle.

Gate Phase 0.5:
- [ ] Produkt-Contracts sind als testbare Akzeptanzkriterien in docs/Blueprints fixiert.

## Phase 0.6 - Contextid-Authority statt activity-spezifischem Scope (neu)
- [ ] Externe Entry-Points, Runtime, Orchestrator, Decision-Service, Preflight, Queue, Executor und Task-Interface auf contextid als autoritative Scope-ID umstellen.
  - [x] Zwischenstand: task_interface, base_task, core_task_base, preflight_pipeline und executor verwenden im zentralen Task-Grenzpfad contextid.
  - [x] Zwischenstand: privacy_anonymizer, ai_privacy_precheck, aiready und die threadbezogene Booking-Metadatenablage sprechen den Conversation-Store intern ueber contextid statt direkt ueber cmid an.
- [x] Confirmation-Allowance fuer Session-Autoconfirm an userid + contextid binden; threadid bleibt nur Referenz fuer konkrete Konversation und pending_intent.
- [ ] Thread-, Queue-, Audit-, Guard- und Idempotency-Daten mit contextid fuehren.
- [ ] Context-Resolution und Capability-Checks ausschliesslich ueber Moodle context API modellieren.
- [x] Neue DB-Struktur direkt in install.xml festlegen (kein upgrade.php, keine Datenmigration, kein Backfill).
- [ ] Alte activity-spezifische Scope-Annahmen in Tests und Dokumentation ersetzen.

Gate Phase 0.6:
- [ ] Kein interner Agent-Vertrag verwendet eine activity-spezifische ID als primaeren Scope.
- [x] install.xml bildet die neue Context-basierten Tabellen/Felder vollstaendig ab; kein Upgrade-Skript erforderlich.

## Phase 0 - Freeze und Safety-Net
- [ ] Architektur-Freeze auf Zielbild in docs/Blueprints festschreiben.
- [ ] Branch-Strategie: refactor/core-deterministic-routing anlegen.
- [ ] Test-Baseline dokumentieren (gruen/rot + bekannte Flakes).
- [ ] Kill-Switch Configs fuer entfernte Pfade definieren (nur falls temporaer noetig).

Gate Phase 0:
- [ ] Team-Entscheid: Remove-Now Liste freigegeben.

## Phase 1 - Entfernen alter Entscheidungs-Pfade
- [x] recovery_enrichment_service entfernen.
- [x] Alle Verwendungen in agent_decision_service entfernen.
- [x] Zweiten generischen Recovery-Block in agent_decision_service entfernen.
- [ ] UNKNOWN/invalid handling auf planner_retry <= 2 + clarification umstellen.
  - [x] Zwischenstand: UNKNOWN_TYPE und harte Contract-Invalid-Faelle laufen im Runtime-Contract-Gate jetzt ueber max. 2 Repair-Retries und fallen danach deterministisch auf clarification zurueck.
- [x] Dead imports in core/tasks/list_actions_task bereinigen.

Gate Phase 1:
- [ ] Kein Codepfad mehr, der aus clarification/error automatisch task_call erzeugt.

## Phase 2 - Explizite Planner-Contracts
- [ ] task_interface um get_prompt_contract erweitern.
- [ ] task_prompt_contract DTO einfuehren (intent, anchors, minimal_input, example_input, namespace, version, capabilities, context scopes).
- [ ] task_registry fallback-Heuristiken entfernen (.create_, query, question etc.).
- [ ] task_registry_factory so absichern, dass Third-Party-Provider ueber Discovery eingebunden werden, aber fehlerhafte Provider isoliert scheitern.
- [ ] Task-Namen und Alias-Ziele namespacen und versionieren, damit Third-Party-Tasks keine Core-/Booking-Tasks ueberschreiben.
- [ ] message_trigger_registry auf Signalnormalisierung begrenzen.
- [ ] Third-Party-Developer-Guide: Minimalbeispiel fuer neuen Provider + Task inklusive Capability, Prompt-Contract, Schema, Preflight und Execute bereitstellen.

Gate Phase 2:
- [ ] Planner-Katalog wird nur aus expliziten Contracts gebaut.
- [ ] Ein neuer Demo-Task laesst sich ausschliesslich ueber Provider-Registrierung einhaengen.
- [ ] Ein fehlerhafter Third-Party-Task blockiert weder Registry-Build noch andere Tasks.

## Phase 2.5 - Sprachtreue ohne Sprachheuristiken (neu)
- [ ] Sprachspezifische Keyword-/Regex-Erkennung in Routingpfaden entfernen.
- [ ] Einheitliche Sprache-Authority festlegen: letzte User-Anfrage > modellseitig deklarierte user_lang > expliziter technischer Fallback.
- [ ] Antworttexte in allen Pfaden (clarification/confirmation/sufficient/error/budget/permission/retry) an dieselbe Sprache-Authority binden.
- [ ] Deterministische Framework-Texte ueber language_policy_service formatieren, nicht lokal in Decision-, Queue- oder Preflight-Code zusammensetzen.

Gate Phase 2.5:
- [ ] Gleiches Verhalten fuer z.B. Chinesisch, Deutsch, Englisch ohne Sonderlisten.
- [ ] Sprachtests decken LLM-Antworten und deterministische Systemantworten ab.

## Phase 3 - Preflight v2 als einziger Wahrheitskoerper
- [ ] preflight_pipeline Rueckgabe auf preflight_result_v2-only umstellen.
  - [x] Zwischenstand: Pipeline liefert keinen `valid`-/`v2_result`-Wrapper mehr, sondern einen flachen v2-Status-Contract plus Pipeline-Metadaten.
- [ ] Legacy valid/errors/prepared_commands Rueckgabepfade entfernen.
  - [x] Zwischenstand: Legacy-Felder `valid` und `v2_result` sind entfernt; `errors` und `prepared_commands` bestehen vorerst weiter als Pipeline-Metadaten.
- [ ] preflight_result_v2 Legacy-Helfer reduzieren.
  - [x] Zwischenstand: `isvalid`, `get_issue_codes()`, `get_issues_by_severity()` und `has_confirmable_issues()` sind entfernt; die Pipeline liest direkt `status` und `issuecodes`.
- [ ] Agent-Decision-Queue-Mapping strikt auf v2 status (pass/soft_block/hard_block/retry_hint).
  - [x] Zwischenstand: `agent_decision_service` mappt Block-/Retry-/Confirm-Entscheidungen direkt aus `status`; die Queue-Entscheidung bekommt denselben v2-Status uebergeben.

Gate Phase 3:
- [x] Kein Aufrufer liest mehr Legacy-Felder aus der Pipeline.

## Phase 4 - Execution Guard statt zweitem Voll-Preflight
- [x] execution_guard token/fingerprint definieren.
- [x] executor: task::preflight vor execute fuer Mutationen entfernen.
- [x] executor: guard verify + deterministische Fail-Codes einfuehren.
- [x] Queue/confirm path so anpassen, dass prepared_input + guard_token + contextid durchgereicht werden.

Gate Phase 4:
- [x] Kein zweiter Voll-Preflight in executor fuer mutierende Tasks.

## Phase 5 - Subtask Primitive
- [ ] spawn_commands[] im Task-Result-Contract standardisieren.
- [ ] produced_outputs im Task-Result-Contract standardisieren, damit Child-Tasks erzeugte IDs, Dateien, Suchergebnisse und Zwischenartefakte referenzieren koennen.
- [ ] Output-Bindings fuer Child-Inputs definieren (z.B. parent.created_course_id -> child.courseid).
- [ ] Spaeten Preflight fuer abhaengige Child-Commands einfuehren: erst nach erfolgreicher Parent-Ausfuehrung und Artefaktbindung validieren.
- [ ] Confirmation-Semantik fuer Ketten definieren: globale Planfreigabe nur bei Session-Allowance, sonst Bestaetigung vor der jeweils naechsten mutierenden Slice.
- [ ] Spawn-Regel verbindlich machen: keine Spawn-Kette bei Ambiguitaet; stattdessen planner-gesteuerte Clarification/Lookup-Runde.
- [ ] executor spawn_handler implementieren.
- [ ] queue_manager: child enqueue mit depends_on Parent stabilisieren.
- [ ] observation_builder fuer Parent/Child-Kette erweitern.
- [ ] Referenz-Workflow als Spawn-Kette nachweisen (z.B. course -> questions -> quiz -> enrolment).

Gate Phase 5:
- [ ] Parent succeeded -> Outputs gebunden -> Child late-preflight -> Children queued -> Children executed im selben Run.

## Phase 6 - Test-Haertung (Pflicht)
- [ ] Neuer Contract-Test: contextid ist autoritativer Scope in Runtime, Confirmation, Queue, Preflight und Executor.
- [ ] Neuer Contract-Test: deterministisches routing ohne recovery fallback.
- [ ] Neuer Contract-Test: preflight L1->L2->L3 inklusive skip-Logik.
- [ ] Neuer Contract-Test: execution guard statt re-preflight.
- [ ] Neuer Contract-Test: spawn_commands dependency chain inklusive produced_outputs, Output-Bindings und late-preflight.
- [ ] Neuer Contract-Test: Planner-first bei Ambiguitaet (z.B. 2x "Billy") erzwingt clarification statt Spawn/Mutation.
- [ ] Neuer Contract-Test: Third-Party-Task-DX mit Provider-only-Onboarding, Namespace, Version und Capability.
- [ ] Bestehende Recovery-basierte Tests entfernen/ersetzen.
- [ ] Neuer Contract-Test: Mutationen sind per Default blocked_confirmation, Session-Autoconfirm nur ueber allow_session.
- [ ] Neuer Contract-Test: Capability-Gating mit mehreren Freischaltkriterien (active/runtime/context/capability).
- [ ] Neuer Contract-Test: Antwortsprache folgt Eingabesprache fuer LLM- und deterministische Antworten (mindestens de/en/zh Matrix).
- [ ] Installations-Test: frische Installation mit neuer install.xml erzeugt alle benoetigten Context-basierten Strukturen ohne upgrade.php.

Gate Phase 6:
- [ ] Alle Kern-Contract-Tests gruen.

## Phase 7 - 3 Ziel-Szenarien (dein Vorgehen)
- [ ] Szenario A: Idealer Readonly Task + Test.
- [ ] Szenario B: Idealer Multistep Task + Test.
- [ ] Szenario C: Idealer Subtask/Spawn Task + Test.
- [ ] Erst danach schrittweises Onboarding weiterer Tasks.
- [ ] Szenario D (komplex, cross-domain): Kurs erstellen -> 10 Fragen aus PDF/Bildquellen erstellen -> Quiz befuellen -> alle Nutzer mit Vorname Peter einschreiben; Nachweis ueber produced_outputs, Output-Bindings, late-preflight, Retry und Confirmation.

Gate Phase 7:
- [ ] Drei Szenarien stabil in CI.
- [ ] Komplexes End-to-End-Szenario deterministisch reproduzierbar (inkl. Retry/Confirm-Pfaden).

## 6. Klasse-fuer-Klasse Disposition (Aenderungspfad)

### A) Sicher entfernen
- classes/local/wbagent/recovery_enrichment_service.php
- classes/local/wbagent/interfaces/agent_task_provider.php
- classes/local/wbagent/booking/support/booking_rules_agent_service.php (falls in Phase 0 kein legitimer Callsite auftaucht)

### B) Sicher refactoren
- classes/local/wbagent/agent_decision_service.php
- classes/local/wbagent/agent_runtime.php
- classes/local/wbagent/task_registry.php
- classes/local/wbagent/services/preflight_pipeline.php
- classes/local/wbagent/services/preflight_result_v2.php
- classes/local/wbagent/executor.php
- classes/local/wbagent/orchestrator.php
- classes/local/wbagent/interfaces/task_interface.php
- classes/local/wbagent/queue/queue_manager.php
- classes/local/wbagent/queue/observation_builder.php

### C) Nach Stabilisierung entfernen
- classes/local/wbagent/planner_service.php
- classes/local/wbagent/interfaces/task_trigger_provider_interface.php (task-routing Anteil)
- classes/local/wbagent/task_discovery.php (trigger discovery Anteil)
- classes/local/wbagent/adaptive_task_catalog_service.php (falls ersetzt)
- embeddings suite (produktstrategische Entscheidung)

## 7. Risiken und Gegenmassnahmen

Risiko 1: Regression in Confirmation-Flow
- Gegenmassnahme: Contract-Tests fuer pending_intent, queue states, ai_confirm_run.

Risiko 2: Versteckte Legacy-Abhaengigkeit in Tests
- Gegenmassnahme: Erst Test-Dispositionsliste erstellen, dann Code entfernen.

Risiko 3: Ueberambitionierte Parallel-Umbauten
- Gegenmassnahme: Phasen strikt sequenziell mit Gates.

## 8. Definition of Done fuer die Architektur

- [ ] Interne Agent-Vertraege verwenden contextid als autoritative Scope-ID.
- [x] Kein recovery_enrichment_service im Code.
- [ ] Keine task_call-Promotion aus clarification/error.
- [ ] Preflight v2 only.
- [ ] Executor ohne zweites mutierendes Voll-Preflight.
- [ ] spawn_commands, produced_outputs, Output-Bindings und late-preflight als offizieller Subtask-Mechanismus.
- [ ] Planner-first bei Ambiguitaet ist durchgaengig erzwungen; Spawn wird nur fuer deterministische Folgeaktionen verwendet.
- [ ] Third-Party-Task-Onboarding ohne Framework-Eingriff dokumentiert, namespaced, versioniert und getestet.
- [ ] Keine sprachspezifischen Routing-Heuristiken; LLM- und deterministische Antworten folgen Eingabesprache.
- [ ] Mutationen sind per Default confirmation-required/blocked_confirmation; Session-Autoconfirm funktioniert nur ueber den vorgesehenen Flow und ist an userid + contextid gebunden.
- [ ] Task-Freischaltung basiert auf mehrstufigem Gate (runtime/active/context/capability).
- [ ] Drei Ziel-Szenarien stabil und reproduzierbar.
- [ ] Komplexes Cross-Domain-Szenario (course -> questions -> quiz -> enrolment) stabil und reproduzierbar mit Artefaktreferenzen, Output-Bindings, Retry und Confirmation.
- [x] Neue DB-Struktur ist ausschliesslich ueber install.xml ausgerollt (kein upgrade.php, keine Migration).
- [ ] Dokumentation und Flowchart synchron zum Code.

## 9. Realitaetscheck zum aktuellen Stand

- Produktiver Code wurde in mehreren Kernpfaden bereits umgebaut.
- Mehrere Legacy-Klassen/-Dateien wurden bereits entfernt.
- Tests sind noch nicht im selben Masse nachgezogen; belastbare Moodle-PHPUnit-Validierung steht weiterhin aus.

Dieses Dokument bleibt der Arbeitsplan mit Priorisierung und Abhakliste; die Haken spiegeln den aktuell verifizierten Implementierungsstand wider.
