# 18 · Site-content semantic search

> **Status — Stage 1 (mechanism slice).** The engine-free indexer + the two-gate access-checked
> retrieval service are built for **one** area (`mod_page`), proven by PHPUnit, and adversarially
> security-reviewed (no access leak). There is no governance UI, no dedicated capability, no
> user-facing skill and no full area whitelist yet — those are Stages 2–4 (see [§6](#6-staged-rollout)).
> The whole feature lives **in the agent** and depends on nothing but the [retrieval
> foundation](17-retrieval-foundation.md); it does **not** require a configured global-search engine.

Site-content search lets the agent find Moodle content (course pages today) by meaning, using the
same embeddings store as docs/skills — with an access model that guarantees a user only ever finds
content they may already see.

---

## Table of contents

1. [Why core_search Areas, but our own everything-else](#1-why-core_search-areas-but-our-own-everything-else)
2. [The two-gate access model](#2-the-two-gate-access-model)
3. [Indexing (engine-free)](#3-indexing-engine-free)
4. [Retrieval](#4-retrieval)
5. [Enablement & guards](#5-enablement--guards)
6. [Staged rollout](#6-staged-rollout)

---

## 1. Why core_search Areas, but our own everything-else

Moodle's `\core_search` **Areas** are the one part we reuse: an area (e.g. `mod_page\search\activity`)
already declares *what* is indexable, *how* to enumerate it, and *who* may see each item. Everything
else — extraction, chunking, embedding, storage, retrieval, ranking — we do ourselves, so the feature
needs no Solr/global-search engine and stays self-contained.

One correction the build had to make: an area's `get_document()` is **not** engine-free — it routes
through the search-engine-gated document factory and fatals when no engine is configured. So the
indexer never calls it. It iterates the engine-free `get_document_recordset()` and extracts plain text
itself with `content_to_text()` on exactly the fields the area's own `get_document()` would read.

`classes/local/wizard/services/sitesearch/`

| Class | Role |
|-------|------|
| `site_content_area_registry` | The curated area whitelist (v1: `mod_page` only), which areas an admin enabled, and engine-free area instantiation. |
| `site_content_extractor` | One recordset row → normalised plain text + live provenance (context/course/instance id), engine-free. |
| `site_content_index_service` | Rebuild the index for the enabled areas via the store's generation swap. |
| `site_access_context_lister` | The engine-free candidate-context prefilter (gate 1). |
| `site_content_search_service` | Query → prefilter → `search_top_k` → authoritative per-hit `check_access` → deep link. |
| `site_content_row_mapper` | The `site_content` area mapper (DB-only) registered in the store factory. |

---

## 2. The two-gate access model

A user must **never** receive a hit for content they cannot already access. Two independent, fail-safe
gates enforce it:

1. **Context prefilter (recall/perf only — never an allow-list).** `site_access_context_lister`
   builds the set of module contexts the user can see, engine-free: a site admin gets a *global*
   (`null`) filter; everyone else gets the `context_module` ids of their `uservisible` modules across
   their active enrolments. A user with nothing visible yields an **empty** filter → the DB store
   narrows to **zero rows** (fail-closed). This gate can only ever *remove* candidates, never add
   authority.
2. **Authoritative per-hit check (the sole authority).** Every candidate returned by the store is run
   through the owning area's `check_access($docid)` and only `ACCESS_GRANTED` survives — evaluated
   live against the current `$USER` (it re-loads the course module and rechecks `uservisible`). The
   stored `docid` is the area-internal instance id the area expects. Candidates are over-fetched (5×)
   before this gate so the deliberately-permissive prefilter never starves the result set.

The fail-safe direction is **under-grant** (a missing hit is acceptable); an over-grant would be a
critical bug, which is why the prefilter is advisory and `check_access` is authoritative.

**Hard DB-only guard.** Site content is served **only** on the DB backend. The CSV backend ignores the
retrieval filter (and no-ops context invalidation), so serving site content from it would leak
cross-user rows. The guard is enforced three ways: `is_ready()` requires `embeddingsstore=db` in both
the index and search services; the `site_content` mapper throws on every CSV entry point; and
`check_access` runs regardless of backend.

---

## 3. Indexing (engine-free)

`site_content_index_service::rebuild()` mirrors the docs rebuild on the store's generation swap:
`begin_generation('site_content', …)` → per enabled area, iterate `get_document_recordset()` →
extract plain text → chunk (`markdown_chunker`, size-bounded) → reuse the vector when the chunk's
content hash is unchanged (else embed) → `upsert` an `embedding_row` **with provenance** (docid =
instance id, contextid, courseid, owneruserid) → `commit_generation` (atomic swap + prune). On any
error the generation is discarded, never half-published.

Provenance is always re-taken from the fresh extraction even when the vector is reused, so a moved
module can never leave a stale context in the index. Embedding is injectable, so the whole path
(including access gating) is tested without the LLM provider.

---

## 4. Retrieval

`site_content_search_service::search($query, $contextid, $k)` embeds the query, builds the prefilter
for the current `$USER`, calls `embeddings_store::search_top_k('site_content', …, $filter)` with a 5×
over-fetch, then keeps only candidates that pass the area's `check_access`. Each surviving result
carries the title, a deep link to the module view (resolved from the stored module context) and the
score. The `search→agent` direction is preserved: a future `searchengine_wbvector` plugin could call
this service, never the reverse.

---

## 5. Enablement & guards

Default is **all areas OFF** — nothing is indexed until an admin enables an area in the
`sitesearchareas` setting (Stage 1's single toggle; Stage 2 replaces it with a governance page +
capability). At run time the indexer and retrieval self-disable unless: the backend is `db`, the
embeddings provider is present, and (indexing) Moodle 5+. The scheduled task
`rebuild_site_content_embeddings` runs hourly and is a cheap no-op while site search is off.

---

## 6. Staged rollout

- **Stage 1 (built):** `mod_page`, engine-free indexer, two-gate access-checked retrieval, DB-only
  guard, default-off toggle, scheduled task, PHPUnit + adversarial security review.
- **Stage 2:** the `bookingextension/agent:configuresitesearch` capability + a `skill_governance`-style
  governance page (per-area enable matrix + a chunk-count estimate and traffic light); `db/events.php`
  observers → `delete_by_context` for freshness (safe to defer — `check_access` denies deleted/hidden
  content at query time, so this is freshness, not access).
- **Stage 3:** broaden the whitelist. `book`/`course`/`section` extend the context lister (visible
  non-enrolled via `can_view_course_info` + front page); `forum`/`glossary`/`wiki` add user-authored
  content, which **requires** a privacy provider first and group-aware access. A known Stage-1 recall
  limitation to revisit here: the prefilter only covers enrolment-based access, so a non-enrolled
  role holder (e.g. a category manager) is under-served (safe, never a leak).
- **Stage 4 (optional):** a server-side ANN fast path swapped behind the frozen `search_top_k`, and/or
  the thin `searchengine_wbvector` core_search engine that forwards to this service.
