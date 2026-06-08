# Preview API Analysis & Design Blueprint
**Date:** 2026-06-08  
**Author:** Georg Maisser / Claude Code  
**Status:** Planning — no code changes

---

## 1. Motivation

Today the preview system is tightly coupled to `mod_booking` booking options. A third-party plugin that contributes its own skills cannot produce any preview output without hacking the core agent files. This document:

1. Inventories every class, method, and JS function involved in the current preview system.
2. Diagnoses the architectural problems.
3. Proposes a flexible, plugin-extensible Preview API.
4. Estimates the implementation effort.

---

## 2. Current Implementation Inventory

### 2.1 PHP — Policy & Allowlist

**`classes/local/wbagent/preview_policy.php`**  
`class preview_policy`

| Method | Role |
|---|---|
| `supports_preview(string $skillname): bool` | Checks suffix of skill name against a **hardcoded array** (`create_option`, `create_slotbooking_option`, `create_selflearning_option`, `update_option`). |
| `filter_previewable_commands(array $commands): array` | Filters a commands array to only those on the allowlist. |
| `has_previewable_command(array $commands): bool` | Returns true if any command is on the allowlist. |

**Problem:** The allowlist is a `private const` inside the class. A plugin outside `bookingextension_agent` has no way to register itself.

---

### 2.2 PHP — External API (preview renderer)

**`classes/external/ai_render_command_preview.php`**  
`class ai_render_command_preview extends external_api`

Called from JS to render visual preview HTML for booking option rows. Parameters:

| Parameter | Purpose |
|---|---|
| `contextid` | Module context |
| `commands` | JSON-encoded command array (for pre-execution confirmation preview) |
| `optionid` | Render a single booking option by id |
| `optionids` | JSON array of option ids |
| `query` | Fulltext query for table-filter mode |
| `limit` | Maximum rows |

**Logic flow:**
1. Runs `preview_policy::filter_previewable_commands()` — silently returns empty HTML if no command qualifies.
2. For `optionid > 0`: calls `new view($cmid, 'showonlyone', $id)` → `get_rendered_showonlyone_table()`.
3. For `optionids` / `query`: builds a `bookingoptions_wbtable` via `render_preview_table()`.
4. For commands only: parses `booking.create_option` or `booking.update_option`, resolves a matching DB row, renders its option table HTML.

**Hardcoded coupling:** `mod_booking\output\view`, `mod_booking\table\bookingoptions_wbtable`, `mod_booking\singleton_service`, `get_coursemodule_from_id('booking', ...)`. This is 100% booking-option-specific.

---

### 2.3 PHP — Interfaces (provider-side option ID memory)

**`interfaces/preview_option_memory_interface.php`**  
`interface preview_option_memory_interface`

| Method | Purpose |
|---|---|
| `remember_last_preview_options_for_execute(int $userid, int $cmid, array $optionids): void` | Store latest executed option IDs for user+cmid. |
| `resolve_last_preview_option_ids_for_execute(int $cmid, int $userid): array` | Retrieve them. |

**`interfaces/preview_option_memory_provider_interface.php`**  
`interface preview_option_memory_provider_interface`

| Method | Purpose |
|---|---|
| `get_preview_option_memory(): ?preview_option_memory_interface` | Skill provider returns a memory helper, or null. |

These interfaces let skill providers keep track of which option IDs were last acted on, so that the confirm-run flow can retrieve them when the execution result doesn't carry `previewoptionids` directly.

---

### 2.4 PHP — Preview ID Aggregation Service

**`services/confirm_preview_option_service.php`**  
`class confirm_preview_option_service`

| Method | Purpose |
|---|---|
| `resolve_preview_option_ids_for_response(int $cmid, int $userid, array $results): array` | Collects `previewoptionids` + `resultid` from raw result entries; falls back to provider memory helpers. |
| `first_preview_option_id(array $ids): int` | Picks first non-zero id. |
| `remember_confirm_preview_option_ids(...)` | Persists aggregated ids in thread metadata. |
| `resolve_confirm_preview_option_ids_for_response(...)` | Merges stored + current ids. |
| `merge_preview_option_ids(...$sources): array` | Utility merge. |

---

### 2.5 PHP — Confirm Run Service (preview fields in response)

**`services/confirm_run_service.php`** — relevant parts:

- `build_preview_response_fields(int $cmid, int $userid, array $results): array` — builds `previewoptionid` and `previewoptionids` keys from aggregated ids.
- Response payload always contains `previewoptionid` (scalar, first) and `previewoptionids` (full array).
- Thread metadata key `_confirm_preview_option_ids` accumulates ids across a multi-step confirm chain.

---

### 2.6 PHP — Skill-Level: `previewmode` / `previewdata` Convention

`execution_feedback_service.php::sanitize_results()` explicitly passes through these keys from every skill result to the JSON payload:

| Key | Type | Semantics |
|---|---|---|
| `previewmode` | string | Identifies the preview renderer type (e.g. `user_profile`, `user_search`) |
| `previewdata` | array | Arbitrary structured payload for that renderer |
| `previewoptionids` | int[] | Booking option row ids to render |
| `docs` | array | `[{path, url}, ...]` for doc-in-preview auto-load |

**Skills currently using these:**

| Skill | `previewmode` | `previewdata` payload |
|---|---|---|
| `core.get_current_user` | `user_profile` | `{fullname, email, userid, ...}` |
| `core.search_users` | `user_search` | `{query, users: [{fullname, email, userid}, ...]}` |
| Booking create/update skills | — | Returns `resultid` (option id), collected via `confirm_preview_option_service` |
| `core.explain_docs` (new) | — | Returns `doc_path`, `doc_url` in result; consumed via `extractFirstDoc()` |

---

### 2.7 JS — Side Preview Panel

The side preview is a single `<div id="booking-ai-side-preview">` that can receive any HTML. It is driven exclusively by calls to `setSidePreviewHtml(html)`.

**JS functions involved in preview:**

| Function | What it does |
|---|---|
| `setSidePreviewHtml(html)` | Replaces inner HTML of `#booking-ai-side-preview`. |
| `renderOptionPreviewsInline(contextid, optionIds)` | Calls `bookingextension_agent_ai_render_command_preview` WS with `optionids`, sets result HTML in side preview. |
| `showConfirmPanel(message, commands)` | Shows `#booking-ai-confirm-panel`, calls `ai_render_command_preview` with commands, renders booking option preview in side preview. |
| `buildSkillPreviewHtml(results)` | Handles `previewmode === 'user_profile'` only — renders a hard-coded user card HTML. Falls back to inferring from raw result fields. **`user_search` mode is declared in PHP but not dispatched in JS.** |
| `loadDocInPreview(docpath, fallbackUrl, fragment)` | Calls `bookingextension_agent_ai_get_doc_content` WS, renders markdown HTML in side preview. |
| `loadUrlInSidePreview(url)` | Loads an arbitrary URL in an iframe inside the side preview. |
| `extractFirstDoc(results)` | Scans results for a `docs[0].{path,url}` entry; triggers `loadDocInPreview()`. |
| `extractPreviewOptionIds(results)` | Scans results for `resultid` + `previewoptionids`, skipping user-centric results. |
| `collectPreviewOptionIds(resp, results)` | Merges `previewoptionidsjson` field + `extractPreviewOptionIds`. |
| `getDocLinkMeta(href)` | Detects `/mod/booking/docs/*` links; marks them as doc links so clicking triggers `loadDocInPreview`. |
| `scrollPreviewToFragment(fragment)` | Scrolls inside side preview to a named anchor after doc load. |
| `showRunStatus(status, message, results)` | Renders inline run output + dispatches `bookingextension_agent_ai_run_completed` event; writes status HTML to side preview as initial feedback. |

**Trigger points in the main response handler:**

1. `earlyPreviewIds = collectPreviewOptionIds(resp, [])` → `renderOptionPreviewsInline()` on every non-command response.
2. `buildSkillPreviewHtml(results)` → `setSidePreviewHtml()` inside `showRunStatus()`.
3. `extractFirstDoc(results)` → `loadDocInPreview()` after sufficient/clarification responses.
4. `renderOptionPreviewsInline(ctx, confirmPreviewIds)` → after `ai_confirm_run` responses.
5. Click on `.booking-doc-link` elements → `loadDocInPreview()`.

---

### 2.8 Summary of Hardcoded Coupling

| Location | What is hardcoded |
|---|---|
| `preview_policy.php` | Skill suffix allowlist (4 booking-only values) |
| `ai_render_command_preview.php` | Entire rendering logic using `mod_booking` classes |
| `buildSkillPreviewHtml()` in JS | Only `user_profile` mode renders a real card; `user_search` is dead code on the JS side |
| `extractFirstDoc()` in JS | Hardcoded `docs` key on result — only works if skill returns that exact key |
| `getDocLinkMeta()` in JS | Hardcoded `/mod/booking/docs/` path pattern |

---

## 3. Architectural Problems

1. **No skill contract for preview.** Skills declare preview intent by convention (adding `previewmode`/`previewdata` keys), not via an interface. Nothing enforces or documents it.

2. **Renderer registry is implicit.** The JS `buildSkillPreviewHtml()` is a growing `if/else` chain. Every new preview type requires a code change in the core JS file.

3. **Third-party plugins are locked out.** A plugin contributing skills via `skill_discovery` cannot tell the system "after executing my skill, render the result this way" — because both `preview_policy.php` and `ai_render_command_preview.php` are hardcoded to booking options.

4. **Two conflicting preview paths exist in parallel:**
   - Path A: `previewmode`/`previewdata` → `buildSkillPreviewHtml()` (skill-authored HTML, JS-rendered client-side)
   - Path B: `previewoptionids` → WS call → server-rendered HTML
   
   These are uncoordinated: a result can fire both, or neither.

5. **`user_search` previewmode is declared but never consumed in JS.** Dead code.

6. **Doc preview is path-coupled to `mod_booking`.** `getDocLinkMeta()` only recognizes `/mod/booking/docs/` URLs, making it useless for any other plugin's documentation.

---

## 4. Proposed Preview API

### 4.1 Design Principles

- **Contract-first:** Skills opt in via a PHP interface. No interface = no preview.
- **Type-dispatched:** Every preview has a declared `type` string. Both PHP renderer and JS renderer are registered by type.
- **Plugin-extensible:** Third-party plugins register preview types without touching core files.
- **Graceful fallback:** Unknown types produce no preview, no error.

---

### 4.2 New Interface: `skill_preview_provider_interface`

```php
interface skill_preview_provider_interface {
    /**
     * Return the preview descriptor for this skill.
     * Called once at skill registration time (not per execution).
     *
     * @return array{
     *   type: string,
     *   renderer: string|null,   // PHP FQCN of server-side renderer, or null = client-only
     *   js_module: string|null,  // AMD module name for client-side renderer, or null = server-only
     *   description: string,
     * }
     */
    public function get_preview_descriptor(): array;
}
```

Skills that want a preview implement this interface in addition to their normal skill interfaces. Skills that don't implement it produce no preview — no changes to existing skills required.

---

### 4.3 Server-side Renderer Interface

```php
interface skill_preview_renderer_interface {
    /**
     * Render preview HTML for one skill execution result.
     *
     * @param array $result       The full skill execution result array.
     * @param int   $contextid    Moodle context id.
     * @param int   $userid       Current user id.
     * @return string             Rendered HTML, or '' to produce no preview.
     */
    public function render(array $result, int $contextid, int $userid): string;
}
```

Example: `bookingextension_agent\local\wbagent\preview\renderers\booking_option_preview_renderer` would encapsulate the current `ai_render_command_preview` logic.

---

### 4.4 Preview Type Registry

```php
class preview_type_registry {
    // Indexed by type string.
    // Auto-discovered from skill providers via skill_discovery.
    private array $renderers = [];

    public function register(string $type, ?skill_preview_renderer_interface $renderer, ?string $jsmodule): void;
    public function get_renderer(string $type): ?skill_preview_renderer_interface;
    public function get_js_module(string $type): ?string;
    public function get_all_js_modules(): array;   // passed to page as AMD init data
}
```

`skill_discovery` populates this when it loads skills: if a skill implements `skill_preview_provider_interface`, the descriptor is used to register the renderer.

---

### 4.5 Unified `preview` Key in Skill Result

Instead of the current `previewmode`/`previewdata`/`previewoptionids` spread, every skill that implements `skill_preview_provider_interface` returns a single `preview` key:

```php
// In skill execute():
return [
    'status' => 'executed',
    // ... other fields ...
    'preview' => [
        'type' => 'booking_option',       // must match descriptor type
        'payload' => ['optionids' => [42, 43]],
    ],
];
```

The `execution_feedback_service` passes `preview` through unchanged.

---

### 4.6 Unified External API: `ai_get_preview`

Replace the current booking-specific `ai_render_command_preview` with a generic endpoint:

```
ai_get_preview(contextid, type, payload_json)
→ { success, html, javascript, js_module, js_data_json }
```

Logic:
1. Look up `type` in `preview_type_registry`.
2. If a PHP renderer is registered, call `render($payload, $contextid, $userid)` → return `html`.
3. If only a JS module is registered, return `js_module + js_data_json` (client renders entirely).
4. Unknown type → `success: true, html: ''` (silent no-op).

`preview_policy.php` is deleted; its allowlist logic moves into the booking option renderer class which registers only for its own type.

---

### 4.7 JS: Type-dispatched Preview Renderer

Replace the `if/else` chain in `buildSkillPreviewHtml()` with a dispatch table:

```js
const previewRenderers = {};   // populated by plugin AMD modules at init time

const registerPreviewRenderer = (type, renderFn) => {
    previewRenderers[type] = renderFn;
};

const dispatchSkillPreview = async (previewDescriptor, contextid) => {
    const type = String((previewDescriptor && previewDescriptor.type) || '').trim();
    if (!type) return;

    // Server-rendered path:
    if (previewDescriptor.html !== undefined) {
        setSidePreviewHtml(previewDescriptor.html);
        return;
    }

    // Client-rendered path via registered handler:
    const renderer = previewRenderers[type];
    if (renderer) {
        const html = await renderer(previewDescriptor.payload, contextid);
        if (html) setSidePreviewHtml(html);
        return;
    }

    // Fallback: call server to render, passing type + payload:
    const resp = await callWs('bookingextension_agent_ai_get_preview', {
        contextid, type, payload_json: JSON.stringify(previewDescriptor.payload || {}),
    });
    if (resp && resp.success && resp.html) setSidePreviewHtml(resp.html);
};
```

Each plugin's AMD module calls `registerPreviewRenderer('my_type', fn)` during init.

---

### 4.8 AMD Module Discovery

The `preview_type_registry::get_all_js_modules()` result is injected into the page init data (same pattern as how skill catalog metadata is currently passed). The main `aiinstructions.js` dynamically requires each AMD module on startup. This means a third-party plugin's JS preview renderer is loaded automatically once the plugin registers its skill.

---

### 4.9 Backward Compatibility

During transition:

- `previewmode`/`previewdata` legacy fields remain supported in `buildSkillPreviewHtml()`.
- `previewoptionids` remains supported alongside the new `preview.type = 'booking_option'` path.
- `ai_render_command_preview` remains registered in `db/services.php` but delegates to the new `ai_get_preview` logic internally.

Legacy support can be dropped in a subsequent cleanup pass once all internal skills have migrated.

---

## 5. Migration Path for Existing Preview Types

| Current preview | New type string | Renderer location |
|---|---|---|
| Booking option row(s) | `booking_option` | PHP: `booking_option_preview_renderer` (wraps existing `view::get_rendered_showonlyone_table()`) |
| User profile card | `user_profile` | JS-only: inline AMD renderer (currently in `buildSkillPreviewHtml`) |
| User search results | `user_search` | JS-only: new AMD renderer (currently dead code — no JS handler existed) |
| Documentation markdown | `doc_markdown` | PHP: `doc_markdown_preview_renderer` (wraps `ai_get_doc_content` logic); JS handles fragment scroll |
| Confirmation command list | `command_list` | JS-only: confirmation panel HTML (extracted from `showConfirmPanel`) |

---

## 6. Effort Estimate

### Phase 1 — Foundation (required for everything else)

| Task | Effort |
|---|---|
| Define `skill_preview_provider_interface` + `skill_preview_renderer_interface` | 0.5 day |
| Implement `preview_type_registry` + integrate into `skill_discovery` | 1 day |
| New `ai_get_preview` external API (type-dispatched, replaces booking-specific one) | 1 day |
| Unify `preview` key in `execution_feedback_service` (pass-through, keep backward compat) | 0.5 day |
| JS `registerPreviewRenderer` / `dispatchSkillPreview` infrastructure | 1 day |

**Phase 1 total: ~4 days**

### Phase 2 — Migrate existing preview types

| Task | Effort |
|---|---|
| `booking_option` PHP renderer (extract from `ai_render_command_preview`) | 0.5 day |
| `user_profile` JS renderer (extract from `buildSkillPreviewHtml`) | 0.25 day |
| `user_search` JS renderer (fix dead code) | 0.25 day |
| `doc_markdown` PHP renderer (extract from `ai_get_doc_content`) | 0.5 day |
| Update `get_current_user_skill` + `search_users_skill` to use new interface | 0.5 day |
| Update booking create/update skills to return `preview.type = 'booking_option'` | 0.5 day |
| `explain_docs_skill` → return `preview.type = 'doc_markdown'` | 0.25 day |

**Phase 2 total: ~3 days**

### Phase 3 — Cleanup

| Task | Effort |
|---|---|
| Delete `preview_policy.php` | 0.25 day |
| Remove `previewmode`/`previewdata` legacy code (after smoke test) | 0.5 day |
| Update tests | 1 day |

**Phase 3 total: ~2 days**

### Overall estimate

| Phase | Effort |
|---|---|
| Phase 1: Foundation | ~4 days |
| Phase 2: Migration | ~3 days |
| Phase 3: Cleanup | ~2 days |
| **Total** | **~9 working days** |

The phases are independent: Phase 1 can ship without Phase 2 (old preview still works), and Phase 2 can be done incrementally skill by skill.

---

## 7. Open Questions

1. **AMD module loading strategy:** Should AMD modules be required eagerly at page init (current AMD `require` pattern) or lazily on first use? Lazy is better for performance but adds complexity.

2. **Pre-execution confirmation preview:** The confirmation panel currently previews a command *before* execution by looking up an existing DB record with the same name. This is fragile. Should the new API support a `preview_pre_execution(array $command): string` method on the renderer?

3. **Preview scope:** Currently the side preview is a single shared pane. Multi-command responses could in theory produce multiple previews. Should the new API support multi-preview (tabbed pane) or always just the last/best preview?

4. **Security:** The PHP renderer receives `$payload` that came from the client via JSON. The renderer must sanitize every field. Should the framework validate payload shape against a schema declared in `get_preview_descriptor()`?

5. **`user_search` dead code:** The `user_search` previewmode in `search_users_skill` has no JS handler. Should we fix it as part of Phase 2, or remove it from the skill result until Phase 2 ships?
