# Audit Section 09 — Shadow Queue & Executor

**Scope:** `classes/local/wizard/queue/*` (queue_manager, observation_builder), `classes/local/wizard/executor.php`, `services/queue_command_mapper.php`, `services/queue_status_policy.php`, `services/queue_transition_service.php`, `services/execution/execution_feedback_service.php`, `services/confirm_run_service.php`, `services/discard_pending_service.php`, `services/pending_intent_service.php`, `services/pending_queue_command_service.php`  ·  **Files audited:** 11  ·  **Methods audited:** 78
**Arch chapter(s):** docs/architecture/10-shadow-queue.md, docs/architecture/11-executor.md  ·  **Flowchart nodes:** QUEUE (Q_ENQUEUE, Q_IDEM, Q_RUNNING, Q_BLOCKED, Q_PLANNED, Q_RETRY, Q_RTRACE, Q_FAIL_TTL, Q_UPDST), EXEC (EXC, EXC_IDEM, EXC_EVAL, EXC_GUARD, EXC_RUN)
**Auditor verdict:** ⚠️ issues (no blocker)

The queue/executor core is, on its security-critical invariants, sound: the guard token is a constant-time `hash_equals` over `sha256(skill:operating_context:normalized prepared_input)` with no second full preflight; the running slot is acquired in a `SELECT … FOR UPDATE` transaction enforcing at-most-one running item per thread; the queue (input-signature) vs executor (already-executed run) idempotency split is implemented exactly as documented; blocked TTLs are R1=900/R2=300/R3=900; R3 never enters `retry_waiting`; the retry-layer cap of 2 is enforced; IDOR ownership is enforced at the WS entry and re-authorized in the executor. The findings are **documentation/flowchart drift** (a deny reason and an async-task chapter that do not match the code) plus minor string-localisation and duplication cruft — none of which mis-executes a mutation.

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | Guard token constant-time + operating-context-bound; atomic single-running-item; executor re-authorizes (`require_use_capability`+`require_valid_context`); Gate-1 evaluator + Gate-2 native backstop both run before `execute()`; pending-intent ownership scoped by userid+contextid; WS entry enforces sesskey + `thread_belongs_to_user`. One MEDIUM (executor does not itself re-verify run/thread ownership — defended by both callers). SQL parameterised. |
| D2 Moodle API      | issues | Hard-coded English `detail`/`message` strings in executor + confirm_run_service (LOW; relayed/translated by synchronizer). `$DB` data API + delegated transaction used correctly. phpcs not machine-verifiable in this checkout (no `vendor/bin/phpcs`). |
| D3 Structure       | pass | No dead code (every in-scope class has live callers — grepped `classes/`+`tests/`); engine stays domain-clean (executor is duck-typed, carries no `mod_booking.*` names); executor is the only place `skill::execute()` runs. |
| D4 Duplication     | issues | Two parallel command-signature helpers (queue `sha256` vs confirm_run `sha1`); repeated queue-item skeleton literal across 3 enqueue paths in queue_manager; `normalize_queue_item_ids` duplicated in 3 classes. All LOW. |
| D5 Flowchart       | issues | `EXC_EVAL` lists `skill_version_unsupported` as an evaluator deny reason — the evaluator never checks version (enforced in preflight). Behavioural-label drift. |
| D6 Docs coverage   | issues | ch.11 §3 lists a 6th deny reason `DENY_SKILL_VERSION_UNSUPPORTED` not enforced by `evaluate_skill`, and omits the real `DENY_REQUIRES_PRO` branch; ch.11 §7 describes an async `execute_ai_run_adhoc` task + `executionmode=adhoc` that **do not exist** in the code. |

## B. Findings

### [09-F01] 🟠 HIGH · D6 Docs coverage · docs/architecture/11-executor.md §3 + §7 / flowchart EXC_EVAL
**What:** The executor chapter and the `EXC_EVAL` flowchart node both describe releasability deny reasons and an async execution path that the code does not implement, while omitting a deny branch the code does implement.
**Evidence:**
- ch.11 §3 table claims `skill_executability_evaluator::evaluate_skill` gates "registry → runtime → active → capability → context" and returns `DENY_SKILL_VERSION_UNSUPPORTED` "when the requested skill version is not supported (`skill_version_policy`)". But `skill_executability_evaluator::evaluate_skill()` (lines 61–118) has exactly these branches: `DENY_NOT_REGISTERED` (65), `DENY_RUNTIME_DISABLED` (71), `DENY_INACTIVE` (77), `DENY_REQUIRES_PRO` (85–93), `DENY_MISSING_CAPABILITY` (95), `DENY_CONTEXT_INVALID` (101). There is **no** version check; `grep` shows `skill_version_policy`/`preflight_version_validator` live only in the **preflight** layer, never in the evaluator. The `DENY_SKILL_VERSION_UNSUPPORTED` constant exists (`skill_contract_validator.php:60`) but is consumed only by `list_skills_skill.php:362` for display — never returned by the gate the chapter attributes it to.
- ch.11 §3 omits `DENY_REQUIRES_PRO`, which the evaluator *does* return (line 89) and the executor surfaces as `issue_codes=['REQUIRES_PRO']` (executor.php:168).
- ch.11 §7 ("Async execution: `execute_ai_run_adhoc`") describes `executionmode = adhoc` routing a confirmed run to an `execute_ai_run_adhoc` ad-hoc task. `ls classes/task/` shows no such file (only cleanup/embeddings/benchmark tasks); `grep -rn executionmode classes/` returns zero hits. Both real execution paths — `confirm_run_service::confirm()` (executor.php call at :184) and `agent_decision_service` R0 staged-execute (:1180) — run `execute_commands()` **synchronously** in the web request.
**Impact:** A maintainer reading ch.11 to reason about the executor's last-line-of-defence will believe version enforcement happens at execution (it happens upstream in preflight) and will look for an async task that was never built; an auditor verifying "6 deny reasons incl skill_version_unsupported" against the code finds a mismatch.
**Compensating control:** Skill-version enforcement genuinely exists, just one layer earlier (preflight `preflight_version_validator` → `SKILL_VERSION_UNSUPPORTED` hard-block), so there is no security gap — only a doc accuracy gap. Per `feedback_flowchart_policy` this is reported, not reconciled.
**Recommendation:** Update ch.11 §3 to list the real evaluator branches (incl. `DENY_REQUIRES_PRO`) and move the `skill_version_unsupported` mention to the preflight chapter; relabel `EXC_EVAL` accordingly. Either remove ch.11 §7 / the `executionmode=adhoc` description or mark it as a not-yet-implemented design intent.

### [09-F02] 🟡 MEDIUM · D1 Security · classes/local/wizard/executor.php:93–116
**What:** `execute_commands()` re-checks the *use* capability and context, but does not itself verify that `$userid` owns the run/thread it loads by `$runid`.
**Evidence:** `$run = $this->store->get_run($runid)` (line 115) loads the run purely by id; `$threadid = (int)($run->threadid ?? 0)` is then trusted. There is no `thread_belongs_to_user($threadid, $userid, …)` assertion inside the executor. The `$userid`/`$contextid` passed in are taken on trust from the caller.
**Impact:** If a future caller invoked `execute_commands()` with a `$userid` that does not own `$runid`/`$threadid`, the executor would de-anonymize and execute against the wrong thread's anonymizer map. Today this is unreachable: both callers validate ownership first — `ai_confirm_run::execute()` enforces `require_sesskey()` + `require_use_capability` + `thread_belongs_to_user((int)$params['threadid'], $USER->id, $context->id)` before delegating to `confirm_run_service`, and `agent_decision_service` derives the thread from the authenticated runtime.
**Compensating control:** Strong — sesskey + ownership at the only WS entry; `require_use_capability` + `require_valid_context` re-checked here; the executor is not itself a web-service entry point.
**Recommendation:** Add a defensive `thread_belongs_to_user` (or run-ownership) assertion at the top of `execute_commands()` so the executor's "always re-verify in adhoc context" comment (line 98) is actually true for ownership, not only for the use-capability.

### [09-F03] 🟢 LOW · D2 Moodle API · classes/local/wizard/executor.php:164,227,238
**What:** User-reachable `detail` strings are hard-coded English instead of `get_string()`.
**Evidence:** `'Skill denied by governance gate (' . $denyreason . '): '` (164), `'Execution guard missing for mutating command.'` (227), `'Execution guard mismatch for mutating command.'` (238). These flow into the result `detail` and onward through the observation/synchronizer pipeline.
**Impact:** Violates `feedback_all_strings_via_get_string`; if ever surfaced raw they would not honour `outputlang`.
**Compensating control:** These are guard/governance failure paths re-rendered by the synchronizer in the user's language (the real cause is fed via `synchronizer_input_builder`, `feedback_synchronizer_always_answers`); the line-164 string is a fallback only when `get_user_facing_deny_message` returns null. Low residual.
**Recommendation:** Wrap in `get_string(...)` keyed to `bookingextension_agent` (mirroring the already-localised `agent_executor_*` strings used elsewhere in the same method).

### [09-F04] 🟢 LOW · D2 Moodle API · classes/local/wizard/services/confirm_run_service.php (multiple)
**What:** Numerous hard-coded English `message`/`errors` strings in `build_error_payload`, `resolve_run_target`, and the discard/repeat-guard branches.
**Evidence:** e.g. `'Missing queue item id. Please confirm the latest assistant proposal.'` (106), `'Invalid or stale queue item id…'` (508), `'Queue item is waiting for dependencies…'` (535), `'Retry available in about ' . $waitseconds . 's.'` (547). `discard_pending_service` similarly returns English `message` literals (94–95).
**Impact:** Same get_string policy gap as 09-F03.
**Compensating control:** All are `response_type=error`/clarification payloads relayed and translated by the synchronizer, not shown raw; `error_presentation_requested` faithfully presents the real cause.
**Recommendation:** Localise via `get_string`/`localized_string_service` consistent with `execution_feedback_service`.

### [09-F05] 🟢 LOW · D4 Duplication · confirm_run_service.php:710 vs queue_manager.php:734
**What:** Two independent command/input signature helpers with different hash algorithms.
**Evidence:** `confirm_run_service::command_signature()` builds `sha1($skill.'|'.json_encode($input))` (after `unset($input['outputlang']); ksort()`); `queue_manager::build_input_signature_details()` builds `sha256($skill.':'.$json)` over a recursively-normalised payload (with optional skill-business identity). They serve different roles (repeat-guard vs idempotency dedupe) but encode the same concept divergently.
**Impact:** Drift risk: a change to "what counts as the same command" must be made in two places; the `sha1` repeat-guard does not benefit from the queue's recursive trim/sort normalisation, so semantically-identical inputs with reordered nested keys could evade the repeat guard while being deduped by the queue.
**Compensating control:** Both are best-effort guards over already-confirmed flows; neither gates a security decision.
**Recommendation:** Extract a single `command_signature` service used by both, with one normalisation + one algorithm.

### [09-F06] 🟢 LOW · D3 Structure · queue_manager.php (enqueue_command / enqueue_placeholder / DAG-fail item)
**What:** The full queue-item array skeleton (~26 keys) is hand-duplicated three times.
**Evidence:** The item literal at lines 116–144 (DAG-cycle failed item), 175–208 (normal enqueue), and 631–660 (placeholder) repeat the same key set with small deltas.
**Impact:** A new queue-item field must be added in three places (and `queue_command_mapper`/`update_status` ALLOWED list kept in sync); easy to forget one.
**Compensating control:** None needed; cosmetic.
**Recommendation:** Add a `private function new_queue_item_skeleton(array $overrides): array` factory.

### [09-F07] ⚪ INFO · D1 Security · queue_manager.php:375–451 / executor.php:235 / preflight_execution_gate.php:136–163
**What:** Confirmation of the three security-critical invariants in this section — recorded as audited-clean.
**Evidence:**
- **Atomic single-running-item:** `try_mark_running()` opens `start_delegated_transaction()`, locks the thread row with `… WHERE id = :id FOR UPDATE` (skipped only on MSSQL), rejects if *any* item is `running`, then sets+persists — exactly the documented `Q_RUNNING` contract. SQL is parameterised (`:id`); the only interpolation is the constant `{$forupdate}` clause (no user input).
- **Guard token, no 2nd preflight:** executor verifies via `preflight_execution_gate::verify_guard_token($guardtoken, $skillname, $operatingcontextid, $input)` → `hash_equals($guardtoken, sha256(trim(skill):max(0,ctx):json(normalize(input))))`. The token is bound to the **operating** context that `set_prepared_input()` persisted (queue_manager:344–352), so a cross-context target cannot be replayed at the ambient scope. Mutating commands with a missing token fail closed (`EXECUTION_GUARD_MISSING`).
- **Idempotency split:** queue reuses a non-terminal same-signature item (`QUEUE_SIGNATURE_REUSE`, queue_manager:163–173; terminal items correctly excluded so a failed item is not reused); executor returns a single `skipped`/`EXECUTOR_ALREADY_EXECUTED` when `run_exists_other_than($idempotencykey,$runid)` (executor:104). `run_exists_other_than` is parameterised SQL.
- **Gate-2 backstop:** `native_capability_guard::missing_capabilities($skill, $operatingcontextid, $userid)` runs immediately before `execute()` (executor:266) at the operating context, independent of the skill.

### [09-F08] ⚪ INFO · D5 Flowchart · queue_transition_service.php / queue_manager.php
**What:** Confirmation that the queue behavioural contract matches the flowchart for the nodes this section owns.
**Evidence:** R3 → `R3_NO_RETRY`/failed on a retry transition (queue_transition_service:111–123; confirm_run_service:825); retry-layer cap `MAX_RETRY_LAYERS_PER_ERROR_CLASS = 2` with `RETRY_LAYER_LIMIT_EXCEEDED`/`RETRY_LAYER_COLLISION` (:41–47, :416–425); blocked TTL R2=300 else 900 (`resolve_blocked_ttl_seconds` :844–856); `fail_expired_blocked_items` → failed/`BLOCKED_CONFIRMATION_TIMEOUT` (:583–611, matches the corrected `Q_FAIL_TTL` code name); planned-placeholder lifecycle (`enqueue_placeholder`/`has_planned_placeholders`/`consume_next_placeholder`) drives `CONF_FOLLOW` via confirm_run_service:292; preflight retry backoff `500 * 2^n` capped 4000 (:128) matching L3 constants.

## C. Per-file / per-method checklist

#### `classes/local/wizard/queue/queue_manager.php`  (class `queue_manager`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (see 09-F06) [x] D5 [x] D6 — file-level
- methods:
  - [x] `__construct()` — clean
  - [ ] `enqueue_command()` — D1✓ D2✓ D5✓; D4 skeleton dup (09-F06)
  - [x] `update_status()` — D1✓ (ALLOWED_EXTRA_STATUS_FIELDS whitelist prevents arbitrary key injection) D2✓
  - [x] `get_queue_items()` / `get_queue_item()` / `save_queue_items()` — clean
  - [x] `set_prepared_input()` — D1✓ operating-context-bound guard token (09-F07)
  - [x] `try_mark_running()` — D1✓ atomic FOR UPDATE, parameterised SQL (09-F07)
  - [x] `can_pickup_now()` / `dependencies_succeeded()` / `dependencies_succeeded_from_items()` — clean
  - [x] `validate_depends_on_is_dag()` / `dfs_cycle_detect()` — clean (cycle → failed item, no enqueue)
  - [x] `fail_expired_blocked_items()` — D5✓ (09-F08)
  - [ ] `enqueue_placeholder()` — D4 skeleton dup (09-F06); else clean
  - [x] `has_planned_placeholders()` / `consume_next_placeholder()` / `get_planned_placeholder_intents()` — clean
  - [x] `build_input_signature_details()` — D4 note: parallel to confirm_run signature (09-F05); logic clean
  - [x] `normalize_for_signature()` / `next_sequence()` / `resolve_thread_contextid()` — clean
  - [x] `resolve_blocked_expires_at()` / `resolve_blocked_ttl_seconds()` — D5✓ TTLs correct (09-F08)

#### `classes/local/wizard/queue/observation_builder.php`  (class `observation_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (live caller: agent_decision_service:1255)
  - [x] `build_observation()` — pure string assembly, no PII leak (skill+status+issue_codes only)

#### `classes/local/wizard/executor.php`  (class `executor`)
- [x] D1 (1 MEDIUM 09-F02) [ ] D2 (09-F03) [x] D3 [x] D4 [ ] D5 (09-F01) [ ] D6 (09-F01) — file-level
- methods:
  - [x] `__construct()` — clean
  - [ ] `execute_commands()` — D1: re-authz + guard + Gate-2 ✓ (09-F07); MEDIUM run-ownership (09-F02); D2 hard-coded strings (09-F03); deny-reason doc drift (09-F01)
  - [x] `build_safe_executed_input()` — D1✓ schema-key allowlist + duck-typed sensitive-field stripping (no skill names in engine)
  - [x] `skill_requires_module_target()` — D1✓ fail-closed cross-context module guard; duck-typed

#### `classes/local/wizard/services/queue_command_mapper.php`  (class `queue_command_mapper`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
  - [x] `from_queue_item()` — guard_token only emitted when `$includeexecutionmetadata`; operating_contextid carried — clean
  - [x] `from_queue_items()` — clean

#### `classes/local/wizard/services/queue_status_policy.php`  (class `queue_status_policy`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (single source of truth for status semantics — good)
  - [x] all `*_status()` / `is_*_status()` accessors — clean, no logic drift

#### `classes/local/wizard/services/queue_transition_service.php`  (class `queue_transition_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
  - [x] `apply_preflight_decision()` — D5✓ R1/R2/R3 routing, R3 no-retry, autoconfirm gating (09-F08)
  - [x] `to_ready()/to_blocked_confirmation()/to_failed()/to_skipped()/to_succeeded()` — clean
  - [x] `to_retry_waiting()` — D5✓ category + layer-guard cap of 2 (09-F08)
  - [x] `evaluate_retry_layer_guard()` — clean (cap enforced)
  - [x] `normalize_reason_code()/resolve_retry_layer_from_reason_code()/normalize_retry_layers()/normalize_queue_item_ids()` — clean

#### `classes/local/wizard/services/execution/execution_feedback_service.php`  (class `execution_feedback_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
  - [x] `__construct()` — clean
  - [x] `build_completion_feedback()` — D2✓ deterministic (secondary LLM removed), localised
  - [x] `sanitize_results_for_client()` — D1✓ explicit field allowlist (no raw passthrough of arbitrary skill keys); long but single-responsibility
  - [x] `sanitize_result_detail()/fallback_message_for_results()` — D2✓ get_string via localized_string_service
  - [x] `extract_primary_link_from_result()/_results()/localized()/localized_list_count_message()/append_link_to_message()` — clean

#### `classes/local/wizard/services/confirm_run_service.php`  (class `confirm_run_service`)
- [x] D1 [ ] D2 (09-F04) [x] D3 [ ] D4 (09-F05) [x] D5 [x] D6 — file-level
- methods:
  - [ ] `confirm()` — D1✓ (session write_close before exec; ownership validated at WS entry; try_mark_running guarded; autoconfirm-blocked-on-failure guard); D2 hard-coded strings (09-F04). Large method but clearly staged.
  - [x] `resolve_run_target()` — D1✓ dependency/retry gates + repeat guard; D2 strings (09-F04)
  - [x] `build_error_payload()` — D2 strings (09-F04)
  - [x] `build_preview_response_fields()/resolve_and_accumulate_preview_json()` — clean (domain-agnostic passthrough)
  - [ ] `command_signature()` — D4 sha1 vs queue sha256 (09-F05)
  - [x] `record_failed_command()/get_failed_command_detail()` — D1✓ bounded map (≤20), detail truncated to 600 chars
  - [x] `has_successful_execution_results()/normalize_string_list()` — clean
  - [x] `build_retry_decision()` — D5✓ R3 no-retry + gate-evaluate backoff (09-F08)
  - [x] `should_continue_with_runtime_loop()/find_next_mutating_queue_item()/extract_attempted_skills_from_commands()` — clean
  - [x] `resolve_pending_queue_item_id()` — D1✓ requested id must be a member of the pending intent's queue_item_ids (no arbitrary id injection)
  - [x] `resolve_commands_for_run()/mark_dependents_skipped()/get_active_mutating_queue_item()/is_actionable_mutating_queue_item()` — clean

#### `classes/local/wizard/services/discard_pending_service.php`  (class `discard_pending_service`)
- [x] D1 [ ] D2 (09-F04) [x] D3 [x] D4 [x] D5 [x] D6 — file-level
  - [x] `__construct()` — clean
  - [x] `discard()` — D1✓ consumes pending intent (userid+contextid scoped) then skips only actionable mutating items; D2 English message literals (09-F04)

#### `classes/local/wizard/services/pending_intent_service.php`  (class `pending_intent_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
  - [x] `get()/clear()` — clean
  - [x] `consume()` — D1✓ delegates to store `consume_pending_intent` which fail-closes on userid/contextid mismatch (verified in conversation_store)
  - [x] `set()` — D1✓ intent key bound to userid+threadid+queue_item_ids; `queue_authoritative` flag set

#### `classes/local/wizard/services/pending_queue_command_service.php`  (class `pending_queue_command_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (live caller: agent_decision_service)
  - [x] `build_mutating_commands_from_pending_intent()` — D1✓ only mutating + actionable items mapped
  - [x] `normalize_queue_item_ids()` — clean (note: same helper shape as two siblings — minor, folded into 09-F05 family but not separately raised)

## D. Go-live blockers from this section
- **None.** No BLOCKER. One HIGH (09-F01) is a documentation/flowchart accuracy defect, not a runtime risk — recommended to fix pre-launch so the executor's last-line-of-defence is documented truthfully, but it does not gate launch on its own. All security-critical invariants (guard token, atomic single-running-item, idempotency split, Gate-1+Gate-2 ordering, ownership) are implemented correctly.
