# Blueprint: Generischer `course.add_activity`-Skill (Activity in einem Kurs anlegen)

*Stand: 2026-06-10 · Verifiziert gegen Code-Stand 2026-06-13 (Plugin-Version 2026061201) · Status: Analyse abgeschlossen, gegen Flowchart + realen Code geprüft, umsetzungsbereit — noch keine Umsetzung*

> **Namens-Korrektur (2026-06-13):** Der Skill heißt **`course.add_activity`** (nicht `core.add_activity`). Begründung in §0.5 — passt zum bestehenden `course.*`-Namespace (`course.search_courses`) und zur Discovery-Konvention `classes/local/wbagent/course/skills/`.

---

## 0.5 Verifikation gegen realen Code-Stand (2026-06-13)

Geprüft wurde die Kern-These „**kein einziger Engine-Touch nötig**" gegen den tatsächlichen Code und gegen das autoritative `AGENT_IMPLEMENTATION_FLOWCHART.mmd`. **Ergebnis: Die These hält.** Es existiert ein nahezu vollständiges Präzedenz-Skill, das exakt dieses Muster ohne Engine-Änderung umsetzt: **`question.generate_questions`** (`classes/local/wbagent/question/skills/generate_questions_skill.php`).

### Bestätigt (1:1 im Code vorhanden)

| Blueprint-Annahme | Verifiziert im Code |
|---|---|
| `needs_clarification` + `options[]` als generischer Kanal | `preflight_result_v2::invalid([['severity'=>'needs_clarification','message'=>…,'code'=>…,'options'=>[…]]])` — exakt so in `generate_questions_skill::build_target_clarification()` |
| Deterministische Namens-Auflösung statt LLM-IDs | `match_targets_by_name()` im generate_questions-Skill (Vorlage für Modul-Namensauflösung) |
| Preview als reine Daten, generisch durchgereicht | `executor.php:225` ruft `get_result_preview()` **duck-typed** via `method_exists`; `preview_passthrough` ruft **nie** in Skills, reicht nur `{type,html,js,js_module,payload}` durch |
| Native Caps deklarierbar (Gate 2) | `base_skill::get_required_native_capabilities()` + `require_native_capabilities()` vorhanden |
| Cross-Context (Ziel-Kurs ≠ aktueller Kurs) **gratis** | `supports_target_context()`/`get_target_selector()`/`get_target_context_level()` in `base_skill`; generate_questions nutzt es bereits für einen abweichenden Ziel-Kurs |
| R2 + Confirm-Flow | `parent::__construct(false, skill_risk_class::R2)` |
| Skill-Discovery findet neuen Ordner automatisch | `skill_discovery::get_skill_directories()` scannt **jeden** `classes/local/wbagent/<dir>/skills/`; `course/skills/` existiert bereits (`search_courses_skill.php`) |
| Family entsteht ohne Registry-Edit | `family_registry_service::discover()` leitet Families **dynamisch aus den Skill-Prompt-Contracts** ab (Namespace). Ein `course.*`-Skill ergibt automatisch die `course`-Family — **kein** hardcodierter Map-Eintrag |
| Cap-Registrierung deterministisch | `skill_contract_validator::build_skill_capability_name()` → `course.add_activity` ⇒ `bookingextension/agent:skill_course_add_activity` |
| Core-Funktionen vorhanden | `add_moduleinfo()` (`course/modlib.php:49`), `get_module_types_names()` (`course/lib.php:402`), `course_allowed_module()` (`course/lib.php:1648`) |

### Präzisierungen / Korrekturen am Blueprint (wichtig für die Umsetzung)

1. **Gate-2-Caps werden NICHT automatisch von der Engine erzwungen.** `get_required_native_capabilities()` wird im gesamten Engine-Code **nur** vom base_skill-eigenen Helfer `require_native_capabilities()` konsumiert — es gibt keinen Engine-Schritt, der die deklarierten Caps eigenständig prüft. Konsequenz: Der Skill **muss selbst** prüfen — entweder graceful als `needs_clarification` (wie generate_questions es mit `moodle/question:add` tut) oder via `require_native_capabilities()` (wirft `require_capability`). Sowohl `moodle/course:manageactivities` **als auch** `mod/<modname>:addinstance` werden im Preflight des Skills selbst geprüft. Das Deklarieren in `get_required_native_capabilities()` bleibt sinnvoll als Contract/Doku.
2. **Namespace `course.*` statt `core.*`** → Skill-Datei nach `classes/local/wbagent/course/skills/add_activity_skill.php`, Cap `skill_course_add_activity` (siehe korrigiertes §2 und §6).
3. **Aktivierung ist operativ, nicht Code.** Neu entdeckte Skills sind **default-off** (`skill_registry::is_skill_active()` → „Default-off for newly discovered skills"). Nach dem Merge: System-Setting `aiskillenabled_course_add_activity` einschalten (oder dev: `aiskillenableall`) **und** Skill-Katalog-Embeddings neu bauen (`rebuild_skill_catalog_embeddings_adhoc` / Skill `core.recreate_skill_catalog`). Beides sind Betriebsschritte, **keine** Engine-Änderung.

### Flowchart-Abgleich

Keine Diskrepanz zum autoritativen Flowchart. Der Skill bewegt sich vollständig innerhalb dokumentierter Knoten: `PF_L2P` (skill::preflight am Operating-Context, Gate 2), `needs_clarification` → `D_*`/`SYNC`, `Q_BLOCKED` (R2, 300s TTL), `EXC_RUN` (skill::execute), `get_result_preview` → `preview_passthrough`. Die Skill-interne Retry-Schleife in `execute` ist (wie bei generate_questions) **unterhalb** der Flowchart-Granularität und damit konform.

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
classes/local/wbagent/course/skills/
  add_activity_skill.php                # course.add_activity — der einzige Skill (course/skills/ existiert bereits)
classes/local/wbagent/services/activities/
  module_catalog_service.php            # addbare Module im Kurs ermitteln (Caps-gefiltert)
  module_form_contract.php              # mform headless bauen + validation() (Dry-Run) + kuratierte Felder
  activity_creation_service.php         # add_moduleinfo(...) in execute, transaktional, mit Rollback
  activity_preview_renderer.php         # gerenderte Vorschau/Trefferliste fürs Preview-Pane
lang/en/bookingextension_agent.php      # nur neue Strings
db/access.php                           # Gate-1-Cap: 'course_add_activity' in $teacherskills aufnehmen
```

> **Verortung verifiziert (2026-06-13):** `skill_discovery::get_skill_directories()` scannt jeden
> `classes/local/wbagent/<dir>/skills/`-Ordner (und rekursiv darunter). Der `course/`-Namespace existiert
> bereits (`course/skills/search_courses_skill.php`), daher ist `course/skills/add_activity_skill.php` die
> natürliche, konventionskonforme Heimat. Services gruppiert unter `services/activities/`. → Offene Frage #4
> ist damit entschieden (kein `core/skills/course/`-Sonderfall nötig).

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

- **Gate 1 (Agent-Cap):** `bookingextension/agent:skill_course_add_activity` (Konvention bestätigt via
  `skill_contract_validator::build_skill_capability_name()`). Registrierung: Suffix **`course_add_activity`**
  in das `$teacherskills`-Array in `db/access.php` aufnehmen (gleicher Mechanismus wie `course_search_courses`,
  `question_generate_questions`). Bewusst **eine** Cap für „Activity anlegen" — `manageactivities` ist das echte Gate.
- **Gate 2 (native):** **skill-selbst-geprüft** im Preflight (die Engine erzwingt deklarierte Caps NICHT
  automatisch — siehe §0.5 Präzisierung 1): `moodle/course:manageactivities` **und** `mod/<modname>:addinstance`
  am Ziel-Kurs-Context, geprüft sobald `modname` feststeht. Umsetzung graceful als `needs_clarification`
  (Vorbild generate_questions mit `moodle/question:add`) oder via `require_native_capabilities()`.
  `get_required_native_capabilities()` deklariert die statische (`moodle/course:manageactivities`) als Contract/Doku.
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
   Antwort: schlank.
2. **Modul-Whitelist:** Starten wir mit einer kuratierten Whitelist sinnvoller Module, oder „alles addbare
   anbieten"? *Empfehlung: Whitelist (page/url/label/book/folder/forum), Rest später.*
   Antwort: whitelist ist gut!
3. **Section-Platzierung:** immer Default-Section, oder im Preflight anbieten? *Empfehlung: Default, optional
   `sectionnum`.*
   Antwort: im Preflight die vorhanden sections mit klarnamen zurückgeben, also "wohin", wenn nicht mit dem ursprünglichen befehl schon "ganz unten", ganz oben, in Sektion "meine Sektion" oder so mit gegeben wurde.
4. ~~**Skill-Ordnerstruktur:**~~ **ENTSCHIEDEN (2026-06-13):** `course/skills/add_activity_skill.php` —
   `course/skills/` existiert bereits und wird von der Discovery gescannt. Services unter `services/activities/`.
5. **R2 vs R3.** *Empfehlung: R2.*
  Antwort: R2.
6. **Cross-Context-Targeting (neu, 2026-06-13):** Soll der Skill von Anfang an einen abweichenden Ziel-Kurs
   unterstützen (wie generate_questions via `supports_target_context`/`get_target_selector`)? Das ist „gratis"
   über das bestehende Muster. *Empfehlung: ja — minimaler Aufwand, gleicher Vertrag; Default bleibt aktueller Kurs.*
  Antwort: Ja, inklusive target.
> **Verbleibende, von Georg zu treffende Entscheidungen vor P0:** #1 (Scope schlank), #2 (Start-Whitelist),
> #5 (R2), #6 (Cross-Context ja/nein). #3 und #4 sind entschieden. Bis dahin folgt der Plan unten den Empfehlungen.

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

---

## 13. Konkreter Implementierungsplan (Checkboxen, umsetzungsbereit ab 2026-06-13)

Referenz-Skill für jeden Schritt: `question/skills/generate_questions_skill.php` + `services/questions/*`.
Reihenfolge bewusst risiko-zuerst (P0 = Headless-mform). **Engine-Dateien bleiben unberührt** — wenn ein
Schritt eine Engine-Datei anfassen will, ist das ein Stop-Signal und zuerst mit Georg zu klären.

> ### Umsetzungsstand 2026-06-13 — Code vollständig, nur Betrieb (P5) offen
>
> Auf Basis von Georgs Antworten (Scope schlank · Whitelist page/url/label/book/folder/forum · Section im
> Preflight erfragen mit Klarnamen + top/bottom/Name-Auflösung · R2 · Cross-Context inkl. Ziel-Kurs)
> implementiert — **ohne jede Engine-Änderung**. Neue Dateien:
> - `services/activities/module_catalog_service.php` (Whitelist ∩ installiert ∩ site-enabled ∩
>   `course_allowed_module`/addinstance; Namensauflösung)
> - `services/activities/section_resolver_service.php` (Sections listen; top/bottom/Name/Nummer auflösen, read-only)
> - `services/activities/module_form_contract.php` (echte mod_form headless via `prepare_new_moduleinfo_data`
>   + `set_data`; Defaults aus `exportValues()`, Pflichtfelder aus `_required`, echte `validation()`-Fehler;
>   QuickForm-Zugriff via **einer** gekapselten Reflection auf das protected `moodleform::$_form`, guarded)
> - `services/activities/activity_creation_service.php` (`add_moduleinfo` transaktional, Rollback, cmid/URL)
> - `services/activities/activity_preview_renderer.php` (ob-gehärtete Karte)
> - `course/skills/add_activity_skill.php` (`course.add_activity`, R2, Cross-Context, zweistufiger Preflight
>   Modul→Section→Felder, execute mit Retry, `get_result_preview`)
> - `db/access.php` (+`course_add_activity` in `$teacherskills`), `lang/en/…` (Cap-Label), `version.php` (Bump
>   auf 2026061300)
> - Tests: `add_activity_skill_test.php`, `module_catalog_service_test.php`, `section_resolver_service_test.php`
>
> **Verifiziert auf der VM (user@10.111.0.2 · Moodle 5.1.1+ · PHP 8.3.28 · MariaDB 10.11):** `php -l` über
> alle 15 Dateien sauber; alle **6 neuen PHPUnit-Suites grün — 26 Tests, 149 Assertions, 0 PHP-/Moodle-Warnings**
> (nur framework-eigene „PHPUnit Deprecations" aus Doc-Comment-Annotations, codebase-weit). Die Per-Modul-
> Headless-mform-Tests (page/url/label/book/folder/forum) bauen + validieren alle.
>
> **Gelöste Headless-mform-Brittleness (in `module_form_contract`):** Core-`standard_coursemodule_elements()`
> liest das globale `$COURSE`, und der erste `$OUTPUT`-Zugriff in `definition()` setzt `$COURSE` auf
> `$PAGE->course` (Site). Fix: während Build/Validate eine **frische `moodle_page` ans Ziel binden** (erst
> `$PAGE` global zuweisen, DANN `set_course()` — `moodle_page::set_course()` aktualisiert das globale `$COURSE`
> nur für die aktuelle globale Page), danach restaurieren. Zusätzlich bekommt `validation()` ein vollständiges
> Wertepaket (jedes Form-Element default `''`) → keine `trim(null)`/`json_decode(null)`-Noise.
>
> **Regressionscheck:** voller `bookingextension_agent_testsuite`-Lauf = 362 Tests; **1 Failure
> (`phase2_discovery_staging_contract_test::test_family_registry_uses_prior_without_hard_exclusion`) ist
> VORBESTEHEND** — reproduziert identisch, wenn der neue Skill+Services beiseitegelegt werden (nicht meine
> Änderung; `core_family_set` liefert `wbagent.general` als mandatory Family, was dieser hartkodierte
> Discovery-Test nicht erwartet).
>
> **P5 ERLEDIGT auf VM (2026-06-13, mit Georgs Go):** Upgrade auf 2026061300; Setting
> `aiskillenabled_course_add_activity` aktiviert (Skill aktiv+discoverbar); Embeddings rebuilt (course.add_activity
> im Katalog); **Live-End-to-End-Rauchtest mit echtem LLM grün** — „erstelle eine Seite" → course.add_activity →
> confirmation_request → confirm → Page real in Section 0 angelegt + verifiziert. Ticket damit komplett (P6
> `course.add_quiz` bleibt bewusst separat).

### P0 — Spike: Headless-mform-Tragfähigkeit (die einzige echte Unbekannte)
- [x] `services/activities/module_form_contract.php` als Wegwerf-/Keimzelle: für `page` die
      `mod_page_mod_form` headless bauen (`$current` = Default-stdClass, Section-Objekt, `$cm = null`).
- [x] `validation($data, $files)` aufrufen und echte, lokalisierte Fehlermeldungen + fehlende Felder zurückbekommen.
- [x] Best-effort Required-Extraktion aus `$mform->_form->_required` ergänzen.
- [x] Dasselbe für `url` und `label` (3 Module = Tragfähigkeitsnachweis).
- [x] **Gate-Entscheidung:** sträubt sich ein Modul → fällt aus der Whitelist. Ergebnis im Doc notieren.

### P1 — `module_catalog_service` (addbare Module ermitteln)
- [x] `get_module_types_names()` ∩ `course_allowed_module($course, $modname, $user)` ∩
      `has_capability('mod/<modname>:addinstance', $coursecontext, $userid)`.
- [x] Auf die in P0 bestätigte Whitelist (#2) filtern.
- [x] Deterministische Modul-Namensauflösung (Vorbild `match_targets_by_name()`): exakt → Teilstring →
      mehrdeutig/0-Treffer als Clarification-Kandidatenliste.
- [x] Liefert sowohl maschinenlesbare `options[]` als auch menschenlesbare Zeilen (für die Clarification-Message).

### P2 — `activity_creation_service` (modulneutraler Mutations-Kern)
- [x] `add_moduleinfo($moduleinfo, $course)` innerhalb `start_delegated_transaction()`.
- [x] Defaults setzen: `visible`, `completion` off, `groupmode` aus Kurs, Ziel-`section`.
- [x] Rollback-on-error + sauberer Fehlertext (für die skill-interne Retry-Schleife).
- [x] Rückgabe: `cmid` + Modul-URL. **Keine** Skill-/Task-Logik über das Modulneutrale hinaus (Wiederverwendung
      durch späteren `course.add_quiz`-Skill, §8).

### P3 — `course.add_activity` Skill (Datei: `course/skills/add_activity_skill.php`)
- [x] Klasse `extends core_skill_base implements skill_trigger_provider_interface`,
      `parent::__construct(false, skill_risk_class::R2)`.
- [x] `get_name()` → `'course.add_activity'`; `get_required_context_level()` → `CONTEXT_COURSE`.
- [x] **Statisches, schlankes** `get_schema()`: `modname`, `name`, `intro`, optional `sectionnum`, freies
      `settings` (object) — Bedeutung kennt nur der Skill (§5).
- [x] `get_required_native_capabilities()` → `['moodle/course:manageactivities']` (Contract/Doku).
- [x] `get_message_triggers()` + `get_contextual_prompt_packs()` (de/en Trigger + Guidance) für Discovery.
- [x] `get_example_input()` für die Planner-Contract-Darstellung.
- [x] **Preflight Stufe A (Modulwahl):** `modname` leer/unbekannt → `module_catalog_service` →
      `preflight_result_v2::invalid([... severity=needs_clarification, 'options'=>[...]])`.
- [x] **Preflight Stufe B (Feld-Validierung + Gate 2):** `modname` steht → `module_form_contract` validiert;
      **selbst** `has_capability('moodle/course:manageactivities', …)` **und** `mod/<modname>:addinstance`
      prüfen (graceful `needs_clarification`); fehlende/ungültige Felder als `needs_clarification` mit echten Messages.
- [x] **`execute()`:** `activity_creation_service` aufrufen; skill-interne Retry-Schleife (max N) bei
      Erstellungsfehler (Vorbild generate_questions `MAX_RETRIES`). Ergebnis: cmid + URL + `observation_full`.
- [x] **`get_result_preview()`:** `{type:'created_activity', html, payload:{cmid,…}}`; HTML server-seitig mit
      `ob_start/ob_end_clean` gehärtet (Vorbild `question_preview_renderer`).
- [x] *(Optional, Entscheidung #6)* `supports_target_context()`/`get_target_context_level()`/`get_target_selector()`
      für abweichenden Ziel-Kurs (1:1 generate_questions-Muster).

### P3b — Registrierung & Strings (kein Engine-Code)
- [x] `db/access.php`: `'course_add_activity'` in `$teacherskills` aufnehmen.
- [x] `lang/en/bookingextension_agent.php`: neue Strings (Cap-Label `agent:skill_course_add_activity`,
      Clarification-/Preview-Texte).
- [x] `version.php` hochzählen (für Cap-/Setting-Rollout).

### P4 — Tests (pinnen Cleanliness + Brittleness, §10)
- [x] Pro Whitelist-Modul: Headless-mform-Test (Form baut + validiert) — die eigentliche Risikoabsicherung.
- [x] Catalog: Caps-Filter (Student sieht 0 addbare Module) + Namensauflösung (eindeutig/mehrdeutig/0).
- [x] Creation: Erfolg **und** erzwungener Fehler → Rollback, keine Leichen.
- [x] **Preflight read-only-Beweis:** nach Preflight existiert **kein** neues Modul (belegt: keine „Simulation").
- [x] Clarification-Pfade: kein `modname` → Liste; Modulname → Auflösung.
- [x] **Engine-Unberührtheit:** Grep-Guard-Assertion, dass keine Engine-Datei modulspezifische Strings enthält
      (dokumentiert die Invariante).

### P5 — Aktivierung & Rollout (operativ, kein Code) — ERLEDIGT auf VM 2026-06-13
- [x] Plugin-Upgrade auf 2026061300 gefahren (Capability `skill_course_add_activity` installiert).
- [x] System-Setting `aiskillenabled_course_add_activity` aktiviert (admin/cli/cfg.php); Skill aktiv, in Registry bekannt, Cap vorhanden.
- [x] Skill-Katalog-Embeddings neu gebaut (`rebuild_skill_catalog_embeddings_adhoc`: status=written, 32 Skills, `course.add_activity` im Katalog).
- [x] End-to-End-Rauchtest GRÜN (echtes LLM): „erstelle eine Seite" → `course.add_activity` → `confirmation_request` → confirm → Page real in Section 0 angelegt + verifiziert (mod/page/view.php), Kurs danach aufgeräumt.

### P6 — (separat, späterer Schritt) `course.add_quiz`
- [ ] Dedizierter Skill auf demselben `activity_creation_service`, der den Quiz-Intent-Workflow kennt
      (Fragen referenzieren). **Nicht** Teil dieses Tickets (§7 harte Grenze).

---

### Definition of Done (dieses Tickets, P0–P5)
- [x] Whitelist-Module lassen sich per Chat anlegen — live verifiziert für `page` (Selektion → Confirm → reale Anlage); übrige Whitelist über Per-Modul-mform-Tests gepinnt.
- [x] Keine einzige Engine-Datei geändert (Grep-Guard `engine_cleanliness_activity_test` grün, 42 Assertions).
- [x] PHPUnit-Suite grün inkl. read-only-Preflight-Beweis und Rollback-Test (26 Tests / 149 Assertions, 0 Moodle-Warnings).
- [x] Skill default-off ausgeliefert, per Setting aktivierbar (Discoverbarkeit nach Embeddings-Rebuild = P5, offen).
```
