# Developer guide · Writing a skill

> **Scope.** A practical, end-to-end guide to adding a new skill: the interface to
> implement, the contracts to declare, and the lifecycle methods the engine calls. See
> [architecture/14-skill-layer.md](../architecture/14-skill-layer.md) for the layer it fits
> into and [architecture/15-risk-classes.md](../architecture/15-risk-classes.md) for risk
> classes.

A skill is one capability. To add one you implement `skill_interface` (almost always by
extending `base_skill` or `core_skill_base`), declare its contracts, and register it through
a provider. The engine does the planning, gating, confirming, queuing, and replying around
it.

> **Quick start (scaffold).** You don't have to write the boilerplate by hand. Ask the agent
> for a skill template — the `wizard.scaffold_skill` skill turns a natural-language description
> plus a target `component` into a downloadable ZIP containing a fully-commented skill class
> (contract filled in, `preflight()`/`execute()` left as guided `TODO`s), a `db/access.php`
> capability snippet, a lang snippet and a README with the exact wiring steps. The generated
> file is contract-valid by construction and auto-discovered once dropped under
> `<yourplugin>/classes/local/wizard/<domain>/skills/` (no provider needed). Use this guide to
> then implement the behaviour.

---

## 1. Anatomy

```php
namespace yourcomponent\local\wizard\skills;

use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

class do_something_skill extends base_skill {
    public const SKILL_NAME = 'yourcomponent.do_something';

    public function __construct() {
        // (is_read_only, risk_class) — validated at construction.
        parent::__construct(false, skill_risk_class::R1);
    }

    public function get_name(): string { return self::SKILL_NAME; }
    // get_schema(), check_structure(), run_preflight(), execute() …
}
```

`base_skill`'s constructor `(bool $readonly, string $riskclass)` validates the risk class
immediately. The defaults it gives you: `check_structure()` → valid, `run_preflight()` → pass
after a structure check, `get_prompt_contract()` → derived from your schema's `prompt_meta`.
`execute()` is abstract — you must implement it. You never name an engine type: `preflight()`
and `get_prompt_contract()` are **final** wrappers; you override the DTO-free `run_preflight()`
(returns `$this->pass()/invalid()/confirmable()`) and `prompt_contract_payload()` (returns an
array). This is what lets the same skill run against any engine.

---

## 2. Name & schema

`get_schema()` returns the JSON the planner sees:

```php
public function get_schema(): array {
    return [
        'version' => 1,
        'description' => 'One clear sentence: what this does and when to use it.',
        'readonly' => $this->is_read_only(),
        'properties' => [
            'target' => ['type' => 'string', 'description' => '…', 'required' => true],
            'outputlang' => ['type' => 'string', 'description' => 'ISO 639-1 code', 'required' => false],
        ],
        'prompt_meta' => [
            'input_fields_for_prompt' => ['target'],
            'anchor_fields' => ['target'],
        ],
    ];
}
```

Keep the `description` action-oriented — it is the primary signal the
[selector](../architecture/07-selection-and-construction.md) uses. The constructor phase
supports 50+ properties, so be thorough about parameters.

---

## 3. The contracts: risk class & prompt contract

- **Risk class** — choose the lowest class that is honest (see the
  [matrix](../architecture/15-risk-classes.md)): R0 read-only, R1 scoped write, R2 broad
  write, R3 irreversible/external. `skill_contract_validator` enforces **R0 ⇔ is_read_only**,
  and **R2/R3 must declare `context_scopes`** — a mismatch makes the skill *not activatable*.
- **Prompt contract** — `base_skill` derives a `skill_prompt_contract` (intent, anchors,
  minimal_input, example_input, namespace, family, version, capabilities, context_scopes,
  risk_class) from your schema. Override `get_prompt_contract()` only if you need to tune the
  family or anchors.

Provide `get_example_input()` — a compact, realistic example improves selection and prompt
rendering.

---

## 4. `check_structure()` — pure validation

Validate **shape only**, no DB/IO. Return `['valid' => bool, 'errors' => string[]]`. This
runs in the planner right after parsing; keep it fast and side-effect-free.

```php
public function check_structure(array $input): array {
    $errors = [];
    if (trim((string)($input['target'] ?? '')) === '') {
        $errors[] = get_string('err_target_required', 'yourcomponent');
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}
```

---

## 5. `run_preflight()` — domain prepare (the DB-aware step)

This is [Layer 2](../architecture/09-preflight-pipeline.md) of preflight. Do the real
checks — resolve the target, confirm the user may act on it — and return the **prepared
input** the executor will run. Return the DTO-free primitive result via the `base_skill`
helpers (`pass()`/`invalid()`/`confirmable()`); the final `base_skill::preflight()` wraps it
into the engine result. You never name `preflight_result_v2`:

```php
protected function run_preflight(array $input, int $contextid, int $userid): array {
    // resolve + authorize …
    if ($notallowed) {
        return $this->invalid([['code' => 'PERMISSION_ERROR', 'severity' => 'needs_clarification',
            'message' => get_string('err_permission', 'yourcomponent')]]);
    }
    if ($looksLikeDuplicate && empty($input['override'])) {
        return $this->confirmable($prepared, [['code' => 'DOMAIN_CONFLICT',
            'severity' => 'needs_confirmation', 'message' => '…']]);
    }
    return $this->pass($prepared);
}
```

Soft-block codes must be declared confirmable by the domain
[issue-code provider](../architecture/16-support-services.md). An `override` input is the
conventional way to let the user confirm past a soft block.

---

## 6. `execute()` — run it

`execute()` receives the **prepared** input from preflight and must not redo heavy
resolution (the guard token guarantees the prepared input is unchanged — see
[architecture/11-executor.md](../architecture/11-executor.md)). Return a result array:

```php
return [
    'status' => 'executed',            // or 'error'
    'detail' => $userfacingmessage,
    'usermessage' => $userfacingmessage,
    'resultid' => $newid,
    'observation_full' => $deterministicObservation,   // what the next loop step sees
    'produced_outputs' => [...],        // optional artifacts for dependent commands
];
```

`observation_full` matters: it is the deterministic evidence the
[synchronizer](../architecture/12-synchronizer.md) trusts over the model's own claims, and
the basis of post-mutation verification.

> **Booking rule.** Option mutations must go through `mod_booking`'s
> `booking_option::update()` with form-style params (e.g. a draft area for images), so the
> executor stays free of skill-specific write logic. Bulk mirrors update_option through a
> shared `persist_and_verify_single_option` core with flat input.

---

## 7. Optional capabilities

A skill may also implement:

- `skill_trigger_provider_interface` → `get_message_triggers()` and
  `get_contextual_prompt_packs()` (guidance injected into the construction prompt for the
  selected skill);
- `get_result_preview($resultentry, $contextid, $userid)` → preview **data**
  (`type` / `html`/`js_module` / `payload`) that the engine passes through as `previewjson`;
- a `skill_input_normalizer` (via the provider) for domain normalization;
- a result-summary contributor for compact bulk summaries.

---

## 8. Registering & activating

Expose your skills through a `\{component}\local\wizard\skill_provider` implementing
`skill_provider_interface` (see
[skill-providers-and-families.md](skill-providers-and-families.md)). A newly discovered
skill is **default-off** until enabled via `aiskillenabled_<name>` (or "Enable all" on the
[governance page](../operations/governance.md)), and it needs its per-skill capability
`bookingextension/agent:skill_<name>` granted to the relevant roles
([db/access.php](../architecture/02-authorization-and-context.md#3-capabilities-dbaccessphp)).

---

## 9. Worked references

- **R0 example:** `core/skills/explain_docs_skill.php` — schema, triggers, contextual prompt
  pack, preview, no mutation.
- **R2 example:** `mod_booking.update_option` — preflight resolves the option, soft-blocks on
  duplicate title, executes via `booking_option::update()`.
- **R3 example:** `mod_booking.book_users` — manual confirmation, no retry, multi-user
  effect.
