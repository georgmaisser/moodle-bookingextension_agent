# Audit Section 02 — Authorization, Context & Privacy

**Scope:** `classes/local/wizard/services/security/*` (authorization_service, context_resolver, module_target_resolver, native_capability_guard, operating_context_target_registry, skill_operating_context_resolver, context_target_unresolved_exception), `classes/local/wizard/services/agent_access_service.php`, `classes/local/wizard/aiready.php`, `classes/local/wb_license.php`, `classes/local/wizard/privacy_anonymizer.php`, `classes/privacy/provider.php`, `db/access.php`
**Files audited:** 12  ·  **Methods audited:** 78
**Arch chapter(s):** docs/architecture/02-authorization-and-context.md  ·  **Flowchart nodes:** AUTHZ (AZ1–AZ4), LG_AVAIL, LG_CTX, LG_OPCTX
**Auditor verdict:** ⚠️ issues (no blocker)

The security core is sound. The two-gate model (Gate 1 use/skill capabilities at ambient context, Gate 2 native capabilities re-checked at the operating context) is implemented correctly and enforced **twice** (preflight + executor backstop) with fail-closed behaviour. The privacy provider covers all five PII tables for metadata/export/delete. The anonymizer's display gate is genuinely fail-closed. All `$DB` access in scope is parameterised; the single dynamic table-name interpolation (`module_target_resolver`) is guarded by a strict regex + `table_exists()`. Findings are concentrated in **documentation coverage** (a documented method that does not exist; an entire subsystem — operating-context resolution — absent from the arch chapter) plus one dead method, one dead capability, and a few INFO hardening notes.

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | Two-gate model correct + double-enforced; fail-closed native guard, fail-closed anonymizer display gate, complete privacy provider; all SQL parameterised; guarded table-name interpolation. One dead capability (02-F03) is governance clarity, not exploit. |
| D2 Moodle API      | pass | Correct use of `context::instance_by_id`, `has_capability`, `require_capability`, privacy subsystem, caching API, `get_string`, `core_course_category::search_courses`, `get_fast_modinfo`/`uservisible`. `db/access.php` defines every fixed + name-derived skill capability (proven by `skill_name_capability_test`). |
| D3 Structure       | issues | `require_capability_at()` has zero productive callers (02-F02). Otherwise clean layering, no engine→domain leak (module targeting keyed by core `modname`, booking discovered duck-typed). |
| D4 Duplication     | pass | Shared subpatterns (EMAIL_SUBPATTERN, ANON_TOKEN_*), shared `get_or_create_token`, single `resolve_valid_context`. Endpoint→Wunderbyte detection centralised in `agent_access_service`. No material duplication. |
| D5 Flowchart       | pass | Behaviour matches AZ1–AZ4, LG_AVAIL, LG_CTX, LG_OPCTX. One doc-lag note (AZ3 names `is_agent_extension_installed`; entry gate actually uses the `is_agent_engine_active` wrapper) — 02-F07. |
| D6 Docs coverage   | issues | Chapter documents `require_valid_context_for_levels()` which does not exist (02-F01); the whole operating-context subsystem (LG_OPCTX) is undocumented in any arch chapter (02-F05); license product token drift (02-F06). |

## B. Findings

### [02-F01] 🟡 MEDIUM · D6 Docs coverage · docs/architecture/02-authorization-and-context.md:28,49
**What:** The chapter documents a method `require_valid_context_for_levels(int $contextid, array $allowedlevels): agent_context` that does not exist anywhere in the code or in the `agent_authorization_service` interface.
**Evidence:** Ch.02 §1 table row 4 and §2 ("built once at the entry point (e.g. via `require_valid_context_for_levels()`)") both name it. Grep across `classes/` for `require_valid_context_for_levels` returns **no hits**. The interface (`interfaces/agent_authorization_service.php`) declares only `require_use_capability`, `can_use`, `check_use_readiness`, `require_valid_context`, `require_capability_at`. The real validation helper is the **private** `authorization_service::resolve_valid_context()` (line 110), which carries a hardcoded level allow-list, not a caller-supplied one.
**Impact:** A maintainer following the chapter would call a non-existent API; the documented "explicit allow-list of context levels" capability does not exist, so a reader believes the engine supports per-entry-point level restriction that it does not.
**Compensating control:** None (documentation only; no runtime effect).
**Recommendation:** Remove the `require_valid_context_for_levels` row and the §2 reference, or implement it. Replace the §2 "built once at the entry point" example with `agent_context::from_contextid()` / `require_valid_context()`.

### [02-F02] 🟢 LOW · D3 Structure · classes/local/wizard/services/security/authorization_service.php:203
**What:** `require_capability_at()` is defined on the service and the interface but has **zero productive callers**; the Gate-2 re-check at the operating context is actually performed by `native_capability_guard::missing_capabilities()`.
**Evidence:** Grep for `require_capability_at` across `classes/` returns only the interface declaration (`interfaces/agent_authorization_service.php:95`) and the implementation (`authorization_service.php:203`). The real operating-context capability enforcement is `native_capability_guard::missing_capabilities()` called in `preflight_pipeline.php` and `executor.php:266`. The chapter §1 row even claims `require_capability_at` is "used by the runtime context switch (`context_resolver`)" — `context_resolver` never calls it.
**Impact:** Dead method plus a false architectural claim; a reader may believe Gate 2 flows through `authorization_service` when it flows through `native_capability_guard`. No security impact (the enforcement that matters is present and double-checked).
**Compensating control:** Gate 2 is enforced (correctly) by `native_capability_guard` in both preflight and the executor backstop.
**Recommendation:** Remove `require_capability_at` from the service + interface (and the chapter row), or wire it as the single Gate-2 entry and have `native_capability_guard` delegate to it. Note: it is an interface method, so removal must touch the interface too.

### [02-F03] 🟡 MEDIUM · D1 Security · db/access.php:226
**What:** Capability `bookingextension/agent:managebenchmarks` is defined (write, empty archetypes) but enforced nowhere. (Same defect as cross-cutting C1-F02; reported here because `db/access.php` is in this section's scope.)
**Evidence:** `db/access.php:226-230` defines it; grep across `classes/ cli/` for `agent:managebenchmarks` finds only the definition. Benchmark write paths gate on `viewbenchmarks` / `moodle/site:config` instead.
**Impact:** Dead capability: an admin sees an assignable permission in the role UI with no effect; the intended read/write split for benchmark management is not realised. No exploit.
**Compensating control:** Benchmark writes remain behind `viewbenchmarks`/`site:config` + sesskey (per C1).
**Recommendation:** Wire it onto the benchmark write paths, or remove the unused definition.

### [02-F04] ✅ PARTIALLY RESOLVED (was 🟡 MEDIUM) · D6 Docs coverage · docs/architecture/02-authorization-and-context.md (whole chapter)
**✅ Resolved 2026-06-30 (operating-context part):** the operating-context subsystem is now documented — `09-preflight-pipeline.md §2b` describes `skill_operating_context_resolver`, the scope cascade, `CONTEXT_TARGET_UNRESOLVED` + candidate clarification and `native_capability_guard` (Gate 2 at the operating context), and `02-authorization-and-context.md §2` adds the "ambient vs operating context" split with the Gate-1-ambient / Gate-2-operating placement (`LG_OPCTX`). **Still open:** the full-access/readonly gate (`agent_access_service`, `wb_license`) remains undocumented — track separately. — _Original finding below._

**What:** The operating-context resolution subsystem (flowchart node `LG_OPCTX`, owned by this section) — `context_resolver`, `skill_operating_context_resolver`, `operating_context_target_registry`, `module_target_resolver`, `context_target_unresolved_exception`, `native_capability_guard` — is not documented in any architecture chapter. So are `agent_access_service` and `wb_license`.
**Evidence:** Ch.02 mentions only `authorization_service`, `aiready`, `db/access.php` (its own "Files:" header, line 13). Grep for `operating_context`, `module_target_resolver`, `skill_operating_context` across `docs/architecture/*.md` returns **no hits in any chapter**. Yet the flowchart `LG_OPCTX` legend (and the flowchart-guide §"Flowchart updates 2026-06-28") describes this as a first-class, recently-landed engine subsystem that ch.02 owns. The full-access/readonly gate (`agent_access_service`, `wb_license`) — material to what skills are executable — is likewise undocumented.
**Impact:** A reader of the authorization chapter cannot learn how cross-context / generic module targeting, the ambient-vs-operating split, or the full-access licensing gate work — these are exactly the security-relevant mechanics this chapter should cover. The chapter materially under-describes its own subsystem.
**Compensating control:** The flowchart `LG_OPCTX` legend + `flowchart-guide.md` describe the behaviour; the code is heavily docblock-commented.
**Recommendation:** Add a §"Operating context (cross-context + generic module targeting)" to ch.02 covering the scope cascade, the trait opt-in, `CONTEXT_TARGET_UNRESOLVED` + candidate-list clarification, the queue write-back of the resolved `operating_contextid`, and the fail-closed mutating-target rule; add a §"Full-access gate" for `agent_access_service`/`wb_license`.

### [02-F05] 🟢 LOW · D6 Docs coverage · classes/local/wb_license.php:36, classes/local/wizard/services/agent_access_service.php:42
**What:** Docblocks name the agent-only PRO product token `'wizard'`, but the code uses `PRODUCT_AGENT = 'wbagent'`.
**Evidence:** `agent_access_service.php:42-44` docblock: "a valid PRO license is set (product 'wizard' or combined 'bookingagent', see wb_license)". `wb_license.php:39` defines `public const PRODUCT_AGENT = 'wbagent';` and matches against `['wbagent', PRODUCT_BOOKING_AGENT]`. The token actually checked is `wbagent`, not `wizard`.
**Impact:** A maintainer minting/validating a license key from the docblock would use the wrong product token. No runtime effect (the constant, not the comment, drives the check).
**Compensating control:** The code uses the constant; only the prose is stale (likely a leftover from the planned `local_wizard` rename).
**Recommendation:** Update both docblocks to say `'wbagent'` (or `PRODUCT_AGENT`).

### [02-F06] ⚪ INFO · D1 Security · classes/local/wizard/privacy_anonymizer.php:1016-1081, db/caches.php:28-34
**What:** The user name-match index (whole `user` table firstname/lastname) is cached for 900 s with no event-based invalidation; a newly created, renamed or suspended/deleted user is not reflected in name anonymization until the TTL expires.
**Evidence:** `get_user_name_match_index()` builds from `$DB->get_records_select('user', 'deleted = 0 AND suspended = 0', …)` and caches under `aiprivacynames` (`db/caches.php` ttl=900, no `invalidationevents`). There is no `db/events.php` observer on `\core\event\user_created`/`user_updated`/`user_deleted` to purge it.
**Impact:** Up to a 15-minute window where a freshly-added user's name is sent to the LLM in clear text under strict mode (or a deleted user's name still anonymizes). Low residual risk: the strict-mode contract is best-effort name masking, emails are matched live against the DB (`resolve_identity_from_email`), and the window is short.
**Compensating control:** Short TTL; email anonymization is live (not cached); the display gate fails closed regardless.
**Recommendation:** Optionally add a lightweight observer (or invalidation event) on user create/update/delete to purge `aiprivacynames`, or document the staleness window as accepted behaviour.

### [02-F07] ⚪ INFO · D5 Flowchart · docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd (AZ3)
**What:** Doc-lag: node `AZ3` names `is_agent_extension_installed() = is_installed_and_upgraded()`, but the gate consumed at entry / readiness is the wrapper `is_agent_engine_active()` (= installed AND **not** `local_wizard_is_active()`), the coexistence chokepoint.
**Evidence:** `authorization_service.php:94` `is_agent_engine_active()`; called by `check_use_readiness()` (line 159) and `aiready::export_for_template()` (line 71). `is_agent_extension_installed()` is the inner half. AZ3's label describes only the inner half.
**Impact:** None behavioural; the readiness path correctly also yields to `local_wizard`. Diagram describes a sub-step, not the active gate.
**Compensating control:** n/a.
**Recommendation:** Optionally annotate AZ3 with the `is_agent_engine_active` wrapper + `local_wizard` yield (matches the documented coexistence design).

### [02-F08] ⚪ INFO · D1 Security · classes/local/wizard/services/security/module_target_resolver.php:90-94,195-256
**What:** The site-wide fallback scope of module resolution runs `get_records_sql` over *every* instance of the module across the whole site, then per-course `can_access_course` + `get_fast_modinfo`/`uservisible` filtering.
**Evidence:** `resolve()` calls `collect_instances($modname, $userid, null)` when the ambient-course scope yields nothing; `collect_instances` joins `{<modname>} × {course} × {modules} × {course_modules}` site-wide (line 205) and loops modinfo per course.
**Impact:** Performance only — a large site with thousands of booking instances would do a broad scan + many `get_fast_modinfo` loads on the rare fallback path. Visibility/authorization remain correct (every candidate is `uservisible`-gated; Gate 2 re-checks at the resolved context). Not reachable for the common in-course path.
**Compensating control:** Fallback only fires when the ambient course has zero matches; `is_known_module()` validates `modname` first; results are not cached but the path is rare.
**Recommendation:** None required for go-live. Consider a result cap / index hint if site-wide resolution becomes hot.

## C. Per-file / per-method checklist

#### `classes/local/wizard/services/security/authorization_service.php`  (class `authorization_service`)
- [x] D1 [x] D2 [ ] D3 [x] D4 [x] D5 [ ] D6 — file-level (D3 see 02-F02; D6 see 02-F01)
- methods:
  - [x] `static is_agent_extension_installed()` — D1✓ D2✓ (Throwable-safe)
  - [x] `static local_wizard_is_active()` — clean (coexistence chokepoint)
  - [x] `static is_agent_engine_active()` — clean; see 02-F07 (flowchart doc-lag, not a code defect)
  - [x] `private resolve_valid_context(int)` — D1✓ correct level allow-list (BLOCK excluded); MUST_EXIST
  - [x] `require_use_capability(int,int)` — D1✓ AZ1; engine-active + has_capability, throws required_capability_exception
  - [x] `can_use(int,int)` — D1✓ delegates to check_use_readiness, Throwable-safe via that path
  - [x] `check_use_readiness(int,int)` — D1✓ three-state (agent_unavailable / context_invalid / permission_denied), never throws
  - [x] `require_valid_context(int)` — D1✓ AZ2
  - [ ] `require_capability_at(int,context,string)` — see 02-F02 (D3 dead method)

#### `classes/local/wizard/services/security/context_resolver.php`  (class `context_resolver`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `resolve(agent_context,int)` — D1✓ ancestor walk; throws coding_exception when none (fail-hard, not a privilege grant)
  - [x] `resolve_operating_context(agent_context,int,?target_selector,int,?registry)` — D1✓ empty-module-target nuance correct; throws context_target_unresolved_exception (no silent ambient fallback for mutations)
  - [x] `private find_ancestor_of_level(context,int)` — clean

#### `classes/local/wizard/services/security/skill_operating_context_resolver.php`  (class `skill_operating_context_resolver`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct(?context_resolver)` — clean (DI)
  - [x] `resolve(skill_interface,array,agent_context,int)` — D1✓ duck-typed opt-in; ambient fallback only for empty non-module selector
  - [x] `private skill_opts_into_target_context(skill_interface)` — clean
  - [x] `private resolve_target_level(skill_interface)` — clean (defaults to required level / CONTEXT_MODULE)

#### `classes/local/wizard/services/security/operating_context_target_registry.php`  (class `operating_context_target_registry`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct(array,?module_target_resolver)` — clean (DI; engine carries no domain provider by default)
  - [x] `resolve(target_selector,int,?agent_context)` — D1✓ level-routed; module→resolver, course→core, else duck-typed provider / unsupported
  - [x] `private resolve_course(target_selector)` — D1✓ id / numeric / visible-search via `core_course_category::search_courses` (visibility-aware); skips site course id 1; not_found/ambiguous handled

#### `classes/local/wizard/services/security/module_target_resolver.php`  (class `module_target_resolver`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (D1 INFO perf note 02-F08, not a defect)
- methods:
  - [x] `resolve(target_selector,?agent_context,int)` — D1✓ scope cascade; guarded by `is_known_module` before any interpolation
  - [x] `private decide(array)` — clean (0→null, 1→resolved, many→ambiguous w/ candidate payload)
  - [x] `private resolve_explicit_cmid(int,string,int)` — D1✓ `uservisible` gated, Throwable-safe → not_found
  - [x] `private filter_by_name(array,string)` — clean (exact-before-substring, core_text case-fold)
  - [x] `private collect_instances(string,int,?int)` — D1✓ **parameterised** SQL; only the validated `{modname}` table name is interpolated (guarded by is_known_module); `can_access_course` + `uservisible` per row; format_string on names; INFO 02-F08 (site-wide scan)
  - [x] `private is_known_module(string)` — D1✓ regex `^[a-z][a-z0-9_]*$` + `table_exists()` — the interpolation guard

#### `classes/local/wizard/services/security/native_capability_guard.php`  (class `native_capability_guard`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `static missing_capabilities(object,int,int)` — D1✓ Gate 2; **fail-closed** (unresolvable context → all required returned); duck-typed `get_required_native_capabilities`; per-cap `has_capability` at operating context

#### `classes/local/wizard/services/security/context_target_unresolved_exception.php`  (class `context_target_unresolved_exception`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (carries resolution for candidate-list clarification)
- methods:
  - [x] `__construct(context_target_resolution)` — clean
  - [x] `get_resolution()` — clean

#### `classes/local/wizard/services/agent_access_service.php`  (class `agent_access_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (D6 docblock token drift 02-F05; subsystem-undocumented 02-F04)
- methods:
  - [x] `static has_full_access()` — D1✓ request-memoized; license-or-endpoint
  - [x] `static runs_on_wunderbyte_llm()` — D1✓ endpoint-URL based, Throwable-safe; ordered fallback over planner/reply/summarise/generate actions
  - [x] `static find_wunderbyte_llm_instances(bool)` — clean (provider_compat 4.5/5.x bridge)
  - [x] `static instance_targets_wunderbyte_llm(object)` — clean
  - [x] `private static resolve_primary_endpoint(ai_manager,string)` — D1✓ Throwable-safe
  - [x] `private static is_wunderbyte_host(string)` — D1✓ host-suffix match via `parse_url` PHP_URL_HOST (no naive substring); exact host or `.wunderbyte.at` subdomain only

#### `classes/local/wizard/aiready.php`  (class `aiready`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct(int,int)` — clean (context-agnostic via agent_context)
  - [x] `export_for_template()` — D1✓ all caps via `has_capability`; `sesskey()` emitted for the panel; `$runtimeproviderstatus` only read via `?? false` (null-safe); reason map → get_string; usage-bar gated on `aiprovider/wunderbyte:viewusage`; no unescaped user data (icons are static HTML)
  - [x] `private build_check(bool,string,string,?string)` — clean (static icon HTML)
  - [x] `private is_module_ai_toggle_enabled(int)` — D1✓ Throwable-safe → false (fail-closed)
  - [x] `private get_booking_statistics()` — clean (duck-typed booking provider, Throwable-safe, neutral default)

#### `classes/local/wb_license.php`  (class `wb_license`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (D6 token drift 02-F05)
- methods:
  - [x] `static agent_license_is_activated()` — D1✓ PHPUNIT/BEHAT override is test-only-gated; product token + expiry checked
  - [x] `static parse_licensekey_for_agent(string)` — D2✓ delegates to `wb_payment` crypto; product allow-list + `time() < expiration`
  - [x] `private static get_candidate_licensekeys()` — clean (own setting, then booking licensekey)
  - [x] `private static licensekey_activates_agent(string)` — clean

#### `classes/local/wizard/privacy_anonymizer.php`  (class `privacy_anonymizer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (D1 staleness INFO 02-F06)
- methods (selected; all 40 audited):
  - [x] `get_mode()` / `should_anonymize_user_input()` / `should_anonymize_llm_backend_data()` — clean (validated enum, default off)
  - [x] `static looks_like_anon_token(string)` — clean (pure regex)
  - [x] `precheck_user_message(int,string)` — D1✓ strict→emails+names, soft→names; persists map
  - [x] `deanonymize_command_input(int,array)` / `_for_active_user(int,int,array)` — clean (off-guard, empty-map guard)
  - [x] `has_unresolved_anon_tokens(mixed)` — D1✓ supports the executor fail-closed contract
  - [x] `deanonymize_message_for_display(int,string)` — **D1✓ fail-closed display gate**: unresolved token → `ai_privacy_redacted_user`, never a raw placeholder (lines 318-343)
  - [x] `anonymize_value_for_llm` / `anonymize_value_recursive` / `anonymize_string_for_llm` — clean (recursive, field-aware)
  - [x] `reanchor_value_for_thread` / `reanchor_recursive` — D1✓ recall re-anchoring writes original/value only into the target map (server-side), never expands clear text to the LLM
  - [x] `anonymize_labeled_user_fields` / `anonymize_person_field_value` / `anonymize_emails` / `anonymize_names` — clean (protected spans for emails/code-tokens/capabilities; pass-1 full-name then pass-2 single)
  - [x] `find_email_spans` / `find_code_token_spans` / `offset_overlaps_protected_span` — clean (thread-288 protections)
  - [x] `get_user_name_match_index()` — D2✓ parameterised `get_records_select`; cached (INFO 02-F06)
  - [x] `get_token_map` / `set_token_map` — clean (thread metadata persistence)
  - [x] `get_or_create_token` / `build_field_token_from_base` / `extract_base_token_from_anon_token` / `resolve_token_entry` — clean (identitykey dedupe, field-suffix variants)
  - [x] `resolve_entry_for_field` / `resolve_identity_from_email` / `resolve_identity_from_user_ids` / `load_user_identity_record` — D2✓ all `$DB->get_record` parameterised, `deleted=0` filtered
  - [x] `build_identity_variants_from_user_record` / `merge_identity_variants` / `array_contains_person_identity_fields` / `anonymize_person_identity_field_group` / `is_user_reference_field` / `normalize_name` / `is_protected_word` / `get_protected_words` / `user_sets_intersect` — clean

#### `classes/privacy/provider.php`  (class `provider`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `static get_metadata(collection)` — D2✓ all 5 tables + every PII column declared; external `llm_provider` link declared
  - [x] `static get_contexts_for_userid(int)` — D2✓ per-table single-SELECT add_from_sql (UNION-guesser caveat noted in code), parameterised; memory→user context
  - [x] `static get_users_in_context(userlist)` — D2✓ parameterised; memory only at user context
  - [x] `static export_user_data(approved_contextlist)` — clean (memory at own user context; conversations per context)
  - [x] `private static export_memory(context,int)` — clean (transform::datetime)
  - [x] `private static export_conversations(context,int)` — clean (threads→messages/runs/debuglogs, all PII fields)
  - [x] `static delete_data_for_all_users_in_context(context)` / `delete_data_for_user(approved_contextlist)` / `delete_data_for_users(approved_userlist)` — D2✓ `get_in_or_equal` named params; memory at user context only
  - [x] `private static delete_conversations_select(context,string,array)` — D2✓ messages by thread-id list, runs/debug by contextid+clause, parameterised

#### `db/access.php`
- [x] D1 [x] D2 [ ] D3 [x] D4 n/a D5 [x] D6 — file-level (D3: dead capability 02-F03)
- [x] Fixed caps (useaiinstructions, seemagicwand, debugskillselection, ignoreaiavailability, requesttrial, viewbenchmarks, managegovernance) — all defined; `requesttrial`/`seemagicwand` consumed by aiready/navbar
- [x] `$buildskillcapability` closure + teacher/manager/admin/authorizeduser loops — D1✓ name-derived caps match `skill_contract_validator::build_skill_capability_name`; proven complete by `skill_name_capability_test`
- [ ] `managebenchmarks` — see 02-F03 (defined, never enforced)

## D. Go-live blockers from this section
None. No BLOCKER or HIGH findings. The two-gate authorization model, fail-closed native-capability guard, fail-closed anonymizer display gate, and complete privacy provider are all correct. Recommended (non-gating) pre/post-launch fixes: 02-F01 + 02-F04 (chapter: remove the non-existent `require_valid_context_for_levels`, document the operating-context subsystem), 02-F03 (wire or remove `managebenchmarks`), 02-F02 (remove dead `require_capability_at`).
