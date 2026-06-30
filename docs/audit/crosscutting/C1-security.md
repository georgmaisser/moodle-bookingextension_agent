# Cross-cutting Audit C1 — Security (horizontal)

**Scope:** WHOLE plugin tree (`classes/external/*`, root `*.php` admin/benchmark pages,
`skill_governance.php`, `skill_selection_debug.php`, `trial_challenge.php`, `cli/*`,
`classes/local/wizard/services/security/*`, `conversation_store.php`, `privacy/provider.php`,
`db/access.php`, `db/services.php`, `db/hooks.php`, `lib.php`, the LLM/debug logging path, the
anonymizer display gate, `markdown_renderer.php`, `ws_message_formatter.php`).
**Files read in full or grepped:** ~45 · **Methods audited (estimate):** ~120
**Arch chapter(s):** cross-references docs/architecture (security/authorization) + section 08 report
**Auditor verdict:** ⚠️ issues — **no BLOCKER**. The two-gate authorization model, IDOR ownership
gating, SQL parameterisation, secrets handling and privacy coverage are all sound. Findings are
HIGH→LOW hardening items, none gate launch.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues | Core model is strong (see below). Residual items: a write action gated only by a *read* benchmark cap (C1-F01), an unused `managebenchmarks` cap (C1-F02), a latent `javascript:`-scheme passthrough in the docs markdown renderer reachable only from the trusted corpus (C1-F03), pending-intent ownership check skipped when stored userid is 0 — defended downstream (C1-F04), and several read endpoints validating the WS context after the readiness check rather than before (C1-F05, INFO). |
| D2 Moodle API      | na | Covered by the per-subsystem + moodle-api cross-cutting reports. |
| D3 Structure       | na | Out of scope for the security sweep. |
| D4 Duplication     | na | Out of scope. |
| D5 Flowchart       | na | Out of scope (covered by flowchart sweep). |
| D6 Docs coverage   | na | Out of scope. |

### Two-gate model — verified end-to-end (the headline result)

- **Gate 1 (governance + per-skill capability)** is enforced in
  `skill_executability_evaluator::has_required_capabilities()` at the **ambient** context. The
  required capability `<component>:skill_<normalized_name>` is **derived by the engine from the
  skill name**, not trusted from skill metadata (`skill_executability_evaluator.php:183-200`), and
  the check **fails closed** if the capability is undefined (`!get_capability_info($cap)` →
  `return false`, line 210) or the component is missing (line 192). Invoked by the executor
  (`executor.php:157`), the orchestrator (`orchestrator.php:235`) and the interpreter
  (`interpreter.php:1109`).
- **Gate 2 (native Moodle capability of the underlying action)** is enforced centrally by
  `native_capability_guard::missing_capabilities()` at the **operating** context — both in the
  preflight pipeline before `skill::preflight` (no guard token minted on denial,
  `preflight_pipeline.php:207`) and again as the authoritative backstop in the executor immediately
  before `execute()` (`executor.php:266`). Non-resolvable operating context ⇒ fail-closed "all
  missing" (`native_capability_guard.php:67-70`).
- **Execution guard token** binds `sha256(skill : operating_context : normalized_input)` and is
  re-verified with `hash_equals` in the executor for every mutating command
  (`executor.php:235`); empty token ⇒ fail-closed (section 08 confirmed).
- **Cross-context safety:** a mutating module-targeted skill whose operating context did not
  resolve to a `context_module` is refused with `CONTEXT_TARGET_UNRESOLVED`
  (`executor.php:191-203`).

The model is correct: the agent can never grant a right the acting `$USER` does not natively hold
at the context the action operates on, even for a crafted/replayed command.

### IDOR / ownership — verified

Every WS that accepts a client-supplied `threadid` passes it through
`conversation_store::thread_belongs_to_user($threadid, $USER->id, $contextid)` before reading or
mutating thread-scoped data, and falls back to a neutral/empty result on mismatch:
`ai_poll_thread.php:93`, `ai_confirm_run.php:127`, `ai_discard_pending.php:81`,
`ai_get_thread_debug_logs.php:93`; `ai_send_message.php:232` uses the equivalent
`get_owned_active_thread()` and silently creates a fresh thread if the id is not owned. The store
query is keyed on `(id, userid, contextid)` (`conversation_store.php:300-305`). User-memory
(`user_memory_service.php`) scopes every read/write/delete on `userid` (delete requires
`['id'=>$id,'userid'=>$userid]`, line 173-177), and the `userid` always originates from the
authenticated session, never from request input.

### SQL injection — clean

Grepped every `*_sql`/`execute`/`*_select` call across `classes/ cli/ *.php` (92 sites). No user
input is concatenated into SQL. The only dynamic-string SQL is `queue_manager.php:390`
(`... WHERE id = :id{$forupdate}` where `$forupdate` is a literal chosen from
`$DB->get_dbfamily()`) and the privacy provider's `{" . $table . "}` (table name from a private
`const` allowlist) — both safe. All value bindings use named placeholders.

### Secrets — never logged

The only thing written to the `bx_agent_ai_llm_debug` table as `requesttext` is the prompt text
(`llm_call_service.php:111-121` / `:192-202`). The API key lives on the core_ai provider instance
and is attached to the HTTP transport *inside* `core_ai`, never passed through this plugin's logger
or telemetry. `trial_provisioner` only `debugging()`s exception messages, not the key value.

### Privacy — complete + fail-closed anonymizer

`privacy/provider.php` declares and exports/deletes all **five** user-data tables
(`bx_agent_user_memory`, `bx_agent_ai_threads`, `bx_agent_ai_messages`, `bx_agent_ai_runs`,
`bx_agent_ai_llm_debug`) and the external-LLM location link. The four benchmark tables hold no
per-user PII. The display de-anonymiser **fails closed**: an unresolvable `ANON_USER_*` token is
replaced with the neutral `ai_privacy_redacted_user` string rather than leaking the raw token
(`privacy_anonymizer.php:320-325`).

### CSRF / CLI guards

Every state-changing WS calls `require_sesskey()` (`ai_send_message`, `ai_confirm_run`,
`ai_discard_pending`, `ai_upload_attachment`, `ai_privacy_precheck`, `request_trial_key`,
`activate_trial_context`, `configure_provider_from_existing`, `store_provider_apikey`,
`set_debug_mode`). Read endpoints (`ai_poll_thread`, `ai_get_thread_debug_logs`,
`ai_get_doc_content`) deliberately omit sesskey to stay lock-free — acceptable, they are read-only
and still ownership-gated. All 9 `cli/*.php` declare `CLI_SCRIPT`. The admin POST pages
(`skill_governance.php`, `skill_selection_debug.php`, `benchmark_report.php`) wrap writes in
`confirm_sesskey()`.

---

## B. Findings

### [C1-F01] 🟠 HIGH · D1 Security · benchmark_report.php:31,48
> **✅ FIXED 2026-06-30** (commit `8402580`) — the `pinbaseline` write action now calls `require_capability('bookingextension/agent:managebenchmarks', context_system::instance())` before writing the baseline, so the read-only `viewbenchmarks` cap no longer authorises the write (resolves C2-F03 too).
**What:** A state-changing action (`pinbaseline`, which writes a benchmark baseline row) is gated
only by the **read** capability `bookingextension/agent:viewbenchmarks`, not by a write capability.
**Evidence:** The page guards with `require_capability('bookingextension/agent:viewbenchmarks', context_system::instance())`
(line 31, a `read` captype in `db/access.php:218`). The POST branch
`if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey())` (line 48) then writes a
baseline. There is a dedicated write capability `bookingextension/agent:managebenchmarks`
(`db/access.php:226`, `write`, empty archetypes) that is **never enforced** (see C1-F02), so the
intended privilege separation is not applied.
**Impact:** Any manager (or anyone granted the read-only `viewbenchmarks` cap, e.g. to let them
*view* the report) can also mutate the pinned performance baseline. Blast radius is internal
benchmark tooling, not user/booking data, and sesskey + system-context manager-level access are
still required — so this is a privilege-separation defect, not an unauthenticated hole.
**Compensating control:** `confirm_sesskey()` and system-context manager access required; affects
only benchmark baselines, no PII or booking mutation.
**Recommendation:** Require `bookingextension/agent:managebenchmarks` on the `pinbaseline` write
branch (and any other write actions on benchmark pages), keeping `viewbenchmarks` for read-only
viewing. This also gives `managebenchmarks` its intended consumer (resolves C1-F02).
**Status:** ✅ FIXED 2026-06-30 — the `pinbaseline` POST branch now calls
`require_capability('bookingextension/agent:managebenchmarks', context_system::instance())` before
writing (benchmark_report.php); `viewbenchmarks` still gates read-only viewing. This is also the
first enforcement of `managebenchmarks` (resolves C1-F02 / C2-F03).

### [C1-F02] 🟡 MEDIUM · D1 Security · db/access.php:226
**What:** Capability `bookingextension/agent:managebenchmarks` is defined but enforced nowhere.
**Evidence:** Grep across `classes/ cli/ *.php` for `agent:managebenchmarks` returns only its
definition in `db/access.php`. The benchmark write actions are gated by `viewbenchmarks` or
`moodle/site:config` instead.
**Impact:** Dead capability — administrators see an assignable permission in the role UI that has no
effect, and the intended read/write split for benchmark management is not realised. No direct
exploit; a governance/clarity defect.
**Compensating control:** Writes are still behind `viewbenchmarks`/`site:config` + sesskey.
**Recommendation:** Either wire it onto the benchmark write paths (preferred, see C1-F01) or remove
the unused definition.
**Status:** ✅ FIXED 2026-06-30 — wired onto the `pinbaseline` write path (see C1-F01); the capability
is now enforced, not dead.

### [C1-F03] 🟡 MEDIUM · D1 Security · classes/local/wizard/services/lookup/markdown_renderer.php:228-238 + format_non_doc_link()
**What:** A markdown link whose href carries a dangerous URI scheme (`javascript:`, `data:`,
`vbscript:`) is emitted into an `<a href="…">` unchanged; `htmlspecialchars` does not neutralise the
scheme, only quotes/brackets.
**Evidence:** In `format_non_doc_link()` the branch `if (!empty($parts['scheme'])) { return $raw; }`
returns the href verbatim for ANY scheme. The caller wraps it as
`'<a href="' . htmlspecialchars($safehref, ENT_QUOTES, 'UTF-8') . '">'` (inline_format line 237).
For `[x](javascript:alert(1))`, no quote-breaking is required, so the rendered anchor is a live
`href="javascript:alert(1)"`. The HTML is returned as `PARAM_RAW` from `ai_get_doc_content` and
injected into the preview pane.
**Impact:** Stored/rendered XSS **iff** an attacker can place such markdown into the rendered
corpus. They cannot via the agent: `ai_get_doc_content` resolves the file strictly inside
realpath-contained, registry-whitelisted plugin `docs/` roots, `.md` only, with traversal blocked
(`ai_get_doc_content.php:91-105`). The corpus is shipped plugin documentation, not user content. So
this is a latent hardening gap, not a presently exploitable hole.
**Compensating control:** Source markdown is restricted to the trusted, code-shipped docs corpus
(no user write path); path traversal is blocked; everything else in the renderer is
`htmlspecialchars`-escaped.
**Recommendation:** In `format_non_doc_link()` allow-list schemes (`http`, `https`, `mailto` only)
and drop/neutralise anything else (return `'#'` or strip), so the renderer is safe even if the docs
corpus ever ingests third-party/plugin-contributed markdown.

### [C1-F04] 🟢 LOW · D1 Security · classes/local/wizard/conversation_store.php:791
**What:** `consume_pending_intent()` skips the userid ownership comparison when the stored intent
has no recorded userid (`userid == 0`).
**Evidence:** `if ($userid > 0 && (int)($pending['userid'] ?? 0) > 0 && (int)$pending['userid'] !== $userid)`
— the middle clause means a pending intent persisted without a userid bypasses the per-user check
(same for contextid on the next line).
**Impact:** In isolation, a caller who reached a thread could consume a pending intent not stamped
with their userid. Residual risk is near-zero: the WS layer has already proven thread ownership via
`thread_belongs_to_user` before `confirm_run_service` runs, the acting `$USER->id` is used for the
actual execution, and the mutating command is still guard-token-verified and Gate-2 re-checked at
the operating context against that acting user — so no privilege escalation or cross-user mutation
is possible through this path.
**Compensating control:** Thread-ownership gate at the WS, guard-token verification, Gate-2 backstop
at execute time, all keyed on the authenticated user.
**Recommendation:** Make the comparison unconditional when `$userid > 0` (treat a missing stored
userid as a non-match, fail-closed), and ensure pending intents are always stamped with userid +
contextid at write time.

### [C1-F05] ⚪ INFO · D1 Security · ai_get_thread_debug_logs.php:75-80, ai_get_doc_content.php:73-83, ai_privacy_precheck.php:82-98, ai_upload_attachment.php:110-122
**What:** Several endpoints call `authorization_service::check_use_readiness()` (which internally
resolves + level-validates the context) *before* `context::instance_by_id()` + `validate_context()`
(the WS-token scope check), and a few read endpoints (`ai_get_thread_debug_logs`,
`ai_get_doc_content`) do not call `require_valid_context()` explicitly the way `ai_send_message`
does.
**Evidence:** Ordering differs from `ai_send_message.php:131-134` which does
`require_valid_context()` then `validate_context()` up front.
**Impact:** None — `check_use_readiness()` already runs `resolve_valid_context()` (rejecting invalid
context levels and non-existent ids) and `has_capability()` at that context before any data access,
and `validate_context()` still runs before returning. The capability check is the authoritative
gate; context-level validation happens regardless. Confirmed-correct, noted only for consistency.
**Compensating control:** n/a.
**Recommendation:** None required. Optionally standardise the order across all endpoints for
readability.

### [C1-F06] ⚪ INFO · D1 Security · trial_challenge.php
**What:** Confirmed-correct: the public (login-less) `trial_challenge.php` back-channel only echoes
the supplied token **iff** it exactly matches a value previously cached under
`nonce_<token>` in the `trialnonce` cache.
**Evidence:** `PARAM_ALPHANUMEXT` token, GET-only, `if ($stored !== $token) { http 403 }`, then
`echo $token`. No DB access, no user data, no state change; it is a proof-of-reachability nonce for
the Wunderbyte trial provisioning handshake.
**Impact:** None — an attacker can only echo back a token they already supplied and which was
already minted server-side. Login-less is intentional and the `phpcs:disable RequireLogin` is
justified.
**Compensating control:** n/a.
**Recommendation:** None.

### [C1-F07] ⚪ INFO · D1 Security · ai_upload_attachment.php
**What:** Confirmed-correct: file-upload safety is robust.
**Evidence:** `require_sesskey()`; server-side MIME sniff of the actual bytes via
`finfo(FILEINFO_MIME_TYPE)` (not the browser-declared type) against a hard allow-list
(`ALLOWED_MIMES`); size caps; random temp filename `wizard_<24 hex>.<safe-ext>` from a fixed
extension `match`; `clean_param(..., PARAM_FILE)` on the display name; thumbnail alt is
`htmlspecialchars`-escaped. No path is taken from the client.
**Impact:** None.
**Recommendation:** None.

---

## C. Per-file / per-method checklist (security dimension only)

#### `classes/external/ai_send_message.php` (class `ai_send_message`)
- [x] D1 — file-level: sesskey, context resolve+validate, readiness gate, thread ownership via `get_owned_active_thread`, page-context sanitised (whitelist+strip_tags+length cap), output via `clean_text` formatter
  - [x] `execute_parameters()` / `execute_returns()` — typed params, PARAM_* correct
  - [x] `execute()` — D1✓ (sesskey, ownership, anonymiser pre/post)
  - [x] `private normalize_string_list()` / `resolve_response_queue_item_id()` / `resolve_response_commands()` / `encode_phase_trace_for_response()` / `sanitize_page_context()` — clean (sanitiser whitelists keys, coerces ints, strips markup)

#### `classes/external/ai_poll_thread.php`
- [x] D1 — read endpoint, no-sesskey-by-design documented, `thread_belongs_to_user` gate, display de-anonymise fail-closed
  - [x] `execute()` — clean

#### `classes/external/ai_confirm_run.php`
- [x] D1 — sesskey, context resolve+validate, readiness gate, `thread_belongs_to_user` gate, delegates to `confirm_run_service`
  - [x] `execute()` — clean

#### `classes/external/ai_discard_pending.php`
- [x] D1 — sesskey, readiness, `thread_belongs_to_user` gate
  - [x] `execute()` — clean

#### `classes/external/ai_get_thread_debug_logs.php`
- [x] D1 — readiness, debug-mode gate (`llm_debug_logger::is_enabled()`), `thread_belongs_to_user` gate, limit clamped 1..500
  - [x] `execute()` — clean (C1-F05 ordering INFO only)

#### `classes/external/ai_get_doc_content.php`
- [x] D1 — readiness gate; realpath containment inside registry-whitelisted corpus root; `.md`-only; traversal blocked; output escaped by `markdown_renderer`
  - [x] `execute()` — clean (renderer scheme gap tracked in C1-F03, not reachable here)

#### `classes/external/ai_upload_attachment.php`
- [x] D1 — see C1-F07 (confirmed-correct: sesskey, server MIME sniff, size cap, random name, escaped alt)
  - [x] `execute()` / `error_response()` / `safe_extension()` / `build_thumbnail_html()` — clean

#### `classes/external/ai_privacy_precheck.php`
- [x] D1 — sesskey, readiness, context validate; returns sanitized message
  - [x] `execute()` — clean

#### `classes/external/request_trial_key.php`
- [x] D1 — sesskey, readiness, `require_capability(requesttrial, SYSTEM)`, GDPR consent gate, audit event
  - [x] `execute()` — clean

#### `classes/external/activate_trial_context.php`
- [x] D1 — sesskey, readiness, `require_capability(requesttrial, SYSTEM)`, `$DB->set_field` parameterised
  - [x] `execute()` — clean

#### `classes/external/configure_provider_from_existing.php` / `store_provider_apikey.php`
- [x] D1 — sesskey, readiness, `require_capability(requesttrial, SYSTEM)`; key is config write, never logged/echoed
  - [x] `execute()` — clean

#### `classes/external/set_debug_mode.php`
- [x] D1 — sesskey, `require_capability('moodle/site:config', SYSTEM)` — true admin only
  - [x] `execute()` — clean

#### `classes/external/ws_message_formatter.php`
- [x] D1 — `clean_text(markdown_to_html(...), FORMAT_HTML)` purifies HTML (XSS-safe), deliberately not `format_text`
  - [x] `format_ws_message()` — clean

#### `classes/local/wizard/services/security/authorization_service.php`
- [x] D1 — Gate-1 use cap, engine-active chokepoint, fail-closed `check_use_readiness`, level-allowlist `resolve_valid_context`
  - [x] all methods — clean

#### `classes/local/wizard/services/security/native_capability_guard.php`
- [x] D1 — Gate-2 at operating context, fail-closed on unresolvable context
  - [x] `missing_capabilities()` — clean

#### `classes/local/wizard/skill_executability_evaluator.php`
- [x] D1 — Gate-1 governance + engine-derived name capability, fail-closed on undefined cap / missing component
  - [x] `has_required_capabilities()` / `is_valid_context()` / `deny_result()` — clean

#### `classes/local/wizard/executor.php`
- [x] D1 — `require_use_capability` + `require_valid_context`; Gate-1 eval; guard-token verify (`hash_equals`); Gate-2 backstop; cross-context module guard
  - [x] `execute_commands()` (security path) — clean

#### `classes/local/wizard/conversation_store.php`
- [x] D1 — `(id,userid,contextid)`-keyed ownership; parameterised SQL throughout
  - [x] `thread_belongs_to_user()` / `get_owned_active_thread()` — clean
  - [ ] `consume_pending_intent()` — see C1-F04 (LOW, userid==0 skip; defended downstream)

#### `classes/local/wizard/services/user_memory_service.php`
- [x] D1 — every read/write/delete `userid`-scoped; delete requires `(id,userid)`
  - [x] all methods — clean

#### `classes/privacy/provider.php`
- [x] D1 — all 5 user-data tables covered (metadata + export + delete), external-LLM link, parameterised deletes
  - [x] all methods — clean

#### `classes/local/wizard/privacy_anonymizer.php`
- [x] D1 — display gate fails closed (`ai_privacy_redacted_user`), token re-anchoring dedupes by identity key
  - [x] `deanonymize_message_for_display()` and helpers — clean

#### `classes/local/wizard/services/llm/llm_call_service.php` + `llm_debug_logger.php`
- [x] D1 — only prompt text logged as `requesttext`; API key never passed to the logger
  - [x] `invoke_*` / `log_exchange*` — clean

#### `classes/local/hooks/page_injection.php`
- [x] D1 — `seemagicwand` cap at page context; not an authz source (server re-checks); free `$PAGE` scalars only
  - [x] `extend_head()` / `current_page_context()` — clean

#### `lib.php`
- [x] D1 — fragment callback `require_capability('useaiinstructions', $context)`; reached only via authenticated `core_get_fragment`
  - [x] `bookingextension_agent_output_fragment_aipanel()` — clean

#### `skill_governance.php`
- [x] D1 — `require_capability('managegovernance', SYSTEM)`; POST wrapped in `data_submitted() && confirm_sesskey()`; all interpolated output via `s()`
  - [x] page body — clean

#### `skill_selection_debug.php`
- [x] D1 — `require_capability('debugskillselection', SYSTEM)`; `confirm_sesskey()` on action
  - [x] page body — clean

#### `benchmark_compare.php` / `benchmark_run_detail.php`
- [x] D1 — `require_login` + `require_capability('moodle/site:config', SYSTEM)`; parameterised SQL
  - [x] page body — clean

#### `benchmark_report.php`
- [x] D1 — see C1-F01 (HIGH: write action `pinbaseline` gated only by read cap `viewbenchmarks`) — FIXED 2026-06-30: `pinbaseline` now requires `managebenchmarks`

#### `trial_challenge.php`
- [x] D1 — see C1-F06 (confirmed-correct public nonce echo)

#### `cli/*.php` (9 files)
- [x] D1 — all declare `CLI_SCRIPT`; run under admin CLI privileges as intended

#### `db/access.php`
- [ ] D1 — see C1-F02 (`managebenchmarks` defined but unused). All used caps are defined; all referenced caps resolve. Skill caps generated as `<component>:skill_<suffix>` with role-scoped archetypes.

#### `db/services.php`
- [x] D1 — every function carries a `capabilities` gate; `set_debug_mode` correctly bound to `moodle/site:config`; all `ajax => 1`

---

## D. Top blockers

**No BLOCKER findings.** The security-critical machinery — two-gate authorization (Gate 1 governance
+ engine-derived per-skill cap at ambient context; Gate 2 native cap at operating context, enforced
in both preflight and executor), guard-token binding with `hash_equals`, thread/user IDOR ownership
gating on every threadid-accepting endpoint, fully parameterised SQL, API keys never logged, a
complete and fail-closed privacy provider + anonymizer display gate, sesskey on all writes, and
CLI/admin-page gating — is correct and defensible for go-live.

**Pre-launch fix recommended (HIGH):**
- **C1-F01** — gate the benchmark `pinbaseline` write on a write capability
  (`bookingextension/agent:managebenchmarks`) instead of the read-only `viewbenchmarks`. Low blast
  radius (internal benchmark tooling, no PII), but it is a real privilege-separation defect and
  fixing it also gives the orphaned `managebenchmarks` cap (C1-F02) its purpose.

**Schedule soon (MEDIUM):** C1-F02 (unused cap — resolved by C1-F01), C1-F03 (allow-list URL schemes
in the docs markdown renderer to harden against any future untrusted-corpus ingestion).

**Backlog (LOW):** C1-F04 (make pending-intent userid match unconditional / always stamp userid).
