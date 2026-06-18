# Operations · Trial Service

> **Scope.** The Wunderbyte AI trial: how an admin/manager goes from "nothing configured"
> to a working AI provider in one click, and the **infrastructure that lives outside this
> repo** (the trial microservice + LiteLLM proxy on `llm.wunderbyte.at`). Most of this is
> environment/config, so it is NOT recreated by a code deploy — keep this note current.

## Architecture

```
Moodle (customer site)                     llm.wunderbyte.at (Wunderbyte infra)
─────────────────────                      ───────────────────────────────────
[Start trial] (request_trial_key, sesskey  ── POST /api/moodle-trial {wwwroot,nonce} ─►  litellm_trial_api  (FastAPI, :8001)
 + capability requesttrial)                                                                 │  origin challenge -> GET wwwroot/trial_challenge.php?token=nonce
 trial_provisioner.php                      ◄── {apikey, endpoint, model} ───────────────  │  POST /key/generate (scoped admin key)
   └ core_ai create_provider_instance                                                      ▼
     (config.apikey + actionconfig          ── agent LLM calls (bearer = trial key) ────►  litellm_proxy     (:4000)  -> models
      endpoints = https://llm.wunderbyte.at)
```

- **Moodle side** (this repo): `classes/external/request_trial_key.php` (capability `requesttrial` + GDPR consent gate → `trial_consent_given` event) → `classes/local/wbagent/services/trial/trial_provisioner.php` → core_ai provider instance. The trial endpoint is **hardcoded** as `trial_provisioner::BASE_URL = https://llm.wunderbyte.at`; the former admin setting `trial_endpoint_base_url` was removed (the upgrade unsets the orphaned config).
- **Trial service** (separate server, container `litellm_trial_api`): `classes/local/wbagent/wunderbyte_trial_endpoint.py` is the **reference copy**. The running service has its own copy baked into its image — apply changes there and **rebuild**, not just restart.
- **LiteLLM proxy** (container `litellm_proxy`, `127.0.0.1:4000`): mints/serves keys and models.

## Trial service environment (docker-compose.yml on the trial server)

| Env | Purpose | Value used |
|---|---|---|
| `LITELLM_BASE_URL` | LiteLLM proxy base (what the service talks to) | internal, e.g. `http://litellm:4000` |
| `LITELLM_TRIALCREATE_KEY` | **Scoped** admin key for `/key/generate` + `/key/list` (NOT the master key — H1) | `sk-…` |
| `TRIAL_BUDGET_USD` | One-time credit per key | `3` |
| `TRIAL_DAYS` | Key lifetime (days) | `30` |
| `TRIAL_MODELS` | Models the key may use; must match the Moodle actionconfig and exist on the proxy | `wunderbyte-privat,wunderbyte-privat-mini,wunderbyte-embeddings` |
| `TRIAL_TEAM_ID` | LiteLLM team every key is created into (isolation + team budget backstop) | `<team id>` |
| `TRIAL_MAX_KEYS_PER_IP` | Per-IP key cap (C2) | `3` |
| `TRIAL_MAX_ACTIVE_KEYS` | Global cap over the trailing window (C2) | `500` |
| `TRIAL_ACTIVE_WINDOW_DAYS` | Window for the global cap | `30` |

> ⚠️ The public base URL Moodle stores in the provider (`https://llm.wunderbyte.at`) comes
> from the hardcoded `trial_provisioner::BASE_URL`, **not** from the service's
> `LITELLM_BASE_URL` (which is the service's internal view, e.g. `http://litellm:4000`).
> The provisioner deliberately ignores the service's echoed endpoint.

## One-time LiteLLM setup (must exist on the proxy)

1. **The three models** must be defined on the proxy: `wunderbyte-privat`, `wunderbyte-privat-mini`, `wunderbyte-embeddings`.
2. **Trial team** (isolation + hard total-spend backstop) — must permit the trial models:
   ```bash
   curl -X POST 'http://127.0.0.1:4000/team/new' \
     -H "Authorization: Bearer $LITELLM_MASTER_KEY" -H 'Content-Type: application/json' \
     -d '{"team_alias":"moodle-trials","max_budget":1000,
          "models":["wunderbyte-privat","wunderbyte-privat-mini","wunderbyte-embeddings"]}'
   # -> set the returned team_id as TRIAL_TEAM_ID
   ```
3. **Scoped admin key** for the service (H1 — keep the master key off the trial box):
   ```bash
   curl -X POST 'http://127.0.0.1:4000/user/new' \
     -H "Authorization: Bearer $LITELLM_MASTER_KEY" -H 'Content-Type: application/json' \
     -d '{"user_role":"proxy_admin","user_alias":"moodle-trial-service","key_alias":"moodle-trial-service-key"}'
   # -> set the returned key as LITELLM_TRIALCREATE_KEY
   ```
   (A `proxy_admin` key is rotatable/revocable independently of the master; for true least
   privilege scope it to the `moodle-trials` team — verify the team-admin sequence against
   your LiteLLM Swagger at `http://127.0.0.1:4000/docs`.)
4. **Spend alerting via e-mail to the main admin** (LiteLLM-native, not service code).
   LiteLLM sends budget alerts over SMTP; the recipient is **the e-mail on the account that
   owns the budget**, so put the **main/super-admin's e-mail** on the `moodle-trials` team admin
   (or the scoped service user from step 3) — there is no standalone "send alerts to X" address.

   a. Set the recipient by giving the owning account the main admin's e-mail (re-run/patch step 2/3):
   ```bash
   # On user/new (step 3) or via /user/update, attach the admin alert address:
   curl -X POST 'http://127.0.0.1:4000/user/update' \
     -H "Authorization: Bearer $LITELLM_MASTER_KEY" -H 'Content-Type: application/json' \
     -d '{"user_id":"<moodle-trial-service user id>","user_email":"<main admin e-mail>"}'
   ```

   b. Enable SMTP e-mail alerting in the proxy `config.yaml`:
   ```yaml
   litellm_settings:
     callbacks: ["smtp_email"]
   general_settings:
     alerting: ["email"]
     alert_types: ["budget_alerts", "spend_reports"]
     spend_report_frequency: "1d"
   ```

   c. SMTP env (docker-compose.yml on the trial server). Use Wunderbyte's own SMTP relay so the
   alert comes from a Wunderbyte address:
   ```bash
   SMTP_HOST="<wunderbyte smtp host>"
   SMTP_PORT="587"
   SMTP_TLS="True"
   SMTP_USERNAME="<smtp user>"
   SMTP_PASSWORD="<smtp password>"
   SMTP_SENDER_EMAIL="alerts@wunderbyte.at"   # the From: address
   # Optional tuning:
   EMAIL_BUDGET_ALERT_MAX_SPEND_ALERT_PERCENTAGE=0.8   # alert at 80% of max_budget
   EMAIL_BUDGET_ALERT_TTL=86400                        # at most one alert per 24h
   ```
   Verify after deploy: drive a trial key close to its budget (or lower a soft budget) and confirm
   the main admin receives the alert. (LiteLLM e-mail alerting docs:
   https://docs.litellm.ai/docs/proxy/email and https://docs.litellm.ai/docs/proxy/alerting.)

## Reverse proxy / client IP (required for the per-IP cap)

The containers bind to `127.0.0.1`, so a fronting layer (host nginx/Caddy/cloud LB)
terminates TLS for `llm.wunderbyte.at`. The per-IP cap reads **`X-Forwarded-For`**; that
layer **must set it (and strip any client-supplied XFF)**, or every request looks like one
IP and the per-IP=3 cap blocks the 4th trial globally. nginx:
```nginx
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Real-IP $remote_addr;
```
Verify after deploy: a freshly minted key's `metadata.request_ip` should be a real public IP.

## Deploy

- **Trial service code change** (e.g. `wunderbyte_trial_endpoint.py`): apply on the trial
  server, then **rebuild** — `docker compose up -d --build litellm_trial_api`. A plain
  `restart` reuses the old image.
- **Env-only change** (docker-compose.yml): `docker compose up -d` (recreate). `restart`
  keeps the old env.
- **Moodle plugin**: `php admin/cli/upgrade.php` + `php admin/cli/purge_caches.php`. The trial
  endpoint is hardcoded (`trial_provisioner::BASE_URL = https://llm.wunderbyte.at`); there is no
  admin setting. Capability `bookingextension/agent:requesttrial` (manager + admin) gates the trial.

## Security controls (implemented)

- **C1** — origin challenge re-enabled (`_verify_origin`); minting requires proof the caller controls the declared `wwwroot`.
- **H2** — SSRF guards on that challenge: https-only, private/loopback/link-local/metadata IPs rejected, no redirects.
- **C2** — per-IP cap (`TRIAL_MAX_KEYS_PER_IP`) + global trailing-window cap (`TRIAL_MAX_ACTIVE_KEYS`/`_ACTIVE_WINDOW_DAYS`); a `/key/list` failure fails OPEN (logged).
- **H1** — service uses the scoped `LITELLM_TRIALCREATE_KEY`, not the master key.
- **Key scoping** — each key: `max_budget` one-time (no `budget_duration` → never refills), `duration` expiry, `models` allowlist, `allowed_routes` = `["llm_api_routes","/key/info"]`, created into `TRIAL_TEAM_ID`.

Still open (see the security review): **H3** (challenge only proves control, not approval — mitigated by C2 + per-site dedup), **M1** (key stored plaintext in `ai_providers.config` — mitigated by budget/expiry), **M2** (confirm no key leaks into debug logs).

## Common ops tasks

```bash
P=http://127.0.0.1:4000; H="Authorization: Bearer $LITELLM_TRIALCREATE_KEY"

# List all trial keys (with the fields the caps use)
curl -s "$P/key/list?return_full_object=true&size=1000" -H "$H" \
 | jq '.keys[]|select(.key_alias|startswith("wunderbyte-privat-"))|{key_alias,created_at,request_ip:.metadata.request_ip,spend,expires}'

# Total trial spend
curl -s "$P/key/list?return_full_object=true&size=1000" -H "$H" \
 | jq '[.keys[]|select(.key_alias|startswith("wunderbyte-privat-")).spend]|add'

# Inspect one key
curl -s "$P/key/info?key=sk-…" -H "$H" | jq '{expires,max_budget,budget_duration,budget_reset_at,models,team_id}'

# Reset a site's trial: delete its key so a fresh one can be minted (site_id = sha256(wwwroot)[:32])
curl -X POST "$P/key/delete" -H "$H" -H 'Content-Type: application/json' \
 -d '{"keys":["sk-…"]}'
```

## Troubleshooting (issues seen during rollout)

| Symptom | Cause | Fix |
|---|---|---|
| `Request to http://litellm:4000/key/info failed: The URL is blocked` | provider endpoint was the service's internal URL | already fixed in code (uses public `trial_endpoint_base_url`); re-provision the instance |
| `key not allowed to access model … wunderbyte-privat-mini` | key minted before the 3-model change | redeploy trial service; delete old key; re-mint |
| `could not reliably parse the last step` | LLM call failing (blocked URL or model not granted) | the two rows above |
| Key shows `expires: null` | LiteLLM ignores raw `expires`; needs `duration` | fixed (payload uses `duration`); re-mint |
| "Budget resets mid-month" | `budget_duration` made the credit recurring | fixed (removed; one-time `max_budget`); re-mint |
| `request_ip` shows `127.0.0.1`/docker IP | fronting proxy not passing XFF | set `X-Forwarded-For` on the proxy |
| New env value not taking effect | container not recreated / not rebuilt | `up -d` (env) or `up -d --build` (code) |

## Pointers
- Design + decisions: [`../Blueprints/todo/oneclick_ai_setup_blueprint_2026-06-15.md`](../Blueprints/todo/oneclick_ai_setup_blueprint_2026-06-15.md)
- Moodle provisioner: [`../../classes/local/wbagent/services/trial/trial_provisioner.php`](../../classes/local/wbagent/services/trial/trial_provisioner.php)
- Trial service reference: [`../../classes/local/wbagent/wunderbyte_trial_endpoint.py`](../../classes/local/wbagent/wunderbyte_trial_endpoint.py)
