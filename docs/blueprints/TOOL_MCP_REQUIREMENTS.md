# Requirements: `tool_mcp` — Moodle MCP server with full OAuth 2.1

Status: requirements specification (2026-07-08). Companion to
`MCP_SKILL_EXPOSURE.md` (phases 1–2, the facade this plugin will transport)
and its implementation plan. This document defines the standalone Wunderbyte
plugin that fully satisfies remote MCP requirements — including claude.ai
custom connectors — without any external gateway or IdP.

## 1. Purpose and positioning

A generic Moodle **admin tool plugin `tool_mcp`** that turns any Moodle site
into a remote MCP server:

- **MCP transport**: streamable-HTTP endpoint speaking JSON-RPC 2.0.
- **OAuth 2.1 authorization server**: built in, so MCP clients that demand
  OAuth (claude.ai custom connectors, Claude Desktop remote servers) connect
  with nothing but the plugin installed. "Connect your Moodle to claude.ai in
  five minutes" is the product claim.
- **Generic, not agent-specific**: tools come from two sources — auto-mapped
  Moodle external functions and a **dynamic tool-provider hook** that other
  plugins implement. `bookingextension_agent`/`local_wizard` uses that hook to
  publish its skills as first-class per-skill MCP tools (with the schemas from
  `mcp_tool_catalog_service`), replacing the stdio bridge for remote use.

Separate plugin, separate repo, own release cycle. No dependency on
mod_booking or the agent; the agent integrates *optionally* via the hook.

## 2. Functional requirements

### 2.1 MCP protocol (FR-MCP)

- FR-MCP-1: Streamable-HTTP endpoint (`/admin/tool/mcp/server.php` or a
  routed pretty URL) accepting JSON-RPC 2.0 POST; implements `initialize`,
  `notifications/initialized`, `ping`, `tools/list`, `tools/call`.
- FR-MCP-2: Protocol version negotiation for at least `2025-03-26` and
  `2025-06-18`; reject unknown versions per spec.
- FR-MCP-3: Session handling via `Mcp-Session-Id` header (issue on
  initialize, validate on subsequent calls, expire server-side).
- FR-MCP-4: `Origin` header validation and HTTPS-only operation (refuse plain
  HTTP unless a developer debug flag is set).
- FR-MCP-5: Tool results support `content` (text blocks) and
  `structuredContent`; errors are reported as `isError` tool results (not
  JSON-RPC transport errors) when the tool itself fails.
- FR-MCP-6 (should): SSE response streaming for long-running calls.
  May ship in a later minor release; simple request/response is acceptable
  for v1 (Claude clients tolerate it).
- FR-MCP-7 (later, explicitly out of v1): resources, prompts, sampling,
  elicitation.

### 2.2 Tool sources (FR-TOOL)

- FR-TOOL-1: **External-function mapping** — expose the functions of one or
  more admin-selected web service definitions as MCP tools, converting
  `external_function_parameters` (external_value / single / multiple
  structure) to JSON Schema (feature parity with `webservice_mcp`, which
  serves as prior art).
- FR-TOOL-2: **Dynamic tool-provider hook** — a Moodle hook (`\tool_mcp\hook\
  collect_tools` + `execute_tool`) any plugin can listen to, contributing
  tools as data (`name`, `description`, `inputSchema`, `annotations`) plus a
  dispatcher callback. Tool listing MUST be per-user: the hook receives the
  authenticated userid and context so providers can filter (the agent filters
  via `skill_executability_evaluator`).
- FR-TOOL-3: Per-tool admin enable/disable UI; disabled tools disappear from
  `tools/list` and hard-fail on `tools/call`.
- FR-TOOL-4: Tool names namespaced per source to avoid collisions
  (hook providers own their prefix, e.g. `course_update_activity`).
- FR-TOOL-5: All tool calls execute **as the authenticated Moodle user**
  (capability checks apply); the plugin never elevates.

### 2.3 OAuth 2.1 authorization server (FR-OAUTH)

- FR-OAUTH-1: Endpoints — `/authorize`, `/token`, `/register` (dynamic client
  registration, RFC 7591), `/revoke` (RFC 7009), plus discovery documents
  `/.well-known/oauth-authorization-server` (RFC 8414) and
  `/.well-known/oauth-protected-resource` (RFC 9728) pointing at the MCP
  endpoint as the resource.
- FR-OAUTH-2: Authorization-code grant **only**, PKCE (S256) mandatory, no
  implicit/password grants, exact-match redirect URI validation, single-use
  short-lived auth codes (≤ 60 s), `state` passthrough.
- FR-OAUTH-3: Resource indicators (RFC 8707) accepted and bound into issued
  tokens (2025-06 MCP spec requirement).
- FR-OAUTH-4: Opaque access tokens (DB-backed, default TTL 1 h) mapped to
  {Moodle userid, clientid, scopes}; refresh tokens with rotation and
  reuse detection (revoke family on replay); revocation endpoint honours
  both token types.
- FR-OAUTH-5: **Authentication = Moodle login.** The `/authorize` endpoint
  runs `require_login()` — whatever the site uses (manual, LDAP, SAML SP,
  OIDC, MFA) is inherited. No own credential store, ever.
- FR-OAUTH-6: Consent screen after login: shows client name, requested
  scopes, and the tool list the grant unlocks; consent persisted per
  user+client; "remember" optional per admin policy.
- FR-OAUTH-7: User self-service page (profile → "Connected MCP apps"):
  list active grants/tokens, revoke individually.
- FR-OAUTH-8: DCR can be disabled by the admin; manual client registration UI
  (admin creates client, gets client id/secret) as the alternative. Default:
  DCR **on** but rate-limited and quota-capped (claude.ai needs it for
  friction-free setup).
- FR-OAUTH-9: Scopes v1: `mcp:read` (read-only tools) and `mcp:write`
  (mutating tools); hook providers label their tools accordingly (the agent
  maps R0 → read, R1+ → write). Token without `mcp:write` never lists or
  executes write tools.
- FR-OAUTH-10: Library: vendored **`league/oauth2-server`** (declared in
  `thirdpartylibs.xml`); we implement Moodle-side repositories
  (clients/codes/tokens/scopes) and the DCR endpoint (not covered by the
  library). No hand-rolled crypto/grant logic.

### 2.4 Administration & governance (FR-ADMIN)

- FR-ADMIN-1: Master switch (default off) + settings: token TTLs, DCR
  on/off + quota, allowed tool sources, rate limits, require-consent policy.
- FR-ADMIN-2: Capability `tool/mcp:connect` gates who may complete the OAuth
  flow (default: not granted by archetype — explicit admin decision), plus
  `tool/mcp:manageclients` for the client admin UI.
- FR-ADMIN-3: Rate limiting: per-user tool-call limit (default 60/min) and
  per-IP limits on `/token` + `/register`; violations logged.
- FR-ADMIN-4: Audit events (Moodle events): client registered, consent given,
  token issued/refused/revoked, tool called (with tool name, status) —
  visible in the standard log report.
- FR-ADMIN-5: Scheduled task: purge expired codes/tokens/sessions.

## 3. Non-functional requirements

- NFR-1: Moodle 4.5–5.x, PHP 8.1+; no server components beyond the plugin.
- NFR-2: GDPR: privacy provider covering clients/consents/tokens/audit
  (export + delete); tokens are personal data.
- NFR-3: Code quality gates: `phpcs --standard=moodle` 0/0,
  moodle-plugin-ci `phpdoc` clean, PHPUnit coverage for every OAuth flow
  branch (happy path + each abuse path), Behat for consent/self-service UI.
- NFR-4: **External security review before first production release** — the
  AS is account-takeover-critical. Threat model documented in-repo
  (redirect hijack, code replay, PKCE downgrade, token leakage, DCR abuse,
  mix-up attacks).
- NFR-5: Performance: token validation ≤ 1 extra DB read (cached via MUC);
  tools/list cached per user+token.
- NFR-6: i18n: all UI strings via lang packs (en source, de shipped).
- NFR-7: License GPL-3.0 (moodle.org directory requirement).

## 4. Interoperability / acceptance criteria

- AC-1: **claude.ai custom connector** connects with URL only: DCR →
  authorize (Moodle login + consent) → tools listed → read tool call
  succeeds. No manual client configuration.
- AC-2: Claude Code (`claude mcp add --transport http`) and Claude API MCP
  connector work with the same endpoint via OAuth; Claude Desktop remote
  server likewise.
- AC-3: MCP Inspector protocol validation passes for `initialize`,
  `tools/list`, `tools/call` on both supported protocol versions.
- AC-4: With `bookingextension_agent`/`local_wizard` installed and its hook
  provider enabled, the agent skills appear as individual tools with their
  schemas, and an R2 skill runs the two-call confirm flow end-to-end from
  claude.ai (preview → human confirms in chat → confirm tool call →
  mutation verified).
- AC-5: Revoking a grant in the self-service UI kills the connector's access
  within one minute (cache TTL).
- AC-6: A third-party MCP client (e.g. Inspector CLI or Cursor) can connect
  using manual client registration with DCR disabled.

## 5. Explicit non-goals (v1)

- No SAML/LTI identity flows (Moodle login covers SSO indirectly).
- No resources/prompts/sampling/elicitation MCP features.
- No agent/LLM logic of any kind — this plugin is transport + auth + tool
  registry only. The wizard's gates (capabilities, licence, guard tokens,
  confirm) stay entirely in the wizard plugins.
- No multi-tenant token issuing for other sites.

## 6. Milestones & rough effort

| Milestone | Content | Effort |
|---|---|---|
| M1 | Plugin skeleton, MCP endpoint, external-function mapping, bearer-token auth via existing Moodle WS tokens (no OAuth yet) | ~1 week |
| M2 | OAuth 2.1 AS (league/oauth2-server, endpoints, consent, self-service, DCR), audit events, rate limits | ~2 weeks |
| M3 | Dynamic tool-provider hook + agent-side provider, scopes, per-tool governance | ~3–4 days |
| M4 | Hardening: threat-model tests, privacy provider, Behat, external security review, moodle.org release prep | ~1 week + review latency |

M1 alone already replaces the stdio bridge for header-capable clients; M2
unlocks claude.ai. The agent-side work in M3 is small because the facade
(phases 1–2 of `MCP_SKILL_EXPOSURE_IMPLEMENTATION_PLAN.md`) already exposes
catalog + dispatch + confirm as services.

## 7. Open questions for George

1. Plugin name/branding: `tool_mcp` (generic, moodle.org-friendly) vs. a
   Wunderbyte-branded `tool_wbmcp`? A generic name maximises adoption; the
   directory does not yet have an OAuth-capable MCP server.
2. Publish free on moodle.org (adoption play, agent as the paid layer) vs.
   keep as part of the PRO offering?
3. Should M1 reuse/credit `webservice_mcp` (GPL) for the schema-conversion
   code instead of re-implementing?
