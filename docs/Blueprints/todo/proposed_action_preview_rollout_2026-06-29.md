# Individual pre-confirmation previews for ALL write tasks — rollout plan

Status: COMPLETE (2026-06-29). All 17 write tasks now ship a tier-3 describe_proposed_action(),
all strings via get_string (bound to outputlang). Commits: P1 mod_booking 25fbe1e90 + agent lang;
P2 mod_booking 768ce321b + agent lang 0fd61f0; P3 agent 9e1269a; P4 mod_booking 3bfe2009e + agent
d86d640. Built on the proposed-action preview FRAMEWORK (ebd4598). Open decision #1 (label
localization) resolved: labels ARE localized via get_string/outputlang.

## Foundation already in place (do NOT re-do)

The engine-agnostic preview framework landed 2026-06-29:

- `base_skill::describe_proposed_action(array $input): ?array` — returns
  `{title, summary, rows:[{label,value}]}` or null. Ships a GENERIC default (tiers 1+2): surfaces
  only schema-declared fields, label = schema `label` ∥ humanized key, value formatted by declared
  type, drops framework-internal (`outputlang`) / empty / falsy entries. Protected helpers for reuse:
  `short_skill_name()`, `humanize_identifier()`, `format_proposed_action_value()`.
- `services/proposed_action_preview::build_preview_json($commands, $registry)` — asks each proposed
  command's skill to describe itself; wraps into a self-contained descriptor `{type:'proposed_action',
  payload:{actions:[...]}}`. Best-effort. Built ONLY when there is no executed-result preview and
  `response_type == confirmation_request`. Wired into `ai_send_message` + `ai_confirm_run`.
- Client: `amd/src/aiinstructions.js` registers a `proposed_action` renderer (title/summary/rows);
  the raw `command_list` JSON dump is now FALLBACK ONLY (used when the server sent no descriptor).
- Flowchart `APREVIEW` node documents the two-source model.
- Test: `tests/proposed_action_preview_test.php` (generic default + tier-3 + service wrapping).

Because `mod_booking\local\wizard\options\skills\booking_skill_base extends
bookingextension_agent\local\wizard\base_skill`, EVERY write skill (booking, course, question,
wizard) already gets the generic floor for free. This plan is about replacing that floor with a
hand-tuned **tier-3 override** per write task.

## Goal

Every confirm-gated write task returns a human-readable, individually shaped preview of exactly what
it is about to change, so the user confirms against meaning — not against a parameter dump. The
engine stays 100% skill-agnostic: all shaping lives in the skill's `describe_proposed_action()`
override. No engine/executor/interpreter edits.

## What "tier-3" means per skill (the override contract)

Each override SHOULD:

1. **Title** — a short imperative naming the action and its subject, e.g.
   `Create slot booking option "Sprechstunde"`. Use the resolved subject (option title, course
   name, rule name), not a raw query.
2. **Summary** — ONE plain sentence capturing the essence (the thing a user actually reads before
   clicking), e.g. *"Bookable 08:00–10:00 on Wednesdays, 30-min slots, 1 seat each, 1 Jul–31 Jul
   2026."* Optional but strongly preferred for the high-traffic skills.
3. **Rows** — a curated, ordered set of `{label, value}` pairs:
   - **Collapse** related fields (the 7 `slot_day_*` booleans → one "Weekdays: Wednesday";
     opening/closing → "Availability window 08:00–10:00"; prices map → "default 10 €, student 5 €").
   - **Relabel** technical keys into domain language (`maxanswers` → "Seats", `maxoverbooking` →
     "Waiting list").
   - **Resolve** queries to names: previews run on PREPARED input (post-preflight), so prefer the
     resolved value (teacher name, course full name, target activity + id) over the raw `*query`
     field. Where the resolved id/name is available in prepared input, show it.
   - **Format** dates/times in the user's locale; booleans as enabled/disabled or yes/no.
   - **Omit** noise and anything sensitive or internal (tokens, `outputlang`, `optiontype`,
     `slot_enabled`, draft-area ids, override-condition id arrays).
4. **Localize** to the request language. DECISION NEEDED (see open questions): use `get_string()`
   with the skill's resolved output language (the skills already read `outputlang`), so labels match
   the conversation language. Until decided, English labels via `get_string` defaults are acceptable.
5. **Stay engine-clean**: no references to engine/orchestrator/interpreter; reuse the protected
   `base_skill` helpers; return data only.

Return `null` (fall back to the generic floor) only when there is genuinely nothing meaningful to
show — which should be rare for a write.

## Inventory — all current confirm-gated write tasks (readonly=false)

`remember` (wizard.remember) is R0/auto-exec → NOT confirm-gated → out of scope. `scaffold_skill`
produces an inert ZIP, no confirmation → out of scope.

### A. Booking option family — `mod_booking` (share one helper)
| Skill | Risk | Shaping notes |
|---|---|---|
| `mod_booking.create_option` | R2 | Title + key fields: title, sessions (coursestarttime/endtime or optiondates count), seats, waiting list, location, price map, linked course (resolved), teacher (resolved), booking open/close window, visibility. |
| `mod_booking.create_slotbooking_option` | R2 | The worked example: availability window, weekdays (collapsed), slot length, seats per slot, validity range, summary line. |
| `mod_booking.update_option` | R2 | Show the TARGET option (name + id) first, then ONLY the fields being changed (the prepared diff), not the whole option. |
| `mod_booking.update_option_trainer` | R2 | Target option + resolved trainer name(s) added/removed. |
| `mod_booking.bulk_update_options` | R2 | Match criteria + count of affected options + the change set, compactly (per `[[feedback_bulk_mirrors_update_option]]`). |
| `mod_booking.configure_booking_instance` | R2 | Target instance (course + activity name) + the instance settings changed. |
| `mod_booking.book_users` | R3 | Target option + resolved user list (names, capped with "+N more") + completion/waitlist flags. R3 ⇒ preview is especially important; ensure user identities respect the anonymizer at display. |
| `mod_booking.add_price_category` | R2 | New category identifier + display name + default price. |

### B. Booking rules — `mod_booking` (share one helper)
| Skill | Risk | Shaping notes |
|---|---|---|
| `mod_booking.create_rule_from_template` | R2 | Resolved template name + target + the rule's trigger/condition/action in words. |
| `mod_booking.update_rule_from_template` | R2 | Target rule (name + id) + changed fields only. |

### C. Course activities — `bookingextension_agent` (share one helper)
| Skill | Risk | Shaping notes |
|---|---|---|
| `course.add_activity` | R2 | Module type (clear name), activity name, target course + section (clear names), key whitelist fields. |
| `course.update_activity` | R2 | Target activity (name + id) + changed fields only. |
| `course.add_quiz` | R2 | Quiz name + target course/section + question count + feedback/cmidnumber if set. |
| `course.update_quiz` | R2 | Target quiz + changed fields. |

### D. Question generation — `bookingextension_agent`
| Skill | Risk | Shaping notes |
|---|---|---|
| `question.generate_questions` | R2 | Source (PDF name) + target question bank category (resolved) + number/type of questions to import. Already has a rich get_result_preview; this is the PRE-import counterpart. |

### E. Agent self-management — `bookingextension_agent`
| Skill | Risk | Shaping notes |
|---|---|---|
| `wizard.forget` | R2 | Which memory/memories will be deleted (the matched memory titles), so deletion is confirmed against the actual targets. |
| `wizard.recreate_skill_catalog` | R2 | What gets rebuilt (catalog + embeddings) + scope; a one-liner is enough. |

Total in scope: **17 write tasks** (8 option-family + 2 rules + 4 course + 1 question + 2 wizard).

## Shared-helper strategy (avoid 17 bespoke implementations)

Most shaping repeats. Build small, skill-side (NOT engine-side) helpers so each override is a few
lines:

- **Option preview helper** (in `booking_skill_base` or an option-skills trait): maps the common
  option field set → labelled rows with the collapsing/relabel/resolve rules above. create / update
  / bulk / slotbooking / trainer compose it; create_slotbooking adds the weekday/window collapsing
  and the summary line; update/bulk feed it ONLY their prepared diff.
- **Rule preview helper**: shared by the two rule skills.
- **Activity/quiz preview helper**: shared by the four course skills (module type clear-name,
  course/section clear-name, changed-fields-only for updates).
- **Diff awareness**: update/bulk skills must preview the *prepared change set*, not a full record.
  Confirm where the prepared diff lives in prepared_input and expose a `changed-fields-only` path.

Keep helpers data-only and reuse `base_skill::humanize_identifier()` /
`format_proposed_action_value()` for the long tail of unmapped fields.

## Phased rollout

- **Phase 1 — Option family core** (highest traffic, biggest win): `create_slotbooking_option`
  (the worked example) + `create_option` + `update_option`, plus the shared option preview helper.
  ✅ DONE (mod_booking 25fbe1e90): `option_preview_builder` + the three `describe_proposed_action()`
  overrides + `option_preview_builder_test` (5/5). Labels English for now (open decision #1).
- **Phase 2 — Rest of option family**: `update_option_trainer`, `bulk_update_options`,
  `configure_booking_instance`, `book_users` (R3 user list), `add_price_category`.
  ✅ DONE (768ce321b): extended `option_preview_builder` (11/11 tests).
- **Phase 3 — Course family**: `add_activity`, `update_activity`, `add_quiz`, `update_quiz` + helper.
  ✅ DONE (9e1269a): `activity_preview_builder` (4/4 tests).
- **Phase 4 — Rules + question + wizard**: `create_rule_from_template`,
  `update_rule_from_template`, `generate_questions`, `forget`, `recreate_skill_catalog`.
  ✅ DONE (3bfe2009e + d86d640): `rule_preview_builder` + `preview_support` (6/6 tests).

Each phase: implement overrides → unit tests → `phpcs` 0/0 → run focused PHPUnit. No `grunt` needed
(JS framework already done). No engine touch.

## Testing

- Extend the framework test pattern (`proposed_action_preview_test`): for each override, a small
  data test asserting title/summary and the collapsed/relabelled/resolved rows for a representative
  prepared input, and that sensitive/internal keys are absent.
- For update/diff skills, assert that ONLY changed fields appear.
- For `book_users`, assert the user-list cap and that no de-anonymized identity leaks before the
  display boundary (the WS layer de-anonymizes; the skill must not pre-resolve raw identities into
  the preview in a way that bypasses the anonymizer).
- Keep tests DB-light where possible by exercising `describe_proposed_action` directly with prepared
  input fixtures.

## Acceptance criteria

- Every one of the 17 write tasks returns a tailored, non-dump preview for a typical request.
- No raw JSON parameter dump is ever shown for a write confirmation (generic floor at worst, tailored
  at best).
- Engine/executor/interpreter and the flowchart's engine contracts unchanged.
- `phpcs --standard=moodle` 0/0; focused PHPUnit green per phase.

## Risks & open decisions

1. **Localization of labels** — confirm whether labels follow the conversation language via
   `get_string()` + resolved `outputlang` (recommended), or stay English. Affects all overrides.
2. **Prepared vs raw input** — previews run on prepared_input (post-preflight). Verify each skill's
   prepared shape (resolved ids/names present?) so overrides show resolved values; where a query
   wasn't resolved at confirm time, fall back to the raw query string with a clear label.
3. **Diff exposure for update/bulk** — confirm a reliable "changed fields only" source in prepared
   input; otherwise previews risk showing unchanged or defaulted fields as if they were edits.
4. **R3 user lists (`book_users`)** — large lists need capping; identities must respect the privacy
   anonymizer / display de-anonymization boundary (see `[[project_anonymizer_recall_reanchor]]`).
5. **Multi-step confirm chains** — a chain can surface several proposed actions; the descriptor
   already supports multiple `actions[]`, but verify ordering/labelling reads well.

## Out of scope

- Read-only skills (auto-execute, no confirmation).
- `wizard.remember` (R0), `scaffold_skill` (inert ZIP).
- Any engine/interpreter/orchestrator change — the framework is complete; this is skill-only work.
