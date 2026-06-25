# Neue Skills hinzufügen — Ist-Analyse am Beispiel „PDF → Moodle-Fragen"

**Datum:** 2026-06-09
**Status:** Reine Ist-Analyse. **Keine Code-Änderung.** Grundlage für eine spätere
Umsetzungsentscheidung.
**Scope:** Was ist nötig, um dem wizard weitere Skills zu geben — generell und konkret
am Beispiel „erzeuge Moodle-Fragen aus einem hochgeladenen PDF". Mit Blick auf die
bestehende **Family-Discovery** und die geplante **Content-/Context-Spezifik** (siehe
Blueprints `wizard_local_plugin_context_decoupling_analysis_2026-06-08.md`,
`skill_catalog_planner_analysis.md`, `docs_lookup_skill_analysis_2026-06-08.md`).

---

## 1. Kernaussage (TL;DR)

- **Einen Skill anzulegen ist mechanisch billig.** Eine Klasse, die von `base_skill`
  (bzw. `core_skill_base`) ableitet, wird durch die **Family-Discovery automatisch
  gefunden** — kein Eintrag in einer Registry-Liste nötig. Der teure Teil ist nicht das
  „Anlegen", sondern die **Sichtbarkeit für den Selector** und die **Wirkung** (was der
  Skill schreibt).
- **Pflicht-Schritt, sonst unsichtbar:** Nach dem Hinzufügen muss der Embeddings-Katalog
  neu gebaut werden (`family_embeddings_index_service::rebuild_catalog($registry)`), sonst
  findet der Selector / `core.search_skills` den neuen Skill semantisch nicht. Was der
  **Selector** sieht, ist ausschließlich `description` + `message_triggers` + `intent`
  (nicht `get_schema()`, nicht die Guidance-Packs — siehe
  [project-agent-skill-discovery-visibility] und [project-agent-guidance-injection]).
- **Für den PDF-Fall ist die halbe Miete schon da:** Upload, Token, **PDF-Textextraktion
  und Injektion in den Prompt** existieren bereits (`attachment_processor`,
  `pdf_text_extractor`). Der PDF-Text steht beim Planen also **schon im Kontext** — der
  Token wird sofort verbraucht. Ein textbasierter „PDF → Fragen"-Skill braucht **kein**
  neues File-Plumbing.
- **Der eigentliche neue Aufwand** liegt (a) im **Anlegen der Fragen** und (b) in der
  **Context-Frage**.
  - Zu (a): **Gewählter Weg = Moodle-Import-API**, nicht direktes `core_question`-Schreiben.
    Der Skill erzeugt eine **syntaktisch korrekte Importdatei** (GIFT oder Moodle-XML) und
    lässt sie von `qformat_*::importprocess()` einlesen. Das ist deutlich einfacher und
    robuster als die Fragebank-Internas direkt zu bedienen — Moodle übernimmt Validierung,
    Qtype-Erzeugung und Kategorie-Einhängung.
  - Zu (b): Die Fragebank hängt am **Kurs-/Modul-Context**, der Agent ist aber hart auf den
    **Booking-Modul-Context** gegated. **Diese Context-Frage wird bewusst später gelöst**
    (Decoupling); im MVP wird in den **Kurs des Booking-Moduls** importiert. Damit bleibt
    „PDF → Fragen" trotzdem das Paradebeispiel für einen Skill, der **nicht zu Booking
    gehört** und mittelfristig die Context-Generalisierung braucht.
- **Empfehlung der Einordnung:** „PDF → Fragen" ist ein **content-/context-spezifischer
  Engine-Skill**, kein Booking-Skill. Er sollte an der **Contract-Surface** (interfaces +
  dto + base_skill) hängen und perspektivisch im `local_wizard`-Engine (bzw. als eigener
  Provider) leben — nicht in `mod_booking`.

---

## 2. Zielbild & Scope

Georg will dem Agent „mehr Skills" geben; der konkrete Wunsch ist ein Skill, der aus einem
hochgeladenen PDF Moodle-Fragen erzeugt. Diese Analyse beantwortet zwei Ebenen:

1. **Generisch:** Was ist der vollständige Weg, einen neuen Skill „erstklassig" (sichtbar,
   auswählbar, korrekt klassifiziert, mit Preview/Confirm) in den Agent zu bringen?
2. **Konkret:** Was fehlt für „PDF → Moodle-Fragen" auf Basis des heutigen Codes?

Ausdrücklich mitgedacht: die **Family-Discovery** (wie Skills gefunden/gerankt werden) und
die **Content-/Context-Spezifik** (Kurs-/System-Ebene statt nur Booking-Modul), wie in den
Blueprints angelegt.

---

## 3. Ist-Zustand — Anatomie eines Skills

### 3.1 Vertrag (was ein Skill implementieren MUSS)

Basisklassen:
`classes/local/wizard/base_skill.php` → Core-Skills zusätzlich über
`classes/local/wizard/core/skills/core_skill_base.php`.

Pflicht-/Vertragsmethoden (aus `skill_interface` / `base_skill`):

| Methode | Zweck |
| --- | --- |
| `get_name(): string` | Voll qualifizierter Skill-Name, Namespace-Präfix (`core.*`, `mod_booking.*`, künftig z. B. `core.generate_questions`). |
| `get_schema(): array` | Eingabe-Schema (properties: `string` / `integer` / `array` / `boolean`). **Nur in der Construction-Phase sichtbar.** |
| `execute(array $input, int $contextid, int $userid): array` | Wirkung + `observation_full` + `usermessage`. |
| `check_structure(array $input): array` | **Reine** Strukturvalidierung, **kein** DB-Zugriff (Trust-Boundary im `interpreter`). |
| `preflight(...)` → `preflight_result_v2` | Tiefe Validierung inkl. DB; liefert `prepared_input`. (Readonly-Default in `core_skill_base`.) |
| `get_example_input(): array` | Beispiel-Parameter — nur Construction-sichtbar. |
| `get_prompt_contract()` / `get_risk_class()` / `is_read_only()` | Contract-Metadaten, Risikoklasse, Auto-Exec-Fähigkeit. |
| `get_message_triggers()` | Intent-Signale — **Selector-sichtbar**. |
| `get_contextual_prompt_packs()` | Guidance — speist **nur** Embeddings-Katalog, **nicht** den Live-Prompt automatisch (siehe [project-agent-guidance-injection]). |
| `get_result_preview(...)` (optional) | Preview als Daten (`type/html/payload`) — siehe Preview-API-Blueprint. |

**Wichtig (Sichtbarkeitsasymmetrie):** Der **Selector** (Phase Selection) sieht nur
`description` + `message_triggers` + `intent`. `get_schema()`, `get_example_input()` und die
Guidance-Packs sind **erst in der Construction-Phase** sichtbar. Capabilities, die der
Selector kennen soll, **müssen** in `description`/`message_triggers` stehen — sonst wird der
Skill nie gewählt.

### 3.2 Family-Discovery (Registrierung ohne Liste)

- **Provider-Modell:** `skill_registry::make_default()` iteriert
  `core_component::get_component_names()` und lädt aus jeder Komponente die Klasse
  `\{component}\local\wizard\skill_provider`. Existiert keiner, greift eine
  **provider-lose Fallback-Discovery**.
  Datei: `classes/local/wizard/skill_registry.php` (`make_default()` ~Z. 641 ff.).
- **Discovery-Scan:** `skill_discovery::get_skill_instances($component)` scannt
  `{plugindir}/classes/local/wizard/**` rekursiv und instanziiert jede Skill-Klasse
  (`classes/local/wizard/skill_discovery.php` ~Z. 44 ff.).
  → **Eine neue Datei im richtigen Namespace genügt; kein manueller Registry-Eintrag.**
- **Provider heute:** `bookingextension_agent` (eigener `skill_provider.php`,
  Component `bookingextension/agent`). Es gibt bereits einen **Nicht-Booking-Provider**
  (`local_entities`) als Beleg, dass Fremd-Plugins Skills beisteuern können.
- **Validierung bei Registrierung:** `skill_registry::register()` prüft Namespace-Reservierung,
  Dubletten und baut/validiert Contract-Metadaten via `skill_contract_validator`.

### 3.3 Katalog & Embeddings (Sichtbarkeit/Ranking)

- **Selection-Katalog ist „slim":** `adaptive_skill_catalog_service` reduziert die volle
  Registry auf einen kompakten Routing-View (mandatory + recency, Top-N). Konstante
  `ALWAYS_INCLUDE_SKILL_NAMES` erzwingt immer-sichtbare Skills (heute u. a.
  `core.search_skills`).
  Datei: `classes/local/wizard/services/catalog/adaptive_skill_catalog_service.php`.
- **Auswählbarkeit ≠ Slim-Katalog:** `allowed_skills` = volle Registry-for-context. Ein per
  `core.search_skills` **entdeckter** (registrierter) Skill ist im Folge-Turn wählbar,
  auch wenn er nicht im Slim-Katalog stand (siehe [project-agent-skill-discovery-visibility]).
- **Embeddings-Pipeline:** `embeddings_catalog_builder_service::build_full_catalog_rows()`
  baut pro Skill eine Zeile aus `intent/description/minimal_input/example_input/`
  `message_triggers/contextual_prompt_packs` + Content-Hash;
  `family_embeddings_index_service::rebuild_catalog()` embeddet inkrementell (Reuse bei
  unverändertem Hash) in den CSV-Katalog. Modell laut Blueprints `text-embedding-3-small`,
  1536 Dim., Top-K=6.
- **PFLICHT nach neuem Skill:** `rebuild_catalog($registry)` ausführen
  (`core.recreate_skill_catalog` queued das async, R2), sonst ist der Skill für
  Semantik-Suche/Discovery unsichtbar.

### 3.4 Risikoklassen

`dto/skill_risk_class.php`: `R0=read_only`, `R1=scoped_write`, `R2=broad_write`,
`R3=irreversible_or_external`. Deklaration im Konstruktor
(`base_skill::__construct($readonly, $riskclass)`). R0 ist auto-exekutierbar; ab R1 greifen
Confirm-/TTL-/Retry-Gates (Risk-Class-Framework, ROADMAP P0).

### 3.5 Lebenszyklus eines Skill-Calls (Kurzform)

1. **Selection:** Planner wählt Skill-Namen (Slim-Katalog + ggf. Embeddings/`search_skills`).
2. **Parameter-Construction:** Planner baut `input` (jetzt sind `schema`, `example_input`,
   Guidance des **gewählten** Skills sichtbar — via
   `orchestrator::enrich_construction_catalog_entry()`).
3. **Interpreter (Trust-Boundary):** JSON-Parse → Klassifizierung → `check_structure()`
   (rein) → Normalisierung.
4. **preflight():** tiefe Validierung (DB) → `prepared_input`.
5. **execute():** Wirkung + `observation_full`.
6. **Confirm/Preview** je nach Risikoklasse; Observation geht in den nächsten Turn.

---

## 4. Ist-Zustand — Datei-/PDF-Pipeline (vieles existiert schon)

> Wichtig fürs Scoping: Der „Datei-rein"-Teil ist für **PDF** bereits gelöst.

- **Upload-UI:** `templates/aiinstructions.mustache` (Paperclip) akzeptiert bereits
  `image/*` **und** `application/pdf`; `amd/src/aiinstructions.js` lädt als Data-URL hoch.
- **Webservice:** `classes/external/ai_upload_attachment.php` validiert MIME serverseitig
  via `finfo()` (Whitelist inkl. `application/pdf`), Größenlimits (Bild 10 MB, PDF 20 MB),
  schreibt in `make_temp_directory('bookingextension_agent/uploads')`.
- **Token:** `services/attachment/attachment_token_service.php` legt einen opaken Token im
  Application-Cache ab (TTL **30 min**, `db/caches.php`), Metadaten:
  `userid/contextid/path/mime/filename/expires`.
- **Injektion:** `services/attachment/attachment_processor.php::augment_message()`:
  - **PDF:** Text wird via `pdf_text_extractor` extrahiert und als
    `--- DOCUMENT: <name> --- … --- END DOCUMENT ---` **vor** die User-Nachricht gesetzt;
    **Token wird sofort invalidiert** (`attachment_processor.php:79-89`). Extraktion:
    poppler `pdftotext` (schneller Pfad) ▸ **gebundeltes pure-PHP `smalot/pdfparser`**
    (`thirdparty/pdfparser/`, LGPL-3.0, via `thirdpartylibs.xml` + lazy PSR-4-Autoloader)
    als dependency-freier Fallback; **Cap 15 000 Zeichen**, **kein OCR** (Scan-PDFs → leer).
    → Damit ist die Extraktion auf jeder Moodle-Instanz **out-of-the-box** verfügbar
    (Entscheidung 2026-06-09; siehe `architecture/01-entry-and-web-services.md` §7).
  - **Bild:** nur Text-Hinweis mit Token; **Token bleibt am Leben**, damit ein Skill ihn via
    `attachment_token_service::resolve()` auflösen kann (heute der einzige Pfad, über den ein
    Skill an die **Roh-Datei** kommt).

**Konsequenzen für „PDF → Fragen":**

- Der **PDF-Text liegt beim Planen bereits im Kontext** (`--- DOCUMENT ---`-Block). Ein
  textbasierter Generierungs-Skill braucht **keinen** File-Zugriff und **kein** neues
  Schema-Feld „file".
- **Grenzen, die der Skill kennen muss:** 15 000-Zeichen-Cap (lange PDFs werden
  abgeschnitten); reine Textextraktion (kein OCR, keine Bilder/Formeln/Layout); Token ist
  nach Injektion weg → **kein** Re-Processing der Originaldatei.
- Falls künftig **Roh-PDF** gebraucht wird (OCR, eingebettete Bilder, seitengenaue
  Generierung): dann fehlt das, was Agent 2 als Lücke notiert hat — PDF dürfte **nicht**
  sofort invalidiert werden, bzw. die Datei müsste in `file_storage` (Draft/eigener Bereich)
  statt nur Temp persistiert werden. **Für den ersten Wurf: bewusst out of scope.**

---

## 5. Fallstudie — Skill „PDF → Moodle-Fragen generieren"

### 5.1 Was der Skill tun muss (Soll-Ablauf)

1. **Eingabe:** Der PDF-Text steht bereits im Kontext (s. o.). Skill-Parameter beschreiben
   nur **wie** generiert wird: Zielkurs/-context, Fragetypen, Anzahl, Sprache, Schwierigkeit,
   Ziel-Fragenkategorie.
2. **Generierung:** LLM-Call (vorhandener `llm_call_service`) erzeugt die Fragen **direkt im
   Importformat** — bevorzugt **GIFT** (kompakt, gut „LLM-schreibbar"), alternativ
   **Moodle-XML** (mächtiger, aber geschwätziger). Keine eigene JSON-Zwischenschicht nötig.
3. **Persistenz via Import-API:** Importdatei in einen Temp-Pfad schreiben, passenden
   `qformat_*` (z. B. `qformat_gift`) instanziieren, Ziel-`question_category`/Context/Course
   setzen und `importprocess()` ausführen. Moodle erledigt Parsing, Validierung und das
   Anlegen der Qtypes. Heute berührt **weder Booking noch der Agent** die Fragebank → neuer,
   aber **flacher** Integrationspunkt (Datei bauen → importieren).
4. **Preview/Confirm:** Vor dem Schreiben Preview (Liste der Fragen) + Confirm; nach dem
   Schreiben deterministische Verifikation (siehe [project-agent-post-mutation-verification]).
5. **Observation:** IDs/Links der erzeugten Fragen (Question-Bank-URL).

### 5.2 Der harte Teil ist der Context, nicht die Generierung

- Eine `question_category` hängt an einem **Context** — typischerweise **Kurs-Context**
  (`CONTEXT_COURSE`) oder Modul-Context eines Aktivitätsmoduls (z. B. Quiz), **nicht** am
  Booking-Modul.
- Der Agent ist heute **hart auf den Booking-Modul-Context** gegated:
  `authorization_service::require_booking_module_context()` verlangt `context_module` +
  `get_coursemodule_from_id('booking')`; die Entry-Points (`ai_send_message`,
  `ai_poll_thread`) lösen `cmid` booking-spezifisch auf
  (siehe `wizard_local_plugin_context_decoupling_analysis_2026-06-08.md`).
- **Damit ist „PDF → Fragen" der Beweis-Use-Case für die Context-Generalisierung:** Der Skill
  ist seiner Natur nach **kurs-/content-spezifisch**, nicht booking-spezifisch. Solange der
  Agent nur im Booking-Modul lebt, kann er bestenfalls in den **Kurs** des Booking-Moduls
  schreiben (Kurs-Context aus `cmid` ableitbar) — aber das ist eine Krücke. Sauber wird es
  erst mit `require_valid_context()` + kontext-agnostischen Entry-Points (Decoupling-Plan).

### 5.3 Risikoklasse, Preview, Capability

- **Risk:** Schreiben in die Fragebank ist mindestens **R2 (broad_write)**, eher **R3**, wenn
  man „viele Fragen auf einmal" als schwer reversibel wertet → **Confirm zwingend**, kein
  Auto-Exec.
- **Capability:** Schreiben erfordert `moodle/question:add` im Ziel-Context — zusätzlich zur
  Agent-Nutzungs-Capability. Preflight muss das prüfen (nicht nur die Agent-Capability).
- **Preview:** Neuer Preview-Type (z. B. `generated_questions`) über die generische
  Preview-API (`get_result_preview` liefert Daten; Renderer/Registry generisch) — passt zur
  Stoßrichtung in `preview_api_analysis_2026-06-08.md`.

### 5.4 Schema-Skizze (nur illustrativ, kein Code)

```text
Skill: core.generate_questions   (R2/R3, readonly=false)
description:  "Generate Moodle quiz questions from an uploaded document/PDF text and
               save them into a course question bank category."   // Selector-sichtbar!
message_triggers: ["generate questions from this pdf", "erstelle Fragen aus dem Dokument", ...]
properties:
  targetcourseid   : integer  (optional; default = Kurs des aktuellen Context)
  questioncategoryid: integer (optional; sonst Default-Kategorie des Context anlegen/nutzen)
  qtypes           : array<string>  ["multichoice","truefalse","shortanswer"]
  count            : integer  (z. B. 5, mit Obergrenze)
  difficulty       : string   ("easy"|"medium"|"hard")
  outputlang       : string
  // KEIN "file"-Feld: der PDF-Text kommt aus dem --- DOCUMENT ----Block im Kontext.
```

### 5.5 Konkrete To-dos für diesen Skill (Bauliste)

1. **Skill-Klasse** unter dem Engine-Skill-Namespace (Contract-Surface-only Imports).
2. **`description` + `message_triggers`** so formulieren, dass der **Selector** „PDF/Dokument
   → Fragen" trifft (Capability auf Selection-Ebene sichtbar machen).
3. **Generierungs-Service** (LLM → **GIFT/XML-Importdatei**) — wiederverwendet `llm_call_service`;
   Prompt erzwingt syntaktisch gültiges Importformat (+ leichte Syntax-Validierung vor Import).
4. **Import-Service** über die Moodle-Import-API: Temp-Datei schreiben, `qformat_gift`
   (bzw. `qformat_xml`) konfigurieren (Kategorie/Context/Course), `importprocess()` aufrufen,
   Ergebnis (erzeugte IDs) einsammeln — sauber gekapselt in einem Service, nicht im Skill-Body.
5. **preflight():** Ziel-Context + `moodle/question:add` prüfen; PDF-Text-Präsenz prüfen;
   `prepared_input` bauen.
6. **Preview-Type** `generated_questions` + Confirm; **Post-Mutation-Verifikation**.
7. **Risk-Class** R2/R3 setzen.
8. **Embeddings-Rebuild** (`family_embeddings_index_service::rebuild_catalog`).
9. **Tests** (Contract + Strukturvalidierung + Writer).
10. **Context-Entscheidung:** vorerst Kurs des Booking-Moduls als Ziel, oder auf
    Decoupling warten (siehe §7/§8).

---

## 6. Verallgemeinerung — Checkliste „neuen Skill hinzufügen"

1. Klasse von `base_skill`/`core_skill_base` ableiten, im **richtigen Namespace**
   (`\{component}\local\wizard\…`) ablegen → **Auto-Discovery**.
2. `get_name()` mit sauberem Namespace-Präfix; **keine** Engine-Internas importieren — nur
   **Contract-Surface** (interfaces + dto + base_skill).
3. **Selector-Sichtbarkeit:** Capability klar in `description` + `message_triggers`.
4. `get_schema()` + `get_example_input()` für die Construction-Phase.
5. `check_structure()` rein halten (keine DB), tiefe Checks in `preflight()`.
6. **Risk-Class** korrekt deklarieren; ab R1 Confirm/Preview einplanen.
7. Optional **Preview** über die generische Preview-API als Daten.
8. **Embeddings-Katalog neu bauen** — sonst unsichtbar für Discovery/`search_skills`.
9. Tests (Contract + Strukturvalidierung + Wirkung).
10. Bei Bedarf `ALWAYS_INCLUDE_SKILL_NAMES` nur für echte „immer sichtbar"-Fälle.

---

## 7. Einordnung in die Zukunftsrichtung (content-/context-spezifisch)

- **Gehört in die Engine, nicht in Booking.** „PDF → Fragen" hat mit Buchungen nichts zu tun.
  Es ist der erste klare **content-spezifische** Skill und damit ein Kandidat für das
  künftige `local_wizard`-Engine bzw. einen **eigenen Provider** (Family-Discovery findet
  ihn unabhängig vom Plugin). Solange er in `bookingextension_agent` lebt, muss er trotzdem
  **nur** an der Contract-Surface hängen, damit die spätere Auskopplung ihn nicht zurück in
  die Engine zieht (siehe `wizard_local_plugin_extraction_plan_2026-06-08.md`,
  [project-wizard-local-plugin-extraction]).
- **Treiber für die Context-Generalisierung.** Der Skill macht den im Decoupling-Blueprint
  beschriebenen Bedarf **akut**: `require_booking_module_context()` →
  `require_valid_context()`, kontext-agnostische Entry-Points, `context_name` statt
  `booking_name`. Ohne das bleibt der Zielkontext (Kurs-Fragebank) eine Krücke.
- **Discovery skaliert mit.** Je mehr content-spezifische Skills, desto wichtiger werden die
  im `skill_catalog_planner_analysis.md` beschriebenen **Discovery-Stages** (Context+Core →
  Nachbardomänen → globaler Slim) und das Kollisions-/Debug-Tooling
  (`skill_selection_debug_tool_blueprint.md`), um Selektions-Kollisionen neuer Skills zu
  erkennen.
- **Preview/Memory-Muster** sind bereits generisch genug (Preview-Daten-Contract,
  User-Memory) — neue Skills docken ohne Sonderpfade an.

---

## 8. Entscheidungen & offene Fragen

**Gesetzt (2026-06-09):**
- **Persistenz = Moodle-Import-API** (`qformat_*::importprocess()`), **kein** direktes
  `core_question`-Schreiben. Begründung: einfacher, robust, Moodle validiert/erzeugt selbst.
- **Context-Frage wird später gelöst.** MVP importiert in den **Kurs des Booking-Moduls**
  (Kurs-Context aus `cmid`); kurs-/systemweit kommt mit der Context-Decoupling.

**Offen:**
1. **Importformat GIFT vs. Moodle-XML.** GIFT ist kompakt und für ein LLM leicht korrekt zu
   schreiben (gut für multichoice/truefalse/shortanswer); Moodle-XML deckt mehr Qtypes/Medien
   ab, ist aber geschwätziger und fehleranfälliger zu generieren. **Tendenz:** GIFT als MVP.
2. **Nur Text oder auch Roh-PDF?** MVP = nur extrahierter Text (15 000-Zeichen-Cap
   akzeptieren). Roh-PDF/OCR/seitengenau = späterer Ausbau, erfordert Änderung an
   `attachment_processor` (PDF-Token nicht sofort invalidieren) + `file_storage`-Persistenz.
3. **Lange Dokumente:** Chunking/Map-Reduce über den 15 000-Zeichen-Cap hinaus? Oder bewusst
   „erste N Zeichen" mit klarer User-Meldung?
4. **Import-Robustheit:** Umgang mit teilweise ungültiger LLM-Importdatei — Vorab-Validierung
   + gezielter Retry der fehlerhaften Fragen, oder „alles oder nichts"? (`importprocess()`
   meldet Fehler pro Frage.)
5. **Risk R2 vs. R3** für Bulk-Anlage vieler Fragen — beeinflusst Confirm-/Reversibilitäts-UX.
6. **Plugin-Heimat:** Skill schon jetzt in einen eigenen Provider/Plugin legen oder vorerst in
   `bookingextension_agent` (Contract-Surface-only) und mit der Engine migrieren?

---

## 9. Referenzen

**Code (Ist-Zustand):**
- Family-Discovery: `classes/local/wizard/skill_registry.php` (`make_default()`),
  `classes/local/wizard/skill_discovery.php`, `classes/local/wizard/skill_provider.php`.
- Basis/Contract: `classes/local/wizard/base_skill.php`,
  `classes/local/wizard/core/skills/core_skill_base.php`, `dto/skill_risk_class.php`.
- Katalog/Embeddings: `services/catalog/adaptive_skill_catalog_service.php`
  (`ALWAYS_INCLUDE_SKILL_NAMES`), `services/embeddings/embeddings_catalog_builder_service.php`,
  `services/embeddings/family_embeddings_index_service.php`,
  `core/skills/search_skills_skill.php`.
- Datei/PDF: `templates/aiinstructions.mustache`, `amd/src/aiinstructions.js`,
  `classes/external/ai_upload_attachment.php`,
  `services/attachment/attachment_token_service.php`,
  `services/attachment/attachment_processor.php` (PDF-Konsum Z. 79-89),
  `services/attachment/pdf_text_extractor.php` (Cap 15 000).
- Fragebank-Ziel (neu, **Import-API**): `question/format.php` (`qformat_default::importprocess()`,
  `readdata()`, `importpreprocess()`, `importpostprocess()`), Importer unter `question/format/gift/`
  bzw. `question/format/xml/`.

**Blueprints:**
- `wizard_local_plugin_context_decoupling_analysis_2026-06-08.md` (Context-Gate, Decoupling).
- `wizard_local_plugin_extraction_plan_2026-06-08.md` (Contract-Surface, Provider, Migration).
- `skill_catalog_planner_analysis.md` (Slim-Katalog, Discovery-Stages, Embeddings).
- `skill_selection_debug_tool_blueprint.md` (Kollisions-/Selektions-Debugging neuer Skills).
- `docs_lookup_skill_analysis_2026-06-08.md` (Muster: neuen Skill + Embeddings korrekt anbinden).
- `preview_api_analysis_2026-06-08.md` (generische Preview für neue Skills).
- `user_specific_memory_blueprint_2026-06-09_corrected.md` (Muster: Skill + Rebuild-Pflicht).
- `ROADMAP.md` (Risk-Class-Framework, Context-Awareness, Bild-/Multimodal-Input).

**Memory:** project-agent-skill-discovery-visibility, project-agent-guidance-injection,
project-agent-post-mutation-verification, project-wizard-local-plugin-extraction.
