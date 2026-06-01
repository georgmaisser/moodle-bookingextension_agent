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
