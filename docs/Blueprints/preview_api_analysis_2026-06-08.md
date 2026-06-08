# Preview API Analysis & Design Blueprint (Revised)
**Date:** 2026-06-08  
**Author:** Georg Maisser / Claude Code  
**Status:** Planning — Clean slate refactoring (No backward compatibility)

---

## 1. Motivation

Today the preview system is tightly coupled to `mod_booking` booking options. A third-party plugin that contributes its own skills cannot produce any preview output without hacking the core agent files.

To achieve a clean, maintainable, and extensible architecture, we will **completely deprecate and remove the old preview path**. There will be **no backward compatibility** for legacy keys (`previewmode`, `previewdata`, `previewoptionids`, `docs`). 

This document outlines the final plan to:
1. Identify all obsolete files, classes, methods, and functions to be deleted.
2. Establish a unified, extensible Preview API.
3. Define the new generic metadata structures and external Web Service schemas.

---

## 2. Inventory of Obsolete Code (To Be Deleted)

The following components are 100% booking-option-specific or legacy boilerplate and will be **completely removed**:

### 2.1 PHP Files & Classes to Delete
- **`classes/local/wbagent/preview_policy.php`**: Deprecated. The allowlist logic is replaced by individual renderer registrations.
- **`classes/external/ai_render_command_preview.php`**: Deprecated. Replaced by the generic `ai_get_preview` Webservice.
- **`interfaces/preview_option_memory_interface.php`**: Deprecated. General session/thread preview memory is used instead.
- **`interfaces/preview_option_memory_provider_interface.php`**: Deprecated.
- **`classes/local/wbagent/services/confirm_preview_option_service.php`**: Deprecated. Replaced by generic preview payload merging.

### 2.2 Obsolete Webservice Return Parameters to Remove
The following keys will be completely removed from `execute_returns()` in `ai_send_message.php` and `ai_confirm_run.php`:
- `previewoptionid` (PARAM_INT)
- `previewoptionidsjson` (PARAM_RAW)

These will be replaced by a single, generic `previewjson` (PARAM_RAW) field.

### 2.3 JS Functions to Delete in `aiinstructions.js`
- `buildSkillPreviewHtml()` (hardcoded `user_profile` card rendering)
- `renderOptionPreviewsInline()` (hardcoded booking option rendering call)
- `extractPreviewOptionIds()`
- `collectPreviewOptionIds()`
- `extractFirstDoc()`

---

## 3. The New Unified Preview API

### 3.1 Skill Interface: `skill_preview_provider_interface`
Skills that support a preview implement this interface:

```php
namespace bookingextension_agent\local\wbagent\interfaces;

interface skill_preview_provider_interface {
    /**
     * Return the preview descriptor for this skill.
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

---

### 3.2 Server-side Renderer Interface: `skill_preview_renderer_interface`
PHP renderers must implement this interface:

```php
namespace bookingextension_agent\local\wbagent\interfaces;

interface skill_preview_renderer_interface {
    /**
     * Render preview HTML for a specific payload.
     *
     * @param array $payload      The preview payload (either execution result or proposed commands).
     * @param int   $contextid    Moodle context id.
     * @param int   $userid       Current user id.
     * @return string             Rendered HTML.
     */
    public function render(array $payload, int $contextid, int $userid): string;
}
```

---

### 3.3 Preview Type Registry
The `preview_type_registry` dynamically maps preview type strings to their respective PHP and JS handlers. It is populated during skill discovery.

```php
namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\interfaces\skill_preview_renderer_interface;

class preview_type_registry {
    private array $renderers = [];
    private array $jsmodules = [];

    public function register(string $type, ?skill_preview_renderer_interface $renderer, ?string $jsmodule): void {
        if ($renderer) {
            $this->renderers[$type] = $renderer;
        }
        if ($jsmodule) {
            $this->jsmodules[$type] = $jsmodule;
        }
    }

    public function get_renderer(string $type): ?skill_preview_renderer_interface {
        return $this->renderers[$type] ?? null;
    }

    public function get_js_module(string $type): ?string {
        return $this->jsmodules[$type] ?? null;
    }
}
```

---

### 3.4 Unified Webservice Response: `previewjson`
Both `ai_send_message::execute()` and `ai_confirm_run::execute()` will return a generic `previewjson` string containing a serialized preview descriptor:

```json
{
  "type": "booking_option",
  "payload": {
    "optionids": [42, 43]
  }
}
```

---

### 3.5 Generic External API: `ai_get_preview`
Replaces the booking-specific preview Webservice entirely.

**Parameters:**
- `contextid` (PARAM_INT)
- `type` (PARAM_TEXT)
- `payload_json` (PARAM_RAW)

**Returns:**
- `success` (PARAM_BOOL)
- `html` (PARAM_RAW)
- `js_module` (PARAM_TEXT, optional)
- `js_data_json` (PARAM_RAW, optional)

---

### 3.6 Generic Thread Metadata Accumulation
Instead of storing raw option IDs, thread metadata will store accumulated preview payloads under the key `_confirm_previews` across multi-step execution chains.
When merging, if the type matches, payloads are combined (e.g. merging `optionids` arrays). If types differ, the latest preview takes precedence.

---

### 3.7 JS: Type-Dispatched Preview and Lazy AMD Loading
The `aiinstructions.js` file maintains a registry of client-side handlers and dynamically imports AMD modules only on demand.

```js
const previewRenderers = {};
const registeredJsModules = {}; // Populated during page initialization

const registerPreviewRenderer = (type, renderFn) => {
    previewRenderers[type] = renderFn;
};

const dispatchSkillPreview = async (previewDescriptor, contextid) => {
    const type = String((previewDescriptor && previewDescriptor.type) || '').trim();
    if (!type) return;

    // 1. Direct HTML from server
    if (previewDescriptor.html !== undefined) {
        setSidePreviewHtml(previewDescriptor.html);
        return;
    }

    // 2. Client-side handler already loaded
    const renderer = previewRenderers[type];
    if (renderer) {
        const html = await renderer(previewDescriptor.payload, contextid);
        if (html) setSidePreviewHtml(html);
        return;
    }

    // 3. Lazy-load JS AMD module if declared
    const jsModule = registeredJsModules[type];
    if (jsModule) {
        return new Promise((resolve, reject) => {
            require([jsModule], (mod) => {
                const loadedRenderer = previewRenderers[type];
                if (loadedRenderer) {
                    loadedRenderer(previewDescriptor.payload, contextid).then(resolve).catch(reject);
                } else {
                    resolve();
                }
            }, reject);
        });
    }

    // 4. Fallback to generic server-side rendering Webservice
    const resp = await Ajax.call([{
        methodname: 'bookingextension_agent_ai_get_preview',
        args: {
            contextid,
            type,
            payload_json: JSON.stringify(previewDescriptor.payload || {}),
        }
    }])[0];
    if (resp && resp.success && resp.html) {
        setSidePreviewHtml(resp.html);
    }
};
```

---

### 3.8 Generic Document Link Parsing
To support documentation links contributed by any plugin, `getDocLinkMeta()` in JS will be refactored to parse plugin-independent paths dynamically:

```js
getDocLinkMeta = href => {
    const raw = String(href || '').trim();
    if (raw === '') return {docpath: '', fragment: ''};
    
    // ... absolute URL and hash normalization ...

    const match = withoutQuery.match(/^\/(?:public\/)?mod\/[a-z0-9_]+\/docs\/(.+)$/i);
    if (match) {
        return {docpath: match[1].trim(), fragment: fragment};
    }
    return /\.md$/i.test(withoutQuery) && !/^\//.test(withoutQuery)
        ? {docpath: withoutQuery.trim(), fragment: fragment}
        : {docpath: '', fragment: fragment};
};
```

---

## 4. Preview Types to Implement

| Type String | Source / Scope | Rendering Target |
|---|---|---|
| `booking_option` | `mod_booking` | Renders a table of booking options using `bookingoptions_wbtable` |
| `user_profile` | `core.get_current_user` | Client-rendered user card (fullname, email, id) |
| `user_search` | `core.search_users` | Client-rendered user list table |
| `doc_markdown` | `core.explain_docs` / static link | Server-rendered markdown document |
| `command_list` | Pre-execution | Client-rendered proposed command preview list |

---

## 5. Tasks & Implementation Status

- [x] **Task 1: Code Removal & Deprecation**
  - [x] Delete `preview_policy.php`, `ai_render_command_preview.php`, `confirm_preview_option_service.php` and the memory interfaces.
  - [x] Remove deprecated WS parameters `previewoptionid` and `previewoptionidsjson` from all webservice registration files.
  - [x] Purge obsolete preview JS helper functions from `aiinstructions.js`.

- [x] **Task 2: Framework Interfaces & Registry**
  - [x] Implement `skill_preview_provider_interface` and `skill_preview_renderer_interface`.
  - [x] Implement `preview_type_registry` and hook it into `skill_discovery`.
  - [x] Implement the generic thread metadata preview accumulation logic.

- [x] **Task 3: Generic Webservice & JS Client**
  - [x] Create the generic `ai_get_preview` external service.
  - [x] Implement the JS dynamic dispatcher `dispatchSkillPreview` with lazy AMD module loading.
  - [x] Implement the generic `getDocLinkMeta` regex parser.

- [x] **Task 4: Typspezifische Renderer & Test-Update**
  - [x] Implement `booking_option_preview_renderer` (extracted from the old code).
  - [x] Implement client-side renderers for `user_profile`, `user_search` and `command_list`.
  - [x] Implement `doc_markdown_preview_renderer`.
  - [x] Clean up and rewrite unit tests to conform to the new schemas (updated and verified).
  - [x] Fix ESLint and Grunt compilation errors on `aiinstructions.js`.
  - [x] Align `search_users_skill.php` to set `js_module` to `null` to use inline rendering.
