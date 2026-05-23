# Reviewer Reading Sequence

Status: aktiv, externe Review-Reihenfolge fuer das finale Zielbild.

## Ziel

Dieses Dokument definiert eine kurze, belastbare Lesereihenfolge fuer externe Reviewer.

## Reihenfolge

1. PREFLIGHT_FINAL_TARGET_FOR_REVIEW
2. flowcharts/PREFLIGHT_FINAL_TARGET_FOR_REVIEW.mmd
3. PREFLIGHT_IMPLEMENTATION_AGENT_RUNBOOK
4. TASK_QUEUE_BLUEPRINT
5. PREFLIGHT_BLUEPRINT
6. CONTRACT_TASK_METADATA
7. TASK_GOVERNANCE_ZIELBILD_DOD
8. PREFLIGHT_IMPLEMENTATION_BEST_PRACTICE_PLAN

## Review-Fragen je Dokument

### 1) PREFLIGHT_FINAL_TARGET_FOR_REVIEW

- Sind Layer 1 bis 3 klar getrennt und ohne semantische Ueberlappung?
- Ist die Queue als einzige lifecycle-fuehrende Instanz fuer mutating tasks eindeutig?
- Ist die Versionpruefung als verpflichtender Gate-Check plausibel und ausreichend?

### 2) flowcharts/PREFLIGHT_FINAL_TARGET_FOR_REVIEW.mmd

- Deckt das Diagramm alle terminalen und retry-bezogenen Pfade ab?
- Ist der Weg soft_block -> blocked_confirmation konsistent und ohne versteckte Bypaesse?
- Sind Audit-Kanten fuer alle entscheidenden Knoten vorhanden?

### 3) PREFLIGHT_IMPLEMENTATION_AGENT_RUNBOOK

- Sind Phasen, Reihenfolge und Loeschkandidaten klar genug fuer direkte Umsetzung?
- Sind Legacy-Pfade eindeutig als zu entfernen markiert?
- Ist die Aussage "keine Migration" konsequent im Ablauf umgesetzt?

### 4) TASK_QUEUE_BLUEPRINT

- Ist das Statusmodell vollstaendig, deterministisch und frei von Alt-Konzepten?
- Sind Retry-, TTL- und Confirmation-Regeln widerspruchsfrei?
- Sind Invarianten klar messbar (z. B. maximal ein running pro thread)?

### 5) PREFLIGHT_BLUEPRINT

- Entspricht das Schichtenmodell dem finalen Zielbild 1:1?
- Ist das PreflightResult-Modell minimal, aber operativ ausreichend?
- Ist die Zuordnung von Layer-Ergebnissen zu Queue-Status eindeutig?

### 6) CONTRACT_TASK_METADATA

- Sind Pflichtfelder und Validierungsregeln fuer Provider eindeutig?
- Ist die Versionspolitik operationalisierbar (supported/deprecated/unsupported)?
- Sind deny_reason und issue_codes fuer Diagnose und Telemetrie brauchbar?

### 7) TASK_GOVERNANCE_ZIELBILD_DOD

- Sind Definition of Done und Akzeptanzkriterien testbar und objektiv?
- Schliessen die Kriterien Legacy-Rueckfaelle aus?

### 8) PREFLIGHT_IMPLEMENTATION_BEST_PRACTICE_PLAN

- Ist die Ausgangsanalyse vollstaendig genug, um die Refactoring-Notwendigkeit zu belegen?
- Ist das Zielbild konsistent mit allen kanonischen Folgedokumenten?

## Abschluss-Check fuer Reviewer

- Konsistenz: Kein Widerspruch zwischen Text und Diagrammen.
- Vollstaendigkeit: Jeder mutating Pfad laeuft ueber denselben Preflight-Mechanismus.
- Vereinfachung: Keine Shadow- oder Legacy-Fallback-Logik verbleibt im Zielbild.
- Umsetzbarkeit: Runbook-Schritte sind in realer Implementierungsreihenfolge ausfuehrbar.
