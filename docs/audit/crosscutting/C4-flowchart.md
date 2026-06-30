# Cross-cutting Audit C4 — Flowchart Compliance (horizontal)

**Scope:** the entire `bookingextension_agent` engine, verified node-by-node against
`docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd` (all 14 subgraphs:
ENTRY, AUTHZ, CSTORE, RUNTIME, ORCH, MV, USERMEM, SYNC, DECIDSVC, PREFLIGHT, QUEUE, EXEC,
SKILLS, SUPPORT, OUTCOMES, LEGEND) and the discrepancy log
`docs/reference/flowchart-guide.md`.
**Files spot-checked:** ~28 · **Methods/nodes verified:** ~55
**Arch chapter(s):** all (`docs/architecture/01..16`) via the subgraph→chapter map · **Flowchart nodes:** ALL
**Auditor verdict:** ✅ clean (no blockers; deviations are DOC-LAG only)

> Per `feedback_flowchart_policy` this is a **report only**. No `.mmd` and no guide edit was
> made. The flowchart is the authoritative design reference; every deviation below is left
> for the maintainer to reconcile.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | n/a   | Covered by the security cross-cut; here only as it bears on a node's drawn behaviour. Spot-confirmed: sesskey on all state-changing WS, Gate-1 name-derived cap (skill_registry.php:191-198), Gate-2 at operating context (preflight_pipeline.php:207), guard-token `hash_equals` — all match their nodes. |
| D2 Moodle API      | n/a   | Not the focus of this sweep. |
| D3 Structure       | n/a   | Two cruft items noted under findings (`obsolet/`, `__pycache__`) but those belong to the structure cross-cut. |
| D4 Duplication     | n/a   | Not the focus of this sweep. |
| D5 Flowchart       | **issues** | Behaviour matches the diagram across all 14 subgraphs. The prior behavioural findings are RESOLVED in code (queue R2 TTL=300, session TTL=900). Residual deviations are **DOC-LAG only**: stale STATUS labels on MV/QNORM, an undrawn WS inventory in ENTRY, and two stale entries inside the discrepancy log itself. No BEHAVIOURAL contradiction found. |
| D6 Docs coverage   | **issues** | The `.mmd` is accurate. The discrepancy log `flowchart-guide.md` has gone stale in two places (EMB_QUERY mandatory tier; see C4-F05) and omits the L2 shared-timeout retry already raised in section 08. |

---

## B. Findings

### [C4-F01] ⚪ INFO · D5 Flowchart · queue_manager.php:844-856 + conversation_store.php:47
**What:** The three prior-audit behavioural TTL findings are RESOLVED in current code and the diagram is now correct.
**Evidence:**
- `resolve_blocked_ttl_seconds()` returns **300** for `skill_risk_class::R2` (line 847) and **900** (`DEFAULT_BLOCKED_TTL_SECONDS`) for R1/R3 (line 851) — exactly the `Q_BLOCKED` node (`R1=900 / R2=300 / R3=900`).
- `CONFIRMATION_SESSION_ALLOWLIST_TTL = 900` (`conversation_store.php:47`), matching `D_PROMOTE` ("R1 → session-allow ok (TTL 900s)") and `LG_RISK_CONF` ("R1: 900s TTL"). The earlier MEMORY note "R2-blocked-TTL 900≠300" no longer holds: the queue-block TTL (300 for R2) and the session-allow TTL (900, only ever consulted for R1) are two distinct constants, both correct.
**Impact:** None — the "R2 blocked-TTL 900 vs documented 300" deviation is closed.
**Compensating control:** n/a.
**Recommendation:** None. (The discrepancy log line 144 already records this as confirmed; keep it.)

### [C4-F02] 🟢 LOW · D5 Flowchart (doc-lag) · ENTRY subgraph vs `classes/external/`
**What:** The `ENTRY` subgraph draws 6 web-service nodes (`ASM`, `ASM_UPLOAD`, `ACR`, `ACD`, `APO`, `APREVIEW`); the plugin ships **14** external classes.
**Evidence:** `classes/external/` also contains `activate_trial_context`, `request_trial_key`, `configure_provider_from_existing`, `store_provider_apikey`, `set_debug_mode`, `ai_privacy_precheck`, `ai_get_doc_content`, `ai_get_thread_debug_logs` — none drawn. All 12 state-changing/loop WS correctly call `require_sesskey()` and `check_use_readiness()` first (grep over `classes/external/` confirms both on every loop entry point), so the ENTRY contract ("0. require_sesskey + validate_parameters", "1. readiness gate") holds for everything drawn AND undrawn.
**Impact:** The diagram is the *agent-loop* architecture, not a complete WS inventory; provider-setup/trial/debug entry points are intentionally outside it. A reader could mistake the 6 nodes for the full surface.
**Compensating control:** Those extra WS are setup/trial/debug, not part of the planner→preflight→execute loop the diagram models; their readiness+sesskey gating is verified present.
**Recommendation:** Optionally add a one-line note to the `ENTRY` subgraph that provider-setup / trial / debug WS exist but are out of the loop scope. No behavioural action.

### [C4-F03] 🟢 LOW · D5 Flowchart (doc-lag) · MV subgraph + QNORM node STATUS labels
**What:** Several `MV_*` nodes and `QNORM` carry STATUS labels that read as "not yet wired / gated on Georg's go" although the code path is now fully wired.
**Evidence:**
- `QNORM` says "STATUS: DECIDED 2026-06-27 … wiring into the discovery / search_skills / debug embedding calls." The wiring is **complete**: `query_english_normalizer::to_english()` is called in `discovery_phase_service.php:292-293`, `skill_discovery_service.php:84-85`, and the debug service.
- `MV_RETRIEVE`/`MV_SUBSET` "STATUS: IMPLEMENTED … the LIVE index becomes multi-vector only after the next embeddings rebuild (gated on Georg's go)." Code-wise this is accurate but easy to misread: `embeddings_retrieval_service::search_top_k_skills()` is **live-called** (`discovery_phase_service.php:307`, `skill_discovery_service.php:100`, `skill_selection_debug_service.php:410`); the only thing gated is the *data* (the rebuilt anchor-row index), guarded by the `$status['ready'] && $status['rows']` check at `discovery_phase_service.php:289`. CSV `HEADERS` (`embeddings_csv_repository.php:50`) already carry the slim per-anchor schema (`skill, anchor_index, anchor_kind, …, content_hash, embedding_json`) that `MV_STORE` describes.
**Impact:** Doc-lag: the labels describe a partially-built feature that has since been finished in code. No behavioural contradiction — the path activates automatically once the rebuilt index exists.
**Compensating control:** The gating is purely the index rebuild, exactly as the node's last line states.
**Recommendation:** Refresh the STATUS lines on `QNORM` (wiring done) and clarify on `MV_RETRIEVE` that the code path is live and only the index data is pending a rebuild.

### [C4-F04] ⚪ INFO · D5 Flowchart · preflight_pipeline.php:235-251 (PF_L3_EXT) — R3 external gate is wired but a no-op by default
**What:** The prior-audit "R3 external gate no-op" observation is still accurate, and the diagram is correct as a *contract*.
**Evidence:** The R3 branch in `preflight_pipeline::run()` (lines 235-251) does invoke `$this->externaldependencychecker->check($command, $contextid, $userid)`, merges its issue codes, and on `status !== 'pass'` returns the external result (a hard_block) — matching `PF_L3_EXT`. But the only shipped implementation is `noop_external_dependency_checker`, injected by default at the constructor (`preflight_pipeline.php:79`), whose `check()` always returns `ok($input)`. So the "webhook reachable? / payment provider ready?" checks the node lists never fire unless a provider injects a real checker.
**Impact:** None today — no skill ships an R3 external dependency, and the gate is structurally present (fail-closed once a real checker is wired). The node correctly documents the *contract*, not a guaranteed runtime check.
**Compensating control:** Interface + injection point exist; a real checker drops in without engine change.
**Recommendation:** None required. Optionally annotate `PF_L3_EXT` that the default checker is a no-op until a provider supplies one (mirrors section-08 finding 08-F04).

### [C4-F05] 🟢 LOW · D6 Docs coverage (doc-lag) · flowchart-guide.md:115-121 (EMB_QUERY mandatory tier)
**What:** The discrepancy log's own ch.05 entry describing the "mandatory tier" is now stale; the `.mmd` `EMB_QUERY`/`DISCO_RULE` nodes are correct.
**Evidence:** `flowchart-guide.md:115-121` ("EMB_QUERY mandatory tier — flowchart updated 2026-06-10 … the mandatory tier is now `adaptive_skill_catalog_service::get_mandatory_skills()` … unions the per-skill `always_available` flag … with `MANDATORY_SKILL_KEYWORDS`"). The code has since removed all of that: `adaptive_skill_catalog_service.php:21-22` states "The former mandatory/recency tiering (MANDATORY_SKILL_KEYWORDS, get_mandatory_skills, always_available flag, recency Top-K) has been removed". Grep confirms no live `MANDATORY_SKILL_KEYWORDS`/`get_mandatory_skills`/`ensure_trigger_mandatory_skills` callers remain. The current `.mmd` `EMB_QUERY` node already documents this removal correctly ("REMOVED (lexical, NO-GO per DISCO_RULE) … are GONE"), and the sole sanctioned always-include is `discovery_phase_service::ensure_search_skills_fallback` (confirmed at `discovery_phase_service.php:591`).
**Impact:** A maintainer reading the *guide* (not the diagram) would believe a removed mechanism is still present. The diagram itself is right.
**Compensating control:** The `.mmd` is authoritative and is correct; only the prose log lags.
**Recommendation:** Mark the `flowchart-guide.md:115-121` entry superseded ("mandatory tier subsequently removed; see `EMB_QUERY`/`DISCO_RULE`").

### [C4-F06] 🟢 LOW · D3 Structure · `obsolet/` directory + `classes/local/wizard/__pycache__/`
**What:** Two cruft artefacts not modelled in the flowchart (and that should not exist in a shipped PHP plugin).
**Evidence:** `obsolet/` exists at the plugin root (per template §5 it is noted, not audited). `classes/local/wizard/__pycache__/` is a Python bytecode directory inside the PHP `classes/` tree (matches the prior `project_agent_deadcode_flowchart_audit` finding about Python cruft under `classes/`).
**Impact:** No runtime effect (neither is autoloaded by Moodle), but both pollute the tree and `__pycache__` under `classes/` can confuse PSR-4 tooling and packaging.
**Compensating control:** Moodle's classloader ignores non-`.php` and non-namespaced dirs.
**Recommendation:** Remove `__pycache__` from `classes/`; resolve `obsolet/` (delete or move out of the plugin) before go-live packaging. (Structure cross-cut owns the fix.)

### [C4-F07] ⚪ INFO · D5 Flowchart · DECIDSVC / EXEC / SYNC / SKILLS / RUNTIME nodes spot-confirmed
**What:** The load-bearing deterministic nodes outside the items above were spot-checked and match code.
**Evidence:**
- `D_TARGET_NOTE` `build_operating_context_note` + `describe_target_context` → `agent_decision_service.php`.
- `ABANDON_GUARD` `reclassify_abandoned` + `RUN_ABANDONED_ALL_STEPS_FAILED` → `agent_runtime.php`.
- `SCONTRACT` ERROR-FAITHFULNESS (`source_conflict_reason`, `error_presentation_requested`) → `synchronizer_output_contract.php`.
- `EXC_GUARD` `verify_guard_token(...)` + `build_guard_token(skillname, contextid, preparedinput)` → `preflight_execution_gate.php:136/151`.
- `EXC_EVAL` Gate-1 name-derived cap denied when `get_capability_info()===null` → `skill_registry.php:191-198`.
- `LG_OPCTX` generic module target: `module_target_resolver`, `operating_context_target_registry`, `module_targeted_skill` trait, `context_resolver`, `target_selector` DTO all present under `classes/local/wizard/services/security/`.
- `CONF_TERM`/`CONF_FOLLOW`/`Q_PLANNED`: `has_planned_placeholders`, `consume_next_placeholder`, `fail_expired_blocked_items` → `confirm_run_service.php` / `agent_decision_service.php` / `queue_manager.php`.
- `SINT`/`CINT`: `interpreter::interpret_phase_output` → `interpreter.php`.
**Impact:** None — confirmed-correct.
**Recommendation:** None.

---

## C. Per-subgraph verification ledger

`[x]` = drawn behaviour matches code; `[~]` = matches with a doc-lag note; `[ ]` = behavioural contradiction (none found).

- [x] **ENTRY** — sesskey + readiness on all loop WS; attachments/preview nodes accurate. Doc-lag: 8 undrawn setup/trial/debug WS (C4-F02).
- [x] **AUTHZ** — `check_use_readiness` graceful (never throws) on every WS; AZ4 availability bypass via `agent:ignoreaiavailability`.
- [x] **CSTORE** — TTL constants confirmed (C4-F01); CS15 `next_step_intent`/`phase_trace_loop_history` present.
- [x] **RUNTIME** — `run_loop` MAX_LOOP_STEPS=6; ABANDON_GUARD present (C4-F07); FCLASS classifier matrix matches `LG_MATRIX`.
- [x] **ORCH** — two planner LLM calls (selection/construction); DISCO_RULE semantic-only enforced; mandatory tier removed in code (C4-F05 = guide doc-lag, diagram correct).
- [~] **MV** — multi-vector retrieval live-wired (`search_top_k_skills`), slim CSV schema present; STATUS labels stale (C4-F03).
- [x] **USERMEM** — `user_memory_service` + per-channel `get_for_scope` injection; privacy provider @ CONTEXT_USER.
- [x] **SYNC** — ERROR-FAITHFULNESS guard + rollback-on-drift present (C4-F07).
- [x] **DECIDSVC** — guard chain order (preview→pending→lookup→promote); risk-routed handling; `D_TARGET_NOTE` always appended (C4-F07).
- [~] **PREFLIGHT** — risk→layer gating, Gate-2 at operating ctx, guard token correct. PF_L3_EXT wired but no-op by default (C4-F04); L2 shared-timeout undocumented in ch.09 (section-08 08-F02).
- [x] **QUEUE** — blocked TTLs R1=900/R2=300/R3=900 (C4-F01); atomic single running item; idempotency split; planned placeholders.
- [x] **EXEC** — idempotency skip, Gate-1 eval, guard-token verify, Gate-2 backstop, operating-context execute.
- [x] **SKILLS** — registry/contract validator/risk-class declaration; name-derived Gate-1 cap (C4-F07).
- [x] **SUPPORT** — anonymizer (fail-closed deanon), language_policy_service, message_trigger_registry (returns []), issue_code_provider.
- [x] **OUTCOMES** — observation builder + summarizer; terminal-state colouring matches.
- [x] **LEGEND** — `LG_OPCTX` (generic module target), `LG_RISK_CONF` TTLs, `LG_MATRIX`, `LG_AVAIL` all match code.

---

## D. Top blockers

**None.** No BEHAVIOURAL deviation between the `.mmd` and the running code was found in any of
the 14 subgraphs. The three prior-audit behavioural items are resolved or correctly
characterised:

1. **R2 blocked-TTL 900 vs 300** → RESOLVED in code (queue returns 300 for R2; `.mmd` is correct) — C4-F01.
2. **R3 external gate no-op** → still a no-op by *default implementation*, but the gate is wired and the diagram documents the contract correctly — C4-F04 (INFO).
3. **Timeout-code split** → already raised as a structure/docs item in section 08 (08-F01/08-F02); not a flowchart contradiction.

All residual flowchart findings are **DOC-LAG (LOW/INFO)**: undrawn setup WS (C4-F02),
stale MV/QNORM STATUS labels (C4-F03), a superseded discrepancy-log entry (C4-F05), and
tree cruft (C4-F06). None gates go-live.
