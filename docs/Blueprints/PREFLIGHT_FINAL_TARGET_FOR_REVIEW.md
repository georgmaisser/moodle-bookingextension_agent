# Finales Zielbild Preflight fuer externe Review

## Zweck

Dieses Dokument ist die freigabefaehige Zielbeschreibung fuer die Preflight-Architektur des bookingextension_agent.

Es kombiniert:

- den ehrlichen Status quo,
- das finale Zielbild,
- und die grundsaetzlichen Refactoring-Schritte.

Scope in diesem Dokument:

- Architektur und Zielverhalten,
- keine Implementierungsdetails,
- kein Commit-Plan,
- keine Ruecksicht auf Migration, da der Agent noch nicht produktiv ist.

## Ausgangslage und Status quo

### A. Heute vorhandene Bausteine

- Es gibt bereits eine Queue-Struktur mit Statusfeldern, depends_on, retry- und TTL-Feldern.
- Es gibt bereits Preflight-Bausteine fuer schema, domain, execution gate und audit logging.
- Es gibt bereits Task-preflight-Methoden in Teilen der Booking-Tasks.

### B. Heute zentrale Defizite

- Kein einziger autoritativer Preflight-Pfad. Es laufen Legacy-Preflight, Task-Preflight und V2-Overlay parallel.
- Queue ist noch nicht durchgehend die steuernde Runtime, sondern teilweise Projektionsmodell.
- Mutating Flows bleiben im Kern beim first-mutation staging, was zusaetzliche Re-Plan-Schleifen erzeugt.
- Task-Konsistenz ist unvollstaendig:
  - Booking-Tasks sind nur teilweise auf check_structure plus preflight migriert.
  - Core-Tasks sind noch nicht auf den neuen Vertrag gehoben.
- Contract-Semantik fuer Drittplugin-Tasks ist noch nicht voll deckungsgleich mit dem Zielvertrag.

### C. Auswirkung

Das System ist heute funktionsfaehig, aber architektonisch nicht einheitlich genug fuer ein robustes, queue-getriebenes agentic loop Zielbild.

## Finales Zielbild

## Leitprinzipien

1. Ein Preflight, ein Vertrag, ein Ergebnisobjekt.
2. Queue ist Single Source of Truth fuer Mutationsausfuehrung.
3. Steuerlogik ist sprachagnostisch und basiert auf strukturierten Signalen, nicht auf Freitext.
4. Drittplugins muessen denselben technischen Vertrag erfuellen wie interne Tasks.
5. Jeder relevante Zustand ist auditierbar und reproduzierbar.

## Zielarchitektur in drei Schichten

### Schicht 1: Contract and Schema Gate

Schicht 1 validiert synchron und ohne Domain-I/O:

- task registration und activation,
- task version check (supported, deprecated, minimum required),
- task contract Pflichtfelder,
- input schema und envelope,
- depends_on syntax,
- mutability Konsistenz.

Ergebnis:

- pass oder hard_block.

Versionierungsregel in Schicht 1:

- Jeder Task liefert eine version im Task-Contract.
- Die Runtime validiert diese gegen eine zentrale Supported-Version-Matrix.
- Nicht mehr unterstuetzte Task-Versionen werden als hard_block behandelt mit issue_code TASK_VERSION_UNSUPPORTED.
- Als deprecated markierte Task-Versionen werden strukturiert rueckgemeldet (issue_code TASK_VERSION_DEPRECATED) und sind konfigurierbar entweder hard_block oder soft_block.

### Schicht 2: Domain Gate

Schicht 2 validiert fachlich read-only:

- permission,
- context,
- preconditions,
- conflict,
- task-lokales preflight.

Ergebnis:

- pass, soft_block, hard_block oder retry_hint.

### Schicht 3: Execution Gate

Schicht 3 steuert retry und backoff:

- retry_hint wird in retry_waiting ueberfuehrt,
- backoff mit jitter wird persistiert,
- max retries fuehren deterministisch zu hard_block.

Ergebnis wird direkt auf Queue-Status gemappt.

## Zielvertrag: zentrale Objekte

### PreflightRequest

Pflicht:

- queue_item_id, thread_id, run_id, step_id,
- taskname, mutability,
- raw_input, prepared_input optional,
- retry_count,
- user/context Informationen,
- autoconfirm_mode.

### PreflightDecision

Pflicht:

- status: pass | soft_block | hard_block | retry_hint,
- issue_codes,
- blocking_layer: 1 | 2 | 3 | null,
- retry_after_ms,
- retry_count,
- duration_ms,
- prepared_input optional,
- audit payload.

## Zielverhalten im Queue-Kontext

Deterministische Abbildung:

- pass und autoconfirm true -> ready,
- pass und autoconfirm false -> blocked_confirmation,
- soft_block -> blocked_confirmation,
- retry_hint -> retry_waiting,
- hard_block -> failed.

blocked_confirmation ist Queue-Status, nicht Nebenkanal.

## Ziel fuer Drittplugin-Faehigkeit

Jeder Provider muss einen klaren Task-Contract liefern:

- taskname,
- version,
- capability,
- activation,
- optional alias_of,
- optional deprecated_since,
- optional deny_reason.

Jeder mutating Task muss zwingend liefern:

- check_structure,
- preflight,
- execute.

Keine Legacy-validate-only Aufnahme neuer Tasks.

## Ziel fuer Auditierbarkeit

Jeder Preflight-Durchlauf schreibt audit events fuer:

- layer,
- status,
- issue_codes,
- retry_count,
- retry_after_ms,
- duration_ms,
- thread_id,
- run_id,
- queue_item_id.

Pass wird genauso geloggt wie Block.

## Grundsaetzliche Refactoring-Schritte

Diese Schritte sind bewusst grob und eignen sich fuer externe Architekturpruefung.

### Schritt 1: Vertrage finalisieren

- finalen Task-Contract festziehen,
- finales PreflightRequest und PreflightDecision Modell festziehen,
- eindeutige Issue-Code Taxonomie definieren.

### Schritt 2: Preflight vereinheitlichen

- einen einzigen Preflight-Entry bauen,
- Legacy- und Parallelpfade entfernen,
- gleiche Pipeline fuer initiale und confirm-Pfade erzwingen.

### Schritt 3: Queue zur Autoritaet machen

- confirmation an queue items koppeln,
- pending raw command Nebenwahrheiten abbauen,
- retry_waiting und blocked_confirmation voll queue-getrieben umsetzen.

### Schritt 4: Task-Flaeche angleichen

- alle mutating tasks auf check_structure plus preflight plus execute heben,
- readonly tasks mindestens mit explizitem check_structure vereinheitlichen,
- core tasks auf den gleichen Vertragsrahmen bringen.

### Schritt 5: Drittplugin-Haertegrad einziehen

- provider onboarding mit strict contract validation,
- capability und activation vertraglich pruefen,
- plugin issue code namespace sauber erzwingen.

### Schritt 6: Beobachtbarkeit und Review-Gates

- audit stream finalisieren,
- queue transition telemetry standardisieren,
- Architektur-DoD fuer Preflight und Queue vor Umsetzungsplan fixieren.

## Explizite Nicht-Ziele in dieser Phase

- keine konkreten Codeaenderungen,
- keine CI-Feinschritte,
- keine Klassen- oder Dateimoves,
- keine API-Migrationsstrategie.

## Review-Fragen fuer den externen Berater

1. Ist das Zielbild mit einem strikt queue-getriebenen agentic loop in sich konsistent?
2. Sind Contract and Schema Gate, Domain Gate und Execution Gate sauber getrennt?
3. Ist die Drittplugin-Strategie robust genug fuer langfristige Erweiterbarkeit?
4. Sind Audit und Beobachtbarkeit fuer Forensik und Betrieb ausreichend definiert?
5. Fehlen aus externer Sicht harte Architektur-Invarianten, bevor die Umsetzung startet?

## Bereits eingearbeitete Diagramm-Schaerfungen

Die finale Diagrammversion fuer die Review beinhaltet bereits folgende Schaerfungen:

- Budget-Guard vor jedem Planner-Re-Entry.
- expliziter depends_on-Wartepfad nach Queue-Ingestion.
- expliziter Re-Preflight-Schritt nach User-Confirm mit Contract-Snapshot-Bezug.
- sichtbare prepared_input-Provenienz aus dem Domain-Gate.
- konsistente Queue-State-Entscheidung in Layer 3 fuer soft_block, pass und retry_hint.
- Audit-Anbindung auch fuer readonly-ready Pfad.

## Verweis auf Arbeitsdokumente

Dieses finale Review-Dokument basiert auf den bisherigen Blueprint-Artefakten und der Inventur:

- TASK_QUEUE_BLUEPRINT
- CONTRACT_TASK_METADATA
- PREFLIGHT Blueprint Diagramme
- interne Inventur der aktuellen Implementierung
