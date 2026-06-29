# Roadmap — `course.report` / `site.report`: aggregierte Reporting-Skill-Familie

Status: Konzept (2026-06-29). Keine Code-Änderungen. Backlog-Eintrag.

## Motivation

Die bestehende `course.diagnose_user_in_course`-Familie ist **einzel-user- und erklärungsorientiert**
("warum kann/hat *dieser* User X nicht?"). Es fehlt das Gegenstück, das Moodle-Admins/Trainer:innen im
Alltag am häufigsten brauchen: **aggregierte Reports über VIELE User** (und site-weit über viele Kurse) —
genau die Daten, die heute über die Moodle-Report-Seiten (Bewertungen, Aktivitätsabschluss, Teilnahme,
Logs, Kursabschluss) geholt werden, nur per natürlicher Sprache.

**Abgrenzung:** `diagnose_user_in_course` = ein User, Ursachenanalyse. `course.report` = N User,
Faktenüberblick (+ Drill-down auf den nativen Report). Read-only.

## Ziel

Eine read-only **Reporting-Familie**, die aggregierte Übersichten als (a) kompakte Observation/Antwort,
(b) Preview-Tabelle und (c) Deep-Link auf die native Moodle-Report-Seite liefert — für einen User, für
alle User eines Kurses, und (admin) site-weit.

## Typische Moodle-Admin-/Trainer-Reportfälle (zu unterstützen / zu priorisieren)

Kurs-Ebene (Trainer/Manager):
1. **Aktivitätsabschluss-Matrix** — alle eingeschriebenen User × Aktivitäten, wer hat was abgeschlossen,
   wer hängt hinterher (Core: `report_progress`, `core_completion`). „Wie weit sind alle im Kurs X?"
2. **Kursabschluss-Report** — Gesamt-Kursabschlussstatus aller User + Kriterienstatus (`report_completion`).
3. **Bewertungen — Grader-Report** — alle User × alle Bewertungsobjekte eines Kurses (`gradereport_grader`).
   „Zeig mir die Noten für Kurs X."
4. **Bewertungen — User-Report** — alle Bewertungsobjekte für *einen* User (`gradereport_user`).
   „Welche Noten hat Maria in Kurs X?" (user-spezifisch, aber alle Items)
5. **Teilnahme-/Aktivitätsreport** — pro Aktivität: wer hat angesehen/abgegeben, View-/Post-Zahlen über
   einen Zeitraum (`report_participation`). „Wer hat Quiz 3 noch nicht gemacht?"
6. **Letzter Zugriff / Inaktive User** — wann waren User zuletzt im Kurs aktiv; Liste der Inaktiven
   (über Enrolment-`lastaccess` / Logs). „Wer war seit 4 Wochen nicht mehr im Kurs?"
7. **Einschreibungs-Report** — wer ist eingeschrieben, Methode, Status (aktiv/suspendiert), Start/Ende,
   gruppiert nach Kohorte/Gruppe (`core_enrol`). „Welche Einschreibungen laufen diesen Monat aus?"
8. **Kurs-Log / Ereignis-Report** — wer hat wann was getan (`report_log`). „Was ist gestern in Kurs X passiert?"
9. **Gruppen-/Kohorten-Übersicht** — Mitgliedschaften, Gruppengröße, ungruppierte User.
10. **Aktivitätsspezifische Reports** — Quiz-Versuche/Statistik, Assignment-Abgaben/Bewertungsstatus,
    Forum-Beteiligung (modulspezifisch; mind. Quiz + Assign zuerst).

Site-Ebene (Admin):
11. **User-Übersicht** — aktive/suspendierte/nie-eingeloggte User, User ohne Rolle, Bulk-Sicht.
12. **Cross-Course-Aktivität eines Users** — alle Kurse + Fortschritt/Noten eines Users site-weit
    (ergänzt das bestehende enrolment-overview).
13. **Kurse-Übersicht** — Kurse mit Einschreibezahlen/Abschlussquoten, leere/inaktive Kurse.
14. **Site-Logs / Konfig-Änderungen / Live-Logs** (admin) — `report_log`, `report_configlog`.
15. **Anstehende Fälligkeiten / überfällige Abgaben** kursweit oder pro User.

## Architektur-Skizze (Vorschlag, nicht final)

- **Aspekt-basiert wie `diagnose_user_in_course`**: ein Skill `course.report` mit `aspect ∈
  {completion, grades, participation, enrolment, lastaccess, logs}` + `scope ∈ {user, all}`; optional
  `site.report` als eigener Skill für die admin-/site-weiten Fälle (11–14).
- **Core-APIs/Report-Tabellen wiederverwenden**, nicht nachbauen: `report_*`/`gradereport_*`-Tabellen,
  `core_completion`, `grade_report_*`. Der Skill aggregiert und fasst zusammen; die Quelle bleibt Core.
- **Read-only (R0)**, je Aspekt **capability-gated** am Operating-Kontext: z. B. `report/progress:view`,
  `gradereport/grader:view`, `report/participation:view`, `report/log:view`,
  `moodle/course:viewparticipants`; site-Aspekte verlangen die jeweilige Site-Capability.
- **Observation-Disziplin (wichtig — vgl. thread 565):** aggregierte Reports können riesig werden.
  Antwort/Observation = **kompakte Zusammenfassung** (z. B. „32/40 User abgeschlossen; 5 ohne Zugriff seit
  >14 Tagen: …"), harte Zeilen-Obergrenze, **Deep-Link auf die native Report-Seite** für das Vollbild;
  niemals die ganze Matrix in den Prompt.
- **Preview** als Daten (Tabelle) über den bestehenden Preview-Datenvertrag (`get_result_preview`).
- **Privacy:** aggregierte personenbezogene Daten → Anonymizer-Pfad beachten (Display-Gate), Cross-User
  nur mit Review-/Report-Capability, sonst self-scope.

## Offene Fragen / Entscheidungen

- O1: Ein generischer `course.report` (aspect/scope) **oder** mehrere spezialisierte Skills? (Tendenz:
  generisch mit Aspekt, konsistent zur Diagnose-Familie; modul-spezifische Reports ggf. separat.)
- O2: Wie weit aggregieren vs. nur auf die native Report-Seite verlinken? (Mindestens: Kennzahlen +
  Ausreißer in der Antwort, Vollreport per Link.)
- O3: Welche Aspekte zuerst (P1)? Vorschlag: **completion (1) + grades grader/user (3/4) +
  participation (5)** — das deckt die häufigsten Trainer-Fragen ab; logs/enrolment/site als P2.
- O4: Site-Aspekte (11–14) in eigenen `site.report`-Skill (admin-gated) trennen.
- O5: Export (CSV) als Folgeschritt? (Core-Reports können das; Agent könnte den Export-Link liefern.)

## Verwandt

- `course.diagnose_user_in_course` (Einzel-User-Diagnose, Aspekte access/enrolment/progress/grades) —
  das erklärungsorientierte Pendant; Reporting ist das aggregierte Gegenstück.
- `course.analyze_course_structure` (Struktur), `obsolet`/`todo`-Blueprint `diagnose_course_progress`.
- Deploy für jeden neuen Skill: Settings-Seed + Embeddings-Rebuild (Discovery-Anker).
