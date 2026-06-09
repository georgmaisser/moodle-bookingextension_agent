# bookingextension_agent — Documentation

This `docs/` directory is the documentation corpus for the **bookingextension_agent**
plugin — the *wbagent* engine and its skills. It is scoped to the **agent itself**:
how it receives a message, plans, decides, confirms, executes, and replies. For the
underlying booking feature set (booking rules, options, conditions, placeholders, …)
see [`mod/booking/docs`](../../../docs/README.md).

> **Who is this for?** Three audiences read this corpus:
> 1. **The agent itself** — `core.explain_docs` can serve these pages, so every page is
>    written as plain, self-contained Markdown with descriptive headings.
> 2. **Developers** extending the engine or writing skills.
> 3. **Administrators / operators** configuring, monitoring, and benchmarking the agent.

---

## Quick-start guide

| I want to… | Go to… |
|------------|--------|
| Understand the whole engine in one read | [Architecture overview](architecture/README.md) |
| Follow a single chat message through the engine | [Architecture overview → Request lifecycle](architecture/README.md#request-lifecycle) |
| Know which web service the UI calls | [Entry layer & web services](architecture/01-entry-and-web-services.md) |
| Understand how a skill is chosen | [Planner orchestrator](architecture/05-planner-orchestrator.md) and [Discovery, families & embeddings](architecture/06-discovery-families-embeddings.md) |
| Understand confirmation / "are you sure?" prompts | [Decision service](architecture/08-decision-service.md) and [Risk classes](architecture/15-risk-classes.md) |
| Know why a command was blocked or retried | [Preflight pipeline](architecture/09-preflight-pipeline.md) and [Shadow queue](architecture/10-shadow-queue.md) |
| Write a new skill | [Developer guide: writing a skill](developer-guides/writing-a-skill.md) |
| Add the agent to a third-party plugin | [Developer guide: skill providers & families](developer-guides/skill-providers-and-families.md) |
| Configure the plugin as an admin | [Operations: configuration](operations/configuration.md) |
| Debug a conversation / read LLM logs | [Operations: observability & debugging](operations/observability.md) |
| Understand PDF upload / why a PDF "could not be processed" | [Entry layer → Attachments §7](architecture/01-entry-and-web-services.md#7-attachments-docs--previews) and [Operations: server requirements](operations/configuration.md#server-requirements-optional) |
| Look up a term | [Reference: glossary](reference/glossary.md) |

---

## Documentation sections

### Architecture (the engine, end to end)

The [`architecture/`](architecture/README.md) section is the heart of this corpus. It
mirrors the canonical design diagram
[`AGENT_IMPLEMENTATION_FLOWCHART.mmd`](Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd)
one subsystem per chapter.

| Chapter | Subsystem |
|---------|-----------|
| [Overview](architecture/README.md) | Big picture, layers, request lifecycle, flowchart map |
| [01](architecture/01-entry-and-web-services.md) | Entry layer & external web services |
| [02](architecture/02-authorization-and-context.md) | Authorization & context |
| [03](architecture/03-conversation-store.md) | Conversation store (threads, runs, metadata) |
| [04](architecture/04-agent-runtime-and-loop.md) | Agent runtime & the agent loop (budget, observations) |
| [05](architecture/05-planner-orchestrator.md) | Planner orchestrator (the strict phase pipeline) |
| [06](architecture/06-discovery-families-embeddings.md) | Discovery: families, embeddings & ranking |
| [07](architecture/07-selection-and-construction.md) | Selection & parameter construction |
| [08](architecture/08-decision-service.md) | Decision service (deterministic routing & risk gating) |
| [09](architecture/09-preflight-pipeline.md) | Preflight pipeline (schema / domain / execution gate) |
| [10](architecture/10-shadow-queue.md) | Shadow queue (DAG, retries, confirmation, planned steps) |
| [11](architecture/11-executor.md) | Executor (the only place skills run) |
| [12](architecture/12-synchronizer.md) | Synchronizer (the final user-facing reply) |
| [13](architecture/13-finalization-and-observations.md) | Finalization classifier & observation loop |
| [14](architecture/14-skill-layer.md) | Skill layer (registry, interface, contracts) |
| [15](architecture/15-risk-classes.md) | Risk classes R0–R3 (the cross-cutting contract) |
| [16](architecture/16-support-services.md) | Support services (anonymizer, language, triggers, errors) |

### Skills

| Page | Description |
|------|-------------|
| [`skills/`](skills/README.md) | Catalog of shipped skills (`core.*` and `booking.*`), what each does, its risk class and triggers |

### Developer guides

| Page | Description |
|------|-------------|
| [Writing a skill](developer-guides/writing-a-skill.md) | The skill contract, risk-class declaration, preflight, execute, examples |
| [Skill providers & families](developer-guides/skill-providers-and-families.md) | How a third-party plugin contributes skills and families without engine changes |
| [Web services API](developer-guides/web-services-api.md) | Every external function: parameters, returns, errors |
| [Data model & DB](developer-guides/data-model-and-db.md) | `db/install.xml` tables, the install-only rollout policy |

### Operations

| Page | Description |
|------|-------------|
| [Configuration](operations/configuration.md) | Every admin setting in `settings.php` |
| [Tasks & async execution](operations/tasks-and-async.md) | Scheduled and ad-hoc tasks, the adhoc run worker |
| [Governance](operations/governance.md) | The skill governance page and service |
| [Benchmarking](operations/benchmarking.md) | The benchmark harness, scenarios, CI gate |
| [Observability & debugging](operations/observability.md) | LLM debug logs, the skill-selection debug tools, audit trails |

### Reference

| Page | Description |
|------|-------------|
| [Glossary](reference/glossary.md) | Every term of art used in this corpus |
| [Issue codes & error classes](reference/issue-codes.md) | The complete catalog with meanings and routing effect |
| [Flowchart guide](reference/flowchart-guide.md) | How to read the canonical diagram and how it maps to code |

### Topic notes

| Page | Description |
|------|-------------|
| [Shortcode escaping in replies](shortcode_escaping_in_replies.md) | Why the agent escapes `[shortcode]` brackets in chat replies |
| [PDF text extraction](architecture/01-entry-and-web-services.md#7-attachments-docs--previews) | How uploaded PDFs become text: `pdftotext` fast path + bundled pure-PHP `smalot/pdfparser` fallback ([`thirdparty/pdfparser/`](../thirdparty/pdfparser/), LGPL-3.0); no OCR; 15 000-char cap |

### Blueprints (working documents)

The [`Blueprints/`](Blueprints) subfolder holds design analyses, roadmaps, and
refactor plans. They are development working documents, not end-user documentation, and
may describe states that are planned rather than shipped. The authoritative design
reference is the flowchart in
[`Blueprints/flowcharts/`](Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd).

---

## How to read this corpus

- **Start with the [architecture overview](architecture/README.md).** It gives you the
  whole loop in one page and links into each subsystem chapter for depth.
- **The flowchart is the source of truth for design.** Where this prose and the
  flowchart disagree, the discrepancy is called out explicitly in the relevant chapter
  (look for a *⚠ Flowchart note* callout) rather than silently resolved.
- **Every chapter names the files it documents** so you can jump from prose to code.

---

## Related resources

- [Wunderbyte website](https://www.wunderbyte.at)
- [mod_booking documentation](../../../docs/README.md)
- Canonical design diagram: [`AGENT_IMPLEMENTATION_FLOWCHART.mmd`](Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd)
