# Audit Section 16 — Plugin Files: settings, DB, tasks, lib, governance pages

**Scope:** `version.php`, `lib.php`, `settings.php`, `skill_governance.php`, `skill_selection_debug.php`, `trial_challenge.php`, `benchmark_report.php`, `db/access.php`, `db/install.xml`, `db/caches.php`, `db/hooks.php`, `db/tasks.php`, `db/upgrade.php`, `classes/task/*` (4), `classes/event/trial_consent_given.php`, `classes/local/hooks/page_injection.php`, `classes/admin/setting_docs_corpora.php`  ·  **Files audited:** 19  ·  **Methods audited:** ~30
**Arch chapter(s):** `docs/developer-guides/data-model-and-db.md` + `docs/operations/governance.md`  ·  **Flowchart nodes:** `LG_DB`, `LG_MEM`, `UM_TABLE` (install-only DB rollout contract)
**Auditor verdict:** ⚠️ issues (one HIGH upgrade-path defect; no exploitable BLOCKER)

> Note: the scope brief pointed at `docs/architecture/developer-guides/…` and `operations/governance.md`; the real paths are `docs/developer-guides/data-model-and-db.md` and `docs/operations/governance.md` — audited there.

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues | `benchmark_report.php` mutation (pin baseline) gated by a *read* cap; raw `htmlspecialchars` instead of `s()`. Public `trial_challenge.php` reviewed — safe. No IDOR/SQLi found; placeholders parameterised throughout. |
| D2 Moodle API      | issues | `skill_governance.php` carries hard-coded English user strings (notification + button labels + table headers) — string-API violation. Otherwise correct task/cache/event/hook/forms/admin APIs. |
| D3 Structure       | issues | Two unused cache defs (`aiwaitstate`, `aiwaitmailbox`); one defined-but-unchecked capability (`managebenchmarks`); misleading `_adhoc` class name for a scheduled task; `obsolet/` cruft tree present. |
| D4 Duplication     | pass | Per-skill toggle write loop repeated 3× in `skill_governance.php` but small and intentional; admin-externalpage-setup-with-fallback block duplicated across two pages (acceptable). |
| D5 Flowchart       | issues | `LG_DB`/`LG_MEM`/`UM_TABLE` nodes use the stale `local_wizard_*` prefix and assert an "install.xml + matching upgrade.php" contract that the data-model doc contradicts; corroborates the upgrade prefix defect. |
| D6 Docs coverage   | issues | `data-model-and-db.md` documents the wrong table prefix (`local_wizard_` vs actual `bx_agent_`), wrong cache TTLs/holders for dead caches, and an "install-only / no upgrade.php" claim that the real `upgrade.php` (which DOES create tables) contradicts. |

## B. Findings

### [16-F01] 🟠 HIGH · D2 Moodle API · db/upgrade.php:34,87,118,145,159,181,202 (+ flowchart LG_DB)
**What:** Every table reference in `upgrade.php` still uses the legacy `local_wizard_*` prefix, while `db/install.xml` and all runtime code use `bx_agent_*`, and there is **no `rename_table` upgrade step** bridging the two.
**Evidence:** `upgrade.php` line 34 `new xmldb_table('local_wizard_ai_messages')`; lines 87/118/145/159 create `local_wizard_benchmark_*`; line 181/202 `local_wizard_user_memory`. `install.xml` defines `bx_agent_ai_messages`, `bx_agent_benchmark_*`, `bx_agent_user_memory`. Runtime confirmed on `bx_agent_*` only: `grep` of `classes/` finds `bx_agent_*` in `conversation_store.php`, `benchmark_db_writer.php`, `user_memory_service.php`, `queue_manager.php`, `privacy/provider.php`; zero `classes/` references to `local_wizard_*` tables. `grep -n "rename_table" db/upgrade.php` → none.
**Impact:** Fresh installs are fine (install.xml creates `bx_agent_*`; the `< 2026062…` upgrade branches never run, so the dead `local_wizard_*` create/alter code is never reached). But a site that installed a **pre-rename** version (the engine had live `m_local_wizard_*` tables — confirmed historically per the load-test notes) upgrades into a broken state: the old `local_wizard_*` tables are never renamed to `bx_agent_*`, so runtime queries hit non-existent `bx_agent_*` tables → fatal. The benchmark/user_memory create-guards in upgrade.php would *also* create empty `local_wizard_benchmark_*`/`local_wizard_user_memory` tables that nothing reads.
**Compensating control:** Fresh installs unaffected; this is a deployment-time defect for upgraded sites only. The plugin is pre-1.0 in practice (dev installs), so blast radius is limited to existing dev/VM environments.
**Recommendation:** Either (a) add an upgrade step that `rename_table`s each `local_wizard_*` → `bx_agent_*` (guarded by `table_exists`) and bump `version.php`, and rewrite the existing create-guards to the `bx_agent_*` names; or (b) if no pre-rename install must be supported, delete the dead `local_wizard_*` upgrade blocks to remove the trap. Reconcile the data-model doc + `LG_DB` node afterwards.

### [16-F02] 🟡 MEDIUM · D1 Security · benchmark_report.php:31,48-53
**What:** The state-changing "pin baseline" action mutates `bx_agent_benchmark_baselines` / sets `is_baseline`, but the page is gated only by `bookingextension/agent:viewbenchmarks` (a *read* capability); the purpose-built write cap `managebenchmarks` is never checked.
**Evidence:** Line 31 `require_capability('bookingextension/agent:viewbenchmarks', …)`; line 48 `if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey()) { … $writer->pin_baseline(...) }`. `db/access.php:226` defines `managebenchmarks` (write) but `grep` finds zero callers anywhere (incl. cli/tests).
**Impact:** Anyone with the read-only benchmark cap can perform the baseline-pin write. Benchmark data is operator-internal (no PII, no user-facing effect beyond regression-flagging), so residual risk is low — but a "write behind a read cap" is a capability-model violation, and `managebenchmarks` is dead.
**Compensating control:** `confirm_sesskey()` present (no CSRF); `viewbenchmarks` is manager-archetype only; benchmark rows are not security-sensitive.
**Recommendation:** Add `require_capability('bookingextension/agent:managebenchmarks', …)` to the `pinbaseline` branch (and grant the manager archetype on that cap, currently `'archetypes' => []`), or fold the action behind a manager-level check. Resolves the dead-cap finding (16-F05) too.

### [16-F03] 🟡 MEDIUM · D2 Moodle API · skill_governance.php:331-333,353,364,370,396-401,550-635
**What:** The governance admin page emits many user-visible strings as hard-coded English literals instead of `get_string()`.
**Evidence:** Line 331 `$message = 'Warning: There are ' . $highcollisioncount . ' high-similarity embedding collision pair(s) detected…'`; line 353 placeholder `'Search skills by name, component or capability...'`; lines 364/370 button values `'Enable All'`/`'Disable All'`; lines 396-401 table headers `'Active'`, `'Skill Name / Component'`, `'Required Capabilities'`, `'Collision Status'`, `'Actions'`; detail labels `'Description'`, `'Example Parameter Input'`, `'Message Triggers'`, `'Contextual Guidance (Prompts)'`, `'No description.'`, etc.
**Impact:** Page is not translatable and violates the string API (and the project's "all user strings via get_string" rule). Admin-only page, so impact is cosmetic/i18n, not security.
**Compensating control:** None needed; many *other* strings on the same page already use `get_string`.
**Recommendation:** Move every literal to `lang/en/bookingextension_agent.php` and call `get_string`. Note the same applies symmetrically in `skill_selection_debug.php`, which already uses `get_string` throughout — use it as the template.

### [16-F04] 🟢 LOW · D2 Moodle API · benchmark_report.php:223-231
**What:** Output escaping uses raw PHP `htmlspecialchars()` rather than Moodle's `s()` / `format_string()`.
**Evidence:** Lines 223-231 wrap `$run->label`, `model_id`, `skill_set`, `environment`, `git_ref` in `htmlspecialchars(...)`.
**Impact:** `htmlspecialchars()` does escape, so there is no XSS hole; but it ignores Moodle's escaping conventions (default flags/encoding) and is flagged by `phpcs --standard=moodle`. Values originate from CLI benchmark runs (operator-controlled) and are manager-only viewable.
**Compensating control:** Output IS escaped; manager-only page.
**Recommendation:** Replace with `s()` for plain strings.

### [16-F05] 🟢 LOW · D3 Structure · db/access.php:226-230
**What:** Capability `bookingextension/agent:managebenchmarks` is defined with empty `archetypes` and is never checked by any code.
**Evidence:** `db/access.php:226` definition; `grep -rn managebenchmarks` over the whole tree (excluding access.php/lang/docs) returns nothing.
**Impact:** Dead capability — assignable in the UI but governs nothing. Confusing for operators.
**Compensating control:** none.
**Recommendation:** Wire it into `benchmark_report.php` (see 16-F02) — that both removes the dead cap and fixes the read-vs-write authz gap. If benchmarks stay view-only at runtime, drop the cap instead.

### [16-F06] 🟢 LOW · D3 Structure · db/caches.php:35-46
**What:** Cache definitions `aiwaitstate` and `aiwaitmailbox` have zero callers anywhere in the plugin.
**Evidence:** `grep -rn "aiwaitstate|aiwaitmailbox|waitstate|waitmailbox" classes amd tests` → no matches; only `db/caches.php` defines them. (Confirmed I grepped the whole `classes/`, `amd/`, `tests/` tree.)
**Impact:** Dead cache definitions; documented in data-model-and-db.md §6 as live ("conversation state during polling" / "long-poll mailbox results"), so the doc misleads.
**Compensating control:** none.
**Recommendation:** Remove both definitions (and their doc rows), or wire up the long-poll path they were intended for.

### [16-F07] 🟢 LOW · D6 Docs coverage · docs/developer-guides/data-model-and-db.md §1,§2,§3,§5,§6
**What:** The data-model chapter is materially out of date against the code.
**Evidence:** §2 claims tables use the `local_wizard_` prefix and "`m_local_wizard_ai_llm_debug`"; install.xml + runtime use `bx_agent_` (`m_bx_agent_ai_llm_debug`). §1 asserts "install-only … there are no `upgrade.php` migrations for the agent's own tables", but `upgrade.php` contains guarded `create_table` calls for the benchmark and user_memory tables (and the `LG_DB` flowchart node explicitly says "install.xml + matching upgrade.php step"). §3/§5 tables are listed under `local_wizard_*`. §6 documents the two dead caches (16-F06) as live.
**Impact:** A contributor following the doc would look for the wrong physical tables and misunderstand the rollout policy (a real source of the 16-F01 split).
**Compensating control:** none.
**Recommendation:** Rewrite §1-§6 to `bx_agent_*`, state the actual rollout (install.xml for fresh + guarded create/rename steps in upgrade.php), and drop the dead-cache rows.

### [16-F08] 🟢 LOW · D5 Flowchart · AGENT_IMPLEMENTATION_FLOWCHART.mmd:142,608 (`UM_TABLE`,`LG_MEM`)
**What:** Flowchart nodes name `local_wizard_user_memory`; the live table is `bx_agent_user_memory`.
**Evidence:** Line 142 `UM_TABLE["local_wizard_user_memory…"]`; line 608 `LG_MEM[… (local_wizard_user_memory, no contextid) …]`. install.xml table is `bx_agent_user_memory`.
**Impact:** Doc-lag only (not behavioural). Per `feedback_flowchart_policy` this is reported, not silently reconciled.
**Compensating control:** none.
**Recommendation:** Flag to maintainer for a prefix sweep of the `.mmd` together with 16-F01/16-F07.

### [16-F09] 🟢 LOW · D3 Structure · classes/task/cleanup_attachment_temp_files_adhoc.php:31 + db/tasks.php:29
**What:** The class is named `*_adhoc` but extends `\core\task\scheduled_task` and is registered as a scheduled task (`*/15`), not an ad-hoc task.
**Evidence:** Class decl `class cleanup_attachment_temp_files_adhoc extends \core\task\scheduled_task`; `db/tasks.php` registers it under `$tasks` with cron fields. Contrast the genuine ad-hoc tasks (`rebuild_*_adhoc extends \core\task\adhoc_task`).
**Impact:** Misleading name only; behaviour is correct (a scheduled safety-net cleanup; the per-token inline invalidation is the primary path).
**Compensating control:** none.
**Recommendation:** Rename to `cleanup_attachment_temp_files_task` for consistency with `cleanup_old_benchmark_runs_task`.

### [16-F10] ⚪ INFO · D3 Structure · obsolet/
**What:** An `obsolet/` directory exists at the plugin root.
**Evidence:** `ls -d obsolet` succeeds.
**Impact:** Per template §5 this is noted as LOW/INFO cruft, not audited. It should not ship in a 1.0 release.
**Recommendation:** Remove before go-live (or confirm it is git-ignored / build-excluded).

### [16-F11] ⚪ INFO · D1 Security · trial_challenge.php (whole file)
**What:** Public, login-less endpoint (`phpcs:disable moodle.Files.RequireLogin.Missing`) reviewed as a back-channel nonce echo.
**Evidence:** Rejects non-GET (405); requires `PARAM_ALPHANUMEXT` token; looks up `trialnonce` cache key `nonce_<token>`; returns 403 unless `$stored === $token`; echoes the token as `text/plain`. No DB access, no PII, no state change; the 600 s TTL cache bounds exposure.
**Impact:** None — the endpoint only confirms that a nonce the server itself minted is currently live (the gateway verification handshake). No user data, no injection surface (token constrained + used only as a cache key + compared, never interpolated into SQL/HTML).
**Recommendation:** None. Confirmed-correct. (Minor: comparison is `!==` on two server-derived tokens — not a secret-vs-attacker compare, so constant-time is unnecessary.)

## C. Per-file / per-method checklist

#### `version.php`
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — `MOODLE_INTERNAL` guard, GPL header, `requires`/`supported`/`dependencies` sane; `MATURITY_STABLE` + `release 1.0.0` (consistent with go-live intent).

#### `lib.php`  (fn `bookingextension_agent_output_fragment_aipanel`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — fragment callback casts `contextid` to int, `context::instance_by_id(…, MUST_EXIST)`, `require_capability('…:useaiinstructions', $context)` before rendering. Framework-invoked (fragment API) — not dead.

#### `settings.php`
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — all settings use `get_string`; admin externalpages gated on real caps (`managegovernance`/`debugskillselection`/`viewbenchmarks`); license desc HTML built from `get_string` only (no user input interpolated); shortcode token auto-seeded with `random_string`; `aidocsroot` uses the validating `setting_docs_corpora`. Coexistence handover branch clean. Inline `color:green/red` style strings are static.

#### `db/access.php`  (closure `$buildskillcapability`)
- [x] D1 [x] D2 [ ] D3 (see 16-F05) [x] D4 n/a D5 [x] D6 — risk/archetype/contextlevel correct; skill caps generated `…:skill_<name>` with `RISK_DATALOSS|RISK_XSS`; every skill suffix has a matching lang string (74 suffixes ⊆ 78 lang keys; verified by diff) and completeness is enforced by `tests/skill_name_capability_test.php`. `managegovernance` correctly carries `RISK_CONFIG`.

#### `db/install.xml`
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — 8 `bx_agent_*` tables; PKs, FKs to threads/user, unique `idempotencyidx` on runs, sensible composite indexes; field types/lengths match runtime usage. The doc prefix mismatch is tracked under 16-F07, not here.

#### `db/caches.php`
- [x] D1 [x] D2 [ ] D3 (see 16-F06) [x] D4 n/a D5 [ ] D6 (see 16-F07) — `aiprivacynames`/`trialnonce`/`attachment_tokens` used; `aiwaitstate`/`aiwaitmailbox` dead.

#### `db/hooks.php`
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — single callback `page_injection::extend_head` on `before_standard_head_html_generation`; target method exists.

#### `db/tasks.php`
- [x] D1 [x] D2 [ ] D3 (see 16-F09 naming) [x] D4 n/a D5 [x] D6 — both scheduled classes exist; cleanup `*/15`, benchmark purge `03:30` daily; idempotent.

#### `db/upgrade.php`  (fns `…_ensure_ai_messages_userid`, `xmldb_bookingextension_agent_upgrade`)
- [ ] D1 [ ] D2 (see 16-F01) [x] D3 [x] D4 n/a D5 [ ] D6 (see 16-F07) — savepoint sequence monotonic and well-formed; create_table/add_field/unset_config steps guarded; **but** wrong table prefix throughout (16-F01). Last savepoint `2026062406` < `version.php 2026062900` (the trailing gap is WS-only, no schema step needed — acceptable).

#### `classes/task/cleanup_attachment_temp_files_adhoc.php`  (`get_name`, `execute`)
- [x] D1 [x] D2 [ ] D3 (16-F09) [x] D4 n/a D5 [x] D6 — `get_name` via `get_string`; `execute` delegates to `attachment_token_service::cleanup_expired()`, `mtrace`.

#### `classes/task/cleanup_old_benchmark_runs_task.php`  (`get_name`, `execute`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — retention from config (default 365), baselines kept; idempotent purge.

#### `classes/task/rebuild_docs_embeddings_adhoc.php`  (`execute`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — E2 gate (skill active) + provider class_exists guard before any embedding call; full-rebuild verifies readiness and throws to trigger faildelay backoff. Framework-invoked.

#### `classes/task/rebuild_skill_catalog_embeddings_adhoc.php`  (`execute`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — provider guard, registry rebuild, per-skill state summary, post-rebuild readiness throw. Framework-invoked.

#### `classes/event/trial_consent_given.php`  (`init`, `get_name`, `get_description`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — `crud='c'`, `LEVEL_OTHER`; `get_name` via `get_string`; description interpolates only `userid`/`contextid` ints into a server-rendered audit log string (event API renders it, not HTML). Framework-invoked.

#### `classes/local/hooks/page_injection.php`  (`extend_head`, `current_page_context`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — engine-active + `inject_in_navbar` config + logged-in/non-guest + pagelayout exclusion + `require capability('…:seemagicwand', $PAGE->context)` checks; reads only free `$PAGE` scalars (`format_string` on names); wraps everything in try/catch with `debugging` so it never breaks page render. Correct that visibility-gate here is distinct from the per-call `useaiinstructions` server gate.

#### `classes/admin/setting_docs_corpora.php`  (`validate`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — defers to parent then `corpus_source_parser::parse`; blocks save on path-escape/parse warnings (E2 confinement); warnings escaped via `s()`.

#### `skill_governance.php`  (top-level script + closure `$describedeny`)
- [x] D1 [ ] D2 (see 16-F03) [x] D3 [x] D4 n/a D5 [x] D6 — `managegovernance` cap enforced; POST guarded by `data_submitted() && confirm_sesskey()`; per-skill toggles whitelisted against known `$contracts` before `set_config` (PARAM_RAW values used only as lookup keys — no injection); `evaluserid`/`evalcontextid` are `PARAM_INT` and only feed read-only governance evaluation + escaped labels; all dynamic output escaped via `s()`. D2 only: hard-coded English strings.

#### `skill_selection_debug.php`  (top-level script)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — `debugskillselection` cap enforced; `confirm_sesskey()` on actions; inputs `PARAM_RAW_TRIMMED`/`PARAM_INT`/`PARAM_BOOL`; all output via `get_string`/`s()`/`format_float`. Clean reference for 16-F03.

#### `benchmark_report.php`  (top-level script)
- [ ] D1 (see 16-F02) [ ] D2 (see 16-F04) [x] D3 [x] D4 n/a D5 [x] D6 — `require_login` + `viewbenchmarks` cap; `confirm_sesskey()` on pin action; all SQL parameterised (`{…}` table syntax, `[]` params, literal IN-list); chart/table labels via `get_string`. D1: write behind read cap; D2: raw `htmlspecialchars`.

#### `trial_challenge.php`  (public endpoint)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — see 16-F11 (confirmed safe).

## D. Go-live blockers from this section
- **None are hard BLOCKERs.** The one launch-gating item is **16-F01 (HIGH)**: `db/upgrade.php` uses the legacy `local_wizard_*` prefix with no `rename_table` step, so any site upgraded from a pre-rename build lands on missing `bx_agent_*` tables (fatal at runtime). Fresh installs are unaffected. Fix the upgrade path (rename step or remove the dead blocks) before promoting to any environment that has existing agent tables, and reconcile the data-model doc + `LG_DB`/`LG_MEM`/`UM_TABLE` flowchart nodes.
