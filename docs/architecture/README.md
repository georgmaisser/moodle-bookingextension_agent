# Architecture Overview — bookingextension_agent

This is the central reference for how the **wbagent** engine works. Read it top to
bottom once to get the whole loop; then follow the links into each subsystem chapter for
depth.

The design source of truth is the diagram
[`AGENT_IMPLEMENTATION_FLOWCHART.mmd`](../Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd).
Every chapter in this section documents one of its subgraphs. Where the running code and
the diagram disagree, the chapter says so in a **⚠ Flowchart note**.

---

## Table of contents

1. [What the agent is](#1-what-the-agent-is)
2. [Design principles](#2-design-principles)
3. [The layers](#3-the-layers)
4. [Request lifecycle](#4-request-lifecycle)
5. [The agent loop](#5-the-agent-loop)
6. [The planner contract](#6-the-planner-contract)
7. [The safety pipeline (decision → preflight → queue → executor)](#7-the-safety-pipeline)
8. [The reply contract (finalization → synchronizer)](#8-the-reply-contract)
9. [Cross-cutting: risk classes](#9-cross-cutting-risk-classes)
10. [Plugin directory structure](#10-plugin-directory-structure)
11. [Flowchart map](#11-flowchart-map)

---

## 1. What the agent is

`bookingextension_agent` is a Moodle subplugin of `mod_booking`. It is a **conversational
agent**: a user types a message in the booking context (a course module), and the agent
either answers a question (read-only) or performs an action (create/update booking
options, book users, …) — always with an explicit confirmation step for anything that
mutates data.

Internally the agent is **not** a single LLM prompt. It is a deterministic state machine
that makes a small, fixed number of LLM calls per turn and wraps every model decision in
non-LLM guardrails: schema validation, capability checks, a confirmation gate keyed by
risk, an idempotent execution queue, and a separate "polish the reply" step. The model
*decides what to do*; deterministic code *decides whether it is allowed and safe*.

The engine code lives under the `wbagent` namespace
(`classes/local/wbagent/…`). The name "wbagent" (Wunderbyte agent) is used throughout the
code and the design diagram; "the agent" and "the engine" mean the same thing in this
corpus.

---

## 2. Design principles

These principles recur in every chapter. They are the *why* behind the structure.

| Principle | What it means |
|-----------|---------------|
| **Deterministic routing** | After the planner produces JSON, every routing decision (confirm? queue? execute? polish?) is made by testable, non-LLM code — see the [decision service](08-decision-service.md) and [finalization classifier](13-finalization-and-observations.md). |
| **Strict planner split** | The planner never picks a skill and builds its parameters in one step. Discovery → selection → construction are separate phases with separate prompts and exactly two planner LLM calls. See [chapter 5](05-planner-orchestrator.md). |
| **Risk-class gating** | Every skill declares a risk class R0–R3. That single declaration drives confirmation, retry policy, preflight depth, and the reply contract. See [chapter 15](15-risk-classes.md). |
| **Framework-agnostic by contract** | The engine carries no booking-specific heuristics. Domain behavior enters only through interfaces/hooks implemented by a provider. A third-party plugin can add skills without touching the engine. See [skill providers & families](../developer-guides/skill-providers-and-families.md). |
| **Language fidelity** | The reply language follows the latest user message, never a keyword/language-routing heuristic. See [support services](16-support-services.md) and `language_policy_service`. |
| **Install-only DB rollout** | New schema ships via `db/install.xml` only — no `upgrade.php` migrations for the agent's own tables. See [data model & DB](../developer-guides/data-model-and-db.md). |
| **Idempotency everywhere** | The queue dedupes by input signature; the executor skips already-executed runs. A retried turn never double-applies a mutation. See [chapter 10](10-shadow-queue.md) and [chapter 11](11-executor.md). |

---

## 3. The layers

The engine is organized as a stack. A turn flows down for planning and execution, and the
result flows back up for the reply.

```
┌──────────────────────────────────────────────────────────────────┐
│ Entry layer            classes/external/*  (web services)          │  ch. 01
│   ai_send_message · ai_confirm_run · ai_discard_pending            │
│   ai_poll_thread · ai_render_command_preview · ai_upload_attachment│
├──────────────────────────────────────────────────────────────────┤
│ Authorization & store  authorization_service · conversation_store  │  ch. 02–03
├──────────────────────────────────────────────────────────────────┤
│ Runtime                agent_runtime  (the loop coordinator)       │  ch. 04
│   agent_state · attempt_budget · observation accumulation          │
├──────────────────────────────────────────────────────────────────┤
│ Planner                orchestrator  (discovery→selection→constr.) │  ch. 05–07
│   family_registry · embeddings · ranking · skill_selector ·        │
│   parameter_constructor · parameter_contract_validator             │
├──────────────────────────────────────────────────────────────────┤
│ Decision               agent_decision_service                      │  ch. 08
│   preview/pending/lookup guards · risk promotion · routing         │
├──────────────────────────────────────────────────────────────────┤
│ Safety                 preflight_pipeline (L1/L2/L3)               │  ch. 09
│                        queue_manager (shadow queue)                │  ch. 10
│                        executor                                    │  ch. 11
├──────────────────────────────────────────────────────────────────┤
│ Reply                  finalization_classifier → synchronizer     │  ch. 12–13
├──────────────────────────────────────────────────────────────────┤
│ Skills                 skill_registry · skill_interface · *_skill  │  ch. 14
│ Support                anonymizer · language · triggers · errors   │  ch. 16
└──────────────────────────────────────────────────────────────────┘
```

---

## 4. Request lifecycle

A normal turn — the user sends a message that needs one skill — flows like this:

1. **Entry.** The UI calls `ai_send_message::execute(contextid, message, threadid)`. A
   readiness gate checks auth, subsystem, provider, and context; on failure it returns a
   specific error JSON. On success it appends the user message to the thread and starts
   the loop. *(ch. [01](01-entry-and-web-services.md), [02](02-authorization-and-context.md))*
2. **Loop step.** `agent_runtime::run_loop()` runs up to `MAX_LOOP_STEPS = 6` steps. Each
   step calls `run_internal()`, which plans then decides. *(ch. [04](04-agent-runtime-and-loop.md))*
3. **Plan.** The orchestrator runs the strict phase pipeline: **discovery** (which skill
   *families* are relevant), **selection** (exactly one concrete skill), **construction**
   (build that skill's parameters). Two LLM calls — selector and constructor.
   *(ch. [05](05-planner-orchestrator.md)–[07](07-selection-and-construction.md))*
4. **Decide.** `agent_decision_service::process()` applies deterministic guards
   (preview / pending intent / lookup-vs-mutation) and **promotes** a mutating skill call
   to a `confirmation_request` according to its risk class. *(ch. [08](08-decision-service.md))*
5. **Preflight & queue.** For a confirmable mutation, the [preflight pipeline](09-preflight-pipeline.md)
   validates schema (L1), prepares the domain (L2), and evaluates an execution gate (L3).
   The command is placed on the [shadow queue](10-shadow-queue.md) as `ready` (auto-allowed
   for this session) or `blocked_confirmation` (waiting for the user).
6. **Reply.** The turn's result is classified by the [finalization classifier](13-finalization-and-observations.md)
   into one of three reply strategies — *LLM polish* (via the [synchronizer](12-synchronizer.md)),
   *template only*, or *direct final* — and persisted as the assistant message.
7. **Confirmation.** If a confirmation was requested, the user confirms via
   `ai_confirm_run` (or it auto-confirms when a session allowance exists). The confirmed
   run is executed — synchronously or via the `execute_ai_run_adhoc` worker — by the
   [executor](11-executor.md), the only place a skill actually runs.
8. **Observe & continue.** A read-only or executed result becomes an **observation** that
   is fed back into the next loop step, so a multi-step request (e.g. "create an option
   *and* book three users") progresses turn by turn until a terminal state.

The UI keeps the chat live by polling `ai_poll_thread`, which reads per-step progress
messages lock-free.

---

## 5. The agent loop

`agent_runtime::run_loop()` is the coordinator. Key facts (from
`agent_runtime.php`):

- **Budget.** `MAX_LOOP_STEPS = 6`. Before each new LLM-calling step the budget guard
  checks `(step + 1) < limit`; when exhausted it returns a deterministic
  `BUDGET_EXCEEDED` result.
- **Two outcomes per step.** A step returns either an `execution_result` (a read-only or
  executed command produced an observation → record it and continue) or a **terminal
  planner result** (clarification / confirmation_request / sufficient / error → finalize).
- **Framework retry hints.** Two planner *contract* failures —
  `CONTRACT_PARSE_ERROR` and `CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED` — get exactly
  **one** in-loop retry, injected as a `RETRY_HINT` observation, unless a non-planner
  retry signal is already active (collision guard) or an R3 command is involved
  (R3 never auto-retries).
- **Observations** accumulate in `agent_state` and are anonymized before they re-enter a
  prompt.

See [chapter 4](04-agent-runtime-and-loop.md) for the full state machine and the
`attempt_budget` (the global view across loop / preflight / queue / execution retries).

---

## 6. The planner contract

The planner (`orchestrator.php` plus the discovery/selection/construction services) is
deliberately split so the model never conflates "which skill" with "what arguments":

- **Discovery** ranks skill *families* (not concrete skills) using a dual path: semantic
  family-embeddings retrieval when `aiprovider_wunderbyte` + embeddings are ready,
  otherwise a deterministic context+signal derivation. Discovery expands in bounded
  stages A → B → C and never dumps the full skill list to the model.
- **Selection** (LLM call 1, the *selector*) picks exactly one concrete skill inside the
  ranked families and declares `planned_steps[]` and `next_step_intent` for multi-step
  requests.
- **Construction** (LLM call 2, the *constructor*) builds only the selected skill's
  parameters against its full schema.
- **Validation.** `parameter_contract_validator` checks structure, schema, and ambiguity
  and can emit a clarification or a recoverable input error.

Chapters [05](05-planner-orchestrator.md), [06](06-discovery-families-embeddings.md), and
[07](07-selection-and-construction.md) cover this in depth.

---

## 7. The safety pipeline

Everything between "the planner proposed a command" and "the command ran" is deterministic
safety machinery:

- **[Decision service](08-decision-service.md)** — guards (preview / pending / lookup) and
  risk-based promotion to a confirmation.
- **[Preflight pipeline](09-preflight-pipeline.md)** — L1 schema+version, L2 domain
  prepare, L3 execution-gate hint; the active layers depend on the risk class
  (R0 none · R1 L1+L2 · R2 +L3 · R3 +external dependency check). Output is a single
  `preflight_result_v2` DTO.
- **[Shadow queue](10-shadow-queue.md)** — a DB-backed queue with DAG dependency checks,
  input-signature idempotency, `blocked_confirmation` / `planned` placeholders, retry
  backoff, and at most one running item per thread.
- **[Executor](11-executor.md)** — re-verifies the prepared input against an execution
  guard token (no second full preflight), checks the skill is releasable, runs
  `skill::execute()`, and produces success / transient-retry / domain-error outcomes.

---

## 8. The reply contract

The agent separates *deciding what to do* from *writing the reply*:

- The **[finalization classifier](13-finalization-and-observations.md)** maps a normalized
  result to one of three strategies from result metadata alone (no LLM intuition):
  `direct_final`, `template_only`, or `llm_polish`.
- The **[synchronizer](12-synchronizer.md)** runs only on `llm_polish` states. It may
  refine the message, language, and presentation, but must **not** invent commands or
  change execution semantics; on semantic drift its output is discarded and the planner
  output is used (rollback). R3 replies must carry an irreversibility notice, R2 an
  affected-scope summary.

---

## 9. Cross-cutting: risk classes

Risk classes are the spine of the safety model. A skill declares one of:

| Class | Meaning | Confirmation | Preflight | Retry |
|-------|---------|--------------|-----------|-------|
| **R0** | read-only | never | none (staged execute) | allowed |
| **R1** | scoped write | session-allow ok (900 s TTL) | L1 + L2 | allowed |
| **R2** | broad write | always explicit (300 s TTL) | L1 + L2 + L3 | allowed |
| **R3** | irreversible / external | always manual, no session-allow | L1 + L2 + L3 + external dependency check | **no execution retry** |

The declaration is validated by `skill_contract_validator` and enforced consistently in
the decision service, preflight, queue, executor, and synchronizer. Full detail in
[chapter 15](15-risk-classes.md).

---

## 10. Plugin directory structure

```
mod/booking/bookingextension/agent/
├── version.php · settings.php · lib.php · styles.css
├── db/
│   ├── install.xml      # all agent tables (install-only rollout)
│   ├── access.php       # capabilities
│   ├── services.php     # external function registrations
│   ├── tasks.php        # scheduled tasks
│   ├── caches.php       # MUC cache definitions
│   └── upgrade.php
├── classes/
│   ├── agent.php
│   ├── external/        # web services (the entry layer)        ch. 01
│   ├── task/            # scheduled + ad-hoc tasks              operations
│   └── local/wbagent/   # the engine
│       ├── agent_runtime.php · agent_state.php                 ch. 04
│       ├── orchestrator.php · interpreter.php                  ch. 05
│       ├── conversation_store.php                              ch. 03
│       ├── executor.php                                        ch. 11
│       ├── skill_registry*.php · base_skill.php · interfaces/  ch. 14
│       ├── core/skills/         # the core.* skills            skills/
│       ├── dto/                 # DTOs incl. skill_risk_class  ch. 15
│       ├── queue/               # queue_manager, observation_builder ch. 10
│       ├── services/            # ~80 engine services
│       │   ├── discovery/       # families, ranking            ch. 06
│       │   ├── selection/       # skill_selector, lazy loader  ch. 07
│       │   ├── construction/    # parameter constructor/validator ch. 07
│       │   ├── decision/        # agent_decision_service       ch. 08
│       │   ├── preflight_*      # the preflight pipeline       ch. 09
│       │   ├── synchronizer_*   # the reply contract           ch. 12
│       │   ├── embeddings/ · lookup/  # semantic retrieval, docs
│       │   ├── mutation/ · execution/ · governance/ · catalog/
│       │   └── security/        # authorization_service        ch. 02
│       ├── summarizer/          # result summarizers
│       └── benchmark/           # the benchmark harness        operations
├── amd/src/                     # front-end JS
├── templates/                   # mustache
├── cli/                         # benchmark + embedding CLIs
├── lang/                        # language strings
└── docs/                        # this corpus
```

---

## 11. Flowchart map

Each diagram subgraph maps to a chapter:

| Flowchart subgraph | Chapter |
|--------------------|---------|
| `ENTRY` (External API Layer) | [01 Entry & web services](01-entry-and-web-services.md) |
| `AUTHZ` | [02 Authorization & context](02-authorization-and-context.md) |
| `CSTORE` (conversation_store) | [03 Conversation store](03-conversation-store.md) |
| `RUNTIME` (agent_runtime) | [04 Runtime & loop](04-agent-runtime-and-loop.md) |
| `ORCH` (planner orchestrator) | [05 Planner](05-planner-orchestrator.md), [06 Discovery](06-discovery-families-embeddings.md), [07 Selection & construction](07-selection-and-construction.md) |
| `DECIDSVC` | [08 Decision service](08-decision-service.md) |
| `PREFLIGHT` | [09 Preflight pipeline](09-preflight-pipeline.md) |
| `QUEUE` (Shadow Queue) | [10 Shadow queue](10-shadow-queue.md) |
| `EXEC` (Execution Layer) | [11 Executor](11-executor.md) |
| `SYNC` (synchronizer) | [12 Synchronizer](12-synchronizer.md) |
| `RUNTIME` finalization + `OUTCOMES` | [13 Finalization & observations](13-finalization-and-observations.md) |
| `SKILLS` | [14 Skill layer](14-skill-layer.md) |
| `LEGEND` risk-class contracts | [15 Risk classes](15-risk-classes.md) |
| `SUPPORT` | [16 Support services](16-support-services.md) |

See [reference/flowchart-guide.md](../reference/flowchart-guide.md) for how to read the
diagram itself and a current list of code↔diagram discrepancies.
