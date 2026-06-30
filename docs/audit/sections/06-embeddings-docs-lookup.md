# Audit Section 06 — Embeddings & Docs Lookup

**Scope:** `classes/local/wizard/services/embeddings/*` (7), `classes/local/wizard/services/lookup/*` (9),
`classes/local/wizard/embeddings_action_config_resolver.php`, `classes/local/wizard/embeddings_csv_repository.php`,
`classes/local/wizard/embeddings_csv_repository_base.php`, `classes/local/wizard/doc_markdown_preview_renderer.php`,
`classes/admin/setting_docs_corpora.php`  ·  **Files audited:** 20  ·  **Methods audited:** ~95
**Arch chapter(s):** docs/architecture/06-discovery-families-embeddings.md (embeddings half)  ·  **Flowchart nodes:** EMB_QUERY, EMB_AVAIL, EMB_CATALOG, EMB_READY, EMB_REBUILD, MV_BUILD, MV_STORE, MV_SUBSET, MV_RETRIEVE
**Auditor verdict:** ⚠️ issues (no blocker)

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues | Path confinement is solid (lexical + realpath, two independent layers). One residual markdown→HTML `javascript:`-scheme link vector in `markdown_renderer` (admin-controlled corpus = low residual). No PII written to CSV — verified the slim schema. No secrets logged. |
| D2 Moodle API      | issues | Uses `$DB->get_records` (parameterised), task API, `get_string`, `make_temp_directory`, `core_component`, `get_config`/`set_config` correctly. Minor: `htmlspecialchars` used directly instead of `s()`/`format_text` in two renderers; phpcs not runnable in this checkout (visually clean). |
| D3 Structure       | issues | Two genuinely dead methods (`search_top_k`, `build_planner_catalog_subset` — the latter test-only, confirmed by the flowchart itself as "currently unused"). Otherwise clean single-responsibility layering, good base-class extraction. |
| D4 Duplication     | issues | `load_doc_meta()` vs `load_docs()` vs `build_windowed_doc()` repeat the H1-title + 5-line-excerpt extraction; `anchor_key()` duplicated verbatim across `embeddings_readiness_service` and `family_embeddings_index_service`; the `pix/` skip + `.md` filter scan duplicated between `docs_lookup_service::load_docs` and `docs_embeddings_index_service::scan_md_files`. |
| D5 Flowchart       | pass | Behaviour matches MV_BUILD/MV_STORE/MV_SUBSET/EMB_READY: slim hashed-cols+vector schema, per-anchor content_hash, empty-vector-not-ready guard, live re-join. One doc-lag in the *flowchart-guide* (docs_provider) but not in the .mmd nodes I own. |
| D6 Docs coverage   | pass | Chapter 06 (embeddings half) accurately describes the readiness/rebuild/retrieval flow. §8 text "(`rebuild_docs_embeddings_adhoc`)" and the per-anchor content_hash gotcha are both correct against code. Minor omission: the docs-lookup lexical fallback + corpus-parser confinement are barely mentioned (they live mostly in the explain_docs skill chapter). |

## B. Findings

### [06-F01] 🟠 HIGH · D1 Security · classes/local/wizard/services/lookup/markdown_renderer.php:379-398
**What:** A relative markdown link with a dangerous URL scheme (e.g. `[x](javascript:alert(1))`) is emitted into an `href` attribute without scheme validation, only `htmlspecialchars`-escaped — which does not neutralise the `javascript:`/`data:`/`vbscript:` scheme.
**Evidence:** `resolve_internal_doc_link()` returns `null` for anything matching `^[a-z][a-z0-9+.-]*:` (line 282), so a `javascript:` href falls through to `format_non_doc_link()`. There, `parse_url('javascript:alert(1)')` yields `scheme=javascript` and the function returns `$raw` unchanged (lines 373-381); the caller then renders `'<a href="' . htmlspecialchars($raw) . '">'` (line 237-238). `htmlspecialchars` escapes quotes/`<`/`>` but leaves `javascript:alert(1)` intact, producing a clickable XSS link.
**Impact:** If any indexed corpus file contains attacker-influenced markdown, a click on the rendered preview link executes script in the viewing admin/teacher's session. The preview HTML is delivered as `get_result_preview` `type=>html` and inserted client-side.
**Compensating control:** Corpora are admin-configured directories strictly under `$CFG->dirroot` (`corpus_source_parser` E2 confinement) and contain shipped `.md` docs, not user uploads — so today the markdown source is trusted. Residual risk is the day a corpus points at a directory that ingests less-trusted markdown. Also `target=_blank` external links already carry `rel="noopener noreferrer"` (line 216), showing scheme-awareness was intended.
**Recommendation:** In `format_non_doc_link()` (and the external-URL branch is already fine), reject non-`http(s)`/non-relative schemes: if `parse_url` returns a non-empty `scheme` that is not `http`/`https`, drop the href (render the label as plain text or `#`). One guard closes the whole vector.

### [06-F02] 🟡 MEDIUM · D3 Structure · classes/local/wizard/services/embeddings/embeddings_retrieval_service.php:50-79
**What:** `search_top_k()` is dead code — zero production and zero test callers.
**Evidence:** Grepped the whole `classes/` + `tests/` tree: `search_top_k(` appears only at its own definition (`:50`) and in three doc-comments (`:91`, `:153`, and `docs_lookup_service.php:137`). The live skill-retrieval path uses `search_top_k_skills()` (multi-vector aggregation) and the docs path uses `search_top_k_streaming()`. No caller invokes the plain single-vector `search_top_k()`.
**Impact:** Maintenance noise; a future reader may mistake it for the live retrieval entry point.
**Compensating control:** None needed — it is inert.
**Recommendation:** Remove `search_top_k()` (or fold its body into a private helper if `search_top_k_streaming` were to reuse it). Cleanup backlog only.

### [06-F03] 🟡 MEDIUM · D3 Structure · classes/local/wizard/services/embeddings/embeddings_retrieval_service.php:220-273
**What:** `build_planner_catalog_subset()` (and its two private helpers `build_live_contract_lookup`, `compact_properties_for_planner`) is only reachable from integration tests; the live planner-candidate path is `planner_catalog_service::sanitize_runtime_catalog_for_prompt`.
**Evidence:** Grep shows `build_planner_catalog_subset` called only in `tests/agent/contracts/integration_agent_framework_test.php:359,396`. The flowchart MV_SUBSET node states it explicitly: "build_planner_catalog_subset is the equivalent helper, **currently unused**." flowchart-guide `:258-259` confirms "integration-test-only".
**Impact:** A whole code path (and two helpers) kept alive solely for tests; risk of drift from the real `sanitize_runtime_catalog_for_prompt`.
**Compensating control:** Behaviour is documented as test-only in both the flowchart and the guide, so it is a known/accepted helper, not a hidden bug.
**Recommendation:** Either delete it (and retarget the integration test at the real path) or annotate it `@internal test-only` so it is unambiguous. Not a launch gate.

### [06-F04] 🟡 MEDIUM · D4 Duplication · classes/local/wizard/services/embeddings/embeddings_readiness_service.php:150-156 + family_embeddings_index_service.php:225-231
**What:** The per-anchor identity key `skill . '#' . anchor_index` is implemented twice, verbatim, as a private `anchor_key()` in two services.
**Evidence:** Both methods are byte-identical (`return $skillname . '#' . (string)($row['anchor_index'] ?? '0');`). The multi-vector readiness/reuse contract depends on the two staying in lock-step — if one ever changes the separator, readiness and rebuild silently disagree about identity and the index thrashes.
**Impact:** Latent correctness coupling across two files with no shared anchor.
**Compensating control:** None; currently identical by luck of review.
**Recommendation:** Hoist the key derivation to a shared static (e.g. `embeddings_catalog_builder_service::anchor_key(array $row)`) and call it from both. The builder already owns the anchor schema.

### [06-F05] 🟡 MEDIUM · D4 Duplication · classes/local/wizard/services/lookup/docs_lookup_service.php:376-471 + 482-511
**What:** H1-title extraction (`/^#\s+(.+)$/m`) and the 5-line-excerpt / line-window construction are repeated across `load_docs()`, `load_doc_meta()` and `build_windowed_doc()`.
**Evidence:** The identical `preg_match('/^#\s+(.+)$/m', …)` title block appears at `:410`, `:457`, `:495`; the `explode("\n", str_replace([...]))` + `array_slice(…, 0, 5)` excerpt block appears at `:414-416` and `:461-462`.
**Impact:** Three copies to keep consistent; a change to title/excerpt rules must be made in three places.
**Compensating control:** Logic is small and stable.
**Recommendation:** Extract a `private static doc_title(string $content): string` and `doc_excerpt(string $content, int $lines = 5): string`. Low priority.

### [06-F06] 🟢 LOW · D1 Security · classes/local/wizard/doc_markdown_preview_renderer.php:59-64
**What:** On error, the renderer echoes the raw exception/`$result['error']` message into an HTML `alert` div (`htmlspecialchars`'d, so no XSS), potentially surfacing internal detail (paths, class names) to the UI.
**Evidence:** `return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';` (lines 60-63). The underlying `ai_get_doc_content::execute` only returns short, non-sensitive error strings ("file not found or not accessible", "unknown documentation corpus"), but the `catch (\Throwable)` branch surfaces *any* thrown message verbatim.
**Impact:** Minor information disclosure to an already-authorised user (the WS already gated `check_use_readiness` + `validate_context`).
**Compensating control:** Output is escaped (no XSS); caller is an authenticated, capability-gated agent user; messages are short by construction.
**Recommendation:** Replace the `catch` branch message with a generic `get_string()` error and log the detail via `debugging()`. Also prefer Moodle's `s()` over bare `htmlspecialchars()` for consistency.

### [06-F07] 🟢 LOW · D1 Security · classes/local/wizard/services/embeddings/vector_math.php:42-65
**What:** `cosine_similarity()` silently compares two vectors over only their shared leading dimensions (`$len = min(count($a), count($b))`) instead of treating a dimension mismatch as an error / zero score.
**Evidence:** Line 43 `$len = min(count($a), count($b));` — a 1536-dim query against a stray 768-dim row would score on the first 768 dims and return a plausible-but-meaningless similarity.
**Impact:** A mixed-dimension store would produce subtly wrong rankings rather than failing loudly.
**Compensating control:** Strong — the store is **variant-scoped per (model, dimensions)** (`embeddings_csv_repository::for_variant`, `docs_embeddings_csv_repository::for_active_variant`), and readiness rejects any row whose `embedding_dimensions` != the active dims (`embeddings_readiness_service.php:100-102`). So mismatched dims cannot reach this function on the live path. This is a defensive observation, not a live defect.
**Recommendation:** Optionally return `0.0` when `count($a) !== count($b)` to make the invariant explicit. INFO-grade.

### [06-F08] 🟢 LOW · D2 Moodle API · classes/local/wizard/services/lookup/docs_lookup_service.php:586-599 (sanitize_rel_path)
**What:** `read_doc_by_path()`/`load_doc_meta()` confine the relpath only *lexically* (`..` reject + `.md` suffix) and read `$root . '/' . $relpath` without a post-realpath containment check; a symlink inside a corpus could resolve outside the root.
**Evidence:** `sanitize_rel_path` rejects `strpos($relpath, '..') !== false` and requires `\.md$` (lines 589-595), then `read_doc_by_path` does `$abspath = $root . '/' . $relpath; is_readable(...)` (lines 260-263) with no `realpath`-under-root assertion. Contrast the external `ai_get_doc_content::execute` which *does* `realpath` + `strpos($requested, $docsroot) !== 0` (lines 97-101).
**Impact:** Reading a `.md` symlink that points outside dirroot — only exploitable by whoever can place files in an admin-declared corpus directory (i.e. server/file access already).
**Compensating control:** The `..`-reject blocks classic traversal; corpora are dirroot-confined and admin-controlled; the only attack requires filesystem write access to a corpus dir, which already implies higher privilege. The WS entry point (the actual external surface) has the stronger realpath guard.
**Recommendation:** For parity, add a `realpath($abspath)`-starts-with-`realpath($root)` check in `read_doc_by_path` (mirror the WS). Low priority given the controls above.

### [06-F09] ⚪ INFO · D2 Moodle API · classes/local/wizard/embeddings_action_config_resolver.php:93-145
**What:** `resolve()` reads the core `ai_providers` table directly; on Moodle 4.5 (pre-instance core_ai) that table may not exist.
**Evidence:** `$DB->get_records('ai_providers', ...)` (line 97). The whole body is wrapped in `try { … } catch (\Throwable) { return defaults; }` (lines 96/134) and there is a final unconditional defaults return (line 141), so a missing table degrades gracefully to `EMBEDDINGS_DEFAULT_MODEL`/`DIMENSIONS`.
**Impact:** None — confirmed-correct defensive handling, consistent with `project_moodle45_coreai_compat`.
**Compensating control:** n/a (this is the control).
**Recommendation:** None. Noted as a positive.

### [06-F10] ⚪ INFO · D5 Flowchart / D6 · docs/reference/flowchart-guide.md:190-193
**What:** The flowchart *guide* still claims a `docs_provider` self-registers the corpus; the current `docs_corpus_registry` docblock states "There is **no** component provider scan" and the two defaults are seeded into the `aidocsroot` setting instead.
**Evidence:** `find classes -iname '*docs_provider*'` → nothing; `settings.php:233-234` seeds `aidocsroot` default = `"bookingextension_agent\nmod_booking"`. Registry docblock lines 38-41 describe the setting-only model.
**Impact:** Doc-lag in the guide only; the `.mmd` nodes I own (EMB_*/MV_*) are accurate. Behaviour is correct and matches arch chapter 06.
**Compensating control:** n/a.
**Recommendation:** Update the flowchart-guide entry to reflect the setting-seeded corpora (per `feedback_flowchart_policy`, reported not reconciled).

### [06-F11] ⚪ INFO · D1 Security · CSV slim-schema / no-PII confirmation
**What:** Confirmed no PII is written to either embeddings CSV.
**Evidence:** Skill catalog `HEADERS` = `skill, anchor_index, anchor_kind, anchor_text, embedding_model, embedding_dimensions, content_hash, embedding_json` (embeddings_csv_repository.php:50-59); the row payload is built solely from the prompt contract's `description` + `example_utterances` (embeddings_catalog_builder_service.php:69-88) — English, authored metadata, no user data. Docs `HEADERS` = `corpus_id, chunk_path, chunk_title, line_start, line_end, …` (docs_embeddings_csv_repository.php:43-53) — derived from shipped `.md` files. The embedding *input* is anchor text / doc chunk text only; the API key never appears in any row or log. Files live under `make_temp_directory('bookingextension_agent/wizard')` with `$CFG->filepermissions`.
**Impact:** None — positive confirmation matching MV_STORE.
**Recommendation:** None.

## C. Per-file / per-method checklist

#### `classes/local/wizard/embeddings_csv_repository_base.php` (abstract class `embeddings_csv_repository_base`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (RFC-4180 escape='' fix, atomic round-trip-verified write, streaming O(1) API, fingerprint sidecar — all sound)
- methods:
  - [x] `__construct()`, `get_csv_path()`, `get_variant_key()`, `get_fingerprint_path()` — clean
  - [x] `read_fingerprint()` / `write_fingerprint()` / `delete_fingerprint()` — atomic temp+rename, perms applied — clean
  - [x] `normalize_variant_key()` — filename-safe token — clean
  - [x] `exists()` / `read_rows()` / `count_unreadable_rows()` / `parse_file()` — corrupt-row surfaced via `debugging()`, never silently dropped — clean
  - [x] `is_valid_schema()` / `headers_match()` — clean
  - [x] `write_rows()` — round-trip verify before swap, throws `embeddingscatalogwritecorrupt` — clean
  - [x] `stream_rows()` / `stream_is_valid_schema()` / `get_required_nonempty_columns()` — clean
  - [x] `build_key_offset_index()` / `read_row_at()` / `close_random_reader()` — offset via `ftell` pre-`fgetcsv` (newline-safe) — clean
  - [x] `begin_stream_write()` / `stream_write_row()` / `commit_stream_write()` / `discard_stream_write()` / `count_parsed_rows()` — clean
  - [x] `get_default_file_permissions()` — clean

#### `classes/local/wizard/embeddings_csv_repository.php` (class `embeddings_csv_repository`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — slim multi-vector schema matches MV_STORE; see 06-F11
- methods: [x] `for_active_variant()` [x] `for_variant()` [x] `headers()` [x] `required_nonempty_columns()` [x] `store_label()` [x] `default_csv_path()` — all clean

#### `classes/local/wizard/embeddings_action_config_resolver.php` (class `embeddings_action_config_resolver`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (see 06-F09 INFO)
- methods: [x] `variant_key()` [x] `resolve_with_overrides()` [x] `resolve()` — parameterised `$DB`, graceful fallback — clean

#### `classes/local/wizard/doc_markdown_preview_renderer.php` (class `doc_markdown_preview_renderer`)
- [ ] D1 — see 06-F06 (raw exception message in alert div); [x] D2 [x] D3 [x] D4 [x] D5 [x] D6
- methods: [ ] `render()` — see 06-F06 (LOW)

#### `classes/admin/setting_docs_corpora.php` (class `setting_docs_corpora`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — hard-blocks save on E2 warnings via `corpus_source_parser`, `s()`-escapes messages — clean
- methods: [x] `validate()` — clean

#### `classes/local/wizard/services/embeddings/vector_math.php` (class `vector_math`)
- [ ] D1 — see 06-F07 (dimension-mismatch leading-dims compare, defended by variant scoping); [x] D2 [x] D3 [x] D4 [x] D5 [x] D6
- methods: [ ] `cosine_similarity()` — see 06-F07 (LOW)

#### `classes/local/wizard/services/embeddings/embeddings_readiness_service.php` (class `embeddings_readiness_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (anchor_key dup — 06-F04) [x] D5 [x] D6 — empty-vector-not-ready guard + orphan/removal detection correct (EMB_READY/MV)
- methods:
  - [x] `is_wunderbyte_embeddings_available()` — clean
  - [x] `get_catalog_status()` — per-anchor compare, empty-vector guard (lines 114-117), orphan check (131-135) — clean
  - [ ] `anchor_key()` — duplicated, see 06-F04
  - [x] `ensure_rebuild_scheduled_if_needed()` — clean

#### `classes/local/wizard/services/embeddings/embeddings_retrieval_service.php` (class `embeddings_retrieval_service`)
- [ ] D3 — dead `search_top_k` (06-F02) + test-only `build_planner_catalog_subset` (06-F03); [x] D1 [x] D2 [x] D4 [x] D5 [x] D6
- methods:
  - [ ] `search_top_k()` — dead, 06-F02
  - [x] `search_top_k_skills()` — live multi-vector aggregation (MV_RETRIEVE), distinct-skill max — clean
  - [x] `search_top_k_streaming()` — SplHeap O(k) memory, drops vector after scoring — clean
  - [ ] `build_planner_catalog_subset()` — test-only, 06-F03
  - [ ] `build_live_contract_lookup()` / `compact_properties_for_planner()` — only reached via 06-F03 path
- note: `compact_properties_for_planner` (180-char/40-prop cap) is reused by the live lookup only through `build_planner_catalog_subset`; if that is removed, retarget.

#### `classes/local/wizard/services/embeddings/embeddings_catalog_builder_service.php` (class `embeddings_catalog_builder_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — MV_BUILD: one row per anchor, content_hash over anchor text only, no card metadata (correct gotcha)
- methods: [x] `build_full_catalog_rows()` [x] `build_anchor_list()` (dedupe by lowercased text) [x] `compute_content_hash()` (sha256 over model+dims+canonical row) [x] `to_embedding_input()` (anchor text alone) — all clean

#### `classes/local/wizard/services/embeddings/family_embeddings_index_service.php` (class `family_embeddings_index_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (anchor_key dup — 06-F04) [x] D5 [x] D6 — provider-absent skip, per-anchor reuse, prune removed skills, write_rows atomic
- methods:
  - [x] `rebuild_catalog()` — admin userid context, reuse-by-hash, `unset(_embedding_input)` before write — clean
  - [ ] `anchor_key()` — duplicated, see 06-F04

#### `classes/local/wizard/services/embeddings/family_embeddings_retrieval_service.php` (class `family_embeddings_retrieval_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — deterministic family max-score + bounded blend (0.7/0.3), clamped weights
- methods: [x] `score_families()` [x] `boost_skill_rows()` — clean (callers verified in discovery_phase_service + tests)

#### `classes/local/wizard/services/embeddings/embeddings_rebuild_scheduler.php` (class `embeddings_rebuild_scheduler`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — single config-marker debounce + deduped `queue_adhoc_task` (EMB_REBUILD)
- methods: [x] `queue_if_due()` — clean

#### `classes/local/wizard/services/lookup/corpus_source_parser.php` (class `corpus_source_parser`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — E2 confinement: lexical `normalize_absolute` (collapses `..`) + `is_within_dirroot` for the *intended* path, plus `realpath`-under-root for resolvable — two independent layers, declared≠resolvable split correct
- methods: [x] `parse()` [x] `strip_comment()` [x] `split_line()` [x] `candidate_path()` [x] `derive_corpus_id()` [x] `normalize_corpus_id()` [x] `normalize_absolute()` [x] `is_within_dirroot()` — all clean

#### `classes/local/wizard/services/lookup/docs_corpus_registry.php` (class `docs_corpus_registry`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — single corpus_id→root authority; test override is PHPUNIT-gated (throws otherwise)
- methods: [x] `__construct()` [x] `list()` [x] `declared_corpus_ids()` [x] `resolve_root()` [x] `is_known()` [x] `primary()` [x] `parsed()` [x] `sanitize()` [x] `set_corpora_for_testing()` (PHPUNIT-gated) — clean

#### `classes/local/wizard/services/lookup/docs_embeddings_gate.php` (class `docs_embeddings_gate`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — config-only "is docs skill active" gate, default-off — clean
- methods: [x] `is_docs_skill_active()` — clean

#### `classes/local/wizard/services/lookup/docs_embeddings_csv_repository.php` (class `docs_embeddings_csv_repository`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — single-vector docs schema, inherits base; required cols include corpus_id+chunk_path+content_hash
- methods: [x] `for_active_variant()` [x] `headers()` [x] `required_nonempty_columns()` [x] `store_label()` [x] `default_csv_path()` [x] `read_rows_for_corpus()` (test-only caller; kept as a declared filter helper) — clean

#### `classes/local/wizard/services/lookup/markdown_chunker.php` (class `markdown_chunker`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — heading/size-bounded chunking, whitespace chunks dropped, full-doc coverage
- methods: [x] `chunk()` [x] `extract_h1()` — clean

#### `classes/local/wizard/services/lookup/docs_embeddings_index_service.php` (class `docs_embeddings_index_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (scan_md_files dup with lookup load_docs — 06-F05 adjacent) [x] D5 [x] D6 — E3 skill-active gate, provider gate, streaming atomic rebuild, fingerprint only on full rebuild, non-destructive merge
- methods:
  - [x] `rebuild()` — gated, streaming, discard-on-throw, deleted-count clamp — clean
  - [x] `compute_source_fingerprint()` — stat-only sorted hash, removal-aware — clean
  - [x] `scan_md_files()` — pix/ skip, `.md` filter, sorted (minor dup with docs_lookup_service::load_docs)
  - [x] `build_embedding_input()` — corpus/path/title header + 6000-char content cap — clean

#### `classes/local/wizard/services/lookup/docs_embeddings_readiness_service.php` (class `docs_embeddings_readiness_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — provider+exists+schema+coverage+source-fingerprint drift; coverage vs resolvable, prune vs declared (correct asymmetry)
- methods:
  - [x] `is_embeddings_provider_available()` [x] `is_index_ready()` (schema-only fast path) [x] `is_index_covered()` — clean
  - [x] `get_status()` — single streaming pass, fingerprint drift detection — clean
  - [x] `get_corpus_index_summary()` — read-only admin summary, never triggers rebuild — clean
  - [x] `on_corpus_setting_updated()` (settings updated-callback — framework-invoked) [x] `ensure_rebuild_scheduled_if_needed()` — E1 gate + shared scheduler — clean

#### `classes/local/wizard/services/lookup/docs_lookup_service.php` (class `docs_lookup_service`)
- [ ] D1 — see 06-F08 (read path lexical-only confinement); [x] D2 [ ] D4 (title/excerpt dup — 06-F05) [x] D3 [x] D5 [x] D6
- methods:
  - [x] `search_semantic()` — readiness gate, single query embed, streaming top-k, SEMANTIC_MIN_SCORE=0.30, dangling-self-heal — clean
  - [x] `search_multi()` / `search_lexical()` — lexical fallback, cross-query bonus capped — clean
  - [ ] `read_doc_by_path()` — see 06-F08
  - [x] `read_doc_any_corpus()` (caller: explain_docs_skill) [x] `read_root_doc()` (caller: explain_docs_skill) [x] `build_summary()` (caller: explain_docs_skill) — clean
  - [ ] `load_docs()` / `load_doc_meta()` / `build_windowed_doc()` — see 06-F05 dup; `load_doc_meta` shares 06-F08 read pattern
  - [x] `score_doc()` / `extract_query_tokens()` — language-safe tokens (≥3 chars), bounded scoring — clean
  - [ ] `sanitize_rel_path()` — lexical-only (06-F08); blocks `..` + non-`.md` — adequate but weaker than the WS realpath guard

#### `classes/local/wizard/services/lookup/markdown_renderer.php` (class `markdown_renderer`)
- [ ] D1 — see 06-F01 (HIGH: `javascript:` scheme link); [x] D2 [x] D3 [x] D4 [x] D5 [x] D6
- methods:
  - [x] `render()` — fenced code/tables/headings/lists/paragraphs all `htmlspecialchars`-escaped — clean
  - [ ] `inline_format()` — emits the unsafe href from `format_non_doc_link` (06-F01)
  - [x] `resolve_internal_doc_link()` — rejects schemes/`//`/absolute, `.md`-only, normalises traversal — clean
  - [x] `normalize_relative_docs_path()` — rejects `..` escaping root — clean
  - [ ] `format_non_doc_link()` — returns dangerous-scheme `$raw` unchanged — root of 06-F01
  - [x] `build_moodle_url_from_parts()` — `moodle_url`-based — clean

## D. Go-live blockers from this section

- **None are hard blockers.** The single HIGH item (06-F01, `javascript:`-scheme markdown link in `markdown_renderer`) is gated today by the fact that corpora are admin-controlled directories under `$CFG->dirroot` containing shipped docs, so the markdown source is trusted. It should nonetheless be fixed pre- or immediately post-launch with a one-line scheme allowlist, because it is the only place the engine renders markdown into live HTML and the control is a config-time assumption, not an enforced invariant.
