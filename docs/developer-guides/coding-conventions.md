# Developer guide · Coding conventions

> **Scope.** Conventions specific to `bookingextension_agent` that are not obvious from the
> Moodle coding standard. The Moodle standard (`phpcs --standard=moodle`, 0 errors / 0 warnings)
> applies on top of everything here.

## 1. `declare(strict_types=1)` — deliberate, not uniform

This is a **deliberate policy decision** (audit C2-F06), not an oversight:

- **New, isolated service/value classes ship with `declare(strict_types=1)`.** They have
  narrow, well-typed boundaries, so strict typing catches real mistakes early. This is the
  default for anything new.
- **The always-on engine core stays NON-strict on purpose** — specifically `orchestrator`,
  `executor`, `conversation_store`, and the runtime loop around them. These files sit on the
  boundary between LLM-shaped JSON (everything arrives as strings) and typed core/Moodle APIs,
  and they **rely on PHP's implicit scalar coercion** at call boundaries (e.g. a numeric string
  flowing into an `int` parameter). Turning on `strict_types` there would convert
  silently-working coercions into fatal `TypeError`s.

  This is not theoretical: a real coercion bug was once caught exactly here — a context
  property arrived as a string and was passed into an `int` core API. The fix was an **explicit
  cast at the call site** (`(int)$context->instanceid`), not a blanket `strict_types`. The
  lesson encoded in this policy: **harden at the call site with explicit casts; do not flip a
  whole engine file to strict and hope.**

**Rule of thumb.** Adding `declare(strict_types=1)` to an *existing* engine file is **not** a
free, mechanical change — it changes that file's runtime behaviour at every function boundary.
Only do it after auditing the file's call sites for coercion-dependent inputs and adding
explicit casts where needed. New isolated files: strict from the start.

## 2. Single sources of truth

- **Provider action class names** (`aiprovider_wunderbyte\aiactions\…`) live only in
  `wb_action_names` — never re-declare the FQCN string (audit C3-F01).
- **Issue-code meaning** lives only in `issue_code_taxonomy` (`error_class_for` /
  `retry_category_for`) — the two views keep their own match precedence on purpose, but both
  rule sets live in that one class (audit C3-F02 / 08-F01).
- **Input normalization / pruning** lives in `services/input_normalizer` (option-driven) and
  `services/input_payload_pruner` — don't re-implement these recursions inline (audit 03-F03 /
  05-F02).

## 3. User-facing strings

Every user-visible string goes through `get_string()` bound to the request's output language —
never a hard-coded literal. Reuse core strings (yes/no, calendar weekday names, …) where they
exist. Agent skill strings live in the `bookingextension_agent` component.

## 4. Engine ↔ domain boundary

The engine (`classes/local/wizard/**` minus the domain skills) carries **no** domain field
names or skill-specific knowledge. Domain specifics live behind the per-skill hooks
(`normalize_skill_input`, the skill schema, the providers). The executor and orchestrator stay
skill-agnostic.
