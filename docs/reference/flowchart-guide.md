# Reference · Flowchart guide & discrepancy log

> **Scope.** How to read the canonical design diagram
> [`AGENT_IMPLEMENTATION_FLOWCHART.mmd`](../Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd),
> and the living log of where the diagram and the running code differ.

The flowchart is the **authoritative design reference**. Per project policy, code↔diagram
discrepancies are not silently resolved: clear diagram omissions/errors are corrected (with
maintainer approval), and anything ambiguous is raised as a question before changing either
side.

## Reading the diagram

The diagram is a Mermaid `flowchart TD` grouped into **subgraphs**, one per subsystem:
`ENTRY`, `AUTHZ`, `CSTORE`, `RUNTIME`, `ORCH`, `SYNC`, `DECIDSVC`, `PREFLIGHT`, `QUEUE`,
`EXEC`, `SKILLS`, `SUPPORT`, `OUTCOMES`, and `LEGEND`. Each subgraph maps to a chapter (the
table below).

- **Node ids** are short upper-case tokens (`ASM` = ai_send_message, `RUNLOOP`, `PP_RUN`,
  `Q_BLOCKED`, `EXC_GUARD`, …); the label inside `["…"]` is the human description, with `\n`
  for line breaks.
- **Solid arrows** are the normal flow; **dotted arrows** (`-.->`) are secondary/telemetry
  links.
- **Colours** (from the `style` block): green = success/terminal, red = failure/terminal,
  yellow = hold/manual gate, blue = LLM/retry, dark teal/navy = deterministic gate or
  contract.
- The **`LEGEND` subgraph** is not flow — it is a set of design-contract notes
  (`LG_PLAN`, `LG_SYNC`, `LG_RISK*`, `LG_MATRIX`, …) that the chapters reference by name.

## Subgraph → chapter map

| Subgraph | Chapter |
|----------|---------|
| `ENTRY` | [01](../architecture/01-entry-and-web-services.md) |
| `AUTHZ` | [02](../architecture/02-authorization-and-context.md) |
| `CSTORE` | [03](../architecture/03-conversation-store.md) |
| `RUNTIME` | [04](../architecture/04-agent-runtime-and-loop.md) + [13](../architecture/13-finalization-and-observations.md) |
| `ORCH` | [05](../architecture/05-planner-orchestrator.md)–[07](../architecture/07-selection-and-construction.md) |
| `DECIDSVC` | [08](../architecture/08-decision-service.md) |
| `PREFLIGHT` | [09](../architecture/09-preflight-pipeline.md) |
| `QUEUE` | [10](../architecture/10-shadow-queue.md) |
| `EXEC` | [11](../architecture/11-executor.md) |
| `SYNC` | [12](../architecture/12-synchronizer.md) |
| `SKILLS` | [14](../architecture/14-skill-layer.md) |
| `SUPPORT` | [16](../architecture/16-support-services.md) |
| `OUTCOMES` | [13](../architecture/13-finalization-and-observations.md) |
| `LEGEND` (`LG_RISK*`) | [15](../architecture/15-risk-classes.md) |

## Discrepancy log

Each entry: *flowchart node* · *what the diagram says* · *what the code does* · resolution.

Legend: ❓ open question for maintainer · ✏ flowchart change proposed (not yet applied) ·
✓ matches on closer read · ✅ resolved by a code change in this pass.

### Entry layer (ch. 01)

- ✅ **`APREVIEW` — corrected.** The node `ai_render_command_preview::execute()` named a web
  service that does not exist. Relabelled: previews are generated in-loop by
  `preview_passthrough` and returned as `previewjson` on `ai_send_message` / `ai_confirm_run`
  (preview-as-data-contract refactor).
- ✅ **Autoconfirm probe name (`CS12`) — annotated (B5).** Not really a discrepancy: `CS12`
  names the real session-check method; the entry caller just uses the
  `is_confirmation_allowed_for_thread` wrapper (ignores threadid, delegates here). The node
  now carries that annotation. No behavioural change.
- ✅ **Attachments pipeline — added to `ENTRY` (B4).** Nodes `ASM_UPLOAD`
  (`ai_upload_attachment` → token) and `ASM_ATTACH` (`attachment_processor::augment_message`,
  PDF→text via pdftotext ▸ smalot/pdfparser fallback, 15k cap, no OCR) with edges into the
  ASM flow; `ASM` node now lists the `attachments[]` param. Pure omission, no behavioural
  change.

### Conversation store / runtime (ch. 03–04)

- ✅ **`LOOP_STEP` step-message attribution — corrected.** The node previously put
  `clear_step_messages()` + `add_step_message(next_step_intent)` in the agent loop head.
  Reality: `clear_step_messages()` runs once in `ai_send_message::execute()` *before* the
  loop; `add_step_message()` runs in `orchestrator::process()` (~line 389) once per
  `process()`. The `LOOP_STEP` node now states this.
- ✅ **Session-allow TTL — resolved by a code change (maintainer decision).**
  `CONFIRMATION_SESSION_ALLOWLIST_TTL` reduced 43200 s → **900 s**, so the session allowance
  now matches `LG_AUTO`/`LG_RISK_CONF` (900 s) and the queue/pending TTLs. The
  "confirm for this session" button label is now rendered dynamically from the constant
  (`aiready` → `session_confirm_minutes`, string `ai_btn_confirm_session`).
- ✅ **`phase_trace_loop_history` — added to `CS15`.** `agent_runtime` writes this telemetry
  key (capped at `MAX_LOOP_STEPS`) in addition to the store's canonical `phase_trace` /
  `planner_trace_history`; the `CS15` node now documents it.

### Planner (ch. 05–07)

- ✓ **Two planner LLM calls — confirmed.** Selection `orchestrator.php:1057`, construction
  `:1292`; discovery uses only `invoke_embeddings` (`:687`); synchronizer `:489` is a
  separate pass. *Suggest annotating that construction (`CPLLM`) is conditional on a
  `skill_call` selection — a clarification turn makes only one planner call.*
- ✓ **`OR_LANG` — confirmed.** No de/en token lists; language follows the latest user
  message.
- ✅ **`CINT` command-envelope unwrap — flowchart updated (2026-06-10).** Added
  `unwrap_redundant_input_envelope()` to the node: the constructor LLM non-deterministically
  wraps params in a redundant `input`/`parameters` key (e.g. `parameters:{input:{…}}`); the
  interpreter now collapses one such level so the skill receives flat fields. Without it a
  skill sees `$input['input']` and falsely reports missing input (diagnosed via thread 226 →
  `GENERATE_QUESTIONS_NO_SOURCE`; thread 227 with the flat shape worked, proving the wrap is
  LLM-random). Tracks a code fix.
- ✅ **`FSIG` signal components — flowchart corrected (B1).** Node relabelled to the real
  signals: base 0.20 + core 0.10 + namespace_hint 0.35 + recency_namespace 0.20. The
  diagram's `intent_code`/`trigger_id` don't exist in `family_signal_ranker`: intent is
  carried by the semantic path; trigger_id was dropped (consistent with "no trigger→skill
  routing"). Not a bug — the diagram was ahead of a simplified implementation.
- ✅ **`FRANK` scoring formula — flowchart corrected (B2).** Node relabelled to the real
  weighted blend `0.7·signal + 0.3·semantic` (or signal alone; clamped [0,1]);
  context_prior/recency are folded into the signal score, not additive terms. The 70/30
  weighting is a deliberate knob (semantic caps at 30%). Not a bug — diagram imprecision.
- ✅ **`EMB_QUERY` ALWAYS_INCLUDE list — flowchart corrected (B3).** Added `core.search_skills`
  to the node (the always-reachable RAG fallback). Pure omission; the always-include behaviour
  is by design.
- ✅ **`EMB_QUERY` mandatory tier — flowchart updated (2026-06-10, engine boundary cleanup).**
  The node previously named the hardcoded `ALWAYS_INCLUDE_SKILL_NAMES` constant. That constant
  was removed: the mandatory tier is now `adaptive_skill_catalog_service::get_mandatory_skills()`,
  which unions the per-skill `always_available` governance flag (domain skills declare it in
  their schema — no `mod_booking.*` names in the engine) with the engine-level
  `MANDATORY_SKILL_KEYWORDS` (keeps `core.search_skills` reachable). Tracks an approved code
  change, not a diagram imprecision.

### Decision / preflight / queue / executor (ch. 08–11)

- ✅ **`EXC_GUARD` — corrected.** There is no `execution_guard` class. The node now names the
  real call `preflight_execution_gate::verify_guard_token(guardtoken, skillname, contextid,
  prepared_input)` — a `hash_equals` against `sha256(skill:context:prepared_input)` (the old
  label's class name, `userid` param, and missing skillname were wrong).
- ✅ **`PRV2.execution_guard_token` — corrected.** The node no longer lists
  `execution_guard_token` as a `preflight_result_v2` field; it notes the token is built from
  prepared_input and persisted on the **queue item**.
- ✓ **Confirmed:** decision guard chain order (preview → pending → lookup → promotion);
  risk-routed command handling; preflight risk→layer gating; L3 constants 500/200/4/4000;
  retryable categories TECHNICAL + EXTERNAL_DEPENDENCY; queue blocked TTLs R1=900/R2=300/R3=900;
  atomic single running item; idempotency split (queue signature / executor already-executed);
  R3 no retry; retry-layer cap of 2.
- ✅ **`Q_FAIL_TTL` code name — corrected (B6).** Node now uses the real issue code
  `BLOCKED_CONFIRMATION_TIMEOUT` (was `BLOCKED_TIMEOUT`).
- ✅ **`EXC_EVAL` sixth deny reason (B7.1).** Added `skill_version_unsupported`.
- ✅ **`D_PROMOTE` unknown-skill default (B7.3).** Annotated: `resolve_command_risk_class()`
  defaults an unknown skill to **R3** (fail-safe).
- ✅ **`PF_L2D` soft-block codes + engine domain leak (B7.2) — code cleanup applied.**
  `preflight_domain_check_runner` no longer hardcodes the booking-specific
  `DUPLICATE_TITLE_*` codes: it now injects `issue_code_provider_interface` (default
  `booking_issue_code_provider`, mirroring `agent_decision_service`) and sources confirmable
  codes from `get_prevalidation_confirmable_issue_codes()`, keeping only the engine-generic
  `DOMAIN_CONFLICT`. Removed a domain leak (one of the "5 leaks", see
  `project_wbagent_local_plugin_extraction`). Behaviour-neutral; covered by
  `preflight_layers_contract_test`. The `PF_L2D` node was updated accordingly.
  **⚠ Needs verification in an environment with PHP:** run `preflight_layers_contract_test`
  + the `duplicate_prevention` benchmark before merging.
- ✅ **Session-allow TTL — resolved** (see the ch. 03 entry above): reduced to 900 s so the
  session allowance aligns with the queue/pending TTLs and `LG_AUTO`.

### Reply contract / skill layer (ch. 12–16)

- ✓ **`LG_MATRIX` finalization matrix — confirmed rule-for-rule.** Strategies
  direct_final / template_only / llm_polish; precedence direct > template > polish. Code adds
  the `CONTRACT_PHASE_*` (direct) and `SYNC_*` (template) code families beyond the diagram's
  named subsets. *Candidate detail.*
- ✓ **`LG_SYNC` synchronizer contract — confirmed.** `commands = []`; refine message/lang
  only; rollback to planner output on drift. Concrete rejection codes
  (`SYNC_FACT_CONFLICT_REJECTED`, `SYNC_RESPONSE_TYPE_ERROR_REJECTED`,
  `SYNC_CONTRACT_ISSUE_REJECTED`, …) are richer than the diagram summary. *Candidate detail.*
- ✓ **`LG_RISK*` — confirmed.** Risk-class declaration validation (R0↔readonly, R2/R3 require
  context_scopes → not activatable on mismatch); R3 irreversibility / R2 affected-scope
  reply requirements; R3 no execution retry.
- ✅ **`EXC_EVAL` deny reasons — corrected (B7.1).** The sixth reason
  `skill_version_unsupported` was added to the node (also listed in ch. 11).
- ✓ **`MTRIG` — confirmed.** Registry triggers and trigger→skill map return `[]`; triggers
  are server-derived; no trigger→skill routing.

### Resolved by a code change in this pass

- ✅ **Four flowchart node labels corrected** (maintainer-approved, applied to the `.mmd`):
  `APREVIEW` (no standalone WS; previewjson passthrough), `LOOP_STEP` (step-message
  attribution moved off the loop head), `EXC_GUARD` (→
  `preflight_execution_gate::verify_guard_token`), `PRV2` (guard_token not on the DTO).
  The remaining ✏ items stay in the log pending review; the ❓ items remain open questions.
- ✅ **Docs corpus self-registration.** Added
  `bookingextension_agent\local\wbagent\docs_provider` (corpus id `bookingextension_agent`)
  so `core.explain_docs` serves this corpus directly — matching the README claim, no admin
  `aidocsroot` needed.

_(This log is the running deliverable of the discrepancy pass; the ❓ items are collected
for the maintainer at the end.)_

### Flowchart updates 2026-06-10 (maintainer-instructed, applied to the `.mmd`)

- ✅ **`AZ2` label** — now lists the accepted context levels
  (MODULE / COURSE / COURSECAT / USER / SYSTEM); USER contexts host the dashboard for the
  global navbar entry point.
- ✅ **`AZ4` (new node, AUTHZ subgraph)** — the availability layer: course/cm
  `enableaitools` toggles, skipped for holders of `agent:ignoreaiavailability`
  (admins implicitly, manager default), enforced centrally in
  `get_runtime_provider_status` and consumed by aiready + entry points.
- ✅ **`LG_AVAIL` (new legend)** — "availability ≠ permission" contract: the bypass never
  touches Gate 1 (use/skill capabilities) or Gate 2 (native capabilities in preflight).
- ✅ **`LG_CTX` extended** — context-level-agnostic scope key (module/course/coursecat/
  user/system).
- ✅ **`CS1` corrected** — `get_or_create_thread(userid, contextid)`; the `bookingid`
  parameter was removed from code in consolidation phase P3 and the node was stale.
- ✅ **`LG_RCTX` (new legend, 2026-06-10)** — rich context awareness: the SYSTEM_RUNTIME
  base lines (booking_name/timezone/minute-granular now_iso) plus the moodle_context YAML
  section injected only in parameter construction + synchronizer (selection stays slim);
  sources are agent_context + get_fast_modinfo, defensively wrapped.
