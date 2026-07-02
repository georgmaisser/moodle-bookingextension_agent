# Blueprint · Retrieval-Foundation + Semantische Site-Suche (gemeinsamer Umbauplan)

> **Status:** Planungsdokument (todo). Noch nicht committet.
> **Datum:** 2026-07-02
> **Scope:** `bookingextension_agent` — Embeddings-Persistenz + semantisches Retrieval für Docs-Korpus,
> Skill-Katalog **und** (künftig) Site-Content.
> **Entscheidung (Georg):** csv→db-Umstellung und semantische Site-Suche unter **ein** Dach, aber sauber
> schneidbar: gemeinsamer Retrieval-Contract, danach in Phasen auslieferbar.

Verbindet und ersetzt als Dach-Plan:
- `embeddings_store_csv_to_db_2026-07-02.md` (Detail zur DB-Umstellung Docs/Skills) → wird **Phase 1**.
- `semantische_site_suche_embeddings_adapter_2026-06-10.md` (Site-Suche-Analyse) → wird **Phase 2–3**.

---

## 0. Warum gemeinsam (und warum trotzdem getrennt)

csv→db (klein, sicher, sofort nützlich) und Site-Suche (groß, unsicher, Roadmap) teilen **genau einen**
kritischen Vertrag: **wie man semantisch abfragt**. Diesen Vertrag wollen wir **einmal richtig** festlegen,
bevor die DB-Umstellung ihn zementiert — sonst backt der schnelle Umbau eine Schnittstelle ein, die die
Site-Suche später sprengt (Millionen Zeilen kann man nicht pro Query nach PHP streamen).

**Gemeinsam ist der Contract, NICHT die Tabelle:**
- Docs/Skills (~1.250 Zeilen, O(N)-PHP-Cosine ok) → **ein** kleiner Table `{agent_embeddings}`.
- Site-Content (10⁵–10⁷ Chunks, braucht Provenienz+Access+ANN) → **eigene** Tabellen mit eigenem Lifecycle.
- Beide **hinter demselben `embeddings_store`-Interface** mit `search_top_k()` als Retrieval-Naht.

Ein Table für alles würde entweder die kleine Tabelle mit nullbaren Access-Spalten aufblähen oder die große
mit O(N)-PHP erschlagen.

---

## 1. Die festgenagelten Entscheidungen

1. **`search_top_k()` ist die öffentliche Retrieval-Methode** (nicht `stream_rows`). `stream_rows` bleibt
   internes Detail der PHP-Cosine-Implementierung. So kann eine ANN-Implementierung (pgvector/MariaDB-VECTOR)
   pro Area später eingeschoben werden, ohne einen Aufrufer zu ändern.
2. **Getrennte Tabellen, geteiltes Interface.** Kleine Korpora und Site-Content leben physisch getrennt,
   sprechen aber dieselbe API.
3. **Access-Boundary generisch.** Retrieval trägt `contextid`/`owner` immer mit und akzeptiert einen
   optionalen `retrieval_filter`. Docs/Skills lassen ihn leer (global sichtbar); Site-Content verengt darüber
   und filtert autoritativ per `check_access()` (§7).
4. **Alles im Agent; Abhängigkeit search → agent** (§4). Der Agent ist self-contained (nur Moodle-Core, **kein**
   Such-Plugin nötig); die Search-Areas funktionieren ohne aktivierte Global Search. Eine künftige native
   Such-Engine (`searchengine_wbvector`) ist **optional** und hängt vom Agent ab, **nie umgekehrt**.
5. **Eigene Governance (§5b): Freischaltung + Recht + Seite.** Kein Auto-Index — ein Admin schaltet pro
   Kurs/Plugin frei; die Seite zeigt **Aufwandschätzung (potenzielle Kosten)** + **Ampel** (Kosten/Query-Tempo);
   gated durch die eigene Capability `bookingextension/agent:configuresitesearch` (default nur Admins,
   vergebbar).

---

## 2. Layer 0 — Gemeinsamer Contract (einmal, klein, engine-frei)

Neuer Namespace `…\local\wizard\services\retrieval` (engine-agnostisch, kein Wissen über Areas/Domäne).

### 2.1 Interface `embeddings_store`

```php
interface embeddings_store {
    // --- Retrieval: die ANN-Swap-Naht ---
    // Top-K einer Area/Variante, bereits gescored, über minscore. Default-Impl: stream_rows + PHP-Cosine.
    // Eine ANN-gestützte Area überschreibt das mit serverseitigem Top-K.
    public function search_top_k(
        string $area, string $emodel, int $edims,
        array $queryvector, int $k, float $minscore,
        ?retrieval_filter $filter = null   // Access/Context-Verengung; null = keine (global)
    ): array; // embedding_hit[]  (row-Metadaten + score, OHNE Vektor)

    // --- Presence / Readiness ---
    public function exists(string $area, string $emodel, int $edims): bool;
    public function count_rows(string $area, string $emodel, int $edims): int;
    public function fingerprint(string $area, string $emodel, int $edims): string;
    public function set_fingerprint(string $area, string $emodel, int $edims, string $fp): void;

    // --- Rebuild (Generation-Swap, §Phase-1) ---
    public function begin_generation(string $area, string $emodel, int $edims): int;
    public function upsert(string $area, int $generation, embedding_row $row): void;
    public function reuse_existing(string $area, string $emodel, int $edims, string $key): ?embedding_row;
    public function commit_generation(string $area, string $emodel, int $edims, int $generation): void;

    // --- Invalidation (Site-Content: Kontext/Kurs gelöscht) ---
    public function delete_by_context(string $area, int $contextid): void;
}
```

- **`retrieval_filter`** — trägt erlaubte `contextids` (SQL-Vorverengung, effizient bei großen Korpora) und
  optional den `owneruserid`-Scope. Ist **keine** endgültige Autorität; die autoritative Prüfung bleibt
  `check_access()` beim Aufrufer (§8).
- **`embedding_row`** (DTO) — generisch mit optionaler Provenienz:
  `area, owner, refkey, refindex, endindex?, title, emodel, edims, contenthash, embedding(float[])`,
  plus für Site-Content nullbar: `docid?, contextid?, courseid?, owneruserid?`.
- **`embedding_hit`** (DTO) — was `search_top_k` zurückgibt: `owner, refkey, refindex, title, score` +
  Provenienz (`docid/contextid/…`) — **ohne** den Vektor (der bleibt im Store).
- Dünne **Mapper** (`docs_embedding_row_mapper`, `skill_embedding_row_mapper`, später
  `site_content_row_mapper`) übersetzen zwischen `embedding_row` und Area-Semantik — Aufrufer-Code bleibt lesbar.

### 2.2 Was `embeddings_retrieval_service` behält

`embeddings_retrieval_service::search_top_k_streaming()` (Cosine, O(k)-Speicher) bleibt die **Default-Engine**
hinter `db_embeddings_store::search_top_k()` — bekommt weiterhin einen Generator von `(vektor, metadaten)`.
Nur die Zeilenquelle wechselt CSV→DB, und der Aufruf geht über `search_top_k()` statt direktem Streaming.

---

## 3. Phase 1 — DB-Store für Docs/Skills *(für sich auslieferbar)*

Vollständiges Detail: `embeddings_store_csv_to_db_2026-07-02.md`. Delta gegenüber jenem Doc:
**Aufrufer nutzen `search_top_k()`** (Layer 0), nicht `stream_rows()`.

**Schema `{agent_embeddings}`** (klein, docs+skills, float32-BLOB, Generation-Swap):

```
id BIGINT PK · area VARCHAR(32) · owner VARCHAR(255) · refkey VARCHAR(255) · refkeyhash CHAR(40)
refindex INT · endindex INT NULL · title TEXT · emodel VARCHAR(128) · edims INT
contenthash VARCHAR(40) · generation BIGINT · embedding BLOB(float32 LE, edims*4) · timemodified BIGINT
Scan-Index: (area, emodel, edims, generation)
Unique:     (area, owner, refkeyhash, refindex, emodel, edims)   -- Hash statt langem chunk_path
+ {agent_embeddings_meta}: (area, emodel, edims) → committed_generation, fingerprint
```

**Schritte:** P0 Interface-Inversion (CSV hinter `embeddings_store`, Verhalten unverändert, grüne Tests) →
P1 `db_embeddings_store` (float32 pack/unpack, Generation-Swap) → P2 Verdrahtung (Index/Readiness/Lookup +
Feature-Flag `embeddingsstore=csv|db`) → P3 Migration (XMLDB-Upgrade, **Import der aktiven Variante** aus CSV
statt teurem Re-Embed, Temp-Cleanup) → P4 Deprecate CSV nach 1–2 Releases.

**Liefert:** persistenten, cluster-geteilten, gebackupten Store + float32 (~5× kleiner/schneller) für
Docs/Skills — **ohne** auf die Site-Suche zu warten. De-riskt und finanziert Phase 2–3.

---

## 4. Architektur-Leitplanke: alles im Agent — optionale Such-Engine später (Abhängigkeit **search → agent**)

**Feste Regel (Georg):** Der Agent ist **self-contained** und wird **allein** ausgeliefert. Die gesamte
Retrieval-Logik (Store, Indexing, `search_top_k`) lebt **im Agent**. Er benötigt **nie** ein separates
Such-Plugin. Ein künftiges Plugin ist **optional** und hängt vom Agent ab (**search → agent**) — **nie umgekehrt**.

**Warum „alles im Agent" sauber geht:** Wir nutzen die core_search **Areas** (`get_document`/`check_access`) —
die sind **Moodle-Core, kein Plugin**. Der Agent hängt also nur an Moodle-Core (immer vorhanden, auch bei
deaktivierter Global Search), an **keinem** Such-Plugin. Es gibt **kein** separates „Core"-Plugin; der Kern
lebt im Agent.

**Jetzt (Pflicht) — In-Agent-Indexing:** ein eigener Scheduled Task im Agent fährt den Loop (§5), schreibt in
die Agent-Tabellen, Retrieval über `search_top_k`. Kein Fremd-Plugin, keine aktive Global Search nötig.
Access-Wahrung: `check_access()` der jeweiligen Area als Pflicht-Post-Filter (Moodles eigene Logik, wir
reimplementieren nichts) — Test in §10. *(Das ist die frühere „Option A", jetzt schlicht* der *Weg — keine
A/B-Weiche mehr.)*

**Später (optional, „wenn nicht zu kompliziert") — `searchengine_wbvector`:** ein **dünnes**
`\core_search\engine`-Plugin (`search/engine/wbvector`), das `bookingextension_agent` als **Dependency**
deklariert und nur forwardet:
- `add_document($doc)` → Agent-Index (chunk/embed/upsert),
- `execute_query($filters, $accessinfo, $limit)` → Agent-`search_top_k(…, retrieval_filter=$accessinfo)` →
  zurückgemappt auf `\core_search\document`.

Damit wird die **native** Suchbox semantisch — als Zusatzprodukt, das den Agenten braucht, nicht umgekehrt.
Fällt ersatzlos weg, wenn zu aufwändig; der Agent bleibt davon unberührt.

**Kosten der offenen Tür = fast null.** Einzige Disziplin *jetzt*: die Retrieval-Logik im Agent hinter der
sauberen internen API halten (`embeddings_store` + `search_top_k` + Indexing-Service) — bauen wir für Layer 0
ohnehin. Kein separates Plugin, keine Dependency, kein ANN-Zwang jetzt. Die Tür zur nativen Engine bleibt
gratis offen.

---

## 5. Phase 2 — Site-Search-Indexing *(In-Agent-Task, §4)*

Vollständige Analyse: `semantische_site_suche_embeddings_adapter_2026-06-10.md` (inkl. §11-Nachtrag zur
verifizierten Volltext-Verfügbarkeit). Kern der Indexierung — die Search-API liefert **Was/Links/Wann/Wer**,
wir machen Embeddings+Matching:

1. **Areas aufzählen** — `manager::get_search_areas_list(true)`, gefiltert auf **kuratierte Whitelist**
   (page, book/chapter, forum/post, glossary/entry, wiki, course-summary — die Areas mit Volltext-`content`).
2. **Inkrementell** — `$area->get_document_recordset($lastindexed, $context)` (streamend).
3. **Dokument bauen** — `$area->get_document($record)` → `get('title'|'content'|'description1/2')` +
   Provenienz `get('contextid'|'courseid'|'owneruserid'|'modified')`; Files (v1) weglassen.
4. **Change-Detection** — `contenthash` → unverändert = kein Re-Embed.
5. **Chunking** — ~500-Token-Chunks mit Overlap; jeder Chunk erbt die Provenienz.
6. **Embedden (gebatcht)** — Wunderbyte `generate_embeddings`-Action (dieselbe Infra wie Docs/Skills).
7. **Upsert + Deletes** — Chunks/Vektoren pro `(areaid, docid)` ersetzen; gelöschte Docs
   (`check_access()==ACCESS_DELETED`) und Kontexte (`delete_by_context`) prunen.
8. **Cursor + Zeitlimit** — inkrementell fortsetzbar, hinter Admin-Toggle + Whitelist.

**Jetzt (In-Agent, §4):** unser Task fährt alle 8 Schritte selbst. **Falls** später der optionale
`searchengine_wbvector`-Adapter kommt, übernimmt der Core die Schritte 1, 2, 7-delete, 8, und wir
implementieren nur 4–7 **innerhalb** `engine::add_document()` + `delete_index_for_context/_course()` —
dieselbe Kern-Logik, nur anders angestoßen.

**Schema (Site-Content, groß, Vektor von Content getrennt):**

```
{agent_search_chunk}
  id BIGINT PK · areaid VARCHAR(64)  -- z.B. 'mod_forum-post'
  docid BIGINT · contextid BIGINT · courseid BIGINT · owneruserid BIGINT
  chunkno INT · content TEXT · contenthash VARCHAR(40)
  emodel VARCHAR(128) · edims INT · generation BIGINT · modified BIGINT · timemodified BIGINT
  Scan-Index: (emodel, edims, generation)
  Access/Delete-Index: (contextid)      -- schnelle Kontext-Verengung + delete_by_context
  Unique: (areaid, docid, chunkno, emodel, edims)

{agent_search_vector}
  chunkid BIGINT (FK, PK) · embedding BLOB(float32 LE) · emodel · edims
```

Vektor getrennt von `content`: der ANN/Scan liest nur Vektoren; der `content`-TEXT wird erst für die finalen
K Treffer nachgeladen (spart Bytes im Scan, hält BLOBs off-page-neutral).

---

## 5b. Governance-Seite — Indizierung freischalten (pro Kurs/Plugin) + Aufwandschätzung + Ampel

Kein Auto-Index. Ein Admin **schaltet frei**, was indiziert wird, und sieht dabei **sofort die (potenziellen)
Kosten**. Eigene Seite im Muster von `skill_governance.php`, eigenes Recht.

### 5b.1 Eigenes Recht (Capability)

- **`bookingextension/agent:configuresitesearch`** — gated die Seite + jedes Freischalten/Umschalten.
- `db/access.php`: **kein** Archetype-Grant → standardmäßig nur Site-Admins (via `moodle/site:config`), aber als
  definierte Capability **frei an beliebige Rollen vergebbar** (genau deine Vorgabe: default Admin, umverteilbar).
- Serverseitig hart geprüft: `require_capability('bookingextension/agent:configuresitesearch', context_system::instance())`
  auf der Seite **und** an jedem Toggle-Webservice.

### 5b.2 Eigene Seite (wie skill_governance)

- `sitesearch_governance.php` (Struktur/Style von `skill_governance.php`), verlinkt aus den Agent-Admin-Settings
  (analog zum Skill-Governance-Link). Rendert die Freischalt-Matrix + Schätzung + Ampel; Toggles via WS
  (idempotent, capability-geprüft).

### 5b.3 Freischaltung: pro Plugin (Area) UND pro Kurs

- **Pro Plugin/Area:** Whitelist der content-tragenden Areas aus `manager::get_search_areas_list(true)` — je
  Area ein Toggle (page, book, forum, glossary, course-summary, wiki …).
- **Pro Kurs (Scope):** *alle Kurse* / *ausgewählte Kurse* / *Kategorie(n)* / *Ausschlussliste*. Auch der
  Site-Kurs (Front page) ist ein gültiger Scope.
- **Persistenz:** `{agent_search_scope}(id, area, scopetype ['site'|'course'|'category'], scopeid, enabled,
  usermodified, timemodified)`. Der Index-Task (§5/§7) respektiert diese Freischaltung als harte Quelle der
  Wahrheit (nur freigeschaltete Area×Scope werden indiziert; Deaktivieren → `delete_by_context` der betroffenen
  Kontexte).

### 5b.4 Aufwandschätzung im Interface (live, ohne zu embedden)

Ein `index_scope_estimator`-Service liefert je Area×Scope ein **`index_estimate`**-DTO — **ohne** einen
einzigen Embedding-Call:

```
index_estimate {
  doccount     int     // billige Zählung: get_document_recordset()-Rowcount bzw. COUNT-SQL der Area
  estchunks    int     // doccount × Ø-Chunks (aus Content-Längen-Sampling einiger Docs)
  esttokens    int     // estchunks × Ø-Tokens/Chunk
  estcostcents int     // esttokens × Preis/1k des AKTIVEN Embedding-Modells (embeddings_action_config_resolver)
  buildseconds int     // grobe Bau-Dauer (Batch-Größe/Rate-Limit)
  ampel        string  // 'green' | 'yellow' | 'red' (siehe 5b.5)
}
```

- **Billig by design:** Zählen läuft über die Area-Recordsets/COUNT — kein `get_document()`/kein Embedding.
  Content-Längen für die Chunk-Schätzung aus einem kleinen Sample (z. B. 20 Docs) hochgerechnet.
- Kosten = geschätzte Tokens × Preis des **aktiven** Modells (aus `embeddings_action_config_resolver`), damit
  die Zahl zum tatsächlichen Rebuild passt.
- Anzeige: pro Zeile (Area×Scope) und als **Summe** der aktuellen Auswahl — „so viel kostet/dauert das jetzt".

### 5b.5 Ampel-System (Kosten **und** Query-Tempo)

Die Ampel bündelt zwei Dimensionen zu einem Blick-Signal, gespeist aus der geschätzten **Indexgröße**:

- 🟢 **grün** — klein (z. B. `< N` Chunks): billig **und** PHP-Cosine bleibt schnell → sofort freischaltbar.
- 🟡 **gelb** — mittel: spürbare Kosten/Latenz, mit Bedacht.
- 🔴 **rot** — groß (z. B. `> M` Chunks): teuer **und** O(N)-PHP-Cosine wird pro Query langsam → erst
  **ANN-Fast-Path** (Phase 3) aktivieren **oder** Scope enger ziehen.

- Schwellen `N`/`M` als Admin-Settings.
- **Kontextsensitiv:** ist ein ANN-Fast-Path verfügbar (`capability_detect()` pgvector/MariaDB-VECTOR),
  entschärft sich die Query-Tempo-Dimension → rot kann zu gelb/grün werden (nur die Kostendimension bleibt).
  So heißt „rot" konkret: *bei aktueller Retrieval-Engine würde dieser Scope die Suche langsam machen*.

---

## 6. Phase 3 — Site-Search-Retrieval + Skill + ANN-Fast-Path *(braucht Phase 2)*

- **Retrieval** über `search_top_k('site_content', …, $filter)`:
  - `retrieval_filter` = erlaubte `contextids` aus `manager::get_areas_user_accesses()` (SQL-Vorverengung).
  - **Over-fetch** (k' ≫ k), dann autoritativer `check_access($docid)`-Filter der zuständigen Area
    (In-Agent jetzt) bzw. nativer `accessinfo`-Filter in `execute_query()` (optionaler Engine-Adapter). Siehe §7.
- **Skill** `core.find_content` (bzw. `search_site`) — reine Skill-Schicht: nimmt Query, ruft `search_top_k`,
  baut Treffer (Titel, Snippet, Deep-Link via `$area->get_doc_url($doc)`). Engine bleibt clean (routet nur).
- **ANN-Fast-Path** — `capability_detect()`: bei pgvector/MariaDB-VECTOR eine alternative
  `site_content`-`search_top_k`-Implementierung (Cosine+Top-K serverseitig, nur K Zeilen zurück). Schmerzfrei
  einschiebbar, weil das Interface (§2) von Tag 1 stimmt. Bis dahin: PHP-Cosine mit `contextid`-Vorverengung
  (bei schmalem Zugriff tragbar; bei breitem Zugriff = Grund für den Fast-Path).

---

## 7. Access-Modell (Make-or-Break, End-to-End)

Der Index ist **global**, die **Sicht ist pro User** — dasselbe Prinzip wie Moodle mit Solr:

1. Index enthält alle Chunks mit `contextid`/`owneruserid` (Schritt 3/5 der Indexierung).
2. Query: erlaubte Kontexte des Users via `manager::get_areas_user_accesses()` → als `retrieval_filter`
   in `search_top_k` (SQL-Vorverengung, hält den Scan bei großen Korpora klein).
3. **Autoritative Prüfung**: über die verengte Kandidatenmenge läuft `check_access($docid)` der Area
   (`ACCESS_GRANTED|DENIED|DELETED`) — das ist die Domänen-Logik, die die Engine **nicht** kennen darf.
   Beim optionalen `searchengine_wbvector`-Adapter erledigt das der Core-Engine-Vertrag (`accessinfo`).
4. Docs/Skills: `retrieval_filter=null` (global sichtbar), keine `check_access`-Runde nötig.

**Nie** Top-K ohne diesen Filter zurückgeben → sonst Leak fremder Kurse/versteckter Aktivitäten.

---

## 8. Reihenfolge & Schneidbarkeit

```
Layer 0 (Contract)  ──►  Phase 1 (DB-Store Docs/Skills)  ──►  [auslieferbar, Betriebsgewinn]
        │
        └──►  §4-Leitplanke (alles im Agent)  ──►  Phase 2 (Governance-Seite §5b + Site-Indexing)  ──►  Phase 3 (Retrieval+Skill+ANN)  ┄┄►  [optional] searchengine_wbvector (depends on agent)
```

- **Layer 0 + Phase 1** hängen an nichts und liefern sofort Wert (csv→db).
- **Phase 2** beginnt mit der **Governance-Seite (§5b: Recht + Freischaltung + Aufwandschätzung + Ampel)** —
  ohne Freischaltung wird nichts indiziert, und die Schätzung/Ampel entscheidet, *ob* man einen Scope freischaltet.
  Der Index-Task kommt danach und respektiert `{agent_search_scope}`.
- **Phase 2–3** dürfen eigenes Tempo/Unsicherheit haben, ohne Phase 1 zu blockieren.
- Einziger harter Kopplungspunkt: das `embeddings_store`-Interface (Layer 0) — deshalb zuerst.

---

## 9. Risiken

1. **Interface zu eng geschnitten** (nur `stream_rows`) → Site-Suche später blockiert. → `search_top_k` ab
   Layer 0 (die #1-Vorgabe).
2. **Access-Leak** bei fehlendem/fehlerhaftem Post-Filter (höchstes Risiko) → im In-Agent-Betrieb: Pflicht-
   `check_access` + Tests, die einen unberechtigten User gegen einen Treffer prüfen (§10). Der optionale
   Engine-Adapter entschärft das später zusätzlich strukturell (nativer `accessinfo`).
3. **DB-Last** — Docs/Skills unkritisch; Site-Content-Scan pro Query = echter Druck auf die geteilte DB →
   MUC-Cache der dekodierten Vektoren gekeyt auf `generation` (Docs/Skills), `contextid`-Vorverengung +
   ANN-Fast-Path (Site-Content). Metrik/Log mitlaufen lassen.
4. **float32/Endianness** — fix little-endian (`pack/unpack('g*')`), Konstante + Roundtrip-Test.
5. **Generation-Pruning idempotent** — abgebrochener Rebuild darf keine verwaisten Generationen lassen
   (Prune-on-commit + „orphan generation"-Cleanup im Task).
6. **Kosten** — Site-Content ganze Site embedden = echtes Geld → Whitelist + inkrementell + Content-Hash
   zwingend; Docs/Skills-Migration per **Import der aktiven Variante** statt Re-Embed.
7. **Optionaler Engine-Adapter (später)** — als aktive Such-Engine ersetzt `searchengine_wbvector` die
   Site-Engine (nur eine aktiv) und muss ANN mitbringen (Site-weite Query-Latenz). Erst relevant, wenn dieses
   Zusatzprodukt tatsächlich gebaut wird; der Agent selbst ist davon unberührt.

---

## 10. Testing

- **Layer 0/Phase 1:** float32-Roundtrip; `db_embeddings_store` upsert/reuse/commit/prune; `search_top_k`
  Parität gegen CSV-Fixture (identische Top-K); Korrupt-BLOB-Guard; Readiness `missing→ready`.
- **Phase 2:** Indexing-Roundtrip pro Whitelist-Area (get_document→chunk→embed→upsert); inkrementeller
  Re-Index (nur geänderte Docs); `delete_by_context` prunt korrekt.
- **Phase 3 (Access, kritisch):** ein Treffer in Kurs X; User **ohne** Zugang zu X bekommt ihn **nicht**
  (weder über `retrieval_filter` noch über `check_access`); berechtigter User bekommt ihn.
- phpcs/phpdoc 0/0 durchgehend (auch local_moodlecheck: keine `array<…>`-Generics/`array{…}`-Shapes in @param).

---

## 11. Offene Entscheidungen für Georg

1. **Architektur (§4) — entschieden:** alles im Agent, self-contained; optionale `searchengine_wbvector` später
   (Abhängigkeit search→agent). Keine A/B-Weiche mehr. Hier nur zur Bestätigung.
2. **Layer 0 jetzt zusammen mit Phase 1 bauen** (empfohlen — sonst zementiert Phase 1 die Schnittstelle) oder
   Phase 1 mit `stream_rows` und Refactor später (Risiko)?
3. **MUC-Cache** der dekodierten Vektoren (Docs/Skills) in Phase 1 mitnehmen oder als Follow-up?
4. **Site-Content-Store** wirklich getrennte Tabellen (empfohlen) — bestätigt?
5. **Whitelist v1** — welche Areas (Vorschlag: page, book, forum, glossary, course-summary; wiki optional)?
6. **Capability-Name** — `bookingextension/agent:configuresitesearch`, default nur Admins (kein Archetype),
   frei vergebbar. Name/Default ok?
7. **Ampel-Schwellen** `N`/`M` (Chunks) — sinnvolle Defaults festlegen (z. B. grün `<2.000`, rot `>20.000`
   ohne ANN)? Und: Kosten-Ampel getrennt von Tempo-Ampel anzeigen oder zu einem Signal bündeln?
8. **Governance-Seite Teil von Phase 2** (empfohlen — Voraussetzung fürs Freischalten) oder vorgezogen als
   eigener kleiner Meilenstein direkt nach Phase 1?

---

*Verwandt:* `embeddings_store_csv_to_db_2026-07-02.md`, `semantische_site_suche_embeddings_adapter_2026-06-10.md`,
`project_agent_skill_discovery_visibility` (vorhandene Embeddings-/Retrieval-Infra), ROADMAP.md WS7.
