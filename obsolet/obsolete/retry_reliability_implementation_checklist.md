# Retry Reliability Implementation Checklist

Status: Vorbereitung fuer Implementierung
Owner: bookingextension_agent Team

## Ziel

Dieses Dokument dient als Implementierungs-Checkliste fuer die geplanten Retry-Verbesserungen im Agenten-Stack (Planner, Preflight, Queue, Execution, Runtime Loop, Global Budget).

## Scope (vereinbart)

- [x] Nur Architektur- und Runtime-Logik anpassen, keine UI-Aenderungen.
- [x] Prioritaet auf Stabilitaet und deterministische Fehlerbehandlung.
- [x] Keine neuen "stillen" Heuristiken ohne explizite Issue-Code-Gates.

## A. Planner Retry korrekt im Loop verankern

- [x] Festlegen, dass Planner-Retries ausschliesslich ueber Loop-Observationen laufen.
- [x] `CONTRACT_PARSE_ERROR` als Retry-Hinweis im Loop behandeln (nicht als in-phase Sonderweg).
- [x] `CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED` als Retry-Hinweis im Loop behandeln.
- [x] Sicherstellen, dass pro Issue-Code nur eine begrenzte Retry-Anzahl moeglich ist.
- [x] Festlegen, wann Planner-Retry terminieren muss (harter Stop).

## B. Task-Selector Retry an richtige Stelle

- [x] Retry-Pfad fuer Selection-Shape-Fehler explizit nach `LOOP_STOP` im Runtime-Loop einordnen.
- [x] Selection-Contract-Fehler eindeutig klassifizieren (z. B. `CONTRACT_SELECTION_*`).
- [x] Eindeutige Eskalation definieren, falls Retry erneut denselben Fehler produziert.
- [x] Sicherstellen, dass kein Constructor-Call startet, solange Selection-Contract ungueltig ist.

## C. Retry-Policy zentralisieren

- [x] Entscheid, ob ein `retry_policy_service` eingefuehrt wird.
- [x] Wenn ja: Eingaben definieren (`issue_code`, `error_class`, `risk_class`, `layer`, `attempt_count`).
- [x] Wenn ja: Ausgabevertrag definieren (`allow`, `backoff_ms`, `terminal_reason`).
- [x] Mapping fuer Layer definieren: Planner, Preflight, Queue, Execution.

## D. Guardrails und harte Regeln

- [x] R3: Retry-Disable ueber alle Layer explizit definieren und testen.
- [x] Verhindern, dass dieselbe Fehlerklasse in mehr als zwei Layern retryt.
- [x] Verhindern, dass Planner + Queue + Execution gleichzeitig retry-aktiv sind (gleiches Intent).
- [x] Circuit-Breaker-Regel fuer Provider-Fehler entscheiden (timeout/auth/quota).

## E. Issue-Code und Retry-Hint Qualitaet

- [x] `retry_hint` Kategorien festlegen: `TECHNICAL`, `DOMAIN`, `EXTERNAL_DEPENDENCY`.
- [x] Definieren, welche Kategorien retried werden duerfen.
- [x] Sicherstellen, dass jede Retry-Entscheidung einen klaren Issue-Code traegt.
- [x] Undefinierte/unklare retry_hint ohne Kategorie als Fehler behandeln.

## F. Observability und Debugging

- [x] Thread-Metadaten fuer Retry-Entscheidungen erweitern (attempt, layer, reason).
- [x] Eindeutige Trace-Felder fuer Retry-Ursprung (`planner`, `preflight`, `queue`, `execution`).
- [x] Log-Format fuer End-to-End-Rekonstruktion validieren.
- [x] Nachweis, dass finale Fehlermeldungen technische Ursache korrekt widerspiegeln.

## G. Tests (vor Merge erforderlich)

### Unit

- [x] Planner: `CONTRACT_PARSE_ERROR` erzeugt Retry-Hinweis statt sofortigem Terminalfehler.
- [x] Planner: Selection-Shape-Fehler (`CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED`) erzeugt Retry-Hinweis.
- [x] Policy: Max-Attempts pro Issue-Code werden erzwungen.
- [x] Guardrail: R3 hat keinen indirekten Retry-Pfad.

### Integration

- [x] Durchlauf mit kaputtem Selection-Envelope (Wrapper-Shape) endet deterministisch ohne Loop.
- [x] Durchlauf mit kaputtem Constructor-JSON nutzt genau die erlaubte Retry-Anzahl.
- [x] Queue retry_waiting und Execution transient retry kollidieren nicht mit Planner-Retry.
- [x] Global Budget stoppt reproduzierbar bei Grenzfall.

### Regression

- [x] Normale mutierende Flows bleiben unveraendert.
- [x] Read-only Flows ohne Zusatzkosten.
- [x] Confirm-Flow und Pending-Intent bleiben konsistent.

## H. Rollout und Risiko

- [x] Feature-Flag-Strategie fuer Retry-Policy-Anpassungen festlegen.
- [x] Fallback-Verhalten bei unvorhergesehenen Issue-Codes definieren.
- [x] Monitoring-Plan fuer erste Deployments (Fehlerquote, Retry-Rate, Kosten).
- [x] Rollback-Kriterien dokumentieren.

## Definition of Done

- [x] Architekturkonforme Retry-Pfade (loop-basiert, keine versteckten Sonderloops).
- [x] Harte Abbruchbedingungen pro Issue-Code aktiv.
- [x] Keine unkontrollierten Retry-Kaskaden ueber mehrere Layer.
- [x] Tests gruen (Unit + Integration + Regression).
- [x] Flowchart und Implementierung sind konsistent.
