# Operations · Governance

> **Scope.** The skill governance page and service: reviewing, activating, and auditing
> skills and their risk declarations.

**Files:** `skill_governance.php` (page), `services/governance/skill_governance_service.php`,
`services/debug/skill_selection_debug_service.php`, `skill_selection_debug.php` (page).

---

## 1. The governance page

`skill_governance.php` (admin page `bookingextension_agent_skillgovernance`, capability
`moodle/site:config`) is the operator's control panel for the skill catalog. It shows a
searchable table of every discovered skill with, per skill:

- an **active/inactive** toggle, name, component, and required capabilities;
- a **collision** badge — high-similarity embedding pairs are flagged (warning/danger) so two
  skills that the selector might confuse are visible;
- a collapsible detail row: description, example input (JSON), message triggers, and
  contextual guidance/prompt packs.

Bulk actions: **Enable all**, **Disable all**, and **Rebuild skill-catalog embeddings**
(queues the [ad-hoc task](tasks-and-async.md)).

---

## 2. Activation model

Each skill has a toggle config `aiskillenabled_<name>` (default **on per skill once
enabled**, but newly discovered skills are off until enabled). The "Enable all" action sets
`aiskillenableall = 1`; `skill_governance_service::sync_enableall_toggles()` then writes each
per-skill toggle to 1 and resets the one-shot flag. `skill_registry::is_skill_active()` reads
these toggles ([ch. 14 §4](../architecture/14-skill-layer.md#4-the-registry)).

---

## 3. Risk-class & contract review

A skill whose risk declaration is inconsistent is **not activatable**:
`skill_contract_validator` requires R0 ⇔ read-only and explicit `context_scopes` for R2/R3
([ch. 15 §2](../architecture/15-risk-classes.md#2-declaration--validation)). With
`aigovernancestrictmode` on, contract diagnostics make registry initialization fail outright
— useful as a CI guard so a mis-declared skill can never ship enabled.

---

## 4. Skill-selection debugging

`skill_selection_debug.php` (capability `bookingextension/agent:debugskillselection`, manager)
plus `skill_selection_debug_service` let an operator replay an input and inspect **why** a
skill was or wasn't selected — the discovery ranking, the candidate families, and the
selector decision. This is the governance-side companion to the runtime
[observability](observability.md) tools and the [benchmark](benchmarking.md) harness.
