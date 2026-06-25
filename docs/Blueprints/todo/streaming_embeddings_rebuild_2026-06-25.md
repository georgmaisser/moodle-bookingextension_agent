# Streaming embeddings rebuild (bounded memory) — plan

Status: planned (2026-06-25)
Trigger: the docs embeddings rebuild OOMs the cron PHP process (CLI memory_limit
512M) on a full re-embed of the large corpus (≈2957 chunks / 87 MB CSV). The
embed+write actually completes (index is valid) but the process dies right after,
so Moodle marks the adhoc task failed (result=1) and retries — a perpetual,
expensive fail loop on large corpora.

Goal: the rebuild (and ideally the runtime read paths) must process the catalog
**streaming**, holding ~one row at a time instead of 4-5 full copies of the catalog.

## Why it OOMs today (memory hotspots)

`docs_embeddings_index_service::rebuild()`:
- `$existingrows = $repo->read_rows()` — full array of every row incl. the ~20-30 KB
  `embedding_json` per row.
- `$existingbykey` — second map over the same rows.
- `$scannedrows` — accumulates all reused + newly embedded rows.
- `$mergedrows` + `$finalrows = array_merge(...)` — another full copy.
- `$repo->write_rows($finalrows)` → `parse_file($tmppath)` re-reads the whole temp
  file into `$verified` for the round-trip check — yet another full copy.

Peak ≈ existing + bykey + scanned + final + verified ≈ 4-5× the catalog → >512 MB.

`embeddings_csv_repository_base::parse_file()` builds the full `$rows[]`;
`write_rows()` takes the full array and re-reads the whole temp file.

## Design — streaming primitives + streaming rebuild

Keep the existing guarantees: RFC-4180 quoting (escape disabled), atomic rename,
and the corruption guard (no publish unless every written row parses back, 0 skipped).

### Phase 1 — base repository streaming primitives (`embeddings_csv_repository_base`)

1. `stream_rows(): \Generator` — yields one associative row at a time using the same
   header check + skip logic as `parse_file()`, never building the full array.
   Re-implement `read_rows()` as `iterator_to_array(stream_rows())` for callers that
   genuinely need all rows (back-compat: skill catalog, governance, current readiness).

2. Streaming writer (a small `csv_atomic_writer` helper or methods on the base):
   - `open_writer(): handle` → opens `<path>.tmp`, writes the header.
   - `write_row(handle, array $row): void` → field-orders by `headers()`, `fputcsv`
     one row, increments a written counter.
   - `commit_writer(handle, int $written): void` → fclose + chmod, then a
     **streaming** round-trip verify: re-stream the temp file counting parseable rows
     + skipped (no array collected); throw `embeddingscatalogwritecorrupt` + unlink on
     `skipped>0 || parsed!=$written`; otherwise atomic `rename`.
   - `discard_writer(handle)` → fclose + unlink.
   This replaces the "build full array → write_rows → re-read full array" round-trip
   with O(1)-memory incremental write + streaming verify.

3. `build_key_offset_index(callable $keyfn): array` — stream the file once and return
   `key => ['content_hash' => string, 'offset' => int]`, where `offset` is `ftell()`
   captured **before** each `fgetcsv()` (correct row start even when a quoted field
   contains newlines). Holds only hash + int offset per row (~100 B × N ≈ sub-MB),
   not embeddings. Plus return the total row count.

4. `read_row_at(handle, int $offset): ?array` — `fseek($handle,$offset)` + one
   `fgetcsv`, returned as an associative row. Used to copy a reused row's raw bytes
   without ever loading all embeddings.

Unit tests (Phase 1): round-trip a file via the streaming writer; `stream_rows()`
equals `read_rows()`; corruption guard still throws on a deliberately broken row;
`build_key_offset_index` + `read_row_at` returns the same row as a full read.

### Phase 2 — rewrite `docs_embeddings_index_service::rebuild()` (the OOM fix)

Two streaming passes + one streaming writer, existing file handle kept open:

1. **Index pass:** `[$existingidx, $existingtotal] = build_key_offset_index(key=corpus||path||line_start)`
   on the existing variant file (hashes + offsets only). Open the existing file handle
   for `read_row_at`.
2. **Write pass (scanned corpora):** open the writer. For each scanned corpus → file →
   `markdown_chunker::chunk()` (per-file, bounded):
   - compute `content_hash`; if `!force` and `$existingidx[key].content_hash === hash`
     → `read_row_at(offset)` and `write_row()` (reuse, `$reused++`).
   - else embed (one API call, text already in hand) and `write_row()` the new row
     (`$embedded++`).
   No `$scannedrows` accumulation.
3. **Keep pass (declared, non-scanned corpora):** `stream_rows()` over the existing
   file again; `write_row()` rows whose `corpus_id` is declared and not in `$scan`
   (`$kept++`); drop scanned-corpus rows (already rewritten) and no-longer-declared
   corpora (pruned).
4. `commit_writer($written)`. `deleted = $existingtotal - $reused - $kept` (clamp ≥0).

Result is byte-identical in content to today's logic (same reuse/prune semantics),
but peak memory ≈ the offset index (sub-MB) + one row + one file's chunks (a few MB),
independent of catalog size.

Add `mtrace('… peak mem ' . display_size(memory_get_peak_usage(true)))` at the end so
the next run's memory is observable.

Unit test (Phase 2): synthetic multi-corpus index; assert reused/embedded/deleted/kept
counts and the final row set match the pre-refactor behaviour; assert
`memory_get_peak_usage` stays well under a low cap for a deliberately large fixture
(mock the embeddings call so no real LLM is used).

### Phase 3 (recommended, same primitives) — runtime read paths

These also load the whole catalog per request:
- `docs_lookup_service` similarity search → iterate `stream_rows()`, keep only a
  bounded top-k heap (never hold all rows).
- `docs_embeddings_readiness_service::get_status()/get_corpus_index_summary()` →
  stream-count for schema validity + per-corpus coverage/tallies instead of
  `read_rows()`.

### Phase 4 (consistency, lower priority) — skill catalog

`family_embeddings_index_service::rebuild_catalog()` shares the same accumulation
pattern (smaller data: ~41 rows). Migrate it onto the Phase 1 primitives for
consistency once docs is proven.

## Verification & rollout

1. Phase 1+2 unit tests green (incl. the bounded-memory assertion).
2. Full agent suite green.
3. On the VM: re-run the docs rebuild adhoc task → must complete with **result=0**
   within the 512M CLI limit; check the logged peak memory.
4. Clear the currently stuck failing task (5445) once a clean run succeeds.

## Notes / guarantees preserved
- RFC-4180 escape-disabled quoting, atomic rename, and the "no publish unless every
  row round-trips, 0 skipped" corruption guard are all kept (the verify just becomes
  streaming).
- Operational stopgap until shipped: raise the cron CLI `memory_limit` and drop the
  dead retry task; the index on disk is already valid.
