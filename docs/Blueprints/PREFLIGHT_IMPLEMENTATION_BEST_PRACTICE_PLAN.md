# Preflight Best Practice Plan fuer den Agentic Loop

## Ziel dieses Dokuments

Dieses Dokument ersetzt kein bestehendes Implementierungsdetail, sondern formuliert ein sauberes Zielbild fuer eine neue Preflight-Implementierung auf Basis des realen Ist-Zustands.

Rahmenbedingungen fuer dieses Zielbild:

- Nur Planung, keine Implementierung.
- Keine Ruecksicht auf Migration oder Rueckwaertskompatibilitaet noetig.
- Der Agent ist noch nicht produktiv.
- Das Ziel ist ein queue-getriebener, agentic loop.
- Die Preflight-Logik muss sprachagnostisch sein.
- Drittplugins muessen eigene Tasks sauber registrieren und in dieselbe Preflight-Pipeline einhaengen koennen.

## Executive Summary

Die aktuelle Preflight-Landschaft ist funktional, aber nicht einheitlich. Es gibt heute keinen einzigen autoritativen Preflight-Pfad. Stattdessen existieren drei Ebenen nebeneinander:

1. Task-lokale Legacy-Validation ueber validate().
2. Task-lokale Preflight-Methoden ueber check_structure() + preflight().
3. Eine V2-Schicht mit schema validator, domain runner, execution gate und audit logger, die aktuell nur als Overlay bzw. Shadow-Mechanik arbeitet.

Damit passt der aktuelle Stand nur teilweise zum Queue-Zielbild.

Das wichtigste Architekturproblem ist nicht, dass Komponenten fehlen. Das wichtigste Problem ist, dass Verantwortung doppelt und inkonsistent verteilt ist:

- initiales confirmation preflight laeuft anders als confirm_pending preflight,
- Queue ist beobachtbar, aber noch nicht die autoritative Runtime,
- V2 klassifiziert meist nur Legacy-Ergebnisse nachtraeglich statt die Entscheidung selbst zu tragen,
- die Task-Landschaft ist ueber alle Provider hinweg nicht konsistent migriert.

Die Best-Practice-Neuplanung muss deshalb nicht nur weitere Layer ergaenzen. Sie muss die Preflight-Verantwortung vereinheitlichen.

## Ehrliche Ist-Inventur

### 1. Tatsaechlicher Steuerpfad heute

Die aktuelle Preflight-Steuerung verteilt sich ueber folgende Kernstellen:

- agent_decision_service.php
  - handle_command_routing() ingestiert Queue-Items und staged weiterhin nur die erste Mutation fuer die aktuelle Confirmation-Stufe.
  - handle_preflight() ruft direkt task->preflight() pro Command auf.
  - run_preflight_on_commands() ist ein zweiter Legacy-Sammelpfad fuer confirm_pending.
  - run_preflight_pipeline_on_commands() legt optional V2 darueber, aber nur per Flags.
- ai_send_message.php
  - startet den agentic loop und loggt optional nur Shadow-/API-Mapping.
- ai_confirm_run.php
  - setzt Queue-Status und fuehrt bestaetigte Commands direkt aus.
  - der Queue-Status wird hier eher projiziert als von einem Worker autoritativ vollzogen.
- queue_manager.php
  - verwaltet Queue-Items im Thread-Metadatenstore.
  - hat DAG-Check, blocked TTL, Retry-Felder und Statusprojektion.
- executor.php
  - fuehrt Commands mit prepared_input aus und wiederholt nur leichte structure checks.

### 2. Schicht-fuer-Schicht Inventur gegen das Zielbild

#### Schicht 1: Schema-Validation

Was vorhanden ist:

- preflight_schema_validator.php validiert das Command-Envelope.
- command_schema.json fordert nur task und input, plus optional depends_on.

Was tatsaechlich nicht vorhanden ist:

- keine echte task-spezifische zentrale Schema-Validation,
- keine zentrale Task-Versionpruefung gegen supported/minimum/deprecated Regeln,
- keine Validierung gegen deklarative Task-Metadaten fuer capability oder activation,
- keine Mutability-Whitelist, obwohl die SVG dies als Zielbild nahelegt,
- keine harte Begrenzung auf bekannte Felder, weil additionalProperties=true gesetzt ist,
- kein PreflightRequest-Objekt, das Queue-Kontext, Task-Kontext und Runtime-Kontext sauber zusammenfuehrt.

Ehrliche Bewertung:

- Die aktuelle Schicht 1 validiert nur das Transportformat, nicht den eigentlichen Command-Vertrag.
- Sie ist nuetzlich, aber deutlich schmaler als das Blueprint-Zielbild.

#### Schicht 2: Domain-Checks

Was vorhanden ist:

- Task-spezifische preflight()-Methoden liefern prepared_input und issue codes.
- preflight_domain_check_runner.php existiert.

Was tatsaechlich passiert:

- handle_preflight() und run_preflight_on_commands() verlassen sich primaer auf task->preflight().
- preflight_domain_check_runner.php fuehrt keine echten parallelen conflict, permission und precondition checks aus.
- Stattdessen klassifiziert er bereits entstandene issue codes nachtraeglich in hard_block, soft_block oder retry_hint.
- Der Timeout ist kein echter gemeinsamer async timeout ueber mehrere Checks, sondern nur eine Messung verstrichener Zeit.

Ehrliche Bewertung:

- Die eigentliche Domain-Pruefung liegt heute in jeder Task separat.
- Schicht 2 ist noch kein eigenstaendiger, framework-getriebener Preflight-Runner.
- Das fuehrt zu uneinheitlichem Verhalten ueber Tasks hinweg.

#### Schicht 3: Execution Gate und Backoff

Was vorhanden ist:

- preflight_execution_gate.php kennt provider_timeout, transient_io, exponential backoff, jitter und max_retries.
- queue_manager.php hat Felder fuer preflight_retry_count, retry_after_ms, backoff_ms und next_retry_at.

Was tatsaechlich passiert:

- Das Gate wird nur im optionalen V2-Overlay verwendet.
- Es entscheidet heute nicht autoritativ ueber Queue-Pickup oder Queue-Transition.
- Es gibt keinen dedizierten Worker, der retry_waiting aus persisted backoff semantics wieder in ready ueberfuehrt.
- retry_count im Gate ist derzeit nicht sauber mit einem echten Queue-Lebenszyklus verbunden.

Ehrliche Bewertung:

- Die Bauteile fuer Layer 3 existieren, aber die Laufzeit ist noch nicht gate-driven.
- Der wichtigste Unterschied zum Zielbild: Backoff ist heute eher Datenmodell und Hilfslogik als eigentliche Runtime-Steuerung.

### 3. Inkonsistenz ueber alle Tasks hinweg

#### Booking-Tasks

Unter booking/tasks gibt es 20 Task-Dateien.

Davon sind:

- 6 Tasks mit explizitem check_structure() und explizitem preflight()
  - create_option_task
  - update_option_task
  - bulk_update_options_task
  - diagnose_booking_issue_task
  - create_rule_from_template_task
  - update_rule_from_template_task
- 3 Tasks mit explizitem preflight(), aber ohne eigenes check_structure()
  - configure_booking_instance_task
  - create_slotbooking_option_task
  - create_selflearning_option_task
- 7 Tasks mit Legacy validate()-Pfad als primaerem Verhalten
  - add_price_category_task
  - book_users_task
  - diagnose_cancellation_issue_task
  - explain_docs_topic_task
  - get_option_details_task
  - list_option_properties_task
  - search_options_task
- 4 Wrapper-/Basisklassen bzw. Ableitungen ohne eigene Preflight-Migration
  - analyze_rules_task
  - recall_memory_task
  - search_courses_task
  - search_users_task

#### Core-Tasks

Unter core/tasks gibt es 38 Task-Dateien.

Davon haben:

- 0 ein explizites preflight()
- 0 ein explizites check_structure()
- alle bleiben faktisch im Legacy-/Default-Pfad

#### Bedeutende Folge

Der Framework-Vertrag sagt bereits: neue Tasks sollen check_structure() + preflight() verwenden.

Die Runtime behandelt den Code aber noch so, als muessten Legacy-Tasks dauerhaft mitgetragen werden. Dadurch entstehen drei Probleme:

1. Die gleiche fachliche Entscheidung liegt mal im Task, mal im Shim, mal im V2-Overlay.
2. prepared_input ist nicht als universeller Framework-Standard erzwungen.
3. Das Verhalten aendert sich zwischen Tasks, obwohl der Planner nur ein einheitliches System sehen sollte.

### 4. Konkrete Mismatches zum Queue-Blueprint

#### Queue ist noch nicht die autoritative Runtime

Das Blueprint will eine Queue-getriebene Ausfuehrung. Aktuell ist die Queue vor allem ein Shadow-/Projektionsmodell:

- Queue-Items werden bei handle_command_routing() angelegt.
- Die eigentliche Mutation laeuft aber weiterhin ueber pending intent plus ai_confirm_run.
- ai_confirm_run fuehrt direkt Commands aus und setzt Queue-Status nachtraeglich.

Konsequenz:

- Die Queue beschreibt den Zustand mit, sie steuert ihn aber nicht durchgehend.

#### First-mutation staging lebt weiter

Das Blueprint will mehrere mutierende Commands serverseitig in einer Queue verwalten. Aktuell gilt weiter:

- handle_command_routing() slice_first_mutation_confirmation_stage() behaelt nur die erste Mutation fuer die aktuelle Confirm-Stufe.
- spaetere Mutationen werden nicht als vollwertiger, bereits bestaetigbarer Plan behandelt.

Konsequenz:

- Der Planner bleibt in zusaetzlichen Re-Plan-Runden gefangen.
- Genau das Problem, das der Queue-Blueprint beseitigen will, besteht weiter.

#### Confirmation und blocked_confirmation sind nicht voll vereinheitlicht

blocked_confirmation existiert im Queue-Modell, aber die eigentliche Confirmation-Quelle bleibt pending intent.

Konsequenz:

- Es gibt zwei Wahrheiten fuer dieselbe fachliche Situation:
  - pending intent fuer die UI-/Bestatigungslogik
  - queue item fuer Statusbeobachtung

#### Retry-Pfad ist nicht queue-getrieben

retry_hint ist im Zielbild nicht terminal. Heute fuehrt der V2-Pfad bei retry_hint auf Legacy-Fehlertext zurueck.

Konsequenz:

- retry_hint ist noch kein echter Runtime-Status, sondern nur eine Nebenklassifikation.

### 5. Konkrete Mismatches zum Task-Contract-Blueprint

Das Contract-Dokument formuliert:

- taskname
- version
- capability
- activation
- optional alias_of, deprecated_since, deny_reason

Der aktuelle Code baut Task-Metadaten dagegen so:

- capabilities statt capability
- active statt activation
- activation als callable wird nicht unterstuetzt
- capability wird automatisch aus component plus taskname abgeleitet, nicht deklarativ vom Task geliefert

Konsequenz:

- Der aktuelle Validator passt nicht zum Blueprint-Vertrag.
- Fuer Drittplugins ist das unguenstig, weil Governance nicht ausdruecklich beim Task-/Provider-Vertrag liegt, sondern implizit im Framework generiert wird.

### 6. Konkrete Mismatches im Ergebnisvertrag

Das Blueprint erwartet einen einheitlichen PreflightResult-Typ mit:

- status: pass | soft_block | hard_block | retry_hint
- issue_codes
- blocking_layer: 1 | 2 | 3 | null
- retry_after_ms: number | null
- retry_count
- duration_ms

Der aktuelle preflight_result_v2 ist bereits nah dran, weicht aber fachlich ab:

- blocking_layer ist ein String statt 1|2|3|null
- retry_after_ms ist 0 statt null wenn nicht gesetzt
- der Typ ist kein einziger autoritativer Rueckgabevertrag des Systems
- task_preflight_result existiert parallel weiter

Zusatzbefund:

- Der Mapper task_preflight_result -> preflight_result_v2 behandelt confirmable Ergebnisse semantisch nicht sauber, weil soft_block an !isvalid gekoppelt ist. Das ist latent fehlerhaft, selbst wenn der Mapper aktuell kaum genutzt wird.

### 7. Audit-Logging: nuetzlich, aber nicht sauber abgegrenzt

Positiv:

- preflight_audit_logger existiert.
- audit events werden unveraenderlich im Thread-Metadatenstore angehaengt.

Probleme:

- Logging ist per Flag optional und daher nicht garantiert vorhanden.
- Es mischt heute Preflight-Ereignisse mit API-Mapping-, Confirmation- und Execution-Ereignissen.
- Es ist kein strikt definierter, ausschliesslich preflight-spezifischer Audit-Stream.

Konsequenz:

- Fuer forensische Analyse im autoconfirmmode ist das noch nicht praezise genug.

## Architekturentscheidung fuer die Neuplanung

Weil keine Migration noetig ist, sollte die Zielarchitektur radikal vereinfacht werden.

### Entscheidung 1: Ein einziger autoritativer Preflight-Pfad

Es darf kuenftig genau einen Preflight-Entry geben:

- Runtime baut ein PreflightRequest-Objekt.
- Framework durchlaeuft Layer 1, Layer 2 und Layer 3 in fester Reihenfolge.
- Ergebnis ist genau ein PreflightDecision-Objekt.
- Nur dieses Objekt darf Queue-Status und Confirmation-Verhalten bestimmen.

Kein paralleler Legacy-Pfad mehr.

### Entscheidung 2: Queue wird Single Source of Truth

Mutating Commands duerfen nicht mehr ueber pending intent als primaere Wahrheit laufen.

Stattdessen:

- jedes mutating command erzeugt ein queue item,
- blocked_confirmation lebt nur noch als Queue-Status,
- confirmation token referenziert queue item ids,
- ai_confirm_run bestaetigt queue items, nicht rohe command arrays.

### Entscheidung 3: Task-API wird vereinheitlicht

Zielvertrag fuer alle Tasks:

- check_structure(input): rein, synchron, I/O-frei
- preflight(request, context): read-only, darf normalized/prepared input zurueckgeben
- execute(prepared_input, context): mutierend oder readonly

Legacy validate() wird in der Zielarchitektur nicht mehr mitgetragen.

### Entscheidung 4: Sprachagnostik nur ueber Codes und strukturierte Felder

Der Planner und die Runtime duerfen keine Steuerlogik ueber freie Fehlertexte oder sprachabhaengige Tokens ableiten.

Stattdessen muss jede relevante Entscheidung auf strukturierten Feldern beruhen:

- issue_codes
- deny_reason
- mutability
- task contract metadata
- queue status
- response_type

### Entscheidung 5: Drittplugin-Faehigkeit wird in den Vertrag eingebaut

Drittplugins sollen nicht nur Tasks registrieren koennen. Sie muessen denselben Preflight-Vertrag erfuellen.

Pflicht pro mutating task:

- task metadata contract
- input schema contract
- check_structure()
- preflight()
- execute()
- capability declaration
- activation rule
- optionale issue code provider

Readonly-Tasks duerfen eine vereinfachte Form haben, aber auch sie muessen mindestens structure validation explizit deklarieren.

## Zielbild: Best Practice Preflight Architecture

### A. Kernobjekte

#### PreflightRequest

Pflichtfelder:

- queue_item_id
- thread_id
- run_id
- step_id
- taskname
- raw_input
- current_prepared_input
- mutability
- retry_count
- user_id
- cmid
- context_id
- output_lang
- autoconfirm_mode

Optional:

- depends_on snapshot
- previous_audit_entry_ids
- provider_component
- planner_step_reference

#### PreflightDecision

Pflichtfelder:

- status: pass | soft_block | hard_block | retry_hint
- issue_codes: string[]
- blocking_layer: 1 | 2 | 3 | null
- retry_after_ms: int | null
- retry_count: int
- duration_ms: int
- prepared_input: array | null
- user_message_key: string | null
- user_message_params: array
- audit_payload: array

Wichtig:

- prepared_input gehoert in den Ergebnisvertrag.
- Das verhindert doppelte Aufloesung spaeter im Executor.

### B. Layer 1: Contract and Schema Gate

Layer 1 prueft ausschliesslich Dinge, die ohne Domain-I/O entschieden werden koennen:

- command envelope
- registrierter taskname
- task version supported oder deprecated
- task contract validity
- task activation
- mutability declaration
- input schema strict validation
- allowed fields
- depends_on syntax

Schicht-1-Regel:

- kein DB-Zugriff
- keine Provider-I/O
- keine Seiteneffekte
- hart terminal bei Fehler

Ergebnis:

- hard_block mit issue_code schema_error oder contract_error
- oder pass mit normalisiertem raw_input

Versionierungsdetails fuer Layer 1:

- Task-Contract enthaelt version als Pflichtfeld.
- Registry liefert pro taskname eine Supported-Version-Regel mit mindestens min_supported_version.
- Wenn task.version kleiner als min_supported_version ist: hard_block mit TASK_VERSION_UNSUPPORTED.
- Wenn task.version als deprecated markiert ist: strukturierte Rueckmeldung ueber TASK_VERSION_DEPRECATED und konfigurierbares Verhalten hard_block oder soft_block.
- Versionierungsentscheidungen werden im Preflight-Audit mit taskname, task_version und supported_rule protokolliert.

### C. Layer 2: Domain Gate

Layer 2 fuehrt echte fachliche Pruefungen aus. Nicht als nachtraegliche Klassifikation, sondern als autoritativen Check-Runner.

Pflicht-Checks fuer mutating tasks:

1. Permission check
2. Context validity check
3. Precondition check
4. Conflict check
5. Task-local preflight check

Wichtig fuer Drittplugins:

- Framework stellt eine Standard-Schnittstelle fuer Checks bereit.
- Plugins duerfen eigene task-local checks implementieren.
- Die Rueckgabe muss aber immer in standardisierte CheckResult-Objekte gemappt werden.

CheckResult-Vertrag:

- check_name
- status: pass | soft_block | hard_block | retry_hint
- issue_codes
- prepared_input_patch
- debug_context

Ausfuehrung:

- parallelisierbar, wo fachlich moeglich,
- aber mit klarer Aggregationsregel,
- keine Writes,
- gemeinsamer Timeout,
- kein Textparsing als Steuerlogik.

### D. Layer 3: Execution Gate

Layer 3 entscheidet nicht ueber Fachlogik, sondern ueber Wiederholung und Laufzeitsteuerung.

Pflichtregeln:

- retry_hint ist nicht terminal,
- retry_count lebt am queue item,
- backoff wird nur hier berechnet,
- max_retries lebt nur hier,
- hard_block bei Exhaustion,
- soft_block bleibt blocked_confirmation,
- pass fuehrt zu ready oder blocked_confirmation je nach confirmation mode.

Formel:

- backoff_ms = base_ms * 2^retry_count + jitter

Empfehlung fuer Startwerte:

- base_ms = 500
- jitter_ms = 200
- max_retries = 4

### E. Queue-State Mapping

PreflightDecision muss deterministisch auf Queue-Status mappen:

- pass + autoconfirm=true -> ready
- pass + autoconfirm=false -> blocked_confirmation
- soft_block -> blocked_confirmation
- retry_hint -> retry_waiting
- hard_block -> failed

Wichtig:

- blocked_confirmation ist kein Nebenkanal.
- retry_waiting ist kein Fehlertext, sondern ein echter Queue-Status.

## Zielbild fuer Task-Konsistenz

### Pflicht fuer mutating tasks

Jeder mutating task muss implementieren:

- get_name()
- get_schema()
- get_task_contract()
- check_structure()
- preflight()
- execute()
- is_read_only() == false

### Pflicht fuer readonly tasks

Jeder readonly task muss implementieren:

- get_name()
- get_schema()
- get_task_contract()
- check_structure()
- execute()

Readonly-Tasks duerfen ein explizites no-op preflight() liefern, aber kein implizites Legacy-Shim.

### Verbot in der Zielarchitektur

- kein Legacy validate() als Runtime-Pfad
- keine implizite Default-Validation fuer neue Tasks
- keine freie Interpretation von Fehlermeldungen als Steuerlogik
- keine task-spezifische Sonderverdrahtung fuer Queue oder Confirmation ausserhalb des Contracts

## Zielbild fuer Drittplugins

### Task-Provider Contract

Das Framework sollte den Contract aus dem Blueprint direkt uebernehmen und auf einen eindeutigen Provider-Vertrag abbilden:

- taskname
- version
- capability
- activation
- optional alias_of
- optional deprecated_since
- optional deny_reason
- readonly
- mutability
- provider_component

Entscheidung:

- capability bleibt deklarativ beim Task, nicht implizit auto-generiert.
- activation darf bool oder callable sein, aber muss vor Queue-Ingest ausgewertet werden koennen.

### Provider onboarding

Ein Drittplugin wird nur registriert, wenn:

- sein Task-Contract valide ist,
- sein Input-Schema valide ist,
- seine Pflichtmethoden vorhanden sind,
- sein Mutability-Flag konsistent ist,
- seine issue_codes namespace-sicher sind.

Empfehlung fuer issue codes:

- booking.* fuer booking domain
- core.* fuer frameworknahe Codes
- plugincomponent.* fuer Drittplugins

## Zielbild fuer Audit und Beobachtbarkeit

Audit darf kein gemischtes Debug-Log sein. Es braucht einen klaren, preflight-spezifischen Event-Typ.

Pflichtfelder pro Audit-Event:

- event_id
- timestamp
- thread_id
- run_id
- queue_item_id
- taskname
- layer
- decision_status
- issue_codes
- retry_count
- retry_after_ms
- duration_ms
- autoconfirm_mode
- provider_component

Wichtige Regel:

- jeder Preflight-Durchlauf schreibt genau einen Abschluss-Event,
- optional zusaetzlich pro Layer einen Detail-Event,
- pass wird genauso geloggt wie block.

## Zielbild fuer Confirmation und Agentic Loop

### Confirmation

Confirmation darf nicht mehr auf pending raw commands beruhen.

Stattdessen:

- UI bestaetigt queue_item_ids oder confirmation bundle ids,
- Runtime laedt prepared_input aus der Queue,
- confirm_pending als eigener Nebenpfad entfällt,
- blocked_confirmation TTL wird an Queue-Objekten ausgewertet,
- Re-Preflight bei TTL-Ueberschreitung ist Framework-Regel, nicht Task-Sonderfall.

### Agentic Loop

Der Loop arbeitet nur noch gegen Beobachtungen aus:

- Queue transition events
- execution results
- observation builder
- repair-needed classification

Der Planner sieht keine impliziten Runtime-Sonderfaelle, sondern nur strukturierte Observations.

## Zielbild fuer Tests

### Pflicht-Contract-Tests

- jeder Task mit gueltigem Contract
- jeder Task mit gueltigem Input-Schema
- keine doppelten tasknames oder aliases
- capability und activation sauber validiert

### Pflicht-Preflight-Tests

- pass, soft_block, hard_block, retry_hint je Schicht
- prepared_input persistence
- autoconfirm vs normal confirmation
- blocked TTL
- retry_waiting mit backoff
- max_retries_exceeded

### Pflicht-Queue-Tests

- mutating batch mit depends_on
- mixed readonly + mutating
- skipped nach failed dependency
- scheduler lock
- dedupe ueber input_signature

### Pflicht-Plugin-Tests

- Drittplugin task registration
- Drittplugin preflight mapping
- plugin-specific issue code namespace
- activation callable

## Empfohlene Umsetzungsreihenfolge fuer eine saubere Neuentwicklung

Da keine Migration noetig ist, sollte die Umsetzung nicht in Kompatibilitaetsschichten gedacht werden, sondern in Architektur-Schnitten.

### Paket 1: Contracts zuerst

- finaler Task-Contract
- finaler PreflightRequest
- finaler PreflightDecision
- finaler Queue-Item-Vertrag

### Paket 2: Queue als Autoritaet

- confirmation an queue items koppeln
- pending intent entkoppeln oder entfernen
- worker-/pickup-Regeln finalisieren

### Paket 3: Layer 1 und Layer 3 framework-first

- strict schema gate
- strict execution gate
- audit stream

### Paket 4: Layer 2 task migration framework-driven

- mutating tasks zuerst
- danach readonly tasks
- danach core tasks und plugin provider templates

### Paket 5: Observation und Repair Loop

- execution failure -> observation
- repair-needed -> new queue item
- budget guard vor jedem neuen planner call

## Abschlussbewertung

Die aktuelle Implementierung ist kein Fehlstart. Sie ist ein brauchbarer Uebergangszustand.

Aber fuer das formulierte Zielbild gilt klar:

- Die Queue ist noch nicht autoritativ.
- Preflight V2 ist noch nicht der eine steuernde Pfad.
- Die Task-Landschaft ist nicht konsistent migriert.
- Der Contract fuer Drittplugins ist noch nicht deckungsgleich mit dem Blueprint.

Die beste naechste Planung ist deshalb nicht "mehr V2 ueber Legacy legen", sondern:

- einen einzigen Preflight-Vertrag definieren,
- Queue und Confirmation vereinheitlichen,
- Legacy validate() aus der Zielarchitektur streichen,
- alle Tasks und Provider auf einen sprachagnostischen, strukturierten Framework-Vertrag zwingen.

Wenn diese vier Punkte festgezogen werden, passt die Preflight-Architektur sauber zur Task-Queue, zum Agentic Loop und zu einem spaeteren Drittplugin-Oekosystem.