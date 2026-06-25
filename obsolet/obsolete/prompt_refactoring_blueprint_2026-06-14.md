# Prompt-Refactoring — Sammelblueprint

**Status:** 🟢 Discovery-Lücke behoben · H2/H4/H5/H6/H7 umgesetzt · Suite stabil (3 nichtdet. real_llm-Failures)
**Angelegt:** 2026-06-14 · **Letzter Stand:** 2026-06-15 (vollständiger Suite-Lauf)
**Thema:** Refactoring der Agent-Prompts (Selector / Constructor / Synchronizer)

---

## Aktueller Stand (Snapshot 2026-06-15, vollständiger Suite-Lauf inkl. real_llm)

Vollständiger Lauf `bookingextension_agent_testsuite` **mit** Live-LLM (MiniMax-M2.7,
text-embedding-3-small):

```
Tests: 435 · Failures: 3 · Skipped: 2 · Deprecations: 85 (Moodle-Rauschen) · ~9:46 min
```

### ✅ Was bereits funktioniert

| Bereich | Stand |
|---------|-------|
| **Deterministische Suite** (~373 Tests) | **vollständig grün**, 0 Failures |
| **Discovery-Lücke** (course.*/core.diagnose_* im Booking-Kontext) | **behoben** (H7) — keine „kein Skill"-Verweigerung mehr; `add_quiz`, `add_activity`, `diagnose_*`, `update_activity/quiz` werden gefunden & ausgeführt |
| **H2** genannter Kurs → `coursequery` (Construction) | umgesetzt (commit 9299077) |
| **H4** Selektor plant keinen Suchschritt für Ziel im Kontext | umgesetzt (9299077) |
| **H5** Synchronizer Entitätstyp-/Link-Policy | umgesetzt (9299077) |
| **H6** Zielkurs-Hinweis vor Write (`build_operating_context_note`) | bereits vorhanden (verifiziert) |
| **H7** Discovery: Intent-Eskalation + Kontext-als-Prior + Embeddings-Fixture | umgesetzt (ab3b171, 3d2fb37) |
| **Smoke-Matrix-Coverage** (alle registrierten Skills) | Coverage-Meta-Test grün |
| **real_llm-Einzeltests** (list_skills, generate_questions, confirmation_flow, search_*, get_current_user, lecture_autoconfirm, normal_option_datetime) | grün |

### ⚠️ Was noch offen ist — Warum — Fix-Weg

**O1 — real_llm-Clarification bei unvollständigem Input (die 3 aktuellen Failures).**
Alle 3 sind **nicht** „kein Skill", sondern `clarification` (Skill wird korrekt gewählt,
Planner fragt nach fehlendem Feld). **LLM-nichtdeterministisch** (in anderen Läufen grün).

| Skill | Planner-Frage | Ursache | Fix-Weg |
|-------|---------------|---------|---------|
| `course.add_activity` | „Welcher Kurs? Kein aktueller Kurs-Kontext erkannt." | LLM verwirft sporadisch den ambienten Kurs-Kontext | Selektor-/Construction-Regel schärfen: ambienter Kurs aus `SYSTEM_RUNTIME.moodle_context` ist gültiger Default; nicht nachfragen, wenn Kontext vorhanden |
| `course.diagnose_access` | „Welche Aktivität?" | optionales `activityquery` — Szenario unterspezifiziert; Skill fragt statt kursweit zu diagnostizieren | Szenario-Prompt präzisieren **oder** `allow_clarification`; Skill: bei fehlender Aktivität kursweit diagnostizieren statt fragen |
| `mod_booking.update_option_trainer` | „teacherids fehlt" | Prompt liefert `teacheremail`, Skill-`minimal_input` will `teacherids` | Name/E-Mail→id-Auflösung in Construction/Skill **oder** `minimal_input` um `teacheremail` erweitern |

**O2 — create/update **options**: Construction sporadisch unzuverlässig** (Analyse #3, Muster C).
Diesen Lauf **nicht** aufgetreten (update_option/create_selflearning grün), aber in
früheren Läufen real: `update_option` → „no commands generated" (348-Klasse);
`create_selflearning_option` → schema-fremde Keys (`courseid`/`contextid`) → Reject.
**Intermittierend.** → **Georgs „radikaler Rethink":** schmaleres, intent-getriebenes
Option-Input-Contract; kanonische Keys **normalisieren statt rejecten**; H1
(Skill/Command strukturell fixieren) + Recovery statt `error`.

**O3 — Sprachdrift (Modell).** MiniMax-M2.7 antwortet sporadisch auf Chinesisch
(diesen Lauf nicht aufgetreten). → Sprach-Contract im Synchronizer/Selector härten
bzw. Modellwahl prüfen. Reines Modellverhalten, kein Skill-Defekt.

**O4 — `namespace_hint` = Popularität statt echtem Kontext** (H7-Folgeschritt).
Funktioniert dank Intent-Guard bereits, ist aber unsauber → Hint aus dem realen
ambienten cm/Kurs ableiten ([orchestrator.php:2888](../../../classes/local/wizard/orchestrator.php#L2888)).

**O5 — `oneclick.create_instance` / `wizard.explain_docs`** → bewusst `skip_reason`
(Provisioner-Templates bzw. Docs-Embedding-Index im CI nicht vorhanden).

**O6 — H6 offene Frage:** Zielkurs-Hinweis auch bei session-auto-confirmten
Schreibvorgängen erzwingen? (Flowchart-Klärung mit Georg, siehe H6.)

### Priorisierung des nächsten Schritts
1. **O2** (create/update options) — der eigentliche „radikale Rethink", höchster Wert.
2. **O1** — kleine, sichere Schärfungen (ambienter Kontext als Default, Szenario-/Contract-Feinschliff).
3. **O4** — sauberer Kontext-Hint (entkoppelt Discovery endgültig von der Skill-Popularität).

---

## Zweck

Dieses Dokument sammelt konkrete Vorfall-Analysen (Thread-für-Thread), um daraus
ein gezieltes **Refactoring der LLM-Prompts** der Agent-Pipeline abzuleiten. Die
Pipeline besteht aus den Phasen:

- **Discovery** (Embedding-Ranking, `p=disc`)
- **Selection / Planner** (`p=sel`) — wählt genau ein Skill
- **Parameter-Construction** (`p=cons`) — baut Parameter für das gewählte Skill
- **Synchronizer** (`sync`) — formuliert die finale User-Antwort aus Observations

Jeder Eintrag unten beschreibt einen realen Vorfall, ordnet die Ursachen den
Phasen zu und destilliert daraus **prompt-relevante** Änderungen. Skill-/Code-Fixes
werden ebenfalls notiert, sind aber als solche markiert, damit das Prompt-Refactoring
nicht versehentlich Logik nachbaut, die in den Skill gehört.

> **Arbeitsweise:** Wir sammeln zunächst mehrere Analysen. Erst wenn genug Muster
> vorliegen, leiten wir den eigentlichen Prompt-Diff (Selector-/Constructor-/Sync-
> Systemprompts) ab. Querschnitt-Muster werden am Ende unter „Synthese" gepflegt.

---

## Analyse #1 — Thread 347: Aktivität im falschen Kurs + irreführende „Kursliste"

**Datum des Vorfalls:** 2026-06-14, ~18:02–18:09
**Symptom:** Ein Textfeld (Label), das laut User in Kurs „selflearning" angelegt
werden sollte, landete still in Kurs 11 („ai", dem ambienten Thread-Kontext).

### Ablauf (drei Nutzer-Turns)

**Turn 1 (18:02) — die „falsche Kursliste":**
User fragt nach verfügbaren Angeboten. Der Planner ruft `mod_booking.search_options`
auf; der Synchronizer baut daraus (Eintrag 1788) folgende Liste:

```
- ai          – Buchungsaktivität im Kurs „ai"
- booking     – Buchungsaktivität im Kurs „booking"
- Musi        – Buchungsaktivität im Kurs „booking"
- selflearning– Buchungsaktivität im Kurs „booking"
- slotbooking – Buchungsaktivität im Kurs „slotbooking"
```

**Turn 2 (18:08):** „kannst du im Kurs 'Taskflow' ein Textfeld … hinzufügen?"
→ Rückfrage nach der Sektion.

**Turn 3 (18:09):** „… Bitte im Kurs 'selflearning' hinzufügen."
→ `course.add_activity` lief → Label landete in Kurs 11 („ai") — **falscher Kurs** (Run 183).

Der Synchronizer hat den Fehler am Ende sogar selbst erkannt (Eintrag 1798):
„Hinweis: Das Textfeld wurde im Kurs 'ai' erstellt, nicht wie gewünscht im Kurs
'selflearning'." — aber zu diesem Zeitpunkt war die Aktivität schon erstellt.

### DB-Abgleich (warum die Verwechslung entstand)

- **„selflearning" ist gar kein Kurs.** Es ist eine Buchungsaktivität
  (`mod_booking`-Instanz, cmid 23) innerhalb von Kurs 3 (shortname „booking").
- **„Taskflow"** ist ein echter Kurs (id 8, shortname „taskflow").
- **Kurs 11** heißt „ai" — das war der **ambiente Kontext** des Threads
  (Run 182: „Im aktuellen Kontext …").

Der User übernahm in Turn 3 einen Namen aus der Liste von Turn 1, der dort als
„selflearning" stand — aber das ist ein **Aktivitätsname, kein Kurs**. Der Agent
hat das nicht abgefangen, sondern still in den ambienten Kurs 11 geschrieben.

### Ursache 1 — Aktivität im falschen Kurs (der eigentliche Bug)

Zwei Defekte greifen ineinander:

**1a. Constructor hat den Kursnamen nicht in die Parameter übernommen.**
In Eintrag 1797 lautet die Constructor-Message „… im Kurs 'selflearning' hinzu",
die tatsächlichen `parameters` enthalten aber weder `coursequery` noch `courseid`:

```json
{"modname":"label","name":"…","intro":"…","section":"last","settings":{}}
```

Das Schema bietet `coursequery` zwar an (`add_activity_skill.php:169`), aber dessen
Beschreibung sagt: *„Target a DIFFERENT course than the current one, ONLY when the
user explicitly names one … Leave empty to use the current course."* Das LLM hat
„selflearning" (aus Turn 1 als etwas im aktuellen Kontext bekannt) fälschlich als
den aktuellen Kurs interpretiert und das Feld leer gelassen.

**1b. Skill fällt bei leerem `coursequery` still auf den ambienten Kurs zurück.**
`get_target_selector()` (`add_activity_skill.php:100-107`) gibt bei leerem
`courseid`/`coursequery` `null` zurück → der Operating-Context-Resolver läuft gar
nicht → es wird der ambiente Thread-Kontext (Kurs 11) verwendet. `preflight()`
(`:290-300`) prüft nur, ob überhaupt ein Kurskontext da ist — nie, ob der Nutzer
einen anderen, benannten Kurs gemeint hat. Es gibt keinen Guard „Du hast einen
Kurs genannt, den ich nicht zuordnen kann".

→ **Kernproblem:** Ein explizit genannter Kursname kann lautlos verloren gehen
(Constructor lässt das Feld weg), und der Skill schreibt dann kommentarlos in den
ambienten Kurs, statt nachzufragen.

### Ursache 2 — „verfügbare Kurse falsch verlinkt und Liste stimmt nicht"

Die Liste aus Turn 1 ist keine Kursliste, sondern eine Liste aus
`mod_booking.search_options`. Die Daten sind für sich genommen korrekt
(selflearning ist eine Buchung in Kurs „booking"), aber die Darstellung ist
irreführend:

- Mit „im Kurs '…'" über den Shortname beschriftet — wirkt wie eine Kursauswahl.
- Die Links zeigen (laut Run 182) auf `mod/booking/view.php` statt `course/view.php`.
  Daher „falsch verlinkt" aus Kurs-Sicht.
- Aktivitätsnamen wie „selflearning" lassen sich nicht von Kursnamen unterscheiden
  — genau das hat die spätere Verwechslung ausgelöst.

Es war also nie eine Kursliste — sie wurde nur wie eine gelesen.

### Vorschläge zur Vermeidung

**Priorität 1 — kein stiller Fallback bei explizit genanntem Kurs** (verhindert den Hauptschaden):
*(Skill-Fix, kein Engine-Eingriff)*
- In `add_activity_skill` (analog `add_quiz`, `update_activity`) ergänzen: Wenn der
  Nutzer einen Kurs benannt hat, dieser aber nicht auf den ambienten Kontext passt
  bzw. nicht eindeutig auflösbar ist → **Clarification statt Schreiben**. Konkret:
  den genannten Kursnamen immer durch `course.search_courses` auflösen; bei
  Mehrdeutigkeit nachfragen, bei genau 1 Treffer den `courseid` setzen.
- Der Skill kann im `preflight` selbst prüfen, ob ein „named course"-Signal vorliegt,
  das aber nicht im Operating-Context angekommen ist.

**Priorität 2 — den Kursnamen verlässlich in die Parameter bringen** (**prompt-relevant**):
- `coursequery` von `anchor_fields` zusätzlich in `input_fields_for_prompt`
  (`add_activity_skill.php:184-187`) aufnehmen, damit die Construction-Phase das
  Feld aktiv befüllt.
- Schema-Beschreibung schärfen: „Wenn der Nutzer in dieser oder einer vorherigen
  Nachricht einen Kursnamen nennt, muss er hier eingetragen werden — niemals als
  'aktueller Kurs' interpretieren."

**Priorität 3 — Aktivitäts- vs. Kurs-Liste sauber trennen** (**prompt- + Skill-relevant**):
- Im Synchronizer/`search_options`-Preview klar als „Buchungsaktivitäten" labeln und
  nicht „im Kurs 'X'" über den Shortname, sondern z. B. „Aktivität selflearning
  (Kurs: Booking, …)". Links eindeutig auszeichnen.
- Wenn der Nutzer „Kurse" verlangt, einen echten Kurs-Resolver
  (`course.search_courses`) nutzen, nicht die Buchungsliste.

**Priorität 4 — Bestätigung mit explizitem Zielkurs** (**prompt-relevant, R2-Preview**):
- Die R2-Confirmation-Preview von `add_activity` sollte den Zielkurs zeigen
  („Aktivität wird in Kurs ai (ID 11) erstellt"), damit eine Fehlauflösung **vor**
  dem Schreiben auffällt — nicht erst im Nach-Hinweis des Synchronizers.

> Punkt 1 und 4 hätten den konkreten Vorfall verhindert (Nachfrage bzw. sichtbarer
> Zielkurs vor Ausführung); Punkt 2 und 3 beheben die Ursachen, die zur Verwechslung
> geführt haben.

### Prompt-relevante Essenz (für das Refactoring)

| Phase | Befund | Prompt-Hebel |
|-------|--------|--------------|
| Construction | Explizit genannter Kursname wurde nicht in `coursequery` übernommen | `coursequery` in `input_fields_for_prompt` + geschärfte Feldbeschreibung; Regel „benannter Kurs ≠ aktueller Kurs" |
| Synchronizer | Buchungsaktivitäten als „Kurse" dargestellt, falsche Links | Listen-Rendering nach Entitätstyp trennen; Link-Policy pro Typ |
| Synchronizer (R2) | Zielkurs erst im Nachhinein genannt | Confirmation-Preview muss aufgelösten Zielkurs (Name + ID) zeigen |

---

## Analyse #2 — Thread 348: Constructor wechselt das Skill → stiller Turn-Abbruch

**Datum des Vorfalls:** 2026-06-14, 18:13–18:17
**Symptom:** Der letzte „probiere es nochmal"-Turn endet ohne jede User-Antwort
(harter `error`, kein Synchronizer, kein Run).

### Ablauf (vier Nutzer-Turns)

| Turn | User-Eingabe | Verlauf | Ergebnis |
|------|--------------|---------|----------|
| 1 (18:13) | „erstelle im Kurs ai ein neues Quiz mit fünf Fragen zu Agentic AI" | Selector → `course.add_quiz`, Constructor baut saubere Params (`course:11, count:5`), aber Construction liefert `SKILL_DENIED` | Sync (korrekt): „Funktion nicht aktiviert, an Admin wenden" |
| 2 (18:13) | „zeig mir alles, was du kannst" | `wizard.list_skills` | ok |
| 3 (18:16) | „schau nochmal, welche skills du hast" | `wizard.list_skills` | ok |
| 4 (18:17) | „ich glaube du hast jetzt die Möglichkeit … probiere es nochmal" | Selector → `course.search_courses` (Schritt 1 eines Multi-Step-Plans). **Constructor emittiert aber `question.generate_questions`** | **harter `error`, keine User-Antwort** |

### Root Cause — Constructor-Skill-Drift (Construction-Phase)

In Turn 4 wählt die **Selection** als ersten Schritt `course.search_courses`
(debug 1813). Die **Construction** (debug 1814) baut jedoch nicht Parameter für
dieses Skill, sondern springt voraus zum Endziel-Skill:

```json
{"skill":"question.generate_questions","version":1,
 "parameters":{"courseid":11,"question_count":5,"topic":"Agentic AI", …}}
```

Der Engine-Guard greift (Message 1507):

> `CONTRACT_VIOLATION: phase "parameter_construction" command skill is outside discovery-ranked allow-list.`
> `issue_codes: ["CONTRACT_PHASE_SKILL_NOT_ALLOWED"]`

**Mechanik (verifiziert im Code):**
- Die Construction-Allow-List ist exakt das selektierte Skill:
  `$constructionallowedskills = [$selectedskill];` ([orchestrator.php:1362](../../../classes/local/wizard/orchestrator.php#L1362)).
- Der Guard verwirft jedes `command.skill`, das davon abweicht
  ([interpreter.php:495-534](../../../classes/local/wizard/interpreter.php#L495-L534)).
- Der Constructor-Prompt **enthält die Regel bereits**:
  *„This phase is constructor-only … Do not perform skill switching … Every
  command.skill MUST match selected_skill from phase handoff."*
  ([prompt_policy_builder.php:107-112](../../../classes/local/wizard/prompt_policy_builder.php#L107-L112)).

→ **Kernbefund:** Die Regel existiert, wird aber genau dann ignoriert, wenn das
selektierte Schritt-1-Skill (`search_courses`) **nicht zum Aktionsverb des Users
passt** („Quiz/Fragen erstellen"). Das LLM „korrigiert" eigenmächtig auf das
Endziel-Skill. Ein Textverbot allein reicht nicht — das Modell überschreibt es
unter Zielsog.

### Zweiter Defekt — stiller Abbruch ohne Recovery

`CONTRACT_PHASE_SKILL_NOT_ALLOWED` erzeugt `response_type=error` mit **leerer
message** und `error_class=internal_contract`. Laut Flowchart-Knoten
`FW_RETRY_OBS` werden im Loop nur `CONTRACT_PARSE_ERROR` und
`CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED` als RETRY_HINT erneut versucht —
dieser Code **nicht**. Der Turn stirbt ohne Synchronizer-Erklärung.

### Prompt-relevante Essenz

| Phase | Befund | Prompt-Hebel |
|-------|--------|--------------|
| Selection | Wählt `search_courses` als Vorbereitungsschritt, obwohl der Zielkurs („ai") bereits ambienter Kontext ist | Selektor soll Vorbereitungsschritte nur planen, wenn das Ziel **nicht** schon im Kontext steht |
| Construction | Skill-Drift trotz vorhandener „MUST match selected_skill"-Regel | Skill-Identität **strukturell** erzwingen statt nur per Text: Constructor liefert nur `parameters`, Engine setzt `skill` |
| Runtime | `CONTRACT_PHASE_SKILL_NOT_ALLOWED` → stiller Tod | (Framework) Retry-Observation + Synchronizer-Humanisierung ergänzen |

---

## Hypothesen → konkrete Refactoring-Schritte

Aus #1 (347) und #2 (348) destilliert. Jede Hypothese nennt **Befund → Ursache →
Prompt-Änderung → Flowchart-Knoten → Risiko**. Code-/Skill-Anteile sind als solche
markiert, damit das Prompt-Refactoring keine Domänenlogik nachbaut.

### H1 — Constructor darf das Skill nicht mehr „wählen" (Construction)

- **Befund:** Constructor emittierte in 348 ein anderes Skill als selektiert →
  harter Abbruch.
- **Ursache:** Das Skill ist ein Output-Feld des Constructors, obwohl es laut
  Handoff bereits feststeht. Die Textregel „MUST match selected_skill" ist unter
  Zielsog nicht robust.
- **Prompt-Änderung:** Constructor-Output-Contract auf **nur `parameters`** (plus
  `response_type`, `message`) reduzieren. Den canonical-command-Block aus
  [prompt_policy_builder.php:107-112](../../../classes/local/wizard/prompt_policy_builder.php#L107-L112)
  so umformulieren, dass das Modell **kein** `skill`-Feld mehr produziert.
- **Komplementär (Code, kein Prompt):** Die Engine setzt `skill`/`version`
  deterministisch aus `$selectedskill` (Stelle ist bereits vorhanden:
  [orchestrator.php:1362](../../../classes/local/wizard/orchestrator.php#L1362)),
  der Guard bleibt als Sicherheitsnetz. → Skill-Drift wird **strukturell
  unmöglich**, nicht nur „verboten".
- **Flowchart:** deckt sich mit `SELSKILL` („selected_skill handoff — exactly one
  skill from selector to constructor") und `PCON` („build parameters only after
  concrete skill selection"). LG_PLAN: „no joint skill-choice + parameter
  generation". **Kein Konflikt** — der Vorschlag macht den Flowchart-Vertrag
  durchsetzbar.
- **Risiko:** niedrig. Reduziert Freiheitsgrade des Modells.

### H2 — Genannter Kurs/Ziel muss verlässlich ins Feld (Construction)

- **Befund:** Explizit genannter Kursname geht verloren (347: Feld leer → ambient)
  oder wird falsch als „aktueller Kurs" gedeutet.
- **Ursache:** Schema-Beschreibung von `coursequery` lädt zum Weglassen ein
  („Leave empty to use the current course"); Feld nicht in
  `input_fields_for_prompt` (laut 347-Analyse, [add_activity_skill.php:184-187](../../../classes/local/wizard/course/skills/add_activity_skill.php#L184-L187)).
- **Prompt-Änderung:** (a) `coursequery`/Ziel-Felder in `input_fields_for_prompt`
  aufnehmen; (b) Feldbeschreibung schärfen: *„Wenn der Nutzer in dieser oder einer
  vorherigen Nachricht einen Kurs-/Zielnamen nennt, MUSS er hier eingetragen
  werden — niemals als 'aktueller Kurs' interpretieren."*; (c) generische
  Construction-Regel ergänzen: vom User genannte Eigennamen sind grounded values
  und dürfen nicht zugunsten des Kontexts verworfen werden.
- **Flowchart:** `PF_L2P` (`skill_operating_context_resolver`) + LG_CTX
  („cross-context"). Der Resolver existiert, bekommt aber bei leerem Feld nichts
  zu tun. **Kein Konflikt.**
- **Risiko:** mittel — Schema-Texte sind skill-spezifisch; pro Skill konsistent
  halten.

### H3 — Kein stiller Fallback bei genanntem, aber nicht auflösbarem Ziel (Skill/Code, prompt-flankiert)

- **Befund:** „selflearning" (kein Kurs) → still in ambient geschrieben (347);
  „ai" → mal Clarification-Loop (324), mal Fehler (345/172), während „taskflow"
  sauber auflöst (345/175).
- **Ursache:** Bei leerem/uneindeutigem Ziel fällt der Skill kommentarlos auf den
  ambienten Kontext zurück; es gibt keinen Guard „Name genannt, aber nicht
  zuordenbar".
- **Änderung:** primär **Skill-Code** (preflight): genannten Namen immer via
  `course.search_courses` auflösen; 0/≥2 Treffer → `clarification`, genau 1 →
  `courseid` setzen; **nie** stiller ambient-Fallback, wenn ein Name fiel.
  **Prompt-Flanke:** Synchronizer-Regel, eine solche Clarification klar zu
  benennen („Ich konnte den genannten Kurs 'X' nicht eindeutig zuordnen …").
- **Flowchart:** `PF_L2P` liefert dafür bereits `CONTEXT_TARGET_UNRESOLVED →
  clarification`. **Lücke:** greift nur, wenn der Resolver überhaupt mit einem
  Ziel aufgerufen wird (siehe H2). **Kein Konflikt**, ergänzt den bestehenden Pfad.
- **Risiko:** mittel. Verhindert den Hauptschaden aus 347.

### H4 — Selektor plant keine Vorbereitungsschritte für bereits bekannte Ziele (Selection)

- **Befund:** 348 Turn 4 wählt `course.search_courses`, obwohl der Zielkurs „ai"
  ambienter Kontext ist — unnötiger Schritt, der erst den Skill-Drift auslöste.
- **Ursache:** Selektor plant „erst Kurs suchen, dann Fragen" auch dann, wenn das
  Ziel schon im `SYSTEM_RUNTIME.moodle_context` steht.
- **Prompt-Änderung:** Selektor-Routing-Regel: *„Plane keinen
  Auflösungs-/Suchschritt für eine Entität, die bereits im SYSTEM_RUNTIME-Kontext
  benannt ist; wähle direkt das Aktionsskill."*
- **Flowchart:** `DISC_CTX`/`context_prior_builder` („current cm/course … used as
  ranking prior"). Heute nur Ranking-Prior, kein Plan-Sparsignal. **Kein
  Konflikt**, schärft die Nutzung des Kontexts in der Planung.
- **Risiko:** mittel — darf legitime Suchanfragen („zeig mir alle Kurse") nicht
  unterdrücken; eng an „Ziel bereits im Kontext benannt" binden.

### H5 — Entitätstyp in Listen/Previews eindeutig (Synchronizer)

- **Befund:** Buchungsaktivitäten als „im Kurs 'X'" gelistet → später als Kurse
  fehlgelesen (347). Verstärkt durch 330 („mehrere Buchungsaktivitäten").
- **Prompt-Änderung:** Synchronizer-Render-Regel: Entitätstyp explizit auszeichnen
  („Aktivität ‚selflearning' (Kurs: Booking)"), nicht Shortname als „Kurs"; Links
  pro Typ korrekt (Aktivität → `mod/.../view.php`, Kurs → `course/view.php`).
- **Flowchart:** `SYNC_CONTRACT`/LG_SYNC + Link-Policy in `SYNC_RUN`. **Kein
  Konflikt** — Präzisierung der Darstellungsregeln (mutiert keine Semantik).
- **Risiko:** niedrig.

### H6 — Zielkurs in der Bestätigungs-Preview sichtbar (Decision/Synchronizer)

- **Befund:** Falsch aufgelöster Kurs fiel in 347 erst im Synchronizer-Nachsatz
  auf — nach dem Schreiben.
- **Beobachtung vs. Flowchart:** `D_TARGET_NOTE` existiert bereits und soll den
  Zielkurs „ALWAYS — incl. ambient course" an jede **mutierende
  `confirmation_request`** hängen. In 347/345 lief der schreibende Turn aber als
  auto-confirmter `skill_call` (R1 session-allow), **ohne** neue
  `confirmation_request` → der Hinweis erschien nicht vor dem Schreiben.
- **Prompt-/Flowchart-Frage (für Georg):** Soll der Ziel-Hinweis auch bei
  session-auto-confirmten Schreibvorgängen erzwungen werden, oder reicht H3
  (Clarification bei Nichtauflösung)? **Hier nicht eigenmächtig entscheiden** —
  Diskrepanz Code↔Flowchart, siehe Policy.
- **Risiko:** offen — bewusst als Klärungspunkt geführt.

### H7 — Discovery: intent-bewusste Eskalation + Kontext-als-Prior ✅ UMGESETZT 2026-06-15

- **Befund (Analyse #3, Muster A/B):** `course.*` / `core.diagnose_*` waren im
  Booking-Kontext **nicht discoverbar** (registriert+executable, aber „kein Skill"),
  bzw. wurden auf eine Buchungsoption fehlgeroutet.
- **Ursachenkette (verifiziert im Code):**
  1. `namespace_hint` = **häufigste** Skill-Namespace (Popularität), nicht der echte
     Kontext → immer `mod_booking`
     ([orchestrator.php:2888](../../../classes/local/wizard/orchestrator.php#L2888)).
  2. `family_registry_service` verengte die **Ranking-Gesamtmenge** hart auf
     `context∪core` → course/diagnose-Familien wurden **vor** dem Ranking gedroppt.
  3. `core_family_set` nimmt nur `wizard.*` als always-on → core/course nicht baseline.
  4. Stage-A-Kurzschluss bei Score ≥ 0.60 (Booking-Prior) → nie Eskalation auf B/C.
  5. Family-Embeddings-Fixture im Test **veraltet** (Namespace-Split) → semantischer
     Pfad aus → Intent-Signal fehlte.
- **Umgesetzt:**
  - **(1) Intent-bewusster Gate** in
    [discovery_stage_controller.php](../../../classes/local/wizard/services/discovery/discovery_stage_controller.php):
    Stage A gilt nur als „sufficient", wenn die **top-semantische (Intent-)Familie**
    in Stage A liegt; sonst Eskalation (`escalation_reason=stage_a_intent_outside`).
    Ohne Embeddings inert (graceful, `INTENT_SEMANTIC_MIN`).
  - **(Kontext-als-Prior, notwendige Begleitkorrektur)** in
    [family_registry_service.php](../../../classes/local/wizard/services/discovery/family_registry_service.php):
    Ranking-Universe = **alle** Familien; `namespace_hint` markiert nur noch den
    Stage-A-Prior, verengt das Universe nicht mehr. Bringt Code auf den
    Flowchart-Vertrag „context = ranking prior, not hard filter" (LG_DET).
  - **(3) Embeddings-Fixture** neu gebaut
    ([skill_catalog_embeddings.csv](../../../tests/agent/fixtures/skill_catalog_embeddings.csv),
    `cli/rebuild_embeddings_fixture.php --embed`; created=9, updated=11) → semantischer
    Pfad im Test aktiv.
- **Verifikation:** 14/14 Staging-Unit-Tests grün (inkl. 3 neue); real_llm vorher
  „kein Skill" → `course.add_quiz`/`course.add_activity`/`course.diagnose_grades` ✔,
  `core.diagnose_permissions` erreicht jetzt das Skill; volle non-real_llm-Suite
  435 Tests, 0 Failures.
- **Flowchart:** angepasst (DISC_A_OK, FREG, DISC_A, LG_PLAN) — bringt die Doku auf
  das implementierte „prior, not filter"-Verhalten.
- **Noch offen (separat):** `namespace_hint` aus dem **echten** Moodle-Kontext statt
  Popularität (Ursache 1) und die Namespace-Split-Baseline (Ursache 3, Mandatory-Tier)
  — wirken bereits durch (1)+(3) nicht mehr blockierend, bleiben aber sauberere Folge-Schritte.

---

## Thread-übergreifende Evidenz (Hypothesen-Test, ≥5 Threads)

Getestet an 345, 347, 348, 324, 321, 320, 336, 332, 330 (LLM-Debug + Runs).

| Thread | Eingabe (gekürzt) | Beobachtung | Stützt |
|--------|-------------------|-------------|--------|
| **347** | Label „im Kurs selflearning" | `course.add_activity` → **courseid 11** (ambient „ai"), Name nie aufgelöst (Run 183) | H2, H3, H5, H6 |
| **348** | „Quiz mit 5 Fragen … probiere nochmal" | Constructor-Skill-Drift `search_courses`→`generate_questions` → `CONTRACT_PHASE_SKILL_NOT_ALLOWED`, stiller Tod | H1, H4 |
| **345** | „im Kurs Taskflow einen Link …" | `add_activity` → **courseid 8** korrekt (Run 179-181) — eindeutiger Name löst auf | Gegenprobe: H2/H3 greifen bei eindeutigem Namen ✓ |
| **324** | „zwei Fragen zu amsterdam im kurs ai" | `coursequery="ai"` → **Fehler** „Please tell me which course…" (Run 172), 2× Clarification-Loop | H2, H3 |
| **321** | „zwei Fragen zu Paris im kurs todolist" | Erst Clarification, dann erfolgreich (Run 1592→1596) — Reibung bei Kursauflösung | H2, H4 |
| **345** (172) | diagnose_enrolment Kurs „ai" | `coursequery="ai"` → Fehler „nicht eindeutig", `taskflow` (175) ok | H3 (Kurzname „ai" inkonsistent) |
| **330** | „was kann ich hier buchen?" | Clarification „mehrere Buchungsaktivitäten" — Entitäts-Ambiguität | H5 |
| **336** | „list_skills" | 1. Versuch „Funktion nicht verfügbar", 2. Versuch ok — Availability-Flackern | (Rand: Recovery/Messaging) |

**Belastbares Kernmuster:** Die **Auflösung eines benannten Ziels (Kurs/Aktivität)**
ist die mit Abstand häufigste Fehlerquelle und über **beide Prompt-Generationen**
hinweg stabil (alte „kombinierte" Prompts ≤336/324/321 wie neue 2-Phasen-Prompts
≥345). Drei Ausprägungen:
1. **Name fällt weg** → stiller ambient-Fallback (347, der gefährlichste Fall).
2. **Name gesetzt, aber nicht auflösbar** („ai") → Clarification-/Fehlerschleife (324, 345).
3. **Name eindeutig** („taskflow") → funktioniert (345).

Constructor-Skill-Drift (348) ist seltener, aber **maximal schädlich** (stiller
Totalausfall ohne User-Antwort).

---

## Flowchart-Abgleich (Konformität der Vorschläge)

| Hypothese | Flowchart-Knoten | Verhältnis |
|-----------|------------------|-----------|
| H1 | `SELSKILL`, `PCON`, LG_PLAN | ✅ macht bestehenden Vertrag durchsetzbar |
| H2 | `PF_L2P`, LG_CTX | ✅ aktiviert vorhandenen Resolver-Pfad |
| H3 | `PF_L2P` → `CONTEXT_TARGET_UNRESOLVED` | ✅ schließt Lücke im vorhandenen Pfad |
| H4 | `DISC_CTX`/`context_prior_builder` | ✅ erweitert Kontextnutzung (Ranking → Planung) |
| H5 | `SYNC_CONTRACT`, LG_SYNC | ✅ Darstellungsregel, keine Semantikmutation |
| H6 | `D_TARGET_NOTE` | ⚠️ **Klärungspunkt** — Note greift heute nur bei `confirmation_request`, nicht bei auto-confirmten `skill_call` |

> **Policy-Hinweis:** H6 berührt eine mögliche Code↔Flowchart-Diskrepanz. Gemäß
> [[feedback_flowchart_policy]] **nicht eigenmächtig angleichen** — erst mit Georg
> klären.

---

## Implementierungsplan + Umsetzungsstand

Reihenfolge nach Schadenshöhe × Aufwand. P = Prompt, S = Skill-Code, F = Framework.

> **Umsetzungsentscheidung Georg (2026-06-15):** Umgesetzt werden **H2, H4, H5**;
> **H6** war bereits vorhanden (verifiziert). **H1, H3** und der Framework-Retry
> (Schritt 6) werden **bewusst nicht** umgesetzt.

| # | Schritt | Typ | Status | Verhindert | Akzeptanzkriterium |
|---|---------|-----|--------|-----------|--------------------|
| 1 | **H1**: Constructor-Output `parameters`-only; Engine setzt `skill`; Guard bleibt Netz | P + S | ⛔ **zurückgestellt** (Georg) | 348 (Skill-Drift) | — |
| 2 | **H3**: kein stiller ambient-Fallback; 0/≥2 Treffer → Clarification | S (+P) | ⛔ **zurückgestellt** (Georg) | 347 (falscher Kurs) | — |
| 3 | **H2**: genannten Kurs verlässlich in die Construction (Schema-Beschreibung) | P | ✅ **erledigt** 2026-06-15 | 347/324 (Name verloren/falsch gedeutet) | Constructor trägt genannten Kursnamen ins `coursequery`-Feld ein |
| 4 | **H4**: Selektor plant keinen Suchschritt für bereits im Kontext benanntes Ziel | P | ✅ **erledigt** 2026-06-15 | 348/321 (unnötiger Schritt → Drift/Reibung) | „Quiz in aktuellem Kurs" wählt direkt das Aktionsskill, kein `search_courses` davor |
| 5 | **H5**: Synchronizer-Render-Regel Entitätstyp + Link-Policy | P | ✅ **erledigt** 2026-06-15 | 347/330 (Aktivität als Kurs fehlgelesen) | Listen labeln „Aktivität X (Kurs: Y)"; korrekte Link-Ziele |
| 6 | **F**: `CONTRACT_PHASE_SKILL_NOT_ALLOWED` → RETRY_HINT + Humanisierung | F | ⛔ **zurückgestellt** (Georg) | 348 (stiller Tod) | — |
| — | **H6**: Ziel-Hinweis vor Write | P/F | ✅ **bereits vorhanden** (verifiziert) | 347 (Sichtbarkeit vor Write) | s. u. |

### Umsetzungsdetails (2026-06-15)

**H6 — bereits implementiert (verifiziert, keine Änderung):**
[`agent_decision_service::build_operating_context_note()`](../../../classes/local/wizard/services/decision/agent_decision_service.php#L399)
hängt den Zielkurs (`agent_confirm_target_course`, Name + ID) an **jede** mutierende
`confirmation_request` — auch im ambienten Fall (Fallback `operating_contextid ≤ 0 →
ambient`, [Zeile 405-410](../../../classes/local/wizard/services/decision/agent_decision_service.php#L405-L410),
Kommentar nennt explizit „the case point 4 must catch"). Aufrufer:
[Zeile 1005-1013](../../../classes/local/wizard/services/decision/agent_decision_service.php#L1005-L1013)
(„Always name WHERE the write will be carried out").
*Restbeobachtung (nicht geändert):* Der Hinweis erscheint an der `confirmation_request`;
bei session-auto-confirmten Schreibvorgängen sieht der User die Bestätigung ggf. nicht.
Das ist der offene H6-Klärungspunkt — **nicht** eigenmächtig angefasst.

**H5 — Synchronizer (Prompt):**
[`synchronizer_prompt_builder::build_prompt()`](../../../classes/local/wizard/services/synchronizer_prompt_builder.php#L144)
— neue `ENTITY TYPE POLICY` direkt nach der `LINK POLICY`: Entitätstyp explizit benennen,
Buchungsaktivität/-option **nie** als Kurs darstellen, Eltern-Kurs getrennt halten
(„activity 'selflearning' (course: Booking)"), Link-Ziel pro Typ aus den Observations.

**H4 — Selektor (Prompt):**
[`orchestrator.php` ACTION-SPECIFIC GUIDANCE FOR ROUTING](../../../classes/local/wizard/orchestrator.php#L1699)
— neue Regel `CONTEXT-AWARE PLANNING`: keinen Such-/Auflösungsschritt für ein Ziel
planen, das bereits in `SYSTEM_RUNTIME.moodle_context` benannt ist; dann direkt das
Aktionsskill wählen. Auflösungsschritt nur bei einem **nicht** dem Kontext entsprechenden Ziel.

**H2 — `add_activity_skill` (Prompt/Schema) — mit bewusster Abweichung:**
- [`coursequery`-Beschreibung geschärft](../../../classes/local/wizard/course/skills/add_activity_skill.php#L169):
  ein in dieser oder einer früheren Nachricht genannter Kurs MUSS verbatim ins Feld;
  ein benannter Kurs ist **nie** „der aktuelle Kurs"; leer nur, wenn gar kein Kurs genannt
  wurde. Diese Beschreibung erreicht die Construction über das Voll-Schema des selektierten
  Skills — genau der Hebel, der in 347 versagte.
- **Abweichung vom ursprünglichen Wortlaut:** Statt `coursequery` in
  `input_fields_for_prompt` aufzunehmen (was es im Kompakt-Katalog als **`REQUIRED:`**
  rendert → Selektor würde laut Routing-Regel bei **jeder** Aktivität nach dem Kurs fragen
  = die Clarification-Schleife aus **Thread 324**), wird `coursequery` über
  [`get_example_input()`](../../../classes/local/wizard/course/skills/add_activity_skill.php#L196)
  als **`OPTIONAL:`** sichtbar gemacht. Gleiches Ziel (Feld nicht still verlieren), ohne
  die Regression. **→ Falls Georg die REQUIRED-Variante bevorzugt: bitte melden.**
- *Offen (bewusst nicht im Scope):* analoge Ziel-Felder in `add_quiz` /
  `question.generate_questions` / `update_activity` — gleiche Schärfung sinnvoll als
  Folge-PR.

**Nicht umgesetzt (zurückgestellt):** H1 (Constructor-Skill-Fixierung), H3
(Clarification statt ambient-Fallback), Framework-Retry für
`CONTRACT_PHASE_SKILL_NOT_ALLOWED`. Damit bleibt **348 (Skill-Drift → stiller Tod)
weiterhin offen** — H4 verringert nur die Auslösewahrscheinlichkeit (kein unnötiger
Vorbereitungsschritt), behebt den Drift selbst aber nicht.

---

## Analyse #3 — Aktuelle real_llm-Matrix-Failures (aufgedeckt durch den Smoke-Matrix-Fix)

**Datum:** 2026-06-15
**Quelle:** `all_skills_real_llm_test` (Skill-Matrix über alle registrierten Skills),
nach Ergänzung der fehlenden Smoke-Szenarien (Test-Fixture-Fix, siehe unten).
**Charakter:** Diese Failures sind **nicht** durch H2/H4/H5 verursacht (deterministische
Suite bleibt grün, Einzel-real_llm-Tests grün). Der Smoke-Matrix-Fix hat die zuvor
opaken Fixture-Lücken in echte Tests verwandelt und damit **vorbestehende Produkt­probleme
sichtbar gemacht**.

> **Methodischer Hinweis:** Die Matrix ist LLM-nichtdeterministisch (MiniMax-M2.7);
> die Failure-Menge schwankt run-zu-run. Die unten genannten **Muster** sind aber über
> mehrere Läufe stabil.

### Muster A — Discovery-Lücke: `course.*` / `core.diagnose_*` im Booking-Kontext unsichtbar

> ✅ **Behoben durch [H7](#h7--discovery-intent-bewusste-eskalation--kontext-als-prior--umgesetzt-2026-06-15)** (2026-06-15). Muster B (Fehl-Routing) wird durch dieselbe Korrektur entschärft. Verifiziert: 3/4 Szenarien grün, 4. erreicht das Skill.

Im ambienten **Booking-Modul-Kontext** findet die Discovery-/Selection-Phase die
course- und diagnose-Skills **nicht**, obwohl sie registriert **und** executable sind
(Gate `evaluate_skill` → `allow`). Der Planner antwortet stattdessen mit `clarification`
„kein passendes Skill":

| Skill | Planner-Antwort (real) |
|-------|------------------------|
| `core.diagnose_permissions` | „The requested user permission query does not have a matching skill in the current registry. Available skills address booking option management…" |
| `course.diagnose_grades` | „No skill is available to diagnose or analyze student grades. The available capabilities focus on booking status, course management…" |
| `course.add_quiz` | „the current system configuration is set up for managing booking activities, not for creating quizzes… you would typically need to use the Quiz module manually" |
| `course.add_activity` | „you would typically use a different skill or tool that can add resources/activities… this skill mismatch prevents me from proceeding" |

→ **Kernproblem:** Registriert + executable, aber **nicht discoverbar** im Booking-Kontext.
Die Discovery-Staging (Stage A = Kontext+Core-Familien; B = adjazente Domänen; C = global)
expandiert offenbar nicht auf die course/diagnose-Familien, wenn Stage A (Booking) bereits
als „ausreichend" gilt. Der Planner halluziniert daraufhin eine „gibt's nicht"-Antwort,
statt das vorhandene Skill zu nutzen. **Flowchart-Bezug:** `DISC_A_OK`/`DISC_B`/`DISC_C` +
`context_prior_builder` (Booking-Prior zu dominant).

### Muster B — Falsch-Routing: Update-Aktivität → Buchungsoption

| Skill | Beobachtung |
|-------|-------------|
| `course.update_activity` | Planner wählt **`mod_booking.update_option`** statt `course.update_activity` → „The system couldn't find a booking option matching 'Matrix Activity …'" |
| `course.update_quiz` | analog: als Buchungsoption fehlinterpretiert |

→ „Aktivität umbenennen" wird im Booking-Kontext als „Buchungsoption umbenennen" geroutet.
Gleiche Wurzel wie Muster A (Booking-Prior überlagert course-Domäne), hier mit aktiver
**Fehlauswahl** statt Verweigerung.

### Muster C — create/update **options**: Construction unzuverlässig ⚠️ (Georgs Schwerpunkt)

Dies ist der von Georg markierte, vermutlich „radikal neu zu denkende" Punkt. Zwei
verschiedene, beide gravierende Fehlmodi bei den Option-Mutations-Skills:

| Skill | Fehlmodus (real) | Klasse |
|-------|------------------|--------|
| `mod_booking.update_option` | `response_type=error` — *„no commands were generated to carry out the update"* | **kein Command emittiert** (= 348-Klasse) |
| `mod_booking.create_selflearning_option` | `error` — *„schema mismatch … unsupported property keys: 'contextid' and 'courseid' … not part of the allowed schema"* | **Constructor übergeneriert** schema-fremde Keys → harter Reject |

→ Die Option-Skills sind die **einzigen**, bei denen die Construction-Phase entweder
**gar kein** Command produziert **oder** ein Command mit **schema-fremden Zusatz-Keys**,
das hart abgewiesen wird. Beide Enden des Spektrums (zu wenig / zu viel) treten genau
hier auf — ein Indiz, dass die Option-Schemas (Größe, kanonische-Key-Strenge,
form-style-Parametrik) für den Constructor **am schwersten verlässlich befüllbar** sind.

**Hypothesen-Saat für den (separaten) Rethink — noch NICHT umsetzen:**
- Die kanonische-Key-Strenge **rejectet** statt zu **normalisieren** (schema-fremde
  `courseid`/`contextid` könnten verworfen statt hart abgelehnt werden — vgl.
  `unwrap_redundant_input_envelope`-Muster).
- „no commands generated" ist die 348-Klasse → spräche für **H1** (Skill/Command
  strukturell fixieren) **plus** einen Recovery-Pfad statt `error`.
- Evtl. braucht es für Options ein **schmaleres, intent-getriebenes** Eingabe-Contract
  statt des breiten form-style-Schemas (der eigentliche „radikal anders"-Ansatz).

### Muster D — Sprachdrift (Modell)

`course.diagnose_enrolment`, `mod_booking.diagnose_cancellation_issue` u. a. antworten
teils auf **Chinesisch** (MiniMax-M2.7-Eigenheit), z. T. mit `clarification`. Reines
Modellverhalten — relevant für Modellwahl/Sprach-Contract, nicht für die Skills selbst.

### Was Fix 1 (Smoke-Matrix) konkret geändert hat — Status

- ✅ **Coverage-Meta-Test grün:** alle registrierten Skills haben jetzt ein Szenario
  (`get_missing_registered_skill_scenarios()` → `[]`).
- ✅ **10 Szenarien ergänzt** ([llm_skill_matrix_scenario_provider.php](../../../tests/agent/llm_skill_matrix_scenario_provider.php))
  + 2 Seed-Setups (`prepare_course_activity_scenario`, `prepare_course_quiz_scenario`)
  in [abstract_llm_skill_matrix_testcase.php](../../../tests/agent/abstract_llm_skill_matrix_testcase.php).
  `oneclick.create_instance` bewusst mit `skip_reason` (braucht Provisioner-Config,
  wie `wizard.explain_docs`).
- ⚠️ **Ehrlicher Restbefund:** Die neuen course/diagnose-Szenarien laufen real_llm
  **nicht alle grün** — sie decken Muster A/B (Discovery/Routing) auf. Das ist **kein
  Test-Bug**, sondern ein reales Produktthema; bewusst **nicht** durch Test-Tricks
  „grün gemacht".

### Bezug zu den bestehenden Hypothesen

- Muster C (`update_option` „no command") = direkter weiterer Beleg für **H1**.
- Muster A/B (Discovery/Routing im Kontext) ist eine **neue Hypothese-Familie**
  (Discovery-Prior / Cross-Domain-Sichtbarkeit), die über die bisherigen H1–H6
  hinausgeht und in den Rethink gehört.

---

## Synthese (laufend)

Zwei wiederkehrende Grundmuster über alle analysierten Threads:

1. **„Bereits entschiedene/genannte Fakten gehen in der Construction verloren oder
   werden überschrieben"** — selektiertes Skill (348), genannter Kurs (347/324/345).
   → Antwort: bekannte Fakten **strukturell** fixieren (H1) bzw. verlässlich ins
   Feld zwingen (H2), nicht dem Modell-Ermessen überlassen.
2. **„Fehler/Unklarheit wird nicht sauber an den User zurückgespiegelt"** —
   stiller ambient-Fallback (347), stiller Turn-Tod (348), Availability-Flackern
   (336). → Antwort: bei Nichtauflösung/Contract-Bruch **immer** eine benannte
   Clarification bzw. humanisierte Meldung (H3, Schritt 6).
3. **„Im Booking-Kontext sind cross-domänen-Skills unsichtbar/fehlgeroutet"** (Analyse #3,
   Muster A/B) — registriert+executable, aber nicht discoverbar; Planner verweigert
   oder routet auf eine Booking-Option. → Neue Hypothese-Familie: Discovery-Prior /
   Cross-Domain-Sichtbarkeit. **(Rethink)**
4. **„Option-Mutationen sind am schwersten verlässlich zu konstruieren"** (Analyse #3,
   Muster C) — entweder **kein** Command (`update_option`) oder **schema-fremde Keys**
   (`create_selflearning_option`). → Georgs Schwerpunkt; Kandidat für ein
   schmaleres, intent-getriebenes Option-Input-Contract. **(Rethink, nach den Analysen)**

_(Wird mit weiteren Thread-Analysen fortgeschrieben.)_
