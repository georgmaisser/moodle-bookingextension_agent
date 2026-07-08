"""
Wunderbyte Moodle Trial Key Endpoint
=====================================
Mount this FastAPI router in your existing LiteLLM / FastAPI application.

Requirements
------------
    pip install fastapi httpx litellm

Environment variables expected
--------------------------------
    LITELLM_TRIALCREATE_KEY   – your LiteLLM admin key (Bearer token for management API)
    LITELLM_BASE_URL     – base URL of your LiteLLM instance, e.g. https://llm.wunderbyte.at
    TRIAL_BUDGET_USD     – optional, default 3.0  ($ credit for the trial key)
    TRIAL_DAYS           – optional, default 14   (lifetime of the trial key in days)
    TRIAL_MODELS         – optional, comma-separated; default
                           "wunderbyte-privat,wunderbyte-privat-mini,wunderbyte-embeddings"
                           (the model aliases the trial key may access; must match the
                           Moodle agent's actionconfig and exist on the proxy)
    TRIAL_TEAM_ID        – optional; LiteLLM team id every trial key is created into,
                           so trial keys are isolated from production keys and capped by
                           the team's max_budget. The team must permit the trial models.
    TRIAL_FAIL_CLOSED    – optional, default 1 (on). If the existing-key listing used for the
                           abuse caps cannot be fetched, refuse the trial (503) instead of
                           issuing uncapped. Set 0 to restore the old fail-open behaviour.
    TRIAL_TRUSTED_PROXY_HOPS – optional, default 1 — reverse proxies in front of this app;
                           used to read the real client IP from X-Forwarded-For (per-IP cap)
                           without it being spoofable via a forged header.

Deployment
----------
In your main FastAPI app:

    from wunderbyte_trial_endpoint import router as trial_router
    app.include_router(trial_router, prefix="/api")

The Moodle plugin will POST to  POST /api/moodle-trial
"""

import hashlib
import ipaddress
import logging
import os
import socket
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse

import httpx
from fastapi import APIRouter, HTTPException, Request
from pydantic import BaseModel, HttpUrl

logger = logging.getLogger(__name__)

router = APIRouter()

# ---------------------------------------------------------------------------
# Configuration (from environment)
# ---------------------------------------------------------------------------
LITELLM_BASE_URL: str = os.environ.get("LITELLM_BASE_URL", "http://localhost:4000").rstrip("/")
LITELLM_TRIALCREATE_KEY: str = os.environ.get("LITELLM_TRIALCREATE_KEY", "")
TRIAL_BUDGET_USD: float = float(os.environ.get("TRIAL_BUDGET_USD", "3.0"))
TRIAL_DAYS: int = int(os.environ.get("TRIAL_DAYS", "30"))

# LiteLLM model aliases the trial key is allowed to use. The Moodle agent maps its
# actions onto these EXACT names: wunderbyte-privat (chat / agent reply / generate_text),
# wunderbyte-privat-mini (compact planner), wunderbyte-embeddings (embeddings). All three
# must exist as models on the LiteLLM proxy. Override with a comma-separated TRIAL_MODELS
# env var only if the proxy uses different names (then align the Moodle actionconfig too).
TRIAL_MODELS: list[str] = [
    m.strip()
    for m in os.environ.get(
        "TRIAL_MODELS",
        "wunderbyte-privat,wunderbyte-privat-mini,wunderbyte-embeddings",
    ).split(",")
    if m.strip()
]

# Primary model reported back to Moodle (informational only — Moodle configures all
# three actions itself). Defaults to the first granted alias.
TRIAL_MODEL_ALIAS: str = os.environ.get(
    "TRIAL_MODEL_ALIAS", TRIAL_MODELS[0] if TRIAL_MODELS else "wunderbyte-privat"
)

# Abuse limits. Per-IP: how many trial keys one source IP may ever create. Global:
# how many trial keys may exist created within the trailing ACTIVE_WINDOW_DAYS.
# All trial keys carry the alias prefix below + request_ip/created_at in metadata.
MAX_KEYS_PER_IP: int = int(os.environ.get("TRIAL_MAX_KEYS_PER_IP", "3"))
MAX_ACTIVE_KEYS: int = int(os.environ.get("TRIAL_MAX_ACTIVE_KEYS", "500"))
ACTIVE_WINDOW_DAYS: int = int(os.environ.get("TRIAL_ACTIVE_WINDOW_DAYS", "30"))
TRIAL_ALIAS_PREFIX: str = "wunderbyte-privat-"


def _env_flag(name: str, default: str = "1") -> bool:
    return os.environ.get(name, default).strip().lower() in ("1", "true", "yes", "on")


# When the existing-keys listing needed for the abuse caps cannot be fetched, fail CLOSED by
# default (refuse the trial, 503) instead of open: failing open let anyone bypass the per-IP
# and global caps by inducing a list error. Set TRIAL_FAIL_CLOSED=0 to restore fail-open.
TRIAL_FAIL_CLOSED: bool = _env_flag("TRIAL_FAIL_CLOSED", "1")

# Number of trusted reverse proxies in front of this app, used to read the real client IP
# from the (client-appendable) X-Forwarded-For for the per-IP cap without it being spoofable.
TRIAL_TRUSTED_PROXY_HOPS: int = int(os.environ.get("TRIAL_TRUSTED_PROXY_HOPS", "1"))

# Optional LiteLLM team every trial key is created into, so trial keys are isolated
# from production keys and bounded by the team's own max_budget (a hard spend backstop
# independent of the per-key/global caps). Create the team once via /team/new — it MUST
# permit the trial models — then set its id here. Empty -> keys are created team-less.
TRIAL_TEAM_ID: str = os.environ.get("TRIAL_TEAM_ID", "")


# ---------------------------------------------------------------------------
# Request / response schemas
# ---------------------------------------------------------------------------
class TrialRequest(BaseModel):
    wwwroot: HttpUrl        # e.g. "https://learn.example.com"
    nonce: str              # hex string generated by Moodle


class TrialResponse(BaseModel):
    apikey: str
    endpoint: str           # base URL Moodle should use (no trailing slash)
    model: str


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def _site_id(wwwroot: str) -> str:
    """Stable, opaque identifier for a Moodle site derived from its wwwroot."""
    return hashlib.sha256(wwwroot.encode()).hexdigest()[:32]


async def _verify_origin(wwwroot: str, nonce: str) -> bool:
    """
    Back-channel challenge: fetch the Moodle challenge endpoint and check that
    it echoes back the nonce exactly.  This proves the request really originates
    from the declared domain.

    Hardened against SSRF: the wwwroot comes from the (untrusted) request body, so
    we require https and refuse any host that resolves to a private/loopback/
    link-local/reserved address, and we do NOT follow redirects (a redirect could
    bounce us onto an internal target). Residual risk: DNS rebinding between this
    resolve and httpx's own resolve — acceptable for a budget-capped trial key, but
    pin the IP here if you want belt-and-braces.
    """
    parsed = urlparse(wwwroot)
    if parsed.scheme != "https":
        logger.warning("Rejected non-https wwwroot: %s", wwwroot)
        return False
    host = parsed.hostname
    if not host or not _host_is_public(host):
        logger.warning("Rejected wwwroot with non-public/unresolvable host: %s", wwwroot)
        return False

    challenge_url = f"{wwwroot.rstrip('/')}/mod/booking/bookingextension/agent/trial_challenge.php"
    try:
        async with httpx.AsyncClient(timeout=10, follow_redirects=False) as client:
            resp = await client.get(challenge_url, params={"token": nonce})
            if resp.status_code != 200:
                logger.warning("Challenge failed for %s: HTTP %s", wwwroot, resp.status_code)
                return False
            echoed = resp.text.strip()
            if echoed != nonce:
                logger.warning(
                    "Nonce mismatch for %s: expected %r, got %r", wwwroot, nonce, echoed
                )
                return False
            return True
    except Exception as exc:
        logger.warning("Challenge request error for %s: %s", wwwroot, exc)
        return False


def _host_is_public(host: str) -> bool:
    """
    True only if every address `host` resolves to is a global/public IP. Blocks
    SSRF to loopback, RFC1918, link-local (incl. cloud metadata 169.254.169.254),
    reserved, multicast and unspecified ranges.
    """
    try:
        infos = socket.getaddrinfo(host, None)
    except OSError:
        return False
    for info in infos:
        ip = info[4][0]
        try:
            addr = ipaddress.ip_address(ip)
        except ValueError:
            return False
        if (
            addr.is_private
            or addr.is_loopback
            or addr.is_link_local
            or addr.is_reserved
            or addr.is_multicast
            or addr.is_unspecified
        ):
            return False
    return True


async def _litellm_headers() -> dict:
    return {
        "Authorization": f"Bearer {LITELLM_TRIALCREATE_KEY}",
        "Content-Type": "application/json",
    }


async def _find_existing_key(site_id: str) -> str | None:
    """
    Look for an already-issued trial key for this site in LiteLLM.
    Returns the key string if found, None otherwise.
    """
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get(
                f"{LITELLM_BASE_URL}/key/list",
                headers=await _litellm_headers(),
                params={"key_alias": f"wunderbyte-privat-{site_id}"},
            )
            resp.raise_for_status()
            keys = resp.json().get("keys", [])
            if keys:
                # LiteLLM never returns the plaintext key after creation (only a hash), so we cannot
                # hand the existing key back. Return a truthy marker (the alias) to SIGNAL existence,
                # so the caller responds with a clean 409 ("trial already issued") instead of falling
                # through to /key/generate, which would 400 on the duplicate alias.
                return f"wunderbyte-privat-{site_id}"
    except Exception as exc:
        logger.warning("Could not query existing keys: %s", exc)
    return None


def _client_ip(request: Request) -> str:
    """
    Real source IP for the per-IP cap, resistant to a forged X-Forwarded-For.

    XFF is client-appendable, so its LEFTMOST entry is spoofable (the earlier behaviour). Each
    trusted proxy instead APPENDS the address it actually saw (nginx default
    `$proxy_add_x_forwarded_for`), so the entry TRIAL_TRUSTED_PROXY_HOPS from the RIGHT is the
    one our own infrastructure added. Set TRIAL_TRUSTED_PROXY_HOPS to your proxy depth.
    """
    hops = TRIAL_TRUSTED_PROXY_HOPS
    xff = request.headers.get("x-forwarded-for", "")
    if xff and hops >= 1:
        parts = [p.strip() for p in xff.split(",") if p.strip()]
        if len(parts) >= hops:
            return parts[-hops]
    return request.client.host if request.client else "unknown"


async def _list_trial_keys() -> list[dict] | None:
    """
    All trial keys (full objects, so metadata/created_at are available). Returns None
    if the list call fails — callers then fail OPEN (a LiteLLM hiccup must not block
    legitimate trials), which is logged loudly.
    """
    try:
        async with httpx.AsyncClient(timeout=15) as client:
            resp = await client.get(
                f"{LITELLM_BASE_URL}/key/list",
                headers=await _litellm_headers(),
                params={"return_full_object": "true", "size": 1000},
            )
            resp.raise_for_status()
            data = resp.json()
    except Exception as exc:
        logger.error("Could not list trial keys for cap enforcement: %s", exc)
        return None

    raw = data.get("keys", []) if isinstance(data, dict) else data
    out: list[dict] = []
    for k in raw:
        if not isinstance(k, dict):
            # Only key hashes were returned (no return_full_object support) -> no metadata.
            logger.warning("LiteLLM /key/list returned no full objects; per-IP cap cannot be enforced.")
            continue
        if str(k.get("key_alias") or "").startswith(TRIAL_ALIAS_PREFIX):
            out.append(k)
    return out


def _count_keys_for_ip(keys: list[dict], ip: str) -> int:
    return sum(1 for k in keys if (k.get("metadata") or {}).get("request_ip") == ip)


def _count_active_keys(keys: list[dict], window_days: int) -> int:
    cutoff = datetime.now(timezone.utc) - timedelta(days=window_days)
    n = 0
    for k in keys:
        created = (k.get("metadata") or {}).get("created_at") or k.get("created_at")
        if not created:
            n += 1  # unknown age -> count conservatively
            continue
        try:
            ts = datetime.fromisoformat(str(created).replace("Z", "+00:00"))
            if ts.tzinfo is None:
                ts = ts.replace(tzinfo=timezone.utc)
            if ts >= cutoff:
                n += 1
        except ValueError:
            n += 1  # unparseable -> count conservatively
    return n


async def _create_trial_key(site_id: str, wwwroot: str, client_ip: str) -> str:
    """
    Create a new time-limited, budget-limited LiteLLM virtual key for the trial.
    The key is tagged with the site identifier so it can be found and revoked later.
    """
    payload = {
        "key_alias": f"wunderbyte-privat-{site_id}",
        "models": TRIAL_MODELS,
        # One-time trial credit that NEVER resets: max_budget with NO budget_duration
        # (budget_duration would refill it every period). The key instead simply expires.
        "max_budget": TRIAL_BUDGET_USD,
        # Key lifetime. LiteLLM sets expiry from `duration` (e.g. "30d") — a raw `expires`
        # timestamp is ignored, which is why earlier keys came back with expires=null.
        "duration": f"{TRIAL_DAYS}d",
        # Usage is read via the privacy-preserving gateway (POST /api/shop/usage,
        # master-key lookup), NOT by the key itself — so deliberately NO /key/info:
        # a customer must not be able to read the raw euro spend/budget. Only
        # "llm_api_routes" (chat) stays.
        "allowed_routes": ["llm_api_routes"],
        "metadata": {
            "moodle_site": str(wwwroot),
            "site_id": site_id,
            "trial": True,
            # Used by the per-IP and global trailing-window caps.
            "request_ip": client_ip,
            "created_at": datetime.now(timezone.utc).isoformat(),
        },
        ## "tags": ["moodle-trial"],
    }

    # Isolate trial keys in their own team (and under its budget) when configured.
    if TRIAL_TEAM_ID:
        payload["team_id"] = TRIAL_TEAM_ID

    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.post(
            f"{LITELLM_BASE_URL}/key/generate",
            headers=await _litellm_headers(),
            json=payload,
        )
        resp.raise_for_status()
        return resp.json()["key"]


# ---------------------------------------------------------------------------
# Route
# ---------------------------------------------------------------------------
@router.post("/moodle-trial", response_model=TrialResponse)
async def request_moodle_trial(body: TrialRequest, request: Request) -> TrialResponse:
    """
    Entry point called by the Moodle bookingextension_agent plugin when an admin clicks
    "Start my free trial".

    Steps
    -----
    1. Verify the origin via back-channel challenge.
    2. Reject if this site already has a trial key.
    3. Enforce abuse caps (per-IP and global trailing window).
    4. Create a new LiteLLM virtual key scoped to the trial models.
    5. Return the key + endpoint to Moodle.
    """
    wwwroot_str = str(body.wwwroot).rstrip("/")
    site_id = _site_id(wwwroot_str)
    client_ip = _client_ip(request)

    # -- 1. Verify origin --
    origin_ok = await _verify_origin(wwwroot_str, body.nonce)
    if not origin_ok:
        raise HTTPException(
            status_code=403,
            detail=(
                "Could not verify that this request originates from "
                f"{wwwroot_str}. "
                "Make sure the Moodle site is publicly reachable."
            ),
        )

    # -- 2. Duplicate check --
    existing_key = await _find_existing_key(site_id)
    if existing_key:
        # Return a structured error that Moodle will surface as a friendly message.
        raise HTTPException(
            status_code=409,
            detail=(
                "A trial key has already been issued for this site. "
                "Please check your AI provider settings in Moodle "
                "(Site administration → AI → AI providers)."
            ),
        )

    # -- 3. Abuse caps. On a list failure we fail CLOSED by default (TRIAL_FAIL_CLOSED):
    # failing open would let anyone bypass the per-IP and global caps by inducing a list
    # error. The trial is non-critical, so a brief 503 during a LiteLLM hiccup is preferable
    # to an unbounded key-minting window; set TRIAL_FAIL_CLOSED=0 to restore fail-open.
    trial_keys = await _list_trial_keys()
    if trial_keys is None:
        if TRIAL_FAIL_CLOSED:
            logger.error("Trial key list unavailable; refusing new trial (fail-closed).")
            raise HTTPException(
                status_code=503,
                detail=(
                    "The trial service is temporarily unavailable. Please try again later "
                    "or contact info@wunderbyte.at."
                ),
            )
        logger.error("Trial key list unavailable; issuing anyway (fail-open, caps skipped).")
    else:
        if _count_keys_for_ip(trial_keys, client_ip) >= MAX_KEYS_PER_IP:
            logger.warning("Per-IP trial cap hit for %s", client_ip)
            raise HTTPException(
                status_code=429,
                detail="More than three keys were created for this IP.",
            )
        if _count_active_keys(trial_keys, ACTIVE_WINDOW_DAYS) >= MAX_ACTIVE_KEYS:
            logger.error("Global trial cap (%s) reached.", MAX_ACTIVE_KEYS)
            raise HTTPException(
                status_code=429,
                detail=(
                    "The trial key limit has been reached. Please try again later "
                    "or contact info@wunderbyte.at."
                ),
            )

    # -- 4. Issue a new key --
    try:
        apikey = await _create_trial_key(site_id, wwwroot_str, client_ip)
    except httpx.HTTPStatusError as exc:
        logger.error("LiteLLM key creation failed: %s", exc.response.text)
        # Surface the upstream status + (truncated) LiteLLM message so the calling Moodle can show
        # the real cause in developer-debug mode (e.g. an invalid LITELLM_TRIALCREATE_KEY admin
        # token, a missing/forbidden TRIAL_TEAM_ID, or a trial model alias the proxy does not serve)
        # instead of only a generic "could not be set up" message.
        raise HTTPException(
            status_code=502,
            detail=(
                f"LiteLLM key creation failed (HTTP {exc.response.status_code}): "
                f"{exc.response.text[:300]}"
            ),
        )

    logger.info("Issued trial key for site %s (id=%s)", wwwroot_str, site_id)

    return TrialResponse(
        apikey=apikey,
        endpoint=LITELLM_BASE_URL,
        model=TRIAL_MODEL_ALIAS,
    )
