# Roadmap: `core.fill_form` — let the agent fill the form on the current page

Status: ROADMAP / RESEARCH (2026-06-29). Not implemented. This captures the feature, the technical
feasibility research, and a phased plan so it can be picked up later.

## Idea

The user is on any Moodle page that contains a form (a booking option edit form, an activity
settings form, a profile form, …). They open the Wizard and say, in natural language, what the form
should contain ("set the title to Yoga, max 12 participants, starts next Monday 10:00"). The agent
looks at the form that is actually on the page, maps the request to the form's fields, and **fills
them in the browser via JS** — it does NOT submit. The user reviews the filled form and saves it
themselves.

This is deliberately different from the existing `update_option` / `update_activity` skills, which
persist server-side through Moodle APIs. `fill_form` is a **client-side authoring aid**: it never
writes to the database, it just populates the open form the user is looking at, whatever form that
is. That makes it broadly useful (works on forms the agent has no dedicated skill for) and low-risk
(nothing is saved until the user clicks Save).

## Why it is non-trivial (the core constraint)

The agent runs **server-side** (a web service). The form is a **DOM tree in the browser**. So the
feature needs a round trip:

1. **Capture (client):** read the form on the page into a compact *field schema* and send it with
   the message.
2. **Map (server / skill):** given the field schema + the user's intent, produce a `field → value`
   plan (this is the only "AI" part).
3. **Fill (client):** apply the plan to the DOM, dispatching the right events per field type so
   Moodle's own JS reacts, then let the user review + submit.

Legs 1 and 3 are pure JS in our AMD; leg 2 is a normal skill. The engine already has every channel
we need (see "Reuse").

## What already exists we can build on (researched)

- **Page context channel.** The navbar hook hands a `$PAGE` snapshot to `navbar_magic_wand.init`,
  which stores it in `sessionStorage['wizard_pagecontext']`; `aiinstructions.js` sends it as the
  `pagecontext` arg of `bookingextension_agent_ai_send_message` (sanitised server-side in
  `ai_send_message::sanitize_page_context`). → We add a parallel, larger **`formdescriptor`** arg
  captured the same way (scan the DOM at send time).
- **JS-to-client preview channel.** Skills return a preview descriptor as data; `dispatchSkillPreview`
  renders server HTML, runs render-time JS (`Fragment.processCollectedJavascript` +
  `Templates.replaceNodeContents`), OR lazy-loads a skill-named **`js_module`** and calls its render
  function with a `payload`. → The fill plan ships to the client as a `js_module` preview
  (`mod_booking/fill_form_apply` + payload), or as the confirm-time `proposed_action` rows for review.
- **Skill framework.** Risk class, capability gate, `describe_proposed_action()` (the tier-3 confirm
  preview we just rolled out) — all reusable. The plan is shown as a readable `field: value` table
  before anything touches the form.
- **Privacy.** The anonymiser already runs over the message before the LLM; the captured form values
  must go through the same path (they may contain personal data).

## Field-fill research (mform specifics)

Most Moodle forms are `moodleform`/mform. Element id pattern is `id_<elementname>`, name is
`<elementname>`. A naïve `el.value = x` is **not** enough — many widgets need their own API and/or a
dispatched event. Per type:

| mform element | DOM | How to set (JS) |
|---|---|---|
| text / passwordunmask / int | `input#id_<name>` | set `.value`, dispatch `input` + `change` |
| textarea (plain) | `textarea#id_<name>` | set `.value`, dispatch `input` + `change` |
| select (single) | `select#id_<name>` | set `.value` (match by option value OR visible text), dispatch `change` |
| advcheckbox / checkbox | `input[type=checkbox]#id_<name>` | set `.checked`, dispatch `change` (mind the hidden 0-value companion of advcheckbox) |
| radio | `input[type=radio][name=<name>]` | check the matching one, dispatch `change` |
| **editor** (Atto/TinyMCE/Marklar) | `textarea#id_<name>` + live editor instance | cannot just set the textarea — must use the editor API (TinyMCE: `tinyMCE.get(id)?.setContent()`; Atto: write the contenteditable + sync to textarea) and fall back to textarea+`change` when no editor is active |
| **autocomplete** | hidden `select#id_<name>` + generated suggestion UI | set the underlying select's options/selection then trigger the autocomplete's refresh; for AJAX-backed ones a value the user must pick — likely **degrade to "I prepared X, pick it"** rather than forcing |
| date_selector / date_time_selector | `select#id_<name>_day/_month/_year[/_hour/_minute]` (+ optional `#id_<name>_enabled`) | tick `_enabled`, set each sub-select, dispatch `change` on each |
| duration | `input#id_<name>_number` + `select#id_<name>_timeunit` | set both, dispatch events |
| filepicker / filemanager | draft-area widget | **out of scope** (no programmatic file injection) |
| grouped elements (`group`) | children named `<group>[<child>]` | address children individually |

Implementation notes for the client applier:
- Resolve the **target form**: prefer a single visible `<form>` that is an mform
  (`form.mform`, or contains `input[name=sesskey]`); if several, pick the main content one / ask.
- Match select/radio options by value first, then by case-insensitive visible label (the LLM will
  often produce the label, not the internal key).
- Always dispatch `new Event('change', {bubbles:true})` (and `input` for text) so dependency logic
  (`disabledIf`/`hideIf`) and validation react.
- Never click submit. Optionally scroll to / highlight filled fields.
- Best-effort per field: skip what cannot be set, and **report back** which fields were filled vs
  skipped (so the user is not misled — same honesty rule as the rest of the agent).

## Field-discovery research (the client capture, leg 1)

At send time, walk the chosen form and emit a compact schema the LLM can map onto:

```js
{ form_id, fields: [ { name, label, type, required, current, options? } ] }
```

- `label` from the `<label for>` / mform label cell (this is what the user refers to).
- `type` normalised to the table above.
- `options` for select/radio/autocomplete (value + label), capped.
- `current` the present value (so "change X to Y" and "leave the rest" work).
- Cap total size; strip nothing security-relevant but DO send through the anonymiser server-side.

## Skill design

- **Name:** `core.fill_form` (page-generic, not booking-specific) — lives in `core/skills/`.
- **Risk class:** **R1 (scoped_write).** It mutates only the user's *unsaved* on-page draft, never the
  DB, and the user still reviews + submits. Confirmation via the standard pre-execution preview
  (`describe_proposed_action` → the `field: value` plan as rows). Arguably could be R0, but R1 +
  preview is the honest default because it changes the user's work-in-progress.
- **Capability:** page-scoped — the user already has the form open and the right to edit it; the skill
  adds no DB capability. Gate 1 (agent skill capability) only.
- **Input:** the user's intent (free text) + the `formdescriptor` (from the WS arg, not invented by
  the LLM).
- **Output:** a fill plan `[{name, value, matchby}]`. `describe_proposed_action()` renders it as the
  confirm preview; on confirm, the plan is handed to the client `js_module` applier which fills the
  DOM and returns a filled/skipped report rendered via `get_result_preview`.
- **Engine stays clean:** new `formdescriptor` WS arg (sanitised like `pagecontext`) + the existing
  `js_module` preview channel. No orchestrator/interpreter changes.

## Security / privacy / safety

- Form content runs through the **anonymiser** before the LLM (it can contain personal data).
- **Never auto-submit** — the human stays in the loop.
- Only fields present in the captured descriptor can be targeted (no blind DOM writes).
- Size caps on the descriptor and the plan; sanitise labels/values server-side.
- `disabledIf`/`hideIf` respected implicitly because we dispatch real events.

## Phased plan

- **Phase 0 — spike:** client capture of a plain mform (text/select/checkbox/date_selector) + an
  applier that fills them with events. Prove the round trip end-to-end on the booking option form.
- **Phase 1 — skill + plan:** `core.fill_form` skill (R1), `formdescriptor` WS arg + sanitiser,
  NL→plan mapping, `describe_proposed_action` preview, filled/skipped report.
- **Phase 2 — hard widgets:** editors (TinyMCE/Atto) and autocomplete (with graceful degrade), duration,
  grouped elements.
- **Phase 3 — polish:** multi-form disambiguation, highlight filled fields, "review then save" UX,
  partial-fill honesty messaging.

## Open questions

1. **Confirmation UX:** filling is reversible (user can edit/cancel) — is the standard confirm step
   wanted every time, or a lighter "filled — review and save" with undo? (Leaning: preview the plan,
   then fill without a second click; never submit.)
2. **Autocomplete / AJAX selects:** force a selection, or always degrade to "I prepared the value,
   please pick it"? (Leaning: degrade for AJAX-backed ones.)
3. **Scope of capture:** only mforms, or any `<form>`? (Leaning: mforms first.)
4. **Where the applier lives:** one generic `mod_booking/fill_form_apply` AMD vs. per-widget handlers.
5. **Editors:** is setting editor content in-scope for v1 or deferred to Phase 2? (Leaning: Phase 2.)
