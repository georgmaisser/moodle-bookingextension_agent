# Blueprint: easy API-key entry + admin gear (provider management)

**Status:** Draft / not started · **Date:** 2026-06-24 · **Component:** bookingextension_agent (+ aiprovider_wunderbyte instance)

## 1. Problem & goal
A customer who has **bought** an API key (or wants to **switch** from the trial to a paid key, or rotate a key)
needs an easy, safe way to store that key in the Wunderbyte AI provider — without going deep into
`Site administration → ... → AI providers`.

Two entry points, one shared backend:
- **(C) Connect screen** — first-time setup, when AI is not yet configured. Add an "I already have a key" path
  next to the existing "Start trial" / "Use existing provider" options.
- **(Gear) Admin gear** — always-available management for users with config rights, for **switching/rotating**
  the key after setup (the Connect screen is gone once configured). The gear also hosts a **Debug-mode toggle**.

Non-goal: letting non-admins store a site-level provider key (that is, and stays, a site-config action).

## 2. Hard constraints (security — non-negotiable)
1. **The key must never reach the LLM** — never as a chat message, never in thread history. Any capture is a
   dedicated, masked field; if a chat-paste convenience is ever added, it must intercept client-side and abort
   the send.
2. **Storing the provider key is a config action** → capability-gated server-side; the UI only *offers* it to
   capable users.
3. **All writes go through a webservice** (token/sesskey enforced by the external API layer), never a raw
   client-side `set_config`/GET.
4. **Mask** the key in the UI; **confirm before overwriting** an active trial/subscription/own key.

## 3. Capability model
- **Key store (both entry points):** reuse `bookingextension/agent:requesttrial` (managers + admins, same gate
  as the trial and `configure_provider_from_existing`) — keeps the onboarding/key paths consistent.
- **Debug toggle + gear visibility:** `moodle/site:config` (true site admin). Debug mode is a **site-wide**
  setting, so it belongs to admins only.
- The gear is rendered only if the user can perform **at least one** action; each item inside is shown/enabled
  per its own capability.

## 4. Backend (reuse first)
The trial already persists the provider instance via
`trial_provisioner::upsert_provider_instance($contextid, $apikey, $endpoint, $model)`
(creates/enables the `aiprovider_wunderbyte` instance with config + actionconfig). Reuse it.

New thin pieces:
- `trial_provisioner::configure_from_apikey(int $contextid, string $apikey): array`
  - Validate the key shape server-side (defensive; e.g. `^sk-[A-Za-z0-9_-]{20,}$`).
  - Call `upsert_provider_instance($contextid, $apikey, self::BASE_URL, <default model>)` — endpoint is the
    hard-coded `https://llm.wunderbyte.at` (same as the trial); models = the agent actionconfig defaults.
  - Return `{success, message}` like the other trial methods (synchronizer-friendly).
- External service `bookingextension_agent_store_provider_apikey` (mirror `configure_provider_from_existing`):
  - params: `contextid:int`, `apikey:string (PARAM_RAW, trimmed)`.
  - `require_capability('bookingextension/agent:requesttrial', context_system::instance())`.
  - calls `trial_provisioner::configure_from_apikey()`; returns `{success, message}`.
  - register in `db/services.php` (ajax=true, loginrequired=true).
- External service `bookingextension_agent_set_debug_mode`:
  - params: `enabled:bool`.
  - `require_capability('moodle/site:config', context_system::instance())`.
  - `set_config('aidebugmode', $enabled ? 1 : 0, 'bookingextension_agent')`; returns `{success, enabled}`.

> Switching trial → bought key is just `upsert_provider_instance` with the new key on the **same** instance
> (endpoint/models unchanged). No separate "cancel trial" step is required client-side.

## 5. UI touchpoints
All in the agent panel header (`templates/aiinstructions.mustache`, the `card-header` `d-flex` that already
holds `data-region="wb-usage-bar"` + `#booking-ai-configure-pill`).

### 5.1 Admin gear
- New mustache flag `provider_manage_available` (true when `has_capability('moodle/site:config')` OR
  `requesttrial`), rendered **right of the usage pill**:
  ```
  {{#provider_manage_available}}
    <button type="button" id="booking-ai-manage-gear" class="btn btn-sm btn-link p-0 ml-1"
            aria-haspopup="true" aria-expanded="false"
            title="{{#str}}agent_manage, bookingextension_agent{{/str}}">
      <i class="fa fa-cog" aria-hidden="true"></i>
    </button>
  {{/provider_manage_available}}
  ```
- Click → lightweight **popover/dropdown** (not a modal), with:
  1. **API-Key hinterlegen** → reveals a masked input + "Speichern". Shows current state line
     (see 5.3) and a confirm when a key/trial is already active.
  2. **Debug-Modus** → a toggle reflecting `aidebugmode`, flips via `set_debug_mode` (optimistic UI, revert on
     error). Labelled "site-wide".
  3. **→ Alle AI-Einstellungen** → link to `admin/settings.php?section=bookingextension_agent_aisettings`.
  - Keep it to exactly these; the gear is quick-actions, **not** a settings clone.

### 5.2 Connect screen path (C)
- In the existing connect decision (`#booking-ai-connect-decision`, where `requestTrialKey` /
  `configure_provider_from_existing` live), add a third button **"Ich habe bereits einen Key"** → reveals the
  same masked key field + "Speichern" → calls `store_provider_apikey` → on success reload the panel (same as
  the trial success path).

### 5.3 Current-state line (shown above the key field, both entry points)
Derived from the usage gateway (`aiprovider_wunderbyte` `get_key_usage` / the usage-bar data) — **percent only,
no euro** (per the privacy gateway decision):
- "Trial active (resets …)" / "Subscription active" / "Own key set" / "Not configured".
- Drives the overwrite confirm wording ("This replaces your active trial — continue?").

### 5.4 JS (`amd/src/aiinstructions.js`)
- Reuse the existing delegated click handling (cf. `#booking-ai-configure-pill` handler) for
  `#booking-ai-manage-gear` (toggle popover) and the inner actions.
- Key submit → `core/ajax` call to `store_provider_apikey`; mask input, never log the value, clear it after.
- Debug toggle → `set_debug_mode`.
- One shared `submitProviderKey()` used by both the gear and the connect path.

## 6. Flows
**Store/rotate key:** open (gear or connect) → paste key → [if active key/trial: confirm overwrite] →
`store_provider_apikey` → `upsert_provider_instance` → success toast + panel reload.

**Trial → bought key:** identical; the current-state line makes clear the trial is being replaced.

**Debug toggle:** flip → `set_debug_mode` → optimistic, revert + message on failure.

## 7. Edge cases
- Invalid key shape → client hint + server 4xx (validated both sides).
- WB provider plugin not installed → reuse the existing "install/upgrade" pill messaging; key store disabled.
- Bad/revoked key (passes shape, fails at the proxy) → a lightweight `/key/info`-style **quick check on save**
  reports it immediately (decided §9.2); the existing rate-limit/auth handling still covers later failures.
- No capability → gear/path not rendered; non-admins see "ask your admin".
- Concurrent edit / stale pill → reload after save.

## 8. Non-goals
- No non-admin key storage. No full settings UI in the gear. No euro amounts in the UI (percent only).
- No chat-paste regex capture in v1 (optional later, only with strict client-side intercept).

## 9. Decisions (locked 2026-06-24)
1. **Key capability:** `bookingextension/agent:requesttrial` for the key store (managers + admins, consistent
   with trial/clone); **Debug toggle + gear visibility:** `moodle/site:config`.
2. **Validate-on-save:** YES — a lightweight `/key/info`-style quick check runs on save; an invalid/unreachable
   key is reported immediately (not deferred to the first agent call).
3. **Gear vs configure pill:** keep **both, as distinct states** — `#booking-ai-configure-pill` for the
   not-yet-configured / trial-auto-config state; the admin gear for the configured/admin (rotate/switch +
   debug) state.

## 10. Implementation steps (phased)
1. Backend: `configure_from_apikey()` + `store_provider_apikey` + `set_debug_mode` externals + `db/services.php`
   (+ version bump). Unit-test the provisioner method (mock the instance write).
2. Connect-screen "I have a key" path (C) — smallest visible win, reuses success/reload.
3. Admin gear (mustache flag + markup + popover + JS) with key + debug + settings link.
4. Current-state line via the usage gateway; overwrite confirm.
5. Lang strings (en + de); grunt amd build; manual smoke (admin + non-admin + manager).
