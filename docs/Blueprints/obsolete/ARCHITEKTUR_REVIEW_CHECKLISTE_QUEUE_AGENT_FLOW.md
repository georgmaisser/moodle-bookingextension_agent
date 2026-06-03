# Architektur-Review-Checkliste (Queue + Agent Flow)

Zweck: Diese Checkliste dient als Arbeitsdokument fuer einen fokussierten Architektur-Review auf Design-Schwaechen, Inkonsistenzen und potenzielle Fehlentwicklungen im Queue- und Agent-Flow.

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

## 1) Queue ↔ Preflight Reihenfolge

### Prueffragen

- Wird zuerst enqueue gemacht oder zuerst preflight?
- Gibt es zwei konkurrierende Wahrheiten ueber die Reihenfolge?
- Kann ein Command in die Queue gelangen, ohne dass Preflight final entschieden hat?
- Gibt es Race Conditions zwischen Q_ENQUEUE und D_PREFLIGHT?

### Zielbild

- Eine eindeutige, unverwechselbare Reihenfolge im Lifecycle.

### Checkpoints

- [x] Reihenfolge im Code (decision service, queue manager, preflight pipeline) dokumentiert.
- [x] Abweichende Pfade identifiziert (readonly vs mutating, confirmation, retry).
- [x] Potenzielle Race-Window zwischen Enqueue und Preflight bewertet.
- [x] Source of Truth fuer Reihenfolge benannt.
- [x] Technische Guardrails gegen Reihenfolge-Drift notiert.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Mutating Commands werden zuerst in die Queue ingestiert (handle_command_routing), danach preflighted (handle_preflight) und erst dann per queue_transition auf ready/blocked/retry/failed gesetzt. Readonly wird direkt auf ready ingestiert und sofort ausgefuehrt. Damit existiert eine bewusste Zweistufigkeit (Ingestion vor finaler Preflight-Entscheidung). Risiko: bei Abbruch zwischen Enqueue und Preflight bleiben queued-Artefakte zurueck; source of truth fuer Reihenfolge ist agent_decision_service::process.

---

## 2) READY Zustand als potenzieller Sammelzustand

### Prueffragen

- Bedeutet READY nur "bereit zur Ausfuehrung" oder mehrere semantische Dinge?
- Wird READY verwendet fuer neue Queue-Items, Idempotency-Hits, Retry-Completion und Confirmation-Completion?
- Besteht die Gefahr, dass READY ein Catch-all-State wird?

### Zielbild

- Ein klarer, einzelner Zustand ohne gemischte Semantik.

### Checkpoints

- [x] Alle Eintrittspfade nach READY inventarisiert.
- [x] Semantik pro Eintrittspfad dokumentiert.
- [x] Geprueft, ob READY nur ein technischer Zustand ist.
- [x] Alternative Modellierung (Events/Reason-Codes) bewertet.
- [x] Empfehlung fuer semantische Entflechtung festgehalten.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: READY wird aus mehreren Gruenden erreicht: initiale readonly-Ingestion, preflight-pass mit autoconfirm, confirm_run to_ready vor Ausfuehrung, fallback nach failed try_mark_running, queue-idempotency reuse eines non-terminal items. Der State ist technisch nutzbar, aber semantisch ueberladen; Reason-Codes pro READY-Transition sollten verpflichtend sein.

---

## 3) Idempotenz-Trennung (Queue vs Executor)

### Prueffragen

- Queue-Idempotenz verhindert doppelte Eintraege.
- Executor-Idempotenz verhindert doppelte Ausfuehrung.
- Sind die Schichten sauber getrennt dokumentiert?
- Gibt es Faelle, in denen beide greifen und Debugging erschweren?
- Koennte eine Schicht redundant sein oder falsch interpretiert werden?

### Zielbild

- Klare Verantwortlichkeit plus klare Failure-Debug-Pfade.

### Checkpoints

- [x] Queue-Idempotenzpfad und Triggerbedingungen dokumentiert.
- [x] Executor-Idempotenzpfad und Triggerbedingungen dokumentiert.
- [x] Ueberlappungsszenarien identifiziert.
- [x] Diagnose-Ausgaben und Reason-Codes auf Konsistenz geprueft.
- [x] Redundanz-Risiko bewertet und Entscheidungsvorschlag notiert.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [x] Ja [ ] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [ ] Ja [x] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Trennung ist technisch klar (Queue: input_signature/non-terminal reuse; Executor: run_exists_other_than fuer Ausfuehrungsschutz). Beide koennen in derselben End-to-End-Strecke greifen und unterschiedliche skipped-Resultate erzeugen. Durch neue reason-codes (QUEUE_SIGNATURE_REUSE vs EXECUTOR_RUN_EXISTS) ist Debugging verbessert, aber weiterhin zweistufig komplex.

---

## 4) Q_READY → run_loop Kopplung

### Prueffragen

- Queue triggert indirekt den naechsten run_loop.
- System ist conversation-driven statt worker-driven.
- Ist das bewusstes Design oder historisches Artefakt?
- Gibt es Szenarien, in denen Queue-Arbeit ohne User-Input liegen bleibt?
- Fehlt ein Scheduler/Worker-Konzept?

### Zielbild

- Klare Entscheidung: event-driven vs conversation-driven Queue.

### Checkpoints

- [x] Trigger-Mechanismus fuer Folge-Run dokumentiert.
- [x] Leerlauf-/Stau-Szenarien ohne User-Input geprueft.
- [x] Betriebsmodell (conversation-driven vs worker-driven) explizit eingeordnet.
- [x] Auswirkungen auf Latenz und Zuverlaessigkeit bewertet.
- [x] Empfehlung fuer Architektur-Richtung festgehalten.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Primar ist der Flow conversation-driven (ai_send_message -> runtime->run_loop, ai_confirm_run fuer mutating). Es gibt zwar adhoc-Ausfuehrung fuer bestaetigte Runs, aber keinen generischen Queue-Worker, der ready Items unabhaengig konsumiert. Dadurch kann Arbeit ohne weiteren Trigger liegen bleiben; ein klares Betriebsmodell fehlt.

---

## 5) Spawn-Graph Risiken

### Prueffragen

- EXC_SPAWN -> Q_SPAWN -> Q_ENQUEUE
- Dependency Chains
- Artifact Binding
- Gibt es Limits fuer Spawn-Tiefe, Child-Count und Queue-Wachstum pro Run?
- Kann ein fehlerhafter Task unkontrolliertes DAG-Wachstum erzeugen?

### Zielbild

- Verhindern von unbegrenztem Graph-Wachstum.

### Checkpoints

- [x] Spawn-Tiefe-Limits geprueft.
- [x] Child-Count-Limits geprueft.
- [x] Queue-Wachstum pro Run geprueft.
- [x] Guardrails gegen rekursives/unkontrolliertes Spawn-Verhalten dokumentiert.
- [x] Monitoring/Abbruchkriterien fuer Graph-Wachstum notiert.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [ ] Ja [x] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Im Code existiert spawn_commands aktuell vor allem als Schema-/Normalisierungsvertrag; ein expliziter produktiver enqueue-spawn-loop mit harten Grenzen ist nicht erkennbar. Damit ist akutes Laufzeitwachstum derzeit begrenzt, aber bei Aktivierung fehlen klare Limits fuer Tiefe, Child-Anzahl und Run-weites Queue-Wachstum.

---

## 6) READY / RUNNING / BLOCKED Semantik

### Prueffragen

- Sind READY, RUNNING, BLOCKED_CONFIRMATION, RETRY_WAITING eindeutig getrennt?
- Gibt es semantisch ueberlappende Uebergaenge?
- Gibt es Zustaende, die eigentlich Flags sein sollten?

### Zielbild

- Minimale, nicht ueberladene State Machine.

### Checkpoints

- [x] Zustandsdefinitionen als kurze Vertrage dokumentiert.
- [x] Uebergaenge als Matrix geprueft.
- [x] Ueberlappende Semantik markiert.
- [x] Event-vs-State Fehlmodellierungen identifiziert.
- [x] Vereinfachte Ziel-State-Machine skizziert.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: queue_status_policy trennt READY, RUNNING, BLOCKED_CONFIRMATION, RETRY_WAITING formal gut. Ueberlappung entsteht in Uebergaengen (z.B. to_ready vor run, slot-occupied -> ready, retry->pickup). Teile der Semantik liegen in error_class/issue_codes statt rein im State.

---

## 7) Pending Intent vs Queue State

### Prueffragen

- pending_intent im Conversation Store vs Queue Status.
- Gibt es doppelte Wahrheit?
- Wer ist Source of Truth fuer offenen Intent?
- Kann es Desync zwischen pending_intent und Queue Status geben?

### Zielbild

- Single Source of Truth fuer Intent Lifecycle.

### Checkpoints

- [x] Datenquellen fuer Intent-Zustand vollstaendig inventarisiert.
- [x] Autoritative Quelle benannt.
- [x] Desync-Szenarien und Reconciliation-Pfade geprueft.
- [x] Persistenzgrenzen (thread metadata vs queue item) dokumentiert.
- [x] Vereinheitlichungsoption festgehalten.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Offener Intent ist doppelt modelliert: pending_intent im Thread-Metadata (inkl. queue_item_ids, confirmationcode) und Queue-Status auf Items. pending_intent_service/consume reduziert Inkonsistenz, aber source-of-truth ist verteilt (Intent-Navigation vs Queue-Exekutionsstatus), Desync bleibt moeglich bei Stale IDs oder bei vorzeitigem consume.

---

## 8) Confirmation Flow Konsistenz

### Prueffragen

- confirm_pending
- confirmation_request
- pending_intent
- queue transition nach confirmation
- Ist Confirmation eigener Lifecycle oder Zustand im Mutationspfad?
- Wird Confirmation konsistent re-preflighted?
- Gibt es parallele Confirmation-Mechanismen?

### Zielbild

- Ein einziger konsistenter Confirmation Lifecycle.

### Checkpoints

- [x] Confirmation-Eintrittspunkte und Exits dokumentiert.
- [x] Re-preflight Verhalten pro Pfad geprueft.
- [x] Parallele Confirmation-Mechanismen identifiziert.
- [x] Pending-Intent Kopplung mit Queue-Transitions bewertet.
- [x] Konsolidiertes Confirmation-Lifecycle-Modell vorgeschlagen.

### Bewertung

- Q1 Eindeutig: [x] Ja [ ] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Es gibt zwei Confirmation-Ebenen: planner-seitig confirm_pending/confirmation_request und API-seitig ai_confirm_run mit pending-intent consume. Re-preflight passiert im confirm_pending-Pfad (Decision Service), waehrend ai_confirm_run auf bereits queue-/preflight-aufbereitete Daten arbeitet. Konsistent genug fuer Betrieb, aber lifecycle-seitig nicht als ein einziger linearer Pfad modelliert.

---

## 9) Retry-System Globalitaet

### Prueffragen

- loop budget (ATTB)
- queue retry (Q_RETRY)
- execution retry (EXC_TRANSIENT)
- preflight retry_hint
- Gibt es eine globale Sicht auf Attempt Budget?
- Koennen kombinierte Retries unkontrolliert eskalieren?
- Ist klar, wann ein Turn endgueltig kaputt ist?

### Zielbild

- Einheitliches Retry-Budget-System ueber alle Layer.

### Checkpoints

- [x] Alle Retry-Quellen inkl. Counter inventarisiert.
- [x] Globale Budget-Sicht im Code und in Responses bewertet.
- [x] Eskalationsszenarien (kombinierte Retries) geprueft.
- [x] Harte Abbruchkriterien dokumentiert.
- [x] Vorschlag fuer zentralen Retry-Vertrag notiert.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: Retry ist verteilt: runtime loop budget, preflight_execution_gate retry_hint, queue retry_waiting/next_retry_at, confirm_run build_retry_decision. attempt_budget wird zwar im Runtime-Result ausgewiesen, ist aber kein zentral erzwungener globaler Counter ueber alle Ebenen. Kombinierte Retries bleiben schwer vorhersagbar.

---

## 10) Semantic Overloading von Zustaenden

### Prueffragen

- READY
- SKIP
- FAILED
- CONFIRMATION_REQUEST
- Wird ein Zustand fuer mehrere Bedeutungen verwendet?
- Gibt es Faelle, wo ein Zustand sowohl Erfolg als auch Uebergang bedeutet?
- Gibt es Zustaende, die eigentlich Events sein sollten?

### Zielbild

- Keine Vermischung von State-Semantik und Event-Semantik.

### Checkpoints

- [x] Kritische Status-/Event-Begriffe und Verwendungen inventarisiert.
- [x] Mehrdeutige Bedeutungen markiert.
- [x] State-vs-Event Trennlinie pro Begriff dokumentiert.
- [x] Auswirkungen auf Monitoring/Debugging bewertet.
- [x] Bereinigungsvorschlag fuer Terminologie und Zustandsmodell notiert.

### Bewertung

- Q1 Eindeutig: [ ] Ja [x] Nein
- Q2 Doppelzustaendigkeit: [x] Ja [ ] Nein
- Q3 In 5 Minuten verstehbar: [ ] Ja [x] Nein
- Q4 Sonderfall-Risiko hoch: [x] Ja [ ] Nein
- Q5 Vereinfachbar ohne Funktionsverlust: [x] Ja [ ] Nein
- Refactoring-Kandidat: [x] Ja [ ] Nein
- Notizen: READY ist teilweise Event-Ersatz, FAILED aggregiert heterogene Fehlerquellen, confirmation_request wird teils als User-Event und teils als interner Routing-Status genutzt. SKIP ist seit LOGICAL_SKIP klarer, aber weiterhin Status plus Diagnosesignal. Eine stricte Trennung state vs event fehlt.

---

## Abschlussbewertung

- Wo entstehen wahrscheinlich die ersten Produktionsbugs?
  - An den Grenzstellen zwischen pending_intent consume und Queue-Status (stale queue_item_id, false-ready, retry_waiting Timing).
- Welche 2 Stellen sind langfristig am wartungsintensivsten?
  1. agent_decision_service (Routing + Queue-Ingestion + Preflight + Pending-Intent Persistenz in einer Kette).
  2. confirm_run_service (Confirmation, Retry-Decision, Queue-Transition, optionaler Runtime-Loop in einem Service).
- Welche Komponente sollte als erstes refactored werden?
  - Der Decision/Confirmation Uebergang als einheitliche Safety+Intent Pipeline mit klarer Source-of-Truth-Regel.

---

## Priorisierung fuer den naechsten Review-Durchlauf

1. [x] Hoechste Prioritaet
2. [x] Mittlere Prioritaet
3. [x] Niedrige Prioritaet

Hoechste Prioritaet: Punkt 7, Punkt 9, Punkt 4.
Mittlere Prioritaet: Punkt 2, Punkt 8, Punkt 1.
Niedrige Prioritaet: Punkt 3, Punkt 6, Punkt 10, Punkt 5.

Hinweis: Pro Punkt zuerst Ist-Vertrag dokumentieren, danach erst Refactoring-Vorschlag ableiten.
