# Blueprint · Embeddings-Store: temp-CSV → einheitlicher Moodle-DB-Table (+ float32)

> **Status:** Planungsdokument (todo). Noch nicht committet.
> **Datum:** 2026-07-02
> **Scope:** `bookingextension_agent` — Embeddings-Persistenz für Docs-Korpus **und** Skill-Katalog.
> **Entscheidung (Georg):** Weg von CSV, hin zu **einem** DB-Table mit `area`-Diskriminator; Vektoren als **float32-BLOB**.

---

## 1. Motivation

Heute liegen die Embeddings in **zwei** CSV-Dateien unter `moodledata/temp/bookingextension_agent/wizard/`,
variant-suffixiert nach Modell+Dimensionen:

- `docs_embeddings__<model>__<dims>.csv` (Docs-Korpus, ~1020 Chunks)
- `skill_catalog_embeddings__<model>__<dims>.csv` (Skill-Katalog, ~230 Zeilen)

Drei reale Schwächen:

1. **`temp/`** ist pro-Node im Cluster (nicht geteilt) und wird vom Moodle-Temp-Cleanup-Task purgebar
   → wiederkehrende Re-Embedding-Kosten, Inkonsistenz zwischen Web-Nodes.
2. **Vektoren als Text** (`embedding_json`, ~20 Byte/Float) → 1536 Dim ≈ 30 MB, 4096 Dim ≈ 90 MB;
   pro Query Millionen `(float)`-Parses.
3. Zwei fast identische Repository-Implementierungen (nur die Identitätsspalten unterscheiden sich).

**Ziel:** persistenter, cluster-geteilter, gebackupter Store (DB) + kompaktere/schnellere Vektoren (float32)
+ EIN Repository statt zwei.

**Nicht-Ziel:** native Vektor-Extension (pgvector/MariaDB-VECTOR). Bleibt optionaler, capability-detektierter
Fast-Path für später (~5–10k+ Chunks). Der DB-Table ist **operativ** motiviert, nicht als Perf-Sprung —
Cosine bleibt vorerst O(N) in PHP (siehe §9).

---

## 2. Ausgangszustand (verifiziert)

**Spalten heute:**

| | Docs (`docs_embeddings_csv_repository`) | Skills (`embeddings_csv_repository`) |
|---|---|---|
| Identität | `corpus_id`, `chunk_path`, `line_start` | `skill`, `anchor_index` |
| Anzeige | `chunk_title`, `line_end` | `anchor_kind`, `anchor_text` |
| Gemeinsam | `embedding_model`, `embedding_dimensions`, `content_hash`, `embedding_json` | dito |

**Beteiligte Klassen:**
- `embeddings_csv_repository_base` — `stream_rows()`, `exists()`, `write_rows()` (atomic temp→validate→swap),
  `read_fingerprint()`, `stream_is_valid_schema()`, `for_active_variant()`, `normalize_variant_key()`.
- `docs_embeddings_csv_repository`, `embeddings_csv_repository` (Skill-Katalog) — konkrete Spalten.
- `docs_embeddings_index_service` — Chunking (`markdown_chunker`, `MAX_CHUNK_CHARS=4000`), `rebuild()`,
  `compute_source_fingerprint()`. (Skill-Katalog hat sein Pendant.)
- `docs_lookup_service::search_semantic()` — `for_active_variant()` → `stream_rows()` →
  `embeddings_retrieval_service::search_top_k_streaming()` (Cosine, O(k)-Speicher).
- `docs_embeddings_readiness_service` — `get_status()`/`is_index_covered()`, `ensure_rebuild_scheduled_if_needed()`.
- `docs_embeddings_gate`, `embeddings_rebuild_scheduler::queue_if_due()`, `rebuild_docs_embeddings_adhoc`.
- `embeddings_action_config_resolver` — aktives Modell+Dims (`variant_key()`).

**Retrieval-Prinzip (bleibt):** ein Query-Embedding → alle Zeilen der aktiven Variante+Area streamen →
Cosine → Top-K mit Mindest-Score. Beide Areas werden **nie gemeinsam** abgefragt.

---

## 3. Ziel-Schema — EIN Table

```
{agent_embeddings}
  id             BIGINT  PK, autoincrement
  area           VARCHAR(32)   -- 'docs' | 'skills' | (künftig weitere)
  owner          VARCHAR(255)  -- corpus_id (docs) | skill (skills)
  refkey         VARCHAR(255)  -- chunk_path (docs) | anchor_kind (skills)
  refindex       INT           -- line_start (docs) | anchor_index (skills)
  endindex       INT NULL      -- line_end (docs); NULL bei skills
  title          TEXT          -- chunk_title (docs) | anchor_text (skills)
  emodel         VARCHAR(128)  -- embedding_model
  edims          INT           -- embedding_dimensions
  contenthash    VARCHAR(40)   -- sha1 (Change-Detection, inkl. Modell+Dims)
  generation     BIGINT        -- Build-Generation für atomaren Swap (siehe §6)
  embedding      BLOB          -- float32 little-endian, edims*4 Byte (siehe §5)
  timemodified   BIGINT
```

**Indizes / Keys:**
- **Scan-Index:** `(area, emodel, edims, generation)` — der Retrieval-Scan filtert exakt hierauf.
- **Unique (Upsert/Reuse):** `(area, owner, refkey, refindex, emodel, edims)`.
- Der Docs-Read-Back `(corpus_id, chunk_path)` → `(owner, refkey)`; der Skill-Lookup `(skill)` → `(owner)`.

**Warum ein Table:** siehe Entscheidungsdoku — Moodle-Files-API-Muster (`component/filearea/itemid`),
nie gemeinsam abgefragt (immer `WHERE area=?`), DRY/erweiterbar, keine JSON-Metadaten nötig (die wenigen
Extras `endindex`/`title` sind typisierte Spalten). Preis: `owner`/`refkey` haben je Area andere Semantik —
akzeptiert, typisiert, präzedenzgedeckt.

---

## 4. Repository-Design (ein DB-Repository statt zwei CSVs)

`embeddings_csv_repository_base` + zwei Subklassen → **ein** `embeddings_store` (DB), area-parametrisiert.
Interface (engine-agnostisch, ersetzt die CSV-Repos hinter derselben Aufruf-Oberfläche):

```
interface embeddings_store {
  // Retrieval
  public function stream_rows(string $area, string $emodel, int $edims): \Generator; // yields decoded rows
  public function exists(string $area, string $emodel, int $edims): bool;
  public function count_rows(string $area, string $emodel, int $edims): int;
  public function distinct_owners(string $area, string $emodel, int $edims): array;

  // Rebuild (siehe §6)
  public function begin_generation(string $area, string $emodel, int $edims): int;
  public function upsert(string $area, int $generation, embedding_row $row): void;
  public function reuse_existing(string $area, string $emodel, int $edims, string $key): ?embedding_row;
  public function commit_generation(string $area, string $emodel, int $edims, int $generation): void; // atomarer Swap + Prune alter Generation
  public function fingerprint(string $area, string $emodel, int $edims): string;
  public function set_fingerprint(string $area, string $emodel, int $edims, string $fp): void;
}
```

- `stream_rows()` nutzt `\moodle_database::get_recordset()` → iterativ, **O(k)-Speicher** (wie CSV-Streaming);
  jede Zeile: BLOB → float32-Array (§5) unpacken, dann an `embeddings_retrieval_service` wie bisher.
- `embeddings_retrieval_service::search_top_k_streaming()` bleibt **unverändert** (bekommt weiterhin einen
  Generator von Zeilen mit `embedding`-Array + Metadaten). Nur die Zeilenquelle wechselt CSV→DB.
- Die konkreten Spalten-Mappings (docs vs. skills) leben in dünnen **Adaptern**
  (`docs_embedding_row_mapper`, `skill_embedding_row_mapper`), die zwischen dem generischen
  `embedding_row` und den Area-Semantiken übersetzen — so bleibt Aufrufer-Code (Index/Lookup) lesbar.

---

## 5. float32-Packing

- Speicherung: `pack('g*', ...$floats)` (little-endian float32) → BLOB der Länge `edims*4`.
- Lesen: `unpack('g*', $blob)` → Array. (Alternativ `e`/`G` je nach gewünschter Endianness; **fix little-endian**
  festlegen und in einer Konstante dokumentieren, damit Cross-Plattform stabil.)
- Größe: 4 statt ~20 Byte/Float → **~5×** kleiner (1536 Dim: ~6 KB/Zeile statt ~30 KB; Gesamt 30→~6 MB).
- Dekodier-Kosten: ein `unpack` statt tausender `(float)`-Casts pro Zeile → spürbar schneller.
- **Validierung:** beim Lesen `strlen($blob) === edims*4` prüfen; Mismatch → Zeile als korrupt behandeln
  (überspringen + `debugging()`), Readiness stuft die Variante als „stale" → Rebuild (analog CSV-Kurzread-Guard).
- `contenthash` bleibt `sha1(text . '|m=' . model . '|d=' . dims)` — unverändert, entkoppelt vom Vektorformat.

---

## 6. Atomarer Rebuild ohne Langläufer-Transaktion (Generation-Swap)

CSV war atomar (temp→swap). DB-Äquivalent ohne große Sperr-Transaktion:

1. `begin_generation()` → neue `generation`-Nummer (z. B. `time()` oder max+1) für `(area,emodel,edims)`.
2. Rebuild schreibt **neue** Zeilen mit dieser `generation` (per `upsert`); unveränderte Chunks können
   ihren Vektor aus der **vorherigen** Generation via `reuse_existing()` (Hash-Match) übernehmen (kein Re-Embed).
3. `commit_generation()`:
   - setzt die aktive Generation für `(area,emodel,edims)` (kleiner Pointer, z. B. in `{agent_embeddings_meta}`
     oder als „max committed generation") **in einer kurzen Transaktion**,
   - **prunt** alle Zeilen dieser `(area,emodel,edims)` mit älterer Generation.
4. Retrieval filtert immer auf die **committed** Generation → Leser sehen nie einen halbfertigen Build.

Vorteil: kein 90-MB-Delete+Insert in einer einzigen Transaktion; Reader bleiben konsistent.
Der `fingerprint` (Source-Drift-Detektor aus `compute_source_fingerprint()`) wird pro `(area,emodel,edims)`
in der Meta-Tabelle/-Spalte abgelegt statt in der `.csv.fingerprint`-Datei.

---

## 7. Readiness / Gate / Scheduler (minimaler Umbau)

- `docs_embeddings_readiness_service` und das Skill-Katalog-Pendant lesen künftig **`exists/count/fingerprint`
  aus dem Store** statt aus der CSV — die **Logik** (`get_status()`-Zustände `missing/invalid/incomplete/stale/ready`,
  `is_index_covered()`, `ensure_rebuild_scheduled_if_needed()`) bleibt **unverändert**.
- `docs_embeddings_gate` (Skill-aktiv-Gate) unverändert.
- `embeddings_rebuild_scheduler::queue_if_due()` + die Adhoc-Tasks unverändert (nur der Store-Write dahinter wechselt).
- Der **self-healing-Trigger beim Skill-Aufruf** (verifiziert) bleibt exakt erhalten.

---

## 8. Migration CSV → DB

**Kein Datenverlust nötig, aber auch kein Muss:** Da Embeddings jederzeit aus der Quelle rebuildbar sind,
ist der einfachste Pfad ein **Rebuild-on-first-use** nach dem Upgrade:

- `upgrade.php`: Tabelle(n) via XMLDB anlegen. **Keine** CSV→DB-Datenübernahme (die CSVs sind ohnehin
  variant- und temp-gebunden). Nach dem Upgrade ist der DB-Index leer → `get_status()='missing'` →
  erster Skill-Aufruf plant den Rebuild (bestehender Mechanismus) → DB füllt sich.
- Optional (Komfort, spart erste Rebuild-Latenz): einmaliger Import-Task, der vorhandene CSVs der **aktiven**
  Variante einliest und in die DB packt (Text→float32). Nur wenn gewünscht — sonst weglassen (KISS).
- **Cleanup:** alte CSVs in `temp/` können nach erfolgreichem DB-Build gelöscht werden (Adhoc-Task oder
  einfach dem Temp-Cleanup überlassen).

**Rollback:** solange die CSV-Repos im Code bleiben (Feature-Flag `embeddingsstore=csv|db`), ist ein Zurückschalten
trivial. Empfehlung: **Flag** für 1–2 Releases, Default `db`, dann CSV-Pfad entfernen.

---

## 9. Performance-Erwartung (ehrlich)

- Algorithmus unverändert: **O(N) Cosine in PHP**. Kein Big-O-Gewinn bei ~1020 Chunks.
- Erwartete reale Effekte:
  - **float32** → kleinere Payload + schnelleres Dekodieren (der eigentliche Speed-Hebel).
  - **DB-Transfer** statt lokalem File-Read: bei DB-auf-Localhost ~neutral; bei separater DB ein Transfer-Overhead,
    aber durch float32 (~5× weniger Bytes) weitgehend kompensiert; dafür DB-Buffer-Pool-Caching.
    Nettolast wandert auf die (geteilte) Moodle-DB — bei dieser Skala unkritisch, im Auge behalten.
  - **Robustheit** (Persistenz, Cluster-Sharing, Backup, kein Temp-Purge) — der eigentliche Gewinn.
- Später optionaler **Fast-Path**: `capability_detect()` → wenn pgvector/MariaDB-VECTOR verfügbar, Cosine+Top-K
  serverseitig, nur K Zeilen zurück. Interface aus §4 ist dafür vorbereitet (eine alternative `stream_rows`/
  `search_top_k`-Implementierung), ohne Aufrufer zu ändern.

---

## 10. Phasenplan

- **P0 — Interface-Inversion (engine-frei):** `embeddings_store`-Interface + `embedding_row`-DTO + Mapper
  einziehen; CSV-Repos hinter das Interface hängen (Verhalten unverändert). Grüne Tests = Netz.
- **P1 — DB-Store:** XMLDB `{agent_embeddings}` (+ ggf. `{agent_embeddings_meta}`), `db_embeddings_store`
  implementieren (float32 pack/unpack, Generation-Swap), Unit-Tests (roundtrip, pack/unpack, upsert/reuse,
  commit/prune, stream_rows).
- **P2 — Verdrahtung:** Index-Services + Readiness + Lookup auf den Store umstellen; Feature-Flag `embeddingsstore`.
- **P3 — Migration/Cleanup:** XMLDB-Upgrade, Rebuild-on-first-use, optionaler CSV-Import, Temp-Cleanup.
- **P4 — Deprecate CSV:** nach 1–2 Releases CSV-Pfad + Basisklasse entfernen.

---

## 11. Risiken / offene Punkte

1. **BLOB-Größe/Portabilität:** `edims*4` (z. B. 4096→16 KB) als BLOB über MySQL/MariaDB/PostgreSQL —
   unkritisch (max_allowed_packet beachten bei Batch-Inserts; ggf. chunked insert).
2. **Endianness fix** (little-endian) — Konstante + Test, sonst Cross-Plattform-Bug.
3. **Generation-Pruning** muss idempotent sein (abgebrochener Rebuild darf keine verwaisten Generationen lassen →
   Prune-on-commit + gelegentlicher „orphan generation"-Cleanup im Task).
4. **DB-Last** bei künftig größeren Korpora beobachten (Metrik/Log), bevor der native Fast-Path fällig wird.
5. **XMLDB-Feldtypen:** `title`/`embedding` als `TEXT`/`BLOB`; `owner/refkey` VARCHAR-Längen prüfen
   (chunk_path kann lang sein → ggf. 255→512 oder hashed lookup).
6. **Skill-Katalog-Pendant:** Readiness/Rebuild des Skill-Katalogs analog umstellen (nicht vergessen —
   dieselbe Store-API, `area='skills'`).

---

## 12. Testing

- Unit: float32 roundtrip; `db_embeddings_store` upsert/reuse/commit/prune; stream_rows-Ordnung/Filter;
  Korrupt-BLOB-Guard; fingerprint set/get.
- Integration: Rebuild (docs + skills) → `get_status()=ready`; Lookup liefert dieselben Top-K wie CSV-Pfad
  (Parität-Test gegen einen CSV-Fixture-Datensatz).
- Readiness/Trigger: fehlende Variante → `missing` → Skill-Aufruf plant Rebuild (bestehendes Verhalten).
- phpcs/phpdoc 0/0.

---

## 13. Offene Entscheidungen für Georg

1. **Feature-Flag** `embeddingsstore=csv|db` für sanften Rollout (empfohlen) — ja/nein?
2. **CSV→DB-Import** einmalig (spart erste Rebuild-Latenz) oder **Rebuild-on-first-use** (KISS, empfohlen)?
3. **Meta-Pointer** für Generation: eigene Tabelle `{agent_embeddings_meta}` oder „max committed generation" implizit? (Tabelle = expliziter, empfohlen.)
4. `owner/refkey`-Längen: 255 ausreichend, oder chunk_path-Hash-Spalte für den Unique-Key?
