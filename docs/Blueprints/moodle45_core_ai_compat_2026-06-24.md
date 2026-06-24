# Moodle 4.5 core_ai compatibility — plan

Status: Phases 1-4 implemented (2026-06-24). Code complete + verified on 5.x;
live 4.5 end-to-end (deploy to the 4.5 box + run a real trial) pending Georg's go.
Context: Moodle 4.5 is supported until October 2027, so the agent must run on it.
Verified against a live 4.5.10 instance (training.wunderbyte.at).

## The gap (verified on 4.5.10)

Moodle 5.0 introduced **multi-instance** AI providers. Moodle 4.5 has a
fundamentally different, pre-instance `core_ai` model:

| Concept used by the agent (5.x) | 4.5 equivalent |
|---|---|
| instance row in `ai_providers` table | **does not exist** — `ai/db/` has no schema; one singleton config block per plugin in `config_plugins` |
| `manager::get_provider_instances()` | **does not exist** — provider = `new $pluginclass()`, config via `get_config()` |
| `manager::create/update/enable_provider_instance()` | **do not exist** — `set_config()` + `\core\plugininfo\aiprovider` enable |
| instance `config['apikey']` | `get_config('aiprovider_x', 'apikey')` |
| instance `actionconfig[ACTION]['settings']['endpoint'/'model'/'systeminstruction']` | flat keys `action_{basename}_endpoint` / `_model` / `_systeminstruction` |
| instance `actionconfig[ACTION]['enabled']` | `get_config('aiprovider_x', '{basename}')` (`manager::set_action_state`) |
| `manager::get_providers_for_actions()` (instance) | same name, but **static** (callable via `->`, works) |

4.5 `manager` exposes only `process_action()` + static helpers
(`get_providers_for_actions`, `get_supported_actions`, `is_action_enabled`,
`set_action_state`, `is_action_available`, `user_policy_accepted`).

`aiprovider_wunderbyte` is **not installable** on 4.5 (it is built on the 5.0
provider base + per-instance `actionconfig`). That is acceptable: on 4.5 the
agent runs in **reduced mode** over a core provider (openai/azureai) — only the
core `generate_text` action, full-catalog skill discovery instead of embeddings.

## What already works on 4.5 (no work needed)

- **Runtime LLM dispatch degrades by itself.** `llm_call_service::build_prompt_action()`
  resolves the WB custom actions (`planner_decide`, `generate_agent_reply`) only
  when their classes exist (`resolve_wunderbyte_prompt_action_class()`), otherwise
  falls back to core `generate_text`. So planner + reply run without the provider plugin.
- **Embeddings are `class_exists`-guarded everywhere** (embeddings_readiness_service,
  family/docs embeddings index services, rebuild tasks) → missing plugin ⇒
  full-catalog mode.

## The only real 4.5 blocker

The 5.0-only **provider management API**, called at:
`aiready.php` (3×), `orchestrator.php`, `agent_access_service.php`,
`trial_provisioner.php` (several), `activate_trial_context.php`.

## Plan

### Phase 1 — `provider_compat` read shim (additive, zero risk for 5.x) — IN PROGRESS
New `services/provider_compat.php` with `get_provider_views(): object[]`:
- **5.x:** real `manager->get_provider_instances()`.
- **4.5:** synthesise one instance-shaped `\stdClass` per **configured** aiprovider
  plugin (`->provider`, `->enabled`, `->config['apikey'/'name']`,
  `->actionconfig[ACTION]['enabled'/'settings']`) from the flat config keys, so
  every existing read-site keeps working unchanged.
- Branch via `method_exists($manager, 'get_provider_instances')`.

Reroute the read-sites: `aiready` (3×), `orchestrator`, `agent_access_service::find_wunderbyte_llm_instances`.

### Phase 2 — `provider_compat` write shim (provisioning) — DONE
`configure_provider(component, apikey, perActionSettings, enableActions)`:
- **5.x:** `create/update/enable_provider_instance`.
- **4.5:** `set_config(apikey)` + `set_config('action_{basename}_endpoint/_model/_systeminstruction')`
  + `manager::set_action_state` + plugin enable via `\core\plugininfo\aiprovider`.

Route `trial_provisioner` (incl. its instance reads at lines ~125/327) and
`activate_trial_context` through it. On 4.5 `detect_strategy()` already returns
`'openai'`, so the trial writes the WB key/endpoint into `aiprovider_openai`
(`generate_text` only, model `wunderbyte-privat` against llm.wunderbyte.at —
OpenAI-compatible).

### Phase 3 — readiness / UX for reduced mode — DONE
`aiready` flags hinge on `$wunderbyteprovinstalled` (always false on 4.5). Adjust
so 4.5 shows a clear **reduced-mode** state (no embeddings/RAG, generate_text only)
and lets trial/connect configure the core provider, instead of an empty/hybrid panel.

### Phase 4 — verification — DONE (non-destructive); live 4.5 E2E pending go
- `ai_action_register` column parity **confirmed** against the live 4.5 box: all columns
  the agent reads (`id, userid, contextid, actionname, success, timecompleted, errorcode,
  errormessage`) exist; `ai_error_classifier::classify_from_db` is `table_exists`-guarded.
- **Grep-clean confirmed:** no direct `*_provider_instance` / `get_provider_instances`
  calls remain outside `provider_compat`.
- **Lint clean** on all changed files; **5.x agent suite green** (521 passed, 51 skipped).
- All 4.5 facts verified on the live box: no `get_provider_instances`, flat config keys,
  `plugininfo\aiprovider::enable_plugin($shortname,1)`, `manager::set_action_state`.
- **Pending Georg's go:** deploy these changes to the 4.5 box + run a real trial
  (writes config, mints a real LiteLLM key) → openai gets WB config → agent answers via
  `generate_text` + full-catalog.

## Effort & risk
~3–5 dev-days total (no provider-plugin rework, no second repo). Risks are low:
- `get_providers_for_actions` called as `$manager->` on a 4.5 static method — PHP allows it.
- 4.5 = one config slot per plugin → no parallel providers / no clone flow (irrelevant on 4.5).
- Licence gate: openai→WB endpoint ⇒ `is_wunderbyte_host` true ⇒ full access granted,
  but functionally reduced mode — the intended behaviour.

## Note
The earlier commit `cd15d57` (fail-detail under aidebugmode + `_find_existing_key` fix)
stays valid for 5.x; the provisioning internals are partly replaced by Phase 2.
