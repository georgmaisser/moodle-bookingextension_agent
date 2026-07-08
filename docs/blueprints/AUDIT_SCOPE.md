# Security audit scope — tool_oauthmcp (OAuth 2.1 AS + MCP server)

Status: audit scope / checklist (2026-07-08). Companion to `TOOL_MCP_REQUIREMENTS.md`
(what the plugin must do) and the plugin's own `THREAT_MODEL.md` (17 attacker stories,
each mapped to the test that pins it). This document is the **systematic surface** an
external reviewer works through, and the scope brief you hand the auditor.

**In scope:** the integration — the OAuth 2.1 authorization server endpoints, the
streamable-HTTP MCP transport, the Moodle bearer→`$USER` seam, the admin/self-service
UIs, token/secret handling and deployment.
**Out of scope:** the vendored `league/oauth2-server ^9.4` grant/crypto core itself (broadly
reviewed upstream) — audit how it is *configured and wired*, not its internals.

**Highest-leverage areas** (risk beyond a standard OAuth server): §3 anonymous DCR, §8 the
bearer→`$USER` seam, §12 MCP confused-deputy.

Standards to check against: RFC 6749 + **RFC 9700** (OAuth Security BCP), RFC 7591 (DCR),
RFC 7009 (Revocation), RFC 8414 (AS Metadata), RFC 8707 (Resource Indicators), RFC 9728
(Protected Resource Metadata), and the MCP authorization spec (2025-06-18).

---

## 1. Authorization endpoint (`/authorize`)
- [ ] Redirect-URI **exact** string match; no wildcard/prefix/substring; registration required; no open redirect; loopback/custom-scheme handled safely
- [ ] `response_type` = `code` only; implicit/token rejected
- [ ] PKCE `S256` enforced; `plain` rejected; `code_challenge` validated; no downgrade
- [ ] `state` passthrough; CSRF defended even without state (PKCE)
- [ ] `scope` restricted to registered/allowed values; defined default; no silent widening
- [ ] `resource`/audience (RFC 8707) validated and bound into the token
- [ ] Login binding: `require_login()`; no session fixation; no consent without auth
- [ ] Consent not skippable; per user+client; no consent confusion; "remember" policy sound
- [ ] Error paths: no info leak; correct `error`/`error_description`; no redirect to unvalidated URI on error

## 2. Token endpoint (`/token`)
- [ ] `grant_type` limited to `authorization_code` + `refresh_token`
- [ ] Auth code single-use, TTL ≤ 60 s, bound to client_id + redirect_uri + PKCE verifier; replay/injection defended
- [ ] PKCE verifier checked against challenge (constant-time)
- [ ] Access token opaque, high entropy, hashed at rest, audience/resource bound
- [ ] Refresh rotation on every use; reuse-detection revokes the whole family; TTL; client bound
- [ ] Public-client handling: no secret required, PKCE substitutes; confidential clients separated
- [ ] No token/code leaks in errors; no timing side channel

## 3. Dynamic client registration (`/register`, RFC 7591)
- [ ] Anonymous registration rate-limited + quota-capped (client-flood / DB-exhaustion DoS)
- [ ] redirect_uri validated at registration (no later open redirect)
- [ ] No privilege escalation via metadata (client cannot grant itself arbitrary scope/grant_types/auth method)
- [ ] Metadata sanitized: `client_name` (XSS on consent/admin screen), `logo_uri`/`jwks_uri` (SSRF if fetched)
- [ ] Registration-access-token (if update/delete supported) correctly bound

## 4. Revocation (`/revoke`, RFC 7009)
- [ ] Revocation immediate, incl. cache purge (no revoked token surviving the validation cache)
- [ ] Access **and** refresh revocable; unknown token → 200 without info leak
- [ ] Only the owner/registering client may revoke (IDOR)

## 5. Discovery & metadata
- [ ] `/.well-known/oauth-authorization-server` (RFC 8414): issuer = wwwroot, correct endpoints, no wrong URLs
- [ ] `/.well-known/oauth-protected-resource` (RFC 9728): correct resource metadata
- [ ] MCP endpoint `WWW-Authenticate` challenge carries `resource_metadata` (RFC 9728)
- [ ] Issuer consistency against mix-up; all HTTPS

## 6. Token & secret handling
- [ ] CSPRNG (`random_bytes`) for tokens/codes/session-ids — never `rand()`/`uniqid()`
- [ ] Hash at rest (SHA-256+); comparison via `hash_equals` (constant-time)
- [ ] TTL hygiene: codes very short, access short, refresh longer; expired records purged
- [ ] No token in logs, URLs, query strings, referrer, errors
- [ ] Cache coherence: the 60 s validation cache cannot outlive revocation (regression anchor)

## 7. MCP transport
- [ ] Bearer validated on every call; expired/revoked → 401 with correct challenge
- [ ] `Mcp-Session-Id`: high entropy, user-bound, TTL, no cross-user adoption, no fixation
- [ ] Origin-header validation (DNS rebinding); HTTPS enforcement; method whitelist
- [ ] JSON-RPC input validation; batch limits; no injection; version negotiation robust
- [ ] Per-tool scope enforcement (`mcp:read` vs `mcp:write`); tool governance applied
- [ ] Endpoint rate limit; idempotency/replay defence

## 8. Moodle integration seam
- [ ] Bearer → `$USER` mapping correct: no swap, no elevation; tools run as the authenticated user, never admin/system
- [ ] Capability checks (`tool/oauthmcp:connect` + per-tool caps) evaluated in the real user's context
- [ ] WS-token path (if parallel): service binding enforced (a foreign-service token must not pass)
- [ ] Session handling (`write_close`, `WS_SERVER`) without pollution; sesskey bypass only where deliberate and safe
- [ ] No authorization trust in client-supplied values

## 9. Web-app security of the UIs (consent, self-service, client-admin, diagnostics)
- [ ] XSS: every output escaped — especially `client_name`, `redirect_uri`, scope display on the consent screen (attacker-controlled DCR data)
- [ ] CSRF: `sesskey` on every state-changing action (consent approve, self-service revoke, client enable/disable)
- [ ] IDOR / access control: user A cannot see or revoke user B's tokens/consents/clients; admin pages capability-gated
- [ ] SQL injection: all DB access parameterised via `$DB`
- [ ] Information disclosure: no stack traces/debug; diagnostics page not over-sharing

## 10. Cryptography & vendored library
- [ ] PKCE S256 correct; no weak algorithms
- [ ] `league/oauth2-server ^9.4` + deps checked for known CVEs; version current; grant config not unsafely overridden
- [ ] Dependency audit of vendored composer packages

## 11. Deployment & infrastructure
- [ ] HTTPS-only at the endpoint (dev flag not in prod)
- [ ] Authorization-header passing documented (Apache/php-fpm strip → SetEnvIf), else silent 401
- [ ] Security headers; rate-limit robustness (cache- vs DB-based, multi-node behaviour)
- [ ] `purge_expired` task runs reliably and clears all expired records (no residue)

## 12. Attack classes as a whole (OAuth Security BCP / RFC 9700 + MCP)
- [ ] Authorization code injection
- [ ] CSRF via missing state/PKCE
- [ ] Mix-up attack (multiple ASes)
- [ ] Confused deputy (RFC 8707 binding)
- [ ] Redirect-URI manipulation
- [ ] Token substitution / leakage
- [ ] Refresh replay
- [ ] PKCE downgrade
- [ ] Scope escalation
- [ ] Client impersonation
- [ ] Consent phishing
- [ ] DCR abuse
- [ ] Session hijack
- [ ] MCP confused deputy (tool executed in wrong user context)
- [ ] Over-broad tool exposure
- [ ] Tool "rug-pull" (definition changes after consent)

## 13. Privacy, logging, governance
- [ ] Privacy provider: export/delete across all 6 PII tables complete, no forgotten column, no PII leak
- [ ] Audit logging of all security-relevant events (token issued/revoked, consent, client registered, **failed auth**), tamper-evident
- [ ] Admin controls: block client, revoke token/family, global kill switch (`enabled`)
- [ ] Coverage documented against RFC 9700 and RFC 7591/7009/8414/8707/9728 + the MCP auth spec
