# Capability-Fidelity & Data-Isolation Audit — Wizard / Core Utility Skills

Scope: `mod/booking/bookingextension/agent/classes/local/wizard/` wizard + core utility
skills (user memory store, skill discovery/introspection, scaffolding, docs lookup, self
profile). Read-only review; **no code changed**.

Engine model recap:
- **R0** skills skip preflight — only **Gate 1** (skill-use capability
  `bookingextension/agent:skill_<suffix>` at the ambient context) is enforced; there is no
  Gate-2 native check.
- **R2** skills run preflight + the central `native_capability_guard` (**Gate 2**) at the
  operating context.
- `$userid` is established **once** at every webservice entry point as `(int)$USER->id`
  (e.g. `classes/external/ai_send_message.php:270`, `classes/external/ai_confirm_run.php:157`)
  and threaded unchanged through `agent_runtime → decision/confirm_run service →
  executor::execute_commands() → skill::execute()`. **No `userid` request parameter exists**,
  so for every skill in scope `$userid == $USER->id` — skills run in the actor's own session.

---

## Per-skill verdicts

| Skill | Risk | Verdict |
|-------|------|---------|
| `wizard.remember` | R0 | SAFE |
| `wizard.forget` | R2 | SAFE |
| `wizard.recall_memory` | R0 | SAFE |
| `wizard.list_memories` | R0 | SAFE |
| `wizard.list_skills` | R0 | SAFE |
| `wizard.search_skills` | R0 | SAFE |
| `wizard.scaffold_skill` | R0 | SAFE |
| `wizard.explain_docs` | R0 | SAFE |
| `core.get_current_user` | R0 | SAFE |
| `wizard.recreate_skill_catalog` | R2 | **HOLE** (authorization scope/cap) |

---

## 1. Memory isolation (remember / forget / recall / list_memories)

**Verdict: SAFE — strictly per-user isolated.**

`user_memory_service` (`classes/local/wizard/services/user_memory_service.php`) keys **every**
read/write by the `$userid` passed in by the engine. There is no method that takes another
addressing dimension and no `userid` field is ever read from skill input.

- `add(int $userid, …)` — insert record is built with `'userid' => $userid`
  (`user_memory_service.php:128`); dedupe/budget all run over `get_all($userid)`.
- `get_all(int $userid)` — `$DB->get_records(self::TABLE, ['userid' => $userid], …)`
  (`user_memory_service.php:156`), with a `$userid <= 0` guard (`:152`).
- `delete(int $userid, int $id)` — **ownership-checked**: existence is verified with the
  compound key `['id' => $id, 'userid' => $userid]` (`:173`) and the delete uses the **same
  compound key** (`:177`). Deleting another user's id by guessing the id is impossible — the
  `record_exists` fails and it returns `false`.
- `find(int $userid, …)` / `get_for_scope(int $userid, …)` — both iterate only over
  `get_all($userid)` (`:197`, `:218`).

The four skills pass the engine `$userid` straight through and **never** call
`resolve_userid()` / read a `userquery`:
- `remember_skill.php:197` → `$service->add($userid, …)`.
- `list_memories_skill.php:118` → `$service->get_all($userid)`.
- `recall_memory_skill.php` — see §1b.
- `forget_skill.php` — see §5.

> Confirmed by grep: `userquery` / `resolve_userid` appear in **none** of the four memory
> skill files. (The base helper `core_skill_base::resolve_userid()` at
> `core/skills/core_skill_base.php:159` *can* resolve a foreign user from a `userquery`
> field, but it is used only by other-user lookup skills — diagnose/profile — that carry
> manager-grade capabilities. The memory skills do not import it.)

**No parameter exists that lets a caller address another user's memory.** The schema text in
each skill even states "User isolation is strict; userid is never taken from input"
(e.g. `remember_skill.php:71`).

### 1b. recall_memory conversation store

`recall_memory_skill::execute()` reads through `conversation_store`, every method of which is
userid-scoped:
- `get_last_thread_for_user($userid, …)` → `WHERE userid = :userid`
  (`conversation_store.php:341,353`).
- `get_user_threads_by_date_window($userid, …)` → `WHERE t.userid = :userid AND m.userid =
  :userid2` (`conversation_store.php:427-428`).
- `get_user_messages_for_thread($userid, $threadid, …)` — first re-checks thread ownership
  with the compound key `['id' => $threadid, 'userid' => $userid]`
  (`conversation_store.php:463`), then filters messages by `userid` (`:470`). A foreign or
  injected `threadid` resolves to no thread → empty result. **Cross-user recall is
  impossible.**

The placeholder re-anchoring (`recall_memory_skill.php:281-302`) operates token-to-token
within the user's own threads (re-anchor only runs for `sourcethreadid` values that came from
this user's own query results), so no clear-text PII and no cross-user leakage. `query` is
declared a sensitive input field (`:415`) and stripped from echoes.

---

## 2. `wizard.recreate_skill_catalog` — **HOLE (authorization)**

**Verdict: HOLE.** A *teacher / editingteacher* can trigger a **site-global, cost-bearing**
embeddings rebuild of the entire skill catalog, and Gate 2 does nothing to stop it.

Evidence:
- The skill queues a **global** adhoc task that rebuilds the whole skill-catalog embeddings
  CSV (`recreate_skill_catalog_skill.php:189-194`,
  `manager::reschedule_or_queue_adhoc_task(new rebuild_skill_catalog_embeddings_adhoc())`).
  This calls the embeddings provider for every skill (real LLM cost) and overwrites a
  site-wide artifact — there is nothing module-scoped about it.
- It is R2 (`recreate_skill_catalog_skill.php:41`), so it *should* be gated by Gate 2. But it
  **declares no `get_required_native_capabilities()`** — so
  `native_capability_guard::missing_capabilities()` returns `[]` immediately
  (`native_capability_guard.php:54-62`). **Gate 2 is a no-op.**
- It **declares no `context_scopes`** in `prompt_meta`, so it inherits the default
  `['module']` from `base_skill.php:408`. This **misrepresents** a system-scope action as a
  module action (the RED FLAG 12-F02 confirmed).
- The only remaining gate (Gate 1, the skill-use cap
  `bookingextension/agent:skill_wizard_recreate_skill_catalog`) is built from the
  **`$teacherskills`** list (`db/access.php:129`), so `$buildskillcapability(…, 'teacher')`
  grants it to `teacher` + `editingteacher` at `CONTEXT_MODULE`
  (`db/access.php:178-184, 201-203`). A teacher in any course/module they can use the agent in
  can fire a global rebuild.

**Impact:** privilege/cost — a low-privilege editing teacher can repeatedly enqueue an
expensive global embeddings regeneration (denial-of-wallet / cache thrash). It is a *queued
task*, not direct data loss, which is why this is a HOLE rather than a critical breach, but it
violates concern (b): a site-wide/admin action must require a high (manager/admin) capability.

**Recommended fix (no code change applied):**
1. Move `'wizard_recreate_skill_catalog'` out of `$teacherskills` and into `$managerskills`
   (`db/access.php`) so the generated cap is manager-only — or, better, into a system-scope
   admin-only definition (empty `archetypes`, `CONTEXT_SYSTEM`, `RISK_CONFIG`) like the
   provider-secret caps at `db/access.php:236-264`.
2. Declare honest scope on the skill: add
   `'context_scopes' => ['system']` to the `prompt_meta` in
   `recreate_skill_catalog_skill.php::get_schema()` so the catalog/preview stop labelling it a
   module action.
3. Add a real Gate-2 check: implement
   `get_required_native_capabilities(): array { return ['moodle/site:config']; }` (or the new
   manager-scoped skill cap evaluated at `CONTEXT_SYSTEM`) so the central
   `native_capability_guard` enforces it at the system context even if a future caller crafts
   the command. Also override `get_required_context_level(): int { return CONTEXT_SYSTEM; }`
   so the operating context resolves to system, not the ambient module.

---

## 3. `wizard.scaffold_skill` — SAFE

**Verdict: SAFE — inert, no provider call, no arbitrary write, no path traversal, no path
leak.**

- **No provider/LLM call, no DB:** `skill_template_generator::generate()` is purely
  deterministic string templating (`services/scaffold/skill_template_generator.php`); the
  generated `execute()` body is an inert "not implemented yet" placeholder
  (`NOT_IMPLEMENTED_MESSAGE`, `:42`).
- **No write outside a temp area:** the only filesystem write is the ZIP built in
  `make_request_directory()` (`skill_template_generator.php:754-755`), immediately read back
  and `@unlink`-ed (`:766-767`). The ZIP is returned base64-in-memory; nothing is written into
  the codebase. The `relativepath` it embeds is a *string inside generated text*, never a real
  write target.
- **No server-path leak:** generated content is component-name / skill-name derived only;
  there are no `$CFG->dirroot`/absolute paths in the output. `component`, `skillname`,
  namespaces are all slugified to `[a-z0-9_]` (`slugify()`, `:886`).
- **Namespace abuse blocked:** `validate_spec()` rejects reserved namespaces
  (`component_may_register_namespace`, `:168`) and forces R2/R3 templates to declare scopes
  (`:175-181`), so the generated artifact is contract-valid and cannot claim the engine's own
  `booking`/`core`/`wizard` namespaces.
- R0, no sensitive fields (`scaffold_skill.php:304`). The download card escapes file names /
  warnings via `s()` (`scaffold_skill.php:265-290`); the only non-escaped value is the
  base64 ZIP body in a `data:` URI (`:279`), which is opaque generated data.

Minor (informational, not a finding): the generated `db_access.php.txt` snippet defaults to
`editingteacher`+`manager` archetypes (`skill_template_generator.php:679-681`). That is
guidance the third-party dev must review for *their* skill — it does not affect this plugin's
authorization. Worth a doc note given finding §2.

---

## 4. explain_docs / search_skills / list_skills — SAFE

**`wizard.explain_docs` (R0) — SAFE.**
- Corpora are **admin-configured**: roots come from `get_config('bookingextension_agent',
  'aidocsroot')` (`docs_corpus_registry.php:143`) and the registry only exposes corpora whose
  root exists and lies **under `$CFG->dirroot`** (class contract `docs_corpus_registry.php:43`).
- **Path traversal blocked:** `sanitize_rel_path()` rejects any `..` and allows only `*.md`
  (`docs_lookup_service.php:586-598`); reads go through `resolve_root($corpusid) . '/' .
  $relpath` with an `is_readable` gate (`:260-261`). A caller cannot read arbitrary server
  files or non-doc files.
- **Output escaping:** the only public URL built is for the `mod_booking` corpus, gated by an
  `is_readable` check on the resolved component dir (`explain_docs_skill.php:549-571`); other
  corpora get no clickable URL. Preview rendering goes through
  `doc_markdown_preview_renderer` (`explain_docs_skill.php:619`). Returned content is shipped
  documentation (admin-provisioned), explicitly marked `observation_engine_static` so the
  anonymizer leaves it intact.
- It is `requires_explicit_activation()` (`explain_docs_skill.php:79`) — off until an admin
  configures a corpus, so it cannot run in a degraded/leaky state by default.

**`wizard.search_skills` (R0) — SAFE.** Returns only registered-skill names + their public
`description` strings from the discovery provider (`search_skills_skill.php:212-218`). No
server paths, no disabled-skill internals beyond name/description, no capability internals,
no user data. Discovery is engine-injected; the skill only formats.

**`wizard.list_skills` (R0) — SAFE, and *correctly* actor-scoped.** The available/unavailable
split is computed by the injected introspection service evaluated **at the acting
`$userid`/`$contextid`** (`list_skills_skill.php:198-199`), so each user only sees what *they*
may run. Unavailable actions are shown with a localized deny *reason label* and, at most, the
*names* of required capabilities and the contextid (`build_unavailable_action_detail`,
`:377-398`) — this is the same information the user would get by trying the action, not a
privilege leak. No server file paths are exposed.

---

## 5. `wizard.forget` (R2) — SAFE

**Verdict: SAFE — confirmation-gated and cannot touch another user's data.**

- R2 with `is_read_only() == false` (`forget_skill.php:44`) ⇒ runs preflight + Gate 2 + always
  routes through explicit confirmation (the decision service promotes the `pass()` result to a
  confirmation). Schema text confirms "always asks for confirmation before deleting"
  (`forget_skill.php:87-90`).
- **All resolution is over the actor's own store:** every path in `run_preflight()` reads
  `$service->get_all($userid)` / `$service->find($userid, …)`
  (`forget_skill.php:211, 228, 247, 252`). The explicit-id path walks the user's own records
  and only `pass()`es if the id is found among them (`:226-243`) — a foreign id yields an
  `id_not_found` clarification, never a delete.
- **Zero / multi match never deletes** — it returns a clarification with the candidate list
  (`:248-271`).
- **`all=true` clears only the actor's own memories:** preflight collects ids exclusively from
  `$service->get_all($userid)` (`:211, 220`) and `execute()` deletes them one-by-one through
  `$service->delete($userid, (int)$deleteid)` (`:297`), which is itself ownership-checked
  (compound `id+userid` key, see §1). Even a tampered `ids` array in the prepared input cannot
  delete another user's rows — `delete()` re-verifies ownership per id.
- Gate-1 cap `…:skill_wizard_forget` is in `$authorizeduserskills` → `'user' => CAP_ALLOW`
  (`db/access.php:166, 191-196`). This is **appropriate**: forget only ever touches the
  caller's own data, so any authenticated agent user may delete *their own* memories. (This
  correct placement is the contrast that makes §2's misplacement clear.)
- Gate 2: the skill declares no native cap, which is acceptable here because the action is
  intrinsically self-scoped (no foreign context is ever touched), unlike §2 which acts
  site-wide.

---

## Issues (severity-ranked)

- **[HIGH] Teacher can trigger a global, cost-bearing embeddings rebuild.**
  `wizard.recreate_skill_catalog` skill-use cap is in `$teacherskills`
  (`db/access.php:129`) → granted to teacher/editingteacher; the skill declares no native
  capability so Gate 2 is a no-op (`recreate_skill_catalog_skill.php` has no
  `get_required_native_capabilities()`; `native_capability_guard.php:54-62`). Fix: move to
  manager/admin + add `moodle/site:config` native cap + `CONTEXT_SYSTEM`.
- **[LOW] Scope misrepresentation:** `wizard.recreate_skill_catalog` declares no
  `context_scopes`, inheriting `['module']` (`base_skill.php:408`) for a system-scope action
  (RED FLAG 12-F02 confirmed). Fix: declare `'context_scopes' => ['system']` +
  `get_required_context_level(): CONTEXT_SYSTEM`.
- **[INFO] Scaffold snippet defaults to teacher archetypes** for the generated db/access entry
  (`skill_template_generator.php:679-681`) — third-party guidance only, but worth a doc note in
  light of the §2 misplacement so downstream devs don't repeat it.

## What is provably safe

- Memory store (`user_memory_service`) is **strictly per-user**: every read/write keyed by the
  engine `$userid` (== `$USER->id`); `delete()` is compound-key ownership-checked; no input
  field can address another user's memories. `forget all=true` clears only the actor's own
  rows.
- `conversation_store` recall paths are all userid-scoped with thread-ownership re-checks.
- `scaffold_skill` is inert (no provider, no DB), writes only to a request temp dir that it
  immediately deletes, and cannot traverse paths or leak server paths.
- `explain_docs` corpora are admin-configured and dirroot-confined; `..`-traversal and
  non-`.md` reads are rejected; output is escaped / engine-static documentation.
- `list_skills` is evaluated at the acting user's rights; `search_skills` exposes only
  registered names+descriptions. `core.get_current_user` reads only `$USER`
  (`get_current_user_skill.php:153-155`) — self only, confirmed.
