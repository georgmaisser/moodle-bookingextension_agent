# Audit Section 01 — Entry & Web Services

**Scope:** `classes/external/*.php` (14 files), `classes/shortcodes.php`, `db/services.php`, `db/shortcodes.php`  ·  **Files audited:** 17  ·  **Methods audited:** 44
**Arch chapter(s):** docs/architecture/01-entry-and-web-services.md  ·  **Flowchart nodes:** ENTRY (ASM, ASM_GATE, ASM_FAIL, ASM_UPLOAD, ASM_ATTACH, ACR, ACD, APO, APREVIEW)
**Auditor verdict:** ✅ clean (no BLOCKER/HIGH) — minor doc-lag + one defense-in-depth note

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | Every WS resolves context via `context::instance_by_id(..., MUST_EXIST)`, gates on `check_use_readiness()` (`has_capability('useaiinstructions')` at the resolved context) and `validate_context()` (WS-token scope). State-changing endpoints all call `require_sesskey()`; read endpoints intentionally omit it. IDOR closed: every client-supplied `threadid` passes `thread_belongs_to_user()`/`get_owned_active_thread()`. No SQL in these files (delegated to parameterised store). Upload sniffs real MIME + size caps + random temp name. One LOW: `ai_poll_thread` returns LLM step text as PARAM_RAW without server-side purification (client escapes). |
| D2 Moodle API      | pass | Correct `external_api` shape (`execute_parameters`/`execute`/`execute_returns`), `PARAM_*` on every field, `get_string` for all user strings, `validate_parameters`, `make_temp_directory`, `cache` API for tokens, events API (`trial_consent_given`). One INFO: trial/admin functions declare `useaiinstructions` in `db/services.php` but enforce the stronger `requesttrial`/`site:config` in code. |
| D3 Structure       | pass | Thin WS adapters delegating to services (`confirm_run_service`, `discard_pending_service`, `trial_provisioner`, `attachment_processor`). No engine→domain leak. `ws_message_formatter` is the shared formatter (no duplication of `clean_text` calls). No dead code (all classes are framework-invoked WS entry points; `shortcodes::wbbagent` is a `db/shortcodes.php` callback). |
| D4 Duplication     | pass | The repeated error-payload literal in `ai_send_message` (readiness vs empty-message) and the readiness→error pattern across endpoints are acceptable WS-contract boilerplate, not extractable logic. Noted as INFO. |
| D5 Flowchart       | issues | ASM node lists 4 params; code now passes a 5th, `pagecontext` (01-F02, doc-lag). Otherwise ENTRY subgraph matches: readiness gate, attachments pipeline, preview passthrough, auto-confirm wrapper all present as drawn. |
| D6 Docs coverage   | issues | Chapter §1 says "Ten functions are registered" — `db/services.php` registers **13** (omits `configure_provider_from_existing`, `store_provider_apikey`, `set_debug_mode`); §2 omits the `pagecontext` param; §3 readiness table omits `actions_missing` and mis-states `exception_thrown` mapping (01-F01). |

---

## B. Findings

### [01-F01] 🟢 LOW · D6 Docs coverage · docs/architecture/01-entry-and-web-services.md §1, §2, §3
**What:** The chapter under-counts and under-describes the registered services and the readiness map.
**Evidence:**
- §1: "Ten functions are registered as web services" and a 10-row table. `db/services.php` registers **13** functions; the chapter never lists `configure_provider_from_existing`, `store_provider_apikey`, or `set_debug_mode`.
- §2 parameter table for `ai_send_message` lists 4 params; `execute_parameters()` declares a 5th, `pagecontext` (`ai_send_message.php:84-90`).
- §3 readiness table lists 6 reasons but omits `actions_missing` (mapped at `ai_send_message.php:194`), and states `exception_thrown → ai_provider_error` whereas the code maps it to `error_ai_internal_status` (`ai_send_message.php:201-203`).
**Impact:** A maintainer relying on the chapter would miss three admin endpoints, the page-context input, and the true internal-error string. No runtime effect.
**Compensating control:** `db/services.php` and the code are authoritative and self-consistent.
**Recommendation:** Update §1 to "thirteen functions" + add the three rows; add `pagecontext` to §2; add `actions_missing` and correct the `exception_thrown` row in §3.

### [01-F02] 🟢 LOW · D5 Flowchart · docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd:8 (node ASM)
**What:** The `ASM` node label lists `contextid · message · threadid · attachments[]` but `ai_send_message::execute()` now accepts a fifth parameter `pagecontext`.
**Evidence:** `flowchart .mmd:8` vs `ai_send_message.php:84-90` (`pagecontext`, sanitised by `sanitize_page_context()` and stored as thread metadata `_page_context`).
**Impact:** Doc-lag only; the diagram understates the entry surface. No behavioural deviation.
**Compensating control:** n/a.
**Recommendation:** Append `· pagecontext` to the ASM node label (and note the `sanitize_page_context` whitelist step).

### [01-F03] 🟢 LOW · D1 Security · classes/external/ai_poll_thread.php:113-124
**What:** `ai_poll_thread` returns LLM-derived step-message `content` as `PARAM_RAW` after de-anonymisation but **without** server-side HTML purification — unlike `ai_send_message`/`ai_confirm_run`, which route their message through `ws_message_formatter::format_ws_message()` (`clean_text`).
**Evidence:** `ai_poll_thread.php:115-123` builds `content` from `deanonymize_message_for_display()` and returns it; the return schema declares `content` as `PARAM_RAW` (`:142`). The step text originates from the planner's `next_step_intent` (LLM output).
**Impact:** If any consumer ever inserted this field as raw HTML, LLM-influenced markup could execute. The sole current consumer (`amd/src/aiinstructions.js:1772` → `appendStepBubble` → `renderTextWithLinks`, `:1053-1082`) escapes all non-link text via `escapeHtml` and only emits links via `renderSmartLink`, so there is **no live XSS**.
**Compensating control:** Client-side `escapeHtml` in the only consumer; step bubbles are ephemeral and self-authored per turn.
**Recommendation:** For defense-in-depth and parity with the other endpoints, purify step content server-side (`clean_text`) before returning it, so a future second consumer cannot regress into an XSS.

### [01-F04] ⚪ INFO · D2 Moodle API · db/services.php:85-116 vs the trial/admin classes
**What:** The four onboarding/admin write functions (`request_trial_key`, `activate_trial_context`, `configure_provider_from_existing`) declare `capabilities => 'bookingextension/agent:useaiinstructions'` in `db/services.php`, but their `execute()` bodies enforce the stronger `require_capability('bookingextension/agent:requesttrial', context_system::instance())`. (`store_provider_apikey` correctly declares `requesttrial`; `set_debug_mode` correctly declares `moodle/site:config`.)
**Evidence:** `db/services.php:90,98,106` (declared `useaiinstructions`) vs `request_trial_key.php:91`, `activate_trial_context.php:81`, `configure_provider_from_existing.php:79` (enforced `requesttrial`).
**Impact:** None — the WS `capabilities` field is an *advisory pre-filter*; the authoritative check is the in-body `require_capability`. The mismatch is purely cosmetic/inconsistent with `store_provider_apikey`.
**Compensating control:** In-body `require_capability` at `context_system`.
**Recommendation:** Align the three declarations to `requesttrial` for consistency and accurate WS-admin display.

### [01-F05] ⚪ INFO · D1 Security · ai_get_thread_debug_logs.php, ai_get_doc_content.php, ai_privacy_precheck.php, ai_upload_attachment.php, ai_discard_pending.php
**What:** Several endpoints call `check_use_readiness()` (which internally `resolve_valid_context()` + `has_capability()`) **before** `context::instance_by_id()` + `validate_context()`, and `ai_discard_pending`/`ai_get_thread_debug_logs` do not call `require_valid_context()` the way `ai_send_message` does.
**Evidence:** e.g. `ai_discard_pending.php:72` (readiness) precedes `:75-76` (`instance_by_id` + `validate_context`); cf. `ai_send_message.php:131-134`.
**Impact:** None. `check_use_readiness()` already rejects invalid/non-existent contexts (`resolve_valid_context` → `MUST_EXIST` + level whitelist) and runs `has_capability()` at that context before any data access; `validate_context()` (WS-token scope) still runs before returning data. Confirms cross-cutting C1-F05.
**Compensating control:** The capability check is the authoritative gate and runs first.
**Recommendation:** Optionally standardise the ordering across endpoints for readability. No security change required.

### [01-F06] ⚪ INFO · D1 Security · classes/external/ai_upload_attachment.php (confirmed-correct)
**What:** File-upload safety is robust and is recorded here as a positive confirmation.
**Evidence:** `require_sesskey()` (`:95`); server-side MIME sniff of the *actual* bytes via `finfo(FILEINFO_MIME_TYPE)` against the hard `ALLOWED_MIMES` allow-list (`:137-142`); per-type size caps (`:147-155`); random temp name `wizard_<24 hex>.<safe-ext>` from a fixed `match` (`:159-161`); `clean_param(..., PARAM_FILE)` on the display name (`:105`); thumbnail alt escaped with `htmlspecialchars(ENT_QUOTES)` (`:287`); token is 256-bit `bin2hex(random_bytes(32))`, PARAM_ALPHANUMEXT-safe (`attachment_token_service.php:57`).
**Impact:** None — no defect.
**Recommendation:** None.

---

## C. Per-file / per-method checklist

#### `classes/external/ai_send_message.php`  (class `ai_send_message`)
- [x] D1 [x] D2 [x] D3 [x] D4 [ ] D5 [ ] D6 — file-level (see 01-F02 D5, 01-F01 D6)
- methods:
  - [x] `execute_parameters()`            — D1✓ D2✓ (5 params, all PARAM_*)
  - [x] `execute()`                       — sesskey✓, context MUST_EXIST✓, readiness gate✓, double provider-status gate✓, IDOR via `get_owned_active_thread`✓, privacy precheck✓, write_close✓
  - [x] `normalize_string_list()`         — clean
  - [x] `resolve_response_queue_item_id()`— clean
  - [x] `resolve_response_commands()`     — clean (reads owned queue item only)
  - [x] `encode_phase_trace_for_response()` — clean
  - [x] `sanitize_page_context()`         — whitelist keys, strip_tags, length cap, int coercion — D1✓
  - [x] `execute_returns()`               — clean

#### `classes/external/ai_confirm_run.php`  (class `ai_confirm_run`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — `queue_item_id` PARAM_ALPHANUMEXT✓, allow_session PARAM_BOOL✓
  - [x] `execute()`             — sesskey✓, readiness✓, `thread_belongs_to_user` ownership gate (`:127-148`)✓, delegates to `confirm_run_service`✓
  - [x] `execute_returns()`     — clean

#### `classes/external/ai_discard_pending.php`  (class `ai_discard_pending`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (D6 covered by 01-F01)
- methods:
  - [x] `execute_parameters()`  — clean
  - [ ] `execute()`            — see 01-F05 (D1 ordering, INFO; readiness before validate_context) — otherwise sesskey✓, ownership gate `:81`✓
  - [x] `execute_returns()`     — clean

#### `classes/external/ai_poll_thread.php`  (class `ai_poll_thread`)
- [x] D1-file via consumer escaping [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — clean (no sesskey by design — read endpoint)
  - [ ] `execute()`            — see 01-F03 (D1, LOW: PARAM_RAW step content unpurified server-side); ownership gate `:93`✓, auto-resolve guarded✓
  - [x] `execute_returns()`     — clean

#### `classes/external/ai_get_doc_content.php`  (class `ai_get_doc_content`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — `corpus_id` PARAM_ALPHANUMEXT, `path` PARAM_PATH
  - [x] `execute()`            — readiness✓; registry-only corpus root, `realpath` + containment (`strpos===0`) + `.md` requirement traversal hardening (`:91-105`)✓ — D1✓
  - [x] `execute_returns()`     — clean

#### `classes/external/ai_get_thread_debug_logs.php`  (class `ai_get_thread_debug_logs`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — clean
  - [ ] `execute()`            — see 01-F05 (D1 ordering, INFO); `is_enabled()` debug gate✓ + `thread_belongs_to_user` `:93`✓ (no cross-user log leak)
  - [x] `execute_returns()`     — clean

#### `classes/external/ai_privacy_precheck.php`  (class `ai_privacy_precheck`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — clean
  - [x] `execute()`            — sesskey✓, readiness✓, thread is own (`get_or_create`/`create_fresh`)✓
  - [x] `execute_returns()`     — clean

#### `classes/external/ai_upload_attachment.php`  (class `ai_upload_attachment`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (see 01-F06 positive)
- methods:
  - [x] `execute_parameters()`  — clean
  - [x] `execute()`            — sesskey✓, finfo MIME allow-list✓, size caps✓, random temp name✓, clean_param filename✓
  - [x] `execute_returns()`     — clean
  - [x] `error_response()`      — clean
  - [x] `safe_extension()`      — fixed `match`, default `bin`✓
  - [x] `build_thumbnail_html()`— GD-guarded, `htmlspecialchars(ENT_QUOTES)` alt✓

#### `classes/external/activate_trial_context.php`  (class `activate_trial_context`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (see 01-F01, 01-F04)
- methods:
  - [x] `execute_parameters()`  — clean
  - [x] `execute()`            — sesskey✓, `require_capability(requesttrial, system)` `:81`✓, `core_ai\manager` presence guard✓, endpoint-based provider enable (no name heuristic)✓, parameterised `set_field`✓
  - [x] `execute_returns()`     — clean

#### `classes/external/request_trial_key.php`  (class `request_trial_key`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (see 01-F04)
- methods:
  - [x] `execute_parameters()`  — `strategy` PARAM_ALPHA, `consented` PARAM_BOOL
  - [x] `execute()`            — sesskey✓, `require_capability(requesttrial)`✓, GDPR consent gate✓, consent audit event✓, strategy whitelist✓
  - [x] `execute_returns()`     — clean

#### `classes/external/configure_provider_from_existing.php`  (class `configure_provider_from_existing`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (see 01-F04)
- methods:
  - [x] `execute_parameters()`  — clean
  - [x] `execute()`            — sesskey✓, `require_capability(requesttrial)`✓, delegates to provisioner✓
  - [x] `execute_returns()`     — clean

#### `classes/external/store_provider_apikey.php`  (class `store_provider_apikey`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — `apikey` PARAM_RAW (key, not echoed)
  - [x] `execute()`            — sesskey✓, `require_capability(requesttrial)`✓; key written to provider config, never logged✓
  - [x] `execute_returns()`     — clean

#### `classes/external/set_debug_mode.php`  (class `set_debug_mode`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()`  — clean
  - [x] `execute()`            — sesskey✓, `require_capability('moodle/site:config', system)` `:68`✓ (true admin only)
  - [x] `execute_returns()`     — clean

#### `classes/external/ws_message_formatter.php`  (class `ws_message_formatter`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `format_ws_message()`   — `clean_text(markdown_to_html(...))` — XSS-purifies, runs NO filters (no shortcode expansion). Correct per `feedback_no_format_text_on_llm_answer`. `$context` retained for API stability (intentional, documented).

#### `classes/shortcodes.php`  (class `shortcodes`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `render_shortcode_warning()` — `\s(\get_string(...))` escaped✓
  - [x] `wbbagent()`           — engine-active gate✓, `hash_equals` security-token check (`:91`)✓, try/catch page-safety✓; server-side caps still enforced on every agent call (panel renders "not ready" otherwise). Framework-invoked via `db/shortcodes.php` (not dead).

#### `db/services.php`  (data file)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [ ] D6 — registers 13 functions; all `ajax=1`; caps declared (see 01-F04 advisory-vs-enforced note; 01-F01 chapter count).

#### `db/shortcodes.php`  (data file)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — single `wbbagent` callback → `shortcodes::wbbagent`. Correct.

---

## D. Go-live blockers from this section

None. No BLOCKER or HIGH findings. The entry layer's authorization (capability + sesskey split + IDOR ownership gating), upload hardening, doc-path traversal hardening, and secrets handling are all sound. Remaining items are LOW doc-lag (01-F01, 01-F02), one LOW defense-in-depth hardening (01-F03 — purify `ai_poll_thread` step content server-side), and two INFO consistency notes (01-F04, 01-F05).
