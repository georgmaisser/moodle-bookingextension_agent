# Developer guide · Web services API

> **Scope.** Reference for the external functions the plugin registers in
> [`db/services.php`](../../db/services.php). Narrative context is in
> [architecture/01](../architecture/01-entry-and-web-services.md).

All functions are registered under the **"Booking AI Agent"** service, are AJAX-enabled
(`ajax = 1`), and require the `bookingextension/agent:useaiinstructions` capability.
Write-type functions call `require_sesskey()`; read-type polling/preview reads do not.

---

## `ai_send_message` · write

Send a user message and run the agent loop.

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | booking module context id |
| `message` | raw | ✓ | the user's message |
| `threadid` | int | — (0) | existing thread; 0 → resolve/create |
| `attachments` | raw | — (`[]`) | JSON array of attachment tokens |

**Returns:** `response_type`, `message`, `displaymessage`, `privacyapplied`, `autoconfirm`,
`commands`, `ambiguities`, `ambiguityoptionsjson`, `errorsjson`, `issuecodesjson`,
`phasetracejson`, `queueitemid`, `threadid`, `runid`, `resultsjson`, `previewjson`.
**Errors:** readiness `error_ai_*` (see [ch. 01 §3](../architecture/01-entry-and-web-services.md#3-the-readiness-gate)), `permission_denied`.

## `ai_confirm_run` · write

Confirm a blocked mutation.

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | module context |
| `threadid` | int | ✓ | thread |
| `queue_item_id` | alphanumext | ✓ | the queue item to confirm |
| `allow_session` | bool | — (false) | grant a session allowance (auto-confirm) |

**Returns:** `success`, `runid`, `threadid`, `response_type`, `message`, `displaymessage`,
`commands`, `resultsjson`, `issuecodesjson`, `errorsjson`, `queueitemid`, `previewjson`.

## `ai_discard_pending` · write

| Param | Type | Req |
|-------|------|-----|
| `contextid` | int | ✓ |
| `threadid` | int | ✓ |

**Returns:** `success`, `discardedcount`, `threadid`, `message`.

## `ai_poll_thread` · read (no sesskey)

Delta-poll progress / messages.

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | module context |
| `threadid` | int | — (0) | 0 → auto-resolve |
| `lastseenid` | int | — (0) | only newer than this id |

**Returns:** `threadid`, `messages[]` (`id`, `role`, `content`, `timecreated`).

## `ai_privacy_precheck` · read

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | module context |
| `message` | raw | ✓ | draft message |
| `forcenewthread` | int | — (0) | 1 → start a fresh thread |

**Returns:** `status`, `message`, `sanitizedmessage`, `anonymizedcount`,
`anonymizedemails`, `anonymizednames`, `elapsedms`, `threadid`, `strictmode`.

## `ai_upload_attachment` · write (no sesskey)

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | module context |
| `filename` | file | ✓ | display name |
| `mimetype` | raw | ✓ | declared MIME |
| `filedata` | raw | ✓ | base64 (data-URL or plain) |

Whitelist: jpeg/png/webp/gif/pdf; images ≤ 10 MB, PDFs ≤ 20 MB (configurable).
**Returns:** `success`, `attachment_token`, `attachment_type`, `display_name`,
`thumbnail_html`, `message`.

## `ai_get_doc_content` · read (no sesskey)

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | module context |
| `corpus_id` | alphanumext | ✓ | e.g. `mod_booking`, `bookingextension_agent` |
| `path` | path | ✓ | relative `.md` path inside the corpus |

**Returns:** `success`, `html`, `title`, `error`. Path is hardened (`realpath` + containment
+ `.md`).

## `ai_get_thread_debug_logs` · read (no sesskey)

| Param | Type | Req | Meaning |
|-------|------|-----|---------|
| `contextid` | int | ✓ | module context |
| `threadid` | int | ✓ | thread |
| `limit` | int | — (100) | clamp 1–500 |

**Returns:** `debuglogsjson`, `error`. Requires `aidebugmode` (see
[observability](../operations/observability.md)).

## `request_trial_key` · write (admin)

`contextid` (int). Additionally requires `moodle/site:config` + `\core_ai\manager`.
**Returns:** `success`, `message`.

## `activate_trial_context` · write (admin)

`contextid` (int). Requires `moodle/site:config`; flips `enableaitools` on course + module.
**Returns:** `success`, `message`.

---

## Not registered (internal mutation API)

`booking_create_option`, `booking_update_option`, `booking_bulk_update_options`,
`booking_validate_option` live in `classes/external/` but are **not** in `db/services.php` —
they are the application-service mutation API booking skills call internally. Each takes a
JSON `fields` payload + an `idempotencykey`; `booking_validate_option` is a side-effect-free
dry run (`task` = create/update/bulk_update). See
[ch. 01 §9](../architecture/01-entry-and-web-services.md#9-the-direct-booking-endpoints).
