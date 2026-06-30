# Cross-cutting Audit C5 — Architecture-Docs Coverage (horizontal)

**Scope:** the entire `docs/` corpus verified against current engine code — the 16 chapters in
`docs/architecture/` + `README.md`, the four `docs/developer-guides/*`, the three
`docs/reference/*`, the `docs/skills/README.md`, cross-referenced with the on-disk section
report `docs/audit/sections/08-preflight-pipeline.md`.
**Files audited (doc files):** 25 · **Methods/claims spot-verified against code:** ~45
**Arch chapter(s):** all (D6 is the central dimension) · **Flowchart nodes:** n/a (this sweep
audits the *prose* against code; flowchart-vs-code is C4/per-section)
**Auditor verdict:** ⚠️ issues (no blockers — docs are read-only; errors mislead operators but
cannot mis-execute a mutation)

Plugin version at audit: `version.php` = `2026062900`, release `1.0.0`.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | n/a   | Not a docs-coverage concern; security is covered by the per-subsystem + C1 reports. |
| D2 Moodle API      | n/a   | — |
| D3 Structure       | n/a   | — |
| D4 Duplication     | n/a   | — |
| D5 Flowchart       | n/a   | Flowchart-vs-code is owned by the per-section D5 verdicts; this sweep only flags the **flowchart-guide reference doc** (`docs/reference/flowchart-guide.md`) as a doc artifact (C5-F02/F04). |
| D6 Docs coverage   | **issues** | The conceptual model (layers, risk classes, safety pipeline, planner split, reply contract) is **accurate and well-written**. But the corpus has drifted behind two real refactors that already landed in code: the **DB table rename `local_wizard_` → `bx_agent_`** (data-model guide + observability are wrong on every table name) and the **orchestrator split into `planner_phase_service`** (chapters cite line numbers and a call location that no longer exist). Plus an **undocumented per-turn discovery LLM call**, a **missing table** (`bx_agent_user_memory`), a **missing skill** in the catalog (`wizard.scaffold_skill`), and stale counts/tree in README §10. None is a contract error; all are coverage/accuracy drift. |

### Per-chapter coverage table

Legend: **accurate** = claims hold against today's code · **drifted** = a stated claim is now
false (stale name/number/path) · **incomplete** = material live behaviour omitted.

| Doc | Verdict | Drift / gap (finding) |
|-----|---------|-----------------------|
| `architecture/README.md` | **drifted + incomplete** | §10 "~80 services" (real **121**); directory tree omits ≥10 service subdirs and top-level `contracts/`/`diagnostics/`/`config/` (C5-F06). §1 "small, fixed number of LLM calls" is undercut by the undocumented discovery normalizer call (C5-F03). |
| `01-entry-and-web-services` | **accurate** | WS list, sesskey split, readiness gate, attachments/PDF, doc-content hardening all verified. |
| `02-authorization-and-context` | **accurate** | Gate-1/Gate-2 split, context-level-agnostic resolver, availability layer, per-skill caps — all verified. |
| `03-conversation-store` | **drifted** | `add_step_message` cited at "orchestrator.php ~line 389"; real call is **orchestrator.php:267** (C5-F08). Section model otherwise accurate. Backing table name inherited from data-model is wrong (C5-F01). |
| `04-agent-runtime-and-loop` | **drifted** | Constants (`MAX_LOOP_STEPS=6`, retry codes) verified ✓. **§9 flowchart notes cite `orchestrator.php:1057/:1292/:707/:489`** — all stale; the planner LLM calls moved to `planner_phase_service.php:222/:432` and discovery embeddings to `discovery_phase_service.php:294` (C5-F02). |
| `05-planner-orchestrator` | **drifted + incomplete** | §2 table "Selection `orchestrator.php:1075`, Construction `:1309`" and §8/§9 `:489/:492` are stale (file is now 809 lines; calls live in `planner_phase_service`) (C5-F02). §1/§3 omit the discovery **query-English normalizer** LLM call (C5-F03). |
| `06-discovery-families-embeddings` | **incomplete** | Force-include (`wizard.search_skills`), removed mandatory tier, ranker weights, stage budgets — all verified ✓. **But §3 states discovery makes "No planner chat call" / "no chat call"**, which is now false: discovery invokes `query_english_normalizer::to_english()` (a `generate_text` call) before embedding (C5-F03). |
| `07-selection-and-construction` | **accurate** | Lazy load, selector/constructor contract, `planned_steps`/`next_step_intent`, validator division of labour all hold. |
| `08-decision-service` | **accurate** | Guard chain order, risk resolution (unknown→R3), risk-routed handling verified. |
| `09-preflight-pipeline` | **issues** (see section report) | Layer model + leak-inversion accurate; section 08 report flags two omitted behaviours (L2 shared-timeout retry; `!legacyvalid`→hard_block). Cross-referenced, not re-derived. |
| `10-shadow-queue` | **accurate** | Statuses, TTLs (900/300/900), atomic single-running, idempotency, planned placeholders, retry-layer cap verified. |
| `11-executor` | **accurate** | Re-auth, idempotency split, releasability, guard-token verify, three outcomes, adhoc task verified. |
| `12-synchronizer` | **accurate** | `llm_polish`-only, `commands=[]`, rejection codes, R2/R3 reply requirements verified. |
| `13-finalization-and-observations` | **accurate** | Classifier matrix precedence + code sets verified against `finalization_classifier`. |
| `14-skill-layer` | **accurate** | Interface, base classes, registry, provider-first wiring, contract validation verified. |
| `15-risk-classes` | **accurate** | The canonical matrix verified value-for-value. |
| `16-support-services` | **accurate** | Anonymizer, language policy, server-derived triggers (registry returns `[]`), error classifier, domain hooks verified. **Does not mention** `query_english_normalizer` (the new cross-language bridge) (C5-F03). |
| `developer-guides/data-model-and-db` | **drifted + incomplete** | §2/§3/§5/§8 use the **`local_wizard_` prefix** throughout; every table is really **`bx_agent_*`** (C5-F01). Omits the live **`bx_agent_user_memory`** table (C5-F05). Caches §6 accurate. |
| `developer-guides/skill-providers-and-families` | **accurate** | Provider-first model, families, docs/issue-code/normalizer/external-dep hooks all verified. |
| `developer-guides/web-services-api` | **accurate** | Param/return tables match `db/services.php` registrations; "not registered" internal API correct. |
| `developer-guides/writing-a-skill` | **accurate** | `base_skill` contract, `run_preflight()`/`execute()` shape, scaffold mention all correct. |
| `reference/glossary` | **accurate** | Terms map to chapters; no stale claims. |
| `reference/issue-codes` | **accurate** | Code sets match the classifier/preflight/queue emit sites spot-checked. |
| `reference/flowchart-guide` | **drifted** | Discrepancy log is itself stale: Planner section cites `orchestrator.php:1057/:1292/:687/:489` (gone); presents the **removed** mandatory tier (`get_mandatory_skills`/`MANDATORY_SKILL_KEYWORDS`/`always_available`) as the **current** state and names `core.search_skills` (real: `wizard.search_skills`) — directly contradicting ch.06 (C5-F02/F04). |
| `skills/README` | **incomplete** | Omits **`wizard.scaffold_skill`** (9 wizard skills exist, 8 listed) (C5-F07). Otherwise the risk classes / inputs match the skill classes. |

---

## B. Findings

### [C5-F01] ✅ RESOLVED (was 🟠 HIGH) · D6 Docs coverage · docs/developer-guides/data-model-and-db.md + docs/operations/observability.md
**✅ Resolved 2026-06-30:** `data-model-and-db.md` was re-prefixed to `bx_agent_` under C2-F02; `observability.md` lines 18/78 are now likewise `bx_agent_ai_llm_debug` (`grep local_wizard docs/operations/observability.md` → 0). No doc names the abandoned prefix as a live table. — _Original finding below._

**What:** Every documented agent table name uses the obsolete `local_wizard_` prefix; the real schema renamed all tables to `bx_agent_`.
**Evidence:** `db/install.xml` declares `bx_agent_ai_threads`, `bx_agent_ai_messages`, `bx_agent_ai_runs`, `bx_agent_ai_llm_debug`, `bx_agent_benchmark_runs/scenarios/baselines/metrics`, `bx_agent_user_memory`. `conversation_store.php` queries `'bx_agent_ai_threads'` etc. The data-model guide §2 states *"All agent tables use the **`local_wizard_`** prefix … **not** `bookingextension_agent_`"* and gives `m_local_wizard_ai_llm_debug`; observability.md line 18 prints `local_wizard_ai_llm_debug → physical: m_local_wizard_ai_llm_debug`. No `local_wizard_*` table exists in `install.xml`.
**Impact:** An operator following the docs to debug a live incident (LLM debug logs, run idempotency, thread metadata/queue) queries a **nonexistent** table (`m_local_wizard_ai_llm_debug`) and concludes "no data" — the single most operationally misleading error in the corpus. Also misleads anyone writing migrations or backups.
**Compensating control:** Code is internally consistent (all queries use `bx_agent_`); the error is doc-only, cannot affect runtime.
**Recommendation:** Global replace `local_wizard_` → `bx_agent_` in `data-model-and-db.md` and `observability.md`. Update the "legacy of the engine namespace" wording in §2 to reflect the `bx_agent_` rename (the local_wizard→bx_agent Phase-1 extraction step).

### [C5-F02] ✅ RESOLVED (was 🟠 HIGH) · D6 Docs coverage · docs/architecture/04 §9 + 05 §2/§8/§9 + docs/reference/flowchart-guide.md (Planner section)
**✅ Resolved 2026-06-30:** the stale `orchestrator.php:1057/:1292/:687/:489/:1075/:1309/:707/:492` citations were removed from ch.04 §9, ch.05 §2/§8/§9 and the flowchart-guide Planner section, and **re-anchored to stable class/method names** rather than new line numbers (which drift): selection/construction → `planner_phase_service::run_selection()` / `::run_construction()` (with a note that `orchestrator` keeps thin `run_selection_phase()` / `run_construction_phase()` delegating wrappers from the split), discovery embeddings → `discovery_phase_service`, synchronizer → the one remaining `invoke_for_context()` in `orchestrator`. A "citations name classes/methods, not line numbers" note was added so they don't re-rot. `grep` for the old numbers → 0. — _Original finding below._

**What:** The chapters and the flowchart-guide pin the "exactly two planner LLM calls" invariant to specific `orchestrator.php` line numbers and to the orchestrator as the call site; after the orchestrator split those line numbers are gone and the calls moved to `planner_phase_service`.
**Evidence:** `orchestrator.php` is now **809 lines**; its only `invoke_for_context()` is at **:372** (the synchronizer). The planner selection/construction LLM calls live in `services/planner_phase_service.php:222` and `:432`; discovery embeddings invoke is in `services/discovery_phase_service.php:294`. Yet ch.04 §9 cites *"`run_selection_phase()` (orchestrator.php:1057)"*, *"`run_construction_phase()` (orchestrator.php:1292)"*, *"`invoke_embeddings` at :687"*, *"synchronizer …:489"*; ch.05 §2 cites `:1075`/`:1309`, §8 `:489`; flowchart-guide line 90-93 repeats `:1057/:1292/:687/:489`. None resolves in the current file.
**Impact:** A maintainer trusting the "two planner calls" evidence trail follows dead line references and may not discover that selection/construction now flow through `planner_phase_service` (and `planner_phase_prompt_trait`). The *invariant itself still holds* (two planner chat calls max), so this is accuracy/traceability drift, not a behavioural contradiction — hence HIGH not BLOCKER.
**Compensating control:** The conceptual claim ("two planner LLM calls, conditional construction") is still true; only the citations are stale.
**Recommendation:** Re-anchor the citations to `planner_phase_service.php:222/:432`, `discovery_phase_service.php:294`, and `orchestrator.php:372` (synchronizer). Add one sentence to ch.05 noting the planner phases were extracted from `orchestrator` into `planner_phase_service` (the orchestrator is now a thin coordinator). Refresh the flowchart-guide Planner section.

### [C5-F03] ✅ RESOLVED (was 🟡 MEDIUM) · D6 Docs coverage · docs/architecture/06 §4 + 05 §2 + ch.16
**✅ Resolved 2026-06-30:** `query_english_normalizer` is now documented. ch.06 §4 gains a "Query normalization (the one discovery-time LLM call)" subsection (a fail-open, not-config-gated `generate_text` translation of the embedding query, scope = query only, not routing); ch.06 intro and ch.05 §2 + the context-block table soften "no chat call" → "no planner *decision* chat call (an optional fail-open query-translation call may run)"; ch.16 §6 lists the helper. — _Original finding below._

**What:** The discovery phase makes an LLM call (`query_english_normalizer::to_english()`, a `generate_text` translation of the embedding query) that the docs never mention — and ch.06 explicitly asserts the opposite ("**No** planner chat call" / "no chat call" in discovery).
**Evidence:** `services/discovery_phase_service.php:292` calls `(new query_english_normalizer())->to_english($embedquery, …)`; `services/llm/query_english_normalizer.php:69` builds a `generate_text` prompt ("Translate the text after 'TEXT:'") and invokes `llm_call_service::invoke_for_context()` (line 101). It is **fail-open** (returns the original query on error) and **not config-gated**, so it fires on a normal multilingual turn. The class docblock cites SKILL_REWORK.md §5.7 ("Weg B"). README §1 says the agent "makes a small, fixed number of LLM calls per turn"; ch.06 §3 and §9 say discovery issues no chat call.
**Impact:** The per-turn LLM-call accounting in the corpus is understated by one call on non-English turns; a reader optimising latency/cost or reasoning about the language contract would miss this step. It is *query normalisation*, not skill routing (anchors stay English, replies stay in the user's language), so the language-fidelity contract is intact — hence MEDIUM, not HIGH.
**Compensating control:** Fail-open design means a normalizer outage degrades to prior behaviour, not breakage; ch.16's "no language token lists" claim is still literally true.
**Recommendation:** Add a short subsection to ch.06 (and a line to ch.16's support-services list) documenting `query_english_normalizer`: what it does, that it is a discovery-time `generate_text` call, fail-open, scope = embedding query only. Soften README §1 / ch.05 §1 and ch.06 §3/§9 from "no chat call in discovery" to "no planner *decision* call (an optional query-translation call may run)".

### [C5-F04] ✅ RESOLVED (was 🟡 MEDIUM) · D6 Docs coverage · docs/reference/flowchart-guide.md (Planner section)
**✅ Resolved 2026-06-30:** the flowchart-guide's `EMB_QUERY` mandatory-tier bullet was rewritten to match ch.06 §4 — the mandatory tier (`get_mandatory_skills()`, `MANDATORY_SKILL_KEYWORDS`, `always_available`) is gone; discovery is semantic-only and the single sanctioned force-include is `wizard.search_skills` via `discovery_phase_service::ensure_search_skills_fallback()`, with ch.06 §4 named as the source of truth. — _Original finding below._

**What:** The flowchart-guide discrepancy log documents the *superseded* discovery design (the lexical mandatory tier) as the **current** state, contradicting ch.06 and the skills README which correctly say it was removed.
**Evidence:** Guide lines 115–121 describe `adaptive_skill_catalog_service::get_mandatory_skills()` unioning the `always_available` flag with `MANDATORY_SKILL_KEYWORDS` as the live mechanism, and line 112 names the force-include `core.search_skills`. Current code: `adaptive_skill_catalog_service.php:21-22` docblock states *"The former mandatory/recency tiering (MANDATORY_SKILL_KEYWORDS, get_mandatory_skills, always_available flag, recency Top-K) has been removed"*; `get_mandatory_skills` no longer exists as a method; the sole force-include is **`wizard.search_skills`** via `discovery_phase_service::ensure_search_skills_fallback()` (`:591`, constant `wizard.search_skills` at `search_skills_skill.php:34`).
**Impact:** The reference log that is supposed to be the authoritative code↔diagram reconciliation is internally inconsistent with the architecture chapter it backs; a reader gets two contradictory accounts of discovery's force-include. The chapters (06, skills README) are the correct ones.
**Compensating control:** ch.06 and skills/README are accurate, so the canonical narrative is right; only the historical log entry is stale.
**Recommendation:** Replace the two stale entries with a single ✅ entry: the mandatory tier was removed; the only force-include is `wizard.search_skills` via `ensure_search_skills_fallback()`. Fix the `core.search_skills` → `wizard.search_skills` name.

### [C5-F05] ✅ RESOLVED (was 🟡 MEDIUM) · D6 Docs coverage · docs/developer-guides/data-model-and-db.md
**✅ Resolved 2026-06-30:** `data-model-and-db.md` gained a `## 5b. User memory` section documenting `bx_agent_user_memory` (userid, memory, scopes, timecreated/timemodified) and its per-channel injection. — _Original finding below._

**What:** The data-model guide omits the live `bx_agent_user_memory` table, which backs the agent's memory feature.
**Evidence:** `db/install.xml` declares `<TABLE NAME="bx_agent_user_memory">`. It is read/written by `services/user_memory_service.php`, the `wizard.remember`/`forget`/`list_memories` skills, `runtime_context_block_builder.php`, `orchestrator.php`, and exported by `classes/privacy/provider.php`. The guide's table inventory (§3) lists only threads/messages/runs/llm_debug + the four benchmark tables; the entity sketch (§8) omits it.
**Impact:** A reader auditing PII/GDPR scope or planning a data export/purge would miss a user-data table — relevant because it stores user-stated facts/preferences and is part of the privacy provider's export. Operational/privacy completeness gap.
**Compensating control:** The privacy provider does export it, so GDPR mechanics work despite the doc gap.
**Recommendation:** Add a row for `bx_agent_user_memory` (columns + purpose: per-user stored memories, scopes) to §3 and the entity sketch; note its privacy-provider coverage.

### [C5-F06] 🟢 LOW · D6 Docs coverage · docs/architecture/README.md §10
**What:** README §10 understates the service count and gives a directory tree missing many real subdirectories.
**Evidence:** `find classes/local/wizard/services -name '*.php'` = **121** files (README §10 annotation: *"`services/` # ~80 engine services"*). The tree lists `discovery/ selection/ construction/ decision/ embeddings/ lookup/ mutation/ execution/ governance/ catalog/ security/` but the real `services/` also contains `activities/ attachment/ course/ debug/ introspection/ llm/ messaging/ questions/ risk/ scaffold/ telemetry/ trial/`. The top-level `classes/local/wizard/` tree omits real dirs `contracts/`, `diagnostics/`, and `config/`.
**Impact:** A new contributor's mental map of the engine is incomplete and the count is ~50% low; purely orientational, no correctness effect.
**Compensating control:** Each subsystem chapter lists its own real files, so per-area navigation is accurate.
**Recommendation:** Refresh §10 to the real subdir list and change "~80" to "120+ engine services" (or drop the number).

### [C5-F07] 🟢 LOW · D6 Docs coverage · docs/skills/README.md (wizard.* table)
**What:** The skills catalog omits `wizard.scaffold_skill`, an existing shipped skill.
**Evidence:** `classes/local/wizard/wizard/skills/scaffold_skill.php` exists (backed by `services/scaffold/skill_template_generator.php`); it is described in `developer-guides/writing-a-skill.md` (Quick-start box) but is absent from the skills README `wizard.*` table, which lists 8 of the 9 `wizard/skills/*` classes.
**Impact:** The "every skill the agent ships with" catalog is incomplete by one skill; minor.
**Compensating control:** writing-a-skill.md documents the scaffold flow, so the feature is not undocumented overall.
**Recommendation:** Add a `wizard.scaffold_skill` row (risk class per its class — verify R0/readonly — purpose: generate a contract-valid skill template ZIP from an NL description).

### [C5-F08] 🟢 LOW · D6 Docs coverage · docs/architecture/03-…md §7, 04-…md §9, reference/flowchart-guide.md
**What:** The `add_step_message()` call site is cited at "orchestrator.php ~line 389"; the real call is at orchestrator.php:267.
**Evidence:** `grep -n add_step_message orchestrator.php` → single hit at **:267** (inside `process()`). ch.03 §7 and ch.04 §9 both say "orchestrator.php ~line 389"; the attribution (it is in `orchestrator::process()`, not the loop head) is **correct** — only the line number drifted with the orchestrator split.
**Impact:** Trivial citation drift; the behavioural claim is right.
**Compensating control:** The corrective note's substance (call is in `process()`, not the loop) is accurate.
**Recommendation:** Update the parenthetical to `orchestrator.php:267` (or drop the exact line and keep "in `orchestrator::process()`").

### [C5-F09] ⚪ INFO · D6 Docs coverage · docs/architecture/README.md §10 (directory tree)
**What:** Non-PHP cruft lives under `classes/local/wizard/` that the README's PHP-only tree does not reflect.
**Evidence:** `classes/local/wizard/wunderbyte_shop_endpoint.py`, `wunderbyte_trial_endpoint.py`, and a `__pycache__/` directory sit inside `classes/`. The README §10 tree presents `classes/` as PHP-only.
**Impact:** None for docs correctness; flagged here only because the README implies a clean PHP tree. The dead-code/cruft disposition is owned by the dead-code sweep (and a standing memory note); not a docs defect per se.
**Compensating control:** n/a.
**Recommendation:** Out of scope for docs; defer to the dead-code sweep. If retained, a one-line note that Python endpoint stubs live alongside would keep the tree honest.

---

## Top blockers

**None.** No BLOCKER or HIGH item gates go-live in the sense of a runtime/security/data-loss
risk — this sweep audits documentation, which is read-only and cannot mis-execute. The two HIGH
findings (C5-F01 stale `bx_agent_` table names; C5-F02 stale orchestrator line/call citations)
are **operational-risk** docs errors: they will actively mislead an on-call engineer debugging a
live incident (querying a nonexistent `m_local_wizard_*` table) or a maintainer tracing the
planner. They should be fixed in the same window as launch but do not block the binary itself.

**Recommended pre-launch doc fixes (in priority order):**
1. C5-F01 — global `local_wizard_` → `bx_agent_` rename in `data-model-and-db.md` + `observability.md`.
2. C5-F02 — re-anchor planner LLM-call citations to `planner_phase_service`; note the orchestrator split.
3. C5-F03 — document the discovery `query_english_normalizer` LLM call; correct "no chat call in discovery".
4. C5-F04 — fix the flowchart-guide Planner log (removed mandatory tier; `wizard.search_skills`).
5. C5-F05 — add the `bx_agent_user_memory` table to the data model.
6. C5-F06/F07/F08 — refresh README §10 counts/tree; add `wizard.scaffold_skill`; fix the `:389`→`:267` citation.
