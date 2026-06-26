# Blueprint: Kurs-Indizierung & kurs-skopierte semantische Suche (`course.*`)

*Stand: 2026-06-25 · Status: Analyse / Blueprint — KEINE Umsetzung. Nachfolger/Konkretisierung von
`obsolet/todo/semantische_site_suche_embeddings_adapter_2026-06-10.md` (Site-weite Variante, WS7).*

## 0. Idee in einem Satz

Statt einer Site-weiten Vektorsuche (braucht echten Vektor-Store) bauen wir die Suche **pro Kurs**:
ein Skill `course.index_course` indiziert *einen* Kurs vollständig (alle Inhalte bis hinunter zu
Dateien/PDFs und Buchungsoptionen), legt ihn als **eigenen Korpus** ab; `course.search_course` durchsucht
genau diesen einen Kurs semantisch; ein optionales `course.search_site` iteriert nacheinander über alle
registrierten Kurs-Korpora. Die **Indizierung sieht alles**, das **Retrieval filtert pro User** über
Moodles eigene Zugriffsprüfung zurück.

Dieser Zuschnitt ist die pragmatische Brücke, bis ein echter Vektor-Store da ist: er vermeidet sowohl die
Riesen-CSV als auch das site-weite Skalierungsproblem, indem er den Korpus auf Kursgröße shardet
(~10²–10⁴ Chunks/Kurs → lineares Cosine mit Streaming-Top-K reicht).

---

## 1. Was schon da ist (der Hebel — nicht neu bauen)

### 1.1 Embeddings-/Retrieval-Infrastruktur (wiederverwenden)
- **`services/embeddings/embeddings_retrieval_service`** — `search_top_k()` und vor allem
  `search_top_k_streaming()` (Min-Heap, Speicher O(k) statt O(Korpus); droppt den Vektor sofort nach dem
  Scoren). Genau das Richtige für Per-Kurs-Korpora.
- **`services/embeddings/vector_math::cosine_similarity()`** — kanonische Cosine-Implementierung.
- **`embeddings_csv_repository_base`** — atomarer, round-trip-verifizierter CSV-Write, **Variant-Keying**
  (Modell+Dimensionen → eigene Datei, Modellwechsel invalidiert die anderen nicht),
  `build_key_offset_index()` / `read_row_at()` / Stream-Write für inkrementelle, speicherarme Rebuilds.
- **`services/lookup/docs_embeddings_index_service` + `docs_embeddings_csv_repository` +
  `docs_corpus_registry`** — das **direkte Vorbild**: Multi-Korpus-Index mit `corpus_id`, Content-Hash-Dedup,
  nicht-destruktivem Prune, `markdown_chunker`-Chunking, Readiness-Gate. Der Kurs-Index ist im Kern
  „dasselbe Muster, aber `corpus_id = course-{id}` und die Quelle sind Moodle-Search-Areas statt .md-Dateien".
- **`llm_call_service::invoke_embeddings_for_context()`** — kontext-/userbezogener Embeddings-Call
  (Wunderbyte `generate_embeddings`), inkl. Debug-Logging.

### 1.2 Moodle Core-Search (die eigentliche Content-Quelle + Sicherheits-Maschinerie)
- **Search-Areas** (`\core_search\base`-Subklassen): `get_document_recordset($modifiedfrom, $context)`
  liefert pro Area zugriffskontrollierte `\core_search\document`-Chunks (`title`, `content`,
  `description1/2`, `contextid`, `courseid`, `owneruserid`, `modified`).
  `get_document_recordset()` **akzeptiert einen `\context`** → wir können die Iteration sauber **auf einen
  Kurs(-Kontext) einschränken**.
- **`check_access($id)` → `ACCESS_GRANTED|DENIED|DELETED`** je Area: das ist der **Per-User-Filter** beim
  Retrieval (Make-or-Break, s. §4).
- **`\core_search\manager::get_search_areas_list(true)`** — Liste der aktiven Areas.
- **`get_areas_user_accesses($limitcourseids, $limitcontextids)`** — erlaubte Kontexte je User; nützlich für
  die Vorfilterung bei `search_site` und als grobe Schranke.
- **Dateien/PDF**: `\core_search\base::attach_files()` / `document::get_files()` liefern die `stored_file`-Anhänge.
  Den Volltext extrahieren wir **selbst, in-process** — siehe 1.4 (kein Solr/Tika nötig).

### 1.4 PDF-/Datei-Textextraktion — schon vorhanden (keine offene Abhängigkeit!)
Das Plugin bringt einen fertigen Service mit:
`services/attachment/pdf_text_extractor` über das gebündelte `thirdparty/pdfparser` (`Smalot\PdfParser`,
pures PHP, selbst-registrierender Autoloader — **kein Composer/Solr/Tika erforderlich**):
- `is_available()` — prüft, ob `pdftotext` (Shell) ODER der gebündelte Parser da ist (Dual-Backend).
- `extract(string $filepath): string` — Volltext, inkl. Truncation.

Bereits genutzt von `services/attachment/attachment_processor`. → **Der Kurs-Index ruft genau diesen
Service** auf den `stored_file`-Anhängen der Search-Dokumente; „bis hinunter zu PDFs" ist damit ab v1
realistisch, ohne Infra-Annahmen.

### 1.3 Skill-/Capability-Gerüst (so „darf" der Skill überhaupt etwas)
- Skills liegen in `classes/local/wizard/course/skills/` (Ordner == Namespace `course.*`).
  Bestehende Nachbarn: `search_courses_skill`, `analyze_course_structure_skill`,
  `course_structure_service` (re-implementiert `has_capability` *nie*, surfaced nur was der User darf).
- **Gate 1 (immer, nicht umgehbar):** Die Engine leitet aus dem Skill-Namen die Capability
  `<component>:skill_<normalized_name>` ab und erzwingt sie — *unabhängig* von den deklarierten Metadaten
  (`skill_executability_evaluator::has_required_capabilities()`). → `bookingextension/agent:skill_index_course`
  etc. müssen in `db/access.php`, defaultmäßig nur Teacher/Manager für den Index-Skill.
- **Gate 2 (native Rechte am Operating-Context):** `base_skill::get_required_native_capabilities()` +
  `require_native_capabilities($operatingcontext, $userid)`. „Der Agent gewährt nie ein Recht, das der User
  nativ nicht hat."
- **Cross-Context:** `supports_target_context()` + `get_target_selector()` lösen einen *genannten* Zielkurs
  auf und prüfen Gate 2 dort. Für „indiziere **diesen** Kurs" und „suche in **Kurs X**" genau der Mechanismus.

---

## 2. Kernentscheidung: ein Korpus pro Kurs (Sharding statt Vektor-DB)

| | Site-weite CSV (verworfen) | **Korpus pro Kurs (dieser Blueprint)** | Echter Vektor-Store (Zukunft) |
|---|---|---|---|
| Speicher/Scan | 1 Riesendatei, linearer Scan über alles | 1 CSV/Kurs, Scan nur über den 1 Kurs | DB/ANN |
| Skalierung | bricht bei 10⁵–10⁷ | gut bis ~10⁴/Kurs, site-wide = Summe der *indizierten* Kurse | beliebig |
| Re-Index | alles oder kompliziert | pro Kurs unabhängig (re-index 1 Kurs) | inkrementell |
| Aufwand | mittel | **niedrig** (Docs-Muster wiederverwenden) | hoch (Infra) |

**Mapping auf das vorhandene Docs-Muster:** `corpus_id = "course-{courseid}"`. Eine
`docs_embeddings_csv_repository`-analoge `course_index_csv_repository` schreibt pro Kurs eine Variant-Datei.
Damit kommt Content-Hash-Dedup, atomarer Write, Streaming-Retrieval **gratis** mit.

**Registry der indizierten Kurse** — bewusst eine eigene DB-Tabelle (nicht nur „welche CSV-Dateien
existieren"), weil wir Status/Metadaten brauchen:

```
local_wizard_course_index            -- ein Datensatz pro indiziertem Kurs
  id, courseid, contextid,
  status ('building'|'ready'|'stale'|'error'),
  chunkcount, lastindexed (timestamp), lastindexedby (userid),
  embedding_model, embedding_dimensions,   -- welche Variante wurde gebaut
  areas_included (text/JSON: welche Search-Areas + booking),
  contenthash_summary (für „stale?"-Heuristik), errormsg
  UNIQUE(courseid)
```

Die **Chunks/Vektoren** selbst bleiben in der CSV-pro-Kurs-Datei (kein BLOB in der DB, konsistent zur
Docs-Strategie). Die Tabelle ist nur das *Register* + Statusboard für `search_site` und die Admin-Übersicht.

---

## 3. Die drei Skills

### 3.1 `course.index_course` — „Indiziere diesen Kurs" (mutierend, mit echten Rechten)
- **Risk-Klasse:** R2/R3 (schreibt Index, kostet Embeddings-Geld, ggf. Confirm). `readonly=false`.
- **Gate 1:** `bookingextension/agent:skill_index_course` — default nur `editingteacher`/`manager`.
- **Gate 2:** `get_required_native_capabilities()` → z. B. `moodle/course:viewhiddensections` +
  `moodle/course:update` (begründung: nur wer den Kurs *vollständig inkl. versteckter Inhalte* sehen darf,
  darf einen Volltext-Index bauen, der genau diese versteckten Inhalte enthält). `supports_target_context()=true`,
  Target-Selector löst den Kurs-Kontext auf; Gate 2 wird **dort** geprüft.
- **Was es tut:**
  1. Kurs-Kontext auflösen (Cross-Context-Resolver).
  2. Aktive Search-Areas (kuratierte Whitelist, §5) via `get_document_recordset($since, $coursecontext)`
     iterieren — **als „Indizierer-Sicht" (admin/teacher), bewusst ohne Per-User-Filter**, denn wir wollen den
     *vollständigen* Korpus. Jedes Dokument: `areaid`, `docid`, `contextid` (cmid-Kontext), `owneruserid`
     mitschreiben — diese Metadaten sind später der Schlüssel zur Rückfilterung.
  3. Buchungsoptionen separat einsammeln (§7).
  4. Datei-Anhänge: Text extrahieren (§6).
  5. Chunken (`markdown_chunker` bzw. ein Text-Chunker, ~500–800 Tokens, Overlap), Content-Hash je Chunk,
     nur Geänderte neu embedden, Upsert in `course-{id}`-CSV (inkrementell, nicht-destruktiv wie Docs).
  6. `local_wizard_course_index`-Datensatz auf `ready` + Metadaten setzen.
- **Trigger/Preview:** „indiziere diesen Kurs", „mach Kurs X durchsuchbar"; Preview = Fortschritt/Spinner
  (vgl. oneclick js_module + Polling) — Indizierung als Adhoc-Task im Hintergrund, Skill startet + meldet
  Status; Re-Poll über die Registry-Tabelle.

### 3.2 `course.search_course` — „Suche in diesem (indizierten) Kurs" (read-only, **Per-User-Filter!**)
- **Risk-Klasse:** R0, `readonly=true`.
- **Gate 1:** `bookingextension/agent:skill_search_course` — darf breit sein (auch Studenten), DENN die
  inhaltliche Sicherheit kommt **nicht** aus Gate 1, sondern aus dem Per-Treffer-Filter unten. Gate 1 regelt
  nur „darf diese Person den Such-Skill *aufrufen*", nicht „was sieht sie".
- **Ablauf (der sicherheitskritische Teil):**
  1. Query embedden → `search_top_k_streaming()` über die `course-{id}`-CSV, **groß over-fetchen**
     (z. B. K=50–200), weil danach gefiltert wird.
  2. **Pro Kandidat den Per-User-Access prüfen** — gegen *den fragenden User*, nicht den Indexierer:
     - Search-Area-Chunks: `area->check_access($docid)` muss `ACCESS_GRANTED` liefern.
     - Zusätzliche Defense-in-depth über den `cm`/Kontext: Modul- und Section-Visibility für den User
       (`\core_availability`, `$cm->uservisible`, versteckte Sektionen) — „Verweis auf cm und die konkrete
       visibility für einen user", genau wie gefordert.
     - Buchungsoptionen: Moodle-`canseeoption`/Availability der Option (§7).
  3. Erst die **erlaubten** Treffer Top-N (z. B. 5–10) zurück, mit Snippet + **Deep-Link** zum Kontext
     (cm-URL, Option-URL).
- **Stale-Hinweis:** Wenn Registry `lastindexed < cm->modified` → Treffer mit „Index evtl. veraltet"
  markieren bzw. Re-Index vorschlagen.

### 3.3 `course.search_site` — „Suche über alle indizierten Kurse" (read-only, optional, v2)
- Iteriert die Registry: für jeden Kurs mit `status=ready`, der für den User *grundsätzlich* zugänglich ist
  (`get_areas_user_accesses` / Enrolment-Vorfilter, um teure Filterläufe über fremde Kurse zu sparen),
  ruft denselben Per-Kurs-Retrieval+Filter wie `search_course`, merged Top-N global.
- Bewusst „nacheinander" und damit nicht ideal — aber korrekt und sicher; die Vorfilterung auf erlaubte
  Kurse begrenzt den Aufwand. Klarer Kandidat, später durch echten Vektor-Store ersetzt zu werden (das ist
  dann der Punkt, an dem der alte Site-Suche-Blueprint mit Option B / eigener `\core_search\engine` greift).

---

## 4. Knackpunkt #1 — Berechtigungen (Make-or-Break)

**Asymmetrie, die alles trägt:** *indizieren mit voller Sicht, retrieven mit User-Sicht.* Ein flacher
Embeddings-Index hat keine Rechte. Würden wir Top-K direkt zurückgeben, leaken wir versteckte Aktivitäten,
fremde Abgaben, nicht-sichtbare Optionen.

Pflichten (jede einzeln ein Fail-Closed):
1. **Index baut nur, wer alles sehen darf** (Gate 2 am Kurskontext: viewhiddensections etc.). Sonst entsteht
   ein Index, der mehr enthält als der Builder sehen dürfte.
2. **Retrieval over-fetcht + filtert jeden Kandidaten** durch die Area-`check_access($docid)` **des
   fragenden Users** + cm/Section-Visibility. Niemals den Indexierer-Kontext zum Filtern verwenden.
3. **`skill_search_course` ≠ Inhaltsfreigabe.** Gate 1 ist nur die Aufruf-Erlaubnis. Bitte im Code-Review
   explizit darauf achten, dass kein Pfad existiert, der Treffer *ohne* den Per-User-Filter ausgibt
   (z. B. ein „Debug"-Output, observation_full, Preview).
4. **Metadaten-Minimierung:** zurückgegebene Snippets dürfen keine Felder enthalten, die der User am
   Originalort nicht sähe.

> Dies ist exakt das gleiche Modell wie Moodle + Solr: globaler Index, Filter nach
> `get_areas_user_accesses()`. Wir bauen denselben Filter, nur kurs-skopiert.

---

## 5. Area-Whitelist (was ist den Index wert?)

**Ja (Kerninhalt, gut indexierbar, klare `check_access`):**
`core_course\search\course` (Summary/Section-Beschreibungen), `mod_page`, `mod_book\search\chapter`,
`mod_label`, `mod_glossary\search\entry`, `mod_forum\search\post`, `mod_lesson`, `mod_wiki`,
`mod_data\search\entry`, `mod_folder/mod_resource` (Datei-Anhänge → §6), `mod_assign\search\activity`
(nur Aufgabenstellung; **keine** fremden Abgaben → check_access trägt das ohnehin).

**Vorsicht/optional:** `mod_quiz` (Fragen sind sensibel — eher aus), große/rauschige Areas.

**Nein:** alles ohne sinnvollen Volltext oder mit hohem Leak-Risiko.

Whitelist als Admin-Setting (Textarea/Checkboxen, vgl. Docs-Korpora-Textarea-Muster).

---

## 6. Datei-/PDF-Volltext — gelöst (in-process, gebündelt)

**Keine Solr/Tika-Abhängigkeit.** Das Plugin liefert `services/attachment/pdf_text_extractor` +
`thirdparty/pdfparser` (`Smalot\PdfParser`, reines PHP, selbst-registrierender Autoloader). Dual-Backend:
`pdftotext`-Shell falls vorhanden, sonst der gebündelte Parser. Bereits produktiv über
`attachment_processor`.

Index-Pipeline für Dateien:
1. Aus dem Search-Dokument bzw. dem Modul-Kontext die `stored_file`-Anhänge holen
   (`get_files()` / Moodle File-API, Filearea der Aktivität).
2. PDF → `pdf_text_extractor::extract()` (mit `is_available()`-Guard); andere Texttypen direkt lesen.
3. Den extrahierten Text wie jeden anderen Chunk-Quelltext chunken + embedden, `areaid` der Aktivität,
   `docid`/`contextid` = cmid-Kontext (damit der Per-User-Filter aus §4 auch für Datei-Treffer greift).

Offene Detailfragen (klein, nicht blockierend): Truncation-Grenze pro Datei, max. Dateigröße/Timeout,
welche Nicht-PDF-Typen (DOCX/PPTX) v1 mitmachen (Smalot deckt primär PDF ab).

---

## 7. Buchungsoptionen (kein Core-Search-Area!)

`mod_booking` hat **keine** `classes/search/`-Area. Optionen müssen separat eingesammelt werden:
- **Indizieren:** über die Booking-API (Optionen je Booking-Instanz im Kurs) — Titel, Beschreibung,
  Teacher, Termine, ggf. Custom-Fields → ein Chunk/Option, `areaid='booking-option'`,
  `docid=optionid`, `contextid` = cmid-Kontext der Booking-Instanz.
- **Retrieval-Filter:** Bookings eigene Sichtbarkeit pro User (`canseeoption`/Availability der Option,
  invisible-Flag) — **nicht** `core_search::check_access` (gibt es nicht), sondern Bookings native Prüfung.
  Hier ist `executor bleibt clean` zu beachten: die Booking-spezifische Logik gehört in einen
  Skill-eigenen Service, nicht in die Engine.
- Alternativ (sauberere Langfrist-Lösung): eine echte `mod_booking\search\option`-Area bauen — dann fällt
  die Sonderbehandlung weg und `check_access` trägt auch Optionen. Größerer Scope, eigener Vorschlag.

---

## 8. Engine-Cleanliness & Projektvorgaben (Pflicht)

- **Alles als Skill + Skill-eigene Services.** Die Agent-Engine routet nur; sie kennt „Kurs-Index" nicht.
  Neue Services: `course_index_builder_service`, `course_index_csv_repository`,
  `course_index_registry` (Tabelle), `course_search_retrieval_service` (Over-Fetch + Per-User-Filter),
  `file_text_extractor`, `booking_option_indexer`.
- **Lang-Strings immer en+de** über `get_string` (keine Hardcodes) — alle Skill-Beschreibungen, Trigger,
  User-Messages.
- **Preview als Daten** (`get_result_preview` → html/js_module/payload), Engine reicht generisch durch.
- **Flowchart:** `AGENT_IMPLEMENTATION_FLOWCHART.mmd` ist die primäre Architekturdoku — neuen
  `course.*`-Such-/Index-Pfad dort einzeichnen, Diskrepanzen mit Georg klären, nicht eigenmächtig angleichen.
- **PHPUnit/Behat:** Access-Filter ist die kritische Teststelle — Test mit Student-User gegen versteckte
  Aktivität/fremde Option muss **leer** zurückkommen; Test mit Teacher sieht alles.

---

## 9. v1-Scope-Vorschlag (wenn wir es angehen)

1. Registry-Tabelle + `course.index_course` (Adhoc-Task, inkrementell, Content-Hash-Dedup, kuratierte
   Area-Whitelist; Dateien zunächst per Option (b) Titel/Kontext).
2. `course.search_course` mit Over-Fetch + striktem Per-User-`check_access` + cm-Visibility + Deep-Links.
3. Buchungsoptionen via Sonderpfad (§7-Variante a).
4. Admin: Whitelist-Setting, Übersicht indizierter Kurse, „re-index"-Knopf.
5. `course.search_site` + Datei-Volltext (Solr/Tika) als **v2**.

## 10. Offene Fragen (für Georg)

1. ~~Datei-Volltext: Extractor vorhanden?~~ **Gelöst** — `pdf_text_extractor` + gebündelter `Smalot\PdfParser`
   (§6). Rest-Detail: DOCX/PPTX in v1 dabei, oder nur PDF + Plaintext?
2. **Buchungsoptionen (§7):** Sonderpfad jetzt vs. echte `mod_booking\search\option`-Area bauen?
3. **Index-Builder-Cap:** `moodle/course:viewhiddensections`+`update` (nur Teacher/Manager) ok, oder
   eigene Capability `…:indexcourse`?
4. **Embeddings-Budget:** Ganze Kurse embedden kostet — Confirm/Limit pro Index-Lauf? Batching-Strategie?
5. **Registry vs. Dateiliste:** eigene Tabelle (empfohlen, Status/Stale) bestätigt?
6. **`search_site` v2** — überhaupt, oder direkt auf den echten Vektor-Store (alter WS7-Blueprint, Option B)
   warten?

---

*Verwandt:* `obsolet/todo/semantische_site_suche_embeddings_adapter_2026-06-10.md` (Site-weite Variante,
Option A/B, echter Vektor-Store), `docs_corpus_registry`/`docs_embeddings_index_service` (direktes
Implementierungs-Vorbild), Memory: `project_docs_corpus_embeddings_refactor`, `feedback_executor_stays_clean`,
`feedback_flowchart_policy`, `feedback_always_lang_strings_en_de`.
