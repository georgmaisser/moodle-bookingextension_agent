# Roadmap — Booking AI agent

Forward-looking, planned capabilities. Each entry is a feature concept, not a commitment; the
detail is intentionally light so this stays a planning overview rather than a design archive.

> This file is **not** part of the assistant's answer corpus (it is developer-facing, not
> embedded). End-user documentation lives under [`docs/user/`](user/).

## Planned skills

| Skill (working name) | What it would do |
|----------------------|------------------|
| `core.fill_form` | Let the assistant fill the form the user currently has open on the page (settings form, an activity form, …) from a natural-language description, instead of only creating records in the background. Research/feasibility captured; phased rollout when picked up. |
| `course.report` / `site.report` | An aggregate reporting family: activity-completion matrices, course-completion reports, enrolment reports across all participants — the read-only counterpart to the per-user `diagnose_*` skills. |
| `course.diagnose_progress` | Per-user completion/progress diagnosis in a course (which activities are done, why an activity is not yet complete, course-completion criteria status) — alongside `course.analyze_course_structure` (structure) and the grades diagnosis. |
| `course.*` semantic search | Course indexing + course-scoped semantic search, so the assistant can answer "where in this course is X" against the course's own content. |

## Platform ideas

| Idea | What it would do |
|------|------------------|
| Parallel sub-agents (fan-out) | Spin up several sub-agent runs in parallel for independent parts of a large request, then aggregate — for tasks that decompose into many similar units. |

---

*Implemented features are documented in [`docs/architecture/`](architecture/) (engine) and
[`docs/user/`](user/) (end users); they are not tracked here.*
