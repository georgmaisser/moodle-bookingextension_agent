# 06 · Discovery: families, embeddings & ranking

> **Scope.** The discovery phase: how the engine decides which skill *families* are
> relevant before any concrete skill is loaded, on two independent paths. Flowchart
> subgraph: `ORCH` (discovery half) + `SUPPORT` embeddings.

Discovery is the part of the planner that runs **without a chat call**. Its job is to hand
the [selector](07-selection-and-construction.md) a small, deterministic, budgeted set of
**family-scoped** skill candidates — never the full skill list. It does this two ways,
depending on whether semantic embeddings are available, and both ways converge on the same
budgeted hand-off.

**Files:** `services/discovery/*` (`family_registry_service`, `core_family_set`,
`context_prior_builder`, `family_signal_ranker`, `family_ranker`,
`discovery_stage_controller`, `discovery_budget_policy`, `discovery_confidence_policy`),
`services/embeddings/*` (`family_embeddings_retrieval_service`,
`embeddings_readiness_service`, …), `services/catalog/adaptive_skill_catalog_service.php`,
`contracts/skill_family_contract.php`, `dto/discovery_result.php`.

---

## Table of contents

1. [What a family is](#1-what-a-family-is)
2. [The family registry & core set](#2-the-family-registry--core-set)
3. [The dual discovery path](#3-the-dual-discovery-path)
4. [The embedding query](#4-the-embedding-query)
5. [Signals & the context prior](#5-signals--the-context-prior)
6. [Ranking](#6-ranking)
7. [Staged expansion (A → B → C)](#7-staged-expansion-a--b--c)
8. [Embeddings infrastructure](#8-embeddings-infrastructure)
9. [Flowchart notes](#9-flowchart-notes)

---

## 1. What a family is

A **family** is a lightweight grouping of skills, named `<namespace>.<family>` (lowercase,
validated against `^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`; default `core.general`). It is
derived from a skill's prompt contract — a skill declares its family, or one is inferred as
`<namespace>.general` from its name (e.g. `mod_booking.create_option` → `mod_booking.general`).

Discovery works on families, not concrete skills, because there are far fewer of them and
they are cheap to rank. Only after a family is selected are its concrete skills loaded
(lazy loading — see [ch. 07](07-selection-and-construction.md)).

The `discovery_result` DTO carries `families` (all discovered), `contextfamilies` (matching
the namespace hint — soft), `corefamilies` (always-on), and `contextprior` (ranking
metadata).

---

## 2. The family registry & core set

`family_registry_service::discover(promptcontracts, contextprior)` extracts every family
from the active prompt contracts, deduplicates and sorts them, **softly** prioritizes those
matching a `namespace_hint` (e.g. `mod_booking.*`) — falling back to *all* families if none
match — and always merges in the core set. There is no separate registration step: a
third-party skill participates automatically just by declaring a valid family in its
contract.

`core_family_set::resolve(promptcontracts)` returns the always-on baseline: `core.general`
is hardcoded, plus any `core.*` families from the contracts, hard-capped at
`MAX_CORE_FAMILIES = 4`. This guarantees the engine's own safety/utility families are always
in the candidate set regardless of context.

> Note: `core.search_skills` reaches the planner not through the core *family* set but
> through `ALWAYS_INCLUDE_SKILL_NAMES` in the adaptive catalog (see [§4](#4-the-embedding-query)).

---

## 3. The dual discovery path

The flowchart's `EMB_AVAIL` gate. `run_discovery_phase()` checks
`embeddings_readiness_service::is_wunderbyte_embeddings_available()` plus a ready catalog
status, and a non-empty query:

- **Path A — semantic.** When `aiprovider_wunderbyte` embeddings are available and the
  catalog is ready, it calls `llm_call_service::invoke_embeddings()` for the query vector,
  retrieves top-k skill rows, and — when the `FAMILY_EMBEDDINGS_ENABLED` feature flag is on
  — scores families semantically (`family_embeddings_retrieval_service::score_families()`)
  and boosts the skill rows by family score.
- **Path B — deterministic.** When the `FAMILY_DISCOVERY_ENABLED` flag is on, it scores
  families from **signals only** (`family_signal_ranker`), optionally augmented by semantic
  scores if embeddings happened to be available, then runs the staged controller.

The two paths are **independent**: Path B works with no embeddings at all. Whichever path
runs, the selector always receives deterministic, **budgeted, family-scoped** candidates —
never a full skill dump. This is the `LG_DET` legend ("no full skill dump to planner") and
the mandatory dual-path rule in `LG_PLAN`.

---

## 4. The embedding query

The query text drives both the semantic retrieval and the signal ranking. It is built as:

```
query = latest user message
        + next_step_intent            (thread metadata, when set)
        + any planned-placeholder intents (multi-step queue, when present)
```

Appending `next_step_intent` is what prevents a short confirmation ("ja", "ok", "weiter")
from being treated as a brand-new, contextless request — the pending intent keeps the query
anchored to what the agent was about to do. See [ch. 03 §5](03-conversation-store.md) for
where `next_step_intent` is stored.

`adaptive_skill_catalog_service::ALWAYS_INCLUDE_SKILL_NAMES` force-includes a few skills in
the post-discovery catalog regardless of ranking:

```php
const ALWAYS_INCLUDE_SKILL_NAMES = [
    'mod_booking.update_option_trainer',
    'mod_booking.book_users',
    'core.search_skills',            // universal dynamic-discovery fallback
];
```

These are the skills that must always be reachable: the two highest-traffic booking actions
and the RAG fallback (`core.search_skills`) that can find anything discovery missed.

---

## 5. Signals & the context prior

`family_signal_ranker::score_families(families, contextprior, recentskillnames)` produces a
**language-agnostic** score per family from structural signals only (no lexical keywords):

| Signal | Default weight | Condition |
|--------|----------------|-----------|
| base | 0.20 | every family gets a floor |
| core bonus | 0.10 | family starts with `core.` |
| namespace hint | **0.35** | family namespace == `contextprior.namespace_hint` |
| recency namespace | **0.20** | family namespace appears among recently used skills |

`context_prior_builder::build(contextid, signals)` assembles the prior: `contextid`,
`namespace_hint`, `page_type` (e.g. `mod-booking-view`), and `user_state` (authenticated +
userid). Crucially it sets **`is_hard_filter => false`**: the context is always a **ranking
prior, never a hard exclusion** (the `LG_PLAN` rule "Moodle context acts as ranking prior,
not hard filter").

---

## 6. Ranking

`family_ranker::rank(families, signalscores, semanticscores)` merges the two score sources
deterministically:

```php
const SIGNAL_WEIGHT   = 0.7;
const SEMANTIC_WEIGHT = 0.3;

$score = empty($semanticscores)
    ? $signal                                       // deterministic-only path
    : (0.7 * $signal) + (0.3 * $semantic);          // blended when embeddings present
// clamped to [0, 1]; sorted desc, tiebreak alphabetical
```

`select_low_score_tail()` then appends up to `LOW_SCORE_TAIL_MAX = 2` extra families that
score below the selected floor but above `LOW_SCORE_TAIL_MIN_SCORE = 0.15` — a deliberate
**recall safety net** so a slightly-low-scoring but relevant family still reaches the
selector.

---

## 7. Staged expansion (A → B → C)

`discovery_stage_controller` expands the candidate set only as far as confidence requires,
using hard budgets (`discovery_budget_policy`) and confidence thresholds
(`discovery_confidence_policy`):

| Stage | Families considered | Budget | Advance when |
|-------|---------------------|--------|--------------|
| **A** (default) | context + core | **12** | top score ≥ **0.60** → stop |
| **B** | + all ranked families | **24** | top score ≥ **0.45** → stop |
| **C** (last resort) | all ranked families | **36** | always returns |

Stage A is the common case: context + core families, tight budget. Only when confidence is
low does the engine widen to adjacent domains (B) and finally a global slim fallback (C) —
and even C is bounded at 36 families and is still families, never a full skill dump. The
escalation reason (`stage_a_low_confidence`, `stage_b_low_confidence`) is recorded for
telemetry.

---

## 8. Embeddings infrastructure

`embeddings_readiness_service::get_catalog_status()` decides whether the semantic path is
usable. It checks that the embeddings CSV exists, has the required schema columns, and is
**up to date** — every expected skill must be present with a matching `embedding_model`,
`embedding_dimensions`, and `content_hash` (so a changed skill contract invalidates stale
embeddings). Status is `ready` / `missing` / `invalid` / `stale`.

When not ready, `ensure_rebuild_scheduled_if_needed()` queues the
`rebuild_skill_catalog_embeddings_adhoc` task (default model `text-embedding-3-small`, 1536
dims). The same pattern backs the docs corpus
(`rebuild_docs_embeddings_adhoc`). Family-level semantic scoring
(`family_embeddings_retrieval_service`) computes cosine similarity between the query vector
and each skill embedding, keeping the **best** score per family, and can re-boost skill rows
by blending skill and family scores (default 0.7/0.3). See
[operations/tasks-and-async.md](../operations/tasks-and-async.md).

---

## 9. Flowchart notes

> **⚠ `FSIG` signal list.** The node lists signals `intent_code + trigger_id + context_prior
> + recency`. `family_signal_ranker` actually scores on **base + core bonus + namespace_hint
> (0.35) + recency_namespace (0.20)** — there is no `intent_code` or `trigger_id` input.
> The intent here matches ("language-agnostic structural signals + recency"), but the named
> components differ. *Candidate flowchart correction.*

> **⚠ `FRANK` scoring formula.** The node describes `score = semantic_similarity + signal
> + context_prior + optional recency_bias` (additive). `family_ranker` computes a **weighted
> blend** `0.7·signal + 0.3·semantic` (or signal alone with no embeddings); `context_prior`
> and `recency` are folded **into** the signal score, not added separately. Same inputs,
> different combination. *Candidate flowchart correction.*

> **✓ Confirmed:** dual path with deterministic fallback; query = latest message +
> next_step_intent; `ALWAYS_INCLUDE` = update_option_trainer + book_users + **search_skills**
> (the diagram omits search_skills); Stage budgets 12/24/36; confidence 0.60/0.45;
> context prior is soft (`is_hard_filter = false`); low-score tail (max 2, min 0.15).

See [reference/flowchart-guide.md](../reference/flowchart-guide.md) for the consolidated log.
