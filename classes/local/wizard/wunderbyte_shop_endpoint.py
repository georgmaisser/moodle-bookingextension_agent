"""
Wunderbyte Moodle Shop Key Endpoint
===================================
Issues paid LiteLLM keys for the Wunderbyte shop: subscriptions and one-time
packages, each in sizes S / M / L / XL. Mount this FastAPI router next to the
trial router in your existing LiteLLM / FastAPI application.

This is the *shop* counterpart to wunderbyte_trial_endpoint.py. The trial flow
proves a Moodle origin via a nonce challenge; this flow is server-to-server from
our own shop and is authenticated by a shared secret (plus optional HMAC). Keys
issued here are NOT bound to a site (portable) — the buyer pastes the key into
their Moodle AI provider settings.

Product model
-------------
    Subscription (billed monthly, auto-resets, no expiry; cancel via revoke):
        max_budget per month, budget_duration "1mo" (LiteLLM resets spend every
        period from the purchase date). Cancellation = shop calls /revoke-key,
        which BLOCKS the key (reversible).
    Package (one-time, valid 12 months, no reset):
        max_budget once, duration "365d", no budget_duration.

Default budgets (LiteLLM max_budget = our token cost cap, in the proxy's
pricing currency):
        subscription  S/M/L/XL  = 10 / 20 / 50 / 100   per month
        package       S/M/L/XL  = 100 / 200 / 500 / 1000 once

Requirements
------------
    pip install fastapi httpx

Environment variables expected
------------------------------
    LITELLM_BASE_URL          – base URL of your LiteLLM instance, e.g. https://llm.wunderbyte.at
    LITELLM_TRIALCREATE_KEY   – LiteLLM admin/management key (Bearer for the management API)
    SHOP_API_SECRET           – REQUIRED shared secret; the shop sends it as "Authorization: Bearer <secret>"
    SHOP_HMAC_SECRET          – optional; if set, requests must also carry a valid HMAC signature
                                (see "Shop authentication" below). Strongly recommended.
    SHOP_HMAC_MAX_AGE         – optional, default 300 (seconds) — max age of the signed timestamp
    SHOP_MODELS               – optional, comma-separated model aliases the keys may use; default
                                "wunderbyte-privat,wunderbyte-privat-mini,wunderbyte-embeddings"
                                (must match the Moodle agent actionconfig and exist on the proxy)
    SHOP_PRODUCTS_JSON        – optional JSON overriding the per-(product,size) config. Each entry:
                                  subscription.<SIZE> = {"team_id","max_budget","budget_duration"}
                                  package.<SIZE>      = {"team_id","max_budget","duration"}
                                You MUST at least supply the eight team_ids (one team per product+size);
                                budgets fall back to the defaults above when omitted.

Shop authentication
-------------------
    1. Bearer (required):   Authorization: Bearer <SHOP_API_SECRET>
    2. HMAC (optional, recommended), to stop replay if the bearer ever leaks:
         X-Shop-Timestamp: <unix seconds>
         X-Shop-Signature: hex( HMAC_SHA256(SHOP_HMAC_SECRET, f"{timestamp}.{raw_body}") )
       The server rejects a timestamp older than SHOP_HMAC_MAX_AGE.

Deployment
----------
In your main FastAPI app:

    from wunderbyte_shop_endpoint import router as shop_router
    app.include_router(shop_router, prefix="/api/shop")

The shop then calls:
    POST /api/shop/issue-key    {"product","size","order_id"[,"customer_email","customer_id"]}
    POST /api/shop/revoke-key   {"order_id"}
"""

import hashlib
import hmac
import json
import logging
import os
import re
import time
from datetime import datetime, timezone

import httpx
from fastapi import APIRouter, HTTPException, Request
from fastapi.responses import PlainTextResponse
from pydantic import BaseModel

logger = logging.getLogger(__name__)

router = APIRouter()

# ---------------------------------------------------------------------------
# Configuration (from environment)
# ---------------------------------------------------------------------------
LITELLM_BASE_URL: str = os.environ.get("LITELLM_BASE_URL", "http://localhost:4000").rstrip("/")
LITELLM_TRIALCREATE_KEY: str = os.environ.get("LITELLM_TRIALCREATE_KEY", "")

# Public base URL customers use to reach the proxy (e.g. https://llm.wunderbyte.at).
# LITELLM_BASE_URL is the INTERNAL docker URL for our own management calls and must NOT
# be handed to customers; falls back to it only when SHOP_PUBLIC_ENDPOINT is unset.
SHOP_PUBLIC_ENDPOINT: str = os.environ.get("SHOP_PUBLIC_ENDPOINT", LITELLM_BASE_URL).rstrip("/")

SHOP_API_SECRET: str = os.environ.get("SHOP_API_SECRET", "")
SHOP_HMAC_SECRET: str = os.environ.get("SHOP_HMAC_SECRET", "")
SHOP_HMAC_MAX_AGE: int = int(os.environ.get("SHOP_HMAC_MAX_AGE", "300"))

# Model aliases every shop key may use (same three the trial grants). The Moodle
# agent maps its actions onto these EXACT names; all must exist on the proxy.
SHOP_MODELS: list[str] = [
    m.strip()
    for m in os.environ.get(
        "SHOP_MODELS",
        "wunderbyte-privat,wunderbyte-privat-mini,wunderbyte-embeddings",
    ).split(",")
    if m.strip()
]

VALID_PRODUCTS = ("subscription", "package")
VALID_SIZES = ("S", "M", "L", "XL")

# Per-(product,size) defaults. team_id is intentionally empty: it MUST be supplied
# via SHOP_PRODUCTS_JSON (one LiteLLM team per product+size), otherwise issuing for
# that tier fails fast as a misconfiguration. Budgets are our token-cost cap.
DEFAULT_PRODUCTS: dict[str, dict[str, dict]] = {
    "subscription": {
        "S": {"team_id": "", "max_budget": 10, "budget_duration": "1mo"},
        "M": {"team_id": "", "max_budget": 20, "budget_duration": "1mo"},
        "L": {"team_id": "", "max_budget": 50, "budget_duration": "1mo"},
        "XL": {"team_id": "", "max_budget": 100, "budget_duration": "1mo"},
    },
    "package": {
        "S": {"team_id": "", "max_budget": 100, "duration": "365d"},
        "M": {"team_id": "", "max_budget": 200, "duration": "365d"},
        "L": {"team_id": "", "max_budget": 500, "duration": "365d"},
        "XL": {"team_id": "", "max_budget": 1000, "duration": "365d"},
    },
}


def _load_products() -> dict[str, dict[str, dict]]:
    """
    Merge SHOP_PRODUCTS_JSON (if any) over the built-in defaults. A malformed JSON
    is logged and ignored (defaults stand) so a bad env var cannot take the
    endpoint down — but team_ids would then be empty and issuing 500s loudly.
    """
    products = {p: {s: dict(cfg) for s, cfg in sizes.items()} for p, sizes in DEFAULT_PRODUCTS.items()}
    raw = os.environ.get("SHOP_PRODUCTS_JSON", "").strip()
    if not raw:
        return products
    try:
        override = json.loads(raw)
    except json.JSONDecodeError as exc:
        logger.error("SHOP_PRODUCTS_JSON is not valid JSON, using defaults: %s", exc)
        return products
    for product in VALID_PRODUCTS:
        for size in VALID_SIZES:
            entry = (override.get(product) or {}).get(size)
            if isinstance(entry, dict):
                products[product][size].update(entry)
    return products


PRODUCTS = _load_products()


# ---------------------------------------------------------------------------
# Request / response schemas
# ---------------------------------------------------------------------------
class IssueKeyRequest(BaseModel):
    product: str                       # "subscription" | "package"
    size: str                          # "S" | "M" | "L" | "XL"
    order_id: str                      # shop order/subscription reference (unique)
    customer_email: str | None = None  # metadata only
    customer_id: str | None = None     # metadata only
    # Response shape. Default is the JSON IssueKeyResponse. Set "text" (alias "key"/"plain")
    # to get ONLY the bare key as text/plain, so a caller (e.g. a Moodle after-booking REST
    # action) can drop it straight into an e-mail without parsing JSON.
    response_format: str | None = None


class IssueKeyResponse(BaseModel):
    apikey: str
    endpoint: str
    model: str
    product: str
    size: str
    order_id: str


class RevokeKeyRequest(BaseModel):
    order_id: str
    # Must match the e-mail used at issue time: the key alias is wb-shop-<email>-<order_id>,
    # and the management key can only look a key up by its exact alias. Omit only if the key
    # was issued without an e-mail.
    customer_email: str | None = None


class RevokeKeyResponse(BaseModel):
    order_id: str
    revoked: bool
    found: bool


class UsageRequest(BaseModel):
    apikey: str            # the customer's own key (the credential for its own usage)


class UsageResponse(BaseModel):
    state: str             # "ok" | "unlimited" | "unavailable"
    percent: float | None = None            # % of budget used
    percent_remaining: float | None = None  # % of budget left
    resetat: str | None = None              # ISO timestamp of next budget reset
    expiresat: str | None = None            # ISO timestamp of key expiry


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def _slug(value: str) -> str:
    """Reduce an order id to an alias-safe slug (alnum, dash, underscore)."""
    return re.sub(r"[^A-Za-z0-9_-]", "-", value.strip())[:120]


ALIAS_PREFIX = "wb-shop-"


def _alias(order_id: str, email: str | None = None) -> str:
    """Key alias = the LiteLLM display name AND the lookup key: wb-shop-<email>-<order_id>,
    e.g. "wb-shop-buyer-example.com-233". It is deterministic, so issue-dedupe and revoke
    rebuild the SAME alias from email+order_id and find the key via _find_key_by_alias
    (the management key can query a specific alias but not enumerate all keys). When no
    email is given it falls back to wb-shop-<order_id>; revoke must then also omit it."""
    parts = [ALIAS_PREFIX.rstrip("-")]
    if email and email.strip():
        parts.append(_slug(email))
    parts.append(_slug(order_id))
    return "-".join(p for p in parts if p)


# LiteLLM keys always carry this prefix; validating the shape before any upstream call keeps
# malformed / enumeration noise from ever reaching the master-key /key/info lookup.
LLM_KEY_PREFIX = "sk-"

# The /usage endpoint is intentionally unauthenticated (the key itself is the credential), so
# it must only ever reveal usage for keys WE issued through the trial/shop flows — never act as
# a generic usage oracle for arbitrary keys living on the shared proxy (production keys, other
# teams). Trial keys use the "wunderbyte-privat-" alias, shop keys the "wb-shop-" alias.
OWN_KEY_ALIAS_PREFIXES = ("wb-shop-", "wunderbyte-privat-")

# Lightweight in-process rate limit for the unauthenticated /usage endpoint, to blunt the
# enumeration / DoS amplification of turning each public call into a master-key /key/info.
# Per-process only: behind several workers or replicas the real backstop must be the
# ingress / proxy — this is defense-in-depth, not the sole control.
USAGE_RATE_MAX: int = int(os.environ.get("SHOP_USAGE_RATE_MAX", "30"))
USAGE_RATE_WINDOW: int = int(os.environ.get("SHOP_USAGE_RATE_WINDOW", "60"))
_usage_hits: dict[str, list[float]] = {}


def _looks_like_llm_key(value: str) -> bool:
    """Cheap shape gate: a plausible LiteLLM key, checked before we spend a master-key lookup."""
    return value.startswith(LLM_KEY_PREFIX) and 20 <= len(value) <= 200


def _client_ip(request: Request) -> str:
    """First hop of X-Forwarded-For (the proxy must set and strip it), else the socket peer."""
    xff = request.headers.get("x-forwarded-for", "")
    if xff:
        return xff.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


def _usage_rate_ok(client_ip: str) -> bool:
    """Sliding-window limiter keyed by client IP; memory bounded via an opportunistic sweep."""
    now = time.time()
    window_start = now - USAGE_RATE_WINDOW
    hits = [t for t in _usage_hits.get(client_ip, ()) if t >= window_start]
    if len(hits) >= USAGE_RATE_MAX:
        _usage_hits[client_ip] = hits
        return False
    hits.append(now)
    _usage_hits[client_ip] = hits
    if len(_usage_hits) > 10000:
        stale = [ip for ip, ts in _usage_hits.items() if not any(t >= window_start for t in ts)]
        for ip in stale:
            _usage_hits.pop(ip, None)
    return True


async def _litellm_headers() -> dict:
    return {
        "Authorization": f"Bearer {LITELLM_TRIALCREATE_KEY}",
        "Content-Type": "application/json",
    }


def _require_shop_auth(request: Request, raw_body: bytes) -> None:
    """
    Authenticate a shop call: mandatory bearer secret, plus optional HMAC over
    timestamp + raw body (replay-protected). Raises HTTPException on failure.
    """
    if not SHOP_API_SECRET:
        logger.error("SHOP_API_SECRET is not configured; refusing all shop calls.")
        raise HTTPException(status_code=500, detail="Shop endpoint is not configured.")

    authorization = request.headers.get("authorization", "")
    if not hmac.compare_digest(authorization, f"Bearer {SHOP_API_SECRET}"):
        raise HTTPException(status_code=401, detail="Invalid or missing shop authorization.")

    if not SHOP_HMAC_SECRET:
        return

    timestamp = request.headers.get("x-shop-timestamp", "")
    signature = request.headers.get("x-shop-signature", "")
    if not timestamp or not signature:
        raise HTTPException(status_code=401, detail="Missing request signature.")
    try:
        ts = int(timestamp)
    except ValueError:
        raise HTTPException(status_code=401, detail="Invalid signature timestamp.")
    if abs(time.time() - ts) > SHOP_HMAC_MAX_AGE:
        raise HTTPException(status_code=401, detail="Request signature expired.")

    expected = hmac.new(
        SHOP_HMAC_SECRET.encode(),
        f"{timestamp}.".encode() + raw_body,
        hashlib.sha256,
    ).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail="Bad request signature.")


def _resolve_config(product: str, size: str) -> dict:
    """Resolve and validate the (product,size) tier config. Raises HTTPException."""
    if product not in VALID_PRODUCTS:
        raise HTTPException(status_code=400, detail=f"Unknown product '{product}'.")
    if size not in VALID_SIZES:
        raise HTTPException(status_code=400, detail=f"Unknown size '{size}'.")
    cfg = PRODUCTS[product][size]
    if not str(cfg.get("team_id") or "").strip():
        logger.error("No team_id configured for %s/%s.", product, size)
        raise HTTPException(status_code=500, detail="This product tier is not configured.")
    return cfg


async def _find_key_by_alias(alias: str) -> dict | None:
    """Return the LiteLLM key object for an exact key_alias, or None.

    We filter server-side by key_alias (the management key may query a specific alias
    but is not necessarily allowed to enumerate the whole key store), so issue-dedupe
    and revoke both rebuild the same alias from email+order_id and look it up here."""
    try:
        async with httpx.AsyncClient(timeout=15) as client:
            resp = await client.get(
                f"{LITELLM_BASE_URL}/key/list",
                headers=await _litellm_headers(),
                params={"key_alias": alias, "return_full_object": "true", "size": 1},
            )
            resp.raise_for_status()
            data = resp.json()
    except Exception as exc:
        logger.error("Could not query existing key for alias %s: %s", alias, exc)
        raise HTTPException(status_code=502, detail="Could not reach the key store.")

    keys = data.get("keys", []) if isinstance(data, dict) else data
    for k in keys:
        if isinstance(k, dict) and str(k.get("key_alias") or "") == alias:
            return k
    return None


async def _create_key(
    product: str,
    size: str,
    cfg: dict,
    alias: str,
    order_id: str,
    customer_email: str | None,
    customer_id: str | None,
) -> str:
    """Create the LiteLLM key for a paid tier and return the key string."""
    payload = {
        "key_alias": alias,
        "models": SHOP_MODELS,
        "max_budget": cfg["max_budget"],
        "team_id": cfg["team_id"],
        # Usage is read via the privacy-preserving gateway (POST /api/shop/usage,
        # master-key lookup), NOT by the key itself — so deliberately NO /key/info:
        # a customer must not be able to read the raw euro spend/budget. Keep chat
        # access (llm_api_routes).
        "allowed_routes": ["llm_api_routes"],
        "metadata": {
            "source": "shop",
            "product": product,
            "size": size,
            "order_id": order_id,
            "customer_email": customer_email,
            "customer_id": customer_id,
            "created_at": datetime.now(timezone.utc).isoformat(),
        },
    }

    if product == "subscription":
        # Monthly spend reset from the purchase date; no expiry — cancellation is
        # an explicit revoke (block) call from the shop (no per-payment ping today).
        payload["budget_duration"] = cfg["budget_duration"]
    else:
        # One-time budget that never refills; the key simply expires after the term.
        payload["duration"] = cfg["duration"]

    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.post(
            f"{LITELLM_BASE_URL}/key/generate",
            headers=await _litellm_headers(),
            json=payload,
        )
        resp.raise_for_status()
        return resp.json()["key"]


async def _block_key(key_token: str) -> None:
    """Block (disable) a key. Reversible — a future reactivation can unblock it."""
    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.post(
            f"{LITELLM_BASE_URL}/key/block",
            headers=await _litellm_headers(),
            json={"key": key_token},
        )
        resp.raise_for_status()


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------
@router.post("/issue-key")
async def issue_key(request: Request):
    """
    Issue a paid subscription or package key for a shop order.

    Auth: shop bearer (+ optional HMAC). Idempotency: an order_id that already has
    a key returns 409 (the key value cannot be re-read from LiteLLM, so we never
    silently mint a second key for the same order — store the key from the first
    successful response; to re-issue, revoke first).
    """
    raw_body = await request.body()
    _require_shop_auth(request, raw_body)

    try:
        body = IssueKeyRequest(**json.loads(raw_body or b"{}"))
    except (json.JSONDecodeError, TypeError, ValueError) as exc:
        raise HTTPException(status_code=400, detail=f"Invalid request body: {exc}")

    order_id = body.order_id.strip()
    if not order_id:
        raise HTTPException(status_code=400, detail="order_id is required.")

    cfg = _resolve_config(body.product, body.size)
    alias = _alias(order_id, body.customer_email)

    if await _find_key_by_alias(alias) is not None:
        raise HTTPException(
            status_code=409,
            detail=(
                f"A key has already been issued for order '{order_id}'. "
                "Use the key from the original response, or revoke it before re-issuing."
            ),
        )

    try:
        apikey = await _create_key(
            body.product, body.size, cfg, alias, order_id, body.customer_email, body.customer_id
        )
    except httpx.HTTPStatusError as exc:
        logger.error("LiteLLM key creation failed for %s: %s", alias, exc.response.text)
        raise HTTPException(status_code=502, detail="Failed to create the key in LiteLLM.")

    logger.info("Issued %s/%s key for order %s", body.product, body.size, order_id)

    # When the caller asked for the bare key, return only the key as text/plain so it can
    # be inserted directly into a message (no JSON parsing on the caller side).
    if (body.response_format or "").strip().lower() in ("text", "key", "plain"):
        return PlainTextResponse(apikey)

    return IssueKeyResponse(
        apikey=apikey,
        endpoint=SHOP_PUBLIC_ENDPOINT,
        model=SHOP_MODELS[0] if SHOP_MODELS else "",
        product=body.product,
        size=body.size,
        order_id=order_id,
    )


@router.post("/revoke-key", response_model=RevokeKeyResponse)
async def revoke_key(request: Request) -> RevokeKeyResponse:
    """
    Revoke (block) the key for a shop order. Idempotent: an order with no key
    returns found=false, revoked=true (nothing to do) with HTTP 200.
    """
    raw_body = await request.body()
    _require_shop_auth(request, raw_body)

    try:
        body = RevokeKeyRequest(**json.loads(raw_body or b"{}"))
    except (json.JSONDecodeError, TypeError, ValueError) as exc:
        raise HTTPException(status_code=400, detail=f"Invalid request body: {exc}")

    order_id = body.order_id.strip()
    if not order_id:
        raise HTTPException(status_code=400, detail="order_id is required.")

    alias = _alias(order_id, body.customer_email)
    key = await _find_key_by_alias(alias)
    if key is None:
        return RevokeKeyResponse(order_id=order_id, revoked=True, found=False)

    # LiteLLM identifies the key by its token hash on management calls.
    key_token = str(key.get("token") or key.get("key") or "")
    if not key_token:
        logger.error("Found key for order %s but no token to block it.", order_id)
        raise HTTPException(status_code=502, detail="Could not resolve the key to revoke.")

    try:
        await _block_key(key_token)
    except httpx.HTTPStatusError as exc:
        logger.error("LiteLLM key block failed for order %s: %s", order_id, exc.response.text)
        raise HTTPException(status_code=502, detail="Failed to revoke the key in LiteLLM.")

    logger.info("Revoked (blocked) key for order %s", order_id)
    return RevokeKeyResponse(order_id=order_id, revoked=True, found=True)


@router.post("/usage", response_model=UsageResponse)
async def usage(request: Request) -> UsageResponse:
    """
    Privacy-preserving usage lookup. The customer sends their OWN key; the gateway
    looks it up with the MASTER key and returns ONLY a percentage + reset/expiry —
    never the euro spend/budget. Combined with issuing keys WITHOUT the /key/info
    route, a customer can no longer read the raw amounts directly.

    Auth is the key itself (knowing a key entitles you to its own usage %); no shop
    secret required, so a customer's Moodle (which holds the key, not the secret)
    can call it. Because it is unauthenticated, it is deliberately narrow: it is
    rate-limited per client IP, rejects anything that is not a well-formed key before
    spending a master-key lookup, and only ever returns usage for keys WE issued
    (trial/shop alias prefixes) — never usage of unrelated keys on the shared proxy.
    """
    if not _usage_rate_ok(_client_ip(request)):
        raise HTTPException(status_code=429, detail="Too many usage requests. Please retry shortly.")

    raw_body = await request.body()
    try:
        body = UsageRequest(**json.loads(raw_body or b"{}"))
    except (json.JSONDecodeError, TypeError, ValueError) as exc:
        raise HTTPException(status_code=400, detail=f"Invalid request body: {exc}")

    key = body.apikey.strip()
    if not _looks_like_llm_key(key):
        # Don't spend a master-key lookup on an empty/malformed/guessed value, and don't
        # signal the difference — behave exactly like an unknown key.
        return UsageResponse(state="unavailable")

    try:
        async with httpx.AsyncClient(timeout=15) as client:
            resp = await client.get(
                f"{LITELLM_BASE_URL}/key/info",
                headers=await _litellm_headers(),
                params={"key": key},
            )
    except Exception as exc:
        logger.warning("Usage lookup failed: %s", exc)
        return UsageResponse(state="unavailable")

    if resp.status_code != 200:
        return UsageResponse(state="unavailable")

    data = resp.json()
    info = data.get("info") if isinstance(data, dict) else None
    if not isinstance(info, dict):
        info = data if isinstance(data, dict) else {}

    # Only reveal usage for keys we issued (trial/shop). Anything else on the shared proxy
    # (production keys, other teams' keys) is treated as unknown, so a leaked/guessed key
    # string cannot turn this endpoint into a generic usage oracle for the whole proxy.
    alias = str(info.get("key_alias") or "")
    if not alias.startswith(OWN_KEY_ALIAS_PREFIXES):
        return UsageResponse(state="unavailable")

    max_budget = info.get("max_budget")
    spend = info.get("spend") or 0
    resetat = info.get("budget_reset_at")
    expiresat = info.get("expires")

    # No cap -> unlimited; still expose the timing.
    if not max_budget:
        return UsageResponse(state="unlimited", resetat=resetat, expiresat=expiresat)

    try:
        pct = max(0.0, min(100.0, round(float(spend) / float(max_budget) * 100, 1)))
    except (TypeError, ValueError, ZeroDivisionError):
        return UsageResponse(state="unavailable")

    return UsageResponse(
        state="ok",
        percent=pct,
        percent_remaining=round(100.0 - pct, 1),
        resetat=resetat,
        expiresat=expiresat,
    )
