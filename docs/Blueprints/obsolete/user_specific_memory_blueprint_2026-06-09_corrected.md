# User-Specific Memory Blueprint (Corrected)

**Date:** 2026-06-09
**Author:** Claude (review/correction of Antigravity draft 2026-06-08)
**Status:** Implemented (2026-06-10) — flowchart node added; code + tests landed.
Operational follow-ups remaining: run plugin upgrade + rebuild skill embeddings (§8).
**Supersedes:** `user_specific_memory_blueprint_2026-06-08.md`

---

## 0. What changed vs. the 2026-06-08 draft

Same goal, fixed against the actual codebase:

1. Skill location/namespace corrected: agent-core skills live in `core/skills/`
   (`bookingextension_agent\local\wbagent\core\skills`), **not** in mod_booking's
   `options/skills/`.
2. Naming corrected to the `core.*` convention. Term **"memory" kept** (Georg, 2026-06-09);
   the clash with the existing `core.recall_memory` is resolved by distinct verbs + sharp
   descriptions, not by renaming (see §1 / §5).
3. Table renamed `local_wbagent_user_memory` to match the existing `local_wbagent_*` family
   (Georg, 2026-06-09).
4. **Privacy provider added** (was missing entirely — blocker for storing user PII).
5. `forget` is a **list → confirm → delete-by-id** flow, **always explicitly confirmed**
   (Georg, 2026-06-09) — never a silent substring multi-delete.
6. Injection moved to the per-request `[SYSTEM_RUNTIME]` channel
   (`orchestrator::build_runtime_context_block`), injected **once** at discovery, not into
   the cached static SYSTEM prompt nor on every planner phase.
7. **Embeddings catalog rebuild** added (new skills are otherwise not discoverable).
8. Context-scoping made explicit (memories are global per user). **No update/edit skill** —
   editing is forget+remember (Georg, 2026-06-09).

---

## 1. Motivation & Goal

Let users persist personal facts/preferences/instructions (e.g. *"Ich bevorzuge Buchungen am
Vormittag"*, *"My employee ID is 12345"*) so the agent can personalize planning. We call the
stored items **memories**.

**Disambiguation vs. `core.recall_memory`** (critical for the selector): `recall_memory`
retrieves earlier **conversation** turns from `conversation_store`. The new skills manage
**stored facts the user explicitly told the agent to remember**. Distinct verbs
(`remember`/`forget`/`list_memories` vs `recall_memory`) + explicit "stored facts you told
the agent" vs "previous conversation" wording in each description + per-skill message
triggers + embeddings keep them apart.

Scope decisions:
- **Global per user** (no `contextid`): a stored memory applies across all booking instances.
- Memories guide the **planner** (system-runtime context), not individual executors.
- **No edit/update skill**: changing a memory = `forget` then `remember`.

---

## 2. Database Schema & Persistence

Table family follows the existing plugin tables (`local_wbagent_ai_*`,
`local_wbagent_benchmark_*`). New table: **`local_wbagent_user_memory`** (25 chars, under
the 28-char XMLDB limit).

### 2.1 `db/install.xml`
```xml
<TABLE NAME="local_wbagent_user_memory" COMMENT="User-stated memories/instructions for the AI agent">
    <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" COMMENT="Owning user"/>
        <FIELD NAME="memory" TYPE="text" NOTNULL="true" COMMENT="The stated fact/preference/instruction"/>
        <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0"/>
    </FIELDS>
    <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="userid_fk" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
    </KEYS>
    <INDEXES>
        <INDEX NAME="userid_idx" UNIQUE="false" FIELDS="userid"/>
    </INDEXES>
</TABLE>
```

### 2.2 `db/upgrade.php`
Standard `xmldb_table` create guarded by `$dbman->table_exists()`, then
`upgrade_plugin_savepoint(true, <newversion>, 'bookingextension_agent')`. Bump
`version.php`.

---

## 3. Limits & Budget Strategy

Enforced centrally in `user_memory_service`:
- Max memories per user: **15**
- Max chars per memory: **500**
- Max total chars per user: **4096**
- **Normalize + dedupe** on add (trim, collapse whitespace, reject case-insensitive
  duplicates) so the 15-slot budget is not wasted on near-duplicates.

On overflow, `remember` returns a friendly message stating the limit and suggesting the user
forget an outdated entry (no silent eviction).

---

## 4. Core Service: `user_memory_service`

`bookingextension_agent\local\wbagent\services\user_memory_service`
(`classes/local/wbagent/services/user_memory_service.php`):

- `add(int $userid, string $text): array` — normalize, run all three limit checks, dedupe,
  insert; returns `['status' => 'ok'|'limit'|'duplicate', 'message' => ..., 'id' => ?]`.
- `get_all(int $userid): array` — ordered list of records.
- `delete(int $userid, int $id): bool` — delete one **by id** (ownership-checked).
- `find(int $userid, string $query): array` — substring/fuzzy match; returns candidate
  records used only to *propose* deletions (never deletes directly).

All persistence goes through this service — skills never touch `$DB` directly.

---

## 5. Core Memory Skills (`core/skills/`, namespace `...\core\skills`)

Registered via the existing `bookingextension/agent` `skill_provider` (same provider that
registers `recall_memory_skill`/`search_skills_skill`). After adding them, rebuild the
embeddings (§8). Each description must explicitly contrast with `recall_memory` (§1).

### 5.1 `core.remember`
- **Risk class:** R1 (write, additive) → **auto-confirmable**.
- **Description (EN, selector-visible):** "Store a fact/preference/instruction the user
  explicitly asks the agent to remember for future planning (NOT for recalling past
  conversation — that is core.recall_memory)."
- **Params:** `{ memory: string (required, <=500 chars) }`
- **Triggers:** `core.remember_request` — "User asks the agent to remember a fact,
  preference or standing instruction about themselves."
- **Execute:** `user_memory_service::add()`; return confirmation or the limit/duplicate
  message.

### 5.2 `core.forget`  ← list→confirm→delete-by-id, always explicitly confirmed
- **Risk class:** R2 (destructive) → **always** through the preview/confirm pipeline
  (`preview_passthrough` + `confirm_run_service`); never auto-confirm, never silent
  multi-delete.
- **Params:** `{ query?: string, id?: int }`
- **Execute:**
  1. `id` given → `delete($userid, $id)` (ownership-checked) after explicit confirm.
  2. Else `find($userid, $query)` → return matches as a **preview** (`get_result_preview`
     returns the candidate list as data) and ask which id to delete. Zero matches → inform;
     multiple → list, do not delete.

### 5.3 `core.list_memories`
- **Risk class:** R0 (read-only).
- **Description:** "List the stored facts/preferences the user previously asked the agent to
  remember (NOT past conversation)."
- **Params:** none.
- **Execute:** `get_all($userid)` → numbered list (or "none stored yet"); also returns
  `observation_full` with ids so a follow-up `forget` can reference them.

---

## 6. Automated Context Injection

**Hook:** `orchestrator::build_runtime_context_block()`
([orchestrator.php:2159](file:///var/www/moodle/public/mod/booking/bookingextension/agent/classes/local/wbagent/orchestrator.php)),
which assembles the `[SYSTEM_RUNTIME]` lines and is explicitly kept out of the cache-friendly
static SYSTEM prompt.

Rules:
- Inject **only** at `PHASE_DISCOVERY` (mirroring the existing language-policy block) so it is
  not repeated across selection/construction → controls token cost.
- Resolve the acting `userid` (thread/conversation owner; thread it into the block if not
  already present).
- Format (only when non-empty):
  ```
  USER MEMORY (facts the user asked you to remember; respect these when planning):
  - "Ich bevorzuge Buchungen am Vormittag."
  - "My employee ID is 12345."
  ```
- Hard-capped at the 4096-char budget (guaranteed by §3).

No change to the static SYSTEM prompt and no per-executor injection.

---

## 7. Privacy Provider  (NEW — mandatory)

The plugin currently has **no** `classes/privacy/provider.php`. Storing user-identifiable
free text makes a real provider mandatory.

- Create `bookingextension_agent\privacy\provider` implementing
  `\core_privacy\local\metadata\provider` and
  `\core_privacy\local\request\plugin\provider`.
- Declare `local_wbagent_user_memory` in `get_metadata()` (fields `userid`, `memory`,
  `timecreated`, `timemodified` + purpose string).
- Implement `get_contexts_for_userid` / `export_user_data` / `delete_data_for_user` /
  `delete_data_for_all_users_in_context` / `get_users_in_context` at `CONTEXT_USER`.
- Model after an existing `mod_booking` provider for the boilerplate.
- Future agent tables needing privacy coverage live in this same provider.

---

## 8. Rollout / Discoverability

- After registering the 3 skills, **rebuild the skill-catalog embeddings**:
  `family_embeddings_index_service::rebuild_catalog($registry)` — otherwise the new skills
  are not retrievable by `core.search_skills`/selection (lesson from Thread 203).
- Add language strings for descriptions/confirm/skillcall keys (pattern: existing
  `agent_booking_recall_memory_*` strings).

---

## 9. Tests

- `user_memory_service`: add within/over each limit (count/single/total), dedupe, delete-by-id
  ownership, find matching.
- `core.remember`: persists; returns limit/duplicate message at threshold; auto-confirmable.
- `core.forget`: query→preview (no delete); id→delete after confirm; zero/multi-match paths;
  never auto-confirms.
- `core.list_memories`: empty and populated; ids present in observation.
- Injection: `build_runtime_context_block` emits the block at discovery only, respects the
  budget, empty when the user has none.
- Privacy: provider export/delete round-trip.
- Selector disambiguation: `remember`/`list_memories` are not confused with
  `recall_memory` (description/trigger separation).

---

## 10. Implementation Checklist

- [x] **DB:** `install.xml` + `upgrade.php` (step `2026061001`) create `local_wbagent_user_memory`; `version.php` bumped to `2026061001`. NOTE: the standalone `userid` index from the draft was dropped — the `userid_fk` foreign key already provides it, and a duplicate-field index makes `xmldb_table::addIndex()` throw.
- [x] **Service:** `user_memory_service` (add/get_all/delete/find + limits + dedupe).
- [x] **Skills:** `core.remember` (R1), `core.forget` (R2, preflight resolve → R2 confirm → delete-by-id), `core.list_memories` (R0) in `core/skills/`. Registration is automatic via directory auto-discovery — no `skill_provider` edit needed.
- [x] **Injection:** memory block in `orchestrator::build_runtime_context_block` (discovery-only, owner resolved via `store->get_thread()`, budget-capped, empty when none).
- [x] **Privacy:** `classes/privacy/provider.php` (metadata + export + delete, CONTEXT_USER).
- [x] **Lang strings:** `agent_memory_*` + `privacy:metadata:*`.
- [ ] **Discoverability (operational):** rebuild skill-catalog embeddings (run `core.recreate_skill_catalog` / `family_embeddings_index_service::rebuild_catalog`). Skills are discovered + selectable without it, but semantic retrieval needs the rebuild.
- [x] **Tests:** §9 — `user_memory_service_test` (8) + `user_memory_skills_test` (10), all green.
- [x] **Flowchart:** "User Memory" subgraph + wiring + `LG_MEM` legend added to
      `AGENT_IMPLEMENTATION_FLOWCHART.mmd` (also corrected the stale `LG_DB` legend).

---

## 11. Locked decisions (Georg, 2026-06-09)

1. **Term:** "memory" (not "preferences"); clash with `core.recall_memory` handled by
   distinct verbs + sharp descriptions (§1, §5).
2. **Confirmation:** `remember` auto-confirms (additive); `forget` **always** requires
   explicit confirmation.
3. **No update/edit skill:** editing a memory = `forget` + `remember`.

Remaining gate before coding: flowchart node added & signed off (§10 last item).

---

## 12. Scoped (channel-tagged) injection (2026-06-10, Georg)

Initial injection put *every* memory into one place. Refined so the LLM structures each memory
at storage time and injection is filtered deterministically per channel.

- **Tagging:** `core.remember` gains an optional `relevant_for[]` param with enum
  `selection` | `construction` | `synchronization`. The constructor LLM classifies by effect:
  selection = which action/skill; construction = field values/parameters; synchronization =
  reply wording/presentation. Stored in a new `local_wbagent_user_memory.scopes` column
  (comma-separated; **empty = all channels**, also the back-compat default for older rows).
- **Injection (`build_runtime_context_block`)** filters via
  `user_memory_service::get_for_scope($userid, $channel)`:
  - `PHASE_SELECTION` → channel `selection` (planner selection call),
  - `PHASE_PARAMETER_CONSTRUCTION` → channel `construction`,
  - synchronizer → channel `synchronization`, passed **explicitly** as a new `$memorychannel`
    argument because `process_synchronizer` also builds the block with `PHASE_SELECTION` and
    must not pull selection-only items.
  Discovery makes no LLM call → no channel. Untagged memories appear in all channels.
- **Surfacing:** `core.list_memories` shows each memory's `relevant_for`; privacy provider
  exports the `scopes` field.
- Why construction matters (the original gap): a memory like *"I prefer morning bookings"* or
  *"my employee id is 12345"* should default field values during parameter construction, which
  the selection-only injection never reached.
