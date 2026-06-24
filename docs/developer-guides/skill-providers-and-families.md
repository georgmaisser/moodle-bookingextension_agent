# Developer guide · Skill providers & families

> **Scope.** How a third-party plugin contributes skills, families, docs, issue codes, and
> normalization to the agent **without modifying the engine** — the "framework-agnostic by
> contract" principle in practice (`LG_AGN` / `LG_3P`).

The engine carries no domain knowledge. Everything booking-specific reaches it through
interfaces a provider implements. A new plugin "teaches" the agent by shipping a provider and
some contracts — never by editing engine code.

---

## 1. The provider model

`skill_registry::make_default()` discovers skills **provider-first** (see
[architecture/14-skill-layer.md §5](../architecture/14-skill-layer.md#5-provider-first-wiring)):

1. for each Moodle component it looks for `\{component}\local\wizard\skill_provider`;
2. if that class exists, it is instantiated and registered (its `skill_provider_interface`
   lists the skill instances);
3. **only if no provider class exists** does it fall back to scanning
   `…/local/wizard/*/skills` directly (`skill_discovery`);
4. the engine's own `bookingextension_agent` provider is always registered.

So the minimum to add skills is: implement `skill_provider_interface` in
`\{component}\local\wizard\skill_provider` and return your skill instances. The "no fallback
scan when a provider exists" rule keeps your skill set explicit.

```php
namespace yourcomponent\local\wizard;

use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;

class skill_provider implements skill_provider_interface {
    public function get_skills(): array {
        return [ new skills\do_something_skill(), /* … */ ];
    }
}
```

Each skill follows [writing-a-skill.md](writing-a-skill.md).

---

## 2. Families

A skill's [prompt contract](../architecture/14-skill-layer.md#3-the-prompt-contract) declares
a `family` (`<namespace>.<family>`, default `<namespace>.general`). Discovery ranks
**families**, not skills ([ch. 06](../architecture/06-discovery-families-embeddings.md)), so
grouping related skills into a coherent family improves routing. `skill_family_contract`
validates the family name; there is no separate registration — a valid family in a contract
participates automatically.

---

## 3. Serving your documentation (`docs_provider`)

Expose a docs corpus so `wizard.explain_docs` can answer questions about your plugin:

```php
namespace yourcomponent\local\wizard;

class docs_provider {
    public const CORPUS_ID = 'yourcomponent';
    public static function get_doc_corpora(): array {
        $dir = \core_component::get_component_directory('yourcomponent');
        return ($dir && is_dir("$dir/docs")) ? [self::CORPUS_ID => "$dir/docs"] : [];
    }
}
```

`docs_corpus_registry` discovers this component-agnostically; the embeddings index rebuilds
automatically on the next lookup (or via `rebuild_docs_embeddings_adhoc`). This is exactly
how this agent serves its own docs (corpus id `bookingextension_agent`) and how `mod_booking`
serves `mod_booking`.

---

## 4. Issue codes

Implement `issue_code_provider_interface` to define your **confirmable** (soft-block) and
domain-specific issue groups. `booking_issue_code_provider` is the reference: it is what
tells the [preflight domain check](../architecture/09-preflight-pipeline.md#3-layer-2--domain-prepare)
that `DUPLICATE_TITLE_CONFIRM_REQUIRED` is a *soft* block (→ confirmation) rather than a hard
failure. Without a provider entry, an unknown code is treated conservatively.

---

## 5. Input normalization hook

Implement `skill_input_normalizer_interface` (exposed via
`skill_input_normalizer_provider_interface`) for domain normalization applied during
interpretation — so the engine never needs domain-specific parsing (the `DNORM` hook). The
registry calls `normalize_skill_input(skillname, input)` through your provider.

---

## 6. R3 external dependency checks

For an R3 skill that reaches an external system, implement
`external_dependency_checker_interface` so [preflight L3-EXT](../architecture/09-preflight-pipeline.md#layer-3-ext--external-dependency-check-r3-only)
can verify reachability (webhook/payment) before the command is allowed. The shipped default
is `noop_external_dependency_checker`.

---

## 7. Other optional provider interfaces

- `skill_trigger_provider_interface` — message triggers + contextual prompt packs.
- `skill_result_summary_provider_interface` / result-summary contributors — compact bulk
  summaries in observations.
- `queue_identity_provider_interface` — custom queue identity.

---

## 8. Onboarding checklist

1. `skill_provider` returning your skills (each with schema, risk class, preflight, execute).
2. Per-skill capabilities `…:skill_<name>` in your `db/access.php`, assigned to roles.
3. (Optional) `docs_provider`, `issue_code_provider`, normalizer, external-dependency checker.
4. Enable the skills (`aiskillenabled_<name>` / "Enable all" on the
   [governance page](../operations/governance.md)).

No engine files change. That is the whole point of the contract.

---

## This corpus is self-served

The agent plugin ships its own `docs_provider`
(`classes/local/wizard/docs_provider.php`, corpus id `bookingextension_agent`) exposing this
`docs/` folder, so `wizard.explain_docs` answers questions about the engine itself directly
from these pages — no admin `aidocsroot` needed. Only the `mod_booking` corpus currently has
a public web route for a clickable source link; other corpora (including this one) still
render fully in the inline preview pane.
