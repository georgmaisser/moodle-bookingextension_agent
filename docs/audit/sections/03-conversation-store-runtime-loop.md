# Audit Section 03 — Conversation Store & Runtime Loop

**Scope:** `classes/local/wizard/conversation_store.php`, `agent_runtime.php`, `agent_state.php`,
`interpreter.php`, `classes/agent.php`, `ai_error_classifier.php`,
`services/messaging/message_persistence_service.php`, `services/conversation_thread_memory.php`,
`services/completed_command_history_service.php`, `services/execution_observation_ledger.php`,
`services/observation_time.php`  ·  **Files audited:** 11  ·  **Methods audited:** 96
**Arch chapter(s):** docs/architecture/03-conversation-store.md + 04-agent-runtime-and-loop.md
**Flowchart nodes:** CSTORE (CS1, CS2, CS9, CS15), RUNTIME (RUNLOOP, LOOP_STEP, OBS_ACCUM, ATTB,
BUDG_CHECK, BUDGX, LOOP_RETRYABLE, LOOP_COLLIDE, FW_RETRY_OBS, ABANDON_GUARD)
**Auditor verdict:** ⚠️ issues (no blocker present)

The store, runtime loop, budget guard, retry-hint collision/R3 guards, observation anonymization,
thread-ownership IDOR gating, and idempotent message persistence are all implemented correctly and
match the architecture chapters at the contract level. The findings below are a LOW fail-open edge
in `consume_pending_intent` (defended at every reachable call site), three doc-lag items, and minor
duplication/cruft. Nothing in this section gates go-live.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues | Thread-ownership IDOR gate enforced at every WS entry before the runtime is reached (`get_owned_active_thread`/`thread_belongs_to_user`); all SQL parameterised; observations anonymized at construction time before re-prompt. One LOW fail-open in `consume_pending_intent` (03-F01), fully compensated. |
| D2 Moodle API      | pass | `$DB` data API throughout, `get_string`/localized service for user strings, user-preferences API for the allowlist, `userdate` for timestamps, correct PSR-4 namespaces, GPL headers present. |
| D3 Structure       | issues | Runtime is thin and well-layered; agent_state never persisted; no engine→domain leak. Minor: two near-identical retry-code resolvers (03-F04), `has_observations()` zero-caller in this engine (03-F05, LOW). |
| D4 Duplication     | issues | `normalize_input`/`normalize_value` duplicated across `completed_command_history_service` and `execution_observation_ledger` (03-F03); interpreter re-implements the shared JSON fence extractor (corroborates C3-F05). |
| D5 Flowchart       | pass | RUNLOOP/LOOP_STEP/BUDG_CHECK/LOOP_RETRYABLE/LOOP_COLLIDE/ABANDON_GUARD/ATTB/CS15 all match code behaviourally. Step-message attribution note in the .mmd is correct (clearing in `ai_send_message`, per-step write in `orchestrator::process`). |
| D6 Docs coverage   | issues | Conceptual model accurate. Ch.04 §9 cites stale `orchestrator.php` line numbers for planner calls (03-F02, = C5-F02); ch.03 §7/§9 cites `orchestrator.php ~389` for `add_step_message`, real line is **267** (03-F06, = C5-F08). |

---

## B. Findings

### [03-F01] 🟢 LOW · D1 Security · classes/local/wizard/conversation_store.php:791
**What:** `consume_pending_intent()` skips the userid (and contextid) ownership comparison when the
stored intent was persisted without a userid/contextid (value 0).
**Evidence:** `if ($userid > 0 && (int)($pending['userid'] ?? 0) > 0 && (int)$pending['userid'] !== $userid)`
(line 791) and the analogous contextid line 794. The middle clause `(int)($pending['userid'] ?? 0) > 0`
means a pending intent stored with `userid=0` bypasses the per-user comparison entirely (it fails open,
not closed). `set_pending_intent()` defaults `$userid=0, $contextid=0` (lines 711-712), so a caller that
does not pass them produces exactly such an unstamped intent.
**Impact:** In isolation a caller who reached a thread could consume a pending intent not stamped with
their own userid. Residual risk is near-zero: every webservice that confirms/discards
(`ai_confirm_run.php:128`, `ai_discard_pending.php:82`) first proves thread ownership via
`thread_belongs_to_user($threadid, $USER->id, $context->id)`, and the actual mutation is executed as the
authenticated `$USER` with guard-token verification and a Gate-2 re-check at the operating context.
**Compensating control:** WS thread-ownership gate, guard-token verification, Gate-2 backstop at
execute-time — all keyed on the authenticated user. (Same finding as cross-cutting C1-F04.)
**Recommendation:** Make the comparison fail-closed: when `$userid > 0`, treat a missing stored userid
as a non-match; and always stamp `userid`+`contextid` at `set_pending_intent` write time.

### [03-F02] 🟡 MEDIUM · D6 Docs coverage · docs/architecture/04-agent-runtime-and-loop.md §9
**What:** The chapter's flowchart-notes section cites planner-call line numbers that no longer exist.
**Evidence:** §9 states the planner chat call lives in `orchestrator::run_selection_phase()`
(`orchestrator.php:1057`) and `run_construction_phase()` (`orchestrator.php:1292`), with synchronizer
`invoke()` at `:489` and discovery embeddings at `:687`. Per C5-F02 the orchestrator was split: those
calls now live in `planner_phase_service.php` (selection :222 / construction :432) and
`discovery_phase_service.php:294`; `orchestrator.php` is ~809 lines and has no line 1057/1292.
**Impact:** A maintainer following §9 to the planner LLM call lands on the wrong file/line. The
behavioural invariants the note asserts (≤2 planner calls; discovery makes no planner chat call) still
hold against current code — this is location drift, not a behaviour error.
**Compensating control:** The constants and behavioural contract in ch.04 (MAX_LOOP_STEPS=6, retry codes,
budget guard) were verified correct against `agent_runtime.php`.
**Recommendation:** Update §9 line citations to the `planner_phase_service`/`discovery_phase_service`
locations (tracked centrally as C5-F02).

### [03-F03] 🟡 MEDIUM · D4 Duplication · services/completed_command_history_service.php:240 + services/execution_observation_ledger.php:233
**What:** `normalize_input()` and its recursive `normalize_value()` helper are duplicated almost verbatim
across two runtime services.
**Evidence:** Both define `$dropkeys = ['confirmed','outputlang','lang','user_lang','sessiontoken','sesskey']`
and a recursive `normalize_value()` that walks arrays. `completed_command_history_service.php:240-311`
caps strings at 160 chars and lists at 20 entries; `execution_observation_ledger.php:233-275` `ksort`s and
recurses without the caps. The drop-key list and overall shape are the canonical-home candidate.
**Impact:** The two copies have already diverged (string/array capping present in one, absent in the
other). A future change to the redaction drop-key set must be made in two places or it silently drifts —
a privacy-relevant list to keep in sync.
**Compensating control:** None; both are reached on the runtime context-assembly path.
**Recommendation:** Extract a shared `input_normalizer` (with the drop-key set as one constant) and let
each service pass its own caps, so the privacy drop-list has a single home.

### [03-F04] 🟢 LOW · D3 Structure · classes/local/wizard/agent_runtime.php:521 + :559
**What:** `resolve_framework_retry_issue_code()` and `resolve_exhausted_framework_retry_issue_code()` are
near-identical: same response_type guard, same R3-blocker guard, same issue-code normalization, same loop
over `LOOP_RETRYABLE_ISSUE_CODES`; they differ only in the `>=`-budget branch (return `null` vs return the
code).
**Evidence:** Lines 521-550 vs 559-586 are structurally parallel; the only semantic difference is the
`if ($attempts >= self::LOOP_MAX_RETRIES_PER_ISSUE)` arm.
**Impact:** Two copies of the R3-blocker + normalization preamble must be kept in lockstep; a guard tweak
in one (e.g. adding a new R3 signal) can be forgotten in the other.
**Compensating control:** The R3 logic actually lives in the shared `has_r3_retry_blocker()` they both
call, so the highest-risk part is already centralised.
**Recommendation:** Fold into one helper returning a small status enum (`retry` / `exhausted` / `none`),
or a `(?string $code, bool $exhausted)` tuple.

### [03-F05] 🟢 LOW · D3 Structure · classes/local/wizard/agent_state.php:174
**What:** `agent_state::has_observations()` has no caller in `classes/` or `tests/`.
**Evidence:** `grep -rn "has_observations" classes tests` returns only the declaration. `get_observations()`,
`record_step()`, `append_observation()`, `get_steps()`, `step_count()`, and the family-cache methods are all
called; `has_observations()` is not. It is a plain public accessor, not a framework entry point.
**Impact:** Dead accessor (very small). Not a contract method (agent_state is a concrete value object, not
an interface).
**Compensating control:** n/a.
**Recommendation:** Remove, or add a `@codeCoverageIgnore`/keep-note if it is a deliberate API surface.

### [03-F06] 🟢 LOW · D6 Docs coverage · docs/architecture/03-conversation-store.md §7 / §9 note
**What:** Ch.03 attributes the per-step `add_step_message()` call to `orchestrator.php ~line 389`; the real
call site is `orchestrator.php:267`.
**Evidence:** `grep -n add_step_message classes/local/wizard/orchestrator.php` → single hit at line 267
(`$this->store->add_step_message($threadid, $stepnum, $intent);`). The §9 note in ch.04 repeats "~line 389".
The behavioural claim (clearing once in `ai_send_message::execute()` before the loop; per-step write inside
`orchestrator::process()`, not in `run_loop()`) is correct — only the line number drifted.
**Impact:** Cosmetic doc-lag; the attribution and behaviour are otherwise right (and the .mmd LOOP_STEP node
states it correctly). Same item as cross-cutting C5-F08.
**Compensating control:** Verified: `agent_runtime::run_loop()` contains neither `clear_step_messages()` nor
`add_step_message()`.
**Recommendation:** Change "~line 389" to ":267" in both chapters.

---

## C. Per-file / per-method checklist

#### `classes/local/wizard/conversation_store.php`  (class `conversation_store`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (implements `agent_conversation_store`; all SQL parameterised)
- methods:
  - [x] `get_runtime_feature_flags_snapshot()` — delegates to flag snapshot — clean
  - [x] `get_active_thread()` — keyed on (userid, contextid, status) — clean
  - [x] `get_or_create_thread()` — clean
  - [x] `create_fresh_thread()` — archives actives then inserts — clean
  - [x] `add_message()` — looks up thread, stamps message.userid from thread — clean
  - [x] `add_step_message()` / `clear_step_messages()` / `get_step_messages_since()` — parameterised — clean
  - [x] `get_messages()` / `get_thread()` — clean
  - [x] `get_owned_active_thread()` — ownership-scoped fetch (id+userid+contextid+status); IDOR gate — clean
  - [x] `thread_belongs_to_user()` — ownership boolean gate used by every WS — clean
  - [x] `get_recent_messages()` — excludes `step`, returns chronological — clean
  - [x] `get_last_thread_for_user()` — dual user/context fenced — clean
  - [x] `get_user_threads_by_date_window()` — dual-fenced t.userid + m.userid; portable DISTINCT — clean
  - [x] `get_user_messages_for_thread()` — dual user fence + `sql_like_escape`/`sql_like`/`sql_cast_to_char` — clean
  - [x] `create_run()` / `update_run_status()` / `get_run()` / `get_latest_run()` — parameterised — clean
  - [x] `run_exists()` / `run_exists_other_than()` — idempotency reads (EXC_IDEM) — clean
  - [x] `get_thread_metadata_value()` / `set_thread_metadata_value()` — JSON blob accessors — clean
  - [x] `set_planner_trace_history()` / `set_phase_trace()` — normalize then store — clean
  - [x] `set_pending_intent()` — mints `C######` code, sha256 checksum of queue ids, TTL≥1 — clean
  - [x] `get_pending_intent()` — gated on queue items + state=pending + not-expired (auto-clears) — clean
  - [ ] `consume_pending_intent()` — see 03-F01 (LOW, userid==0 fail-open; defended at WS)
  - [x] `clear_pending_intent()` — clean
  - [x] `allow_confirmation_for_thread()` / `is_confirmation_allowed_for_session()` / `is_confirmation_allowed_for_thread()` — context-scoped allowlist (threadid ignored) — clean
  - [x] `make_confirmation_session_allowlist_key()` / `get_confirmation_session_allowlist()` / `save_confirmation_session_allowlist()` — user-prefs API, prunes expired on load — clean
  - [x] `add_llm_debug_entry()` / `get_llm_debug_entries()` — parameterised; success coerced 0/1 — clean

#### `classes/local/wizard/agent_runtime.php`  (class `agent_runtime`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (strict_types; thin coordinator; no domain leak)
- methods:
  - [x] `get_runtime_feature_flags_snapshot()` / `__construct()` / `create_default()` — DI wiring — clean
  - [x] `run_loop()` — bounded for-loop; decorates loop_step/attempt_budget; execution_result→observe→budget-check→continue; terminal→retry gate (RUNLOOP/LOOP_STEP/BUDG_CHECK) — clean
  - [x] `finalize_terminal_result()` / `finalize_and_persist_result()` — single persist funnel — clean
  - [x] `maintain_clarification_origin_task()` / `is_blocking_clarification()` / `latest_user_message_text()` — clarification-origin memory; capped 600 chars — clean
  - [x] `apply_finalization_strategy()` / `apply_template_only_finalization()` / `apply_synchronizer_message_polish()` — classifier-routed; best-effort sync with throwable guard; R3 irreversibility / R2 affected-scope contract — clean
  - [x] `finalize_and_persist_budget_exceeded()` / `build_budget_exceeded_result()` (BUDGX) — deterministic, localized, template-only — clean
  - [x] `budget_guard_allows_next_llm_call()` — `($step+1) < $limit` (verified arithmetic) — clean
  - [ ] `resolve_framework_retry_issue_code()` / `resolve_exhausted_framework_retry_issue_code()` — see 03-F04 (LOW duplication); behaviour (LOOP_RETRYABLE) correct
  - [x] `has_r3_retry_blocker()` — R3 via issue code / risk_class / queue_risk_classes / per-command (R3-no-retry) — clean
  - [x] `has_active_non_planner_retry_signal()` — collision guard (LOOP_COLLIDE): RETRY_WAITING / PREFLIGHT/EXECUTION retry hints — clean
  - [x] `mask_step_observation_for_llm()` — anonymizes live observation before re-prompt unless every result is `observation_engine_static` (OBS_ACCUM privacy) — clean
  - [x] `build_framework_retry_observation()` (FW_RETRY_OBS) — engine-authored RETRY_HINT text — clean
  - [x] `enforce_final_response_contract()` — response-type allow-list, command-shape coercion, fence strip, array invariants, lang resolve — clean
  - [x] `strip_markdown_fences_from_message()` / `build_contract_fallback_message()` — clean
  - [x] `reclassify_abandoned_run_as_error()` (ABANDON_GUARD) — all-steps-failed→error, `RUN_ABANDONED_ALL_STEPS_FAILED` — clean
  - [x] `attach_loop_results()` / `persist_phase_trace_for_loop_step()` — CS15 `phase_trace_loop_history` capped at MAX_LOOP_STEPS — clean
  - [x] `run_internal()` / `extract_planner_context()` / `merge_planner_context()` / `call_orchestrator_step()` / `resolve_output_language()` — plan+decide, no persistence — clean

#### `classes/local/wizard/agent_state.php`  (final class `agent_state`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (pure in-memory value object, never persisted)
- methods:
  - [x] `__construct()` / `make()` / `record_step()` / `get_observations()` / `append_observation()` / `get_steps()` / `step_count()` — clean
  - [ ] `has_observations()` — see 03-F05 (LOW, zero in-repo caller)
  - [x] `get_discovery_family_cache()` / `set_discovery_family_cache()` / `get_cache_entry()` / `set_cache_entry()` — per-run phase cache — clean

#### `classes/local/wizard/interpreter.php`  (class `interpreter` implements `agent_interpreter`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (trust boundary; structural-only validation, no DB; fence regex dup → C3-F05)
- methods:
  - [x] `__construct()` — clean
  - [x] `interpret()` — parse→classify→passthrough/validate; empty-message contract codes; recoverable-input→clarification — clean
  - [x] `interpret_phase_output()` / `interpret_selection_phase_output()` / `enforce_phase_contract()` — phase contracts (single-command selection, allow-listed construction skills, discovery no-commands) — clean
  - [x] `normalize_commands_payload()` / `unwrap_redundant_input_envelope()` / `prune_empty_input_values()` — envelope unwrap + empty-scalar prune (keeps 0/false) — clean
  - [x] `with_optional_next_step_intent()` / `looks_like_completed_action_intent()` — completed-action guard for next_step_intent — clean
  - [x] `normalize_skill_like_response()` — heals skill-like/missing response_type; registry allow-list checked — clean
  - [x] `safe_string()` / `error_result()` / `error_result_with_issue_code()` — clean
  - [x] `clarification_message()` / `confirmation_message_from_ambiguities()` / `extract_command_input()` — clean
  - [x] `parse()` / `sanitize_json_payload()` / `truncate_parse_excerpt()` — BOM strip, fence strip, strip_tags, `{`…`}` guard, 200-char excerpt — clean (fence regex duplicated, C3-F05)
  - [x] `extract_used_triggers()` — server-derived triggers via registry — clean
  - [x] `validate_commands()` — pure structural; selector/constructor/contract-validator; dedup by skill|input; governance deny mapping — clean
  - [x] `user_facing_validation_message()` / `strip_command_prefix()` / `normalize_phase_name()` — clean

#### `classes/agent.php`  (class `agent extends bookingextension`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (plugininfo entry point, framework-invoked)
- methods:
  - [x] `get_plugin_name()` / `contains_option_fields()` / `get_option_fields_info_array()` — clean
  - [x] `load_settings()` — `require` guarded by try/catch so a settings error cannot abort the extension loop — clean
  - [x] `load_data_for_settings_singleton()` / `set_template_data_for_optionview()` / `add_options_to_col_actions()` / `get_allowedruleeventkeys()` / `get_booking_history_description()` — contract stubs (framework-invoked) — clean

#### `classes/local/wizard/ai_error_classifier.php`  (class `ai_error_classifier`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (static classifier; callers: orchestrator:402, planner_phase_service:756)
- methods:
  - [x] `classify_from_response()` — 401→TRIAL_TOKEN_INVALID, 429→AI_PROVIDER_QUOTA_EXCEEDED, then lowercase marker scan; no secrets logged — clean

#### `classes/local/wizard/services/messaging/message_persistence_service.php`  (class `message_persistence_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `__construct()` — clean
  - [x] `persist_assistant_message()` — single idempotent assistant-message write; normalizes phase_trace; persists next_step_intent (falls back to selection intent); writes planner_trace_history/phase_trace metadata — clean

#### `classes/local/wizard/services/conversation_thread_memory.php`  (class `conversation_thread_memory` implements `thread_memory`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (engine adapter; caller: base_skill:205)
- methods:
  - [x] `__construct()` / `get_value()` / `set_value()` — active-thread metadata adapter — clean

#### `classes/local/wizard/services/completed_command_history_service.php`  (class `completed_command_history_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 n/a D5 [x] D6 — file-level (normalize_input/value dup → 03-F03)
- methods:
  - [x] `__construct()` — clean
  - [x] `extract_from_messages()` — reconstructs executed commands from assistant payloads; caps 12 — clean
  - [x] `merge_from_queue()` — queue-authoritative; excludes `__placeholder__`; thread-fenced; dedup; caps 12 — clean
  - [x] `build_signature()` — ksort + sha256 — clean
  - [ ] `normalize_input()` / `normalize_value()` — see 03-F03 (MEDIUM duplication)

#### `classes/local/wizard/services/execution_observation_ledger.php`  (class `execution_observation_ledger`)
- [x] D1 [x] D2 [x] D3 [ ] D4 n/a D5 [x] D6 — file-level (normalize_input/value dup → 03-F03)
- methods:
  - [x] `__construct()` — clean
  - [x] `append_from_results()` — canonical observation entries; dedup by signature; `engine_static` flag preserved; caps 100 — clean
  - [x] `get_recent_for_runtime()` — compact recent entries (default 12) — clean
  - [x] `read_entries()` / `build_signature()` — clean
  - [ ] `normalize_input()` / `normalize_value()` — see 03-F03 (MEDIUM duplication)

#### `classes/local/wizard/services/observation_time.php`  (class `observation_time`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level
- methods:
  - [x] `format()` — `userdate()` + `strftimedatetimeshort`; 0/negative → `get_string('never')` — clean

---

## D. Go-live blockers from this section

None. No BLOCKER or HIGH finding in this section.

Recommended (not gating): close 03-F01 (make `consume_pending_intent` fail-closed and always stamp
userid/contextid at write time — cheap, removes the only fail-open in the store) and 03-F03 (share the
privacy drop-key normalizer so the redaction list cannot drift between the two ledger services).
