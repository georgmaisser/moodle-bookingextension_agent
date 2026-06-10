# Blueprint: Generischer `core.add_activity`-Skill (Activity in einem Kurs anlegen)

*Stand: 2026-06-10 · Status: Analyse + Implementierungsvorbereitung, noch keine Umsetzung*

## 0. Leitprinzip (nicht verhandelbar)

**Die Engine bleibt vollständig clean.** Kein Stück activity-, modul- oder mform-spezifisches Wissen darf in
`orchestrator`, `executor`, `preflight_pipeline`, `agent_decision_service`, `agent_runtime`,
`synchronizer_*` oder `preview_passthrough` landen. Die Engine kennt weiterhin nur **generische
Skill-Kontrakte**. Alles, was „Activity erzeugen" bedeutet, lebt im Skill und in skill-eigenen Services.

Das ist dieselbe Disziplin wie bei generate_questions (Services unter `services/questions/`, Engine reicht
Preview/Issues nur generisch durch) und beim Preview-Daten-Kontrakt. Dieses Dokument zeigt, wie ein so
komplexer Vorgang wie „beliebige Activity anlegen" **ohne einen einzigen Engine-Touch** funktioniert.

---

## 1. Die Engine-Cleanliness-Garantie (Contract-Mapping)

Jeder „Sonderbedarf" des Activity-Vorgangs wird über einen **bereits existierenden generischen Kanal**
transportiert. Die Engine sieht nie etwas Activity-Spezifisches:

| Activity-spezifischer Bedarf | Generischer Kanal (Engine kennt ihn schon) | Engine erfährt … |
|---|---|---|
| „Welche Activity?" — Liste der addbaren Module anbieten | `preflight` → `preflight_result_v2::invalid([... severity=needs_clarification, 'options'=>[...] ])` | nur „ein Skill braucht eine Clarification" |
| Echte Feld-Fehlermeldungen der mform | dieselbe `issues[].message`-Liste im Preflight | nur „Issue mit Message" |
| Pflichtfelder eines konkreten Modultyps | Clarification-Runde (Skill fragt, User/LLM liefert) | nichts Modulspezifisches |
| Bestätigung vor Mutation | R-Class (R2/R3) + bestehender Confirm-Flow | nur die Risikoklasse |
| Retry bei Erstellungsfehler | **skill-intern in `execute`** (wie generate_questions) | nur das Endergebnis |
| Vorschau der erzeugten Activity | `get_result_preview()` → `{type, html, payload}` (duck-typed) | nur einen Daten-Block zum Durchreichen |
| Native Rechte (`mod/x:addinstance` …) | `get_required_native_capabilities()` + Preflight-Cap-Check | nur „Skill verlangt Caps" |
| Skill-Discovery / Trigger | `get_schema`, `get_message_triggers`, `get_contextual_prompt_packs` | nur generische Kataloge/Embeddings |

**Konsequenz:** Es gibt **keinen** Patch an Engine-Dateien. Der Skill nutzt ausschließlich die Methoden, die
`base_skill`/`skill_interface` und die optionalen (duck-typed bzw. interface-basierten) Hooks ohnehin
bereitstellen: `get_name`, `get_schema`, `check_structure`, `preflight`, `execute`,
`get_required_context_level`, `get_required_native_capabilities`, `get_example_input`,
`get_message_triggers`, `get_contextual_prompt_packs`, `get_result_preview`.

---

## 2. Datei-Layout (alles skill-eigen)

```
classes/local/wbagent/core/skills/
  add_activity_skill.php                # core.add_activity — der einzige Skill
classes/local/wbagent/services/activities/
  module_catalog_service.php            # addbare Module im Kurs ermitteln (Caps-gefiltert)
  module_form_contract.php              # mform headless bauen + validation() (Dry-Run) + kuratierte Felder
  activity_creation_service.php         # add_moduleinfo(...) in execute, transaktional, mit Rollback
  activity_preview_renderer.php         # gerenderte Vorschau/Trefferliste fürs Preview-Pane
lang/en/bookingextension_agent.php      # nur neue Strings
db/access.php                           # Gate-1-Cap 'core_add_activity' (teacher/manager)
```

> Verortungs-Hinweis: Die Skill-Discovery scannt `core/skills/`. Ob ein **Unterordner** `core/skills/course/`
> automatisch mitgescannt wird, ist **vor der Umsetzung zu verifizieren** (sonst Skill flach in `core/skills/`
> halten wie `generate_questions_skill.php`, Services aber gruppiert unter `services/activities/`).

Keine dieser Dateien wird von der Engine referenziert. Die Engine instanziiert den Skill nur über die
generische Registry.

---

## 3. Kern-Mechanismus: die mform als *einziger Kontrakt* für beide Phasen

Statt die mform zur **Schema-Extraktion** zu missbrauchen (fragil), nutzen wir sie als **Validator** — ihre
echte Stärke. Sie ist in beiden Phasen die Wahrheit:

- **Preflight (read-only Dry-Run):** Für den gewählten Modultyp die `mod_<x>_mod_form` headless bauen und
  `validation($data, $files)` aufrufen (plus best-effort Element-Requireds aus `$mform->_form->_required`).
  Liefert die **echten, lokalisierten** Fehlermeldungen und fehlenden Felder — ohne DB-Writes.
- **Execute (echte Mutation, nach Confirm):** Dieselben validierten `$moduleinfo`-Daten an
  `add_moduleinfo($moduleinfo, $course)` (`course/modlib.php`) geben.

### Warum NICHT die Erstellung im Preflight „simulieren"
`add_moduleinfo()` ist **nicht transaktional gekapselt**: schreibt Dateien (File-Storage), feuert
`\core\event\course_module_created` → **Fremd-Observer** (Completion, Calendar, Booking-Rules, Search,
Plagiarism), reiht Adhoc-Tasks ein, rebuildet MUC-Caches, schickt ggf. Notifications. Ein
`$transaction->rollback()` macht nur **DB-Zeilen** rückgängig, nicht die übrigen Effekte. Eine „zurückgerollte
Simulation" im Preflight hätte also echte, irreversible Nebenwirkungen **und** würde die Invarianten
„Preflight ist read-only" + „Mutation genau einmal in execute nach Confirm" brechen. → **Verboten.**
Der saubere Dry-Run ist `mform->validation()`, nicht die Voll-Erstellung.

### Headless-mform: die eingehegte Fragilität
`moodleform_mod::__construct($current, $section, $cm, $course)` lebt eigentlich im Web-Request. Headless wird
ein Fake-`$current` (Default-Instanz), eine Section und `$cm = null` benötigt; manche Forms zicken. Das ist die
einzige reale Brittleness — sie wird **pro unterstütztem Modul mit einem Test gepinnt** (Form baut + validiert),
statt offen gelassen zu werden. Genau das ist die Grenze zwischen „generisch tragfähig" und „nicht".

---

## 4. End-to-End-Ablauf (alles innerhalb der generischen Kontrakte)

1. **Trigger/Discovery:** User „leg im Kurs ein Quiz/eine Seite/… an". `get_message_triggers` +
   `get_contextual_prompt_packs` führen zur Skill-Auswahl. Default-Kurs = aktueller Kurs aus dem Context
   (wie generate_questions); abweichender Kurs → später (geparkt).
2. **Construction:** LLM füllt das **kuratierte, statische** Schema (siehe §5). Kennt es den Modultyp nicht,
   lässt es `modname` leer.
3. **Preflight – Stufe A (Modulwahl):** Ist `modname` leer/unbekannt → `module_catalog_service` liefert die
   addbaren Module (Caps-gefiltert) → `needs_clarification` mit `options[]` (gleicher Kanal wie die
   Kategorie-Nachfrage bei generate_questions). Der Skill löst — analog `match_targets_by_name` — auch einen
   **Modul-Namen** deterministisch auf, damit der Planner keine internen IDs erfinden muss.
4. **Preflight – Stufe B (Feld-Validierung):** Modultyp steht → `module_form_contract` baut die mform und
   validiert die Kandidatendaten. Fehlende/ungültige Felder → `needs_clarification` mit den **echten**
   Meldungen. Gate-2-Caps (`moodle/course:manageactivities` + `mod/<modname>:addinstance` am Kurs-Context)
   werden hier geprüft.
5. **Confirm:** R2 (oder R3, siehe §6) → bestehender Confirm-Flow.
6. **Execute:** `activity_creation_service` ruft `add_moduleinfo()` in einer `start_delegated_transaction()`;
   bei Fehler sauberer Rollback + Fehlertext → **skill-interne Retry-Schleife** (max N), exakt wie bei
   generate_questions. Ergebnis: cmid + Modul-URL.
7. **Preview:** `get_result_preview()` liefert einen `{type:'created_activity', html, payload:{cmid,…}}`-Block;
   `preview_passthrough` reicht ihn generisch durch. (HTML server-seitig, `ob_start/ob_end_clean`-gehärtet wie
   beim Question-Renderer, da execute synchron im Webservice läuft.)

---

## 5. Das dynamische-Schema-Problem ohne Engine-Leak gelöst

Der Planner-Kontrakt ist **statisch**. Wir lösen die Modul-Abhängigkeit **innerhalb des Skills**, nicht über ein
dynamisches Engine-Schema:

- `get_schema()` bleibt **klein und statisch**: `modname` (string), `name` (string), `intro` (string),
  `sectionnum` (int, optional), und ein **freies `settings` (object)** als Beutel für modulspezifische Felder.
- Die *Bedeutung* von `settings` kennt nur der Skill: `module_form_contract` mappt sie gegen die echte mform und
  validiert. Unbekannte/fehlende Pflichtfelder kommen als Clarification zurück — **als Daten**, nicht als
  Schema-Mutation.

So bleibt der formalisierte Selektor/Construction-Contract unangetastet; die „Dynamik" ist reine
Skill-Interne + Clarification-Runden über den generischen Issue-Kanal.

---

## 6. Autorisierung

- **Gate 1 (Agent-Cap):** `bookingextension/agent:skill_core_add_activity`, generiert über `db/access.php`
  (teacher/manager). Bewusst **eine** Cap für „Activity anlegen" — gröber als pro Typ, aber `manageactivities`
  ist das echte Gate.
- **Gate 2 (native):** dynamisch im Preflight: `moodle/course:manageactivities` **und** `mod/<modname>:addinstance`
  am Ziel-Kurs-Context. `get_required_native_capabilities()` kann nur die statische (`manageactivities`)
  deklarieren; die modulspezifische `addinstance` wird im Preflight nachgeprüft, sobald `modname` feststeht.
- **Risikoklasse:** R2 (schreibend, Confirm). Erwägen: **R3**, falls wir die Erstellung als „externe
  Abhängigkeit" mit Vor-Check behandeln wollen — wahrscheinlich unnötig, R2 reicht.

---

## 7. Wo der generische Skill sinnvoll ist — und wo er an Grenzen stößt

**Sinnvoll (klarer Gewinn):** simple, formal-stabile Content-Module mit kleinem Feldset und ohne Folge-Workflow:
- `page`, `url`, `label`, `book`, `folder`, `forum` (Basis), `resource/file` (mit Einschränkung Datei-Upload).
- Hier ist „mform validieren → `add_moduleinfo`" praktisch vollständig; der generische Skill ist die richtige
  Lösung und spart N Skills.

**Grenzfälle (geht, aber mit Vorsicht):** Module mit Pflicht-Unterstrukturen oder Datei-/Editor-Feldern:
- `assign` (Submission-Plugins, Zeitfenster), `forum` (Typen), `lesson`/`wiki` (Seiten danach). Die Hülle
  entsteht generisch; der *Inhalt* braucht Folge-Schritte. mform-Validierung deckt die Settings ab, aber der
  Nutzen endet beim leeren Gerüst.

**Harte Grenze (dedizierter Skill besser):** Module, deren **Wert im Intent-Workflow** steckt:
- `quiz` → „Quiz + die gerade erzeugten Fragen referenzieren + Bewertung". Das ist Question-Reference-API
  (`question_references`, `mod_quiz\structure`), nicht „mform befüllen". → **eigener `course.add_quiz`-Skill**,
  der auf demselben `activity_creation_service` aufsetzt, aber den Quiz-Workflow kennt.
- Module mit Editor/filemanager-Pflichtfeldern (Draft-Area-Handling), `lti`/`scorm` (Pakete/Endpoints).

**Faustregel:** generischer Skill = „Activity-Hülle korrekt anlegen". Dedizierter Skill = „Activity + ihr
typischer Folge-Workflow". Beide teilen den Creation-Service; die Engine bleibt in beiden Fällen clean.

---

## 8. Verhältnis zu dedizierten Skills

```
activity_creation_service  ← gemeinsamer, modulneutraler Kern (add_moduleinfo + Transaktion + Defaults)
        ▲                         ▲
core.add_activity            course.add_quiz (später)
(generisch, Hülle)           (Intent-Workflow: + Fragen referenzieren)
```

Kein Skill referenziert den anderen; beide nur den Service. So gibt es **keine Verdopplung** und keine
Task-Logik im Service über das Modulneutrale hinaus.

---

## 9. Phasenplan (Vorbereitung der Umsetzung)

- **P0 – Spike (Risiko zuerst):** `module_form_contract` headless für 3 Module (`page`, `url`, `label`):
  Form bauen + `validation()` + Required-Extraktion. Klärt die einzige echte Unbekannte (Headless-mform).
- **P1 – `module_catalog_service`:** addbare Module ermitteln (`get_module_types_names()` ∩
  `course_allowed_module()` ∩ `mod/x:addinstance`-Cap), inkl. Namens-Auflösung.
- **P2 – `activity_creation_service`:** `add_moduleinfo` transaktional, Defaults (visible, completion off,
  groupmode aus Kurs), Rollback-on-error, Retry-Hook.
- **P3 – `add_activity_skill`:** Schema, zweistufiger Preflight (Modulwahl → Feld-Validierung), execute,
  `get_result_preview`, Gate-2-Caps; `db/access.php`-Cap; lang-Strings.
- **P4 – Tests:** pro Modul Headless-mform-Test; Catalog-Caps-Filter (Student sieht 0); Creation +
  Rollback-on-failure; Preview read-only/`ob`-gehärtet; Clarification-Pfade (kein modname → Liste; Name→Auflösung).
- **P5 – (separat) `course.add_quiz`** auf demselben Service.

---

## 10. Tests, die die Cleanliness/Brittleness pinnen

- **Engine-Unberührtheit:** ein Test/Assertion, dass keine Engine-Datei modulspezifische Strings enthält
  (Grep-Guard im Test, optional) — dokumentiert die Invariante.
- **Headless-mform pro Modul** (die eigentliche Risikoabsicherung).
- **Catalog**: Caps-Filter, Namens-Auflösung eindeutig/mehrdeutig.
- **Creation**: Erfolg + erzwungener Fehler → Rollback, keine Leichen.
- **Preflight read-only**: nach Preflight existiert **kein** neues Modul (Beweis, dass nicht „simuliert"
  erstellt wird).

---

## 11. Offene Entscheidungen

1. **Scope generischer Skill:** nur „validieren-und-erstellen" (Form als Kontrakt, schlank) — oder zusätzlich
   Intent-Workflows pro Typ (→ dann lieber dedizierter Skill)? *Empfehlung: schlank halten.*
2. **Modul-Whitelist:** Starten wir mit einer kuratierten Whitelist sinnvoller Module, oder „alles addbare
   anbieten"? *Empfehlung: Whitelist (page/url/label/book/folder/forum), Rest später.*
3. **Section-Platzierung:** immer Default-Section, oder im Preflight anbieten? *Empfehlung: Default, optional
   `sectionnum`.*
4. **Skill-Ordnerstruktur:** `core/skills/course/` (wenn Discovery rekursiv) vs. flach. *Vor P3 verifizieren.*
5. **R2 vs R3.** *Empfehlung: R2.*

---

## 12. Risiken

- **Headless-mform-Fragilität** — eingehegt durch P0-Spike + Per-Modul-Tests; bei Modulen, die sich sträuben,
  fallen sie einfach aus der Whitelist (kein generischer Anspruch auf 100 % aller Module).
- **Fidelity der Validierung:** `validation()` liefert mod-spezifische/Cross-Field-Checks real; reine
  Element-Requireds nur best-effort. Restfehler fängt der echte `add_moduleinfo`-Versuch in execute (Retry).
- **Synchrones Rendern im Webservice** — Preview wie beim Question-Renderer mit `ob_start/ob_end_clean` härten,
  damit keine HTML-Debug-Ausgabe die JSON-Antwort bricht.

---

*Verwandte Blueprints:* `neue_skills_und_pdf_fragegenerierung_analyse_2026-06-09.md` (Skill-Familie + PDF→Fragen,
selber Service-+Retry-Stil), `preview_api_analysis_2026-06-08.md` (Preview-Daten-Kontrakt),
`agent_cmid_context_decoupling_und_kontextwechsel_pruefung_2026-06-09.md` (Context/Default-Kurs).
```
