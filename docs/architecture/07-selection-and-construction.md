# 07 · Selection & parameter construction

> **Scope.** The two planner LLM phases proper: selecting exactly one skill, then building
> its parameters. Flowchart subgraph: `ORCH` (selection/construction half).

Once [discovery](06-discovery-families-embeddings.md) has handed over a budgeted set of
family-scoped candidate skills, the planner makes its two chat calls. **Selection** answers
*which skill*; **construction** answers *with what arguments*. Each is a separate model call
against a separate prompt, and each is validated by deterministic code before the result is
trusted.

**Files:** `services/selection/*` (`skill_selector`, `lazy_skill_loader`,
`skill_selection_overlap_policy`), `services/construction/*` (`parameter_constructor`,
`parameter_contract_validator`), `dto/skill_selection_result.php`,
`dto/parameter_construction_result.php`.

---

## 1. Lazy skill loading

`lazy_skill_loader::load_skill(skillname, allowedskills = [])` loads exactly one concrete
skill object by canonical name, and only if it is within the allowed set for this turn.
This is the "load full schema only on demand" rule: discovery ranked *families*, selection
names *one skill*, and only that skill's full `skill_interface` (with its 50+-property
schema) is materialized — never the whole catalog. The loader is backed by the
[`skill_registry`](14-skill-layer.md).

---

## 2. Selection (planner LLM call 1)

The selector prompt asks the model to pick exactly one skill from the candidate list and to
emit **no parameters**. The raw model command is resolved by
`skill_selector::select(command, allowedskills, label): skill_selection_result`, which:

1. resolves the skill name through `skill_selection_overlap_policy::resolve(candidate,
   allowedskills)` — accepting either an exact canonical name (`mod_booking.create_option`)
   or an **unambiguous short suffix** (`create_option`, when it is unique among the allowed
   skills). Ambiguous overlaps (e.g. a name that could be booking *or* calendar) are not
   silently resolved;
2. loads that one skill via the lazy loader;
3. returns a `skill_selection_result` capturing the selected skill (or a contract error).

The interpreter enforces the selector contract independently: **exactly one** command
(`CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED`), with a present `skill`
(`CONTRACT_SELECTION_SKILL_MISSING`) that matches the resolved selection
(`CONTRACT_SELECTION_SKILL_MISMATCH`). See [ch. 05 §5](05-planner-orchestrator.md#5-interpretation--the-command-envelope).

---

## 3. `planned_steps[]` and `next_step_intent`

Two selector outputs make multi-step requests work without a second discovery pass:

- **`planned_steps[]`** — required in every selector output. It is `[]` for a single-step
  request, or when the prompt already contains a `[PENDING PLANNED STEPS]` block (the steps
  are already queued). On the **first turn** of a multi-step request it lists the *future*
  steps only (the current step is the selected skill). These become
  [`planned` placeholders](10-shadow-queue.md) on the queue.
- **`next_step_intent`** — required, always a string (never null). It names what the agent
  intends to do next and is:
  - written to thread metadata and appended to the next [embedding query](06-discovery-families-embeddings.md#4-the-embedding-query) (so a short "ja" stays on-task);
  - used as the per-turn progress **step message** label (`store->add_step_message`).

---

## 4. Construction (planner LLM call 2)

Construction runs **only** when selection returned `response_type = skill_call`. The
constructor prompt is restricted to the single selected skill and its full schema, and is
told the skill is already chosen — it must not re-discover. The raw parameters are turned
into a canonical input by `parameter_constructor::build(skillname, rawinput,
lastusermessage): parameter_construction_result`, which normalizes the payload against the
skill's schema (single-skill focus, 50+ properties supported).

---

## 5. Validation

`parameter_contract_validator::validate(skill, input, label): parameter_construction_result`
checks the constructed input against the skill's **structural contract** — it calls the
skill's own `check_structure(input)` (see [ch. 14](14-skill-layer.md)) and applies the
ambiguity rules. The outcome is one of:

- **valid** → the command proceeds to the [decision service](08-decision-service.md);
- **clarification** → missing/ambiguous required fields surface as a clarification to the
  user;
- **recoverable input error** (`RECOVERABLE_INPUT_ERROR`) → the loop can build a
  *preflight retry observation* and try once more
  (`build_preflight_retry_observation()` in the runtime — see
  [ch. 04](04-agent-runtime-and-loop.md)).

Note the division of labor: this validator only checks **structure** (shape, required
fields, ambiguity). Domain validity (does the option exist? may this user write it?) is
**not** checked here — that is the job of the skill's `preflight()` in
[Layer 2 of the preflight pipeline](09-preflight-pipeline.md). Selection/construction never
touch the database.

---

## 6. DTOs

| DTO | Carries |
|-----|---------|
| `skill_selection_result` | the selected skill name, success/contract status, issue codes |
| `parameter_construction_result` | the canonical input, validity, clarification/error detail, issue codes |

Both are consumed by `planner_result_composer` to build the unified planner result
([ch. 05 §6](05-planner-orchestrator.md#6-result-composition)).

---

## See also

- [05 · Planner orchestrator](05-planner-orchestrator.md) — how these phases are invoked and
  composed.
- [06 · Discovery](06-discovery-families-embeddings.md) — what produces the candidate skills.
- [08 · Decision service](08-decision-service.md) — what happens to a validated `skill_call`.
- [14 · Skill layer](14-skill-layer.md) — `check_structure`, the schema, the registry.
