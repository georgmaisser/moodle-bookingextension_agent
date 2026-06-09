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

- ✏ **`APREVIEW` is stale.** The node `ai_render_command_preview::execute()` names a web
  service that does not exist. Previews are generated in-loop by `preview_passthrough` and
  returned as `previewjson` on `ai_send_message` / `ai_confirm_run`. Matches the
  preview-as-data-contract refactor. *Proposed: remove/relabel `APREVIEW`.* ❓ confirm.
- ✏ **Autoconfirm probe name (`CS12`).** Diagram: `is_confirmation_allowed_for_session`.
  Code calls `is_confirmation_allowed_for_thread` (wrapper that ignores threadid and
  delegates to the session check). Functionally equivalent; label is narrower.
- ✏ **Attachments pipeline missing.** `ai_send_message` has a 4th param `attachments`;
  `ai_upload_attachment` + `attachment_processor` + PDF extraction are absent from the
  diagram. *Candidate addition.*

### Conversation store / runtime (ch. 03–04)

- ✏ **`LOOP_STEP` step-message attribution.** Diagram puts
  `clear_step_messages()` + `add_step_message(next_step_intent)` in the agent loop head.
  Reality: `clear_step_messages()` runs once in `ai_send_message::execute()` *before* the
  loop; `add_step_message()` runs in `orchestrator::process()` (~line 389) once per
  `process()`. *Proposed: move these labels off `LOOP_STEP`.*
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
- ✏ **`FSIG` signal components.** Diagram: `intent_code + trigger_id + context_prior +
  recency`. Code (`family_signal_ranker`): base 0.20 + core 0.10 + namespace_hint 0.35 +
  recency_namespace 0.20. No `intent_code`/`trigger_id` inputs. Same spirit, different
  named components.
- ✏ **`FRANK` scoring formula.** Diagram: additive `semantic + signal + context_prior +
  recency_bias`. Code (`family_ranker`): weighted blend `0.7·signal + 0.3·semantic`
  (or signal alone); context_prior/recency are folded into the signal score.
- ✏ **`EMB_QUERY` ALWAYS_INCLUDE list.** Diagram lists `update_option_trainer + book_users`.
  Code also force-includes `core.search_skills`.

### Decision / preflight / queue / executor (ch. 08–11)

- ✏ **`EXC_GUARD` — `execution_guard::verify(...)`.** *Resolved (investigation).* There is no
  `execution_guard` class. The real call is
  `preflight_execution_gate::verify_guard_token(guardtoken, skillname, contextid,
  prepared_input)` — a `hash_equals` against `sha256(skill:context:prepared_input)`. The
  diagram's signature (class name, `userid` param, missing skillname) is wrong. *Proposed:
  relabel `EXC_GUARD`.*
- ✏ **`PRV2.execution_guard_token`.** The diagram lists `execution_guard_token` as a field of
  `preflight_result_v2`. It is not on that DTO; the token is built from prepared_input and
  persisted on the **queue item**. *Proposed: move the field to the queue node.*
- ✓ **Confirmed:** decision guard chain order (preview → pending → lookup → promotion);
  risk-routed command handling; preflight risk→layer gating; L3 constants 500/200/4/4000;
  retryable categories TECHNICAL + EXTERNAL_DEPENDENCY; queue blocked TTLs R1=900/R2=300/R3=900;
  atomic single running item; idempotency split (queue signature / executor already-executed);
  R3 no retry; retry-layer cap of 2.
- ✏ **Code-name nits:** `BLOCKED_CONFIRMATION_TIMEOUT` (diagram `BLOCKED_TIMEOUT`); soft-block
  `DUPLICATE_TITLE_*` / `DOMAIN_CONFLICT` (diagram `PROVIDER_CONFIRMABLE_*`); unknown skill
  defaults to R3 (unsafe default — not shown). *Annotations.*
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
- ✏ **`EXC_EVAL` deny reasons.** Code has a sixth reason `skill_version_unsupported` beyond
  the five shown. *Candidate addition.*
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
