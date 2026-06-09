# 01 · Entry layer & external web services

> **Scope.** The `classes/external/` web services that are the only public entry points
> into the engine, and the readiness gate that protects them. Flowchart subgraph: `ENTRY`.

Every interaction with the agent starts as a Moodle **external function** (an AJAX web
service). The browser front-end (`amd/src/aiinstructions.js`) never touches the engine
classes directly — it calls these services, and they translate a request into a runtime
loop, a confirmation, a poll, or an upload.

All functions are registered in [`db/services.php`](../../db/services.php) and live in the
`bookingextension_agent\external` namespace. Every AI function requires the
`bookingextension/agent:useaiinstructions` capability and runs over AJAX with sesskey
validation (read-only polling/preview reads omit `require_sesskey`).

---

## Table of contents

1. [The registered services at a glance](#1-the-registered-services-at-a-glance)
2. [`ai_send_message` — the primary entry](#2-ai_send_message--the-primary-entry)
3. [The readiness gate](#3-the-readiness-gate)
4. [`ai_confirm_run` — confirming a mutation](#4-ai_confirm_run--confirming-a-mutation)
5. [`ai_discard_pending` — abandoning a pending mutation](#5-ai_discard_pending--abandoning-a-pending-mutation)
6. [`ai_poll_thread` — live progress](#6-ai_poll_thread--live-progress)
7. [Attachments, docs & previews](#7-attachments-docs--previews)
8. [Trial & privacy services](#8-trial--privacy-services)
9. [The direct booking endpoints](#9-the-direct-booking-endpoints)
10. [Entry-gate error codes](#10-entry-gate-error-codes)
11. [⚠ Flowchart notes](#11-flowchart-notes)

---

## 1. The registered services at a glance

Ten functions are registered as web services. They divide into conversation control,
attachments/docs, and admin/trial helpers.

| WS function (`bookingextension_agent_…`) | Class | Type | Sesskey | Purpose |
|------------------------------------------|-------|------|---------|---------|
| `ai_send_message` | `ai_send_message` | write | yes | Send a user message; run the agent loop |
| `ai_confirm_run` | `ai_confirm_run` | write | yes | Confirm a blocked mutation |
| `ai_discard_pending` | `ai_discard_pending` | write | yes | Discard a pending mutation |
| `ai_poll_thread` | `ai_poll_thread` | read | no | Poll per-step progress (delta) |
| `ai_upload_attachment` | `ai_upload_attachment` | write | no | Upload an image/PDF, get a token |
| `ai_privacy_precheck` | `ai_privacy_precheck` | read | yes | Scan a draft message for PII |
| `ai_get_doc_content` | `ai_get_doc_content` | read | no | Render a doc page to safe HTML |
| `ai_get_thread_debug_logs` | `ai_get_thread_debug_logs` | read | no | Read LLM debug entries |
| `request_trial_key` | `request_trial_key` | write | yes | Request a trial provider key (admin) |
| `activate_trial_context` | `activate_trial_context` | write | yes | Enable AI tools on course/module (admin) |

> The `booking_create_option` / `booking_update_option` / `booking_bulk_update_options` /
> `booking_validate_option` classes also live in `classes/external/` but are **not**
> registered in `db/services.php`. They are the application-service mutation API that
> skills call internally, not AJAX endpoints. See [§9](#9-the-direct-booking-endpoints).

---

## 2. `ai_send_message` — the primary entry

`ai_send_message::execute()` is the front door. It turns a user message into a full agent
turn.

**Parameters:**

| Name | Type | Required | Meaning |
|------|------|----------|---------|
| `contextid` | `PARAM_INT` | yes | Module context id of the booking instance (the scope key — see [ch. 02](02-authorization-and-context.md)) |
| `message` | `PARAM_RAW` | yes | The user's message text |
| `threadid` | `PARAM_INT` | no (0) | Pin to an existing active thread; 0 → resolve/create |
| `attachments` | `PARAM_RAW` | no (`[]`) | JSON array of attachment tokens, e.g. `[{"token":"tok_abc","type":"image"}]` |

**Execution sequence:**

1. `require_sesskey()`.
2. `validate_parameters()`.
3. **Authorization** via `authorization_service`: `require_valid_context()`,
   `validate_context()`, and a `can_use()` capability check (returns a permission error if
   denied). See [ch. 02](02-authorization-and-context.md).
4. Empty-message guard.
5. **Readiness gate** — instantiate the skill registry, conversation store, and
   orchestrator, then check `get_runtime_provider_status()`. On any failure the function
   returns a specific error payload (see [§3](#3-the-readiness-gate)) and never starts the
   loop.
6. **Thread resolution** — create an active thread when `threadid == 0`.
7. **Privacy precheck** — `privacy_anonymizer::precheck_user_message()` masks PII before the
   message is stored or sent to a model.
8. **Attachment augmentation** — `attachment_processor::augment_message()` when attachment
   tokens are present (PDF text extraction / image references).
9. **Persist** the user message: `conversation_store::add_message(threadid, 'user', …)`.
10. **Release the session lock** — `\core\session\manager::write_close()`, so the browser
    can poll `ai_poll_thread` concurrently while the loop runs.
11. **Run the loop** — `agent_runtime::run_loop()` (see [ch. 04](04-agent-runtime-and-loop.md)).
12. **Deanonymize** the reply for display (`privacy_anonymizer::deanonymize_message_for_display()`).
13. **Format & assemble** the response via `ws_message_formatter::format_ws_message()`.

**Autoconfirm probe.** When the turn ends in a `confirmation_request`, the response sets an
`autoconfirm` flag so the UI can confirm without a manual click — but only when a session
allowance exists and the turn was not auto-confirm-blocked after an error:

```php
'autoconfirm' => (int)(
    (string)($result['response_type'] ?? '') === 'confirmation_request'
    && $store->is_confirmation_allowed_for_thread((int)$USER->id, $contextid, $threadid)
    && !$autoconfirmblocked
)
```

`is_confirmation_allowed_for_thread()` is a thin wrapper that ignores `threadid` and
delegates to the session-level allowance keyed by `(userid, contextid)` — see
[ch. 03 §6](03-conversation-store.md) and the [⚠ flowchart note](#11-flowchart-notes).

**Return shape (selected fields).** The response is deliberately UI-shaped: scalar fields
plus several JSON-encoded blobs.

| Field | Meaning |
|-------|---------|
| `response_type` | `confirmation_request` / `clarification` / `sufficient` / `error` / … |
| `message` / `displaymessage` | formatted reply / deanonymized variant |
| `privacyapplied` | `1` when masking replaced anything |
| `autoconfirm` | `1` when the UI should auto-trigger confirmation |
| `commands` | JSON of proposed commands |
| `ambiguities` / `ambiguityoptionsjson` | clarification questions / clickable options |
| `errorsjson` / `issuecodesjson` | validation errors / [issue codes](../reference/issue-codes.md) |
| `phasetracejson` | discovery/selection/construction trace |
| `queueitemid` | the queue item awaiting confirmation |
| `threadid` / `runid` | identifiers |
| `resultsjson` | execution results |
| `previewjson` | preview descriptor payload (see [§7](#7-attachments-docs--previews)) |

---

## 3. The readiness gate

Before any work happens, `ai_send_message` asks the orchestrator for
`get_runtime_provider_status()` and maps a failure reason to a specific error string. This
is the `ASM_GATE` node in the flowchart, and the mapping is:

| Reason | Error string | Meaning |
|--------|--------------|---------|
| `subsystem_missing` | `error_ai_subsystem_missing` | the core AI subsystem is not installed |
| `no_provider` | `error_ai_no_provider` | no LLM provider configured |
| `provider_inactive` | `error_ai_provider_inactive` | a provider exists but is disabled |
| `course_disabled` | `error_ai_course_disabled` | AI tools disabled at course level |
| `context_disabled` | `error_ai_context_disabled` | AI tools disabled on this module |
| `exception_thrown` | `ai_provider_error` | an exception during the checks |

The same readiness logic, surfaced for the chat panel UI, lives in
[`aiready`](02-authorization-and-context.md) — the panel uses it to show *why* the agent is
not ready before the user even types.

---

## 4. `ai_confirm_run` — confirming a mutation

When a turn proposes a mutation, the command is parked on the [shadow queue](10-shadow-queue.md)
as `blocked_confirmation`. The user (or an auto-confirm allowance) confirms it here.

**Parameters:** `contextid` (int), `threadid` (int), `queue_item_id` (`PARAM_ALPHANUMEXT`),
`allow_session` (bool, default `false`).

**Behavior.** After the usual sesskey/validate/auth, it delegates everything to
`confirm_run_service::confirm(contextid, cmid, threadid, userid, queue_item_id, allow_session)`.
The service (not the external class) is what:

- records a session allowance when `allow_session == true`;
- consumes the pending intent;
- chooses synchronous execution vs. queuing the `execute_ai_run_adhoc` worker
  (`executionmode = adhoc`);
- runs the confirmed command through the [executor](11-executor.md).

The response mirrors `ai_send_message` (success, runid, response_type, message,
resultsjson, issue codes, the next `queueitemid` if a follow-up confirmation is needed,
and `previewjson`). See [operations/tasks-and-async.md](../operations/tasks-and-async.md)
for the sync-vs-adhoc decision.

---

## 5. `ai_discard_pending` — abandoning a pending mutation

**Parameters:** `contextid` (int), `threadid` (int).

**Behavior.** It consumes the pending intent (`pending_intent_service::consume()`) and then
walks the thread's queue, skipping every actionable **mutating** item via
`queue_transition_service::to_skipped()` with reason
`USER_DISCARDED_PENDING_CONFIRMATION`. It returns `discardedcount`. This is the clean
"never mind" path that prevents an abandoned confirmation from lingering and auto-firing
later.

---

## 6. `ai_poll_thread` — live progress

Because `ai_send_message` closes the session lock before running the loop, the UI polls
this read-only endpoint for **step messages** — the small "Searching skills…",
"Creating option…" progress bubbles.

**Parameters:** `contextid` (int), `threadid` (int, 0 → auto-resolve), `lastseenid` (int,
default 0 — delta cursor).

**Behavior.** It resolves the thread, then returns
`store::get_step_messages_since(threadid, lastseenid)` — only messages newer than the
cursor, to keep the poll cheap. Each returned message has `id`, `role`, `content`,
`timecreated`. Step messages are ephemeral (role `step`) and are cleared at the start of
each turn — see [ch. 03 §7](03-conversation-store.md#7-step-messages) for where they are
written.

---

## 7. Attachments, docs & previews

**`ai_upload_attachment`** (no sesskey; auth only). Accepts `contextid`, `filename`
(`PARAM_FILE`), `mimetype`, and base64 `filedata`. It base64-decodes, re-detects the MIME
type from the actual bytes with `finfo`, enforces a whitelist (`image/jpeg`, `image/png`,
`image/webp`, `image/gif`, `application/pdf`) and size limits (images ≤ 10 MB, PDFs ≤
20 MB, both configurable), writes to a temp dir, and issues an **opaque token** via
`attachment_token_service::create()`. For images it also returns an inline base64
thumbnail. The token is later passed to `ai_send_message` in `attachments[]`. See
`services/attachment/*`.

**PDF text extraction (dependency-free by design).** When a PDF token reaches
`attachment_processor::augment_message()` (entry step 8), the file's text is extracted and
injected into the message as a `--- DOCUMENT: … ---` block, and the token is consumed
immediately (the raw file is not exposed to skills). Extraction is handled by
`services/attachment/pdf_text_extractor.php`, which tries two strategies in order:

1. **`pdftotext`** (poppler-utils) — used when the binary is present *and* PHP `exec()` is
   permitted. Fast and accurate; preferred where available.
2. **Bundled `smalot/pdfparser`** — a pure-PHP fallback so the feature works on **any**
   server with no system binary and no `exec()`. The library is vendored under
   [`thirdparty/pdfparser/`](../../thirdparty/pdfparser/) (LGPL-3.0, declared in
   [`thirdpartylibs.xml`](../../thirdpartylibs.xml)) and loaded by a lazy PSR-4 autoloader
   registered in `pdf_text_extractor::ensure_pdfparser_autoloader()` — no Composer
   `vendor/autoload.php` is required. The upstream `symfony/polyfill-mbstring` dependency is
   intentionally **not** bundled, because Moodle already requires `ext-mbstring`,
   `ext-iconv` and `ext-zlib`.

Extracted text is capped at `pdf_text_extractor::MAX_CHARS` (15 000 chars). Neither path
performs OCR, so **scanned / image-only PDFs yield no text** — the extractor returns empty
and the user sees a "could not be processed" note. See the
[operations: configuration → server requirements](../operations/configuration.md) for the
optional poppler dependency.

**`ai_get_doc_content`** (no sesskey; auth only). Renders a documentation page to safe
HTML. Parameters: `contextid`, `corpus_id` (`PARAM_ALPHANUMEXT`), `path` (`PARAM_PATH`). It
resolves the corpus root through `docs_corpus_registry::resolve_root()`, hardens the path
with `realpath()` + a containment check + a `.md` requirement, then converts Markdown to
sanitized HTML (rewriting inline `.md` links into `data-docpath` attributes the front-end
can follow). This backs the inline doc preview pane for `core.explain_docs`.

**Command preview.** There is **no** `ai_render_command_preview` web service. A preview is
produced inside the loop by the `preview_passthrough` service and returned to the UI as the
`previewjson` field on the `ai_send_message` / `ai_confirm_run` responses (skills supply
preview *data* via `get_result_preview()`; the engine passes it through generically). See
the [⚠ flowchart note](#11-flowchart-notes) and the Preview data-contract design.

---

## 8. Trial & privacy services

**`ai_privacy_precheck`** (read). Given a draft `message` (and optional
`forcenewthread`), it scans for PII with `privacy_anonymizer::precheck_user_message()` and
returns counts (`anonymizedemails`, `anonymizednames`, total), a `sanitizedmessage`, and a
`strictmode` flag — so the UI can warn the user *before* they send. With
`forcenewthread = 1` it archives the active thread and starts a fresh one.

**`request_trial_key`** and **`activate_trial_context`** (write, admin). Both additionally
require `moodle/site:config` and the presence of `\core_ai\manager`. `request_trial_key`
mints and caches a nonce; `activate_trial_context` verifies provider status and flips
`enableaitools` on the course and module. These power the "try the agent" onboarding flow.

---

## 9. The direct booking endpoints

`booking_create_option`, `booking_update_option`, `booking_bulk_update_options`, and
`booking_validate_option` are external function *classes* but are **not registered** in
`db/services.php`. They are the **application-service mutation API**: a booking skill's
`execute()` calls into the corresponding mutation service rather than reimplementing option
writes. Their shared traits:

- JSON `fields` payload (e.g. must include `text` for create; `optionid`/`optionquery` for
  update; `optionids`/`optionquery`/`apply_to_all` for bulk).
- An `idempotencykey` so a retried call returns the stored result instead of re-applying.
- `booking_validate_option` is a **dry run**: it returns validation errors and ambiguities
  with no side effects, letting the agent probe before it confirms.
- They go through `mod_booking`'s own `booking_option::update()` with form-style params —
  the executor and skills stay free of option-write internals (see
  [developer-guides/writing-a-skill.md](../developer-guides/writing-a-skill.md)).

Detailed parameter/return tables live in
[developer-guides/web-services-api.md](../developer-guides/web-services-api.md).

---

## 10. Entry-gate error codes

The readiness gate strings (`error_ai_subsystem_missing`, `error_ai_no_provider`,
`error_ai_provider_inactive`, `error_ai_course_disabled`, `error_ai_context_disabled`,
`ai_provider_error`) are listed in [§3](#3-the-readiness-gate). Authorization failures
surface as `permission_denied` (capability) and invalid-context exceptions
(`invalidcontext`, `invalidcoursemodule`). The full catalog, including the runtime issue
codes, is in [reference/issue-codes.md](../reference/issue-codes.md).

---

## 11. Flowchart notes

> **✓ `APREVIEW` node (corrected).** The node previously read
> `ai_render_command_preview::execute()`, a web service that does not exist. The diagram now
> describes the real mechanism: previews are generated in-loop by `preview_passthrough` and
> returned as `previewjson` on the `ai_send_message` / `ai_confirm_run` responses (the
> dedicated preview endpoint/registry was removed in the "preview as a generic data
> passthrough" refactor).

> **✓ Autoconfirm method name (`CS12`, annotated).** `CS12` names the real session-check
> `is_confirmation_allowed_for_session(userid, contextid)`; `ai_send_message` reaches it via
> the `is_confirmation_allowed_for_thread(...)` wrapper (ignores `threadid`, delegates here).
> The node now carries that annotation.

> **✓ Attachments pipeline (added).** The `ASM` node now lists the fourth `attachments[]`
> parameter, and the diagram includes `ASM_UPLOAD` (`ai_upload_attachment` → token) and
> `ASM_ATTACH` (`attachment_processor::augment_message`, PDF→text via pdftotext ▸
> smalot/pdfparser fallback).

See [reference/flowchart-guide.md](../reference/flowchart-guide.md) for the consolidated
discrepancy log.
