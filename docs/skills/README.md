# Skills catalog

> **Scope.** Every skill the agent ships with: what it does, its risk class, and its key
> parameters.

A **skill** is one capability the agent can invoke. The engine-provided skills are split by
responsibility across four namespaces (all registered by
`bookingextension_agent\local\wizard\skill_provider` and discovered from the matching
`classes/local/wizard/<namespace>/skills/` directory):

- **`wizard.*`** — agent-specific engine skills (memory, doc-Q&A, skill discovery), in
  `classes/local/wizard/wizard/skills/`. This namespace is the engine's always-on discovery
  baseline.
- **`core.*`** — Moodle-core (user) skills, in `classes/local/wizard/core/skills/`. `core` is
  reserved here for genuine Moodle-core domain, matching its meaning in Moodle itself.
- **`course.*`** — Moodle-course skills, in `classes/local/wizard/course/skills/`.
- **`question.*`** — Moodle question-bank skills, in `classes/local/wizard/question/skills/`.

Booking-domain skills live under **`mod_booking.*`** (discovered from the `mod_booking`
component, base class `booking_skill_base`).

Every skill is gated at run time by its per-skill capability
`bookingextension/agent:skill_<name>` and by the activation toggle
`aiskillenabled_<name>`.

The abstract base class `core_skill_base` stays in `core/skills/` and is extended by all
engine skills regardless of namespace.

---

## Agent engine skills (`wizard.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `wizard.explain_docs` | R0 | ✓ | Search the documentation corpora and return a relevant excerpt (any language) | `question`, `outputlang`, `doc_path`, `corpus_id`, `line_start` |
| `wizard.list_skills` | R0 | ✓ | List the agent's capabilities / skill names | `question`, `scope`, `outputlang` |
| `wizard.recall_memory` | R0 | ✓ | Recall the user's own earlier conversation (last thread / date window) | `mode`, `date_hint`, `query` |
| `wizard.remember` | R0 | ✓ | Store a user-stated fact/preference | `memory`, `scopes` |
| `wizard.forget` | R0 | ✓ | Remove a stored user memory | `query` |
| `wizard.list_memories` | R0 | ✓ | List the user's stored memories | `outputlang` |
| `wizard.search_skills` | R0 | ✓ | RAG fallback — search the registry for capabilities discovery missed | `query` |
| `wizard.recreate_skill_catalog` | **R2** | ✗ | Rebuild the skill-catalog embeddings CSV | `force`, `model`, `dimensions` |

`wizard.explain_docs` is preview-capable (`get_result_preview`). All are R0 **except**
`wizard.recreate_skill_catalog`, which mutates the embeddings index (R2).

## Moodle-core skills (`core.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `core.get_current_user` | R0 | ✓ | Return info about the current user | `outputlang` |
| `core.search_users` | R0 | ✓ | Find users with profile/courses/roles | `query`, `limit`, `outputlang` |

Both are preview-capable (`get_result_preview`).

## Moodle-course skills (`course.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `course.search_courses` | R0 | ✓ | Find courses matching a query | `query`, `limit`, `outputlang` |

## Moodle question-bank skills (`question.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `question.generate_questions` | **R2** | ✗ | Generate questions (optionally from an upload) and import them into the course question bank | `topic`, `count`, `qtype`, `courseid` |

---

## Booking skills (`mod_booking.*`)

### Read-only (R0)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.search_options` | Search/list options in the current instance | `query`, `when`, `limit` |
| `mod_booking.get_option_details` | Detailed info for one/more options | `optionid`, `optionids`, `optionquery`, `fields` |
| `mod_booking.list_option_properties` | List the option create/update schema fields | `question`, `scope` |
| `mod_booking.analyze_rules` | Read-only analysis of booking rules / notifications | `query`, `active_only`, `include_templates` |
| `mod_booking.diagnose_booking_issue` | Why a user can't book / isn't booked | `optionquery`, `userquery`, `issue` |
| `mod_booking.diagnose_cancellation_issue` | Why a user can't cancel | `optionquery`, `userquery` |
| `mod_booking.diagnose_user_booking` | Verbose status report for one person — status, when booked, completion, previous/cancelled bookings, submitted form data, and received messages. Option-scoped when an option is named, else an instance-wide overview (e.g. "how many options has X completed") | `userquery`/`userid`, `optionquery`/`optionid` (optional), `includemessages` |

### Scoped write (R1)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.configure_booking_instance` | Configure the booking activity instance (`action=list_fields`/`update`) | `action`, `changes` |

### Broad write (R2)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.create_option` | Create a standard booking option | `text`, option fields, `override` |
| `mod_booking.create_selflearning_option` | Create a self-learning option | `text`, `duration`, `maxanswers`, teacher fields |
| `mod_booking.create_slotbooking_option` | Create a slot/appointment option | `text`, `slot_*` fields |
| `mod_booking.update_option` | Update an existing option | `optionid`/`optionquery`, mutation fields, `override` |
| `mod_booking.update_option_trainer` | Assign/replace trainer(s) | `optionid`/`optionquery`, `teacherids`/`teacherquery`, `mode` |
| `mod_booking.bulk_update_options` | Update many options at once | `optionids`/`optionquery`/`apply_to_all`, mutation fields |
| `mod_booking.add_price_category` | Create a price category | `identifier`, `name`, `defaultvalue` |
| `mod_booking.create_rule_from_template` | Create a booking rule from a template | `templateid`/`templatequery`, `rulename`, `optionids` |
| `mod_booking.update_rule_from_template` | Update an existing rule | `ruleid`/`rulequery`, `templateid`, `active` |

### Irreversible / external (R3)

| Skill | Purpose | Key inputs |
|-------|---------|-----------|
| `mod_booking.book_users` | Book one or more users into an option via the standard bookit flow | `optionid`/`optionquery`, `bookusersquery`/`resolvedbookuserids`, `bookusersupdateexisting` |

`book_users` is the only R3 booking skill: it changes other users' booking state, so it
always requires manual confirmation and never auto-retries.

---

## Notes for skill authors

- **Discovery is semantic-only.** Skills are retrieved purely by embedding similarity (the
  description + `example_utterances` anchors). There is **no** lexical "always-include" tier — the
  `always_available` governance flag, `MANDATORY_SKILL_KEYWORDS` and `mandatory_on_trigger` /
  `intent_triggers` are all removed. If a skill is not retrieved, fix its anchors (utterances),
  never add a keyword. The single exception is `wizard.search_skills` (the RAG fallback), force-added
  to the catalog by `discovery_phase_service::ensure_search_skills_fallback()`.
- **`override`** appears on most mutating booking skills: it is how the agent confirms past a
  soft block (e.g. a duplicate-title `DOMAIN_CONFLICT`).
- **Option mutations** go through `mod_booking`'s `booking_option::update()` with form-style
  params; the executor and skills stay free of option-write internals.

_(Skill names, risk classes, and inputs were read from the skill classes; verify a specific
skill's full schema in its `get_schema()` before relying on an exact field name.)_
