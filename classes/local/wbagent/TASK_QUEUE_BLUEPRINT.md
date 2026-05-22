# Task-Queue Blueprint fuer den Multi-Step-Agent (bookingextension_agent)

## Ziel

Dieses Dokument beschreibt die Zielarchitektur fuer eine robuste Task-Queue im Agent-Loop.

Scope:
- Nur Architektur, Regeln, Datenmodell und Rollout-Plan.
- Keine Implementierung und keine API-Vertragsaenderung in diesem Schritt.

## Problem im Ist-Zustand

Aktuell werden mutierende Commands bewusst gestaged, sodass pro Confirm-Stufe nur der erste mutierende Command weitergegeben wird.
Das ist sicher, fuehrt aber bei mehrstufigen Plaenen zu:

- Mehr Planungsrunden als noetig.
- Hoeherer LLM-Tokenverbrauch.
- Potenziell inkonsistenter Reihenfolge, wenn spaetere Re-Planung den Plan leicht veraendert.

## Zielbild (High-Level)

Der Planner erzeugt weiterhin Commands, aber die Ausfuehrung laeuft ueber eine serverseitige Queue mit klarer Status-Maschine.

Leitprinzipien:
- Planner ist deklarativ, Runtime ist deterministisch.
- Read-only und mutierend werden strikt unterschiedlich behandelt.
- Mutationen bleiben confirmation-gated.
- Jeder Queue-Eintrag ist idempotent und beobachtbar.

## Queue-Item Datenmodell

Jeder Eintrag sollte mindestens enthalten:

- queue_item_id: Stabiler Primarschluessel.
- thread_id, run_id, step_id: Korrelation zur Konversation.
- task: Exakter Task-Name.
- input: Effektiver Input (nach Normalisierung).
- prepared_input: Optional nach Preflight.
- input_signature: Hash ueber task + normalisierten Input.
- mutability: readonly | mutating.
- depends_on: Liste vorgaenger queue_item_ids.
- status: queued | ready | running | succeeded | failed | retry_waiting | blocked_confirmation | skipped.
- retry_count, next_retry_at.
- issue_codes, error_class, last_error_message.
- created_at, updated_at.

## Status-Maschine

Empfohlener Ablauf:

1. queued
2. Preflight (nur mutating): hard block → failed (terminal), domain_conflict → blocked_confirmation,
   autoconfirmmode → ready direkt, Normalmodus → blocked_confirmation
3. ready (alle Dependencies erfuellt, kein Slot-Konflikt)
4. running (Scheduler-Lock: max 1 running pro thread_id)
5. succeeded oder failed
6. optional retry_waiting -> ready
7. nach Nutzerbestaetigung blocked_confirmation -> ready (ohne erneuten Preflight, ausser Item
   ist aelter als konfigurierbares Timeout - dann Re-Preflight empfohlen)

Status skipped:
- Ein Queue-Item wird auf skipped gesetzt, wenn ein Item in seiner depends_on-Liste
  auf failed geendet hat.
- skipped ist terminal (kein Retry, kein User-Signal noetig).
- Planner sieht skipped Items in der Observation und kann entscheiden, ob ein Ersatz-Command
  benoetigt wird.

Wichtige Invarianten:
- Nie gleichzeitig mehr als ein running Eintrag pro thread_id (Scheduler-Lock).
- failed ist terminal, ausser error_class ist transient.
- skipped ist terminal.
- blocked_confirmation ist terminal, bis ein explizites User-Signal kommt.

## Scheduling-Regeln

Deterministische Regeln statt impliziter LLM-Reihenfolge:

1. Zuerst alle ready readonly Commands ohne offene Dependencies.
2. Mutating Commands: Ablauf immer ueber Preflight (auch im autoconfirmmode).
   - Preflight prueft immer auf Hard-blocking Issues (validation_error, permission_error,
     domain_conflict), unabhaengig vom Confirmation-Mode.
   - Normalmodus: Nach erfolgreichem Preflight landet das Item in blocked_confirmation.
   - autoconfirmmode aktiv (is_confirmation_allowed_for_thread = true): Nach erfolgreichem
     Preflight direkt in ready. Der Planner formuliert Announcements statt Fragen.
3. Wenn mutating + readonly im selben Planner-Output vorkommen:
   - readonly direkt in ready,
   - mutating: Preflight-Pfad (s.o.).
4. Reihenfolge fuer Mutationen:
   - Wenn depends_on gesetzt: topologische Reihenfolge.
   - Ohne depends_on: stabile Originalreihenfolge aus Planner-Output.
5. Scheduler-Lock vor jedem ready → running Uebergang:
   - Wird kein freier Slot gefunden (anderes Item ist running), bleibt das Item in ready
     und wird beim naechsten Worker-Tick erneut geprueft.
   - Verhindert Race Conditions bei parallelen Worker-Aufrufen.

## Confirmation-Strategie

Sicheres Default:
- Pro mutierendem Queue-Eintrag eine Confirmation-Stufe.

Session-Freigabe (autoconfirmmode):
- Wenn der Nutzer die Session-Freigabe erteilt hat (is_confirmation_allowed_for_thread = true),
  wird die Confirmation-Stufe uebersprungen.
- Der Planner-Prompt wechselt in den Announcement-Modus:
  Keine Fragen, sondern kurze Ankuendigungen was als naechstes ausgefuehrt wird.
- Der Planner darf trotzdem nur confirmation_request als response_type emittieren;
  der Runtime-Layer erkennt autoconfirmmode und foerdert diesen Eintrag direkt zu ready.
- Sicherheitsnetz: Preflight laeuft immer, auch im autoconfirmmode.
  Hard-blocking Issues (validation_error, permission_error, domain_conflict) stoppen die Auto-Execution.

Optionale Optimierung spaeter:
- Batch-Confirmation fuer mehrere mutierende Eintraege,
  aber Ausfuehrung intern weiterhin sequentiell mit Stop-on-Error.

## Fehler- und Retry-Politik

Error-Klassen:
- validation_error: terminal failed.
- permission_error: terminal failed.
- domain_conflict: blocked_confirmation oder clarification (eigener Pfad, nicht direkt failed).
- contract_repair: Ein Schema/Contract-Fehler im LLM-Output wurde erkannt. Kein silent retry
  im selben Loop-Step. Stattdessen: ein neuer Queue-Eintrag wird angelegt, mit depends_on
  auf dem fehlgeschlagenen Item. Der Planner erhalt die Fehler-Observation und emittiert
  einen reparierten Command. MAX_LOOP_STEPS zaehlt gegen diesen Eintrag.
- provider_timeout / transient_io: retry_waiting mit Backoff.

Retry-Regeln:
- Exponential Backoff mit kleinem Cap.
- Max retry_count pro Eintrag.
- Nach Retry-Exhaustion: failed + issue_code fuer Planner-Sicht.

## Repair-Loop nach Execute-Fehler

Wenn ein Command erfolgreich an den Executor uebergeben wurde, aber das Ergebnis
einen Ausfuehrungs-Fehler enthaelt (z.B. falsche Option, fehlende Daten, Kapazitaetsproblem),
muss das LLM die Moeglichkeit bekommen, in der naechsten Observation-Runde zu reagieren.

Ablauf:
1. Executor liefert Ergebnis mit status=error und befuelltem detail/issue_codes.
2. Observation Builder komprimiert das Fehler-Ergebnis und haengt es als strukturierte
   Observation an den naechsten LLM-Step an.
3. Repair-Erkennung im Runtime-Loop prueft, ob der Fehler reparierbar ist:
   - contract_repair Marker (CONTRACT_* issue_codes, bekannte Schema-Fehlertexte): neuer
     Queue-Eintrag mit depends_on auf dem fehlgeschlagenen Item. Kein direkter silent retry
     im laufenden Loop-Step (Queue-Mechanik greift vollstaendig).
   - Domain-Fehler mit aufloesbaren Hinweisen: LLM sieht Observation und kann neuen Command
     emittieren oder Clarification stellen. Auch das als neuer Queue-Eintrag.
4. Wenn autoconfirmmode aktiv ist, darf der Repair-Step ebenfalls auto-executed werden,
   sofern Preflight den reparierten Command akzeptiert.
5. Repair zaehlt als eigener Loop-Step gegen das MAX_LOOP_STEPS Budget.
6. Wenn das Budget erschoepft ist, bevor Goal reached: failed mit issue_code budget_exceeded.

## Idempotenz und Dedupe

Pro Queue-Item muss eine Signatur gelten:
- input_signature = sha256(task + canonical_input_json)

Regel:
- Bereits erfolgreich ausgefuehrte Signaturen im selben Thread nicht erneut ausfuehren,
  ausser ein neues User-Turn invalidiert explizit den Kontext.

## Beobachtbarkeit

Das UI pollt bereits heute per `bookingextension_agent_ai_poll_thread` gegen den Server und holt
laufende Step-Messages ab. Dieser Mechanismus wird auch fuer Queue-Status-Updates genutzt.

Keine neue Polling-Infrastruktur noetig. Stattdessen:

- Jeder Queue-Statuswechsel (queued → running → succeeded/failed) schreibt eine Step-Message
  in denselben Thread, den `ai_poll_thread` ohnehin abfragt.
- Step-Messages tragen dabei das Queue-Item als Kontext:
  task, status, issue_codes, Dauer, komprimiertes Ergebnis.
- Das UI zeigt dadurch Queue-Fortschritt in denselben Schritt-Bubbles wie heute.

Noetige Telemetrie pro Queue-Eintrag (in Step-Message serialisiert):
- task-Name und step_id,
- status-Wechsel und Zeitstempel,
- issue_codes und error_class,
- komprimiertes Ergebnis fuer Observation-Feed.

UI/Debug-Nutzen:
- Kein separater Kanal: alles laeuft ueber den existierenden Poll-Endpoint.
- Schrittanzeigen werden stabiler, weil Queue-Status direkt sichtbar ist.
- Repeat- und Drift-Probleme sind leichter nachweisbar.
- Post-Mortem Analyse ohne Volltextlogs moeglich.

## Integrationsplan auf bestehende Klassen

### Orchestrator

- Darf mehrere Commands liefern.
- Prompt-Regel "exactly ONE task_call" wird auf Queue-faehige Ausgabe angepasst.
- Optional: depends_on Hinweise erlauben.

### Agent Decision Service

- Statt first-mutation-slice: Queue-Insertion + Statuszuweisung.
- Readonly weiterhin direkt ausfuehrbar, aber ueber Queue-Mechanik.
- Confirmation-Logik auf blocked_confirmation pro Queue-Item.

### Executor

- Kann mehrere Commands bereits verarbeiten.
- Soll als Worker fuer ready Eintraege genutzt werden.
- Muss Status/Issue-Codes pro Queue-Item zurueckgeben.

### Observation Builder (neue Komponente)

- Eigenstaendige Komponente, kein Teil des Executors.
- Eingabe: Liste von Queue-Items (succeeded, failed, skipped) nach einem Worker-Zyklus.
- Ausgabe: Strukturierte Observation fuer den naechsten LLM-Step.
- Verantwortlich fuer:
  - Komprimierung von Ergebnissen und Fehlern.
  - Erkennung von repair_needed (contract_repair, domain-Fehler).
  - Anreicherung mit issue_codes und error_class fuer Planner-Sicht.
- Wird in Phase 3 als eigene Klasse extrahiert; in Phase 1/2 kann Logik im Agent Runtime liegen.

### Agent Runtime

- Loop wird Queue-getrieben:
  - dequeue ready,
  - execute batch,
  - observations aktualisieren,
  - MAX_LOOP_STEPS-Budget pruefen vor jedem Re-Plan,
  - ggf. Re-Plan nur bei Bedarf (nur Anhaengen, kein Ersetzen bestehender queued Items).

## Rollout-Plan (ohne Big Bang)

Phase 1: Shadow-Queue
- Queue nur schreiben und beobachten, Ausfuehrung noch wie heute.
- Vergleich: geplanter vs. tatsaechlich ausgefuehrter Pfad.

Phase 2: Readonly Queue Activation
- Nur readonly ueber Queue steuern.
- Mutationen bleiben im bisherigen Confirmation-Pfad.

Phase 3: Mutating Queue Staging
- Mutationen als blocked_confirmation Eintraege fuehren.
- Nach Confirm sequentielle Ausfuehrung ueber Worker.

Phase 4: Optional Batch-Confirmation
- Nur wenn Monitoring stabil ist und UX-Bedarf klar ist.

## Teststrategie

Pflichtfaelle:
- Mehrere readonly Commands in einem Planner-Output.
- Gemischte readonly + mutating Commands.
- Dependency-Kette ueber 3+ Eintraege.
- Teilfehler in der Mitte eines mutierenden Plans.
- Duplicate command signatures innerhalb eines Turns.
- Resume nach Step-Limit/Timeout.

## Offene Architekturentscheidungen

1. Soll Batch-Confirmation ueberhaupt erlaubt werden?
2. Wie lange sind queue_item records im Thread aufzubewahren?
3. ~~Duerfen spaetere Planner-Turns bestehende queued Items ersetzen oder nur anhaengen?~~
   **Entschieden: Nur Anhaengen.** Spaetere Planner-Turns duerfen bestehende queued Items
   nicht invalidieren oder ersetzen. Neue Commands werden immer als zusaetzliche Eintraege
   in die Queue eingefuegt. Ausnahme: Ein explizites User-Turn (neuer Gespraechsbeitrag)
   darf queued Items via input_signature-Invalidierung verwerfen.
4. ~~Welche error_class mappt auf retry_waiting vs. sofort failed?~~
   **Entschieden: provider_timeout und transient_io → retry_waiting mit Backoff.
   Alle anderen error_classes (validation_error, permission_error, contract_repair nach
   Budget-Exhaustion) → sofort failed (terminal).**

## Kurzfazit

Eine echte Queue reduziert unnötige Re-Planning-Schleifen und macht den Agent-Loop reproduzierbarer.
Die Sicherheitseigenschaften (Confirmation fuer Mutationen) bleiben erhalten, werden aber als expliziter Queue-Zustand modelliert statt als implizite Sonderbehandlung.

## Agent-Ready Implementierungs-Checkliste (Commit-weise)

Hinweis fuer Agentenlauf:
- Jeder Schritt ist bewusst klein gehalten (ein Commit pro Schritt).
- Nach jedem Schritt genau den angegebenen Test-Command ausfuehren.
- Bei Fehlern zuerst Test stabilisieren oder Contract korrigieren, dann erst naechster Schritt.

### Schritt 1: Feature-Flags + Konfigurationsrahmen anlegen

Ziel:
- Refactor sicher hinter Flags einhaengen (ohne sofortiges Runtime-Verhalten zu brechen).

Dateien (voraussichtlich):
- `mod/booking/bookingextension/agent/settings.php`
- `mod/booking/bookingextension/agent/lang/en/bookingextension_agent.php`

Umsetzung:
- Neue Flags einfuehren:
  - `preflight_v2_enabled`
  - `preflight_v2_shadow_mode`
  - `queue_dag_validation_enabled`
  - `queue_blocked_ttl_enabled`
  - `preflight_audit_enabled`

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter agent_architecture_contract_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/permanent/contracts/agent_architecture_contract_test.php
```

### Schritt 2: Zentrales Command-Schema + Schema-Validator Service

Ziel:
- Layer 1 (Schema Validation) zentralisieren und gecacht laden.

Dateien (neu/angepasst):
- `mod/booking/bookingextension/agent/classes/local/wbagent/config/command_schema.json` (neu)
- `mod/booking/bookingextension/agent/classes/local/wbagent/services/preflight_schema_validator.php` (neu)

Umsetzung:
- Validator liefert bei Fehler eindeutig `schema_error`.
- Nur lesen/validieren, keine DB/I/O-Nebenwirkungen ausser initialem Schema-Load.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter interpreter_realistic_llm_matrix_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/permanent/llm_sim/interpreter_realistic_llm_matrix_test.php
```

### Schritt 3: PreflightResult v2 Vertrag als DTO/Mapper einfuehren

Ziel:
- Einheitliches Resultat fuer Layer 1-3 herstellen.

Dateien (neu/angepasst):
- `mod/booking/bookingextension/agent/classes/local/wbagent/services/preflight_result_v2.php` (neu)
- `mod/booking/bookingextension/agent/classes/local/wbagent/task_preflight_result.php` (nur kompatible Erweiterung/Mapper)

Umsetzung:
- Vertrag enthalten:
  - `status` (`pass|soft_block|hard_block|retry_hint`)
  - `issue_codes`
  - `blocking_layer`
  - `retry_after_ms`
  - `retry_count`
  - `duration_ms`

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter task_validation_matrix_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/permanent/tasks/task_validation_matrix_test.php
```

### Schritt 4: Domain-Check Runner (Layer 2) einbauen

Ziel:
- Conflict/Permission/Precondition Checks unter gemeinsamem Timeout orchestrieren.

Dateien (neu/angepasst):
- `mod/booking/bookingextension/agent/classes/local/wbagent/services/preflight_domain_check_runner.php` (neu)
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_decision_service.php`

Umsetzung:
- Read-only Domain-Checks, keine Writes.
- Shared timeout (500 ms) als harte Guard.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter booking_task_mutation_execute_service_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/booking_task_mutation_execute_service_test.php
```

### Schritt 5: Execution Gate (Layer 3) mit Backoff und Retry-Limit

Ziel:
- `retry_hint` von terminalen Blocks trennen; Backoff zentral steuern.

Dateien (neu/angepasst):
- `mod/booking/bookingextension/agent/classes/local/wbagent/services/preflight_execution_gate.php` (neu)
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_decision_service.php`

Umsetzung:
- Parameter:
  - `base_ms=500`
  - `jitter_ms=200`
  - `max_retries=4`
- Formel:
  - `backoff_ms = base_ms * 2^retry_count + random(0, jitter_ms)`
- Bei Exhaustion: `hard_block` + `max_retries_exceeded`.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter ai_send_message_simulated_llm_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/simulated_llm/webservice/ai_send_message_simulated_llm_test.php
```

### Schritt 6: DAG-Validierung bei Queue-Ingestion

Ziel:
- Zyklische `depends_on`-Ketten beim Einreihen verhindern.

Dateien:
- `mod/booking/bookingextension/agent/classes/local/wbagent/queue/queue_manager.php`
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_decision_service.php`

Umsetzung:
- `validate_depends_on_is_dag()` vor persistenter Aufnahme.
- Bei Zyklus: terminal fail + `dependency_cycle`.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter ai_send_message_simulated_llm_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/simulated_llm/webservice/ai_send_message_simulated_llm_test.php
```


### Schritt 7: Queue-Modell erweitern (Retry, TTL, Backoff-Felder)

Ziel:
- Queue-Items um alle benoetigten Gate/TTL-Felder erweitern.

Dateien:
- `mod/booking/bookingextension/agent/classes/local/wbagent/queue/queue_manager.php`

Umsetzung:
- Neue Felder/API:
  - `preflight_retry_count`
  - `retry_after_ms`
  - `backoff_ms`
  - `blocked_expires_at`
  - Helper: `can_pickup_now()`

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter agent_architecture_contract_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/permanent/contracts/agent_architecture_contract_test.php
```

### Schritt 8: blocked_confirmation TTL + Eskalation

Ziel:
- Hängende blocked-States automatisch terminieren.

Dateien:
- `mod/booking/bookingextension/agent/classes/external/ai_confirm_run.php`
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_decision_service.php`
- `mod/booking/bookingextension/agent/classes/local/wbagent/queue/queue_manager.php`

Umsetzung:
- TTL beim Setzen von `blocked_confirmation` schreiben.
- Bei Ablauf: `failed` + klarer Issue-Code.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter confirmation_flow_real_llm_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/real_llm_multistep/confirmation_flow_real_llm_test.php
```

### Schritt 9: Budget-Guard vor jedem LLM-Call

Ziel:
- Teure Calls abbrechen, bevor Budget bereits exhausted ist.

Dateien:
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_runtime.php`

Umsetzung:
- Guard vor Repair-LLM-Call.
- Guard vor Re-Plan-LLM-Call.
- Bestehendes LS-Limit als sekundäre Absicherung beibehalten.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter slotbooking_autoconfirm_real_llm_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/real_llm_multistep/slotbooking_autoconfirm_real_llm_test.php
```

### Schritt 10: Immutable Preflight Audit-Logging

Ziel:
- Forensisch nachvollziehbar machen, warum Jobs (auch im autoconfirmmode) durchgelassen oder blockiert wurden.

Dateien (neu/angepasst):
- `mod/booking/bookingextension/agent/classes/local/wbagent/services/preflight_audit_logger.php` (neu)
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_decision_service.php`
- `mod/booking/bookingextension/agent/classes/external/ai_confirm_run.php`

Umsetzung:
- Log bei jedem Preflight-Durchlauf (auch `pass`).
- Logfelder mindestens: layer, status, issue_codes, retry_count, duration_ms, thread_id, run_id.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --filter agent_runtime_unit_test /var/www/moodle/public/mod/booking/bookingextension/agent/tests/agent/agent_runtime_unit_test.php
```

### Schritt 11: Contract-Hardening und Shadow-Mode Vergleich

Ziel:
- V2-Resultate auf bestehende API-Antwortform mappen, ohne Frontend/WS zu brechen.

Dateien:
- `mod/booking/bookingextension/agent/classes/local/wbagent/agent_decision_service.php`
- `mod/booking/bookingextension/agent/classes/external/ai_send_message.php`
- `mod/booking/bookingextension/agent/classes/external/ai_confirm_run.php`

Umsetzung:
- Shadow-Mode: alt vs. neu vergleichen und nur loggen.
- Danach Flag `preflight_v2_enabled` fuer aktive Steuerung aktivieren.

Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --testsuite bookingextension_agent_testsuite --filter "ai_send_message_simulated_llm_test|confirmation_flow_real_llm_test|slotbooking_autoconfirm_real_llm_test"
```

### Schritt 12: Abschlusslauf und Freigabekriterien

Ziel:
- Saubere Abnahme der Zielarchitektur fuer weitere Implementierungswellen.

Freigabe nur wenn:
- Kein Busy-Loop bei transient errors.
- Kein unendlicher Preflight-Retry.
- blocked_confirmation hat TTL und endet deterministisch.
- DAG-Zyklen werden beim Ingest verhindert.
- Budget-Guard stoppt vor zusaetzlichen LLM-Calls.
- Audit-Logs sind fuer alle Preflight-Resultate vorhanden.

Abschluss-Test-Command:
```bash
php /var/www/moodle/vendor/phpunit/phpunit/phpunit -c /var/www/moodle/phpunit.xml --testsuite bookingextension_agent_testsuite
```