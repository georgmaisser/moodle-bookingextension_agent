# Design-Notiz: Quiz-Skills (`course.add_quiz` + `course.update_quiz`)

*Stand: 2026-06-14 · P1 (add_quiz) + P2 (update_quiz) UMGESETZT & verifiziert (13 Tests grün), committet · v2 (Fragen entfernen/umsortieren/Regrade) offen · baut auf add_activity/update_activity + generate_questions auf*

> **Umsetzungsstand 2026-06-14:** `quiz_question_service` (3 Quellen + generation-first + `ensure_quiz_feedback`),
> `course.add_quiz` (P1, leeres Quiz erlaubt, Quellen-Rückfrage mit Kategorien), `course.update_quiz` (P2,
> Settings-Edit via Update-Modus + Fragen hinzufügen). Commits `fda1c29` (add_quiz) + `b052087` (update_quiz).
> Generierungs-Pfad nur deterministisch getestet (echter LLM-Lauf beim Deploy). Deploy (Upgrade 2026061308 +
> Settings + Embeddings-Rebuild) noch offen.

## Entscheidung (Kontext)
Quiz gehört **nicht** in die generischen `course.add_activity`/`course.update_activity` (siehe deren Blueprint §7
„harte Grenze"): die generische Hülle erzeugt nur ein **leeres** Quiz, der Wert steckt aber im Fragen-Workflow
(Fragen referenzieren, Bewertung). Deshalb **dedizierte Skills**, die die bestehende Foundation und die
`generate_questions`-Services wiederverwenden — **ohne Engine-Touch** (alle nötigen Core-APIs vorhanden).

## Wiederverwendung (alles Skill-Schicht)
- **Hülle:** `module_form_contract` (mform validieren + Defaults) + `activity_creation_service::create()/update()`
  — der Quiz-Skill ruft sie direkt mit `modname='quiz'` (umgeht bewusst die generische Whitelist).
- **Fragen erzeugen:** `services/questions/question_generation_service` + `question_import_service` +
  `question_bank_target_resolver` (genau die, die `generate_questions` nutzt — keine Duplikation).
- **Fragen ins Quiz:** `quiz_add_quiz_question($questionid,$quiz,$page,$maxmark)` (mod/quiz/locallib.php) bzw.
  `\mod_quiz\structure::add_random_questions()`, `remove_slot()`, `get_slots()`. Grade-Sync nach Änderungen:
  exakte API (quiz grade calculator) bei Umsetzung final verifizieren.
- **Preview:** `activity_preview_renderer` (Quiz-Karte) + ggf. `question_preview_renderer` für die Fragen.

## Geteilter Baustein: `quiz_question_service` (neu, Skill-Schicht)
Ein Helfer „bringe Fragen in ein Quiz", von beiden Skills genutzt, mit drei Quellen:
1. **Generieren** aus `content`/PDF → über `question_generation_service` in die Kurs-Bank → ins Quiz referenzieren.
2. **Konkrete Bank-Fragen** per `questionids[]` referenzieren.
3. **Zufallsfragen**: N aus einer Kategorie (`structure::add_random_questions`).
Danach Grade-Sync. So ist `generate_questions` weiterhin „Fragen → Bank", der Quiz-Skill „Quiz + Fragen".

## Skill 1 — `course.add_quiz` (R2, create + populate)
Analog `add_activity`: Preflight (Kurs/Section/Felder + Cross-Context), Confirm, execute.
- **Input:** `name`, `intro`, `section`, `coursequery`/`courseid` (wie add_activity) **plus** Fragen-Quelle:
  `content`(+`count`,`qtypes`,`difficulty`,`outputlang`) für Generieren · `questionids[]` · `category`+`randomcount`.
- **Ablauf:** Quiz-Hülle anlegen (`activity_creation_service::create`) → `quiz_question_service` füllt Fragen →
  Grade-Sync. **Ein** R2-Confirm über alles; skill-interne Retry-Schleife wie generate_questions.
- **Gate 2:** `mod/quiz:addinstance` + `moodle/course:manageactivities`; Generieren zusätzlich `moodle/question:add`.
- **Preview:** Quiz-Karte + Anzahl/Liste der eingefügten Fragen. **Observation** nennt Quiz-URL + Fragenzahl.

## Skill 2 — `course.update_quiz` (R2, edit + Fragen verwalten)
Analog `update_activity`, plus quiz-spezifisch:
- **Einfache Settings** (name/intro/visibility): über `module_form_contract` Update-Modus (wie update_activity).
- **Fragen hinzufügen** zu bestehendem Quiz: derselbe `quiz_question_service` (generate/ids/random).
- **v2 (zurückgestellt):** Fragen entfernen/umsortieren (`remove_slot`/reorder), Neubewertung, fortgeschrittene
  Quiz-Settings, Review-Optionen.
- Ziel-Quiz-Auflösung wie update_activity (cmid › Name-Fuzzy › ambienter Modul-Kontext), beschränkt auf `quiz`.

## Abgrenzung der Trigger (gegen Selector-Verwirrung)
- `generate_questions` → „Fragen erstellen / in die Bank" (kein Quiz).
- `course.add_quiz` → „Quiz/Test erstellen", „mach ein Quiz aus diesem PDF".
- `course.update_quiz` → „Fragen zum Quiz X hinzufügen", „Quiz bearbeiten/umbenennen/ausblenden".
- `course.add_activity` schließt `quiz` weiterhin aus (Whitelist). Pro neuem Skill ein Benchmark-Szenario.

## Offene Scope-Entscheidungen (für dich, vor der Umsetzung)
1. **Fragen-Quellen in v1:** alle drei (generieren · konkrete ids · Zufall aus Kategorie) — oder zuerst nur
   „generieren aus content" (deckt „Quiz aus PDF")? *Vorschlag: generieren + Zufall-aus-Kategorie in v1; konkrete ids danach.*
2. **Bewertung:** automatisch (Summe der Fragen-Punkte) — oder `grademax`/Bestehensgrenze exponieren? *Vorschlag: auto/minimal v1.*
3. **Ein Skill oder zwei?** `add_quiz` (create+populate) und `update_quiz` (edit+add) getrennt — analog add/update_activity?
   *Vorschlag: zwei Skills, gemeinsamer `quiz_question_service`.*
4. **update_quiz v1-Umfang:** Settings + Fragen-Hinzufügen jetzt; Entfernen/Umsortieren/Regrade als v2? *Vorschlag: ja.*
5. **Komposition „Quiz aus PDF":** add_quiz erzeugt selbst (Service-Reuse, ein Confirm) — statt Planner-Verkettung
   generate_questions→add_quiz (fragiler wegen Artefakt-Bindung). *Vorschlag: add_quiz erzeugt selbst.*

## Phasenplan
- **P1:** `quiz_question_service` (generate + random) + `course.add_quiz` (Hülle + Fragen) + Cap/lang/Version + Tests + Embeddings-Rebuild.
- **P2:** `course.update_quiz` (Settings via Update-Modus + Fragen hinzufügen) + Tests.
- **P3 (v2):** Fragen entfernen/umsortieren/Regrade, fortgeschrittene Quiz-Settings.

## Spike-Befund 2026-06-14 (Quiz-Hülle headless)
Verifiziert: die Quiz-mform **baut** headless; `quiz_process_options()` + der Quiz-Insert laufen über
`module_form_contract::build_prepared_moduleinfo('quiz', …)` durch. **Zwei kleine Quiz-Spezifika nötig:**
1. **Overall-Feedback kuratieren:** `quiz_after_add_or_update` braucht `$moduleinfo->feedbacktext`
   (`[['text'=>'','format'=>FORMAT_HTML,'itemid'=>0]]`) + `feedbackboundaries=[]`, sonst „feedbacktextformat cannot be null".
2. **Access-Subplugin-Validierung ignorieren:** die generische `validate('quiz')` liefert `seb_*`-Fehler
   (quizaccess_seb) — kein Create-Blocker; die Quiz-Hülle überspringt die generische Feld-Validierung und
   prüft nur `name` + die kuratierten Defaults.
→ Ansatz: Hülle = generischer Build + dünner Quiz-Patch (Feedback-Defaults) in einem `quiz_creation`-Helfer
bzw. im Skill; **keine** Änderung an `module_form_contract` nötig.

## Verfeinerungen (Georg 2026-06-14)
- **Quiz-zuerst-Pfad:** `add_quiz` darf ein **leeres** Quiz anlegen (Fragen später) — keine Frage-Pflicht.
- **Fragen-Quellen-Rückfrage bei unklarer Quelle:** wenn Fragen gewünscht, aber keine Quelle angegeben →
  needs_clarification mit drei Optionen: (a) **neu aus Dokument/PDF** erzeugen, (b) **selbst ausdenken**
  (Thema), (c) **bestehende Fragen aus einer Kategorie** — inkl. **Liste der verfügbaren Kategorien**
  (über `question_bank_target_resolver::list_writable_targets`). Geteilt von add_quiz + update_quiz.
- **Schlau bei unvollständigen Anfragen:** generell Clarifications statt nur Happy-Path (Quelle, Kategorie-Wahl,
  Ziel-Quiz bei update). „Füge Fragen hinzu" → obige Quellen-Rückfrage.

## Risiken
- Quiz-mform headless ist größer/feldverflochtener als die Whitelist-Module → wie bei add_activity per Test pinnen;
  notfalls die kritischen Quiz-Felder kuratiert vorbelegen.
- Grade-Sync-API in Moodle 5 (Klassen statt Funktion) bei Umsetzung verifizieren.
- Datenmenge/Confirm: bei vielen generierten Fragen Observation/Preview kompakt halten (Anzahl + Auszug).
- Berechtigungen sauber trennen (addinstance/manageactivities/question:add).
