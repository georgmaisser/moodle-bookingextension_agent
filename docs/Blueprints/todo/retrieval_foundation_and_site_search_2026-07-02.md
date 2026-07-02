# Blueprint · Retrieval-Foundation + Semantische Site-Suche (gemeinsamer Umbauplan)

> **Status:** Planungsdokument (todo). Noch nicht committet.
> **Datum:** 2026-07-02
> **Scope:** `bookingextension_agent` — Embeddings-Persistenz + semantisches Retrieval für Docs-Korpus,
> Skill-Katalog **und** (künftig) Site-Content.
> **Entscheidung (Georg):** csv→db-Umstellung und semantische Site-Suche unter **ein** Dach, aber sauber
> schneidbar: gemeinsamer Retrieval-Contract, danach in Phasen auslieferbar.

> **✅ KONSOLIDIERT & VERIFIZIERT 2026-07-02** gegen echten Core- **und** Agent-Code (7 adversariale
> Verifikations-Workstreams + Critic). Ergebnis: der Plan trägt. Eingearbeitet sind **eine Kernkorrektur**
> (engine-freier Access-Boundary, §6/§7 — `get_areas_user_accesses` ist NICHT engine-frei), die belegten
> **Methoden-/Klassen-Referenzen** (§12), die **neu gefundenen Pflicht-Artefakte** (§13), die
> **PDF-Reader-/Server-Fallback-Integration** für ANN-Detektion + Datei-Indizierung (§14), die
> **Flowchart-/Doku-Diskrepanzen** (§15) und die **getroffenen §11-Entscheidungen**.
> **Georgs jüngste Vorgaben (eingearbeitet):** (a) **Site-Suche = Moodle 5 + `aiprovider_wunderbyte`** — auf
> 4.5/ohne Provider nur ein Admin-Hinweis, kein Feature; Phase 1 bleibt 4.5-inert-safe (§16). (b) **Privacy-Provider
> wird gebaut** (§13.7). (c) **Keine Preisabfrage** — nur Chunk-Anzahl + Ampel (§5b.4/5b.5). Zeilennummern sind
> gegen `/Users/georgmaisser/Code/02` (Moodle-5.1-Webroot) belegt.
>
> **↻ 2. Gegenverifikation (2026-07-02b, 8 unabhängige Workstreams):** Der Kern trägt (Access-Boundary §7,
> API-Oberfläche §12, Whitelist-Volltext, float32/XMLDB — alle bestätigt). Korrigiert wurden **1 Blocker**
> (Estimator §5b.4 — es gibt keinen generischen `COUNT(*)`) + **Majors** (bx_agent-Naming, `insert_records`/PG,
> `delete_by_context`-Arität, Migration, Sub-Doc-Divergenz). **Alle §11-Entscheidungen getroffen** (inkl.
> Whitelist = **B** [course/section rein], **Default = alle Areas AUS / einzeln opt-in**). **Umsetzungsreif für Layer 0 P0.**
>
> **↻ 3. Korrektur (2026-07-02c, Snippet & `get_document()`):** `get_document()` ist — entgegen der §4-Annahme —
> **NICHT engine-frei**: `document_factory::instance()` ruft `manager::instance()` und wirft ohne konfigurierte
> Engine `engine_exception` (`search/classes/document_factory.php:59-63`). Dokumentbau läuft daher über einen
> **eigenen engine-freien Extraktor** (§5.3), und das §5-Snippet-Nachladen per `get_document()` entfällt.
> **Entscheidung (Georg): KEIN Content/Snippet im Store** — die „kein `content`-TEXT"-Regel bleibt bestätigt
> (keine Content-Verdoppelung im Index); Snippets werden zur **Query-Zeit** für die finalen K re-extrahiert,
> Staleness wird über **Indexierungs-Frische** gelöst (Trigger/Cursor), nicht über Content-Kopien. Details §5
> (Storage-Note), Entscheidung §11.22.
>
> **↻ 4. Entscheidungen nach Implementierungs-Audit (2026-07-02d, Georg):** (a) **Site-Indexing STRENG
> INKREMENTELL** — pro Lauf dürfen nur geänderte Chunks geschrieben werden; ein Full-Generation-Swap pro Lauf
> (alle Zeilen neu schreiben, auch bei Vektor-Reuse) ist ein **absolutes No-Go** (§5.2, §11.23; Swap bleibt nur
> Initial-Build/Repair + docs/skills). (b) **Skills-Area wird JETZT auf den Store verdrahtet** (§11.24 — der
> bewusste P2-Scope-Cut wird nachgezogen). (c) **Entscheidung 19 bestätigt:** Governance-Seite + Capability +
> Scope-Tabelle kommen **vor** dem Commit des Site-Search-Schnitts; ein rohes Admin-Setting ist kein Ersatz (§11.25).
>
> **↻ 5. Re-Korrektur Engine-Session (2026-07-02f, Georg):** Der Extraktor-Mechanismus aus ↻ 3 ist **überholt**:
> Statt `get_document()` zu meiden, stellen wir ihm im Index-Task eine **Task-scoped Engine-Session** bereit
> (Null-Engine + Manager-Singleton-Seeding nach Cores eigenem Fixture-Muster, rein im Prozess-Speicher, **kein
> Config-/DB-Write**) — `get_document()` wird die **einzige Content-Quelle**, per-Plugin-Extraktor-Code entfällt,
> Whitelist-Erweiterung = Governance-Config statt Agent-Code. Unverändert bleiben: „kein Content/Snippet im
> Store" (↻ 3) und „streng inkrementell" (↻ 4). Details §5.3, Entscheidung §11.26.

Verbindet und ersetzt als Dach-Plan:
- `embeddings_store_csv_to_db_2026-07-02.md` (Detail zur DB-Umstellung Docs/Skills) → wird **Phase 1**.
- `semantische_site_suche_embeddings_adapter_2026-06-10.md` (Site-Suche-Analyse) → wird **Phase 2–3**.

---

## 0. Warum ein gemeinsamer Store + Contract (Entscheidung: EIN Table mit `area`)

csv→db (klein, sicher, sofort nützlich) und Site-Suche (groß, unsicher, Roadmap) teilen **genau einen**
kritischen Vertrag: **wie man semantisch abfragt**. Diesen Vertrag wollen wir **einmal richtig** festlegen,
bevor die DB-Umstellung ihn zementiert — sonst backt der schnelle Umbau eine Schnittstelle ein, die die
Site-Suche später sprengt.

**EIN Table `{bx_agent_embeddings}` mit `area`-Diskriminator für Docs, Skills UND Site-Content** — kein
Tabellen-Split. Begründung (verifiziert, revidiert 2026-07-02):

- Der scheinbare Skalierungsvorteil eines Splits ist in Wahrheit eine **Index-Eigenschaft, keine
  Tabellen-Eigenschaft**: mit Indizes `(area, emodel, edims, generation)` und `(area, contextid)` trifft jede
  Teilsuche via Index nur ihre eigene Partition. `WHERE area='docs'` scannt nie die Millionen Site-Zeilen.
- Die **Access-Vorselektion, die wir ohnehin brauchen**, trennt gleichzeitig: Docs/Skills = global
  (`retrieval_filter=null`), Site-Content = `contextid IN (…)` (hoch-selektiv). Ein separater physischer Table
  kauft dafür nichts.
- Ein Table = **DRY** (ein Schema, ein Repository, eine Migration), Moodle-Files-API-idiomatisch (`component/area/itemid`).
- Site-Provenienz (`docid/contextid/courseid/owneruserid`) sind **nullbare Spalten** — für Docs/Skills leer, kein Ballast.

**Wo ein Split *doch* zählen könnte — bewusst auf Phase 3 vertagt:** ein **ANN-Vektor-Index** (pgvector/MariaDB-VECTOR)
liefert global nächste Nachbarn; auf MariaDB/MySQL (keine partiellen Indizes) würde er Docs/Skills-Vektoren
mitmischen. *Falls* Phase-3-ANN das erzwingt, wandert **nur die Site-Area** in eine eigene Tabelle/Index —
**hinter demselben `search_top_k`-Interface, ohne Aufrufer-Änderung** (§5 Phase-3-Note). Wir verlieren die Option
also nicht, indem wir unified starten.

---

## 1. Die festgenagelten Entscheidungen

1. **`search_top_k()` ist die öffentliche Retrieval-Methode** (nicht `stream_rows`). `stream_rows` bleibt
   internes Detail der PHP-Cosine-Implementierung. So kann eine ANN-Implementierung (pgvector/MariaDB-VECTOR)
   pro Area später eingeschoben werden, ohne einen Aufrufer zu ändern.
2. **EIN `area`-diskriminierter Table, geteiltes Interface.** Docs, Skills UND Site-Content leben in
   `{bx_agent_embeddings}`, getrennt über die `area`-Spalte + Indizes (`(area,emodel,edims,generation)`,
   `(area,contextid)`). Eine eigene physische Site-Tabelle ist **nur** eine optionale Phase-3-ANN-Frage (§0/§5),
   hinter dem `search_top_k`-Interface non-breaking nachrüstbar.
3. **Access-Boundary generisch.** Retrieval trägt `contextid`/`owner` immer mit und akzeptiert einen
   optionalen `retrieval_filter`. Docs/Skills lassen ihn leer (global sichtbar); Site-Content verengt darüber
   und filtert autoritativ per `check_access()` (§7). **Verifiziert:** der Filter wird aus einem **engine-freien
   Kontext-Lister** gespeist, NICHT aus `manager::get_areas_user_accesses()` (§7, Korrektur).
4. **Alles im Agent; Abhängigkeit search → agent** (§4). Der Agent ist self-contained (nur Moodle-Core, **kein**
   Such-Plugin nötig); die Search-Areas funktionieren ohne aktivierte Global Search. Eine künftige native
   Such-Engine (`searchengine_wbvector`) ist **optional** und hängt vom Agent ab, **nie umgekehrt**.
5. **Eigene Governance (§5b): Freischaltung + Recht + Seite.** **Default = ALLE Areas AUS** — jede Area×Scope muss
   **einzeln aktiv** eingeschaltet werden (kein Auto-Index, kein Bereich per Default indiziert). Die Seite zeigt
   **Aufwandschätzung (Chunk-Anzahl)** + **Ampel** (Kosten/Query-Tempo); gated durch die eigene Capability
   `bookingextension/agent:configuresitesearch` (default nur Admins, vergebbar).

---

## 2. Layer 0 — Gemeinsamer Contract (einmal, klein, engine-frei)

Neuer Namespace `…\local\wizard\services\retrieval` (engine-agnostisch, kein Wissen über Areas/Domäne).

### 2.1 Interface `embeddings_store`

```php
interface embeddings_store {
    // --- Retrieval: die ANN-Swap-Naht ---
    // Top-K einer Area/Variante, bereits gescored, über minscore. Default-Impl: stream_rows + PHP-Cosine.
    // Löst intern committed_generation aus {bx_agent_embeddings_meta} auf und scannt GENAU diese Generation
    // (WHERE generation=committed) → Reader sehen nie einen halbfertigen Build. Eine ANN-gestützte Area
    // überschreibt das mit serverseitigem Top-K.
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
    public function delete_by_context(int $contextid): void;  // ENTSCHIEDEN: KEIN area-Param. Über ALLE areas; docs/skills haben contextid=NULL → nie getroffen (trifft nur site_content); Events tragen nur contextid.

    // Internes Detail (für die PHP-Cosine-Impl), NICHT öffentlicher Retrieval-Pfad:
    // public function stream_rows(string $area, string $emodel, int $edims): \Generator;
}
```

- **`retrieval_filter`** — trägt erlaubte `contextids` (SQL-Vorverengung, effizient bei großen Korpora) und
  optional den `owneruserid`-Scope. Ist **keine** endgültige Autorität; die autoritative Prüfung bleibt
  `check_access()` beim Aufrufer (§7). DTO: `retrieval_filter(?array $contextids, ?int $owneruserid)`.
- **`embedding_row`** (DTO) — generisch mit optionaler Provenienz:
  `area, owner, refkey, refindex, endindex?, title, emodel, edims, contenthash, embedding(float[])`,
  plus für Site-Content nullbar: `docid?, contextid?, courseid?, owneruserid?`.
- **`embedding_hit`** (DTO) — was `search_top_k` zurückgibt: `owner, refkey, refindex, title, score` +
  Provenienz (`docid/contextid/…`) — **ohne** den Vektor (der bleibt im Store).
- Dünne **Mapper** (`docs_embedding_row_mapper`, `skill_embedding_row_mapper`, später
  `site_content_row_mapper`) übersetzen zwischen `embedding_row` und Area-Semantik — Aufrufer-Code bleibt lesbar.

> **Nachtrag (2026-07-02d, §11.23; Form finalisiert 2026-07-02e):** Für das **streng inkrementelle**
> Site-Indexing wird der Contract **additiv** um doc-granulare Write-Ops in der **committed** Generation
> erweitert:
> - `replace_document(string $area, string $emodel, int $edims, string $owner, string $docid, array $rows)` —
>   **doc-atomar** (kleine Txn) und intern **diff-basiert** per `(refindex, contenthash)`: nur geänderte/neue
>   Chunks schreiben, weggefallene Chunk-Nummern löschen, identische Zeilen **physisch unberührt** lassen
>   (ein Read pro geändertem Doc ist billig; blindes delete+reinsert wäre unnötiger Churn, z. B. wenn nur das
>   Intro geändert wurde).
> - `delete_document(string $area, string $emodel, int $edims, string $owner, string $docid)` — ein Doc
>   entfernen (Events-Pfad).
> - `delete_owner(string $area, string $emodel, int $edims, string $owner)` — eine ganze Sub-Area entfernen;
>   **das** ist der saubere „Deaktivieren = prunen"-Pfad (§5b.3), kontextunabhängig.
>
> **⚠️ `$owner` (= Area-ID) ist in allen dreien PFLICHT** — `docid` ist nur **pro Area** eindeutig
> (mod_page-Doc 5 ≠ mod_book-Doc 5); eine Signatur ohne `$owner` wäre eine Cross-Area-Kollision.
> **⚠️ Generation-Bootstrap:** die Ops schreiben in die aktuell **committete** Generation; existiert noch
> keine Meta-Zeile für die Variante, wird sie mit `committedgeneration=1` angelegt — sonst sieht
> `search_top_k` (scannt `WHERE generation=committed`, committed=0 → `[]`) die inkrementell geschriebenen
> Zeilen **nie**. Der Initial-Build läuft doc-weise über denselben Pfad (Cursor 0), ohne Swap.
> **CSV-Store wirft** auf allen drei Ops (inkrementell ist db-only, hart und fail-closed gegatet).
> **Cursor:** eigene State-Tabelle `{bx_agent_sitesearch_state}(areakey, emodel, edims, indexcursor, timemodified)`
> — Spalte heißt `indexcursor`, weil `cursor` ein MySQL/MariaDB-Reserved-Word ist und Moodle-DML Spaltennamen
> unquoted emittiert —
> — Laufzeit-State (Task-geschrieben, pro Area×Variante), bewusst **getrennt** von der Governance-Config
> `{bx_agent_search_scope}` (Admin-geschrieben, variantenunabhängig): andere Writer, anderer Lebenszyklus.
> Cursor-Fortschreibung nach Core-Muster: auf das `modified` des letzten **erfolgreich** verarbeiteten Docs
> (nicht auf die Laufstartzeit), mit Überlapp gegen Sekundengrenzen (vgl. `manager.php:1262` „-1"-Trick);
> `get_document_recordset` liefert nach `modified` aufsteigend — dadurch ist ein abgebrochener Lauf sauber
> fortsetzbar. Der Generation-Swap (`begin/upsert/commit_generation`) bleibt die Einheit für docs/skills und
> als Repair-Pfad (voller Neuaufbau).

> **Verifiziert (§12):** `search_top_k()` **existiert heute nicht** — nur
> `embeddings_retrieval_service::search_top_k_streaming(array $queryvector, iterable $rows, int $k)`
> (`embeddings_retrieval_service.php:123`). Diese wird die **interne** Cosine-Engine hinter
> `db_embeddings_store::search_top_k()`. Alle bestehenden CSV-Repo-Methoden + Call-Sites zum Invertieren: §12.

### 2.2 Was `embeddings_retrieval_service` behält

`embeddings_retrieval_service::search_top_k_streaming()` (Cosine, O(k)-Speicher, `vector_math::cosine_similarity`)
bleibt die **Default-Engine** hinter `db_embeddings_store::search_top_k()`. **Eine nötige Änderung (verifiziert):**
heute liest die Methode `$row['embedding_json']` + `json_decode` (`:143`) — im DB-Pfad muss sie ein **bereits
dekodiertes** `embedding`-float-Array konsumieren (aus dem BLOB via `unpack('g*')`), sonst müsste der Store
float32→JSON re-serialisieren und der Perf-Vorteil verpufft. Ansonsten wechselt nur die Zeilenquelle CSV→DB, der
Aufruf geht über `search_top_k()` statt direktem Streaming.

---

## 3. Phase 1 — DB-Store für Docs/Skills *(für sich auslieferbar)*

Vollständiges Detail (Schritte/Tests): `embeddings_store_csv_to_db_2026-07-02.md`. **Achtung — dieses Dach-Doc
OVERRIDET das Sub-Doc bei Schema + Interface:** dort fehlen `refkeyhash`, die nullbaren Provenienz-Spalten und
`search_top_k`/`retrieval_filter`/`delete_by_context` (Sub-Doc nennt noch `stream_rows`); maßgeblich ist §2/§3 hier,
nicht das Sub-Doc-Schema. Aufrufer nutzen `search_top_k()` (Layer 0), nicht `stream_rows()`.

**Schema `{bx_agent_embeddings}`** — **DER eine Store für alle Areas** (docs, skills, später site_content),
float32-BLOB, Generation-Swap. Phase 1 füllt nur `area IN ('docs','skills')`; die Site-Provenienz-Spalten sind
**nullbar** und bleiben bis Phase 2 leer:

```
id BIGINT PK · area VARCHAR(32) · owner VARCHAR(255) · refkey VARCHAR(255) · refkeyhash CHAR(40)
refindex INT · endindex INT NULL · title TEXT · emodel VARCHAR(128) · edims INT
contenthash VARCHAR(40) · generation BIGINT · embedding BLOB(float32 LE, edims*4) · timemodified BIGINT
-- Site-Content-Provenienz (nullbar, ab Phase 2 befüllt; für docs/skills NULL):
docid BIGINT NULL · contextid BIGINT NULL · courseid BIGINT NULL · owneruserid BIGINT NULL
Scan-Index:   (area, emodel, edims, generation)
Access-Index: (area, contextid)            -- Vorverengung + delete_by_context (Site-Content)
Unique:       (area, owner, refkeyhash, refindex, emodel, edims)   -- Hash statt langem chunk_path
+ {bx_agent_embeddings_meta}: UNIQUE(area, emodel, edims) → committed_generation BIGINT, fingerprint TEXT, timemodified BIGINT
```

> **Verifiziert (§12/§13):** `embedding` = `XMLDB_TYPE_BINARY` (`lib/xmldb/xmldb_constants.php:54`) → auto
> LONGBLOB/BYTEA/VARBINARY(MAX). `refkeyhash CHAR(40)` (SHA1) hält den 6-Spalten-Unique < MySQL-3072-B-Limit
> (`database_manager.php:830`). float32 via `pack/unpack('g*')` (little-endian; §12/§14). Meta-Tabelle **eigene**
> Tabelle (entschieden), Fingerprint wandert dorthin (weg vom `temp/`-Sidecar → cluster-tauglich).
> **Kein Tabellen-Split** (§0): `area`-Index isoliert die Teilsuchen; die nullbaren Site-Spalten kosten docs/skills nichts.

**Schritte:** P0 Interface-Inversion (CSV hinter `embeddings_store`, Verhalten unverändert, grüne Tests) →
P1 `db_embeddings_store` (float32 pack/unpack, Generation-Swap) → P2 Verdrahtung (Index/Readiness/Lookup +
Feature-Flag `embeddingsstore=csv|db`) → P3 Migration (XMLDB-Upgrade; **existiert die CSV der aktiven Variante UND
ist der Import trivial [Text-Float → `pack('g')`] → importieren; sonst leer lassen → Rebuild-on-first-use**
[bestehender Mechanismus]. Kein Datenmigrations-Zwang [pre-prod]; Temp-Cleanup) → P4 Deprecate CSV nach 1–2 Releases.

**Liefert:** persistenten, cluster-geteilten, gebackupten Store + float32 (~5× kleiner/schneller) für
Docs/Skills — **ohne** auf die Site-Suche zu warten. De-riskt und finanziert Phase 2–3.

---

## 4. Architektur-Leitplanke: alles im Agent — optionale Such-Engine später (Abhängigkeit **search → agent**)

**Feste Regel (Georg):** Der Agent ist **self-contained** und wird **allein** ausgeliefert. Die gesamte
Retrieval-Logik (Store, Indexing, `search_top_k`) lebt **im Agent**. Er benötigt **nie** ein separates
Such-Plugin. Ein künftiges Plugin ist **optional** und hängt vom Agent ab (**search → agent**) — **nie umgekehrt**.

**Warum „alles im Agent" sauber geht (verifiziert):** Wir nutzen die core_search **Areas** — die sind
**Moodle-Core, kein Plugin**. `manager::get_search_areas_list(true)` ist **static** (`manager.php:422`),
`get_document_recordset` (`base.php:317`) und `check_access` (`base.php:423`) sind Area-Methoden — **keine**
davon ruft `manager::instance()` oder eine Engine. Der Agent hängt also nur an Moodle-Core (immer vorhanden,
auch bei deaktivierter Global Search), an **keinem** Such-Plugin.
**Zwei Ausnahmen (Korrekturen):** (a) `manager::get_areas_user_accesses()` IST engine-gated → wir bauen die
User-Kontext-Ermittlung engine-frei nach (§7). (b) **`base::get_document()` IST engine-gated (Korrektur
2026-07-02c)** — `document_factory::instance()` ruft `manager::instance()` und wirft ohne konfigurierte Engine
`engine_exception` (`search/classes/document_factory.php:59-63`) → gelöst über die **Task-scoped
Engine-Session** (Re-Korrektur 2026-07-02f, §11.26): Null-Engine + Manager-Singleton-Seeding für die Dauer des
Index-Laufs, rein im Prozess-Speicher — `get_document()` bleibt damit die Content-Quelle, ohne Engine-,
Server- oder Config-Abhängigkeit (§5.3).

**Jetzt (Pflicht) — In-Agent-Indexing:** ein eigener Scheduled Task im Agent fährt den Loop (§5), schreibt in
die Agent-Tabellen, Retrieval über `search_top_k`. Kein Fremd-Plugin, keine aktive Global Search nötig.
Access-Wahrung: `check_access()` der jeweiligen Area als Pflicht-Post-Filter — Test in §10. *(Das ist die frühere
„Option A", jetzt schlicht* der *Weg — keine A/B-Weiche mehr.)*

**Später (optional, „wenn nicht zu kompliziert") — `searchengine_wbvector`:** ein **dünnes**
`\core_search\engine`-Plugin (`search/engine/wbvector`), das `bookingextension_agent` als **Dependency**
deklariert und nur forwardet:
- `add_document($document, $fileindexing=false): bool` (`engine.php:518`) → Agent-Index (chunk/embed/upsert),
- `execute_query($filters, $accessinfo, $limit=0)` (`engine.php:579`) → Agent-`search_top_k(…, retrieval_filter=$accessinfo)` →
  zurückgemappt auf `\core_search\document`.

Damit wird die **native** Suchbox semantisch — als Zusatzprodukt, das den Agenten braucht, nicht umgekehrt.
Fällt ersatzlos weg, wenn zu aufwändig; der Agent bleibt davon unberührt.

**Kosten der offenen Tür = fast null.** Einzige Disziplin *jetzt*: die Retrieval-Logik im Agent hinter der
sauberen internen API halten (`embeddings_store` + `search_top_k` + Indexing-Service) — bauen wir für Layer 0
ohnehin.

---

## 5. Phase 2 — Site-Search-Indexing *(In-Agent-Task, §4)*

Vollständige Analyse: `semantische_site_suche_embeddings_adapter_2026-06-10.md` (inkl. §11-Nachtrag zur
verifizierten Volltext-Verfügbarkeit). Kern der Indexierung — die Search-API liefert **Was/Links/Wann/Wer**,
wir machen Embeddings+Matching:

1. **Areas aufzählen** — `manager::get_search_areas_list(true)` (**static**, `manager.php:422`), gefiltert auf
   die **kuratierte Whitelist** (§12: die 7 Volltext-Area-IDs).
2. **Inkrementell — VERBINDLICH (2026-07-02d, Georg): streng inkrementell, Full-Rewrite = No-Go.**
   `$area->get_document_recordset(int $modifiedfrom=0, ?\context $context=null)` (`base.php:317`, streamende
   `\moodle_recordset`); **Pflicht** `try { … } finally { $rs->close(); }`. Pro Lauf dürfen **nur geänderte
   Chunks** geschrieben werden; ein Generation-Swap pro Lauf (alle Zeilen neu schreiben, auch wenn die
   Vektoren wiederverwendet werden) ist **verboten** — der Swap bleibt Initial-Build/Repair (und docs/skills).
   Grund: der Swap kann `$modifiedfrom` strukturell nicht konsumieren — die Suche liest
   `WHERE generation = committed`, eine Teil-Generation nur aus geänderten Docs würde beim Commit-Prune alle
   unveränderten Zeilen mitlöschen. Streng inkrementell braucht daher **Doc-Level-Write-Ops im Store**
   (§2.1-Nachtrag). **Vorbild ist Core selbst:** der Core-Indexer persistiert pro Area einen Cursor
   (`…_indexingstart`/`…_lastindexrun` in der Plugin-Config, `search/classes/manager.php:1255/1281/1342`)
   und übergibt ihn als `$referencestarttime` an `get_document_recordset(…)` (`manager.php:1442`); Deletes
   laufen über einen expliziten Hook im Löschpfad (`context::delete()` →
   `\core_search\manager::context_deleted()`, `lib/classes/context.php:649`) — unser engine-freies Pendant:
   eigener Per-Area-Cursor + `db/events.php`-Observer (§13.6), **nie** Rebuilds.
3. **Dokument bauen — RE-KORRIGIERT (2026-07-02f, ersetzt die c-Fassung): via `get_document($record)` innerhalb
   der Engine-Session (§11.26).** `get_document()` ist zwar engine-gated (der Factory-Aufruf ist in jeder Area
   hartkodiert ohne `$engine`-Arg: `base_activity.php:108`, `mod/page/.../activity.php:65`,
   `mod/forum/.../post.php:104` → `document_factory.php:59-63` → `manager::instance()`), aber die **Task-scoped
   Engine-Session** macht ihn produktiv nutzbar. Damit ist das **area-eigene Feld-Mapping** —
   `title`/`content`/`description1` inkl. korrekter Format-Behandlung `content_to_text($content, $format)` —
   die **einzige Content-Quelle**: kein Extraktor-Mapping pro Plugin, kein Quellen-Fork, keine
   Hash-Instabilität; eine neue Area whitelisten = Governance-Config, **null Agent-Code** (Kernanforderung
   Georg — z. B. künftiges mod_booking-Area; auch überschriebene `get_document()`-Implementierungen liefern
   per Definition das Richtige, weil wir ihren eigenen Vertrag aufrufen). Provenienz
   (`contextid/courseid/owneruserid/modified`) direkt aus dem Document. `description1` mitchunken wenn
   vorhanden; `description2` skippen. Files (v1) weglassen (§14.2 = Option später).
4. **Change-Detection** — `contenthash` → unverändert = kein Re-Embed.
5. **Chunking** — ~500-Token-Chunks (~2000 Zeichen, `strlen/4`) mit Overlap; jeder Chunk erbt die Provenienz.
   **Hinweis (verifiziert):** der vorhandene `markdown_chunker` ist char-basiert **ohne** Overlap + heading-orientiert
   → für HTML→Text-Site-Content einen Chunker mit Overlap spezifizieren/adaptieren (Phase-2-Detail, nicht blockierend).
6. **Embedden** — Wunderbyte `generate_embeddings` via `llm_call_service::invoke_embeddings_for_context(...)`
   (`llm_call_service.php:148`). **Verifiziert: KEIN Batching** — ein Call pro Chunk (§12); Batch-Wrapper erst bei
   Bedarf im Index-Service, nie in der Action.
7. **Upsert + Deletes** — Chunks pro `(areaid, docid)` ersetzen; gelöschte Docs
   (`check_access()==\core_search\manager::ACCESS_DELETED` = 2) und Kontexte (`delete_by_context`) prunen.
8. **Cursor + Zeitlimit** — inkrementell fortsetzbar (via `$modifiedfrom`); der **Cursor wird pro Area
   persistiert** (Muster Core `…_lastindexrun`, §5.2) und erst nach erfolgreichem Lauf fortgeschrieben;
   hinter Admin-Toggle + Whitelist.

**Jetzt (In-Agent, §4):** unser Task fährt alle 8 Schritte selbst. **Falls** später der optionale
`searchengine_wbvector`-Adapter kommt, übernimmt der Core die Schritte 1, 2, 7-delete, 8, und wir
implementieren nur 4–7 **innerhalb** `engine::add_document()` + `delete_index_for_context/_course()`.

**Speicherung: kein eigenes Site-Schema — Site-Content lebt in `{bx_agent_embeddings}` (`area='site_content'`, §0/§3).**
Mapping in die generische Identität:
- `owner` = areaid (z. B. `'mod_forum-post'`) · `refkey` = docid · `refindex` = chunkno → Unique
  `(area,owner,refkeyhash,refindex,emodel,edims)` = ein Chunk pro `(areaid, docid, chunkno)`.
- `docid/contextid/courseid/owneruserid` → die **nullbaren Provenienz-Spalten** (§3); `title` = Doc-Titel.
- **Snippet — KORRIGIERT (2026-07-02c, Entscheidung Georg: kein Content im Store, bestätigt):** weiterhin
  **KEIN `content`-TEXT im Store** — der Index speichert Verweise + Vektoren, nicht den Content (keine
  Verdoppelung; hält die Zeile schlank und uniform mit docs/skills, und der Store bleibt bei abgeleiteten Daten →
  minimale Privacy-Fläche). Die **finalen K** Treffer (nach `check_access`) werden zur **Query-Zeit via
  `get_document()` in einer kurz geklammerten Engine-Session** (§11.26) re-extrahiert und `chunk[refindex]`
  als Snippet gezeigt — **dieselbe Quelle wie beim Indexing, nur so stimmen die `contenthash`-Vergleiche**
  (bounded: nur K Docs; Einzel-Doc-Fetch über den context-gescopten `get_document_recordset(0, $modulkontext)`).
  Dazu verbindlich:
  - **Fail-soft:** existiert `chunk[refindex]` nicht mehr (Content gekürzt/geändert) → Snippet weglassen bzw.
    auf den ersten Chunk zurückfallen, **nie** Exception. Fehler-Richtung „kein Snippet", nie falscher Fehler.
  - **Selbstheilung:** `sha1` des re-extrahierten Chunks gegen den gespeicherten `contenthash` prüfen —
    gleich = Snippet ist beweisbar exakt der embeddete Chunk; ungleich = Doc als **stale** markieren und fürs
    nächste Indexing vormerken (jede Suche wird nebenbei zum Freshness-Detektor).
  - **Chunker-Version in den Area-Fingerprint** aufnehmen → ein Chunker-Wechsel erzwingt den Rebuild und hält
    Query-Zeit-Chunking und Index aligned (schließt das Deploy-bis-Rebuild-Fenster).
  - **Konsequenz:** Staleness wird über **Indexierungs-Frische** gelöst — `modifiedfrom`-Cursor (§5.8) +
    `db/events.php`-Observer (§13.6) sind damit **tragend** für die Snippet-Korrektheit, nicht optional.
  Access-/Delete via `(area, contextid)`-Index.

> **Optionale Phase-3-ANN-Note (nur wenn ANN gebaut wird):** Erst wenn pgvector/MariaDB-VECTOR zum Einsatz kommt,
> kann sich ein **separater** Site-Store lohnen — weil ein Vektor-Index global nächste Nachbarn liefert und
> MariaDB/MySQL keine partiellen Indizes (`WHERE area=…`) können, würden Docs/Skills-Vektoren mitgemischt. Dann
> (und nur dann): `area='site_content'` in eigene `{bx_agent_search_chunk}` + `{bx_agent_search_vector}` (Vektor von
> Content/Provenienz getrennt) auslagern — **hinter demselben `search_top_k`-Interface, ohne Aufrufer-Änderung**.
> Bis dahin: unified. Diese Entscheidung fällt in Phase 3 mit realen Zahlen, nicht jetzt.

---

## 5b. Governance-Seite — Indizierung freischalten (pro Kurs/Plugin) + Aufwandschätzung + Ampel

**Default = alles AUS.** Kein Auto-Index; **jede Area×Scope muss einzeln aktiv freigeschaltet werden**. Ein Admin
schaltet frei, was indiziert wird, und sieht dabei **sofort** die geschätzte Chunk-Anzahl + Ampel. Eigene Seite im
Muster von `skill_governance.php`, eigenes Recht.

### 5b.1 Eigenes Recht (Capability)

- **`bookingextension/agent:configuresitesearch`** — gated die Seite + jedes Freischalten/Umschalten.
- `db/access.php` (existiert): Muster `runbenchmarks`/`manageaiproviders` (leere `archetypes`) — Eintrag:
  `['captype'=>'write','contextlevel'=>CONTEXT_SYSTEM,'archetypes'=>[],'riskbitmask'=>RISK_CONFIG,'clonepermissionsfrom'=>'moodle/site:config']`
  → default nur Admin (via `moodle/site:config`), aber **frei an beliebige Rollen vergebbar**.
- Serverseitig hart geprüft: `require_capability('bookingextension/agent:configuresitesearch', context_system::instance())`
  auf der Seite **und** an jedem Toggle-Webservice.

### 5b.2 Eigene Seite (wie skill_governance)

> **Hartes Gate (§16):** Die gesamte Site-Suche (Governance-Seite, Index-Task, Retrieval) ist auf
> `docs_embeddings_readiness_service::is_embeddings_provider_available()` (= `class_exists(aiprovider_wunderbyte\aiactions\generate_embeddings)`)
> gegatet. Ist die Voraussetzung nicht erfüllt (kein `aiprovider_wunderbyte` / Moodle < 5), zeigt die Seite schlicht
> den **Hinweis „Benötigt `aiprovider_wunderbyte` und Moodle 5"** und bietet **keine** Toggles; nichts wird indiziert.
> Kein Fallback — Semantik ohne Embeddings gibt es nicht (Keyword-Suche wäre Moodles native Suche).

- `sitesearch_governance.php` — Struktur/Style **1:1** von `skill_governance.php` (manuelles Page-Setup +
  `require_capability(…, context_system::instance())` (vgl. `skill_governance.php:56`) + sesskey-POST). In
  `settings.php` als **hidden** `admin_externalpage` registrieren (Muster `skill_governance` bei
  `settings.php:399-405`) + Link in der Agent-Seitenliste. Rendert Freischalt-Matrix + Schätzung + Ampel.

### 5b.3 Freischaltung: pro Plugin (Area) UND pro Kurs

- **Pro Plugin/Area:** Whitelist der content-tragenden Areas (§12) — je Area ein Toggle.
- **Pro Kurs (Scope):** *alle Kurse* / *ausgewählte Kurse* / *Kategorie(n)* / *Ausschlussliste*. Auch der
  Site-Kurs (Front page) ist ein gültiger Scope.
- **Persistenz:** `{bx_agent_search_scope}(id, area, scopetype ['site'|'course'|'category'], scopeid, enabled TINYINT,
  usermodified, timemodified)`, Index `(area, scopetype, scopeid)`. Der Index-Task (§5) respektiert diese
  Freischaltung als harte Quelle der Wahrheit (nur freigeschaltete Area×Scope werden indiziert; Deaktivieren →
  `delete_by_context` der betroffenen Kontexte).

### 5b.4 Aufwandschätzung im Interface (live, ohne zu embedden) — **nur Chunk-Anzahl, KEINE Preise**

> **Entscheidung (Georg):** Wir rufen **keine** Modell-Preise ab und rechnen **keine** €-Kosten. Der Estimator
> signalisiert ausschließlich über die **geschätzte Chunk-Anzahl + Ampel** (5b.5). Grund: es existiert ohnehin
> keine Preisquelle im Code (verifiziert: grep über `ai/`/Provider/Agent = leer), und die Chunk-Anzahl ist das
> ehrliche, provider-unabhängige Signal für „viel/teuer/langsam".

> **KORREKTUR (2. Verifikation):** core_search bietet **keinen** generischen `COUNT(*)`/Basistabellen-Zugang — der
> einzige öffentliche Weg ist `get_document_recordset($modifiedfrom, $context)`. „Direkter COUNT(*) auf
> Basistabelle+timemodified" ist zudem für 6 der 7 Areas falsch (forum hat Spalte `modified` statt `timemodified` →
> DML-Fehler; wiki/section haben Extra-Filter; Pflicht-Joins; die Scope-Join-Helfer sind `protected`). Daher zählt
> der Estimator über den **öffentlichen Recordset** — Scope kommt gratis über den `$context`-Parameter (verifiziert).

Ein `index_scope_estimator`-Service liefert je Area×Scope ein **`index_estimate`**-DTO — **ohne** einen einzigen
Embedding-Call und **ohne** Preis-Lookup:

```
index_estimate {
  doccount   int     // gekappte Row-Zählung über get_document_recordset($modifiedfrom=0, $scopecontext),
                     //   foreach OHNE get_document(); Abbruch bei Erreichen der Rot-Schwelle → Anzeige ">N"
  estchunks  int     // doccount × Ø-Chunks (aus Content-Längen-Sample) — DAS Signal
  ampel      string  // 'green' | 'yellow' | 'red', rein aus estchunks (5b.5)
}
```

- **So billig wie möglich (nicht gratis):** `foreach` über das Recordset **ohne** `get_document()` pro Zeile (der
  teure Pfad), mit `finally { $rs->close(); }`; bei Erreichen der Rot-Schwelle abbrechen und „>N" zeigen. Kurs-/
  Kategorie-Scope über den `$context`-Parameter (CONTEXT_COURSE/COURSECAT liefern nur die scope-eigenen Rows).
- **Ø-Chunks** aus einem kleinen Sample: `get_document()` in einer kurz geklammerten **Engine-Session**
  (§11.26; admin-only Seite, `finally`-Restore) auf `min(30, doccount)` Docs → Content-Länge / ~2000 Zeichen
  (≈500 Token via `strlen/4`). Öffnet denselben Recordset bounded — kein Tokenizer, keine API-Calls (synchron).
- **Kein `estcostcents`, kein `esttokens`, kein Preis-Setting** — bewusst weggelassen (Georg).
- **Cache:** MUC `MODE_APPLICATION`, TTL 5–15 min, gekeyt Area+Scope (Cache-Definition neu in `db/caches.php`, §13).
- Anzeige: `doccount`/`estchunks` + Ampel pro Zeile (Area×Scope) und als **Summe** der aktuellen Auswahl.

### 5b.5 Ampel-System (rein aus der Chunk-Anzahl)

Die Ampel ist ein einziges Blick-Signal, gespeist **allein** aus der geschätzten **Chunk-Anzahl** (`estchunks`,
5b.4) — **keine** €-/Preis-Dimension (Georg). Sie signalisiert „viel Content = teurer Rebuild **und** langsamere
PHP-Cosine-Query". Schwellen als Admin-Settings (Defaults):

- 🟢 **grün** — `< 2.000` Chunks: klein, PHP-Cosine schnell → sofort freischaltbar.
- 🟡 **gelb** — `2.000–20.000`: spürbarer Rebuild + Latenz, mit Bedacht.
- 🔴 **rot** — `> 20.000` **ohne** ANN: großer Rebuild **und** O(N)-PHP-Cosine wird pro Query langsam → erst
  **ANN-Fast-Path** (§14.1) aktivieren **oder** Scope enger ziehen.

- **Kontextsensitiv:** ist ein ANN-Fast-Path verfügbar (`db_vector_engine_detector`, §14.1), wird die
  Query-Tempo-Sorge entschärft → rot kann zu gelb/grün werden. „Rot" heißt konkret: *bei aktueller
  Retrieval-Engine würde dieser Scope die Suche langsam machen.*

---

## 6. Phase 3 — Site-Search-Retrieval + Skill + ANN-Fast-Path *(braucht Phase 2)*

- **Retrieval** über `search_top_k('site_content', …, $filter)`:
  - `retrieval_filter` = erlaubte `contextids` aus dem **engine-freien Kontext-Lister** (§7, **nicht**
    `get_areas_user_accesses()`) — SQL-Vorverengung.
  - **Over-fetch** (k' ≫ k), dann autoritativer `check_access($docid)`-Filter der zuständigen Area
    (In-Agent jetzt) bzw. nativer `accessinfo`-Filter in `execute_query()` (optionaler Engine-Adapter). Siehe §7.
- **Skill** `core.find_content` (bzw. `search_site`) — reine Skill-Schicht: nimmt Query, ruft `search_top_k`,
  baut Treffer (Titel, Snippet, Deep-Link via `$area->get_doc_url($doc)`). Engine bleibt clean (routet nur).
- **ANN-Fast-Path** — `db_vector_engine_detector` (§14.1, nach PDF-Reader-Muster): bei pgvector/MariaDB-VECTOR
  eine alternative `site_content`-`search_top_k`-Implementierung (Cosine+Top-K serverseitig, nur K Zeilen
  zurück). Schmerzfrei einschiebbar, weil das Interface (§2) von Tag 1 stimmt. Bis dahin: PHP-Cosine mit
  `contextid`-Vorverengung (bei schmalem Zugriff tragbar; bei breitem Zugriff = Grund für den Fast-Path).

---

## 7. Access-Modell (Make-or-Break, End-to-End) — KORRIGIERT (engine-frei)

Der Index ist **global**, die **Sicht ist pro User** — dasselbe Prinzip wie Moodle mit Solr, **aber ohne
Engine-Abhängigkeit**:

1. Index enthält alle Chunks mit `contextid`/`owneruserid` (Schritt 3/5 der Indexierung).
2. **⚠️ KORREKTUR (verifiziert):** die erlaubten Kontexte des Users **NICHT** über
   `manager::get_areas_user_accesses()` holen — die Methode ist **`protected`** (`manager.php:704`) und nur über
   `manager::instance()` erreichbar, das bei leerem `$CFG->searchengine` `engine_exception` wirft
   (`manager.php:217-219`). Das würde uns an eine aktive Such-Engine koppeln (Widerspruch zu §4). Stattdessen
   **engine-freier Kontext-Lister** im Agent. **Ehrliche Einordnung (verifiziert):** er ist eine **sichere
   Teilmenge** der Core-Logik (kein 1:1-Nachbau) — Fehler-Richtung „fehlende Treffer", **nie Leak** (autoritativ ist
   ohnehin `check_access`, Schritt 3). Für die v1-Whitelist **B** (inkl. `core_course-course`/`-section`) wird er um
   sichtbare-nicht-eingeschriebene Kurse + Frontpage **erweitert**, damit dort keine legitimen Treffer verschwinden:
   ```
   is_siteadmin($userid)                         → {everything:true}  // kein Filter
   else:
     $courses = enrol_get_users_courses($userid, true)              // eingeschriebene, sichtbare Kurse
              ∪ sichtbare NICHT-eingeschriebene Kurse (can_view_course_info)   // für course/section (B)
              ∪ SITEID/Frontpage                                    // Frontpage-Content (B)
     foreach $courses:
       $modinfo = get_fast_modinfo($course, $userid)
       foreach whitelisted Modul-$modname:
         foreach $modinfo->get_instances_of($modname) as $cm:
           if ($cm->uservisible) collect (context_module::instance($cm->id))->id
       // + context_course::instance($courseid)   für course/section-Areas (B)
     → contextids[]  →  retrieval_filter
   ```
   → in `search_top_k` als SQL-Vorverengung (`contextid IN (...)`), hält den Scan bei großen Korpora klein.
   Gruppen-Trennung (separate groups) bewusst **nicht** im Vorfilter — autoritativ von `check_access` erledigt
   (z. B. `forum_user_can_see_post`); dafür `k'` (Over-fetch) großzügig, damit gruppen-fremde Kandidaten keine echten
   Treffer aus der Top-K verdrängen, bevor `check_access` greift.
3. **Autoritative Prüfung**: über die verengte Kandidatenmenge läuft `area->check_access($docid)`
   (`base.php:423`) → `\core_search\manager::ACCESS_GRANTED|DENIED|DELETED` (=1/0/2, `manager.php:53-63`) — das
   ist die Domänen-Logik, engine-frei. Beim optionalen `searchengine_wbvector`-Adapter erledigt das später der
   Core-Engine-Vertrag (`accessinfo`).
4. Docs/Skills: `retrieval_filter=null` (global sichtbar), keine `check_access`-Runde nötig.

**Nie** Top-K ohne diesen Filter zurückgeben → sonst Leak fremder Kurse/versteckter Aktivitäten. **Kein**
Core-Patch (`get_areas_user_accesses` public/static) — Wartungslast, verworfen.

---

## 8. Reihenfolge & Schneidbarkeit

```
Layer 0 (Contract)  ──►  Phase 1 (DB-Store Docs/Skills)  ──►  [auslieferbar, Betriebsgewinn]
        │
        └──►  §4-Leitplanke (alles im Agent)  ──►  Phase 2 (Governance-Seite §5b + Site-Indexing)  ──►  Phase 3 (Retrieval+Skill+ANN)  ┄┄►  [optional] searchengine_wbvector (depends on agent)
```

- **Layer 0 + Phase 1** hängen an nichts und liefern sofort Wert (csv→db).
- **Phase 2** beginnt mit der **Governance-Seite (§5b)** — ohne Freischaltung wird nichts indiziert, und die
  Schätzung/Ampel entscheidet, *ob* man einen Scope freischaltet. Der Index-Task kommt danach und respektiert
  `{bx_agent_search_scope}`.
- **Phase 2–3** dürfen eigenes Tempo/Unsicherheit haben, ohne Phase 1 zu blockieren.
- Einziger harter Kopplungspunkt: das `embeddings_store`-Interface (Layer 0) — deshalb zuerst.

---

## 9. Risiken (inkl. verifizierter Gotchas)

1. **Interface zu eng geschnitten** (nur `stream_rows`) → Site-Suche später blockiert. → `search_top_k` ab
   Layer 0 (die #1-Vorgabe).
2. **Access-Leak** bei fehlendem/fehlerhaftem Post-Filter (höchstes Risiko) → im In-Agent-Betrieb: Pflicht-
   `check_access` + Tests, die einen unberechtigten User gegen einen Treffer prüfen (§10). Verstärkt durch die
   Engine-Frei-Korrektur (§7): der Kontext-Lister ist Vorfilter, `check_access` autoritativ.
3. **DB-Last** — Docs/Skills unkritisch; Site-Content-Scan pro Query = echter Druck auf die geteilte DB →
   `contextid`-Vorverengung + ANN-Fast-Path (Site-Content). **MUC-Cache-Vorbehalt:** falls dekodierte Vektoren
   gecacht werden, **gepackte Bytes** cachen, NICHT die PHP-float-Arrays (letztere ~10× größer im RAM). Für
   Docs/Skills-Skala genügt der DB-Buffer-Pool → MUC-Cache dort weglassen.
4. **float32/Endianness** — fix little-endian (`pack/unpack('g*')`, `'g'` NICHT `'f'/'G'`), Konstante
   `EMBEDDING_PACK_FORMAT='g*'` + `strlen($blob)===edims*4`-Guard + Roundtrip-Test.
5. **Generation-Pruning idempotent** — abgebrochener Rebuild darf keine verwaisten Generationen lassen
   (Prune-on-commit + „orphan generation"-Cleanup im Task). **Kein Massen-DELETE:** commit = kurze `UPDATE` der
   Meta-Zeile, altes Prune in Batches ~5.000–10.000 via `delete_records_select` (sonst InnoDB-Exklusiv-Lock, §12).
6. **BLOB-Bulk-Insert (KORRIGIERT):** `insert_records()` chunkt Multi-Row auf **beiden** Treibern (MySQL + PG
   je ~500/Chunk) **und** warnt im Docblock explizit **gegen Binärfelder** — genau unser float32-BLOB. → für die
   Vektor-Zeilen `insert_record()` in einer `start_delegated_transaction`-Schleife (umgeht Binärfeld-Warnung +
   MySQL-`max_allowed_packet`), Generation-Swap batched (§12).
7. **Kosten** — Site-Content ganze Site embedden = echtes Geld → Whitelist + inkrementell + Content-Hash
   zwingend; Docs/Skills-Migration per **Import der aktiven Variante** statt Re-Embed. **Keine €-Berechnung**
   (Georg): Kosten werden nur über die **Chunk-Anzahl + Ampel** (§5b.4/5b.5) signalisiert, kein Modell-Preis-Lookup.
8. **`generate_embeddings` kann NICHT batchen** (verifiziert) — ein Call pro Chunk; Batch erst bei Site-Scale im
   Index-Service, nie in der Action.
9. **Privacy** — `{bx_agent_embeddings}`-Site-Zeilen tragen `owneruserid` + user-verfassten Content (Forum/Glossar/Wiki) →
   Privacy-Provider PFLICHT vor Phase 2 (§13.7, entschieden).
10. **Optionaler Engine-Adapter (später)** — als aktive Such-Engine ersetzt `searchengine_wbvector` die
    Site-Engine (nur eine aktiv) und muss ANN mitbringen. Erst relevant, wenn dieses Zusatzprodukt gebaut wird.

---

## 10. Testing

- **Layer 0/Phase 1:** float32-Roundtrip (pack→unpack bit-exakt); `db_embeddings_store` upsert/reuse/commit/prune;
  `search_top_k` **Parität** gegen CSV-Fixture (identische Top-K); Korrupt-BLOB-Guard; Readiness `missing→ready`.
- **Phase 2:** Indexing-Roundtrip pro Whitelist-Area (`get_document()` in Engine-Session →chunk→embed→upsert);
  **Session-Hygiene-Tests:** `end()` räumt Singleton + `document_factory`-Statics auch im Exception-Fall
  (`finally`), und die Session schreibt NIE Config (kein `set_config` — §11.26-Fallstrick); inkrementeller
  Re-Index (nur geänderte Docs); `delete_by_context` prunt korrekt; Kontext-Lister liefert erwartete contextids.
- **Phase 3 (Access, kritisch — mehrere Fälle):** (a) Treffer in Kurs X, User **ohne** Zugang → bekommt ihn
  **nicht** (weder Vorfilter noch `check_access`); (b) **separate-groups**-Forentreffer eines Kurses, in dem der User
  Mitglied einer *anderen* Gruppe ist (`uservisible=true` → Vorfilter lässt durch) → muss via `check_access`=DENIED
  **verschwinden**; (c) course/section-Summary eines **sichtbaren, nicht-eingeschriebenen** Kurses + Frontpage →
  berechtigter User **findet** ihn (B-Erweiterung greift). Der berechtigte User bekommt in allen Fällen den Treffer.
- phpcs/phpdoc 0/0 durchgehend (auch local_moodlecheck: keine `array<…>`-Generics/`array{…}`-Shapes in @param).

---

## 11. Entscheidungen (alle getroffen)

**Alle Entscheidungen getroffen (2026-07-02b) — nichts mehr offen:**
1. **Architektur (§4):** alles im Agent, self-contained; optionale `searchengine_wbvector` später (search→agent). Keine A/B-Weiche.
2. **Layer 0 zuerst (P0), dann P1 DB** — Contract inkl. `retrieval_filter` + **eingefrorene Signaturen** vor DB-Komplexität.
3. **`retrieval_filter` in Phase 1 designen + testen** (docs/skills `filter=null`), echte contextids Phase 2.
4. **Tabellen (`bx_agent_`-Konvention):** `bx_agent_embeddings`, `bx_agent_embeddings_meta` (eigene, 1 Zeile/`(area,emodel,edims)`),
   `bx_agent_search_scope`. **EIN `area`-Table** — kein Split (Split nur optionale Phase-3-ANN-Frage, §0/§5); nullbare Site-Provenienz + `(area,contextid)`-Index.
5. **Fingerprint** im DB-Pfad nur in der Meta-Tabelle (CSV-Adapter behält Sidecar in der Übergangsphase).
6. **Volle Generation-Swap-Impl** auch in Phase 1; `search_top_k` scannt die **committed_generation** (§2.1).
7. **float32 `pack('g*')` (bleibt)**; **Insert per `insert_record()`-Loop in Transaktion**, NICHT `insert_records()` (Binärfeld-Warnung, §9.6/§12).
8. **Batching aufgeschoben** — Action single-input; Batch erst bei Site-Scale im Index-Service.
9. **Keine Preisabfrage** — Estimator zeigt nur Chunk-Anzahl + Ampel; **Estimator zählt über den Recordset** (kein generischer COUNT, §5b.4-Korrektur).
10. **`delete_by_context(int $contextid)`** — kein `area`-Param (§2.1).
11. **Migration:** aktive-Varianten-CSV vorhanden + Import trivial → importieren; sonst leer → Rebuild-on-first-use. Kein Zwang (pre-prod). (§3-P3.)
12. **Whitelist v1 = B:** alle 7 Area-IDs inkl. `core_course-course`/`-section` (§12) — dafür Kontext-Lister **erweitert** (`can_view_course_info` + SITEID, §7).
13. **Default = ALLE Areas AUS.** Jede Area×Scope muss **einzeln aktiv** freigeschaltet werden (§5b.3).
14. **Capability** `bookingextension/agent:configuresitesearch`, admin-only (leere `archetypes`) + frei vergebbar (§5b.1).
15. **Ampel-Defaults** (aus Chunk-Anzahl, §5b.5): grün `<2.000` / gelb / rot `>20.000` ohne ANN. Keine €-Dimension.
16. **MUC-Cache** der Vektoren in Phase 1 **weglassen** (DB-Buffer-Pool reicht).
17. **Privacy-Provider wird gebaut** (§13.7), vor Phase 2 fertig.
18. **Site-Suche = Moodle 5 + `aiprovider_wunderbyte`** (§16, hart gegatet, kein Fallback); Phase 1 4.5-inert-safe.
19. **Governance-Seite = Start von Phase 2** (kein vorgezogener Mini-Meilenstein).
20. **`embeddings_retrieval_service`** konsumiert im DB-Pfad ein **dekodiertes** Array (nicht `embedding_json`, §2.2).
21. **Neue Arch-Doku** `docs/architecture/17-retrieval-foundation.md`; `06-…embeddings.md §8` nach P1 als stale markieren (§15).
22. **Snippet-Strategie (Nachtrag 2026-07-02c, Georg): KEIN Content/Snippet im Store.** Der Index speichert
    Verweise + Vektoren, nicht den Content (keine Verdoppelung); zeigt die Suche stale Content, ist das ein
    **Frische-Problem** (bessere Indexierungs-Trigger / öfter indizieren), kein Grund, Content zu kopieren.
    Snippets zur Query-Zeit für die finalen K re-extrahiert *(Mechanismus per §11.26: `get_document()` in
    kurzer Engine-Session statt eigenem Extraktor)* — fail-soft,
    `contenthash`-Selbstheilungs-Check, Chunker-Version im Fingerprint (§5-Storage-Note). `get_document()` ist
    engine-gated *(Mechanismus-Teil überholt durch §11.26: via Engine-Session doch produktiv nutzbar — die
    Kernentscheidung „kein Content im Store" bleibt unverändert)*. Eine Store-Snippet-Spalte (Alternative A)
    wurde erwogen und **verworfen**.
23. **Site-Indexing STRENG INKREMENTELL (Nachtrag 2026-07-02d, Georg):** pro Lauf werden ausschließlich
    geänderte Chunks geschrieben; regelmäßiges Neuschreiben aller Zeilen (Full-Generation-Swap pro Lauf) ist
    ein **absolutes No-Go** — auch wenn die Vektoren dabei wiederverwendet werden (keine Embed-Kosten ≠ keine
    Write-Kosten). Umsetzung: Doc-Level-Write-Ops im Contract (§2.1-Nachtrag), persistierter Per-Area-Cursor
    (Core-Muster `…_lastindexrun`, §5.2), Deletes ausschließlich via `db/events.php` (§13.6). Der Swap bleibt
    Initial-Build/Repair + docs/skills. *(Hintergrund: der erste Implementierungs-Slice hatte das
    Docs-Swap-Modell übernommen, das `$modifiedfrom` strukturell nicht konsumieren kann → wird umgebaut.)*
24. **Skills-Area JETZT auf den Store verdrahten (Nachtrag 2026-07-02d, Georg):** der bewusste P2-Scope-Cut
    (`family_embeddings_index_service`, `embeddings_readiness_service`, `skill_selection_debug_service`,
    `rebuild_skill_catalog_embeddings_adhoc` hängen direkt am CSV) wird nachgezogen; dafür bekommt der Store
    eine Multi-Vector-taugliche Erweiterung (die Family-Aggregation `search_top_k_skills`/`score_families`
    passt nicht auf die `search_top_k`-Naht). Bis dahin ist die Flag-Semantik `embeddingsstore=db`
    unvollständig (wirkt nur auf docs/site_content) — dieser Zustand ist NICHT auslieferbar.
25. **Entscheidung 19 BESTÄTIGT (Nachtrag 2026-07-02d, Georg):** Governance-Seite + Capability
    `bookingextension/agent:configuresitesearch` + `{bx_agent_search_scope}` kommen **vor** dem Commit des
    Site-Search-Schnitts. Ein rohes Admin-Setting (Multicheckbox) ist kein zulässiger Ersatz.
26. **Engine-Session: `get_document()` wird die EINZIGE Content-Quelle (Nachtrag 2026-07-02f, Georg).**
    Ersetzt den Mechanismus-Teil von ↻ 3/§11.22 (der eigene Extraktor wird zurückgebaut); „kein Content im
    Store" (§11.22) und „streng inkrementell" (§11.23) bleiben unverändert.
    **Umsetzung:** agent-eigene **Null-Engine** (`extends \core_search\engine`,
    `get_document_classname()` → Basisklasse, alle Ops No-op, kein File-Indexing) + `task_search_session
    extends \core_search\manager` mit `begin()`/`end()` — Seeding des protected-static Manager-Singletons nach
    Cores eigenem Fixture-Muster (`search/tests/fixtures/testable_core_search.php:52`), direkt über den
    Konstruktor (umgeht die Schema-Check-/`lastschemacheck`-Writes von `manager::instance()`). Immer geklammert:
    `begin(); try { … } finally { end(); }` — `end()` nullt den Singleton und ruft
    `document_factory::clean_static()` (so heißt die Core-Methode wirklich; der Docblock in
    `document_factory.php:43` nennt sie fälschlich `clean_statics`).
    **Gewinn:** kein per-Plugin-Mapping-Code (Area whitelisten = Governance-Config statt Agent-Code, inkl.
    künftigem mod_booking-Area); kein Quellen-Fork, keine `contenthash`-Instabilität; die Override-Gefahr
    fremder `get_document()`-Implementierungen entfällt, weil wir ihren eigenen Vertrag aufrufen.
    **Isolations-Garantien:** reines Prozess-Speicher-Seeding — **kein einziger DB-/Config-/Cache-Write**;
    parallele Web-Requests und andere Cron-Worker (eigene Prozesse) sehen nichts; ein Prozess-Crash hinterlässt
    null Spuren; einziger Sharing-Punkt sind Folge-Tasks im selben Cron-Prozess → durch `finally { end(); }`
    abgedeckt.
    **⚠️ Fallstrick:** Cores Fixture ruft `set_config('enableglobalsearch', true)` (`testable_core_search.php:59`)
    — in PHPUnit wird das zurückgerollt, in Produktion würde es **persistieren** (Suchbox + Core-Cron-Indexing
    sitewide!). Die Produktions-Session darf **niemals** `set_config` aufrufen — nur Speicher-Seeding.
    **Scope:** Index-Task; Query-Zeit-Re-Extraktion der finalen K fürs Snippet (kurz geklammert — dieselbe
    Quelle wie beim Indexing ⇒ `contenthash`-Konsistenz); Estimator-Samples (§5b.4, admin-only). Deep-Links
    weiterhin via direkt konstruiertem `\core_search\document` + `$area->get_doc_url($doc)` (Konstruktor public
    + engine-frei, `document.php:216-222`).
    **Tests:** Session-Hygiene (Cleanup auch bei Exception; keine Config-Writes) + Content-Erwartung mod_page;
    der früher angedachte Extraktor-Parity-Test entfällt ersatzlos.
    **Plan B** (falls Core das Fixture-Muster je bricht): in-memory `$CFG->searchengine = 'simpledb'` im Task +
    Singleton-Purge — gleiche Wirkung, mehr bewegliche Teile (simpledb-Präsenz, `is_server_ready`, Schema-Check).
27. **Dynamische Area-Enumeration statt fixer Whitelist (Nachtrag 2026-07-02g, Georg):** Ersetzt die
    kuratierte Whitelist (§11.12/Variante B) — Konsequenz aus §11.26: ohne per-Area-Mapping-Code gibt es
    keinen Grund mehr, Areas hart zu kodieren. Die Registry enumeriert **alle** core_search-Areas dynamisch
    (`manager::get_search_areas_list()` — static, engine-frei, §12), inklusive Third-Party (künftiges
    mod_booking-Area). Die **Governance-Seite ist das einzige Gate** (Default weiterhin ALLE aus, §11.13);
    Cores Area-Enable-Flag wird bewusst ignoriert (gehört zur Global-Search-Konfiguration, nicht zu uns).
    Labels via `$area->get_visible_name()` (löst zugleich die dynamische-Lang-Key-Falle). Damit SOFORT fällig:
    (a) Kontext-Lister-§7-B-Erweiterung (Kurs-Kontexte: eingeschrieben + sichtbar-nicht-eingeschrieben +
    Frontpage) für die course-/section-Areas; Areas auf anderen Kontext-Leveln bleiben fail-closed
    (Prefilter liefert nichts — kein Leak) und werden auf der Seite entsprechend markiert;
    (b) **Privacy-Provider** (§13.7, Entscheidung 17) — mit freischaltbaren User-Content-Areas
    (Forum/Glossar/Wiki) ist das Gate scharf, der Provider wird JETZT gebaut.
28. **Kontext-spezifische Governance: Kursbereich-/Kurs-Scopes (Nachtrag 2026-07-02h, Georg):**
    Freischaltung UND `includefiles` werden pro Scope steuerbar (`{bx_agent_search_scope}` trägt das
    Datenmodell bereits). Kaskade: Kurs-Zeile > tiefste Kategorie-Zeile am Pfad > Site-Zeile >
    Default AUS — die spezifischste Zeile gewinnt KOMPLETT (enabled+includefiles als Paar);
    Kategorie-Vererbung pfad-basiert. Indexer-Enforcement über zwei Lese-Strategien
    (allowlist = context-gescopte Recordsets, blocklist = globaler Scan + courseid-Skip);
    Regel-Änderungen = **Delta-Sync per sofortigem Adhoc-Task** (Backfill/Prune, neue Store-Op
    `delete_owner_in_course`), NIE Site-Rebuild — damit wandert die `|files:`-Komponente wieder AUS
    dem Fingerprint (der trägt nur noch Pipeline-Versionen). Estimator/Ampel pro Scope +
    Gesamt-Ampel. UI = Regel-Liste mit Pickern (bevorzugt `\core_form\dynamic_form`), kein Baum.
    **Maßgebliches Detail-Konzept: `sitesearch_context_governance_2026-07-02.md`** (alle vier
    Einzel-Entscheidungen dort in §8).

**Erster Cut:** **Layer 0 P0** — Interface-Inversion mit eingefrorener Signatur, CSV hinter `embeddings_store`, grüne Tests als Netz.
**Nicht-blockierendes Phase-2-Detail:** konkreter Chunker mit Overlap für HTML→Text-Site-Content (`markdown_chunker` ist char-basiert ohne Overlap, §5.5).

---

## 12. Verifizierte API-Oberfläche + Alt→Neu-Map (Belege)

**core_search (engine-frei außer wo markiert):**

| Element | Signatur / Wert | Datei:Zeile |
|---|---|---|
| Area-Aufzählung | `manager::get_search_areas_list(bool $enabled=false): array` (**static**, keyed nach Area-ID) | `search/classes/manager.php:422` |
| Recordset | `base::get_document_recordset(int $modifiedfrom=0, ?\context $context=null)` → `\moodle_recordset\|false`; `close()` Pflicht | `search/classes/base.php:317`; `moodle_recordset.php:76` |
| Dokument (engine-gated → via **Engine-Session** nutzbar, 2026-07-02f) | `base::get_document()` → `document_factory::instance()` → `manager::instance()` (wirft ohne Engine) → produktiv nutzbar **innerhalb der Task-scoped Engine-Session** (§11.26); Feld-Map: `document::get('title'\|'content'\|'description1'\|'description2'\|'contextid'\|'courseid'\|'owneruserid'\|'modified')` | `document_factory.php:59-63`; `document.php:344`, Felder `107-194` |
| Extra-Provenienz | `document::set_extra()` (set() lehnt unbekannte Felder ab) | `document.php:331` / `276-281` |
| Access | `base::check_access($id)` → `manager::ACCESS_DENIED=0/GRANTED=1/DELETED=2` | `base.php:423`; `manager.php:53-63` |
| ⚠️ User-Access (engine-gated!) | `manager::get_areas_user_accesses()` **protected**; `instance()` wirft ohne `$CFG->searchengine` | `manager.php:704` / `217-219` → §7-Ersatz |
| Area-ID-Format | `manager::generate_areaid($component, $areaname)` = `"$component-$areaname"` | `manager.php:642` |
| Engine (optional) | `engine::add_document($doc,$fileindexing=false):bool`; `execute_query($filters,$accessinfo,$limit=0)` | `engine.php:518` / `579` |

**v1-Whitelist Area-IDs (Content-Feld bestätigt):** `mod_page-activity` (content), `mod_book-chapter` (content),
`mod_forum-post` (message), `mod_glossary-entry` (definition), `mod_wiki-collaborative_page` (cachedcontent),
`core_course-course` (summary), `core_course-section` (summary). `content_to_text→html_to_text($c,75,false)`:
**75 = Umbruch, KEINE Kürzung** (`lib/weblib.php:1288-1305`). `base_activity` = nur name+intro (`base_activity.php:109-110`).

**DB/XMLDB/float32:** `XMLDB_TYPE_BINARY` (`lib/xmldb/xmldb_constants.php:54`) → LONGBLOB/BYTEA/VARBINARY(MAX).
`pack/unpack('g*')` = float32 LE. `get_recordset*` O(1)+`close()` (`lib/dml/moodle_database.php`). **`insert_records()`
chunkt auf BEIDEN Treibern** (MySQL `mysqli:1501-1533`, PG `pgsql:1219`, je ~500/Chunk) und **warnt gegen Binärfelder**
→ float32-BLOBs per `insert_record()`-Loop in Transaktion, NICHT `insert_records()`. Unique-Index-Prefix (InnoDB 767
COMPACT / 3072 DYNAMIC B) hält `refkeyhash CHAR(40)` locker (an `database_manager.php:830` steht KEIN Limit — nur der
Row-Format-Auto-Convert-Fallback). `get_dbvendor()`/`get_dbfamily()` liefern für **MariaDB auch `'mysql'`** →
MariaDB-vs-MySQL nur über `get_server_info()['version']` (§14.1). Generation-Swap: `start_delegated_transaction` + batched `delete_records_select`.

**core_ai Embeddings:** `llm_call_service::invoke_embeddings_for_context(int $threadid,int $contextid,int $userid,
string $source,string $inputtext,?int $dimensions=null):array` (`llm_call_service.php:148`), **single-input**.
`embeddings_action_config_resolver::resolve()/resolve_with_overrides()/variant_key()` liest
`ai_providers.actionconfig[GENERATE_EMBEDDINGS][settings]`; Defaults `orchestrator::EMBEDDINGS_DEFAULT_MODEL`/`_DIMENSIONS`
(`text-embedding-3-small`/1536). **KEINE** Preisquelle → §5b.4. Token = `strlen/4` (`cli/benchmark_runner.php:185`).

**Bestehende Retrieval-Infra + Alt→Neu (hinter `embeddings_store` invertieren):**
- Basis (`embeddings_csv_repository_base`): `stream_rows()@407`, `exists()@221`, `read_fingerprint()@165`,
  `write_fingerprint()@179`, `begin_stream_write/stream_write_row/commit_stream_write/discard_stream_write`,
  `build_key_offset_index@484`, `read_row_at@539`; `for_active_variant()` static.
- Cosine: `embeddings_retrieval_service::search_top_k_streaming(array,iterable,int)@123` (`vector_math::cosine_similarity`)
  → **interne** Engine von `db_embeddings_store::search_top_k`.
- **Call-Sites umverdrahten:** `docs_embeddings_index_service.php:220` (→ `upsert`), `docs_embeddings_readiness_service.php:105/176`
  (→ `exists/count_rows`+Meta-Fingerprint), `docs_lookup_service.php:132` (→ `search_top_k('docs',…,null)`),
  `skill_selection_debug_service.php:207` + `skill_governance.php:176` (`read_rows`→`stream_rows('skills',…)`).
- **Area→DTO:** docs `owner=corpus_id/refkey=chunk_path/refindex=line_start`; skills `owner=skill/refkey=anchor_kind/refindex=anchor_index`.
  Skill-Katalog-Pendants existieren (`embeddings_readiness_service`, `rebuild_skill_catalog_embeddings_adhoc`) — identisch.

---

## 13. Neue Pflicht-Artefakte (vom Plan nicht genannt — vor Umsetzung anlegen)

1. **`db/install.xml` + `db/upgrade.php`** — `bx_agent_embeddings`, `bx_agent_embeddings_meta`, `bx_agent_search_scope`,
   `bx_agent_sitesearch_state` (Cursor-State, §2.1-Nachtrag) (XMLDB);
   Upgrade-Savepoints mit `$dbman->table_exists()`-Guards; **`version.php` bumpen**; danach `admin/cli/upgrade.php`
   fahren (sonst pending-upgrade → irreführende „keine Berechtigung"-Fehler an WS-Eingängen). Aktuell `install.xml`
   VERSION `2026051900`, **9** Tabellen (alle `bx_agent_*`), keine Embeddings-Tabelle.
2. **`db/access.php`** (existiert) — Capability `configuresitesearch` (Muster §5b.1).
3. **`sitesearch_governance.php`** (neu) + `settings.php`-Registrierung (Muster §5b.2).
4. **`db/caches.php`** (existiert: `aiprivacynames`, `trialnonce`) — Cache für Estimator-Counts/Readiness (`MODE_APPLICATION`, kurze TTL).
5. **`db/tasks.php`** (existiert) — neue scheduled task für Phase-2 Site-Indexing (Muster `rebuild_docs_embeddings_adhoc` + `embeddings_rebuild_scheduler::queue_if_due()`).
6. **`db/events.php`** (**existiert NICHT** → neu) — Observer `\core\event\course_module_deleted`, `course_deleted`,
   `course_content_deleted` → `embeddings_store::delete_by_context($contextid)`. **PFLICHTBESTANDTEIL des streng
   inkrementellen Modells (§5.2/§11.23):** ohne Generation-Swap räumt nichts mehr implizit auf — Deletes laufen
   **nur** über diese Observer (Core-Pendant: `context::delete()` → `manager::context_deleted()`,
   `lib/classes/context.php:649`). Freshness zusätzlich via `modifiedfrom`-Cron.
7. **`classes/privacy/provider.php`** (existiert) — **ENTSCHIEDEN (Georg): Privacy-Provider wird gebaut.** In
   `get_metadata` die `{bx_agent_embeddings}`-**Site-Zeilen** (`area='site_content'`) deklarieren: `owneruserid` +
   indexierter user-verfasster Content (Forum/Glossar/Wiki) als abgeleiteter Datenspeicher. Export + Löschung
   liefern (`delete_data_for_user`/`_for_users`/`_for_all_users_in_context`) — Löschung praktisch via
   `delete_by_context()`/Reindex. `privacy:metadata:*`-Strings in `lang/en` (§13.8). **Vor Phase 2 fertig.**
8. **`lang/en/bookingextension_agent.php`** — Strings für Governance-Seite + Capability + `privacy:metadata:*` der neuen Tabellen.
9. **Feature-Flag `embeddingsstore` (`csv|db`)** — Config in `settings.php`, gelesen von neuer `embeddings_store_factory` (pro Area).
10. **Moodle-Version bestätigen** — `version.php $release` (Annahme 5.1) prüfen, bevor exakte Core-Zeilennummern verlassen werden.

---

## 14. PDF-Reader-Muster (Georgs Vorgabe): Server-Fähigkeit + portabler Fallback

Der Agent hat das Muster **bereits produktiv** in `services/attachment/pdf_text_extractor.php`: Strategie
**1. `pdftotext` (Server-Binary, poppler) → 2. gebündeltes `smalot/pdfparser` (pure-PHP) → sonst Exception**;
`is_available()=has_pdftotext()||has_pdfparser()`; `@exec` durch `function_exists('exec')` **gegated**
(`:103`), Pfad via `escapeshellarg` (`:165`), Lazy-PSR-4-Autoloader für die Vendored-Lib (kein Composer). Das ist
das kanonische „Server-Engine bevorzugt + portabler Fallback"-Präzedens — Vorlage an **zwei** Stellen:

### 14.1 ANN-Vektor-Engine-Erkennung (§6) folgt exakt diesem Muster
Neu: `db_vector_engine_detector` mit `has_pgvector()`, `has_mariadb_vector()`, `is_available()`,
`get_recommended_engine()` — **static, Ergebnis in `static`-Property gecacht** (wie `pdf_text_extractor`). Ablauf:
1. `$DB->get_dbfamily()` **zuerst** (`'postgres'` vs `'mysql'`). **Achtung (verifiziert):** `get_dbfamily()`/`get_dbvendor()` liefern für MariaDB **auch `'mysql'`** → die MariaDB-VECTOR-Erkennung MUSS `get_server_info()['version']`/`['description']` parsen, nicht `get_dbvendor()`.
2. pgvector: `SELECT 1 FROM pg_extension WHERE extname='vector'`; MariaDB-VECTOR (ab **11.7**): `get_server_info()`-Version
   parsen **oder** `information_schema.PLUGINS WHERE PLUGIN_NAME='vector'`.
3. **Jede Probe in `try/catch`** (fremd-familiäre Query wirft) — analog dem `has_pdftotext`-Guard.
→ ANN = optionaler Server-Beschleuniger, PHP-Cosine = portabler Fallback. Interface (§2.1) bleibt identisch, nur die
`search_top_k`-Implementierung pro Area wird getauscht. Speist auch die Ampel (§5b.5).

### 14.2 Datei-/PDF-Indizierung als Phase-2-Erweiterung (heute „Files v1 weglassen")
Für Areas/Ressourcen mit Datei-Anhängen (`mod_resource`, PDF-Uploads) wird **derselbe `pdf_text_extractor`
wiederverwendet**: Datei via `get_file_storage()` holen → temporär materialisieren → `is_available()` prüfen →
`extract()` → in den Chunking-/Embedding-Pfad wie normaler Content. `MAX_CHARS=15000`-Kappung beachten (für
Indexierung chunk-weise lesen/anheben). Explizit als Phase-2-**Option** verankern (kein v1-Blocker); der
`function_exists('exec')`-Fallback macht das überall lauffähig, nicht nur auf Servern mit poppler.

> **Leitsatz (generalisiert):** *Server-Fähigkeit erkennen und bevorzugen, portablen gebündelten Fallback immer
> mitliefern, capability-detect in einer kleinen self-contained Klasse mit statischem Cache.* Gilt für PDF-Text
> (existiert), Vektor-Engine (14.1) und Datei-Indizierung (14.2).

---

## 15. Flowchart-/Arch-Doku-Diskrepanzen (Flowchart-Policy: mit Georg klären, NICHT still ändern)

- `AGENT_IMPLEMENTATION_FLOWCHART.mmd` Z. ~87–96: `EMB_CATALOG` (CSV) → „`embeddings_store` (CSV P1 / DB P2 /
  optional ANN P3)"; `EMB_READY`/`EMB_REBUILD` um Store-Backend-Agnostik ergänzen.
- **Neuer Subgraph `RETRIEVAL_LAYER`** (Security-Boundary sichtbar): engine-freier Kontext-Lister →
  `retrieval_filter` → `search_top_k` → Over-fetch k'≫k → pro-Treffer `check_access` (GRANTED) → K Winner.
  Platzhalter `GOVERNANCE_GATE`/`INDEXING_TASK`/`SEARCH_SKILL` für Phase 2–3 reservieren.
- `docs/architecture/06-discovery-families-embeddings.md §8` (Z. 206–221, CSV/Fingerprint-Sidecar) → nach P1 revidieren.
  `14-skill-layer.md`/`16-support-services.md` decken Retrieval nicht ab (ok) → **neue** `17-retrieval-foundation.md`
  (Interface, `retrieval_filter`, Access-Boundary, Multi-Area, ANN-Detect).
- `docs/roadmap.md` hat keinen WS7-Eintrag → um „Current work (WS7): Phase 1–3" ergänzen.

---

## 16. Moodle-4.5 & die Embeddings-Provider-Voraussetzung

**Kernbefund (verifiziert):** Embeddings sind **keine** core_ai-Fähigkeit — core_ai kennt nur
`generate_text/summarise_text/explain_text/generate_image` (`ai/classes/aiactions/`). `generate_embeddings` lebt
**ausschließlich** in unserem Provider `aiprovider_wunderbyte`
(`ai/provider/wunderbyte/classes/aiactions/generate_embeddings.php`;
`wb_action_names::GENERATE_EMBEDDINGS='aiprovider_wunderbyte\aiactions\generate_embeddings'`). Verfügbarkeit =
`docs_embeddings_readiness_service::is_embeddings_provider_available()` = `class_exists(...)` (`:43`).

**Entscheidung (Georg): kein Problem — klare Voraussetzung statt 4.5-Kunstgriffe.**

- **Phase 2-3 (semantische Site-Suche) = Moodle 5 + `aiprovider_wunderbyte`.** Die Search-Governance-Seite im
  Admin-Bereich zeigt bei nicht erfüllter Voraussetzung schlicht den **Hinweis „Benötigt `aiprovider_wunderbyte`
  und Moodle 5"** — keine Toggles, kein Index-Task, kein Retrieval (hart gegatet auf `is_embeddings_provider_available()`).
  **Kein Fallback** — Semantik ohne Embeddings gibt es nicht (Keyword-Suche wäre Moodles native Suche).
- **Phase 1 (docs/skills DB-Store): 4.5-inert-safe.** Ohne Provider greifen die **bestehenden** Fallbacks
  (Skill-Discovery `slim_all`, docs-lookup lexikalisch); der Store bleibt ungenutzt, bricht aber nichts. Die
  Store-Infrastruktur selbst — XMLDB `BINARY`-BLOB, float32 `pack/unpack('g*')`, DML/`get_recordset`,
  core_search-Areas (`get_document_recordset`/`check_access`/`document`, stabil seit Moodle 3.1) — ist
  **prä-5.0-stabil** und läuft auf 4.5.

**Kein Zusatzaufwand für 4.5:** wir bauen **keine** 4.5-Embeddings; Site-Suche ist ein Moodle-5-Feature. Der
`provider_compat`-Bridge (`services/provider_compat.php`: `supports_provider_instances()`, `get_provider_views()`,
`process_action()` versions-agnostisch) bleibt für die Docs/Skills-Provider-Config relevant, ist für die Site-Suche
aber irrelevant (ohnehin Moodle-5-gated). Verweis: [[project_moodle45_coreai_compat]].

---

*Belege gegen `/Users/georgmaisser/Code/02` (Moodle-5.1-Webroot) + `…/bookingextension/agent`; verifiziert 2026-07-02
via 7 + 8 adversariale Workstreams (2 Runden) + Critic; Blocker/Majors der 2. Runde eingearbeitet.*
*Verwandt:* `embeddings_store_csv_to_db_2026-07-02.md`, `semantische_site_suche_embeddings_adapter_2026-06-10.md`,
`project_agent_skill_discovery_visibility` (vorhandene Embeddings-/Retrieval-Infra), `docs/roadmap.md` WS7.
