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
    pip install fastapi httpx pyjwt[crypto]

Environment variables expected
------------------------------
    LITELLM_BASE_URL          – base URL of your LiteLLM instance, e.g. https://llm.wunderbyte.at
    LITELLM_TRIALCREATE_KEY   – LiteLLM admin/management key (Bearer for the management API)
    SHOP_API_SECRET           – shared secret for the legacy bearer fallback (Authorization: Bearer
                                <secret>). Used only when JWT is NOT configured (see below).
    SHOP_JWT_SECRET           – HS256 shared secret the caller signs its JWT with (mod_booking's
                                REST after-booking action, "Sign the request with a JWT"). Set this
                                OR SHOP_JWT_PUBLIC_KEY to require JWT auth on issue/revoke.
    SHOP_JWT_PUBLIC_KEY       – RS256 PEM public key (alternative to the HS256 secret): the caller
                                signs with its private key, we verify with this public key — no
                                shared secret. Provide the PEM inline or via SHOP_JWT_PUBLIC_KEY_FILE.
    SHOP_JWT_PUBLIC_KEY_FILE  – optional path to read the RS256 PEM public key from.
    SHOP_JWT_ISSUER           – optional; if set, the token's "iss" claim must equal it (e.g. the
                                calling Moodle's wwwroot).
    SHOP_JWT_AUDIENCE         – optional; if set, the token's "aud" claim must equal it.
    SHOP_JWT_LEEWAY           – optional, default 60 (seconds) exp/nbf clock-skew tolerance.
    SHOP_JWT_REQUIRE_BODY_HASH – optional, default 1: require a body_sha256 claim matching the raw
                                body, so a captured token cannot be replayed with a different body.
    SHOP_TRUSTED_PROXY_HOPS   – optional, default 1 — reverse proxies in front of this app; used to
                                read the real client IP from X-Forwarded-For without spoofing
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
    Preferred — JWT (RFC 7519), the standards-based, receiver-agnostic scheme mod_booking's REST
    action emits. Configure SHOP_JWT_SECRET (HS256) or SHOP_JWT_PUBLIC_KEY (RS256); the caller
    then sends:
         Authorization: Bearer <jwt>
    We verify the signature, exp/nbf (± SHOP_JWT_LEEWAY), optional iss/aud, and — by default — a
    body_sha256 claim against the raw body, so the bearer/tier cannot be forged or replayed with a
    different body. When JWT is configured the static SHOP_API_SECRET bearer is not accepted.

    Legacy fallback — if no JWT is configured, issue/revoke fall back to a static bearer:
         Authorization: Bearer <SHOP_API_SECRET>
    This has no body binding; prefer JWT. NOTE: /usage is exempt from all of this (unauthenticated
    by design, independently rate-limited and own-keys-scoped).

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

try:
    import jwt as pyjwt  # PyJWT; required only when JWT auth is configured.
except ImportError:  # pragma: no cover - import guard
    pyjwt = None

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


def _env_flag(name: str, default: str = "1") -> bool:
    return os.environ.get(name, default).strip().lower() in ("1", "true", "yes", "on")


# JWT auth for the mutating routes (issue-key / revoke-key), the standards-based scheme
# mod_booking's REST after-booking action emits. Configure an HS256 secret OR an RS256 public
# key to require it; the token then binds the exact body (body_sha256) so a leaked bearer or a
# forged tier is impossible. When neither is set we fall back to the legacy static bearer.
SHOP_JWT_SECRET: str = os.environ.get("SHOP_JWT_SECRET", "")
SHOP_JWT_PUBLIC_KEY: str = os.environ.get("SHOP_JWT_PUBLIC_KEY", "")
_jwt_public_key_file: str = os.environ.get("SHOP_JWT_PUBLIC_KEY_FILE", "").strip()
if _jwt_public_key_file and not SHOP_JWT_PUBLIC_KEY:
    try:
        with open(_jwt_public_key_file, encoding="utf-8") as _fh:
            SHOP_JWT_PUBLIC_KEY = _fh.read()
    except OSError as _exc:
        logger.error("Could not read SHOP_JWT_PUBLIC_KEY_FILE %s: %s", _jwt_public_key_file, _exc)
SHOP_JWT_ISSUER: str = os.environ.get("SHOP_JWT_ISSUER", "").strip()
SHOP_JWT_AUDIENCE: str = os.environ.get("SHOP_JWT_AUDIENCE", "").strip()
SHOP_JWT_LEEWAY: int = int(os.environ.get("SHOP_JWT_LEEWAY", "60"))
SHOP_JWT_REQUIRE_BODY_HASH: bool = _env_flag("SHOP_JWT_REQUIRE_BODY_HASH", "1")


def _jwt_configured() -> bool:
    """True when JWT verification is set up (HS256 secret or RS256 public key present)."""
    return bool(SHOP_JWT_SECRET or SHOP_JWT_PUBLIC_KEY)


# Number of trusted reverse proxies in front of this app. X-Forwarded-For is client-
# appendable, so we trust only the entries our own proxies added (counted from the right).
SHOP_TRUSTED_PROXY_HOPS: int = int(os.environ.get("SHOP_TRUSTED_PROXY_HOPS", "1"))

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
    """Best-effort real client IP for rate limiting, resistant to a forged X-Forwarded-For.

    XFF is client-appendable, so its LEFTMOST entry is spoofable. Each trusted proxy instead
    APPENDS the address it actually saw (nginx default `$proxy_add_x_forwarded_for`), so the
    entry SHOP_TRUSTED_PROXY_HOPS from the RIGHT is the one our own infrastructure added.
    """
    hops = SHOP_TRUSTED_PROXY_HOPS
    xff = request.headers.get("x-forwarded-for", "")
    if xff and hops >= 1:
        parts = [p.strip() for p in xff.split(",") if p.strip()]
        if len(parts) >= hops:
            return parts[-hops]
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


def _bearer_token(request: Request) -> str:
    """Extract the token from an 'Authorization: Bearer <token>' header, or ''."""
    authorization = request.headers.get("authorization", "")
    prefix = "Bearer "
    return authorization[len(prefix):].strip() if authorization.startswith(prefix) else ""


def _verify_jwt(request: Request, raw_body: bytes) -> None:
    """
    Verify the JWT on a mutating shop call (RFC 7519): signature (HS256 secret or RS256 public
    key), exp/nbf, optional iss/aud, and — by default — a body_sha256 claim binding the exact
    body so a captured token cannot be replayed with a different body. Raises on failure.
    """
    if pyjwt is None:
        logger.error("JWT auth is configured but PyJWT is not installed.")
        raise HTTPException(status_code=500, detail="Shop endpoint is not fully configured.")

    token = _bearer_token(request)
    if not token:
        raise HTTPException(status_code=401, detail="Missing bearer JWT.")

    if SHOP_JWT_PUBLIC_KEY:
        algorithms, key = ["RS256"], SHOP_JWT_PUBLIC_KEY
    else:
        algorithms, key = ["HS256"], SHOP_JWT_SECRET

    options = {"require": ["exp", "iat"]}
    decode_kwargs: dict = {
        "algorithms": algorithms,
        "leeway": SHOP_JWT_LEEWAY,
        "options": options,
    }
    # PyJWT validates aud/iss itself when we pass them (and raises on mismatch/absence).
    if SHOP_JWT_AUDIENCE:
        decode_kwargs["audience"] = SHOP_JWT_AUDIENCE
    else:
        options["verify_aud"] = False
    if SHOP_JWT_ISSUER:
        decode_kwargs["issuer"] = SHOP_JWT_ISSUER

    try:
        claims = pyjwt.decode(token, key, **decode_kwargs)
    except pyjwt.InvalidTokenError as exc:
        # One uniform message; details go to the log, not the caller.
        logger.warning("Rejected shop JWT: %s", exc)
        raise HTTPException(status_code=401, detail="Invalid or expired token.")

    if SHOP_JWT_REQUIRE_BODY_HASH:
        expected = hashlib.sha256(raw_body).hexdigest()
        provided = str(claims.get("body_sha256") or "")
        if not provided or not hmac.compare_digest(provided, expected):
            raise HTTPException(status_code=401, detail="Request body does not match the signed token.")


def _require_shop_auth(request: Request, raw_body: bytes) -> None:
    """
    Authenticate a mutating shop call. Preferred: a JWT (signature + exp + body binding) — see
    _verify_jwt. Legacy fallback when no JWT is configured: a static bearer secret (no body
    binding). Raises HTTPException on failure.
    """
    if _jwt_configured():
        _verify_jwt(request, raw_body)
        return

    if not SHOP_API_SECRET:
        logger.error("Neither JWT nor SHOP_API_SECRET is configured; refusing all shop calls.")
        raise HTTPException(status_code=500, detail="Shop endpoint is not configured.")

    authorization = request.headers.get("authorization", "")
    if not hmac.compare_digest(authorization, f"Bearer {SHOP_API_SECRET}"):
        raise HTTPException(status_code=401, detail="Invalid or missing shop authorization.")


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
