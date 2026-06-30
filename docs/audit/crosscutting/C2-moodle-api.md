# Cross-cutting Audit C2 — Moodle API Compliance (horizontal)

**Scope:** whole plugin tree `mod/booking/bookingextension/agent` — `db/*`, `classes/external/*`,
`classes/task/*`, `classes/**`, root entry pages (`*.php`), `cli/*`, `lang/`, `settings.php`,
`version.php`, `thirdpartylibs.xml`.
**Excluded:** `thirdparty/` (vendored, declared), `obsolet/` (cruft, noted), `.claude/worktrees/*`, `tests/`.
**Files audited:** 318 PHP in scope (291 under `classes/`) + 8 `db/*.php` + `version.php` + `thirdpartylibs.xml`.
**Methods audited (estimate):** ~150 (D2 is structural — focus on entry points, db/* contracts, external API shape).
**Arch chapter(s):** `docs/developer-guides/data-model-and-db.md`, `docs/architecture/README.md`.
**Auditor verdict:** ⚠️ issues — no BLOCKER. Both HIGHs **resolved 2026-06-30** (C2-F01 upgrade/install table-prefix divergence → `upgrade.php` emptied of `local_wizard_*` migrations under the confirmed greenfield/install-only invariant; C2-F02 doc-vs-code contradiction → `data-model-and-db.md` rewritten to `bx_agent_`). Remaining items are MEDIUM/LOW (C2-F03 also FIXED 2026-06-30).

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | n/a    | Owned by C1; entry-guard spot-checks here were clean (sesskey/cap/validate_context present on every WS + entry page). |
| D2 Moodle API      | issues | External API shape, `db/access.php`, `db/services.php`, `db/tasks.php`, capability-lang, PSR-4, CLI guards all clean. Defects: (1) ~~upgrade.php migrates `local_wizard_*` tables~~ **FIXED 2026-06-30** (upgrade.php emptied of schema migrations; install-only); (2) two defined caches never `cache::make`-d; (3) ~~`managebenchmarks` cap defined-but-unused~~ **FIXED 2026-06-30**; (4) two `.py` files inside autoloaded `classes/`; (5) 69/291 class files lack `declare(strict_types=1)`. |
| D3 Structure       | n/a    | Owned by C3; only the `.py`-in-`classes/` and `_adhoc`-named scheduled task noted as incidental. |
| D4 Duplication     | n/a    | Owned by C4; benchmark-page cap-gate inconsistency noted under D2. |
| D5 Flowchart       | n/a    | Not a D2 concern. |
| D6 Docs coverage   | clean (was issues) | **FIXED 2026-06-30**: `data-model-and-db.md §1/§2` rewritten — install-only invariant stated honestly (upgrade.php now genuinely carries no schema migrations) and all table names re-prefixed `local_wizard_` → `bx_agent_` to match install.xml + runtime. |

---

## B. Findings

### [C2-F01] ✅ RESOLVED (was 🟠 HIGH) · D2 Moodle API · db/upgrade.php vs db/install.xml
**✅ Resolved 2026-06-30 (option b):** `db/upgrade.php` no longer references `local_wizard_*` at
all (`grep local_wizard_ db/upgrade.php` → 0). The `ensure_ai_messages_userid` helper and every
`create_table`/`add_field`/`unset_config` migration body were deleted — they were unreachable by
any working install and were the rename trap. Greenfield invariant confirmed by George ("we are
not live, no migration needed"): the full schema ships via `db/install.xml` under `bx_agent_`,
verified complete (all 9 tables incl. `ai_messages.userid` + `useridthreadidx`, and
`user_memory.scopes`). `db/upgrade.php` is now intentionally **empty** (`return true;`) — no
migrations of any kind (George: "upgrade wirklich leer gemacht, wir brauchen das nicht"). The
install-only invariant is documented in `data-model-and-db.md §1`. phpcs `--standard=moodle`
clean. — _Below: original finding, kept for the record._

**What:** `db/install.xml` ships every table with the `bx_agent_` prefix (e.g. `bx_agent_ai_messages`,
`bx_agent_benchmark_runs`) and **all** runtime code queries `{bx_agent_*}`, but every migration in
`db/upgrade.php` operates on the OLD `local_wizard_*` table names, and there is **no `rename_table`**
migration anywhere bridging the two.
**Evidence:** `db/upgrade.php:34` `new xmldb_table('local_wizard_ai_messages')`; `:87`
`new xmldb_table('local_wizard_benchmark_runs')` (then `create_table`); `:181`
`local_wizard_user_memory`; `:202` adds `scopes` to `local_wizard_user_memory`. Contrast
`db/install.xml:7` `TABLE NAME="bx_agent_ai_threads"` … and runtime: `conversation_store.php`,
`benchmark/benchmark_db_writer.php`, `queue/queue_manager.php`, `privacy/provider.php`,
`user_memory_service.php` all reference `{bx_agent_*}` (grep: 0 runtime refs to `local_wizard_*`
tables — the only `local_wizard_*` hit in `classes/` is the function name
`local_wizard_is_active`, not a table). `grep -n rename_table db/upgrade.php` → no matches.
**Impact:** A fresh install is fine (install.xml creates `bx_agent_*`; upgrade.php never runs because
`oldversion == version`). But any site that ever installed the pre-rename `local_wizard_*` schema
would: (a) on upgrade have its benchmark/user_memory tables (re-)created/altered under the *old*
name while runtime reads/writes the *new* name → silent data loss / "table does not exist"; (b) keep
its conversation tables orphaned under the old name. The plugin would mis-execute against the wrong
physical tables.
**Compensating control:** The "install-only rollout" design (memory: *Phase 1 inert bis local_wizard
existiert*; `MATURITY_STABLE` but `release 1.0.0`, never shipped to production). No production install
of the old prefix is known to exist, so in practice only fresh `bx_agent_*` installs happen and the
broken upgrade branch is unreachable. This is why it is HIGH, not BLOCKER.
**Recommendation:** Either (a) add a guarded `rename_table('local_wizard_*','bx_agent_*')` step in
`upgrade.php` (idempotent, `table_exists`-guarded) and re-point the existing `local_wizard_*`
migrations to the new names, or (b) if it is contractually true that no old-prefix install exists,
delete the dead `local_wizard_*` migration bodies and state the install-only invariant in a header
comment so a future maintainer does not re-create the trap.

### [C2-F02] ✅ RESOLVED (was 🟠 HIGH) · D6 Docs coverage · docs/developer-guides/data-model-and-db.md §1–§2
**✅ Resolved 2026-06-30:** `data-model-and-db.md` rewritten. §1 now states the install-only
invariant truthfully (schema ships via `install.xml`; `upgrade.php` is empty, `return true;`)
plus a "History" note flagging the abandoned `local_wizard_` prefix as stale. §2 corrected to `bx_agent_` (physical
`m_bx_agent_ai_llm_debug`); §3/§5/§8 table names globally re-prefixed `local_wizard_` →
`bx_agent_` (`grep local_wizard data-model-and-db.md` → 0). The doc now matches code and the
F01 fix. — _Below: original finding, kept for the record._

**What:** The data-model chapter is materially wrong against current code on two load-bearing claims.
**Evidence:** `§1` (line 8): *"New schema for the agent ships via db/install.xml only — there are no
upgrade.php migrations for the agent's own tables."* — false: `db/upgrade.php` `create_table`s four
benchmark tables (`:87,:118,:145,:159`), creates `*_user_memory` (`:181`), and `add_field`s on
`*_ai_messages` (`:35`) and `*_user_memory` (`:203`). `§2` (line 15): *"All agent tables use the
local_wizard_ prefix … not bookingextension_agent_"* — false: `db/install.xml` and 100% of runtime
queries use `bx_agent_`. The whole §3/§4 table list (`local_wizard_ai_threads`, …) names tables that
do not exist on a fresh install.
**Impact:** A developer trusting the doc would look for `m_local_wizard_*` tables that aren't there,
and would believe upgrade.php carries no schema (it does). Directly couples to C2-F01: the doc
documents the *abandoned* prefix as canonical.
**Compensating control:** None.
**Recommendation:** Rewrite §1 to describe the real upgrade.php migrations (or the intended
install-only invariant honestly), and globally replace `local_wizard_` with `bx_agent_` in §2–§4.

### [C2-F03] 🟡 MEDIUM · D2 Moodle API · db/access.php + benchmark_report.php
**What:** Capability `bookingextension/agent:managebenchmarks` is defined but checked nowhere, and the
one benchmark *write* action is gated only by the *read* capability `viewbenchmarks`.
**Evidence:** `db/access.php:226` defines `managebenchmarks` (`captype write`, empty `archetypes`).
Grep of `classes/` + `*.php` (excluding `db/access.php`): the only `managebenchmarks` reference is its
own definition — never `require_capability`'d. Meanwhile `benchmark_report.php:31`
`require_capability('bookingextension/agent:viewbenchmarks', …)` and `:48`
`if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey())` performs a baseline-pin *write*
under that read cap. Sibling pages `benchmark_compare.php:28` / `benchmark_run_detail.php:28` gate on
`moodle/site:config` — a third, inconsistent gate for the same feature family.
**Impact:** A manager granted only `viewbenchmarks` (a "read" capability) can mutate baseline state.
Low blast radius (admin/manager-only diagnostic tool, sesskey present), but the capability model is
incoherent: the write cap that exists is dead, and read/write/site-config gates are mixed across three
pages of one feature.
**Compensating control:** `confirm_sesskey()` on the write action; the feature is manager/admin-only
either way; `viewbenchmarks` defaults to manager-only.
**Recommendation:** Gate `pinbaseline`/baseline mutations on `managebenchmarks` (and grant it to
`manager` in archetypes, or keep admin-only deliberately), or delete `managebenchmarks` if the
intent is "view = pin". Unify the three benchmark pages on one cap pair.
**Status:** ✅ FIXED 2026-06-30 — `pinbaseline` now calls `require_capability('bookingextension/agent:managebenchmarks', …)`
before writing (benchmark_report.php); `managebenchmarks` is enforced for the first time, kept
admin-only by default (empty archetypes) deliberately. Residual (optional, not a hole): the sibling
read-only pages benchmark_compare.php / benchmark_run_detail.php still gate on `moodle/site:config`
(stricter, not write-under-read) — unifying those gates is a separate cleanup.

### [C2-F04] ✅ RESOLVED (was 🟡 MEDIUM) · D2 Moodle API · db/caches.php
**✅ Resolved 2026-06-30:** the two dead cache areas `aiwaitstate` and `aiwaitmailbox` were removed from `db/caches.php` (grep confirmed 0 `cache::make` references tree-wide); the data-model §6 cache table dropped their rows too. The remaining three (`aiprivacynames`, `trialnonce`, `attachment_tokens`) are all used. phpcs clean. — _Original finding below._

**What:** Two cache areas are defined but never instantiated via `cache::make`.
**Evidence:** `db/caches.php` defines `aiwaitstate` (`:35`) and `aiwaitmailbox` (`:41`). Grep across
`classes/`, `cli/`, `amd/`, `*.php`: zero hits for either name. The other three (`aiprivacynames`,
`trialnonce`, `attachment_tokens`) are each used (`privacy_anonymizer.php:1019`,
`trial_provisioner.php:77` + `trial_challenge.php:39`, `attachment_token_service.php` via
`CACHE_AREA` const). Conversely every `cache::make` name in code is defined — no missing definition.
**Impact:** Dead cache definitions; `purge_caches`/install processes them needlessly. No correctness
risk. Likely leftover from the removed adhoc/wait-state execution path (cf. upgrade.php `:222`
removing `aiexecutionmode`).
**Compensating control:** Harmless at runtime.
**Recommendation:** Remove `aiwaitstate` and `aiwaitmailbox` from `db/caches.php`.

### [C2-F05] 🟢 LOW · D2/D3 · classes/local/wizard/wunderbyte_trial_endpoint.py + wunderbyte_shop_endpoint.py
**What:** Two Python files live inside the PSR-4 autoloaded `classes/` tree.
**Evidence:** `classes/local/wizard/wunderbyte_trial_endpoint.py`,
`classes/local/wizard/wunderbyte_shop_endpoint.py` — server-side reference implementations of the
Wunderbyte trial/shop key endpoints (docstring "Wunderbyte Moodle Trial/Shop Key Endpoint"). No PHP
references them (grep clean). They carry no Moodle GPL header (flagged by the GPL/`@package` sweep,
but as non-PHP that is expected).
**Impact:** Cruft in an autoloaded namespace directory; pollutes the component, confuses tooling,
and ships unrelated server code with the plugin. No load/security impact (PHP autoloader ignores
`.py`). Previously noted in project memory ("Python-Cruft in classes/").
**Compensating control:** Inert to PHP.
**Recommendation:** Move both files out of `classes/` into a non-shipped docs/ops location (or delete
from the plugin tree).

### [C2-F06] 🟢 LOW · D2 Moodle API · classes/ (69 files)
**What:** 69 of 291 class files lack `declare(strict_types=1)` while the other 222 have it.
**Evidence:** Sweep listed e.g. `orchestrator.php`, `executor.php`, `conversation_store.php`,
`skill_registry.php`, `authorization_service.php`, all `interfaces/*`, several `*/skills/*`. The
preflight section already flagged the same for `noop_external_dependency_checker.php` (08-F04).
**Impact:** Mixed strict/loose typing is a known latent-bug source in this engine — project memory
*strict_types-Coercion bei Extraktion* records a real coercion bug caused exactly by a non-strict
orchestrator passing a string into an int core API. Not a Moodle-API rule violation (core itself is
loose), so LOW, but a consistency/robustness risk concentrated in core engine files.
**Compensating control:** Most call sites cast explicitly; the one historical bug was caught and fixed.
**Recommendation:** Add `declare(strict_types=1)` uniformly (or document a deliberate policy). At
minimum the always-on engine core (`orchestrator`, `executor`, `conversation_store`,
`authorization_service`) should be strict.

### [C2-F07] 🟢 LOW · D2/D3 · classes/task/cleanup_attachment_temp_files_adhoc.php
**What:** Class named `*_adhoc` is actually a `scheduled_task`, registered in `db/tasks.php`.
**Evidence:** `cleanup_attachment_temp_files_adhoc.php:31` `extends \core\task\scheduled_task`;
`db/tasks.php:29` schedules it `*/15`. The genuinely-adhoc tasks (`rebuild_docs_embeddings_adhoc`,
`rebuild_skill_catalog_embeddings_adhoc`) correctly `extends \core\task\adhoc_task` and are *not* in
`db/tasks.php` (queued programmatically). So the API usage is correct; only the name lies.
**Impact:** Misleading name; no functional defect.
**Compensating control:** Behaviour is correct (it really is scheduled).
**Recommendation:** Rename to `cleanup_attachment_temp_files_task` (cosmetic; defer to a rename window).

### [C2-F08] ⚪ INFO · D2 Moodle API · plugin-wide compliance confirmations
**What:** Positive confirmations from the horizontal sweep (no defect).
**Evidence:**
- **External API:** all 13 functions in `db/services.php` resolve to existing
  `\bookingextension_agent\external\*` classes, each implementing
  `execute_parameters`/`execute`/`execute_returns` with `external_value(PARAM_*)`. `ws_message_formatter`
  is correctly a helper (not registered). Every WS does `require_sesskey()` + `validate_parameters()`
  + `context::instance_by_id(MUST_EXIST)` + `validate_context()` (verified on `ai_send_message`,
  `set_debug_mode`, `store_provider_apikey`, `ai_upload_attachment`). `ajax`/`type` flags coherent;
  secret-bearing `apikey` is `PARAM_RAW` and never logged; uploads use `PARAM_FILE`/`clean_param`.
- **Capability API:** all 8 fixed caps + all 74 generated `skill_*` caps have a `captype`,
  `contextlevel`, `archetypes` and a lang string (verified: 0 missing, 0 duplicate suffixes). The
  name-derived `:skill_<name>` contract is enforced in code (`skill_contract_validator.php:125`) and
  by an existing CI test.
- **Task API:** both scheduled tasks extend `scheduled_task` with `get_name()`+`execute()`; both
  adhoc tasks extend `adhoc_task`. Schedules valid.
- **Hooks/Shortcodes:** `db/hooks.php` callback `page_injection::extend_head` exists;
  `db/shortcodes.php` callback `bookingextension_agent\shortcodes::wbbagent` exists.
- **PSR-4:** every `classes/**/*.php` namespace matches its directory (0 mismatches).
- **Headers:** every PHP file has the GPL header + `@package bookingextension_agent` + (procedural)
  `defined('MOODLE_INTERNAL')`. The only GPL/`@package` "misses" are non-PHP (`.py`, `.md`, `.json`,
  `install.xml`) — expected.
- **CLI:** all 9 `cli/*.php` define `CLI_SCRIPT` and require `config.php` + `clilib.php`.
- **Entry pages:** `skill_governance.php`, `skill_selection_debug.php`, `benchmark_*` all do
  `require_login` + `require_capability`; `lib.php` fragment does `require_capability`;
  `trial_challenge.php` is a deliberate public nonce back-channel (cache-nonce gated, GET-only,
  `phpcs:disable RequireLogin` documented).
- **Strings:** no hard-coded user-facing literals bypassing `get_string`; no literal-message
  `moodle_exception`s; 64 files use `get_string`.
- **thirdpartylibs.xml** declares the only vendored lib (`smalot/pdfparser`).
**Impact:** None — baseline is solid.
**Recommendation:** None.

---

## C. Per-file / per-area checklist (D2-relevant)

#### `db/access.php`
- [x] D2 — see C2-F03 (`managebenchmarks` defined-unused) — FIXED 2026-06-30: now enforced on the `pinbaseline` write. Otherwise: captype/contextlevel/archetypes + lang ✓ for all 8 fixed + 74 generated caps.

#### `db/services.php`
- [x] D2 — all 13 classes exist; ajax/type/capabilities coherent; one service definition valid.

#### `db/caches.php`
- [x] D2 — C2-F04 FIXED 2026-06-30: dead `aiwaitstate`/`aiwaitmailbox` removed; the 3 remaining are all used.

#### `db/tasks.php`
- [x] D2 — both classes exist, extend `scheduled_task`, schedules valid (naming nit → C2-F07).

#### `db/hooks.php` / `db/shortcodes.php`
- [x] D2 — callback classes/methods exist.

#### `db/install.xml` / `db/upgrade.php`
- [x] D2 — C2-F01 FIXED 2026-06-30: `upgrade.php` is now empty (`return true;`) — all `local_wizard_*` schema migrations removed (greenfield, install-only); invariant documented in `data-model-and-db.md §1`. `install.xml` verified complete (9 `bx_agent_*` tables). 0 `local_wizard_` refs; phpcs clean.

#### `classes/external/*.php` (13 WS + `ws_message_formatter`)
- [x] D2 — `execute_parameters`/`execute`/`execute_returns` + PARAM_* + sesskey + validate_context on all. (Detail in C2-F08.)

#### `classes/task/*.php` (4)
- [x] D2 — correct bases; naming nit C2-F07.

#### `classes/**` (PSR-4, headers, strict_types)
- [x] D2 PSR-4 — 0 mismatches across 291 files.
- [x] D2 headers — GPL + @package on all PHP.
- [ ] D2 strict_types — see C2-F06 (69/291 missing).
- [ ] D2 file-type hygiene — see C2-F05 (2 `.py` in `classes/`).

#### root `*.php` (`lib.php`, `settings.php`, `version.php`, `trial_challenge.php`, `benchmark_*`, `skill_*`)
- [x] D2 — entry guards / CLI / fragment cap all correct (C2-F08); benchmark cap-gate inconsistency → C2-F03.

#### `cli/*.php` (9)
- [x] D2 — CLI_SCRIPT + config + clilib on all.

#### `lang/en/bookingextension_agent.php`
- [x] D2 — every checked capability has a string; no missing skill-cap strings.

#### `thirdpartylibs.xml` / `thirdparty/`
- [x] D2 — vendored pdfparser declared; nothing else vendored.

#### `obsolet/`
- INFO — exists (`ROADMAP.md`, `todo`); not audited (template §5).

---

## D. Top blockers (gate launch)

**No BLOCKER.** Items that should gate or immediately-follow launch:

- **[C2-F01] ✅ RESOLVED 2026-06-30** — greenfield invariant confirmed (not live); `upgrade.php`
  emptied of all `local_wizard_*` schema migrations and the install-only invariant documented in
  its header. No more old-prefix references; full schema ships via `install.xml` under `bx_agent_`.
- **[C2-F02] ✅ RESOLVED 2026-06-30** — `data-model-and-db.md` rewritten to the real `bx_agent_`
  prefix and the honest install-only-no-schema-migrations invariant; can no longer mislead.

Non-gating but fix-soon: C2-F03 (read cap guarding a write + dead `managebenchmarks`), C2-F04 (dead
caches), C2-F05 (`.py` in `classes/`), C2-F06 (strict_types in engine core), C2-F07 (task naming).
