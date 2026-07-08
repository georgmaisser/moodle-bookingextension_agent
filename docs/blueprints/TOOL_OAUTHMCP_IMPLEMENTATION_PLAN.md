# Implementation plan: `tool_oauthmcp` — Moodle MCP server with OAuth 2.1

Status: implementation plan (2026-07-08), based on `TOOL_MCP_REQUIREMENTS.md`.
Companions: `MCP_SKILL_EXPOSURE.md` (feasibility) and
`MCP_SKILL_EXPOSURE_IMPLEMENTATION_PLAN.md` (agent-side facade; phase 1 is
committed on `SOFABOOKING` — `f39271a`, `9d65adb`, `445f776` — phase 2/confirm
is still open and is a dependency of WP-C4 below).

Requirement IDs (FR-MCP-*, FR-TOOL-*, FR-OAUTH-*, FR-ADMIN-*, NFR-*, AC-*)
refer to `TOOL_MCP_REQUIREMENTS.md`.

---

## 0. Binding design constraints

1. **Standalone plugin, standalone repo.** `admin/tool/oauthmcp`, own repo
   (`wunderbyte-gmbh/moodle-tool_oauthmcp`), own release cycle, GPL-3.0.
   Zero dependency on mod_booking or the agent; the agent integrates via the
   hook (phase C) and nothing else. No `require_once` across plugin borders.
2. **Moodle 4.5–5.x, PHP 8.1+ target** (NFR-1). Consequence: **no core
   router / `.well-known` routing dependency** — the router exists in 5.x but
   not usably for plugins in 4.5, so all endpoints are plain entry scripts
   under `admin/tool/oauthmcp/`. Root-level `/.well-known/*` URLs are
   delivered via documented webserver rewrites (see WP-B8) — there is no
   portable in-Moodle alternative.
3. **Thin entry scripts, testable classes.** Every endpoint script is ≤ ~30
   lines of glue: build a PSR-7 request (`GuzzleHttp\Psr7\ServerRequest::
   fromGlobals()` — Guzzle + `psr/http-message` + `psr/http-factory` are
   vendored in Moodle core ≥ 4.2, verified in `lib/guzzlehttp/`, `lib/psr/`),
   hand it to a handler class, emit the PSR-7 response. All protocol and
   OAuth logic lives in `classes/local/` and is PHPUnit-drivable without HTTP.
4. **`league/oauth2-server` for all grant/crypto logic** (FR-OAUTH-10). We
   write repositories, the DCR endpoint, the opaque-token response type and
   the consent UI — never grant or PKCE logic ourselves.
5. **Execution always as the authenticated user** (FR-TOOL-5). The auth layer
   sets `$USER` via `\core\session\manager::set_user()`; no service account,
   no elevation, all capability checks run downstream as normal.
6. **Schema conversion re-implemented** (requirements decision 3);
   `webservice_mcp` is credited as prior art in the file docblock of the
   converter, no code copied.
7. **Quality gates** (NFR-3): `phpcs --standard=moodle` 0/0, moodle-plugin-ci
   `phpdoc` clean (no commas inside `@param` generics — use `object[]`),
   PHPUnit for every OAuth branch, Behat for consent + self-service.
8. **Conventions:** English commits, path-limited `git commit -- …`,
   separate `Version (YYYYMMDDXX)` bump commits.

Non-goals of v1 restated (requirements §5): no SAML/LTI flows, no MCP
resources/prompts/sampling/elicitation, no agent/LLM logic, no multi-tenant
issuing. SSE streaming (FR-MCP-6) is deferred to a minor release; `server.php`
answers `GET` with `405` in v1.

---

## 1. Plugin skeleton and repository layout (WP-0)

```
admin/tool/oauthmcp/
├── version.php                  # component 'tool_oauthmcp', requires ≥ 2024100100 (4.5)
├── settings.php                 # admin category + pages (WP-A7)
├── server.php                   # MCP streamable-HTTP endpoint (WP-A2)
├── oauth/
│   ├── authorize.php            # WP-B4
│   ├── token.php                # WP-B5
│   ├── register.php             # WP-B6
│   ├── revoke.php               # WP-B7
│   ├── asmeta.php               # RFC 8414 AS metadata document (WP-B8)
│   └── prm.php                  # RFC 9728 protected-resource metadata (WP-B8)
├── userapps.php                 # self-service "Connected MCP apps" (WP-B10)
├── clients.php / client_edit.php# admin client management (WP-B10)
├── tools.php                    # per-tool governance page (WP-A7)
├── diagnostics.php              # connectivity self-check (WP-B8)
├── db/
│   ├── install.xml              # WP-A1 + WP-B2
│   ├── access.php               # capabilities (WP-A3)
│   ├── hooks.php                # hook *definitions* discovery (phase C)
│   ├── tasks.php                # purge task (WP-B9)
│   └── caches.php               # MUC definitions (WP-A1/WP-B7)
├── classes/
│   ├── local/mcp/               # JSON-RPC + MCP protocol (WP-A2, WP-A4)
│   ├── local/registry/          # tool sources + governance (WP-A5, WP-A6)
│   ├── local/oauth/             # repositories, entities, response type (WP-B3)
│   ├── local/auth/              # bearer validation strategies (WP-A3, WP-B7)
│   ├── local/ratelimit/         # sliding-window limiter (WP-B9)
│   ├── hook/                    # collect_tools / execute_tool (WP-C1)
│   ├── event/                   # audit events (WP-A8, WP-B9)
│   ├── task/purge_expired.php
│   ├── form/consent_form.php, client_form.php
│   └── privacy/provider.php     # WP-D2
├── lang/en/tool_oauthmcp.php    # + de
├── .well-known-snippets/        # apache/nginx rewrite snippets shipped as docs
├── thirdpartylibs.xml
├── vendor-oauth2/               # vendored league/oauth2-server + deps (WP-B1)
├── THREAT_MODEL.md              # WP-D1
└── tests/                       # phpunit + behat
```

Repo bootstrap: `main` branch, moodle-plugin-ci GitHub workflow with matrix
{Moodle 4.5, 5.0, 5.1-dev} × {PostgreSQL, MariaDB} × {PHP 8.1, 8.2, 8.3}
(PHP column trimmed per supported combination), running phplint, phpcs,
phpdoc, mustache, phpunit, behat.

---

## Phase A (= milestone M1) — MCP endpoint, external-function tools, WS-token bearer

Outcome: header-capable MCP clients (Claude Code `--transport http --header`,
API MCP connector, Inspector) work against a live site with a Moodle WS token.
Replaces the stdio bridge for those clients.

### WP-A1 — DB schema v1 + caches

`db/install.xml` (phase-A tables; `id` autoincrement + standard keys implied):

- **`tool_oauthmcp_session`** — MCP sessions (FR-MCP-3):
  `sid CHAR(64) NOT NULL UNIQUE` (128-bit random hex), `userid BIGINT`,
  `authmode CHAR(16)` (`wstoken`|`oauth`), `tokenid BIGINT NULL` (FK to
  access-token row in oauth mode — session dies with the token),
  `protocolversion CHAR(16)`, `timecreated BIGINT`, `lastseen BIGINT`.
  Index on `lastseen` (purge), `userid`.
- **`tool_oauthmcp_tool`** — per-tool governance (FR-TOOL-3):
  `source CHAR(32)` (`extfunc`|hook component name), `toolname CHAR(128)`,
  `enabled TINYINT DEFAULT 1`, `readonly TINYINT DEFAULT 0` (admin-set for
  extfunc tools; hook tools carry their own scope label and ignore this),
  `timemodified BIGINT`. Unique index `(source, toolname)`.

`db/caches.php`: `sessions` (application, ttl 3600, through-cache over the
session table), `toollist` (application, ttl 60, key
`{userid}:{contextid}:{scopehash}` — NFR-5), `ratelimit` (application, ttl
120). Cache invalidation event for `toollist` on governance changes.

### WP-A2 — JSON-RPC layer + `server.php`

`classes/local/mcp/json_rpc.php` — pure functions/value objects:

- Parse body → `rpc_request` (`jsonrpc`, `id`, `method`, `params`); accept a
  JSON array of requests when the negotiated protocol version is
  `2025-03-26` (batching removed in `2025-06-18` — reject arrays there with
  `-32600`).
- Error factory for the reserved codes: `-32700` parse, `-32600` invalid
  request, `-32601` method not found, `-32602` invalid params, `-32603`
  internal. **Tool failures never use these** — they become `isError: true`
  tool results (FR-MCP-5); JSON-RPC errors are reserved for protocol faults.
- Notifications (no `id`) get HTTP `202 Accepted`, empty body.

`classes/local/mcp/mcp_http_handler.php` — PSR-7 in/out, orchestrates:

1. **Transport guards** (FR-MCP-4): refuse non-HTTPS unless
   `$CFG->debugallowmcphttp` (developer flag, documented as dev-only);
   validate `Origin` header when present against `$CFG->wwwroot` + an
   admin-configurable allowlist (`alloworigins`, newline-separated) —
   mismatch → `403`. `POST` only; `GET` → `405` (SSE deferred); `DELETE`
   with valid `Mcp-Session-Id` → terminate session, `204`.
2. **Auth** via the strategy chain (WP-A3) → authenticated userid + scope
   set, else `401` + `WWW-Authenticate` (WP-B8 wires the full RFC 9728
   header; in phase A the header carries only `Bearer error="invalid_token"`).
3. **Session** (FR-MCP-3): `initialize` issues a fresh `Mcp-Session-Id`
   (random 32 bytes hex) bound to the userid+authmode; every other method
   requires a valid, unexpired session whose userid matches the bearer —
   mismatch or unknown id → `404` (per spec, client must re-initialize).
   `lastseen` bumped through the MUC through-cache; hard expiry
   `mcpsessionttl` (default 24 h, setting).
4. **Method dispatch** to `mcp_method_controller` (WP-A4).

`server.php` glue only: `define('NO_MOODLE_COOKIES', true)`,
`define('NO_DEBUG_DISPLAY', true)`, require config, master-switch check
(`enabled`, FR-ADMIN-1 — off → `503`), build PSR-7 request, run handler,
emit response.

### WP-A3 — capabilities + auth strategy v1 (WS token)

`db/access.php`:

```php
'tool/oauthmcp:connect' => [        // FR-ADMIN-2: who may use MCP at all
    'captype' => 'read',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes' => [],             // explicit grant only — never by default
    'riskbitmask' => RISK_PERSONAL,
],
'tool/oauthmcp:manageclients' => [
    'captype' => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes' => [],
    'riskbitmask' => RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS,
],
```

(No archetype defaults on purpose; archetype changes would not reach existing
sites anyway.)

`classes/local/auth/` — `bearer_authenticator_interface`
(`authenticate(string $bearer): ?auth_result` where `auth_result` =
{userid, scopes[], authmode, tokenrecord?}) with two implementations behind
an ordered chain configured by the `authmode` setting
(`wstoken` | `oauth` | `both`, default after phase B: `both`):

- **`wstoken_authenticator`** (phase A): delegates to
  `webservice::authenticate_user($token)` (`webservice/lib.php:70`) inside a
  catch-all (its exceptions → `401`); additionally requires the token's user
  to hold `tool/oauthmcp:connect` at system context. Grants **both scopes**
  (`mcp:read`, `mcp:write`) — WS tokens are an admin-minted, restricted-user
  instrument; scope granularity arrives with OAuth. Rationale recorded here
  so the asymmetry is deliberate.
- **`oauth_authenticator`** (phase B, WP-B7).

After authentication: `\core\session\manager::set_user($user)` so every
downstream capability check, `external_api` call and hook provider sees the
real user (FR-TOOL-5).

### WP-A4 — MCP methods (`initialize`, `ping`, `tools/list`, `tools/call`)

`classes/local/mcp/mcp_method_controller.php`:

- **`initialize`** (FR-MCP-2): client sends `protocolVersion`; if it is one
  of `['2025-06-18', '2025-03-26']` echo it, else respond with our latest
  (`2025-06-18`) per spec (client disconnects if unusable). Result:
  `capabilities: {tools: {listChanged: false}}`, `serverInfo: {name:
  "Moodle (tool_oauthmcp)", version: <plugin release>}`, `instructions`
  (short, from lang string — mentions the two-call confirm convention for
  destructive tools). Non-initialize requests on `2025-06-18` must carry the
  `MCP-Protocol-Version` header; absent → assume the session's negotiated
  version (spec-tolerant), mismatch with session → `400`.
- **`notifications/initialized`** → `202`, no body. **`ping`** → `{}`.
- **`tools/list`**: registry (WP-A5) → definitions for the session user,
  filtered by scope set and per-tool governance; served from the `toollist`
  MUC cache. `cursor` param accepted and ignored (single page — 22 agent
  tools + a service's functions fit trivially).
- **`tools/call`**: `{name, arguments}` → registry dispatch. Every uncaught
  throwable from a tool is mapped to `{content: [text], isError: true}` —
  never a JSON-RPC error, never an HTML Moodle exception page (the handler
  installs a JSON exception guard). Result mapping per FR-MCP-5:
  `content` text blocks + `structuredContent` passthrough when the source
  provides it. A per-user rate limit check runs first (WP-B9's limiter, with
  a phase-A default of 60/min via config `ratelimittools`).

### WP-A5 — tool registry + source abstraction

`classes/local/registry/tool_source_interface.php`:

```php
public function get_source_id(): string;                      // 'extfunc' | component name
public function list_tools(int $userid, int $contextid, array $scopes): array; // MCP defs + 'scope' each
public function call_tool(string $toolname, array $args, int $userid, int $contextid,
                          string $idempotencykey): array;     // MCP-shaped result
public function has_tool(string $toolname): bool;
```

`tool_registry.php` aggregates sources (phase A: `extfunc_tool_source`;
phase C adds `hook_tool_source`), applies:

- per-tool governance from `tool_oauthmcp_tool` (unknown tool rows are
  created lazily on first listing, `enabled=1`, so the admin page always
  shows the full current inventory) — disabled → hidden from `tools/list`
  **and** `tools/call` → error result `TOOL_DISABLED` (FR-TOOL-3);
- scope filtering (FR-OAUTH-9): token without `mcp:write` never sees or
  executes a write tool — enforced in list **and** call;
- namespace collision guard: first source wins, later duplicate logged via
  `debugging()` and skipped (FR-TOOL-4; hook providers own their prefix).

### WP-A6 — external-function source + schema converter (FR-TOOL-1)

`classes/local/registry/extfunc_tool_source.php`:

- Inventory: admin setting `exposedservices` (multiselect of
  `external_services` shortnames). Tools = the union of
  `external_services_functions` rows of the selected services; tool name =
  the external function name verbatim (already frankenstyle-namespaced —
  `core_course_get_courses` — and `[a-zA-Z0-9_]`-safe, FR-TOOL-4).
- `description` = `external_functions.description` (via
  `external_api::external_function_info()`, which also yields the
  parameter/return structures and required capabilities; the capability list
  is appended to the description as "Requires: …" so the model can
  self-select).
- Scope label: `mcp:write` by default; the governance page's `readonly`
  column lets the admin mark individual functions `mcp:read`. Deliberately
  conservative — Moodle external functions carry no machine-readable
  read/write flag, and guessing from `get_`/`search_` prefixes is exactly
  the kind of heuristic that ends in a mutating tool behind a read scope.

`classes/local/registry/extfunc_schema_converter.php` — re-implementation
(decision 3; prior art credited: onbirdev/moodle-webservice_mcp):

| `external_description` node | JSON Schema |
|---|---|
| `external_value(PARAM_INT)` | `{"type": "integer"}` |
| `external_value(PARAM_FLOAT)` | `{"type": "number"}` |
| `external_value(PARAM_BOOL)` | `{"type": "boolean"}` |
| `external_value(<any other PARAM_*>)` | `{"type": "string"}` (Moodle cleans downstream — the schema advertises shape, `validate_parameters()` stays the source of truth) |
| `->desc` | `description` |
| `->required === VALUE_REQUIRED` | listed in parent `required` array |
| `->required === VALUE_DEFAULT` | `default` key emitted |
| `->allownull` | type union `["<type>", "null"]` |
| `external_single_structure` | `{"type": "object", "properties": …, "required": […], "additionalProperties": false}` |
| `external_multiple_structure` | `{"type": "array", "items": <converted content>}` |
| `external_function_parameters` | top-level object schema = the tool `inputSchema` |

Converter is pure (structure in, array out) → exhaustively unit-testable
against fixture structures plus a smoke test over every function of
`moodle_mobile_app` (catches converter blind spots against the real corpus).

Execution: `external_api::call_external_function($function, $args, false)`
(`lib/external/classes/external_api.php:181`) with `$args` passed through as
decoded named parameters; it runs `validate_parameters`, capability checks
and returns `{error, exception?, data}` — mapped to:

- `error === false` → `structuredContent = data`, `content` = pretty-printed
  JSON text block (Claude reads either), `isError: false`;
- `error === true` → `content` = exception message (localised),
  `structuredContent = {errorcode, debuginfo?}` (debuginfo only when site
  debugging is on), `isError: true`.

`annotations`: `readOnlyHint` from the governance `readonly` flag,
`destructiveHint: true` for everything else, `title` = function name.

### WP-A7 — admin UI v1

`settings.php`: category `tool_oauthmcp` under *Server*:

- **Settings page**: `enabled` (master switch, default 0, FR-ADMIN-1),
  `authmode`, `exposedservices`, `alloworigins`, `mcpsessionttl`,
  `ratelimittools` (default 60/min), plus phase-B settings registered early
  but hidden behind `enabled`-style dependency where sensible.
- **`tools.php`** (external admin page, FR-TOOL-3): table of all inventoried
  tools (source, name, description excerpt, scope, enabled toggle, readonly
  toggle for `extfunc`), writing `tool_oauthmcp_tool` + invalidating the
  `toollist` cache.

### WP-A8 — audit event v1 + purge groundwork

`classes/event/tool_called.php` (`crud` dynamic: `r` for read-scope tools,
`u` otherwise; `other = {source, tool, status, sessionid}`; context = system,
`relateduserid` = acting user) — triggered in the registry around every
`tools/call` including denials (`status` ∈ executed | error | denied_scope |
denied_disabled | rate_limited). Satisfies the FR-ADMIN-4 "tool called" row
from day 1.

`classes/task/purge_expired.php` registered in `db/tasks.php` (hourly):
phase A purges sessions past TTL; phase B extends it (WP-B9).

### WP-A9 — tests + interop validation (phase A)

- `tests/json_rpc_test.php` — parse/error-code matrix, batching accepted on
  `2025-03-26` and rejected on `2025-06-18`, notification handling.
- `tests/mcp_handler_test.php` — drives `mcp_http_handler` with synthetic
  PSR-7 requests: initialize/version negotiation (both versions + unknown),
  session lifecycle (issue, reuse, expiry, DELETE, userid mismatch), origin
  validation, HTTPS guard, 401 paths, master switch off.
- `tests/extfunc_schema_converter_test.php` — the mapping table above as
  fixtures + the `moodle_mobile_app` smoke sweep.
- `tests/extfunc_source_test.php` — happy call as a permissioned user,
  capability-denied call → `isError` (not exception), disabled tool, scope
  filtering, unknown tool.
- Manual: **MCP Inspector** against both protocol versions (AC-3), Claude
  Code `claude mcp add --transport http --header "Authorization: Bearer …"`
  (AC-2, header half).

**Phase A exit criteria:** AC-3 passes; AC-2 works in header mode; a Moodle
external function is callable from Claude Code end-to-end.

---

## Phase B (= milestone M2) — OAuth 2.1 authorization server

Outcome: AC-1 — claude.ai custom connector connects with the URL alone.

### WP-B1 — vendor `league/oauth2-server`

- Vendor under `vendor-oauth2/` with entries in `thirdpartylibs.xml`:
  `league/oauth2-server`, `lcobucci/jwt` (+ `lcobucci/clock`),
  `defuse/php-encryption`, `league/uri` + `league/uri-interfaces`,
  `paragonie/constant_time_encoding`. **Not** vendored (already in core):
  `psr/http-message`, `psr/http-factory`, `psr/clock`, Guzzle PSR-7
  (verified present in `lib/psr/`, `lib/guzzlehttp/`).
- **Version pin — verification task, first commit of the phase:** target
  `league/oauth2-server ^9`; if its composer floor is PHP > 8.1 (to verify
  at vendoring time — v9 is expected to require ≥ 8.2), decide per open
  decision D1 (§ "Open decisions"): raise the plugin's PHP floor to 8.2 or
  pin the maintained `8.5.x` line. The repository interfaces we implement
  exist in both lines; the abstraction below keeps the swap cheap.
- Keys: on first use (init in an install/upgrade step), generate an RSA-4096
  keypair into `$CFG->dataroot/tool_oauthmcp/` (mode 0600; used by the
  library for auth-code payload encryption/signing) and a Defuse encryption
  key stored via `set_config` (`encryptionkey`). Neither ever leaves the
  server; access tokens are opaque, so no public-key distribution concern.

### WP-B2 — DB schema v2 (OAuth tables)

- **`tool_oauthmcp_client`**: `clientid CHAR(64) UNIQUE` (random),
  `secrethash CHAR(255) NULL` (`password_hash`; NULL = public client),
  `name CHAR(255)`, `redirecturis TEXT` (JSON array),
  `scopes CHAR(255)` (max grantable, default `mcp:read mcp:write`),
  `authmethod CHAR(32)` (`none`|`client_secret_basic`|`client_secret_post`),
  `dcr TINYINT` (1 = came in via RFC 7591), `enabled TINYINT`,
  `createdby BIGINT NULL`, `registrationip CHAR(45) NULL`,
  `timecreated/timemodified BIGINT`.
- **`tool_oauthmcp_authcode`**: `identifier CHAR(100) UNIQUE`,
  `clientdbid BIGINT`, `userid BIGINT`, `expires BIGINT`,
  `revoked TINYINT` — single-use tracking only (the code payload itself is
  the library's encrypted blob; we store just enough for
  `isAuthCodeRevoked` + replay→family-revocation, WP-B3).
- **`tool_oauthmcp_token`** (access tokens): `tokenhash CHAR(64) UNIQUE`
  (sha256 of the opaque token — plaintext is never stored),
  `identifier CHAR(100) UNIQUE` (library id), `clientdbid`, `userid`,
  `scopes CHAR(255)`, `resource CHAR(255) NULL` (RFC 8707 binding),
  `expires BIGINT`, `revoked TINYINT`, `timecreated`, `lastused BIGINT`.
  Indexes: `tokenhash` (the hot lookup), `userid`, `expires`.
- **`tool_oauthmcp_refresh`**: `tokenhash CHAR(64) UNIQUE`,
  `identifier CHAR(100) UNIQUE`, `accesstokenid BIGINT`,
  `familyid CHAR(64)` (constant across rotations of one grant — the
  reuse-detection handle), `expires`, `revoked TINYINT`, `timecreated`.
- **`tool_oauthmcp_consent`**: `userid`, `clientdbid`, `scopes CHAR(255)`,
  `remembered TINYINT`, `timecreated`, `timemodified`;
  unique `(userid, clientdbid)`.

### WP-B3 — entities + repositories (the library seam)

`classes/local/oauth/entities/` — thin entity classes using the library's
traits (`ClientEntityInterface`, `AccessTokenEntityInterface` etc.).

`classes/local/oauth/repositories/`:

- `client_repository` — lookup by `clientid`, `validateClient` per
  `authmethod` (basic/post secret via `password_verify`; public clients pass
  and rely on PKCE, which the grant enforces), disabled client → fail.
- `scope_repository` — knows exactly `mcp:read`, `mcp:write`;
  `finalizeScopes()` intersects requested ∩ client-registered ∩
  a per-user ceiling (users lacking a site-level write policy are not
  scoped down in v1 — the engine-side gates decide; ceiling hook kept for
  later). Empty request defaults to `mcp:read`.
- `access_token_repository` — `persistNewAccessToken` writes the row
  (hash written by the response type, see WP-B4a); `isAccessTokenRevoked`
  is **not** used on the hot path (our own validator is, WP-B7) but
  implemented for library completeness.
- `auth_code_repository` — persist identifier; `revokeAuthCode` on redeem;
  `isAuthCodeRevoked` returning true triggers **OAuth 2.1 replay handling**
  in the grant — we additionally override the grant response to revoke all
  tokens previously issued from that code (code-replay → token family dead).
- `refresh_token_repository` — persist with `familyid` (new grant → new
  family; rotation → inherit family). `isRefreshTokenRevoked` returning
  true = **reuse detected** → repository hook revokes the whole family
  (all refresh rows with that `familyid` + their access tokens)
  (FR-OAUTH-4).

### WP-B4 — `/oauth/authorize.php` (login + consent)

Flow (FR-OAUTH-2, -5, -6):

1. Normal Moodle page bootstrap, **`require_login()`** — inherits whatever
   the site uses (manual/LDAP/SAML/OIDC/MFA). Then
   `require_capability('tool/oauthmcp:connect', context_system::instance())`
   (FR-ADMIN-2) — missing capability renders a friendly "your account is not
   enabled for MCP access" page, not an exception.
2. `AuthorizationServer::validateAuthorizationRequest()` with an
   `AuthCodeGrant` configured: PKCE **required for all clients** (public and
   confidential — `S256` only; the grant rejects `plain` when
   `setRequireCodeChallengeForPublicClients` + our client entity forces the
   challenge for confidential ones too), exact-match redirect URI (the
   library matches exactly when full URIs are registered — we never register
   prefixes), auth-code TTL **60 s**, `state` passed through untouched.
3. **Resource indicator** (FR-OAUTH-3 / RFC 8707): accept `resource`;
   if present it must equal the canonical MCP endpoint URL
   (`$CFG->wwwroot . '/admin/tool/oauthmcp/server.php'`, compared
   normalised) — anything else → `invalid_target` error redirect. Stored
   into the code payload (custom grant subclass carries it through to the
   token, where WP-B4a binds it).
4. **Consent** (FR-OAUTH-6): if a `tool_oauthmcp_consent` row covers this
   user+client with `remembered=1` and a superset of the requested scopes
   *and* the admin policy `consentpolicy` allows remembering
   (`always_ask` | `allow_remember`, default `allow_remember`), skip the
   screen. Otherwise render `consent_form` (moodleform, sesskey-protected):
   client name, requested scopes with plain-language descriptions, and the
   **tool list the grant unlocks** (registry `list_tools` for this user at
   system context, filtered by requested scopes), an optional "remember"
   checkbox, Approve/Deny. Deny → `completeAuthorizationRequest(false)` →
   `access_denied` redirect. Approve → persist/refresh consent row,
   `completeAuthorizationRequest(true)` → 302 with code + state.
5. Event `consent_given` (WP-B9) on approve.

### WP-B4a — opaque-token response type (FR-OAUTH-4)

`classes/local/oauth/opaque_bearer_response.php` extends the library's
`BearerTokenResponse`: instead of serialising the access token as a JWT, emit
the token **identifier** itself (prefixed `moamcp_` + 80 hex chars — the
prefix makes leaked-secret scanning possible) and store
`sha256(token)` into `tool_oauthmcp_token.tokenhash` at persist time.
Refresh tokens likewise opaque (`moamcr_` prefix), hash-stored. `expires_in`
from settings `accesstokenttl` (default 3600) / `refreshtokenttl`
(default 30 days). Response JSON is the standard RFC 6749 body, so no client
sees any difference.

### WP-B5 — `/oauth/token.php`

`NO_MOODLE_COOKIES`; per-IP rate limit first (WP-B9). Two grants enabled:
`authorization_code` (+PKCE) and `refresh_token` (rotation on every use —
library default — plus the family reuse-detection from WP-B3). Errors emitted
as RFC 6749 JSON (`invalid_grant`, `invalid_client`, `invalid_request`,
`invalid_target`, …) via the library's `OAuthServerException` → PSR-7 path.
Event `token_issued` / `token_refused` per outcome. The `resource` parameter
is re-validated at the token endpoint (RFC 8707 allows narrowing; we accept
equal-or-absent relative to the code's binding).

### WP-B6 — `/oauth/register.php` — dynamic client registration (RFC 7591)

Hand-written (not covered by the library), `NO_MOODLE_COOKIES`, anonymous
POST (per RFC; claude.ai depends on it — FR-OAUTH-8):

- Gate: setting `dcrenabled` (default **1**), per-IP rate limit
  (default 5/hour) + global quota `dcrquota` (default 100 live DCR clients;
  at quota → `503` with `Retry-After`) — FR-ADMIN-3 / DCR-abuse mitigation.
- Accepted metadata: `client_name` (required, `PARAM_TEXT`, ≤ 255),
  `redirect_uris` (required, each: valid absolute URI, `https` scheme —
  `http://localhost`/`http://127.0.0.1` allowed only when
  `dcrallowlocalhost` is on, no fragments, no wildcards),
  `grant_types` ⊆ {authorization_code, refresh_token},
  `response_types` ⊆ {code},
  `token_endpoint_auth_method` ∈ {none, client_secret_basic,
  client_secret_post} (default `none`), `scope` ⊆ registered scopes.
  Unknown members ignored per RFC.
- Response `201`: `client_id`, `client_id_issued_at`, echo of accepted
  metadata, `client_secret` (+`_expires_at: 0`) only for confidential
  methods. No registration-management endpoint in v1 (RFC 7592 out of
  scope).
- Event `client_registered` (records IP + name).
- Manual alternative (DCR off): `clients.php` admin UI (WP-B10) — AC-6.

### WP-B7 — bearer validation v2 + revocation endpoint

`oauth_authenticator` (completing WP-A3's chain):

- Token from `Authorization: Bearer` only (`bearer_methods_supported:
  ["header"]` — no query/body tokens, ever).
- Lookup: `sha256(bearer)` → MUC `tokenvalidation` cache (**ttl 60 s** —
  this is the AC-5 "revocation within one minute" bound) → miss: single
  indexed DB read on `tokenhash` (NFR-5: ≤ 1 extra read), then cache
  {userid, scopes, resource, expires, revoked, clientdbid}.
- Checks: not revoked, not expired, `resource` (when bound) matches the
  canonical endpoint URL, client still enabled, user exists/not
  suspended/not deleted, `tool/oauthmcp:connect` still held. Any failure →
  `401` with `WWW-Authenticate: Bearer error="invalid_token",
  resource_metadata="<prm url>"`.
- `lastused` bumped lazily (at most once per 5 min per token, write-behind
  through the cache, to keep the hot path read-only).

`/oauth/revoke.php` (RFC 7009, FR-OAUTH-1/-4): client-authenticated
(same rules as token endpoint); accepts `token` (+ optional
`token_type_hint`); tries access-token hash first, then refresh; revoking a
refresh token revokes its family + linked access tokens; always `200` (RFC:
invalid tokens are not an error). Cache entries for revoked hashes are
purged immediately. Event `token_revoked`.

### WP-B8 — discovery documents + `.well-known` strategy + diagnostics

- **`oauth/prm.php`** — RFC 9728 protected-resource metadata:
  `{"resource": <server.php URL>, "authorization_servers": [<issuer>],
  "scopes_supported": ["mcp:read","mcp:write"],
  "bearer_methods_supported": ["header"]}`. **Issuer = `$CFG->wwwroot`**
  (no path suffix games — predictable RFC 8414 path-insertion).
- **`oauth/asmeta.php`** — RFC 8414 AS metadata: `issuer`,
  `authorization_endpoint`, `token_endpoint`, `registration_endpoint`
  (only while DCR enabled), `revocation_endpoint`,
  `response_types_supported: ["code"]`,
  `grant_types_supported: ["authorization_code","refresh_token"]`,
  `code_challenge_methods_supported: ["S256"]`,
  `token_endpoint_auth_methods_supported: ["none","client_secret_basic",
  "client_secret_post"]`, `scopes_supported`. Both documents:
  `NO_MOODLE_COOKIES`, `Cache-Control: public, max-age=3600`, anonymous.
- **401 wiring**: every `401` from `server.php` carries
  `WWW-Authenticate: Bearer resource_metadata="<absolute prm.php URL>"` —
  per MCP spec 2025-06-18 clients MUST follow this, which makes the PRM
  document location free-choice (plugin path is fine).
- **The unavoidable webroot part**: RFC 8414 clients derive the AS metadata
  URL from the issuer as
  `https://<host>/.well-known/oauth-authorization-server[/<issuer-path>]`.
  That URL lives at the **webroot**, outside plugin control. Ship
  `.well-known-snippets/` with copy-paste configs — Apache (root
  `.htaccess`/vhost `RewriteRule`) and nginx (`location =` blocks) — mapping,
  for a wwwroot at domain root *and* for a subdirectory install:
  `/.well-known/oauth-authorization-server[<path>]` →
  `<moodle>/admin/tool/oauthmcp/oauth/asmeta.php` and
  `/.well-known/oauth-protected-resource[<path>]` → `…/oauth/prm.php`
  (the PRM well-known alias is belt-and-braces for clients that probe it
  despite the header).
- **`diagnostics.php`** (admin page): live self-checks with green/red rows —
  HTTPS on; master switch; fetches (server-side cURL) of both well-known
  URLs derived from `$CFG->wwwroot` and comparison against the expected
  document; `server.php` reachable and returning a proper 401 challenge;
  DCR endpoint responding; clock sanity for token TTLs. This page is the
  "five minutes" claim's insurance — the rewrite step is the only manual
  one, and this makes its failure visible instead of a silent claude.ai
  connect error.

### WP-B9 — rate limiting, audit events, purge task

- `classes/local/ratelimit/sliding_window.php` over the `ratelimit` MUC
  application cache: `check(string $key, int $limit, int $windowseconds)`.
  Applied: `tools/call` per user (`ratelimittools`, default 60/min —
  FR-ADMIN-3), `/oauth/token` per IP (default 30/min), `/oauth/register`
  per IP (default 5/hour). Violations → HTTP 429 (`Retry-After`) resp.
  `MCP_RATE_LIMITED` error result, and are logged via events (`other.status
  = rate_limited`). Honest limitation, documented: MUC-based counting is
  best-effort under multi-node cache splits — acceptable for abuse braking,
  not billing.
- Events (FR-ADMIN-4, all `\core\event\base`, system context,
  `relateduserid` set): `client_registered`, `consent_given`,
  `token_issued`, `token_refused`, `token_revoked`, `tool_called`
  (phase A, `other.source/tool/status`). All visible in the standard log
  report; names/descriptions localised.
- `task/purge_expired.php` (hourly, FR-ADMIN-5): delete expired+revoked
  auth codes immediately, tokens `expires < now - 7 days` (grace window
  keeps forensics possible), sessions past TTL, DCR clients with zero
  tokens and no activity for `dcrstaledays` (default 90).

### WP-B10 — self-service + admin client management

- **`userapps.php`** (FR-OAUTH-7): listed via the `myprofile` navigation
  callback (`lib.php: tool_oauthmcp_myprofile_navigation`) as "Connected MCP
  apps". Table per grant: client name, scopes, first authorised, last used,
  [Revoke] → revokes consent row + all live tokens of that user+client
  (+ cache purge → AC-5). Sesskey-protected post actions.
- **`clients.php` / `client_edit.php`** (FR-OAUTH-8, capability
  `tool/oauthmcp:manageclients`): list (name, type, DCR?, created, tokens
  live, enabled), create/edit form (`client_form`: name, redirect URIs
  textarea — validated like DCR —, auth method, scopes), secret shown
  **once** after creation, disable/enable, delete (cascades tokens/consents
  with confirmation).

### WP-B11 — tests (phase B)

PHPUnit — every flow branch (NFR-3), all driving the PSR-7 handlers
directly:

- **Happy path**: DCR → authorize (login+consent mocked via generator user +
  direct form submission) → code → token → MCP call with the opaque token →
  refresh → revoke. (This is AC-1 minus the real claude.ai client.)
- **Abuse paths**, one test each (these seed `THREAT_MODEL.md`):
  redirect URI mismatch (exact-match, including trailing-slash and
  query-string variants); PKCE absent / `plain` method / verifier mismatch;
  auth code expired (>60 s), reused (→ family revocation verified),
  swapped-client code redemption; `state` round-trip integrity;
  refresh reuse after rotation (→ family dead); resource indicator foreign
  URL → `invalid_target`; token used after revocation (cache-purge path);
  suspended user's live token → 401; scope escalation attempt
  (`mcp:write` request on read-only client registration); DCR: bad scheme,
  wildcard, fragment redirect URIs, quota breach, rate limit; consent deny;
  `consentpolicy=always_ask` forcing re-consent.
- Repository unit tests against the library's grant test doubles.
- Behat: consent screen approve/deny (JS), self-service list + revoke,
  admin client CRUD, governance page toggles.

**Phase B exit criteria:** AC-1 (claude.ai custom connector, manual),
AC-2 full (OAuth mode), AC-5, AC-6 verified; abuse-path suite green.

---

## Phase C (= milestone M3) — dynamic tool-provider hook + agent provider

### WP-C1 — hooks (FR-TOOL-2)

`classes/hook/collect_tools.php` (implements
`\core\hook\described_hook`; final class, constructor-injected readonly
payload):

```php
public function __construct(int $userid, int $contextid, array $scopes);
public function add_tool(array $definition): void;
// definition keys: name (provider-prefixed), description, inputSchema (array),
// annotations (array), scope ('mcp:read'|'mcp:write'), component (frankenstyle)
public function get_tools(): array;
```

`classes/hook/execute_tool.php` (stoppable, `\Psr\EventDispatcher\
StoppableEventInterface` via core's `stoppable_trait`):

```php
public function __construct(string $toolname, array $args, int $userid,
                            int $contextid, string $idempotencykey);
public function set_result(array $mcpresult): void;  // MCP-shaped verbatim; stops propagation
public function has_result(): bool;
```

`hook_tool_source` (registered in the WP-A5 registry): `list_tools()`
dispatches `collect_tools` via `\core\di::get(\core\hook\manager::class)`,
validates each contribution (name charset `[a-zA-Z0-9_-]{1,128}`,
inputSchema is an object schema, scope valid; invalid → `debugging()` +
skip), records `source = component` in the governance table (per-tool
enable/disable applies to hook tools identically). `call_tool()` dispatches
`execute_tool`; no listener claiming it → `TOOL_UNKNOWN` error result.
`idempotencykey` = sha256 of `{sessionid}:{jsonrpc request id}` when the
client supplies an id (JSON-RPC ids are unique per session), else a fresh
random — providers with run bookkeeping (the agent) get replay protection
for free.

Per-user listing is inherent: the hook carries `userid`/`contextid`, and the
dispatch happens after `set_user()`, so providers can also use `$USER`
consistently.

### WP-C2 — scope enforcement end-to-end (FR-OAUTH-9)

Already structurally present from WP-A5; this WP adds the tests: read-scoped
token — write tool invisible in `tools/list`, `tools/call` → error result
`TOOL_SCOPE_DENIED`; write-scoped token sees both; WS-token mode grants both
scopes (documented behaviour).

### WP-C3 — agent-side hook provider (lives in `bookingextension_agent` / later `local_wizard`)

Separate commit series in the agent repo (its own plan §WP references):

- `db/hooks.php` registering `classes/hook_callbacks.php` for both hooks.
- `hook_callbacks::collect_tools()`: guard chain first —
  `tool_oauthmcp` hook classes exist (`class_exists`, soft dependency),
  engine active (`primary_engine_takes_over()` false), `check_use_readiness`
  for the hook userid, licence gates — then delegate to
  `mcp_execution_service::list_tools($contextid, $userid)` (committed,
  `mcp_execution_service.php:99`) and `add_tool()` each definition with
  `scope` = `mcp:read` for `readOnlyHint` tools, else `mcp:write`, and
  `component = 'bookingextension_agent'`. Tool names arrive already
  MCP-safe from `mcp_tool_catalog_service::tool_name_for()` (committed).
- `hook_callbacks::execute_tool()`: prefix ownership check
  (only names resolving via `skill_for_tool_name()`), then
  `mcp_execution_service::call_tool($toolname, $args, $contextid, $userid,
  $idempotencykey)` — the return value is already MCP-shaped verbatim
  (`content`/`structuredContent`/`isError`, `mcp_execution_service.php:107`)
  → `set_result()` pass-through, no mapping layer.
- Dual-track: when `local_wizard` takes over, the identical callbacks move
  with the `services/mcp` subtree; both plugins listening simultaneously is
  prevented by the engine-active guard (inactive engine contributes no
  tools).

### WP-C4 — two-call confirm over remote MCP (AC-4)

**Dependency: agent facade phase 2** (`MCP_SKILL_EXPOSURE_IMPLEMENTATION_
PLAN.md` WP2.1 — currently `call_mutating_tool()` returns
`MCP_MUTATIONS_NOT_AVAILABLE`, `mcp_execution_service.php:165`).
`tool_oauthmcp` itself needs **nothing** for this: the preview call and the
confirm call are both ordinary `tools/call` round-trips; the
`confirmationcode` in `structuredContent` plus the instruction text drive
Claude's behaviour, and statelessness holds (no session affinity required —
the pending intent lives in the agent's store). This WP is: the AC-4
end-to-end verification from claude.ai (preview → human "yes" in chat →
confirm call → mutation verified + audit events on both sides) plus an
integration test with a fixture provider simulating the two-call shape.

### WP-C5 — tests (phase C)

Fixture hook provider inside `tests/fixtures/` (registered via the hook
manager's phpunit override, `\core\hook\manager::phpunit_replace_callbacks`):
contribution validation (bad names/schemas skipped), governance toggles on
hook tools, scope filtering, execute dispatch + stoppable semantics,
idempotency-key derivation, collision with an extfunc tool name (first
source wins + debugging). Agent-side: provider tests in the agent repo
(guards: engine inactive → no tools; readiness fail → no tools; R0 list
parity with `mcp_list_tools` external function).

**Phase C exit criteria:** AC-4 verified manually from claude.ai (R0
immediately; R2 once facade phase 2 lands); fixture-provider suite green.

---

## Phase D (= milestone M4) — hardening, privacy, release

- **WP-D1 THREAT_MODEL.md** (NFR-4): STRIDE-ish walk of every endpoint;
  documented attacker stories: redirect hijack, code interception + replay,
  PKCE downgrade, token leakage (logs/referrer/cache), DCR squatting +
  resource exhaustion, AS mix-up (single-AS deployment note + resource
  binding), consent CSRF (sesskey), MCP session guessing (128-bit random,
  userid-bound), origin spoofing, opaque-token brute force (2^320 space,
  hash-stored). Each mitigation cross-links the PHPUnit test that pins it
  (WP-B11 list) — the review deliverable is doc + executable evidence.
- **WP-D2 privacy provider** (NFR-2): metadata + export + delete for
  clients-created-by, consents, tokens, sessions, and the events; token
  rows exported as {client, scopes, created, lastused} without hashes.
  Provider tests.
- **WP-D3 performance validation** (NFR-5): perf-guard PHPUnit asserting
  DB read counts on the hot path (warm cache: 0 extra reads for token
  validation; cold: 1) — same `perf_get_reads` pattern as the booking
  perf-regression tests; `tools/list` served from cache on second call.
- **WP-D4 external security review**: package review kit (threat model,
  endpoint inventory, data-flow diagram, test evidence), commission the
  review (NFR-4 gate — **no production release before its findings are
  resolved**), fix window.
- **WP-D5 release prep**: README (setup checklist: enable, pick services,
  grant capability, rewrites + diagnostics screenshot, claude.ai walkthrough,
  Claude Code/Desktop snippets), CHANGES.md, moodle.org listing texts,
  `de` lang pack, final `Version (YYYYMMDDXX)` and tag.

---

## Commit sequence (per repo, all English, path-limited)

`tool_oauthmcp` repo:

1. `Plugin skeleton, capabilities and settings scaffold` (WP-0, A3-caps, A7 shell)
2. `MCP endpoint: JSON-RPC layer, sessions and protocol negotiation` (A2, A4 minus tools)
3. `Tool registry, external-function source and schema converter` (A5, A6 + tests)
4. `WS-token bearer authentication and tool governance UI` (A3, A7, A8)
5. `Version (YYYYMMDDXX)` — separate
6. `Vendor league/oauth2-server and OAuth persistence layer` (B1, B2, B3)
7. `OAuth endpoints: authorize with consent, token, opaque tokens` (B4, B4a, B5)
8. `Dynamic client registration, revocation and discovery documents` (B6, B7-revoke, B8)
9. `OAuth bearer validation, rate limiting, audit events, purge task` (B7, B9)
10. `Self-service connected apps and admin client management` (B10)
11. `OAuth flow and abuse-path test suite` (B11, if not folded into 6–10)
12. `Version (YYYYMMDDXX)` — separate
13. `Dynamic tool-provider hooks and hook tool source` (C1, C2, C5)
14. `Threat model, privacy provider and performance guards` (D1–D3)
15. `Version (YYYYMMDDXX)` — separate

`bookingextension_agent` repo (phase C, after facade phase 2):

1. `MCP facade: mutating skills with two-call confirm` (facade plan WP2.x — prerequisite)
2. `Expose agent skills to tool_oauthmcp via tool-provider hooks` (WP-C3)
3. `Version (YYYYMMDDXX)` — separate

## Open decisions (recommendations attached)

- **D1 — library line vs PHP floor**: if `league/oauth2-server ^9` requires
  PHP ≥ 8.2 (verify at WP-B1), recommend **raising the plugin floor to PHP
  8.2** (Moodle 4.5 supports 8.1–8.3; 8.1 was EOL 2025-12; a
  fresh security-critical plugin should not pin a maintenance-line library
  for an EOL PHP) — deviates from NFR-1's "8.1+", needs George's sign-off.
  Fallback: `8.5.x` line, same repositories.
- **D2 — WS-token mode after phase B**: recommend keeping `authmode = both`
  as default (header-capable clients and CI smoke tests stay cheap; OAuth is
  the claude.ai path). Alternative: flip default to `oauth`, keep `wstoken`
  opt-in.
- **D3 — where phase-C agent commits land**: `SOFABOOKING` (current MCP
  facade branch) assumed; confirm against the branch state at that time.

## Risks

- **Webroot rewrite is a manual step** outside Moodle (shared hosting may
  not allow it) → claude.ai connectors impossible on such hosts; header-auth
  clients unaffected. Mitigation: diagnostics page + prominent README note.
- **claude.ai connector behaviour is a moving target** (discovery probe
  order, DCR expectations) → AC-1 is a manual acceptance test per release;
  keep the MCP spec version matrix (`2025-03-26`, `2025-06-18`) in one
  constant.
- **Library/PHP floor mismatch** → D1, decided before any phase-B code.
- **MUC-based rate limiting is best-effort** on multi-node caches →
  documented; hard guarantees would need DB counters (deliberately not v1).
- **Facade phase 2 timing**: AC-4 (R2 confirm from claude.ai) is blocked on
  the agent-side confirm flow — phases A/B/C1-C2 are independent of it and
  R0 tools deliver value meanwhile.
