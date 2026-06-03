# Refactoring Prompt: Task Risk Classes — Agent Framework

## Ziel

Einführung eines expliziten Risikoklassen-Systems (`task_risk_class`) für alle Tasks im Agent-Framework. Der erste Schritt ist die Einarbeitung in den Architektur-Flowchart. Spätere Schritte (Implementierung, Tests, Migration) bauen auf dem aktualisierten Flowchart auf.

---

## Kontext

Das Framework kennt aktuell keine formalen Risikoklassen. Die Entscheidung, ob ein Task sofort ausgeführt, in die Queue gestellt oder durch eine manuelle Confirmation geblockt wird, ergibt sich implizit aus `is_read_only()` und der Session-Allow-Logik. Das führt dazu, dass sicherheitsrelevante Entscheidungen über mehrere Schichten verteilt sind und keine einheitliche, deklarative Quelle der Wahrheit existiert.

Das neue System führt vier Klassen ein, die quer durch alle Schichten wirken:

| Klasse | Name | Beschreibung |
|---|---|---|
| R0 | `read_only` | Reine Leseoperationen, keine Seiteneffekte |
| R1 | `scoped_write` | Schreiboperation, betrifft nur den anfragenden User, reversibel |
| R2 | `broad_write` | Schreiboperation, betrifft andere User oder Kursstrukturen |
| R3 | `irreversible_or_external` | Externe Effekte oder nicht rückgängig machbar (E-Mail, Zahlung, Zertifikat) |

---

## Schritt 1: Flowchart-Update

### Aufgabe

Arbeite die Risikoklassen in den bestehenden Mermaid-Flowchart ein. Der Flowchart soll nach diesem Update zeigen:

1. **Wo die Klasse deklariert wird** — `task_interface` und `task_prompt_contract`
2. **Wo die Klasse gelesen und durchgesetzt wird** — `task_contract_validator`, `preflight_pipeline`, `queue_manager`, `agent_decision_service`, `synchronizer`
3. **Welche Pfade sich je nach Klasse unterscheiden** — insbesondere Confirmation-Pflicht, Session-Allow-TTL, Retry-Erlaubnis, Synchronizer-Verhalten

### Konkrete Flowchart-Änderungen

#### A — Task Layer

Ergänze in `task_interface`:
```
get_risk_class(): task_risk_class
// R0 | R1 | R2 | R3
```

Ergänze in `task_prompt_contract DTO`:
```
risk_class: task_risk_class
// deklarativ, validiert durch task_contract_validator
```

Neuer Node `task_risk_class`:
```
task_risk_class (enum/DTO)
R0: read_only
R1: scoped_write
R2: broad_write
R3: irreversible_or_external
```

#### B — task_contract_validator

Erweitere den bestehenden Node um:
```
+ verify_risk_class_declaration()
  → R0 must have is_read_only() = true
  → R2/R3 must declare explicit scope in contract
  → mismatch → deny (task not activatable)
```

Die Klasse wird hier strukturell verifiziert, nicht nur durchgereicht. Das verhindert versehentliche Herabstufungen ohne Konsequenzen.

#### C — agent_decision_service

Ergänze in `D_PROMOTE` (mutating task_call → confirmation_request):
```
D_PROMOTE
+ risk_class gating:
  R1 → session-allow ok (TTL: 900s)
  R2 → session-allow ignoriert, immer explizit
  R3 → immer manuell, kein session-allow
```

Neuer Node oder Annotation an `D_CMD_ROUTE`:
```
D_CMD_ROUTE
+ R0 → readonly staged execute (unverändert)
+ R1 → queue mit session-allow
+ R2 → queue, confirmation erzwungen
+ R3 → queue, manuell, extra budget step
```

#### D — Preflight Pipeline

Annotiere `PP_RUN` mit:
```
preflight_pipeline::run()
+ risk_class bestimmt aktive Layer:
  R0 → kein Preflight (kein Queue-Eintrag)
  R1 → L1 + L2
  R2 → L1 + L2 + L3
  R3 → L1 + L2 + L3 + external_dependency_check
```

Neuer Node in Layer 3 oder als Erweiterung von `PF_L3`:
```
PF_L3_EXT [R3 only]
external_dependency_check
→ webhook erreichbar?
→ payment provider ready?
→ hard_block bei Nichtverfügbarkeit
```

#### E — Queue Manager

Annotiere `Q_BLOCKED` mit:
```
Q_BLOCKED
+ R1: blocked_expires_at = now + 900s
+ R2: blocked_expires_at = now + 300s
+ R3: blocked_expires_at = now + 900s,
      manuell only (kein auto-consume)
```

Annotiere `Q_RETRY` mit:
```
Q_RETRY
+ R3: retry nach Execution verboten
      → direkt FAIL_OUT bei transient error
```

#### F — Synchronizer

Erweitere `SYNC_GATE` mit expliziter Risikoklassen-Logik:
```
SYNC_GATE
message-bearing terminal state?
  + R0 sufficient    → SYNC_RUN (optional, kein LLM nötig)
  + R1/R2 sufficient → SYNC_RUN (LLM polish)
  + R3 sufficient    → SYNC_RUN (LLM + irreversibility notice)
  + clarification    → DIRECT_FINAL (Struktur schützen)
  + confirmation_request → DIRECT_FINAL oder SYNC_RUN mit rollback-guard
  + error / budget_exceeded → SYNC_RUN (humanize, template-only für hard errors)
  + structural failures → DIRECT_FINAL
```

Ergänze in `SCONTRACT`:
```
synchronizer_output_contract
+ must NOT invent commands
+ must NOT mutate execution semantics
+ R3: must include irreversibility_notice flag
+ R2: must include affected_scope_summary
+ bei command-semantics-Abweichung:
    → Synchronizer-Output verworfen
    → Planner-Output direkt verwendet (rollback)
```

#### G — Legend

Füge folgende neue Legend-Einträge hinzu:

```
LG_RISK["Risk class contract
task declares R0–R3 in task_interface
validated by task_contract_validator
enforced in preflight, queue, decision service, synchronizer"]

LG_RISK_CONF["Confirmation gating by risk class
R0: never
R1: session-allow ok (900s TTL)
R2: always explicit (300s TTL)
R3: always manual, no session-allow"]

LG_RISK_RETRY["Retry policy by risk class
R0/R1/R2: retry allowed per backoff
R3: no execution retry (idempotency critical)"]

LG_RISK_SYNC["Synchronizer contract by risk class
R3: irreversibility_notice required
R2: affected_scope_summary required
rollback to planner output on semantic drift"]
```

---

## Schritte 2–N (folgen nach Flowchart-Abnahme)

Diese Schritte werden erst nach Review und Abnahme des aktualisierten Flowcharts gestartet:

**Schritt 2 — Interface + DTO**
- `task_risk_class` als Enum/Konstante einführen
- `task_interface::get_risk_class()` deklarieren
- `task_prompt_contract` um `risk_class` erweitern

**Schritt 3 — task_contract_validator**
- `verify_risk_class_declaration()` implementieren
- R0/R3-Invarianten prüfen (is_read_only match, scope-Deklaration)
- Fehlende Klassen-Deklaration = deny (keine stillen Defaults)

**Schritt 4 — agent_decision_service**
- `D_PROMOTE` und `D_CMD_ROUTE` um Risikoklassen-Gating erweitern
- Session-Allow-TTL je nach Klasse setzen
- R3: session-allow komplett deaktivieren

**Schritt 5 — preflight_pipeline**
- Layer-Aktivierung je nach Risikoklasse steuern
- `PF_L3_EXT` für R3 implementieren (external_dependency_check interface)

**Schritt 6 — queue_manager**
- TTL-Werte je nach Risikoklasse setzen
- R3: Retry nach Execution sperren, direkt auf FAIL_OUT

**Schritt 7 — synchronizer**
- `SYNC_GATE` um Risikoklassen-Logik erweitern
- `SCONTRACT` um `irreversibility_notice` und `affected_scope_summary` erweitern
- Rollback-Guard bei semantischer Drift implementieren

**Schritt 8 — Task-Migration**
- Alle bestehenden Tasks mit expliziter `risk_class` annotieren
- Review: bestehende `is_read_only()` = true Tasks → R0 kandidieren
- Booking-Tasks einzeln prüfen (insbesondere R2/R3-Kandidaten)

**Schritt 9 — Tests**
- Unit: task_contract_validator Risikoklassen-Checks
- Integration: Confirmation-Gating je Klasse
- Integration: Synchronizer-Rollback bei semantic drift
- E2E: R3-Task durch kompletten Pfad (kein session-allow, kein retry, irreversibility_notice)

---

## Invarianten (dürfen in keinem Schritt verletzt werden)

1. **Keine stillen Defaults** — eine Task ohne deklarierte `risk_class` ist nicht aktivierbar.
2. **R0 ist immer read-only** — `get_risk_class() = R0` und `is_read_only() = false` ist ein Validator-Fehler.
3. **R3 hat kein session-allow** — diese Invariante gilt auch bei aktivem allow_session flag.
4. **R3 hat keinen Execution-Retry** — nach erstem transient error direkt FAIL_OUT.
5. **Synchronizer darf keine Command-Semantik ändern** — bei Abweichung wird Planner-Output verwendet.
6. **Risikoklasse ist deklarativ, nicht heuristisch** — kein Framework-Code leitet die Klasse aus Task-Namen, Feldern oder anderen Heuristiken ab.

---

## Soll-Ist-Abgleich (Stand 2026-06-01)

Geprüft gegen die aktuelle Codebasis des bookingextension_agent-Frameworks. Zeigt, was bereits existiert und wo die Lücken für die Implementierung liegen.

### Bereits vorhanden

| Komponente | Datei | Status |
|---|---|---|
| `task_interface` | `interfaces/task_interface.php` | vorhanden, `get_risk_class()` ergänzt |
| `task_prompt_contract` DTO | `services/task_prompt_contract.php` | vorhanden, `risk_class` ergänzt |
| `base_task` | `base_task.php` | vorhanden, kennt `readonly` + `risk_class` |
| `task_contract_validator` | `task_contract_validator.php` | vorhanden, prüft readonly/alias + risk_class-Invarianten |
| `task_registry` / `build_prompt_contract` | `task_registry.php` | vorhanden, transportiert `readonly` + `risk_class` |
| `preflight_pipeline` L1→L2→L3 | `services/preflight_pipeline.php` | vorhanden, Layer-Aktivierung **noch** nicht risikoabhängig |
| `preflight_contract_validator` (L1) | `services/preflight_contract_validator.php` | vorhanden |
| `preflight_domain_check_runner` (L2) | `services/preflight_domain_check_runner.php` | vorhanden |
| `preflight_execution_gate` (L3) | `services/preflight_execution_gate.php` | vorhanden, Backoff generisch |
| `agent_decision_service` routing | `services/decision/agent_decision_service.php` | vorhanden, Gating nur via `is_read_only()` + session-allow |
| `queue_manager` | `queue/queue_manager.php` | vorhanden, TTL global (`queue_blocked_ttl_seconds`), kein risk_class-Split |
| `queue_transition_service` | `services/queue_transition_service.php` | vorhanden, Statusmapping ohne Risikoklasse |
| `confirm_run_service` + `build_retry_decision` | `services/confirm_run_service.php` | vorhanden, Retry generisch via `preflight_execution_gate` |
| Finalisierung/Synchronizer | `agent_runtime.php` + `services/finalization_classifier.php` | vorhanden als Final Synthesis (kein separates Synchronizer-Objekt) |
| `booking_task_base` (mod_booking) | `mod/booking/classes/local/wbagent/options/tasks/booking_task_base.php` | vorhanden |
| `base_entities_task` (local_entities) | `local/entities/classes/local/wbagent/tasks/base_entities_task.php` | vorhanden |
| `core_task_base` (bookingextension_agent) | `core/tasks/core_task_base.php` | vorhanden |

### Noch nicht vorhanden (Implementierungslücken)

- Keine risikoabhängige Layer-Aktivierung in `preflight_pipeline`
- Kein `PF_L3_EXT`-Interface oder -Implementierung für R3
- Kein risk_class-Feld in Queue-Items (`queue_manager::enqueue_command`)
- Keine risk_class-abhängige TTL-Steuerung in `queue_manager::resolve_blocked_expires_at`
- Kein risk_class-Gate in `queue_transition_service::apply_preflight_decision`
- Keine R3-Sonderpfade in `confirm_run_service::build_retry_decision`
- Kein `irreversibility_notice`/`affected_scope_summary` in Synchronizer-Contract
- Keine risk_class-abhängige Decision-Service-Gating-Logik in `agent_decision_service`
- Keine Synchronizer-Risk-Guards in `agent_runtime::apply_synchronizer_message_polish()`

---

## Implementierungsplan (mit Checkboxen)

Vollständiger Arbeitsplan bereit für spätere Ausführung. Jeder Abschnitt entspricht einer Phase; innerhalb einer Phase können Punkte parallel bearbeitet werden, sofern keine Abhängigkeit angegeben ist.

### Phase 1 — Vertragsgrundlage (DTO + Interface)

**Neue Datei:** `classes/local/wbagent/dto/task_risk_class.php`
- [x] Klasse `task_risk_class` anlegen mit Klassenkonstanten: `R0 = 'read_only'`, `R1 = 'scoped_write'`, `R2 = 'broad_write'`, `R3 = 'irreversible_or_external'`
- [x] `is_valid(string $class): bool` als statische Hilfsmethode bereitstellen

**Datei:** `classes/local/wbagent/interfaces/task_interface.php`
- [x] Methode `get_risk_class(): string` in `task_interface` deklarieren (Return-Wert: eine der `task_risk_class`-Konstanten)

**Datei:** `classes/local/wbagent/base_task.php`
- [x] `protected string $riskclass` Property einführen (kein Default; fehlendes Setzen im Konstruktor führt zu Validator-Fehler)
- [x] `__construct(bool $readonly, string $riskclass)` Signatur anpassen
- [x] `get_risk_class(): string` implementieren

**Datei:** `classes/local/wbagent/services/task_prompt_contract.php`
- [x] `risk_class` in `to_array()` ergänzen
- [x] Validierung: leerer risk_class-Wert → normalisiert auf `''` (der Validator prüft Vollständigkeit, nicht hier)

### Phase 2 — Governance-Validator

**Datei:** `classes/local/wbagent/task_contract_validator.php`
- [x] `build_task_metadata()` um `risk_class` aus `$task->get_risk_class()` erweitern
- [x] `validate_task_metadata()` um Pflichtfeld-Prüfung für `risk_class` erweitern (leer → Fehler)
- [x] `verify_risk_class_consistency()` implementieren:
  - [x] R0 + `is_read_only() = false` → Fehler
  - [x] R1/R2/R3 + `is_read_only() = true` → Fehler
  - [x] R2/R3 ohne `context_scopes`-Deklaration im Prompt-Contract → Warnung (kein hard deny, aber Diagnostic)
  - [x] Unbekannter Wert (nicht R0–R3) → Fehler

**Datei:** `classes/local/wbagent/task_registry.php`
- [x] `build_prompt_contract()` um `risk_class` aus `task->get_risk_class()` ergänzen
- [x] `get_task_contracts()` transportiert `risk_class` in Metadaten

### Phase 3 — Decision Service Gating

**Datei:** `classes/local/wbagent/services/decision/agent_decision_service.php`

- [x] `split_commands_by_mutability()` zu `split_commands_by_risk_class()` erweitern; Rückgabe enthält vier Gruppen (`r0`, `r1`, `r2`, `r3`)
- [x] `has_mutating_commands()` bleibt erhalten, liest künftig aus risk_class (nicht aus is_read_only)
- [x] `D_PROMOTE`-Logik (task_call → confirmation_request) um risk_class-Gate erweitern:
  - [x] R0: niemals promoten
  - [x] R1: session-allow wird geprüft; bei aktivem allow → direkte Ausführung, TTL 900s
  - [x] R2: session-allow wird **ignoriert**; immer Confirmation erzwingen
  - [x] R3: session-allow wird **ignoriert**; immer Confirmation, kein auto-confirm
- [x] `handle_command_routing()` Queue-Ingestion um risk_class-Feld im Enqueue-Call erweitern
- [x] `pending_intent` payload um `risk_class` je Queue-Item ergänzen (für downstream TTL-Entscheidung)

### Phase 4 — Preflight Pipeline

**Datei:** `classes/local/wbagent/services/preflight_pipeline.php`

- [x] Pro Command risk_class aus Registry lesen (`$task->get_risk_class()`)
- [x] Layer-Aktivierungslogik implementieren:
  - [x] R0: kein Preflight (kein Queue-Eintrag, direkte readonly execute)
  - [x] R1: L1 + L2
  - [x] R2: L1 + L2 + L3
  - [x] R3: L1 + L2 + L3 + `PF_L3_EXT`
- [x] `PF_L3_EXT` Interface definieren:
  - [x] **Neue Datei:** `classes/local/wbagent/interfaces/external_dependency_checker_interface.php`
  - [x] Methode: `check(array $command, int $contextid, int $userid): preflight_result_v2`
- [x] Default-Stub-Implementierung:
  - [x] **Neue Datei:** `classes/local/wbagent/services/noop_external_dependency_checker.php`
  - [x] Gibt immer `preflight_result_v2::ok($input)` zurück

### Phase 5 — Queue Manager

**Datei:** `classes/local/wbagent/queue/queue_manager.php`

- [x] `enqueue_command()`: `risk_class`-Feld in das Queue-Item schreiben (aus `$command['risk_class']` lesen, geliefert vom Decision Service)
- [x] `resolve_blocked_expires_at(string $status, int $now)` zu `resolve_blocked_expires_at(string $status, int $now, string $riskclass = '')` erweitern:
  - [x] R1: TTL = 900s (oder konfigurierbarer Default)
  - [x] R2: TTL = 300s
  - [x] R3: TTL = 900s (aber kein auto-consume durch session-allow)
  - [x] Fallback: bisher konfigurierter `queue_blocked_ttl_seconds`-Wert
- [x] `DEFAULT_BLOCKED_TTL_SECONDS`-Konstante bleibt für Nicht-risk-class-Pfade

### Phase 6 — Queue Transition Service

**Datei:** `classes/local/wbagent/services/queue_transition_service.php`

- [x] `apply_preflight_decision()`: risk_class aus Queue-Item lesen (`$item['risk_class']`)
- [x] Bei R3 + `$autoconfirmmode = true`: autoconfirm ignorieren, trotzdem `blocked_confirmation`
- [x] `to_blocked_confirmation()`: TTL-Override über risk_class in `queue_manager` delegieren (kein direkter TTL-Wert hardcoden)

### Phase 7 — Confirm Run Service / Execution Retry

**Datei:** `classes/local/wbagent/services/confirm_run_service.php`

- [x] `build_retry_decision()`: risk_class aus Queue-Item lesen
- [x] R3 + retryable error class → direkt `queue_status = 'failed'` (kein `retry_hint` für R3)
- [x] Annotation im Code: "R3 tasks are idempotency-critical; retry after execution is forbidden"

### Phase 8 — Finalisierung / Synchronizer-Contract

**Datei:** `classes/local/wbagent/services/finalization_classifier.php`

- [x] `classify()` um risk_class-Routing erweitern:
  - [x] R3 + sufficient: `STRATEGY_LLM_POLISH` mit `irreversibility_notice`-Flag
  - [x] R2 + sufficient: `STRATEGY_LLM_POLISH` mit `affected_scope_summary`-Flag
  - [x] Strukturfehler (alle Klassen): immer `STRATEGY_DIRECT_FINAL`

**Datei:** `classes/local/wbagent/agent_runtime.php`

- [x] `merge_synchronized_message()` um Rollback-Guard erweitern:
  - [x] Wenn Sync-Output Commands enthält → rollback (bereits vorhanden, erweitern)
  - [x] Wenn `response_type` abweicht → rollback (bereits vorhanden, erweitern)
  - [x] Neu: R3 → wenn `irreversibility_notice` im Sync-Output fehlt → rollback auf source result
  - [x] Neu: R2 → wenn `affected_scope_summary` im Sync-Output fehlt → Warnung, kein Rollback

### Phase 9 — Task-Migration (alle existierenden Tasks)

> **Arbeitsregel:** Jede Task-Klasse setzt `risk_class` explizit im Konstruktor. Kein Framework-Code leitet die Klasse her.
>
> Migration über `base_task`-Konstruktor (`parent::__construct(bool $readonly, string $riskclass)`). Tasks in `booking_task_base` und `base_entities_task` bekommen analoge Konstruktor-Erweiterungen.

#### bookingextension_agent Core Tasks

| Task | Klasse | Datei | risk_class | is_read_only | Checkbox |
|---|---|---|---|---|---|
| `core.list_actions` | `list_actions_task` | `core/tasks/list_actions_task.php` | R0 | true | - [x] |
| `core.get_current_user` | `get_current_user_task` | `core/tasks/get_current_user_task.php` | R0 | true | - [x] |
| `core.search_users` | `search_users_task` | `core/tasks/search_users_task.php` | R0 | true | - [x] |
| `core.search_courses` | `search_courses_task` | `core/tasks/search_courses_task.php` | R0 | true | - [x] |
| `core.recall_memory` | `recall_memory_task` | `core/tasks/recall_memory_task.php` | R0 | true | - [x] |
| `core.recreate_task_catalog` | `recreate_task_catalog_task` | `core/tasks/recreate_task_catalog_task.php` | R2 | false | - [x] |

#### mod_booking Tasks

| Task | risk_class | is_read_only | Checkbox |
|---|---|---|---|
| `mod_booking.analyze_rules` | R0 | true | - [x] |
| `mod_booking.search_options` | R0 | true | - [x] |
| `mod_booking.list_option_properties` | R0 | true | - [x] |
| `mod_booking.get_option_details` | R0 | true | - [x] |
| `mod_booking.diagnose_booking_issue` | R0 | true | - [x] |
| `mod_booking.diagnose_cancellation_issue` | R0 | true | - [x] |
| `mod_booking.explain_docs_topic` | R0 | true | - [x] |
| `mod_booking.create_option` | R2 | false | - [x] |
| `mod_booking.create_slotbooking_option` | R2 | false | - [x] |
| `mod_booking.create_selflearning_option` | R2 | false | - [x] |
| `mod_booking.update_option` | R2 | false | - [x] |
| `mod_booking.bulk_update_options` | R2 | false | - [x] |
| `mod_booking.configure_booking_instance` | R2 | false | - [x] |
| `mod_booking.create_rule_from_template` | R2 | false | - [x] |
| `mod_booking.update_rule_from_template` | R2 | false | - [x] |
| `mod_booking.book_users` | R3 | false | - [x] |
| `mod_booking.add_price_category` | R2 | false | - [x] |

> **Hinweis:** `booking_task_base.__construct` übernimmt `risk_class` und reicht an `base_task` weiter. Konkrete Klassen setzen den Wert explizit im eigenen Konstruktor.

#### local_entities Tasks

| Task | risk_class | is_read_only | Checkbox |
|---|---|---|---|
| `entities.search` | R0 | true | - [x] |
| `entities.list_all_entities` | R0 | true | - [x] |
| `entities.create_entity` | R2 | false | - [x] |

> **Hinweis:** `base_entities_task.__construct` analog erweitern.

#### local_shopping_cart

- [ ] Kein `task_provider` in diesem Workspace gefunden (Stand 2026-06-01). Bei zukünftiger Integration: Task-Migration hier ergänzen.

### Phase 10 — Tests

> Alle neuen Testdateien liegen unter `public/mod/booking/bookingextension/agent/tests/`.

**Unit-Tests**

- [x] Contract-Tests rund um task-validator und queue-confirmation erweitert:
  - [x] R0 + `is_read_only = false` → Validator-Fehler
  - [x] R3 + session-allow-Invariante geprüft
  - [x] fehlende risk_class → not_activatable
  - [x] unbekannter risk_class-Wert → Fehler
  - [ ] alias_of kombiniert mit risk_class-Mismatch (Warnung)

- [x] Neue Datei `tests/agent/preflight_pipeline_risk_class_contract_test.php`:
  - [x] risk_class resolution honors batch ordering and registry fallbacks

- [x] Neue Datei `tests/agent/queue_risk_class_contract_test.php`:
  - [x] R1-Item: `blocked_expires_at = now + 900s`
  - [x] R2-Item: `blocked_expires_at = now + 300s`
  - [x] R3-Item: `blocked_expires_at = now + 900s`

**Integration-Tests**

- [x] Neue Datei `tests/agent/decision_service_risk_gating_test.php`:
  - [x] risk_class lookup falls back to registry and command batches are annotated before routing

- [x] Neue Datei `tests/agent/synchronizer_risk_contract_test.php`:
  - [x] R3 sufficient outputs require `irreversibility_notice`
  - [x] R2 sufficient outputs require `affected_scope_summary`

**E2E-Tests**

- [x] Neue Datei `tests/agent/r3_task_e2e_test.php`:
  - [x] R3-Task über Queue blocked → manuelles Confirm → Execution; Invariante: kein `retry_waiting` nach Confirm-Execution

---

## Offene Fragen / Entscheidungsbedarfe (vor Implementierungsstart klären)

1. **R1 im aktuellen Framework**: Gibt es bereits Kandidaten, die R1 wären (write, aber nur eigener User)? Wenn nicht, kann R1 vorerst als Reserved deklariert werden und alle Mutations starten als R2.

-- Antwort: book user, wenn es den eigenen user betrifft wäre R1.

2. **`booking_task_base`-Konstruktor-Signaturänderung**: Breaking Change für alle 17 mod_booking-Tasks. Soll der Default-Parameter `string $riskclass = ''` verwendet werden (verzögerte Pflicht per Validator) oder harter Pflicht-Parameter (sofortige Pflicht)?

-- Antwort: Sofortige Pflicht, wir haben keinen Produktivbetrieb und somit auch keine Notwendigkeit einer Migration oder Rücksicht auf früheren Code stand.
3. **R3-Kandidaten**: Aktuell keine R3-Tasks im Workspace. `mod_booking.book_users` könnte R3 sein (E-Mail-Trigger). Benötigt Review vor Task-Migration.

-- Antwort: Aktuell mod_booking.book_users wenn es andere user betrifft -> R3.
4. **`PF_L3_EXT`-Stub**: Soll der Noop-Stub als separates Setting abschaltbar sein (z. B. `queue_r3_ext_check_enabled`), oder ist er immer aktiv?
5. **Synchronizer als eigenständiges Objekt**: Das Dokument beschreibt einen `Synchronizer`; in der Realität ist dies `agent_runtime::apply_synchronizer_message_polish`. Bei der Implementierung kein separates Synchronizer-Objekt einführen, sondern an der bestehenden Methode bleiben.

-- Antwort: Absolut an der bestehenden Methode bleiben! Nichts neu einführen.
6. **Backward-Compatibility bei task_contract_validator**: Tasks ohne risk_class müssen nach Migration sofort fehlschlagen (hart), oder soll es eine Grace-Period mit Diagnostic-Warnings geben?

-- Antwort: Hart fehlschlagen. Es soll keine Backwards Compatibility geben!
