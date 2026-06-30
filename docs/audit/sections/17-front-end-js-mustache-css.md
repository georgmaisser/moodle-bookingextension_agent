# Audit Section 17 — Front-end: JS, Mustache, CSS

**Scope:** `amd/src/{aiinstructions.js, benchmark_trend_chart.js, navbar_magic_wand.js}`, `amd/build/*`, `templates/{aiinstructions.mustache, benchmark_trend_chart.mustache, trial_consent_modal.mustache}`, `styles.css`, `classes/external/ws_message_formatter.php`  ·  **Files audited:** 8 (+ `classes/local/wizard/doc_markdown_preview_renderer.php` deferred to §06)  ·  **Methods audited:** ~85 (79 JS arrow-fns in aiinstructions.js + 2 small JS modules + 1 PHP formatter method)
**Arch chapter(s):** docs/developer-guides/web-services-api.md (client side); cross-ref docs/architecture/01-entry-and-web-services.md
**Flowchart nodes:** client of `ENTRY` (no owned `.mmd` node — the front-end is a consumer of the entry WS layer)
**Auditor verdict:** ⚠️ issues (no blocker)

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | No client-side XSS sink that is fed un-sanitised user/LLM content. Every `innerHTML` of free text routes through `escapeHtml()` or `renderTextWithLinks()`; every `innerHTML` of raw HTML (`displaymessage`, doc preview, skill preview) is server-sanitised upstream (`clean_text` formatter; corpus renderer). One latent gap is server-side only and already tracked as **C1-F03**. No raw `fetch`/XHR; all 13 WS calls go through `core/ajax` (sesskey auto-attached). No `eval`/`new Function`/global leaks. |
| D2 Moodle API      | issues | A cluster of user-visible English string literals is hard-coded in JS/Mustache instead of `get_string`/`{{#str}}` (17-F02), against the project's "alle Strings über get_string" rule. AMD/ES-module shape, `{{#js}}` boot, `core/fragment` + `core/templates` usage all correct. |
| D3 Structure       | issues | `READ_ONLY_SKILLS` hard-codes `booking.*`/`entities.*`/`shopping_cart.*` domain skill names in the generic engine front-end — an engine→domain leak + duplication of server risk classification (17-F03). Otherwise single-purpose modules, lazy navbar init as designed. |
| D4 Duplication     | issues | Trial-result error rendering (`alert-danger` + `renderTextWithLinks`) is copy-pasted across 6 sites in the trial/activation flow (17-F04); `READ_ONLY_SKILLS` duplicates server-side read-only classification (17-F03). |
| D5 Flowchart       | n/a | This section owns no `.mmd` node; it is a client of `ENTRY`. Behaviour (attachments upload, confirm/discard, polling) is consistent with the `ENTRY` subgraph and flowchart-guide §2026-06-10 navbar notes. No deviation. |
| D6 Docs coverage   | issues | `developer-guides/web-services-api.md` documents the server WS contract accurately, but no chapter documents the **client** rendering/sanitisation contract (the `renderAssistantMessageHtml` "HTML passthrough vs escaped-text" decision, the `dispatchSkillPreview` server-named-AMD-module load, the navbar inline-vs-modal reuse). 17-F05. |

## B. Findings

### [17-F01] ⚪ INFO · D1 Security · amd/src/aiinstructions.js:1099-1110, 578, 604-615, 1246, 684-720
**What:** The client renders three classes of server HTML verbatim into `innerHTML` and trusts the server to have sanitised them: the assistant reply (`displaymessage`/`message`), the doc-preview pane (`ai_get_doc_content` → `resp.html`), and skill previews (`previewjson.html` + collected `{{#js}}`).
**Evidence:** `renderAssistantMessageHtml()` (1099): `if (/<\/?[a-z][\s\S]*>/i.test(raw)) { return raw; }` — any tag-looking content is returned **unescaped** into the bubble at 517/578/2231. The reply is server-formatted by `ws_message_formatter::format_ws_message()` which is `clean_text(\markdown_to_html($message), FORMAT_HTML)` — purifies XSS, runs no filters (deliberately not `format_text`, so `[bookingoptionview]` shortcodes stay literal). `loadDocInPreview()` injects `String(resp.html)` raw (1246); `setSidePreviewHtml()` injects `previewDescriptor.html` raw / via `Templates.replaceNodeContents` with collected JS (604-615, 685).
**Impact:** No client-exploitable XSS: the sanitisation boundary is the server. The three HTML sources are (a) `clean_text`-purified LLM output, (b) the realpath-contained, `.md`-only, registry-whitelisted shipped docs corpus, (c) skill-authored server-rendered previews. The plain-text fallback path (`renderTextWithLinks`) escapes via `escapeHtml`.
**Compensating control:** Server-side `clean_text` formatter (XSS-safe), corpus path-hardening + `htmlspecialchars` in the renderer, server-built previews.
**Recommendation:** None required. Keep the invariant documented (see 17-F05) so a future change cannot quietly route un-`clean_text`'d content to `displaymessage`. Note the **upstream** latent gap is tracked in **C1-F03** (`markdown_renderer.php` emits `javascript:`/`data:` link schemes verbatim into the very `resp.html` this code injects — fix belongs server-side).

### [17-F02] 🟡 MEDIUM · D2 Moodle API · amd/src/aiinstructions.js:37-39, 450-451, 1158, 1232, 1241, 1255, 1819; benchmark_trend_chart.mustache n/a
**What:** Several user-visible strings are hard-coded English literals in JS instead of being passed in from PHP via `get_string`, contradicting the project rule that every user-visible string goes through `get_string` bound to the output language.
**Evidence:** Module-scope defaults `'Privacy check running...'` / `'Privacy note: personal data…'` / `'Thinking...'` (37-41); `'Please select the topic you mean'` + `'I found multiple matching documentation entries.'` (450-451) — these render even when the localised `data-*` labels exist; iframe `title="Documentation preview"` (1158); `'Loading documentation…'` (1232), `'No content available.'` (1255); the all-thread debug panel header `'📋 All LLM Debug Logs (Thread)'` (1819). Upload fallback `'Upload failed.'` (1883). Some are reachable only in debug mode, but the ambiguity-picker labels (450-451) and doc-preview status (1232/1255) are user-facing in normal use.
**Impact:** Non-English sites see English fragments in the agent UI; the privacy/ambiguity copy is exactly the kind of user-facing text the rule targets.
**Compensating control:** The high-traffic chat copy (placeholders, buttons, trial flow) is correctly `{{#str}}`/`data-*`-driven; only secondary panels leak literals.
**Recommendation:** Pass these via `data-*` attributes (as the trial/privacy labels already are) or a `core/str`/`get_strings` request, keyed in `lang/en/bookingextension_agent.php`. Lowest-effort: route the ambiguity-picker and doc-preview status strings through the existing `runtimeConfig` label channel.

### [17-F03] 🟡 MEDIUM · D3 Structure (engine→domain leak) · amd/src/aiinstructions.js:70-99
**What:** `READ_ONLY_SKILLS` hard-codes ten domain skill identifiers (`booking.search_options`, `entities.search`, `shopping_cart.get_items`, …) inside the generic agent front-end, and `shouldAutoExecuteReadOnly()` uses them to decide whether to skip the confirm button.
**Evidence:** Lines 71-82 list `booking.*`/`entities.*`/`shopping_cart.*`; `shouldAutoExecuteReadOnly()` (90-99) returns true only when every command's `skill` is in that list. This is the same read-only/risk classification the server already owns (preflight risk classes, §15).
**Impact:** (1) Engine carries domain knowledge it should not (violates the no-`mod_booking.*`-heuristics-in-engine principle). (2) Drift risk: a server-side read-only skill not added here still shows a confirm button — the failure is *safe* (extra confirmation, never auto-execution of a write), so residual risk is low, but it is a maintainability trap and a duplicated source of truth.
**Compensating control:** Authoritative gate is server-side preflight/decision; this list only suppresses a confirm UI for known-read skills. A write skill omitted from the list cannot be auto-executed (the list is an allow-list, not a deny-list).
**Recommendation:** Drive auto-execution from a server-provided per-command flag (e.g. `cmd.readonly`/`cmd.autoexecutable` already implied by the response), not a client-side skill-name allow-list. Removes the leak and the drift.

### [17-F04] 🟢 LOW · D4 Duplication · amd/src/aiinstructions.js:2551-2556, 2568-2572, 2651-2653, 2659-2661, 2729-2742, 2753-2756, 2798-2817
**What:** The trial/activation flow repeats the same `alert-danger`/`text-danger` + `renderTextWithLinks(resp.message || default)` error-bubble construction across ~6 `.then()/.catch()` pairs in `requestTrialKey`, `storeProviderApiKey`, `activateTrialContext`, `configureFromExistingProvider`.
**Evidence:** Lines listed above all build `'<div class="alert alert-danger mb-0">…' + renderTextWithLinks(...) + '</div>'` (or the `text-danger` span variant) inline.
**Impact:** Maintenance only; markup drift between near-identical error paths.
**Compensating control:** None needed; pure cleanup.
**Recommendation:** Extract a `renderTrialError(container, message)` / `renderInlineError(container, message)` helper and call it from each branch.

### [17-F05] 🟢 LOW · D6 Docs coverage · docs/developer-guides/web-services-api.md
**What:** The web-services-api guide documents the server WS contract well but never documents the **client** rendering/sanitisation invariants this section depends on.
**Evidence:** No chapter states (a) the `renderAssistantMessageHtml` "tag-detected ⇒ passthrough, else escaped-text" rule and its reliance on the server `clean_text` formatter; (b) that `dispatchSkillPreview()` will `require()` an arbitrary server-named AMD module (`previewjson.js_module`); (c) the navbar inline-panel-vs-modal reuse contract (`focusInlinePanel`) and the per-tab `sessionStorage['wizard_pagecontext']` channel.
**Impact:** A future maintainer can break the "only `clean_text`'d HTML reaches `displaymessage`" invariant without a documented contract to check against.
**Recommendation:** Add a short "Client rendering & sanitisation contract" subsection to the web-services-api guide (or a new `developer-guides/front-end.md`).

### [17-F06] 🟢 LOW · D3 Structure (dead attribute) · templates/aiinstructions.mustache:29, 113
**What:** `data-sesskey="{{sesskey}}"` is emitted on `#booking-ai-wrapper` (and on the config-clone button at 113) but is never read by any JS in scope.
**Evidence:** `grep sesskey amd/src/aiinstructions.js` → no hits; sesskey is supplied automatically by `core/ajax`. The `data-sesskey` attribute is unused.
**Impact:** Harmless dead markup; mild confusion (suggests manual sesskey handling that does not exist).
**Compensating control:** n/a.
**Recommendation:** Drop the `data-sesskey` attributes unless a non-`core/ajax` consumer needs them.

### [17-F07] ⚪ INFO · D1 Security · amd/src/aiinstructions.js:1850-1860
**What:** The attachment-tray name uses a partial escape (`.replace(/</g, '&lt;')`) rather than `escapeHtml`, and inserts `att.thumbnailHtml` raw.
**Evidence:** `const name = String(att.displayName || '').replace(/</g, '&lt;');` then `${name}` in element-content position; `${thumb}` is raw `att.thumbnailHtml` (server-generated `<img>` from `ai_upload_attachment`).
**Impact:** No XSS — `displayName` sits in element-content position where neutralising `<` alone prevents tag injection; the filename is the user's own local file name. `thumbnailHtml` is server-built.
**Compensating control:** Server generates the thumbnail HTML; name is the uploader's own file.
**Recommendation:** Use `escapeHtml(att.displayName)` for consistency with the rest of the module (defensive, not a current hole).

## C. Per-file / per-method checklist

#### `amd/src/aiinstructions.js` (module `bookingextension_agent/aiinstructions`)
- [x] D1 [ ] D2 [ ] D3 [ ] D4 [x] D5(n/a) [ ] D6 — file-level: see 17-F01..F07
- security-critical methods:
  - [x] `escapeHtml()` — escapes `& < > "` (not `'`; all attrs double-quoted) — clean
  - [x] `renderAssistantMessageHtml()` — passthrough relies on server `clean_text` — 17-F01 (INFO)
  - [x] `renderTextWithLinks()` / `renderSmartLink()` — escape href+label; URL regex limited to http(s) + `/mod|admin|course|local/` (no `javascript:`) — clean
  - [x] `appendMessage()` / `appendMessageHtml()` / `appendPrivacyNote()` — escaped/server-HTML paths correct
  - [x] `setSidePreviewHtml()` / `loadDocInPreview()` — inject server HTML; sanitisation upstream — 17-F01
  - [x] `dispatchSkillPreview()` — `require()`s server-named AMD module (trusted server) — 17-F01/F05
  - [x] `renderAmbiguityOptionsHtml()` / `renderFollowUpSuggestionsHtml()` — `query`/`label`/`title` escaped — clean (literals → 17-F02)
  - [x] `renderAttachmentTray()` — partial-escape name — 17-F07 (INFO)
  - [x] `sendMessage()` / `confirmRun()` / `discardPendingConfirmation()` / `uploadAttachment()` — `Ajax.call` (sesskey auto), no raw fetch — clean
  - [x] `loadUrlInSidePreview()` — `escapeHtml(url)` into `src`, `referrerpolicy=no-referrer` — clean
  - [x] `escapeCssIdentifier()` / `scrollPreviewToFragment()` — `CSS.escape` fallback — clean
  - [x] `init()` / `initCentralBodyHandlers()` / `handleBody{Click,Keydown,Change}()` — delegated handlers, no inline `onclick` — clean
  - [ ] `shouldAutoExecuteReadOnly()` + `READ_ONLY_SKILLS` — see 17-F03 (D3/D4)
  - [ ] trial flow (`requestTrialKey`,`storeProviderApiKey`,`activateTrialContext`,`configureFromExistingProvider`,`reloadAgentPanel`,`bindTrialButton`,`showConsentStep`) — duplicated error markup 17-F04; `renderTextWithLinks` escapes content — clean on D1
  - [x] debug helpers (`renderDebugLogs`,`buildDebugRunHtml`,`refreshThreadDebugLogs`,`formatDebugLogsForClipboard`) — `escapeHtml` on all interpolations; debug-mode gated — clean (literals → 17-F02)
  - [x] remaining ~50 helpers (parse*, build*, handle*, append*, show*, step polling, resizable layout, mobile switch, clipboard) — audited, no un-escaped free-text sink; `Number()`/`String()` coercion throughout — clean

#### `amd/src/navbar_magic_wand.js` (module `bookingextension_agent/navbar_magic_wand`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level: clean
  - [x] `init()` — pure DOM; dynamic imports only; `sessionStorage` page-context best-effort; no requests on load — matches lazy-init memory + flowchart-guide navbar note
  - [x] `focusInlinePanel()` / `loadPanel()` / `getModal()` / `buildButton()` — `buildButton` uses a static FA `<i>` innerHTML (no interpolation); `label` set via `setAttribute` (not innerHTML) — clean

#### `amd/src/benchmark_trend_chart.js` (module `bookingextension_agent/benchmark_trend_chart`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level: clean
  - [x] `init()` — observers + polling to scroll a server-rendered chart; no untrusted input, no innerHTML write — clean

#### `templates/aiinstructions.mustache`
- [x] D1 [ ] D2 [x] D3(minus 17-F06) [x] D4 [x] D5(n/a) [x] D6 — file-level
  - [x] `{{{icon}}}` (363) — server-rendered FA icon snippet from readiness provider — clean
  - [x] `{{{registered_js_modules_json}}}` (384) — server `json_encode` config injected into `{{#js}}` JS context (triple-stache required for valid JSON) — clean
  - [x] all user-facing copy via `{{#str}}`; numeric/id vars double-stache-escaped; `{{provider_install_url}}` escaped into double-quoted href — clean
  - [ ] `data-sesskey` unused — 17-F06; literals N/A (template uses `{{#str}}`)

#### `templates/benchmark_trend_chart.mustache`
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level
  - [x] `{{{charthtml}}}` (31) — server-rendered Moodle Chart HTML (admin benchmark page) — clean; `{{containerid}}` into JS string (37) server-controlled — clean

#### `templates/trial_consent_modal.mustache`
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level: fully `{{#str}}`-based, no user data, no triple-stache — clean

#### `styles.css`
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — plain CSS scoped to `#booking-ai-wrapper`/`.bookingextension-agent-*`; no `expression()`, `@import`, `javascript:` or external `url()` — clean

#### `classes/external/ws_message_formatter.php` (class `ws_message_formatter`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level
  - [x] `format_ws_message(string, context): string` — `clean_text(\markdown_to_html($message), FORMAT_HTML)`; deliberately NOT `format_text` (no shortcode re-render) — matches `feedback_no_format_text_on_llm_answer` memory; `strict_types`, namespaced, correct header — clean. (The `context` param is retained-but-unused per its own docblock — INFO-grade, not raised.)

#### `amd/build/*` (built bundles)
- [x] present and in sync — `amd/build/aiinstructions.min.js` mtime == source mtime (`grunt amd` output current); `.min.js` + `.map` for all three modules — clean

#### `classes/local/wizard/doc_markdown_preview_renderer.php`
- n/a — owned by §06 (embeddings/docs-lookup); excluded here per scope ("if not in 06"). Note: its rendered output is the `resp.html` consumed raw by `loadDocInPreview()` — the relevant client-side observation is 17-F01, and the upstream scheme-escaping gap is cross-cutting **C1-F03**.

## D. Go-live blockers from this section
None. No BLOCKER or HIGH finding. The front-end has no client-side XSS sink fed un-sanitised content; all sanitisation is correctly delegated to the server (`clean_text` formatter, corpus renderer), and all WS calls use `core/ajax` with automatic sesskey. Recommended pre/post-launch cleanups: 17-F02 (i18n of leaked English literals), 17-F03 (remove domain-skill allow-list from the engine front-end). The one latent XSS hardening item touching this section's data flow is server-side and tracked as **C1-F03**.
