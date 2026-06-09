# 05 · Planner orchestrator

> **Scope.** `orchestrator::process()` and the strict phase pipeline that turns a user
> message + observations into a normalized planner result. Flowchart subgraph: `ORCH` (the
> coordination half; [discovery](06-discovery-families-embeddings.md) and
> [selection/construction](07-selection-and-construction.md) have their own chapters).

The orchestrator is where the engine talks to the model — but never in one shot. It runs a
**deterministic, ordered pipeline**: discovery (no LLM chat), then a selector call, then a
constructor call. The split exists so the model never conflates *which skill* with *what
arguments*, and so each decision is independently validated.

**Files:** `classes/local/wbagent/orchestrator.php`, `interpreter.php`,
`services/phase_prompt_bundle_builder.php`, `services/orchestrator_routing_service.php`,
`services/orchestrator_prompt_profile_service.php`, `services/planner_result_composer.php`,
`services/llm/llm_call_service.php`, `prompts/*`.

---

## Table of contents

1. [The three phases](#1-the-three-phases)
2. [Exactly two planner LLM calls](#2-exactly-two-planner-llm-calls)
3. [Phase prompts & profiles](#3-phase-prompts--profiles)
4. [Routing per phase](#4-routing-per-phase)
5. [Interpretation & the command envelope](#5-interpretation--the-command-envelope)
6. [Result composition](#6-result-composition)
7. [The language contract](#7-the-language-contract)
8. [The synchronizer reuse](#8-the-synchronizer-reuse)
9. [Flowchart notes](#9-flowchart-notes)

---

## 1. The three phases

`process(threadid, cmid, userid, observations = [], ?agent_state $state = null)` runs:

1. **Discovery** (`run_discovery_phase`) — deterministic + embeddings. Builds the runtime
   skill catalog, ranks families, and (when available) retrieves semantic candidates. **No
   planner chat call.** It also emits the per-turn progress step message
   (`store->add_step_message`) using the selector's `next_step_intent`. Covered in
   [ch. 06](06-discovery-families-embeddings.md).
2. **Selection** (`run_selection_phase`) — **planner LLM call 1** (the *selector*). Picks
   exactly one concrete skill from the discovered, budgeted family candidates and declares
   `planned_steps[]` + `next_step_intent`. Covered in
   [ch. 07](07-selection-and-construction.md).
3. **Parameter construction** (`run_construction_phase`) — **planner LLM call 2** (the
   *constructor*), run **only** when selection returned `response_type = skill_call`. Builds
   the selected skill's parameters against its full schema. Covered in
   [ch. 07](07-selection-and-construction.md).

When selection yields a non-`skill_call` outcome (clarification / sufficient / error),
construction is skipped and the turn ends after one planner call.

---

## 2. Exactly two planner LLM calls

This is the `LG_PLAN` design invariant, and it holds literally in the code. The only
planner *chat* calls (`llm_call_service::invoke(...)`) in `process()` are:

| Phase | Call site | Source / action |
|-------|-----------|-----------------|
| Selection | `orchestrator.php:1057` | `planner_selection` / selector pick-skill |
| Construction | `orchestrator.php:1292` | `planner_construction` / constructor build-params |

Discovery issues **no** chat call — only `invoke_embeddings()` at `orchestrator.php:687`
(a vector call, not a planner decision). The third `invoke()` in the file, at
`orchestrator.php:489`, belongs to **`process_synchronizer()`** — a separate pass, not part
of the planner pipeline (see [§8](#8-the-synchronizer-reuse) and [ch. 12](12-synchronizer.md)).

So: **two** planner chat calls for a `skill_call` turn, **one** for a clarification/sufficient
turn, plus an optional embeddings call in discovery.

---

## 3. Phase prompts & profiles

`phase_prompt_bundle_builder` assembles a **separate** system prompt per phase via
`orchestrator_prompt_profile_service` (which exposes phase constants `PHASE_DISCOVERY`,
`PHASE_SELECTION`, `PHASE_PARAMETER_CONSTRUCTION` and per-phase history limits):

- **Selector prompt** — "pick exactly one skill, emit no parameters, declare
  `planned_steps`"; includes the budgeted family-scoped skill catalog.
- **Constructor prompt** — a strict constructor-only contract: "the skill is already
  chosen, build its parameters, no skill discovery"; restricted to the selected skill's
  schema.

The assembled prompt interleaves `[SYSTEM]`, an optional `[SYSTEM_RUNTIME]` block,
trimmed message history, `[PLANNER_TRACE n]` + `[OBSERVATION n]` blocks, a
`[PENDING PLANNED STEPS]` block (selection only), and a phase-local `[OUTPUT_CONTRACT]`
reminder. Admin-configured prompt overrides (`aiinitialprompt_*` settings) take precedence
over the built-in templates.

---

## 4. Routing per phase

`orchestrator_routing_service::resolve_action_class_for_phase(manager, context, phase)`
resolves which AI **action class** runs each phase, with an independent fallback chain:

```
WB_ACTION_PLANNER_DECIDE   (aiprovider_wunderbyte planner action)
  → summarise_text         (if available in context)
  → generate_text          (always-available fallback)
```

Each resolution records a `routepolicy` like `sel_wunderbyte` / `cons_default` and a
`routingfallback` flag, which feed the debug source string and the
[routing decision log](../operations/observability.md). The construction phase resolves the
same way, independently — a provider can be available for one phase and fall back on
another.

---

## 5. Interpretation & the command envelope

Each raw model response goes through `interpreter::interpret_phase_output(raw, phase,
context)`:

1. **Parse** — strips markdown fences and a UTF-8 BOM, then JSON-decodes. A parse failure
   emits `CONTRACT_PARSE_ERROR`.
2. **Classify** — `response_type` must be one of `skill_call`, `clarification`,
   `confirm_pending`, `sufficient`, `error` (else normalized / `CONTRACT_UNKNOWN_RESPONSE_TYPE`).
3. **Phase contracts** — discovery may not emit commands or a `skill_call`
   (`CONTRACT_PHASE_*`); selection must emit **exactly one** command
   (`CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED`) whose `skill` is present
   (`CONTRACT_SELECTION_SKILL_MISSING`) and matches the selected skill
   (`CONTRACT_SELECTION_SKILL_MISMATCH`); construction must emit commands for a `skill_call`
   (`CONTRACT_COMMANDS_REQUIRED`) and stay within the allowed skill
   (`CONTRACT_PHASE_SKILL_NOT_ALLOWED`).
4. **Command envelope normalization** — only `skill`, `version`, and `input | parameters`
   are read from each command; **unknown top-level keys are ignored**. `parameters` and
   `input` merge into a canonical `input`; `version` defaults to `1`. Empty input values are
   pruned, but `0` and `false` are preserved.

The two codes `CONTRACT_PARSE_ERROR` and `CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED` are
exactly the ones the [runtime loop retries once](04-agent-runtime-and-loop.md#4-framework-retry-hints).

---

## 6. Result composition

`planner_result_composer::compose(discoverystate, selectionstate, constructionstate)`
produces the unified planner result the runtime consumes. It:

- returns the construction state as the base result, plus a nested `planner_result`
  (selection + parameter_construction + `phase_trace` + `planner_trace_history`);
- builds a `phase_trace` snapshot per phase (response_type, message, selected_skill,
  `catalogselectionmode`, `embeddingstatus`, issue_codes, errors);
- carries `planned_steps` up from selection when present.

The orchestrator persists the trace via the store (`set_phase_trace`,
`set_planner_trace_history`) and writes `next_step_intent` to thread metadata; the
`message_trigger_registry` normalizes `used_triggers`. These persisted artifacts are what
the next discovery turn and the synchronizer read back.

---

## 7. The language contract

Verified: there are **no de/en token lists** anywhere in routing. The planner detects the
reply language from the conversation (the latest user message), returns a `lang`/`user_lang`
field, and the interpreter coerces it to a 2-char code. The embedding query is built from
the **latest user message** (plus `next_step_intent` — see
[ch. 06](06-discovery-families-embeddings.md)), never from keyword lists. This is the
`OR_LANG` contract and the `LG_LANG` legend: *reply language follows the latest user input,
with no language-specific routing heuristics.*

---

## 8. The synchronizer reuse

The orchestrator also hosts `process_synchronizer(threadid, cmid, userid, observations)` —
the final reply pass. It does **not** run the discovery/selection/construction pipeline:

- it uses `synchronizer_prompt_builder` (not `phase_prompt_bundle_builder`);
- it resolves its own action class (`WB_ACTION_GENERATE_AGENT_REPLY` → `generate_text`);
- it makes a **single** LLM call (`orchestrator.php:489`) and returns the interpreted
  result directly, with no composition.

It is invoked by `agent_runtime` only for `llm_polish` finalization states. Full detail in
[ch. 12 · Synchronizer](12-synchronizer.md).

---

## 9. Flowchart notes

> **✓ Two-planner-call invariant confirmed** — see [§2](#2-exactly-two-planner-llm-calls).
> The diagram's `SPLLM` (selection), `CPLLM` (construction), and `SLLM` (synchronizer) map
> exactly to `orchestrator.php:1057`, `:1292`, and `:489`. Worth noting in the diagram that
> construction (`CPLLM`) is **conditional** on a `skill_call` selection.

> **✓ `OR_LANG` confirmed** — no token lists; language follows the latest user message.

Discovery-phase discrepancies (signal components, ranker formula) are documented in
[ch. 06 §9](06-discovery-families-embeddings.md#9-flowchart-notes).
