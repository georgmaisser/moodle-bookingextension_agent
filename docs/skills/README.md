# Skills catalog

> **Scope.** Every skill the agent ships with: what it does, its risk class, and its key
> parameters. For *how to write* a skill see
> [developer-guides/writing-a-skill.md](../developer-guides/writing-a-skill.md); for the
> abstraction see [architecture/14-skill-layer.md](../architecture/14-skill-layer.md); for
> what risk class means see [architecture/15-risk-classes.md](../architecture/15-risk-classes.md).

A **skill** is one capability the agent can invoke. The agent ships **27 skills** in two
namespaces:

- **`core.*`** — 8 engine-provided, domain-agnostic skills, registered by
  `bookingextension_agent\local\wbagent\skill_provider`
  (in `classes/local/wbagent/core/skills/`).
- **`mod_booking.*`** — 19 booking-domain skills, discovered from the `mod_booking`
  component (in `mod/booking/classes/local/wbagent/options/skills/`, base class
  `booking_skill_base`).

Every skill is gated at run time by its per-skill capability
`bookingextension/agent:skill_<name>` and by the activation toggle
`aiskillenabled_<name>` (see [governance](../operations/governance.md)).

---

## Core skills (`core.*`)

| Skill | Risk | Read-only | Purpose | Key inputs |
|-------|:---:|:---:|---------|-----------|
| `core.explain_docs` | R0 | ✓ | Search the documentation corpora and return a relevant excerpt (any language) | `question`, `outputlang`, `doc_path`, `corpus_id`, `line_start` |
| `core.get_current_user` | R0 | ✓ | Return info about the current user | `outputlang` |
| `core.list_actions` | R0 | ✓ | List the agent's capabilities / skill names | `question`, `scope`, `outputlang` |
| `core.recall_memory` | R0 | ✓ | Recall the user's own earlier conversation (last thread / date window) | `mode`, `date_hint`, `query` |
| `core.search_courses` | R0 | ✓ | Find courses matching a query | `query`, `limit`, `outputlang` |
| `core.search_skills` | R0 | ✓ | RAG fallback — search the registry for capabilities discovery missed | `query` |
| `core.search_users` | R0 | ✓ | Find users with profile/courses/roles | `query`, `limit`, `outputlang` |
| `core.recreate_skill_catalog` | **R2** | ✗ | Rebuild the skill-catalog embeddings CSV | `force`, `model`, `dimensions` |

`core.explain_docs`, `core.get_current_user`, and `core.search_users` are preview-capable
(`get_result_preview`). Note that all core skills are R0 **except**
`core.recreate_skill_catalog`, which mutates the embeddings index (R2).

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
always requires manual confirmation and never auto-retries (see
[architecture/15-risk-classes.md](../architecture/15-risk-classes.md)).

---

## Notes for skill authors

- **Always-included skills.** A skill is force-included in the post-discovery catalog when it
  declares `'governance' => ['always_available' => true]` in `get_schema()` (how domain skills
  like `mod_booking.update_option_trainer` and `mod_booking.book_users` opt in), or when its
  name matches an engine-level keyword in `MANDATORY_SKILL_KEYWORDS` (which keeps
  `core.search_skills` reachable). The engine hardcodes **no** concrete skill names — see
  [discovery](../architecture/06-discovery-families-embeddings.md#4-the-embedding-query).
- **`override`** appears on most mutating booking skills: it is how the agent confirms past a
  soft block (e.g. a duplicate-title `DOMAIN_CONFLICT`).
- **Option mutations** go through `mod_booking`'s `booking_option::update()` with form-style
  params; the executor and skills stay free of option-write internals — see
  [writing-a-skill.md](../developer-guides/writing-a-skill.md).

_(Skill names, risk classes, and inputs were read from the skill classes; verify a specific
skill's full schema in its `get_schema()` before relying on an exact field name.)_
