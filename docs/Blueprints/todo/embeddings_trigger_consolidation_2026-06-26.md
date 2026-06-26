# Embeddings catalog triggers — consolidation + ideal design

Status: IMPLEMENTED (Phases 0–4, 2026-06-26). Phases 5–6 deferred (see end). This supersedes the
partial coverage-based scheduling previously in place.

Pragmatic deltas vs. the original plan: Phase 0 unified the *scheduling* via a shared
`embeddings_rebuild_scheduler::queue_if_due()` helper (config-marker debounce + deduped
`queue_adhoc_task`) rather than a full readiness base class — readiness mechanics legitimately
differ (docs use a stat fingerprint; skills reuse the live expected set). Skills therefore need NO
stored fingerprint: the orphan check is a set-membership test over the freshly built expected
catalog (cheap for ~41 skills), added right after the existing expected-hash drift loop. Note the
skill `get_catalog_status()` short-circuits to ready under `PHPUNIT_TEST`, so the skill orphan check
(like the pre-existing drift loop) is not exercised by unit tests; the docs fingerprint path IS
unit-tested (`docs_embeddings_readiness_coverage_test::test_source_drift_is_stale`).

Implemented surface:
- `embeddings_csv_repository_base`: `get_fingerprint_path()/read_fingerprint()/write_fingerprint()/delete_fingerprint()` (atomic sidecar `<csv>.fingerprint`).
- `embeddings_rebuild_scheduler` (new): single debounce+queue path; used by both readiness services + upgrade.
- `docs_embeddings_index_service::compute_source_fingerprint()`; written only on FULL rebuild after commit.
- `docs_embeddings_readiness_service::get_status()`: fingerprint compare → `stale/source_changed`; scheduling routed through the shared helper.
- `embeddings_readiness_service::get_catalog_status()`: orphan check; scheduling routed through the shared helper.
- `rebuild_docs_embeddings_adhoc`: post-rebuild sanity check (full-rebuild + status ok), new lang `embeddingsdocsrebuildfailed`.
- `docs_lookup_service::search_semantic()`: dangling hit (corpus unresolvable / file gone) → schedule rebuild, never surface a broken reference.
- `db/upgrade.php` savepoint 2026062406 + `version.php` bump: deploy-time reconcile of both indexes (no-op when nothing changed).

Verified: agent PHPUnit suite green (554 tests, 51 provider/real-LLM-gated skips); docs/embeddings
focused tests green incl. the new drift test. No `mod_booking` code touched.

## Problem (verified in code)

There are TWO embeddings indexes, each with its own readiness/scheduling service, and the
two diverge — and neither reliably reacts to **removed** source items:

- Skill catalog: `services/embeddings/embeddings_readiness_service`
  - `get_catalog_status()` builds the EXPECTED rows (`build_full_catalog_rows`) and compares
    each expected skill's `content_hash`/model/dims to the stored row → detects **added/changed**
    skills (`stale`). It does NOT check for **orphan** rows (a removed skill's row lingers) → a
    removed skill stays `ready`.
  - Drift is recomputed on every discovery turn (callers: `discovery_phase_service:278`,
    `skill_discovery_service:76`) — affordable for ~41 skills.
  - `ensure_rebuild_scheduled_if_needed(array $status, model, dims, debounce)` →
    `reschedule_or_queue_adhoc_task` (no config debounce).
  - Rebuild task `rebuild_skill_catalog_embeddings_adhoc` HAS a post-rebuild sanity check
    (`get_catalog_status` ready, else throw → faildelay).

- Docs corpus: `services/lookup/docs_embeddings_readiness_service`
  - `get_status()` = provider + CSV exists + valid schema + **coverage only** (every resolvable
    corpus has ≥1 row). It does NOT detect added/changed/removed **docs** within an existing
    corpus — only a brand-new (0-row) corpus flips it.
  - `ensure_rebuild_scheduled_if_needed(int $debounce=300)` gates on `get_status()['ready']`,
    uses a config-marker debounce (`docs_embeddings_rebuild_queued_at`) + `queue_adhoc_task`.
    Callers: `explain_docs_skill:358`, `discovery_phase_service:280`, and the `aidocsroot`
    settings updated-callback (`settings.php:278`).
  - Rebuild task `rebuild_docs_embeddings_adhoc` has NO sanity check (just mtrace).

Both rebuild services already PRUNE correctly (re-emit current chunks + drop undeclared
corpora / orphan skills) — so the rebuild output is right. The gap is purely the TRIGGER: a
removed (and for docs, an added/edited) source item does not flip readiness, so no rebuild is
scheduled and the stale/orphan rows linger until an unrelated not-ready condition or a manual
rebuild. There is also NO upgrade/deploy trigger (`db/upgrade.php` has none), even though docs
and skills ship in the plugin code and change at release time.

## Target design

One uniform, cheap, removal-aware trigger for BOTH indexes, plus a deploy-time reconcile.

1. **Source fingerprint = the drift + removal detector.** A deterministic hash over the COMPLETE
   current source set, so any add / edit / remove flips it (set-membership + content signal):
   - Docs: over the registry's resolvable corpora, scan `.md` files (excluding `pix/`) and hash the
     sorted list of `(corpus_id, relpath, filesize, filemtime)` plus the declared-corpus id list.
     Stat-only — cheap (~190 files), no chunking/embedding. Removing a file or a corpus changes the
     set → fingerprint flips.
   - Skills: hash the sorted list of `(skillname, content_hash)` (reuse the per-skill canonical hash
     already computed by `embeddings_catalog_builder_service`). Set-based → also catches **orphans**
     (removed skills), which the current expected-only loop misses.
2. **Readiness = exists && valid schema && stored_fingerprint === live_fingerprint.** This single
   rule replaces the docs coverage check AND the skill expected-hash loop, uniformly, and closes the
   removal gap for both. (Coverage is implied: a missing corpus changes the fingerprint.)
3. **The rebuild stores the fingerprint it built from** (sidecar next to the variant CSV, e.g.
   `<index>__<variant>.fingerprint`, managed by `embeddings_csv_repository_base`). After a successful
   atomic publish, stored == live → ready. No rebuild loop.
4. **Reconcile on plugin upgrade (deploy).** `db/upgrade.php` schedules both rebuilds at the new
   savepoint (gated on provider available / docs skill active). Because scheduling now compares
   fingerprints, the upgrade hook is a no-op when nothing changed and a real rebuild when a release
   added/removed docs or skills — so every deploy self-reconciles.
5. **Query-time defensive (docs).** `docs_lookup_service` already drops a hit whose source file is
   unreadable; additionally call `ensure_rebuild_scheduled_if_needed()` on such a dangling hit so a
   stale index self-heals on the next cron, and never surface a broken reference.

## Implementation plan (phased)

### Phase 0 — Unify the two readiness/scheduling services
- New `services/embeddings/embeddings_index_readiness_base` with the shared logic: provider check,
  exists + valid-schema check, fingerprint compare → `is_ready()`/status, one
  `ensure_rebuild_scheduled_if_needed()` (single debounce strategy via
  `reschedule_or_queue_adhoc_task`), and a shared post-rebuild sanity helper.
- Abstract per-index hooks: `variant_repo()`, `rebuild_task_class()`, `compute_source_fingerprint()`,
  `is_provider_available()`.
- Refactor `embeddings_readiness_service` (skill) and `docs_embeddings_readiness_service` to extend it.
  Keep the existing public method names so callers (discovery_phase_service, skill_discovery_service,
  explain_docs, settings callback) are untouched.

### Phase 1 — Source fingerprint
- `embeddings_csv_repository_base`: add `read_fingerprint()/write_fingerprint(string)` (variant-scoped
  sidecar file, atomic write).
- Docs: `docs_embeddings_index_service::compute_source_fingerprint()` (registry scan, stat-based).
- Skills: `embeddings_catalog_builder_service` exposes a `compute_catalog_fingerprint(registry,model,dims)`
  (sorted `(skillname, content_hash)`); used by the skill readiness.
- Readiness `is_ready()` switches to the fingerprint compare (replacing docs-coverage + skill-expected-loop).

### Phase 2 — Rebuild writes fingerprint + sanity-check parity
- Both index services write the fingerprint after a successful publish (from the same scan).
- Add the post-rebuild sanity check to `rebuild_docs_embeddings_adhoc` (parity with the skill task):
  status must be ready afterwards, else throw → faildelay backoff.
- No change to the prune logic (already correct).

### Phase 3 — Upgrade reconcile
- `db/upgrade.php`: at the new savepoint, call the unified `ensure_rebuild_scheduled_if_needed()` for
  both indexes (gated). Bump `version.php`.

### Phase 4 — Query-time defensive (docs)
- `docs_lookup_service`: on a hit whose source file is missing/unreadable, skip it AND schedule a
  rebuild via the unified scheduler; never emit a dangling reference.

### Phase 5 — Consolidate call sites
- All trigger points route through the unified `ensure_rebuild_scheduled_if_needed()` (one debounce).
  Keep the skill-use self-heal path and the `aidocsroot` settings callback.

### Phase 6 (optional) — periodic safety reconcile
- A low-frequency scheduled task that calls the unified scheduler for both indexes as an
  eventual-consistency backstop (cheap when fingerprints match).

## Verification
- Unit: fingerprint flips on add/edit/remove of a doc and on corpus add/remove; readiness goes
  not-ready on drift incl. removal (skill orphan + docs deletion); rebuild writes fingerprint → ready;
  a removed doc's row is pruned after the triggered rebuild; docs_lookup skips + schedules on a
  dangling hit. Keep the streaming/bounded-memory tests green. Run agent + mod_booking suites on the VM.

## Risks / notes
- Fingerprint cost: docs is stat-only over ~190 files (cheap); cache per request if needed. Skills
  reuse the existing per-skill hash (already O(41)).
- mtime caveat: a deploy that doesn't change mtime would be missed by the docs fingerprint alone —
  the Phase 3 upgrade hook covers the deploy case deterministically regardless of mtime.
- Keep the rebuild prune semantics unchanged; only the triggers change.
