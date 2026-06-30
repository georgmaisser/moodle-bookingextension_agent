# Roadmap — Paralleles Hochfahren von Sub-Agent-Instanzen (Fan-out)

Status: Konzept (2026-06-30). Keine Code-Änderungen. Ausführlicher Backlog-Eintrag.

## Vision

Ein Agent-Lauf soll **mehrere weitere Agent-Instanzen parallel** starten können — Fan-out —, statt
alles sequziell in einem einzigen Request abzuarbeiten. Anwendungsfälle:

- **Decomposition / Map-Reduce:** eine große Aufgabe in N unabhängige Teilaufgaben zerlegen, jede von
  einer eigenen Agent-Instanz bearbeiten lassen, danach die Ergebnisse zusammenführen (z. B. „prüfe
  jeden dieser 20 Kurse" oder „erzeuge je eine Option pro Mittwoch" — siehe thread 562, wo eine
  Serie als Einzeloptionen gedacht war).
- **Mehrperspektiv / Self-consistency:** dieselbe Frage von mehreren Instanzen mit unterschiedlichen
  Prompts beantworten und das beste/mehrheitliche Ergebnis nehmen.
- **Adversarial verify:** ein Plan/Ergebnis von unabhängigen „Skeptiker"-Instanzen prüfen lassen.
- **Breite Recherche:** mehrere read-only Diagnosen/Reports gleichzeitig fahren.

Das Gegenstück existiert konzeptionell bereits im Host-Harness (Workflow/Agent-Fan-out); hier geht es
darum, dass der **Booking-Agent selbst** innerhalb von Moodle parallele Instanzen hochfahren kann.

## Das Kernproblem: der Moodle-Session-Lock

Moodle serialisiert **alle Requests, die sich dieselbe PHP-Session teilen**. Beim Start eines Requests
wird die Session geöffnet und **exklusiv gesperrt** (Session-Lock); der Lock wird erst am Requestende
(oder bei explizitem Schließen) freigegeben. Solange ein Request den Lock hält, **blockieren** alle
weiteren Requests derselben Session beim `session_start()` und warten.

Konsequenz für naiven Fan-out: Wenn der Eltern-Request N HTTP-Aufrufe auslöst, die über **dasselbe
Browser-Session-Cookie** authentifizieren, laufen diese **nicht parallel**, sondern reihen sich hinter
dem Lock des Eltern-Requests (und untereinander) auf → kein Geschwindigkeitsgewinn, im schlimmsten Fall
Deadlock (Eltern wartet auf Kinder, die auf den Eltern-Lock warten).

**Die Lösung ist genau die Mechanik, die der Agent heute schon für Einzelläufe nutzt:**
`\core\session\manager::write_close()` schreibt die Session zurück und **gibt den Lock frei**, der
Request läuft danach ohne Session-Lock weiter. Belegstellen im aktuellen Code:

- `classes/external/ai_send_message.php:264` — Lock-Freigabe **vor** dem blockierenden LLM-Call.
- `classes/local/wizard/services/confirm_run_service.php:170` — Lock-Freigabe **vor** der langlaufenden
  Execution.

Für Fan-out heißt das: der Eltern-Lauf gibt seinen Lock frei, bevor er die Kinder anstößt; und die
Kinder dürfen den Eltern-Lock nicht brauchen. Wie genau, hängt vom gewählten Ausführungsmodell ab.

## Zwei Ausführungsmodelle

### Modell A — Synchroner Fan-out über Webservice-Requests (curl_multi)

Der Eltern-Lauf ruft N-mal **denselben Webservice** (REST-Endpoint) des Agents auf und wartet auf alle
Antworten.

Ablauf:
1. Eltern-Request: `\core\session\manager::write_close()` → Eltern hält **keinen** Session-Lock mehr.
2. Eltern feuert N parallele HTTP-Requests via **`curl_multi`** (oder Guzzle-Async) gegen
   `/webservice/rest/server.php?wsfunction=bookingextension_agent_ai_run_subagent&wstoken=<token>` mit
   je einem Teil-Prompt + Ziel-`contextid` + Verweis auf den Eltern-Thread.
3. **Token-Auth statt Cookie:** Webservice-REST-Requests authentifizieren über `wstoken`, **nicht** über
   das Browser-Session-Cookie. Jeder Kind-Request startet eine **eigene** (kurzlebige) Session, die mit
   der des Browsers nichts zu tun hat → kein gemeinsamer Lock. Zusätzlich ruft jeder Kind-Lauf früh
   `write_close()` (bzw. läuft `READ_ONLY_SESSION`), sodass sich auch die Kinder untereinander nicht
   blockieren.
4. Jedes Kind führt einen vollständigen Agent-Turn in **eigenem Thread / eigenem Run** aus (eigener
   `conversation_store`-Thread, eigene Queue, eigener Kontext) und gibt sein Ergebnis (Thread-ID +
   Resultat) zurück.
5. Eltern sammelt alle Antworten (`curl_multi_*`), übergibt sie dem **Synchronizer** zur
   Zusammenführung in **eine** Nutzerantwort.

Eigenschaften: Eltern bleibt für die Dauer am Leben (`ignore_user_abort(true)`, `set_time_limit(0)`/
großzügig), Antwort in einem Schwung; einfache Aggregation. Nachteil: Eltern-Worker ist während der
gesamten Fan-out-Zeit belegt; HTTP-Self-Calls erzeugen Last-Spitzen; Timeouts müssen je Kind gesetzt
werden (vgl. die Gateway-Timeouts in thread 562/565 — pro Kind harte Limits + Retry).

### Modell B — Asynchroner Fan-out über Ad-hoc-Tasks (Moodle Task-API)

Statt HTTP-Self-Calls reiht der Eltern-Lauf **N Ad-hoc-Tasks** (eine pro Kind) ein und kehrt sofort
zurück; die Tasks werden von den Cron-/Task-Runnern (ggf. mehreren parallel) abgearbeitet.

Ablauf:
1. Eltern-Lauf erzeugt je Kind einen Ad-hoc-Task (`\core\task\manager::queue_adhoc_task()`) mit
   `customdata` = {prompt, contextid, userid, parent_threadid, idempotency_key}. (Das Muster existiert
   schon: `classes/task/rebuild_*_adhoc.php` und der Queue-/Run-Mechanismus hinter
   `ai_confirm_run` → asynchrone Execution.)
2. Eltern setzt einen Eltern-Thread-Status „N Kinder ausstehend" und antwortet sofort (oder pollt).
3. Die Task-Runner führen die Kinder **echt asynchron** und (bei mehreren Runnern/`parallel`) parallel
   aus; jedes Kind in eigenem Thread/Run. Session-Lock ist im CLI/Task-Kontext kein Engpass.
4. Wenn das letzte Kind fertig ist, triggert ein „join"-Schritt (letzter Task oder ein Poll des
   Eltern-Threads) die **Aggregation** via Synchronizer; das Ergebnis landet im Eltern-Thread.
5. Das UI pollt den Eltern-Thread (`ai_poll_thread`) wie heute.

Eigenschaften: robust (kein hängender Eltern-Worker, Retry/Backoff über die Task-API gratis), skaliert
über Runner-Anzahl, übersteht Request-Timeouts. Nachteil: braucht laufende Task-Runner und ein
Join/Polling-Protokoll; „echte" Parallelität hängt an der Zahl gleichzeitiger Runner.

Empfehlung: **B als Default** (robust, Moodle-idiomatisch), **A optional** für niedrige Latenz bei
kleinem N. Beide nutzen denselben Kind-Einstieg (eine externe Funktion, die genau einen Agent-Turn
fährt) und dieselbe Lock-Freigabe.

## Session-Lock — Details, die zu beachten sind

- **`write_close()` ist die zentrale Stellschraube.** Nach dem Aufruf ist die Session schreibgeschützt
  gespeichert und entsperrt; spätere Schreibzugriffe auf `$_SESSION` greifen nicht mehr zurück. Daher
  **erst alles Session-Relevante lesen/schreiben, dann schließen**, dann fan-out.
- **`READ_ONLY_SESSION`** (vor dem Session-Start definiert) ist die Alternative für rein lesende
  Kind-Läufe: Moodle nimmt dann gar keinen exklusiven Lock. Für read-only Sub-Agents ideal.
- **Session-Handler-abhängig:** File-Sessions (Lock = Filelock), DB- und Redis-Sessions verhalten sich
  beim Lock unterschiedlich, aber `write_close()` gibt in allen Fällen frei. Auf der Test-VM (kleiner
  UTM-Container, siehe LOADTEST_GUIDE) ist die DB der Engpass, nicht der Lock — Concurrency-Cap wichtig.
- **Webservice-Endpoint** läuft ohnehin mit Token-Auth und `NO_MOODLE_COOKIES`; ein Kind-REST-Request
  teilt das Browser-Session-Cookie nicht. Trotzdem `write_close()`/`READ_ONLY_SESSION` im Kind setzen,
  damit Kinder mit gleichem Token sich nicht gegenseitig serialisieren.

## Architektur-Skizze

- **Kind-Einstieg (eine externe Funktion):** neu `bookingextension_agent_ai_run_subagent`
  (token-callbar, `type=write`, capability `bookingextension/agent:useaiinstructions`), die EINEN
  Agent-Turn für (prompt, contextid, parent_threadid) fährt und {child_threadid, status, result}
  zurückgibt. Intern identisch zu `ai_send_message` (gleicher `orchestrator`), nur mit Eltern-Verweis.
- **Fan-out ist Engine-Machinerie, kein Skill-Wissen** (Agnostik-Regel): die Orchestrierung
  (Zerlegung → spawn → join → Synthese) lebt in der Engine; ein auslösender Skill liefert höchstens die
  Liste der Teilaufgaben als Daten. Kein Skill ruft selbst curl/Tasks.
- **Thread-Modell:** je Kind ein eigener `conversation_store`-Thread; Eltern-Thread hält die Liste der
  Kind-Thread-IDs (Thread-Metadata `subagent_thread_ids`) + Aggregations-Status. Wiederverwendung des
  bestehenden Queue-/Run-/Polling-Modells.
- **Aggregation:** der vorhandene **Synchronizer** fasst die Kind-Ergebnisse zu einer Antwort zusammen
  (Sprache des Users), konsistent zur „Synchronizer antwortet IMMER"-Regel.

## Sicherheit & Limits (nicht optional)

- **Keine Rechte-Eskalation:** Kinder laufen als **derselbe** User (oder eine explizit delegierte
  Identität) und durchlaufen Gate 1 (Governance) + Gate 2 (native Capability) am jeweiligen
  Operating-Kontext **erneut** — ein Kind kann nie mehr als der Eltern-Lauf.
- **Concurrency-Cap (Config):** max. parallele Kinder pro Lauf und site-weit, sonst Self-DDOS /
  DB-Connection-Erschöpfung (N Kinder = N DB-Connections + N LLM-Calls; auf kleiner Infra strikt
  begrenzen). Default klein (z. B. 4–8), per Setting anpassbar.
- **Rekursions-/Tiefenbremse:** ein Kind darf nicht unbegrenzt Enkel starten → Tiefen-Zähler im
  `customdata`/Thread-Metadata, harte Obergrenze.
- **Token-Scope & Audit:** dedizierter, eng begrenzter Service-Token (nicht der volle User-Token);
  jeder Kind-Lauf mit Eltern-Verknüpfung geloggt (`ai_runs`/`ai_llm_debug`) für Nachvollziehbarkeit.
- **Timeouts/Retry je Kind:** harte Per-Kind-Limits + Backoff (Lehre aus den Gateway-Timeouts thread
  562/565); ein gescheitertes Kind kippt nicht den ganzen Batch (Fehler-Isolation, Teil-Aggregation).
- **Idempotenz:** je Kind ein Idempotency-Key, damit Task-Retries keine Doppelausführung (besonders bei
  mutierenden Kindern) erzeugen — der Queue-/Run-Layer hat das Konzept bereits.

## Offene Fragen / Entscheidungen

- O1: Default-Modell B (Ad-hoc-Tasks) vs. A (curl_multi)? Vorschlag B, A als Low-Latency-Option.
- O2: Dürfen Kinder **mutieren**, oder Fan-out zunächst nur für **read-only** Sub-Agents freigeben
  (sicherer Einstieg; mutierende Fan-outs später mit Confirm-Aggregation)?
- O3: Join-Protokoll bei Modell B — „last task wins"-Trigger vs. Eltern-Polling vs. dedizierter
  join-Task.
- O4: Wo wird die Aufgaben-Zerlegung entschieden — deterministisch (Code, z. B. „pro Kurs in Liste")
  oder vom Planner-LLM? Tendenz: Liste kommt als Daten aus einem Skill/aus dem Kontext, Engine fan-out
  deterministisch darüber.
- O5: Concurrency-Cap-Defaults + ob site-weit ein globaler Semaphor nötig ist (DB-Schutz).

## Verwandt

- `classes/external/ai_send_message.php` (synchroner Einzel-Turn, nutzt bereits `write_close()`),
  `ai_confirm_run.php` (async Enqueue), `ai_poll_thread.php` (Polling).
- `classes/local/wizard/services/confirm_run_service.php` (Lock-Freigabe vor Langläufer-Execution).
- Queue-/Run-Modell (`queue_manager`, `ai_runs`) als Basis für Kind-Läufe.
- LOADTEST_GUIDE (`.claude/LOADTEST_GUIDE.md`) — Infra-Grenzen der Test-VM für die Concurrency-Caps.
