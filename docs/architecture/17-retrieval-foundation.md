# 17 · Retrieval foundation: the embeddings store

> **Status.** Layer 0 (contract + CSV adapter), Layer 1 (DB backend), the docs call-site wiring (P2)
> and the CSV→DB migration (P3) are implemented; the CSV backend is still the default. The **docs**
> index reads and rebuilds through the store behind the `embeddingsstore` flag; **skills/family**
> embeddings and site-content search are later phases (see [§6](#6-status-of-the-wiring)). This chapter
> documents the substrate the discovery embeddings
> ([chapter 06 §8](06-discovery-families-embeddings.md#8-embeddings-infrastructure)) and the docs
> lookup sit on.

The retrieval foundation is a single storage-agnostic contract for **all** embedding areas (docs,
skills, and — later — site content) behind **one** interface, so the backend (CSV today, DB next,
a server-side ANN index later) can change without touching a single caller.

---

## Table of contents

1. [Why a store abstraction](#1-why-a-store-abstraction)
2. [The contract and its DTOs](#2-the-contract-and-its-dtos)
3. [Backends](#3-backends)
4. [The DB backend in detail](#4-the-db-backend-in-detail)
5. [Selecting a backend](#5-selecting-a-backend)
6. [Status of the wiring](#6-status-of-the-wiring)

---

## 1. Why a store abstraction

Before this layer, the docs corpus and the skill catalog each talked to their own CSV repository
directly, and the family-level retrieval read CSV rows by hand. That hard-wired two things that
should be free to change independently:

- **Storage.** A per-node CSV file in `temp/` is fine for a single web head but does not share
  across a cluster, is not transactional, and cannot be queried. We want to move to a DB table
  without rewriting every reader.
- **Ranking.** Today every area scores by brute-force cosine in PHP. A large site-content corpus
  will eventually want a server-side approximate-nearest-neighbour index. That swap must not leak
  into callers.

Both concerns collapse into one seam: `search_top_k()` is *the* retrieval method, and everything
behind it — file vs. table, cosine vs. ANN — is an implementation detail.

---

## 2. The contract and its DTOs

`classes/local/wizard/services/retrieval/`

| Type | Role |
|------|------|
| `embeddings_store` (interface) | The single persistence + retrieval contract. |
| `embedding_row` | One stored row: the generic identity triple `(owner, refkey, refindex)`, the vector, a change-detection `contenthash`, and nullable **site provenance** (`docid`, `contextid`, `courseid`, `owneruserid`). |
| `embedding_hit` | A scored result — identity + display metadata + cosine `score`, **never** the vector. |
| `retrieval_filter` | Optional pre-narrowing for a query (allowed context ids / owner). It is **not** an access decision; the caller still runs the area's authoritative per-document check. |
| `embeddings_row_mapper` (interface) | Per-area translation between the generic DTOs and one area's concrete columns, plus its identity key. Implementations: `docs_row_mapper`, `skill_row_mapper`. |
| `embeddings_store_factory` | Resolves the active store from the `embeddingsstore` setting. |

The identity triple means different things per area — for docs it is `(corpus, chunk path, start
line)`, for skills `(skill, anchor kind, anchor index)`, for site content `(search area, doc id,
chunk number)` — and the mapper is the only place that knows which. Everything above the mapper
speaks DTOs.

The contract has four groups of methods:

- **Retrieval:** `search_top_k(area, model, dims, queryvector, k, minscore, filter)` → `embedding_hit[]`.
  Resolves the committed generation internally and never returns a raw vector. This is the ANN-swap seam.
- **Presence/readiness:** `exists`, `count_rows`, `fingerprint`, `set_fingerprint`.
- **Rebuild (atomic generation swap):** `begin_generation` → `upsert*` → `commit_generation`
  (or `discard_generation`), with `reuse_existing(key)` for hash-based reuse of unchanged rows.
- **Enumeration / invalidation:** `stream_rows` (diagnostics / rebuild source) and
  `delete_by_context` (a context was deleted).

A **variant** is the `(model, dimensions)` pair. Embeddings for different models live side by side,
so a model switch never invalidates the others.

---

## 3. Backends

| | `csv_embeddings_store` | `db_embeddings_store` |
|---|---|---|
| Storage | One CSV file per area+variant in `temp/` | Two shared tables (see §4) |
| Vector encoding | JSON array (float64 text) | little-endian **float32** blob (`pack/unpack('g*')`) |
| Atomic rebuild | temp-write-then-swap of the file | generation number + a meta-pointer flip |
| Reuse lookup | lazily-built key→offset index over the prior file | `sha1(identity key)` column lookup |
| Ranking | streaming cosine in PHP | streaming cosine in PHP (same engine) |
| Cluster-safe | no (per node) | yes |

Both delegate scoring to the same `embeddings_retrieval_service::search_top_k_streaming()`, which
holds only `k` candidates in memory and drops each vector as soon as it is scored. It accepts either
a JSON-encoded vector (CSV path) or a pre-decoded float array (DB path), so the ranking is identical
across backends.

---

## 4. The DB backend in detail

Two tables (`db/install.xml`, created on existing installs by the guarded `create_table` upgrade
step):

- **`bx_agent_embeddings`** — one row per embedding: the generic identity columns, `emodel`/`edims`,
  `contenthash`, `identityhash` (sha1 of the area identity key, for reuse), a `generation` number,
  the float32 `embedding` blob, the nullable site-provenance columns, and `timemodified`. Indexes:
  `(area, emodel, edims, generation)` for the scan, `(…, generation, identityhash)` for reuse, and
  `(contextid)` for context invalidation / future site narrowing.
- **`bx_agent_embeddings_meta`** — one row per variant: the `committedgeneration` pointer (0 = none
  published) and the source `fingerprint`. Unique on `(area, emodel, edims)`.

**float32.** Vectors are stored as little-endian float32, halving the byte size of a JSON array
while staying architecture-portable. Only exactly-representable values survive a round-trip
bit-exact (the tests assert on those); ordinary embeddings lose ~7th-decimal precision, far below
any ranking threshold.

**Generation swap (atomic, no long transaction).** A rebuild calls `begin_generation` (which returns
`max(generation)+1`), then `upsert`s rows under that number — invisible to readers, who only ever see
the `committedgeneration` from meta. `commit_generation` flips the meta pointer in a short
transaction, then prunes older generations. Crucially there is **no** DB transaction spanning the
embed loop (that loop is bound by slow embedding-API calls); the new rows are simply unreferenced
until the pointer moves. `discard_generation` deletes an uncommitted generation and refuses to touch
the live one.

**Reuse on rebuild.** `upsert` stores `sha1(mapper->identity_key_for_row(row))`. On the next rebuild,
`reuse_existing(key)` hashes the caller's key and looks it up within the committed generation, so an
unchanged chunk is reused (its content hash compared) instead of re-embedded. `identity_key_for_row()`
is the DTO-form twin of the mapper's CSV `identity_key()`; the two must agree exactly.

---

## 5. Selecting a backend

`embeddings_store_factory::instance()` returns `db_embeddings_store` when the
`embeddingsstore` admin setting equals `db`, and `csv_embeddings_store` otherwise. The default is
CSV until the migration flips it (P3). Because both satisfy the same contract, the flag changes no
caller.

---

## 6. Status of the wiring

**Wired through the store (P2):** the whole **docs** path — `docs_lookup_service::search_semantic`
(read), `docs_embeddings_index_service::rebuild` (generation-swap write) and
`docs_embeddings_readiness_service` (exists / coverage / fingerprint) — resolves its backend via
`embeddings_store_factory`. Flip `embeddingsstore` to `db` and the docs index reads and rebuilds
against `bx_agent_embeddings`; the default stays CSV.

**Still on CSV (by design, for now):** the **skill-catalog** and **family** embeddings. Their
retrieval is multi-vector (many anchors per skill, aggregated max-per-skill) / family-level, which
does not map onto `search_top_k`; they are small (~41 skills) so per-node CSV is adequate. Migrating
them needs a multi-vector store method and is a separate step — `skill_selection_debug_service`, the
skill-catalog rebuild task and the discovery path are untouched by P2.

**Migration (P3):** switching `embeddingsstore` to `db` imports the existing docs CSV index into the
DB backend once — synchronously in the setting's updated-callback. No re-embedding: the CSV rows
already carry their vectors, so it is a plain stream + upsert through the shared contract, with the
source fingerprint carried across so readiness sees the DB index as in-sync. The same import is
available for ops as `cli/migrate_embeddings_to_db.php` (`embeddings_store_migration_service`). If the
import is ever skipped, the readiness rebuild fallback re-embeds instead.

The **site-content** area (its own mapper + an engine-free context lister + the authoritative
`check_access()` per hit) is built on top of this same store — Stage 1 (mod_page) is live; see
[chapter 18](18-site-content-search.md). A later phase deprecates the CSV backend (P4). See the joint
blueprint `docs/Blueprints/todo/retrieval_foundation_and_site_search_2026-07-02.md`.
