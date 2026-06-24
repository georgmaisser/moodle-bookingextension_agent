# Engine ↔ Domain Boundary — Cleanup Register

**Date:** 2026-06-10
**Author:** Claude (review requested by Georg)
**Status:** ✅ Actioned 2026-06-10 — all of Tier 1 (2.1–2.5), Tier 2, and Tier 3 implemented in
one sprint. See the [completion log](#7-completion-log-2026-06-10).
**Related:** `project_wizard_local_plugin_extraction`, the "executor stays clean" rule,
`AGENT_IMPLEMENTATION_FLOWCHART.mmd` (legends `LG_3P`, `LG_AGN`, `LG_DET`)

---

## 0. Why this document exists

The agent engine (the part destined to be extracted as `local_wizard`) must be **fully
domain-agnostic**: it knows about *contracts* (skill_interface, risk classes, triggers,
prompt-contracts, provider interfaces), never about *concrete skills or domains*
(`mod_booking.*`, booking options, trainers, …). Domain behaviour enters **only** through
provider/skill interfaces and hooks.

This register lists every place where that boundary is currently crossed, so it can be cleaned
before/along with the `local_wizard` extraction. Two flavours:

- **Tier 1 — Engine carries concrete skill/domain knowledge** (true boundary violations; the
  "this entry in the selector makes no sense" league). These are hard blockers for extraction.
- **Tier 2 — Domain code physically inside the engine tree** (`classes/local/wizard/...`).
  Not "the engine knowing domain", but domain logic packaged with the engine; must be split
  out so the engine can ship without booking.

---

## 1. Verdict on the recent work (memory + generate_questions)

Reviewed commits `511679c … 50a0256` (generate_questions) and `788dbee … 95cc737` (memory).

- **generate_questions:** touched **no** engine file — only `core/skills/`,
  `services/questions/*` (its own feature services), lang and tests. ✅ Clean.
- **memory:** the **only** engine file touched was `orchestrator.php` (commit `788dbee`, +43
  lines) — the runtime-context injection, which is the explicitly sanctioned exception
  (memory is a cross-cutting *core* capability, like conversation recall, not a domain). ✅
  Within bounds. The injection routes by generic phase/channel and depends on
  `user_memory_service` (a core feature service, `core.*` skills), not on any domain.

So the recent tasks did **not** overstep. Everything below is **pre-existing** debt surfaced by
the review.

---

## 2. Tier 1 — Engine carries concrete skill/domain knowledge (fix first)

### 2.1 Selector catalog hard-codes domain skill names  ← the example Georg flagged
- **Where:** [classes/local/wizard/services/catalog/adaptive_skill_catalog_service.php:61-67](../../classes/local/wizard/services/catalog/adaptive_skill_catalog_service.php)
- **Leak:** `ALWAYS_INCLUDE_SKILL_NAMES = ['mod_booking.update_option_trainer', 'mod_booking.book_users', 'core.search_skills']`.
  The engine catalog force-injects two **booking** skills into every post-discovery selector
  catalog. The selector does not need — and must not encode — which concrete domain skills are
  "downstream companions".
- **Why wrong:** domain coupling in the ranking/selection pipeline; breaks 3rd-party
  onboarding and extraction.
- **Fix:** let skills/providers declare this generically — e.g. a prompt-contract/governance
  flag like `always_available` or a provider-declared "companion set", which the catalog reads
  without naming anyone. `core.search_skills` may stay as a generic engine-level fallback
  (it is a `core.*` engine skill), but the `mod_booking.*` entries must become provider-declared.
- **Severity:** High. **Origin:** pre-existing (commit `8d31838d`).

### 2.2 Planner prompt builder hard-codes a booking skill as the worked example
- **Where:** [classes/local/wizard/services/phase_prompt_bundle_builder.php:306-309](../../classes/local/wizard/services/phase_prompt_bundle_builder.php)
- **Leak:** the "Valid example" embedded in the planner prompt uses
  `{"skill":"mod_booking.create_option", …}`.
- **Why wrong:** the engine's prompt scaffolding teaches the model a specific domain skill.
- **Fix:** use a neutral/synthetic example (e.g. `example.do_something`) or render the example
  from the live catalog (first available skill) so no concrete name is hard-coded.
- **Severity:** Medium. **Origin:** pre-existing.

### 2.3 Static system prompt hard-codes a booking skill example
- **Where:** [classes/local/wizard/prompts/initial_system_prompt.md:48](../../classes/local/wizard/prompts/initial_system_prompt.md)
- **Leak:** example command uses `"skill": "booking.create_option"`.
- **Fix:** same as 2.2 — neutral example string.
- **Severity:** Low/Medium. **Origin:** pre-existing.

### 2.4 Executor knows a specific skill's sensitive fields
- **Where:** [classes/local/wizard/executor.php:51-53](../../classes/local/wizard/executor.php)
- **Leak:** `SENSITIVE_EXECUTED_INPUT_SUFFIX_FIELDS = ['recall_memory' => ['query']]`.
  The engine executor hard-codes one skill name and which of its input fields to redact.
- **Why wrong:** privacy redaction policy for a skill is the skill's own concern; the executor
  must stay skill-agnostic (same principle as `get_result_preview`).
- **Fix:** duck-typed optional skill method, e.g. `get_sensitive_input_fields(): array`, that
  the executor reads generically (return plain arrays, no engine types) — mirroring the
  established `get_result_preview` / `remember_preview_options` pattern.
- **Severity:** Medium. **Origin:** pre-existing (predates the memory feature).

### 2.5 Decision service defines a booking-domain trigger constant
- **Where:** [classes/local/wizard/services/decision/agent_decision_service.php:91](../../classes/local/wizard/services/decision/agent_decision_service.php)
- **Leak:** `private const TRIGGER_ALLOW_MISSING_USER_AUTOCREATE = 'booking.create_user_allowed_if_missing';`
  A booking-specific trigger id baked into the engine decision service (appears **defined but
  unused** — verify; if dead, delete).
- **Why wrong:** domain trigger semantics belong to the domain (message_trigger_registry /
  issue_code_provider), not to a constant in the engine router.
- **Fix:** remove if dead; otherwise route domain triggers through the provider's trigger/
  issue-code declarations so the engine handles them generically by id.
- **Severity:** Medium (Low if dead). **Origin:** pre-existing.

---

## 3. Tier 2 — Domain code packaged inside the engine tree

These are booking/entities domain services and DTOs living under `classes/local/wizard/...`.
They are *correctly* domain code, but sit in the engine tree, so the engine cannot be extracted
without dragging booking along. They are **not** called by the engine pipeline (verified — only
by domain skills), which is good; they just need to move to the domain/provider side on
extraction.

- **Booking option mutation glue:** [services/mutation/option_mutation_service.php](../../classes/local/wizard/services/mutation/option_mutation_service.php)
  (`booking.create_option`, `booking.update_option`, `booking.bulk_update_options`).
- **Entity mutation glue:** [services/mutation/entity_mutation_service.php](../../classes/local/wizard/services/mutation/entity_mutation_service.php)
  (entities domain).
- **Booking option lookup:** [services/lookup/option_lookup_service.php](../../classes/local/wizard/services/lookup/option_lookup_service.php)
  (`mod_booking.search_options`, `mod_booking.update_option`).
- **Canonical booking DTOs:** [dto/create_option_input_dto.php](../../classes/local/wizard/dto/create_option_input_dto.php),
  [dto/update_option_input_dto.php](../../classes/local/wizard/dto/update_option_input_dto.php),
  [dto/bulk_update_options_input_dto.php](../../classes/local/wizard/dto/bulk_update_options_input_dto.php).
- **Readiness reaches into booking internals:** [aiready.php:32,371](../../classes/local/wizard/aiready.php)
  uses `mod_booking\singleton_service::get_instance_of_booking_option_settings()`. Engine
  readiness should query availability through a provider hook, not a booking class.

**Severity:** structural (blocks extraction). **Origin:** pre-existing.

---

## 4. Tier 3 — Domain defaults in engine config (low)

- **Docs corpus default:** [services/lookup/docs_embeddings_index_service.php:44,48](../../classes/local/wizard/services/lookup/docs_embeddings_index_service.php)
  `DEFAULT_CORPUS_ID = 'mod_booking'` and the `aidocs_corpusid` config default. The engine
  should default to a provider-supplied corpus id rather than naming `mod_booking`.
- **Severity:** Low. **Origin:** pre-existing.

---

## 5. Explicitly NOT violations (so we don't "fix" them)

- **orchestrator memory injection** (`build_runtime_context_block` / `append_user_memory_section`
  / `memory_channel_for_phase`) — sanctioned core feature, domain-agnostic.
- **orchestrator.php ~2008** — the `mod_booking.create_option_canonical_fallback` reference is
  only an illustrative *comment* on a generic namespace-stripping regex; no domain logic.
  (Optional: swap the comment example for a neutral one.)
- **`core.*` triggers/skills** in `message_trigger_registry`, `prompt_policy_builder`,
  `core_family_set` (`core.general`), `skill_family_contract` (`core.general`) — these are
  engine-level *core* concepts, owned by the engine by design.
- **`services/questions/*` referencing `generate_questions`** — a feature service naming its own
  feature; domain-side, correct.
- **discovery / selection / interpreter / skill_selector** — swept, clean (no domain names).

---

## 6. Suggested order of work

1. **2.1** (selector catalog) — Georg's flagged item; introduce the generic
   "always-available / companion" declaration and move `mod_booking.*` behind it.
2. **2.4** (executor sensitive fields) — add `get_sensitive_input_fields()` duck-typed method.
3. **2.5** (decision trigger) — delete if dead, else route via provider.
4. **2.2 / 2.3** (prompt examples) — neutralise.
5. **Tier 3** corpus default — provider-supplied default.
6. **Tier 2** — fold into the `local_wizard` extraction (move domain services/DTOs to the
   booking side; `aiready` availability via provider hook).

~~No code changed yet — this is the register only.~~ **Superseded — all items actioned, see below.**

---

## 7. Completion log (2026-06-10)

All items implemented in one sprint. Engine tree (`classes/local/wizard/`) now carries **no**
concrete `mod_booking.*` skill name.

**Tier 1**
- **2.1 Selector catalog** — removed `ALWAYS_INCLUDE_SKILL_NAMES`. Skills now declare
  `'governance' => ['always_available' => true]` in `get_schema()`; the flag is threaded via
  `skill_contract_validator::build_skill_metadata()` → `skill_registry::build_prompt_contract()`
  → catalog, and read by `adaptive_skill_catalog_service::get_mandatory_skills()` (unioned with
  the engine-level `MANDATORY_SKILL_KEYWORDS`, which keeps `core.search_skills` reachable).
  `update_option_trainer` + `book_users` opt in via the flag.
- **2.2 Prompt builder example** — `phase_prompt_bundle_builder.php` now uses
  `example.create_record` instead of `mod_booking.create_option`.
- **2.3 Static system prompt** — `initial_system_prompt.md` example → `example.create_record`.
- **2.4 Executor sensitive fields** — removed `SENSITIVE_EXECUTED_INPUT_SUFFIX_FIELDS`; executor
  now calls the duck-typed `get_sensitive_input_fields()` generically (mirrors
  `get_result_preview`). `recall_memory_skill` implements it (`['query']`).
- **2.5 Decision trigger constant** — was dead; deleted
  `TRIGGER_ALLOW_MISSING_USER_AUTOCREATE` from `agent_decision_service.php`.

**Tier 2 — domain code moved to `mod_booking`** (not to a new namespace inside the agent
plugin — that was a wrong first attempt, corrected per Georg). DTOs and services now live under
`mod_booking\local\wizard\{dto,services\mutation,services\lookup}`; the engine-tree stubs were
**deleted** (no class_alias shims). The 4 unused `classes/external/booking_*` endpoints (never
registered in `db/services.php`, no callers) were deleted entirely. `aiready.php` resolves
booking statistics via duck-typed `mod_booking\local\wizard\booking\booking_readiness_provider`
(class_exists/method_exists), so the engine has no compile-time `mod_booking` dependency.

**Tier 3 — corpus default** — removed `docs_embeddings_index_service::DEFAULT_CORPUS_ID`;
fallback moved to `docs_corpus_registry::FALLBACK_ADMIN_CORPUS_ID` (back-compat value
`'mod_booking'`).
