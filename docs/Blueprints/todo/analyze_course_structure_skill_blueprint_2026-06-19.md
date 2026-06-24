# Course-Struktur-Analyse — `course.analyze_course_structure` (Entwurf)

Status: **Entwurf / Planung** (keine Umsetzung)
Datum: 2026-06-19
Risk-Klasse: **R0 (read-only)** · Familie: `course` · Modelle: `course.diagnose_access`, `course.search_courses`, `course.add_activity`

---

## 1. Auftrag und Anspruch

Der Agent soll **den aktuellen oder einen beliebigen Kurs analysieren** und strukturiert zurückgeben:

- **Sections** (Abschnitte): Nummer, Name, Zusammenfassung, sichtbar/verborgen, Verfügbarkeits-Restriktionen.
- **Activities** (inkl. Buchungsinstanzen): Name, Typ (`modname`), Beschreibung/Intro, Link, sichtbar/verborgen, Gruppenmodus, Verfügbarkeits-Restriktionen.

Das ist die **Vorbedingung**, damit der Agent später *mit* der Struktur interagieren kann (z. B. „erstelle eine Buchungsoption in Sektion X" oder „hinter der Überschrift Y"). **In diesem Blueprint geht es ausschließlich um das Analysieren** — Schreib-Skills sind ein Folgeschritt (siehe §8, §11).

### Die eiserne Regel
> **Nirgendwo an den normalen Capability-Abfragen vorbeiarbeiten. Ein User darf über den Agenten nur die Dinge sehen, die er auch sonst sähe.**

Konkret heißt das (Begründung in §4): Der Agent handelt **immer als der reale `$userid`**, und die Sichtbarkeit wird **ausschließlich** über Moodles eigene Engine (`get_fast_modinfo($course, $userid)` + `uservisible`) ermittelt — wir bauen **keine** eigene `has_capability`-Logik nach, an der man versehentlich vorbeilaufen könnte.

---

## 2. Einordnung in die Skill-Architektur (verifiziert 2026-06-19)

- **Ort / Auto-Discovery:** Neue Datei `bookingextension/agent/classes/local/wizard/course/skills/analyze_course_structure_skill.php`. `skill_discovery::get_skill_instances()` scannt `<plugin>/classes/local/wizard/*/skills/*.php` und instanziiert jede nicht-abstrakte Klasse mit **parameterlosem Konstruktor** (`skill_discovery.php:44-77`). Keine manuelle Registry-Pflege.
- **Basisklasse:** `extends core_skill_base` (`core/skills/core_skill_base.php`) → erbt `resolve_courseid()`, `can_access_user()`, Observation-Formatter etc.
- **R0-Deklaration:** `parent::__construct(true, skill_risk_class::R0)` (`dto/skill_risk_class.php:30`). Der Contract-Validator erzwingt die Kopplung R0 ⇔ `is_read_only()===true` (`skill_contract_validator.php:186-192`).
- **Kein Preflight nötig:** Für R0 überspringt die Engine den Preflight; `core_skill_base::preflight()` ist ein reiner Pass-Through (`core_skill_base.php:281-295`). **Alle Guards leben in `execute()`** — wie bei `diagnose_access_skill`.
- **Gates:**
  - **Gate 1 (Governance-Capability):** automatisch abgeleitet als `bookingextension/agent:skill_course_analyze_course_structure` (`skill_contract_validator.php:110-124`). Muss in `db/access.php` einem Rollen-Bucket zugeordnet werden, sonst *deny* (`skill_executability_evaluator.php:182-186`). → siehe §10.
  - **Gate 2 (PRO/Lizenz):** **R0 umgeht diesen Gate** (`skill_executability_evaluator.php:85-93`). Die Analyse läuft also **ohne PRO-Lizenz** für jeden berechtigten User — bewusst so, weil read-only.
  - **Native Moodle-Caps (`get_required_native_capabilities()`):** für R0 **leer**. Die echte Zugriffskontrolle passiert über `get_fast_modinfo($course,$userid)` (§4), nicht über deklarierte Schreib-Caps.

---

## 3. Kontext-Auflösung: aktueller ODER beliebiger Kurs

Es gibt zwei etablierte Wege; für „aktueller **oder** beliebiger Kurs" ist **Path A (engine-resolved)** der sauberere Wiederverwendungspfad, weil er sichtbarkeits-bewusst auflöst und Mehrdeutigkeiten als Clarification zurückgibt.

**Path A — Cross-Context, von der Engine aufgelöst** (wie `course.add_activity`):

```php
public function supports_target_context(): bool { return true; }
public function get_target_context_level(): int { return CONTEXT_COURSE; }
public function get_target_selector(array $input): ?target_selector {
    $courseid = (int)($input['courseid'] ?? 0);
    $coursequery = trim((string)($input['coursequery'] ?? ''));
    if ($courseid <= 0 && $coursequery === '') {
        return null;                       // leer ⇒ AMBIENTER (aktueller) Kurs
    }
    return target_selector::for_course($courseid > 0 ? $courseid : null,
                                       $coursequery !== '' ? $coursequery : null);
}
```

Verhalten (verifiziert):
- **Leerer Selector ⇒ aktueller Kurs** (Ambient-Kontext aus `$contextid`). (`skill_operating_context_resolver.php:70-83`)
- **Expliziter Selector ⇒ Registry-Auflösung** (`operating_context_target_registry.php:90-135`): `courseid` → `context_course::instance(...)`; Freitext → `core_course_category::search_courses(['search'=>$query])` — **sichtbarkeits-bewusst, als handelnder User**. 1 Treffer → resolved, >1 → `ambiguous(candidates)`, 0 → `not_found`.
- **Keine stille Ambient-Fallback** bei benanntem, aber unauflösbarem Kurs: wirft `context_target_unresolved_exception` → Clarification `CONTEXT_TARGET_UNRESOLVED` (`context_resolver.php:108-110`, `preflight_pipeline.php:184-189`). Das ist der **thread-347-Guardrail** (Agent darf keinen falschen/ambienten Kurs erfinden).
- Die aufgelöste **operating contextid** wird in `execute($input, $operatingcontextid, $userid)` durchgereicht.

**Anker-Schutz im Prompt:** `coursequery` als `anchor_field` im `prompt_meta` deklarieren (überlebt Prompt-Kompaktierung); Schema-Beschreibung muss klarstellen, dass ein benannter Kurs **nie** automatisch „der aktuelle Kurs" ist (analog `add_activity_skill.php:169-178,188`).

> Alternative Path B (skill-intern, `resolve_courseid()`-Leiter `id > coursequery > ambient`, `core_skill_base.php:191-207`) ist einfacher, aber ohne Ambiguitäts-Clarification. Für die „beliebiger Kurs"-Anforderung → **Path A bevorzugen**.

---

## 4. Capability- & Sichtbarkeits-Sicherheit (der Kern)

Das ist der wichtigste Teil dieses Blueprints. Die Sicherheit ergibt sich **nicht** aus eigenen Checks, sondern aus der konsequenten Nutzung von Moodles Sichtbarkeits-Engine **mit dem realen User**.

1. **Der Agent handelt immer als der reale `$userid`** — nie als Admin/System. `$userid` wird durch jede Schicht gefädelt (`executor.php:90,228` → `$skill->execute($input, $ctx, $userid)`); es gibt **kein** `setuser`/Su. (verifiziert)

2. **Gesamtzugang zum Kurs zuerst absichern:**
   - `require_login($course)` (`lib/moodlelib.php:2264`) **oder** für eine boolesche Prüfung ohne Redirect `can_access_course($course, $user)` (`lib/accesslib.php:1991`), bevor irgendetwas aufgebaut wird.
   - Für den Kurs-Datensatz selbst zusätzlich `\core_course_category::can_view_course_info($courserecord)` (so filtert schon `core_skill_base::list_course_candidates_for_preview()`, `core_skill_base.php:589-591`); `SITEID` nie listen.

3. **Sichtbarkeit ausschließlich über modinfo des realen Users:**
   `get_fast_modinfo($course, $userid)` (`lib/modinfolib.php:58`). Der `$userid` ist der **Sichtbarkeits-Scope** — `uservisible`/`available` werden für genau diesen User berechnet. Dann:
   - `$section->uservisible` / `$cm->uservisible` als **einziger Filter**.
   - **Kein** eigenes `has_capability` für die Sichtbarkeit — `uservisible` faltet bereits `viewhiddensections`, `viewhiddenactivities`, `ignoreavailabilityrestrictions`, alle Availability-Conditions **und** Gruppen-Restriktionen (`mod/x:view`) ein. Eigene Logik würde nur Divergenz-Risiko schaffen.

4. **Drei Zustände sauber unterscheiden** (entscheidend für „nur sehen, was man sieht"):
   | Zustand | Bedeutung | Verhalten |
   |---|---|---|
   | `uservisible === false` | User darf das Element **gar nicht** sehen | **komplett weglassen** |
   | `uservisible === true && visible === false` | Element ist *für Studierende verborgen*, aber **dieser** User (z. B. Trainer mit `viewhiddensections/-activities`) darf es sehen | aufnehmen, **Flag `hidden: true`** |
   | `uservisible === true && available === false` | zugänglich, aber **mit Restriktion** (User hat `ignoreavailabilityrestrictions`) | aufnehmen, **`restrictinfo`** rendern |

   Das ist genau richtig im Sinne der Regel: Ein Trainer *sieht* verborgene/eingeschränkte Inhalte (weil er es darf) und bekommt sie mit korrektem Flag; ein Studierender bekommt sie gar nicht (`uservisible=false` → weggelassen).

5. **Links nur capability-gated** ausgeben — `diagnostic_link_builder::if_capable($url, $cap, $context, $userid)` / `if_admin()` (`diagnostics/diagnostic_link_builder.php:191-204`), damit die Observation nie einen 403-Link trägt.

6. **Privacy/Anonymizer:** Kurs-/Section-/Activity-**Namen** sind i. d. R. keine personenbezogenen Daten. Wo aber Personen auftauchen (z. B. Trainer einer Buchungsoption, Gruppennamen), werden Personenfelder von der Engine über `privacy_anonymizer` tokenisiert (an das LLM) und nur für den berechtigten Betrachter de-tokenisiert (fail-closed Display-Gate, `privacy_anonymizer.php:285-344`). → In v1 möglichst **keine** Personenfelder in den Output aufnehmen (siehe §6.3).

---

## 5. Core-APIs (verifiziert 2026-06-19, Moodle 5.1)

> Hinweis Moodle 5.1: `course_modinfo`/`cm_info`/`section_info` liegen jetzt unter `course/classes/` (Namespace `core_course`), sind aber per `class_alias` global verfügbar. Globale Typhints (`\cm_info`) funktionieren weiter.

| Symbol / Property | Bedeutung | Quelle |
|---|---|---|
| `get_fast_modinfo($courseorid, $userid)` | modinfo, Sichtbarkeit auf `$userid` gescoped. **Realen Ziel-User übergeben.** | `lib/modinfolib.php:58` |
| `course_modinfo::get_section_info_all()` | alle Sections (`section_info[]`) | `course/classes/modinfo.php:369` |
| `section_info->uservisible` | darf dieser User die Section sehen (Caps+Availability) | `section_info.php:440` |
| `section_info->visible` | Eye-Icon-Status (für Studierende verborgen) | `section_info.php:88` |
| `section_info->available` / `->availableinfo` | Restriktion erfüllt? / Restriktionstext (HTML) | `section_info.php:345 / 409` |
| `section_info->summary` / `->summaryformat` | Section-Beschreibung | `section_info.php:94 / 100` |
| `get_section_name($course, $section)` | aufgelöster Section-Name inkl. Format-Default („Thema 1") | `course/lib.php:1745` |
| `cm_info->uservisible` | darf dieser User die Activity sehen (Caps+Availability+Gruppen) | `cm_info.php:1493` |
| `cm_info->visible` / `->visibleoncoursepage` | verborgen / auf Kursseite zeigen | `cm_info.php:262 / 268` |
| `cm_info->effectivegroupmode` | NOGROUPS / SEPARATEGROUPS / VISIBLEGROUPS | `cm_info.php:123` |
| `cm_info->modname` / `->get_formatted_name()` | Typ / Anzeigename (format_string) | `cm_info.php:143 / 802` |
| `cm_info->url` | View-Link (`moodle_url`) oder null | `cm_info.php:725` |
| `cm_info->available` / `->availableinfo` | Restriktion / Restriktionstext | `cm_info.php:1539 / 1549` |
| `\core_availability\info::format_info($availableinfo, $course)` | Restriktionstext mit Platzhalter-Tags rendern | `availability/classes/info.php:724` |

**Beschreibungen korrekt holen:**
- Section-Summary: `format_text($section->summary, $section->summaryformat, ['context' => context_course::instance($courseid)])`.
- Activity-Name: `$cm->get_formatted_name()`.
- **Activity-Intro/Beschreibung steht NICHT in modinfo** (`cm_info->content` ist nur der Kursseiten-Text unter dem Link, z. B. Labels). Das echte Intro liegt in der Modul-Instanztabelle (`{forum}.intro` …) → `format_module_intro($cm->modname, $instance, $cm->id)` bzw. `format_text($instance->intro, $instance->introformat, ['context' => $cm->context])`. Instanzen via `$modinfo->get_instances_of($modname)` / DB-Fetch.

---

## 6. Skill-Spezifikation

### 6.1 Name & Metadaten
- `get_name()` → `course.analyze_course_structure`
- `get_required_context_level()` → `CONTEXT_COURSE`
- `implements skill_trigger_provider_interface` → `get_message_triggers()` + `get_contextual_prompt_packs()` (Shape analog `diagnose_access_skill.php:145-187`).

### 6.2 Input-Schema
```php
'properties' => [
  'courseid'    => ['type'=>'integer','description'=>'Numeric course id (wins over coursequery).','required'=>false],
  'coursequery' => ['type'=>'string','description'=>'Course name/idnumber. A NAMED course is never automatically "the current course".','required'=>false],
  'include_hidden' => ['type'=>'boolean','description'=>'Default true: include items the user may see but are hidden from students.','required'=>false],
  'outputlang'  => ['type'=>'string','required'=>false],
],
'prompt_meta' => ['input_fields_for_prompt'=>['coursequery'],'anchor_fields'=>['coursequery'],'context_scopes'=>['course']],
'readonly' => true,
```
`check_structure()` ist trivial valide; keine harte Validierung (R0).

### 6.3 Output-Datenmodell (der Baum + Anker für späteres Targeting)
`execute()` liefert ein flaches Array mit u. a. `status`, `detail`/`usermessage`, `resultid` (= `courseid`), `observation_full` (§6.4) und der Nutzlast `structure`:

```jsonc
"structure": {
  "courseid": 42,
  "coursename": "Mathematik 101",
  "courseurl": "https://…/course/view.php?id=42",
  "viewer_can_edit": true,          // has_capability('moodle/course:manageactivities') — nur als Hinweis fürs spätere Schreiben
  "sections": [
    {
      "id": 555,                    // section_info->id  → Anker für "Sektion X"
      "number": 0,                  // section_info->section (0 = allgemein)
      "name": "Allgemeines",
      "summary_text": "…",
      "hidden": false,              // visible===false?
      "restricted": false,          // available===false?
      "restrictinfo": "",           // format_info(availableinfo) wenn restricted
      "activities": [
        {
          "cmid": 9001,             // → Anker für "hinter Activity Z"
          "modname": "booking",
          "name": "Kurs-Buchung",
          "intro_text": "…",        // format_module_intro(...)
          "url": "https://…/mod/booking/view.php?id=9001",
          "hidden": false,
          "restricted": false,
          "restrictinfo": "",
          "groupmode": "none|separate|visible",
          "position": 0             // Reihenfolge in der Section → "an Position n"
        }
      ]
    }
  ]
}
```
**v1-Datendisziplin:** keine Personenfelder (Trainer-Namen, Teilnehmerlisten) — das hält den Anonymizer aus dem Spiel und den Scope read-only/strukturell.

### 6.4 `observation_full` (LLM-Wahrheitsquelle)
Deterministischer Klartext-Block (höchste Vertrauensstufe `observations > completed_commands > narrative`, `execution_observation_ledger.php:37-41`). Ein Zeile-pro-Element-Baum, z. B.:
```
Course: Mathematik 101 (id 42)
[Section 0] Allgemeines
  - booking  "Kurs-Buchung" (cmid 9001)  url:…
[Section 1] Woche 1  (HIDDEN)
  - page     "Skript"  (cmid 9002)  RESTRICTED: Verfügbar ab 3. Jan 2026
…
State only the structure listed above; do not infer activities or sections that are not listed.
```
Schluss-Guardline analog `diagnose_access_skill.php:483-485`. Personen/URLs nur, wenn sie hier deterministisch stehen.

### 6.5 Preview (preview-as-data)
`get_result_preview($resultentry, $contextid, $userid): ?array` → `{type, html, payload}` (`services/preview_passthrough.php:31-50`, Attach in `executor.php:255-263`).
- **v1:** dedizierter Typ `course_structure` — ein eingerückter Baum (Sections → Activities) mit Badges: `🚫 verborgen`, `🔒 eingeschränkt (Text)`, `👥 Gruppen`. HTML server-seitig mit `html_writer` + `s()`-Escaping, `null` wenn leer (Muster `diagnostic_checklist_preview.php:53-77`).
- Optional später: klickbare Anker, die direkt eine Folgeaktion (Buchungsoption in Section) anstoßen.

---

## 7. Algorithmus (Pseudocode, capability-safe)

```
execute(input, operatingcontextid, userid):
    course = resolve_course(input, operatingcontextid)        // §3 (Path A liefert contextid; daraus courseid)
    if not course: return error_result('…', 'course_not_found')

    if not can_access_course(course, user(userid)):           // lib/accesslib.php:1991
        return error_result('…', 'permission_denied')

    coursectx = context_course::instance(course.id)
    modinfo   = get_fast_modinfo(course, userid)              // Sichtbarkeit = dieser User

    sections = []
    foreach modinfo.get_section_info_all() as s:
        if not s.uservisible: continue                        // gar nicht sichtbar → weglassen
        node = { id:s.id, number:s.section,
                 name: get_section_name(course, s),
                 summary_text: format_text(s.summary, s.summaryformat, {context:coursectx}),
                 hidden: !s.visible,
                 restricted: !s.available,
                 restrictinfo: s.available ? '' : info::format_info(s.availableinfo, course),
                 activities: [] }
        pos = 0
        foreach s.get_sequence_cm_infos() as cm:
            if not cm.uservisible: continue                   // gar nicht sichtbar → weglassen
            node.activities[] = { cmid:cm.id, modname:cm.modname,
                 name: cm.get_formatted_name(),
                 intro_text: module_intro_for(cm),            // §5 (format_module_intro)
                 url: cm.url ? cm.url.out(false) : null,
                 hidden: !cm.visible,
                 restricted: !cm.available,
                 restrictinfo: cm.available ? '' : info::format_info(cm.availableinfo, course),
                 groupmode: map(cm.effectivegroupmode),
                 position: pos++ }
        sections[] = node

    return { status:'executed', detail:summary, usermessage:summary,
             resultid: course.id, structure:{…, sections},
             observation_full: render_tree_text(...) }       // §6.4
```
Korrektheits-Garantie: Jede `uservisible`-Auswertung ruft intern `has_capability(..., $userid)` + `core_availability\info_(module|section)`. Das Iterieren über modinfo des Ziel-Users **ist** der eine, capability-sichere Filter.

---

## 8. Anker für spätere Schreib-Skills (Vorausdenken, nicht v1)

Damit „Buchungsoption in Sektion X / hinter Überschrift Y" später sauber geht, liefert die Analyse schon die stabilen **Targeting-Anker**:
- **Section:** `id` (stabil), `number` (UI-nah), `name` → erlaubt „in Sektion 'Woche 3'" oder „Sektion 2".
- **Activity:** `cmid` + `position` → erlaubt „nach Activity Z" / „an Position n in der Section".
- **„Hinter der Überschrift":** in Moodle ist eine „Überschrift" meist (a) ein Section-Header oder (b) ein `label`-Modul als Pseudo-Überschrift. Beide sind über die obigen Anker adressierbar (Section bzw. cmid des Labels). Der spätere Write-Skill (eigenes Blueprint, R2) konsumiert genau diese IDs → keine Namens-Heuristik nötig.
- `viewer_can_edit` signalisiert dem Planner früh, ob ein Schreibschritt überhaupt sinnvoll ist (echte Autorisierung macht trotzdem erst der Write-Skill via `moodle/course:manageactivities`).

---

## 9. Fehlerfälle & Readiness
- Innerhalb des Skills **nie werfen** → `error_result($msg, $errorclass)` (`diagnose_access_skill.php:548-557`). Klassen: `missing_course`, `course_not_found`, `permission_denied`. Speist den Error-Messaging-Contract (Synchronizer präsentiert sauber, in User-Sprache).
- WS-Readiness (`agent_unavailable` / `context_invalid` / `permission_denied`) wird **oberhalb** durch `check_use_readiness()` behandelt (`services/security/authorization_service.php:119-141`) — nicht im Skill nachbauen.

---

## 10. Registrierung & Deployment
1. Datei in `course/skills/analyze_course_structure_skill.php` (parameterloser Konstruktor) → auto-discovered.
2. Capability-Suffix `course_analyze_course_structure` in `bookingextension/agent/db/access.php` in den passenden Rollen-Bucket (vermutlich `$teacherskills`, dort liegen auch `course_search_courses`, `core_diagnose_*`). Read-only ≠ capability-frei.
3. `version.php` bumpen + `upgrade.php` fahren (installiert die neue Capability — sonst irreführendes „keine Berechtigung").
4. **Skill-Katalog-Embeddings neu bauen** (`classes/task/rebuild_skill_catalog_embeddings_adhoc.php`), sonst findet die Selektion/RAG den Skill nicht. → **erst nach Georgs Go** (Real-LLM/Embeddings-Politik).
5. Lang-Strings (`localized_string()`, Trigger-Texte, Capability-Beschreibung) in `lang/en` + `lang/de`.

---

## 11. v1-Scope & Abgrenzung
**In v1:**
- Lesen der Section/Activity-Struktur eines Kurses (aktuell oder benannt), inkl. hidden/restricted/groupmode, Namen + Beschreibungen.
- Strikte User-Sichtbarkeit via modinfo.
- `observation_full` + `course_structure`-Preview.

**Bewusst NICHT in v1:**
- Jegliches Schreiben/Anlegen/Verschieben (eigener R2-Write-Skill, separates Blueprint).
- Tiefe modul-spezifische Innereien (z. B. einzelne Buchungsoptionen *innerhalb* einer Booking-Instanz) — die Analyse bleibt auf Kurs-Struktur-Ebene. (Option-Ebene ggf. als Folge-Skill `booking.list_options`.)
- Personenbezogene Auswertungen (Teilnehmer, Trainer) — bleibt bei der Diagnose-Familie.

---

## 12. Tests
- **PHPUnit** (Skill-Schicht): Kurs mit (a) sichtbarer Section, (b) für Studierende verborgener Section, (c) Section mit Availability-Restriktion, (d) Activity mit `availability`-JSON, (e) Gruppenmodus. Je einmal als **Trainer** und als **Studierender** ausführen und prüfen:
  - Studierender sieht verborgene/komplett-restringierte Elemente **nicht** (kein Leak).
  - Trainer sieht sie **mit** `hidden`/`restrictinfo`.
  - `observation_full` enthält exakt die sichtbaren Elemente und die Schluss-Guardline.
- **Behat** (`@bookingextension_agent`): „analysiere Kurs X" → Preview zeigt Baum; als Student-Rolle keine verborgenen Einträge.
- **Contract-Test:** R0 ⇔ readonly (greift automatisch über `skill_contract_validator`).

---

## 13. Offene Fragen an Georg
1. **O1 — Greyed-out-Fall:** Sollen Elemente, die der User *nicht betreten* darf, aber Moodle auf der Kursseite *ausgegraut mit Restriktionsgrund* zeigt (`uservisible=false && visibleoncoursepage && availableinfo≠''`, `cm_info.php:1602`), aufgenommen werden (als „gesperrt, Grund: …") — oder strikt weglassen? (Default-Vorschlag: **weglassen** in v1, strikt = sicherer.)
Antwort: wenn der User sie sehen kann: einbeziehen. Allerdings klar für llm anmerken, dass der user sie nicht betreten darf.
2. **O2 — Beschreibungen Kostenfaktor:** Activity-Intros erfordern pro Modul einen Instanz-Load (DB). Bei großen Kursen optional `include_descriptions=false`? (Default-Vorschlag: Intros standardmäßig **an**, abschaltbar.)
Antworten: ok, so wie du sagst.
3. **O3 — Path A vs. B:** Engine-Auflösung (Ambiguitäts-Clarification, empfohlen) vs. einfache `resolve_courseid()`-Leiter — ok mit Path A?
Antwort: Path A.
4. **O4 — Preview-Typ:** dedizierter `course_structure`-Renderer jetzt, oder v1 erstmal mit dem generischen Checklist-Preview ausliefern?
Antwort: course structure renderer.
5. **O5 — Booking-Optionen-Ebene:** Soll die Analyse für `booking`-Activities optional gleich die enthaltenen Buchungsoptionen mitlisten (Brücke zum späteren „Option in Section X"), oder bleibt das einem Folge-Skill vorbehalten?
Antwort:
