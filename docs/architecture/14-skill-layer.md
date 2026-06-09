# 14 · Skill layer

> **Scope.** The skill abstraction: registry, interface, base classes, the prompt and risk
> contracts, and how skills are discovered and validated. Flowchart subgraph: `SKILLS`.

A **skill** is one capability the agent can invoke — read documentation, search users,
create a booking option, book users. The skill layer is the contract every skill obeys so
the engine can plan, validate, gate, and execute it uniformly, and so a third-party plugin
can add skills without touching the engine.

**Files:** `skill_registry.php`, `skill_registry_factory.php`, `skill_discovery.php`,
`skill_provider.php`, `base_skill.php`, `core/skills/core_skill_base.php`,
`skill_contract_validator.php`, `skill_executability_evaluator.php`,
`interfaces/skill_interface.php`, `interfaces/skill_provider_interface.php`,
`interfaces/skill_*_provider_interface.php`, `services/skill_prompt_contract.php`.

---

## 1. The skill interface

Every skill implements `skill_interface`:

| Method | Purpose |
|--------|---------|
| `get_name(): string` | the fully-qualified name (`vendor.action`) |
| `get_schema(): array` | the JSON schema embedded in the planner prompt |
| `get_example_input(): array` | a compact example for the prompt |
| `get_prompt_contract(): skill_prompt_contract` | the routing contract (below) |
| `get_risk_class(): string` | the declared R0–R3 ([ch. 15](15-risk-classes.md)) |
| `check_structure(input): array` | **pure** structural validation, no DB/IO |
| `preflight(input, contextid, userid): preflight_result_v2` | deep, DB-dependent validation/prepare ([ch. 09](09-preflight-pipeline.md)) |
| `execute(prepared_input, contextid, userid): array` | run the skill ([ch. 11](11-executor.md)) |
| `is_read_only(): bool` | whether it can auto-execute |

The contract is precise about **who calls what**: `check_structure()` runs in the planner
right after parsing; `preflight()` runs in the decision/preflight phase and must not write;
`execute()` receives the *prepared* input and must not redo heavy resolution.

---

## 2. Base classes

`base_skill` provides safe defaults. Its constructor takes `(bool $readonly, string
$riskclass)` and **validates the risk class at construction** (an invalid class throws). Its
defaults: `check_structure()` → valid, `preflight()` → ok after a structure check,
`get_prompt_contract()` → auto-derived from the schema's `prompt_meta`, and `execute()` →
**abstract** (every concrete skill must implement it).

`core_skill_base` extends it for the engine's own `core.*` skills (always read-only, with
helpers for language resolution, user/course/role resolution, access checks, and observation
formatting). A provider's `booking_skill_base` plays the same role for booking skills.

---

## 3. The prompt contract

`skill_prompt_contract` is the compact, routing-facing description of a skill (distinct from
its full schema). Fields: `intent`, `anchors`, `minimal_input`, `example_input`,
`namespace`, `family`, `version`, `capabilities`, `context_scopes`, `risk_class`. Discovery
ranks **families** derived from this contract ([ch. 06](06-discovery-families-embeddings.md));
selection and the prompt builders use `intent`/`anchors`/`minimal_input` to present the
skill to the model.

---

## 4. The registry

`skill_registry` is the catalog and the lookup. Selected methods:

- **lookup**: `get_skill(name)`, `get_skills()`, `get_skill_names()`,
  `is_read_only_skill(name)`, `is_skill_active(name)`, `get_skill_capabilities(name)`.
- **schemas & contracts**: `get_all_schemas()`, `get_all_prompt_contracts()`,
  `get_skill_contract(name)` / `get_skill_contracts()` (governance metadata).
- **context-filtered**: `get_skill_names_for_context()`, `get_all_schemas_for_context()`,
  `get_prompt_contracts_for_context()` — filtered through
  `skill_executability_evaluator` so the planner only ever sees skills the user may run.
- **provider extras**: `get_contextual_prompt_packs()`, `get_result_summary_contributors()`,
  `normalize_skill_input()`.
- **construction**: `make_default()`.

**Activation.** `is_skill_active()` returns true if the global `aiskillenableall` is set,
otherwise checks the per-skill `aiskillenabled_<name>` config — newly discovered skills are
**default-off** until explicitly enabled (a governance safeguard, see
[operations/governance.md](../operations/governance.md)).

> Triggers are deliberately inert at this layer: `get_message_triggers()` and the
> trigger→skill map return `[]`. The server *derives* triggers like `core.is_lookup_request`
> instead of routing by them — the `MTRIG` / `LG_*` "no trigger→skill routing" rule (see
> [ch. 16](16-support-services.md)).

---

## 5. Provider-first wiring

`skill_registry::make_default()` (via `skill_registry_factory`) discovers skills
**provider-first**:

1. for each Moodle component, if a `\{component}\local\wbagent\skill_provider` class exists,
   it is instantiated and registered;
2. **only if no provider exists** does it fall back to a direct scan
   (`skill_discovery::get_skill_instances($component)` over `…/skills`), wrapping the found
   skills in an anonymous provider;
3. the engine's own `bookingextension_agent` default provider is always registered.

The rule "if a provider exists, no additional fallback scan" keeps a plugin's skill set
explicit and under its own control. See
[developer-guides/skill-providers-and-families.md](../developer-guides/skill-providers-and-families.md).

---

## 6. Contract validation

`skill_contract_validator` builds normalized governance metadata
(`build_skill_metadata()` — namespace, family, version, capabilities, active flag, readonly,
risk_class, context_scopes) and enforces the **risk-class declaration**
(`verify_risk_class_declaration()`):

- a valid risk class is required;
- **R0 ⇔ `is_read_only()` true**; R1/R2/R3 must be **not** read-only;
- **R2/R3 must declare explicit `context_scopes`**;
- a mismatch makes the skill **not activatable**.

Releasability at run time is a separate evaluator (`skill_executability_evaluator`, [ch. 11
§3](11-executor.md)) with deny reasons `not_registered`, `inactive`, `missing_capability`,
`context_invalid`, `runtime_disabled`, `skill_version_unsupported`.

---

## 7. Flowchart notes

> **✓ Confirmed:** provider-first wiring with no-fallback-when-provider-present;
> `check_structure`/`preflight`/`execute` separation; risk-class validation
> (R0↔readonly, R2/R3 require scopes → not activatable on mismatch); triggers inert at
> registry level. The sixth deny reason `skill_version_unsupported` has been added to the
> `EXC_EVAL` node.

See also [skills catalog](../skills/README.md) and
[developer-guides/writing-a-skill.md](../developer-guides/writing-a-skill.md).
