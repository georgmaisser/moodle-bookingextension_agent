# Zielbild: Task-Governance im bookingextension_agent

Status: Planungsdokument (ohne Codeaenderung)
Kontext: Verfeinerung auf Basis des IST-Zustands aus der Architektur-Analyse

## 1. Zielbild (Endzustand)

Das Agent-Framework steuert Task-Ausfuehrbarkeit zentral, nachvollziehbar und konsistent ueber alle Plugins hinweg.

Im Endzustand gilt:
- Task-Identitaet ist stabil und versionierbar (inklusive Alias/Deprecation-Information).
- Ausfuehrbarkeit wird zentral bewertet ueber Identity, Activation, Capability und Kontext.
- Prompt-Sichtbarkeit und echte Ausfuehrbarkeit sind klar getrennt (Sichtbarkeit ist nie Security).
- `list_actions` und `explain_task_schema` liefern dieselbe Wahrheit wie die Runtime-Enforcement-Logik.
- Diagnose ist pro User/Kontext maschinenlesbar verfuegbar (mit standardisierten Deny-Reasons).
- Task-lokale Capability-Checks bleiben als Safety-Net, sind aber nicht mehr die primäre Steuerung.
- Planner/Synthesizer-Verantwortung ist strikt getrennt:
	- Planner entscheidet ueber Task/Loop-Fortsetzung und Suffizienz.
	- Synthesizer formuliert die finale User-Antwort.
	- Framework ueberschreibt Planner-Response-Typen nicht durch taskname-basierte Sonderregeln.

## 2. In Scope / Out of Scope

In Scope:
- Contract- und Governance-Schicht fuer Task-Identity, Activation, Capability, Executability.
- Einheitliche Evaluations- und Diagnoselogik.
- Registry-Output-Modi und UI-nahe Transparenz.
- CI-Validierung fuer Provider-Metadaten und Konflikte.

Out of Scope:
- Fachliche Task-Implementierungen selbst.
- Prompt-Optimierungen als Ersatz fuer Security/Enforcement.
- Entfernung aller task-lokalen Checks in einem Schritt (nur schrittweise Reduktion).

## 3. Begriffe und Prioritaet

Prioritaet fuer Ausfuehrbarkeit:
1. Global deny
2. Task inactive
3. Missing capability
4. Context invalid
5. Allow

Standardisierte Deny-Reasons:
- `not_registered`
- `inactive`
- `missing_capability`
- `context_invalid`
- `runtime_disabled`

## 4. Phasenplan mit Akzeptanzkriterien (Definition of Done)

## Phase 1: Contract-Design finalisieren

Ziel:
Ein verbindlicher Provider-Contract fuer Identity-, Capability- und Activation-Metadaten ist dokumentiert und abgestimmt.

DoD:
- Identity-Regeln sind festgelegt: `taskname` als stabiler Primaerschluessel, optional `alias_of`, `deprecated_since`.
- Capability-Metadata pro Task ist als deklaratives Feld spezifiziert.
- Activation-Metadata und Praezedenzregel sind schriftlich definiert.
- Konfliktverhalten bei Duplicate-Taskname ist normiert (inkl. Diagnose-Sichtbarkeit).

Akzeptanzkriterien:
- Es existiert ein schriftlicher Contract mit Pflichtfeldern, optionalen Feldern und Validierungsregeln.
- Fuer jeden Metadaten-Typ gibt es mindestens 1 positives und 1 negatives Beispiel.
- Die Reihenfolge der Entscheidungslogik ist eindeutig und widerspruchsfrei dokumentiert.

## Phase 2: Zentralen Evaluator als Read-only Service einfuehren

Ziel:
Eine autoritative Auswertungslogik bewertet Executability, ohne bereits hart zu blockieren.

DoD:
- Ein zentraler Evaluator liefert `executable_state` und `deny_reason` pro Task.
- Evaluator arbeitet fuer Einzel-Task und Bulk-Modus (`taskname|all`).
- Diagnosedaten sind fuer UI und Audit nutzbar (`summary` + `raw diagnostics`).

Akzeptanzkriterien:
- Fuer identische Eingaben liefert der Evaluator deterministische Ergebnisse.
- Alle standardisierten Deny-Reasons koennen reproduzierbar erzeugt werden.
- Evaluator-Ergebnisse sind ohne Task-Ausfuehrung abrufbar.

## Phase 3: Registry-Ausgabe auf Evaluator-Wahrheit umstellen

Ziel:
`list_actions` und `explain_task_schema` spiegeln echte Ausfuehrbarkeit statt nur Existenz.

DoD:
- Registry-Service bietet zwei Modi: `all_tasks` und `executable_tasks(user, cmid)`.
- `list_actions` nutzt standardmaessig `executable_tasks`.
- Optionaler Modus `include_unavailable` ist fuer Admin/Debug vorgesehen.
- `explain_task_schema` erweitert Ausgabe um `executable_state` und `deny_reason`.

Akzeptanzkriterien:
- Ein Task, der nicht ausfuehrbar ist, erscheint im Default-Modus nicht als ausfuehrbar.
- Bei aktivem `include_unavailable` sind nicht-ausfuehrbare Tasks inkl. Grund sichtbar.
- Schema-Erklaerung und Action-Liste widersprechen sich fuer denselben User/Kontext nicht.

## Phase 4: Harte Enforcement-Gates aktivieren (Decision-Service)

Ziel:
Zentrale Evaluator-Entscheidung wird zur autoritativen Laufzeit-Blockade vor Execution.

DoD:
- Decision-Service setzt den zentralen Authorization/Activation-Gate vor Mutability-Split und vor readonly-Execution.
- Executor behaelt globalen Gate und Struktur-Checks als letzte Verteidigung.
- Fehlerausgaben fuer Deny-Pfaede folgen standardisiertem Reason-Set.

Akzeptanzkriterien:
- Nicht-ausfuehrbare Tasks werden vor Task-Execute zentral gestoppt.
- Readonly und mutating folgen derselben Deny-Systematik.
- Preflight/Execute-Pfade liefern konsistente Deny-Reasons fuer gleiche Ursache.

## Phase 5: Task-lokale Checks entkoppeln (Safety-Net beibehalten)

Ziel:
Doppelte/abweichende Autorisierungslogik wird reduziert, ohne Sicherheitsverlust.

DoD:
- Task-lokale `has_capability`-Pruefungen sind inventarisiert und klassifiziert (Business-Regel vs Security-Safety-Net).
- Redundante Security-Checks sind reduziert oder auf Assert/Safety-Niveau umgestellt.
- Uebergangsregeln fuer Legacy-Tasks sind dokumentiert.

Akzeptanzkriterien:
- Kein Regression-Fall, bei dem zentral erlaubt aber task-lokal unerwartet denied (oder umgekehrt), ohne dokumentierte Ausnahme.
- Legacy-Pfade (`validate` vs `preflight/execute`) sind mit klarer Uebergangsregel versehen.
- Sicherheitsniveau bleibt mindestens gleich (keine neu eroefneten write-Pfade).

## Phase 6: Provider-Validator und CI-Strict-Mode scharf schalten

Ziel:
Neue/veraenderte Provider koennen den Contract nicht mehr stillschweigend verletzen.

DoD:
- Validator prueft Pflichtmetadaten, doppelte IDs/Tasknamen, ungueltige Capability-Strings, Alias-Fehler.
- Integrations-Tests decken Identity, Activation, Capability und Deny-Reasons ab.
- Optionaler CI-Strict-Mode fuer neue Provider ist definiert und aktivierbar.

Akzeptanzkriterien:
- Contract-Verletzungen schlagen in CI reproduzierbar fehl.
- Konflikte (z. B. doppelte Task-Identitaeten) sind im Fehlerreport eindeutig.
- Ein neuer Provider ohne Pflichtmetadaten kann nicht unbemerkt integriert werden.

## 5. Querschnittliche Akzeptanzkriterien (ueber alle Phasen)

- Konsistenz: Gleicher User + gleicher Kontext + gleicher Task liefert in Diagnose, Registry und Runtime dieselbe Entscheidung.
- Nachvollziehbarkeit: Jede Deny-Entscheidung ist auf Identity/Activation/Capability/Context rueckfuehrbar.
- Rueckwaertskompatibilitaet: Bestehende Tasks bleiben in der Uebergangsphase funktional, bis zentrale Gates voll uebernommen sind.
- Sicherheit: Prompt-Katalog oder Sichtbarkeit allein hat keine sicherheitsrelevante Wirkung ohne zentrales Enforcement.

## 6. Risiken und Gegenmassnahmen

Risiko: Divergenz zwischen Legacy-`validate` und neuen Gates.
Gegenmassnahme: Es gibt keinerlei Migration, da das Produkt noch nicht veröffentlicht ist. wir können die Struktur ändern, ohne auf den alten Zustand Rücksicht nehmen zu müsssen.

Risiko: Alias/Move erzeugt stale Embeddings/Katalog.
Gegenmassnahme: Ausführung rebuild Task Catalog Task bei Identity-Metadaten-Change verpflichtend.

Risiko: Implizite Overrides durch Discovery-Reihenfolge.
Gegenmassnahme: Konflikte als Diagnosefehler sichtbar machen und in CI blockieren.

## 7. Abnahme-Checkliste (Go/No-Go)

- Contract-Dokument ist final und teamseitig freigegeben.
- Evaluator-Output deckt alle standardisierten Deny-Reasons ab.
- Registry-Default zeigt ausfuehrbare Tasks, Debug-Modus zeigt Rest mit Gruenden.
- Runtime blockt zentral konsistent, bevor Task-Execute startet.
- CI verhindert neue Contract-Verletzungen.
- Support kann fuer Task X / User Y / Kontext Z den Deny-Grund direkt aus Diagnose lesen.
