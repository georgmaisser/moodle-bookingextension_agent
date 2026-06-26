# Blueprint — `course.diagnose_progress`: Fortschritts-/Abschluss-Diagnose eines Users im Kurs

Status: Konzept (2026-06-26). Keine Code-Änderungen. Ergänzt die bestehende `diagnose_*`-Familie um
eine Completion-/Fortschritts-Diagnose, parallel zu `course.analyze_course_structure` (Struktur) und
`course.diagnose_grades` (Noten).

## Ziel

Eine read-only Diagnose, die für **einen User in einem Kurs** beantwortet:
- Welche Kursaktivitäten sind **abgeschlossen**, welche **nicht**?
- Welche **Abschlussbedingungen** gelten je Aktivität (ansehen / Note / Bestehensnote / aktivitäts­eigene
  Regeln / manuell)?
- **Warum** ist eine Aktivität nicht abgeschlossen (welche konkrete Regel ist unerfüllt)?
- Status der **Kurs-Abschlusskriterien** (course completion) und ob der Kurs insgesamt abgeschlossen ist.

Ausgabe: ein deterministischer Diagnosebericht (Checklisten-Rows + Observation), den das LLM nur
**erklärt** — analog zu `diagnose_grades` („FACTS COLLECTOR, kein Recompute").

## Einordnung in die bestehende Architektur

Die `diagnose_*`-Skills sind eine **reine Skill-Schicht** auf einer gemeinsamen Foundation, die Engine
wird nicht angefasst. Dieses Feature folgt 1:1 dem Muster von
`classes/local/wizard/course/skills/diagnose_grades_skill.php`:

- Basis `core_skill_base`/`course_skill_base`, `parent::__construct(true, skill_risk_class::R0)` → **R0,
  read-only**. R0 überspringt Preflight → **alle Guards leben in `execute()`**.
- `get_required_context_level() = CONTEXT_COURSE`.
- Foundation wiederverwenden:
  - `diagnostics\diagnostic_result_builder::row($status,$check,$finding,$url)` — Status `ok|fail|warn`,
    Glyphen `[OK]|[X]|[!]`; `error_result(...)`.
  - `diagnostics\diagnostic_link_builder` — server-seitige `moodle_url`-Deeplinks (nie vom LLM), nur
    wenn der Fragende sie öffnen darf (`if_capable`/`if_admin`).
  - `diagnostics\diagnostic_checklist_preview->render($rows,$title,$payload)` für `get_result_preview()`.
- Ergebnis-Contract identisch zu den anderen Diagnose-Skills: `status, detail, usermessage, resultid,
  diagnosis{courseid,targetuserid,checklist}, checklist_rows, checklist_title, observation_full`.
- Auto-Capability `<component>:skill_course_diagnose_progress` (namensabgeleitet, vom Evaluator erzwungen).
  Wie bei den anderen R0-Diagnose-Skills **keine** `get_required_native_capabilities()` (der zentrale
  Gate-2-Guard überspringt read-only Skills); die Rechteprüfung passiert in `execute()`.

Keine neue Engine-Schnittstelle, kein Executor-Touch, keine Preview-Registry (Daten-Contract bleibt).

## Skill-Spezifikation

**Name:** `course.diagnose_progress`  ·  **Risk:** R0  ·  **readonly:** true  ·  **Context:** CONTEXT_COURSE

**Inputs (Schema):**
- `userquery` (string, opt) — Name/E-Mail/„me"/„ich"; leer = aktueller User. Mehrdeutig → `core.search_users`.
- `userid` (int, opt) — hat Vorrang vor `userquery`.
- `coursequery` (string, opt) / `courseid` (int, opt) — leer = aktueller Kurs; nie raten.
- `activityquery` (string, opt) — Name einer einzelnen Aktivität (z.B. „Quiz 3") für Fokus statt Übersicht.

**Abgrenzung (Trigger-Wording, wie bei `diagnose_grades`):** NUR Completion/Fortschritt — **nicht**
Noten (→ `diagnose_grades`), **nicht** Sichtbarkeit/Verfügbarkeit (→ `diagnose_access`), nicht Einschreibung.

## Logik (execute) — Schritt für Schritt

1. **Kurs auflösen** (courseid → resolve_courseid(input) → Kurskontext-Fallback), `get_course()`,
   `$coursecontext = context_course::instance($courseid)`. Wie in `diagnose_grades::execute()`.
2. **User auflösen** (userid → resolve_userid(input,$userid) → self-Default), `\core_user::get_user`.
   `$isself = ($targetuserid === $userid)`.
3. **Gate (sensibel, cross-user):**
   - cross-user: `has_capability('report/progress:view', $coursecontext, $userid)` (Lehrenden-Archetyp
     hält sie; das ist die Activity-Completion-Report-Cap). Fehlt sie → `permission_denied`.
   - self: erlaubt, wenn der User den Kurs sehen darf (z.B. `moodle/course:view` bzw. eingeschrieben).
     Self-Diagnose legt nie etwas offen, das der User nicht ohnehin in seinem eigenen Fortschrittsbericht sieht.
4. **Completion-Info:** `$completion = new completion_info($course)`.
   - `!$completion->is_enabled()` → eine `warn`-Row „Completion tracking disabled for this course" +
     früher Return (es gibt nichts zu analysieren). Link: `completion_settings($courseid)`.
   - `!$completion->is_tracked_user($targetuserid)` → `warn`-Row „Completion is not tracked for this
     user's role" (Lehrende/Manager werden i.d.R. nicht getrackt) + Hinweis. Fortschritt weiter best-effort.
5. **Pro Aktivität** (Sichtbarkeit = Moodle-Engine, exakt wie `course_structure_service`:
   `get_fast_modinfo($course, $targetuserid)` → nur was dieser User sieht):
   - cms überspringen mit `$cm->completion == COMPLETION_TRACKING_NONE` (keine Abschlussverfolgung).
   - `optional activityquery`-Filter (fuzzy auf `$cm->get_name()`), sonst alle bis `MAX_ITEMS` (z.B. 30,
     Observation-Disziplin; bei Überlauf eine `warn`-Hinweiszeile + activityquery empfehlen).
   - **Gesamtstatus** via `cm_completion_details::get_overall_completion()` bzw.
     `$completion->get_data($cm, true, $targetuserid)->completionstate`. Mapping:
     | completionstate | Row-Status | Bedeutung |
     |---|---|---|
     | COMPLETION_COMPLETE / COMPLETE_PASS | `ok` | abgeschlossen (ggf. bestanden) |
     | COMPLETION_INCOMPLETE | `fail` | nicht abgeschlossen |
     | COMPLETION_COMPLETE_FAIL | `fail` | abgeschlossen, aber nicht bestanden (Note unter Grenze) |
   - **„Warum nicht abgeschlossen"** (Kern): für automatische, unvollständige Aktivitäten
     `(new cm_completion_details($completion, $cm, $targetuserid))->get_details()` →
     assoziatives Array `rulekey => {status, description}` über die geltenden Regeln (view / receivegrade
     / receivepassgrade + aktivitätseigene Regeln aus `activity_custom_completion`). In der `finding`
     die **unerfüllten** Regeln (status ≠ COMPLETE/_PASS) mit ihrer `description` auflisten
     (z.B. „Noch nicht angesehen", „Keine (Bestehens-)Note erreicht", „Mindestanzahl Versuche fehlt").
     Manuelle Completion (`COMPLETION_TRACKING_MANUAL`) → finding „Manuelles Abhaken durch den User nötig".
   - Row-`url`: `links->activity($cm->modname, $cm->id)`.
6. **Kurs-Abschlusskriterien** (course completion):
   - `$completion->get_criteria()` + `$completion->get_completions($targetuserid)`; je Kriterium
     `is_complete()` → `ok|fail`-Row mit Titel (Aktivität abschließen / Note erreichen / Datum /
     Dauer der Einschreibung / Rollenbestätigung / manuelle Selbstbestätigung / andere Kurse).
   - Gesamt: `$completion->is_course_complete($targetuserid)` → abschließende `ok|fail`-Row
     „Course marked complete / not yet complete". Link: `course_completion_report($courseid)`.
7. **Zusammenfassung:** Kopf­zeile mit Quote „X von Y verfolgten Aktivitäten abgeschlossen; Kurs:
   abgeschlossen/offen". Observation schließt mit dem `diagnose_grades`-Stil-Hinweis: „Erkläre die
   Situation nur aus diesen Fakten; berechne/rate nichts selbst."
8. `build_result(...)` + `get_result_preview()` exakt wie `diagnose_grades`.

## Moodle-Completion-API (verifiziert in dieser Codebasis)

- `lib/completionlib.php` → `completion_info`: `is_enabled($cm=null)`, `is_tracked_user($userid)` (über
  `cm_completion_details` erreichbar), `get_data($cm,$wholecourse,$userid)`, `get_criteria()`,
  `get_completions($userid)`, `is_course_complete($userid)`.
- `completion/classes/cm_completion_details.php` → `__construct(completion_info, cm_info, userid)`,
  `get_details()` (Per-Regel `{status, description}`, leer wenn nicht automatisch), `get_overall_completion()`,
  `is_automatic()`, `is_manual()`, `is_tracked_user()`.
- `completion/classes/activity_custom_completion.php` → aktivitätseigene Regeln + Beschreibungen
  (intern via `get_details()` schon aggregiert — kein Direktzugriff nötig).
- Konstanten: `COMPLETION_TRACKING_NONE/MANUAL/AUTOMATIC`, `COMPLETION_INCOMPLETE/COMPLETE/COMPLETE_PASS/COMPLETE_FAIL`.
- `cm_completion_details` lädt mit `$wholecourse=true` die Completion-Daten des Kurses in einem Query in
  den Cache → Schleife über viele cms bleibt günstig.

## Foundation-Ergänzungen (minimal)

`diagnostic_link_builder` um drei Deeplinks erweitern (Muster wie `grade_setup`/`user_grade_report`):
- `activity_completion_report(courseid)` → `/report/progress/index.php?course=…`
- `course_completion_report(courseid)` → `/report/completion/index.php?course=…`
- `completion_settings(courseid)` → `/course/completion.php?id=…`

Row-Status `ok|fail|warn` reichen; **keine** neuen Glyphen, **keine** Builder-Signatur-Änderung. Kein
weiterer Foundation-Eingriff.

## Edge Cases / Fallstricke

- **Completion kursweit aus** → warn + früher Return (Schritt 4); kein irreführender „alles offen"-Bericht.
- **User nicht getrackt** (Lehrende/Manager) → warn; Completion-Daten existieren für diese Rolle oft nicht.
- **Aktivität ohne Abschlussverfolgung** (`COMPLETION_TRACKING_NONE`) → nicht listen (kein Rauschen).
- **Sichtbarkeit/Verfügbarkeit:** `get_fast_modinfo($course,$targetuserid)` filtert bereits, was der User
  sieht. Wenn eine Aktivität wegen **Zugriffsbeschränkung** (availability) für den User gar nicht
  erreichbar ist, ist „nicht abgeschlossen" oft eine *Access*-Frage → in der `finding` auf
  `course.diagnose_access` verweisen (Cross-Skill-Hinweis, kein Recompute der Restriction-Logik hier).
- **COMPLETE_FAIL** (bestanden-Regel nicht erreicht) sauber von INCOMPLETE trennen — das ist häufig die
  eigentliche „warum"-Antwort und überlappt mit Noten → Hinweis auf `diagnose_grades` für Notendetails.
- **Große Kurse** → `MAX_ITEMS`-Cap + `activityquery`-Fokus; nie die ganze Liste ungebremst in die Observation.
- **User nicht eingeschrieben** → leere/teilweise Daten; klare Row statt stiller Lücke.
- **Cross-user ohne `report/progress:view`** → `permission_denied` (nie fremden Fortschritt leaken).

## Tests (PHPUnit, Muster `diagnose_grades_skill_test`)

- Metadaten: name `course.diagnose_progress`, R0, readonly, CONTEXT_COURSE.
- Aktivität mit `completionview`, Student hat **nicht** angesehen → `fail`-Row + unerfüllte Regel „view";
  nach `set_module_viewed` → `ok`.
- Automatische Completion mit Bestehensnote: Note unter Grenze → `COMPLETE_FAIL`/`fail` + Hinweis Pass.
- Manuelle Completion → finding „manuelles Abhaken".
- Course-Completion-Kriterium (Aktivität X) erfüllt vs. unerfüllt; `is_course_complete` ok/fail.
- Completion kursweit aus → genau eine `warn`-Row + früher Return.
- `activityquery`-Fokus filtert auf eine Aktivität; `MAX_ITEMS`-Cap erzeugt Hinweiszeile.
- **Gate:** Student darf Fortschritt eines Peers NICHT sehen (`permission_denied`); self immer erlaubt;
  Lehrender mit `report/progress:view` sieht cross-user.
- Preview: `get_result_preview` liefert `diagnostic_checklist_preview`-Block (type/html/payload).

Scharf assertieren (exakte Row-Status/Counts), keine „oder"-Akzeptanz — konsistent mit dem jüngsten
Test-Härtungs-Audit.

## Discovery / Prompts

`get_message_triggers()` + `get_contextual_prompt_packs()` mit Vokabular (DE+EN): „fortschritt",
„abgeschlossen/nicht abgeschlossen", „completion", „warum nicht abgeschlossen", „offene aktivitäten",
„progress", „completed/incomplete activities", „course completion". Beispiele wie
„Warum hat Maria den Kurs noch nicht abgeschlossen?", „Welche Aktivitäten fehlen Tom noch?",
„Why is this activity not marked complete?". Abgrenzung gegen grades/access im `description`-Text.

## Rollout (greift in die gerade konsolidierten Trigger ein)

- Default-off / über `aiskillenableall` bzw. per-Skill aktivierbar (wie die übrige Diagnose-Familie).
- **Embeddings:** der neue Skill muss in den Skill-Katalog embedded werden. Mit der konsolidierten
  Trigger-Logik (Orphan/Drift-Erkennung) flippt der Katalog nach Deploy auf „stale" und der
  Upgrade-Reconcile (`db/upgrade.php`-Savepoint) schedult den Rebuild automatisch → **version.php bumpen**,
  `upgrade.php` fahren, Embeddings-Rebuild laufen lassen.
- `diagnosis_result_summary_contributor` prüfen: liefert der generische Checklist-Pfad die Summary schon
  mit, oder braucht der neue Skill einen Eintrag? (Vermutlich generisch über `checklist_rows` — verifizieren.)

## Offene Entscheidungen (vor Implementierung mit Georg klären)

- **O1 — Cross-user-Cap:** `report/progress:view` (Activity-Completion-Report) als Gate, oder strenger
  `report/completion:view` (Kurs-Completion-Report) je nach Sektion? Vorschlag: `report/progress:view`
  fürs Aktivitäts-Listing, `report/completion:view` zusätzlich nur für den Kriterien-Block.
- **O2 — Scope:** ein User pro Aufruf (wie diagnose_grades) — kein Klassen-Bulk in v1 (Observation-Größe).
- **O3 — Flowchart:** Soll `AGENT_IMPLEMENTATION_FLOWCHART.mmd` einen neuen Diagnose-Knoten bekommen?
  Pro Flowchart-Policy erst klären, nicht eigenständig angleichen.
- **O4 — Name:** `course.diagnose_progress` vs. `course.diagnose_completion`. Vorschlag: `…_progress`
  (User-Sprache „Fortschritt"), Completion ist das Mittel.
