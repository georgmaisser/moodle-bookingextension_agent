# Design-Notiz: Generischer `course.update_activity`-Skill (Activity bearbeiten)

*Stand: 2026-06-14 · Kurz-Notiz (kein volles Blueprint) · Analog zu `course.add_activity`*

## Warum nur eine Notiz
Die Machbarkeit „komplexe Activity-Mutation ohne Engine-Touch" ist durch `course.add_activity` bereits
bewiesen (Headless-mform als Validator/Default-Quelle, generische Clarification/Preview/Confirm-Kanäle).
Edit nutzt dieselben Kanäle + dieselbe Foundation; neu sind nur die Punkte unten.

## Core-APIs (verifiziert 2026-06-14)
- `update_moduleinfo($cm, $moduleinfo, $course)` — Update-Pendant zu `add_moduleinfo`.
- `get_moduleinfo_data($cm, $course)` → `[$cm,$context,$module,$data,$cw]` — die **bestehenden** Instanz-Daten
  als Form-Daten (Edit-Pendant zu `prepare_new_moduleinfo_data`).
- Sichtbarkeit über `$moduleinfo->visible` (von `update_moduleinfo` verarbeitet).

## Skill
- Name **`course.update_activity`**, Cap `bookingextension/agent:skill_course_update_activity`, **R2** (Confirm).
- `get_required_context_level()` = `CONTEXT_COURSE`, `supports_target_context()` (coursequery) wie add_activity.
- Da R2 → **Preflight läuft** (anders als die R0-Diagnose-Skills): Ziel-/Feld-Auflösung + Clarification im Preflight.

## Neue Design-Punkte
1. **Ziel-Aktivität auflösen** (Preflight): `cmid` direkt > `activityquery` per Name (Fuzzy-Match über
   `get_fast_modinfo`-cms, Mehrdeutigkeit → Clarification mit `options[]`) > ambienter Modul-Kontext.
   Nur Whitelist-Module (gleiche Liste wie add_activity) — andere → Clarification „nicht unterstützt".
2. **Partial-Update-Vertrag**: nur angegebene Felder ändern, Rest aus `get_moduleinfo_data` behalten.
   Schema: `cmid?`, `activityquery?`, `name?`, `intro?`, `visible?` (zeigen/verstecken), `settings{}` (modulspez.),
   plus `coursequery?`/`courseid?` für Cross-Context. Leeres Feld = unverändert.
3. **Before/After**: Preflight berechnet die geänderten Felder (alt → neu) und legt sie in `prepared` ab;
   Preview + Observation zeigen den Diff; R2 ⇒ Synchronizer hängt `affected_scope_summary` an.
4. **Gate 2** (selbst geprüft im Preflight): `moodle/course:manageactivities` am Kurs-Kontext (Edit braucht
   manageactivities, NICHT addinstance).

## Wiederverwendung (alles Skill-Schicht, kein Engine-Touch)
- `module_form_contract`: neue Update-Modus-Methoden `validate_update()` / `build_prepared_update_moduleinfo()`,
  die `get_moduleinfo_data` als Scaffold nehmen und die bestehenden Helfer (fresh-page-Globals, quickform via
  Reflection, exportValues-Harvest, element_defaults, apply_inputs) teilen.
- `activity_creation_service`: neue `update($cm, $moduleinfo, $course)` (transaktional, Rollback).
- `activity_preview_renderer`: 1:1 (zeigt die geänderte Activity); Skill-Gerüst analog add_activity.

## v1-Scope (Georg, 2026-06-14)
- **In v1:** `name`, `intro`, Sichtbarkeit (zeigen/verstecken), modulspezifische `settings`.
- **v2 (zurückgestellt):** Section verschieben (move = eigene Core-Operation), Editor-/Datei-schwere Felder.

## Tests (analog add_activity)
Metadata/R2; Aktivitäts-Resolve (cmid / Name eindeutig+mehrdeutig); Partial-Update (nur name; nur visible;
settings); Preflight-read-only-Beweis (kein DB-Write vor execute); echte Anwendung via `update_moduleinfo` +
Verifikation; Gate-2 (Student ohne manageactivities → Clarification).
