# Audit Section 10 — Synchronizer & Reply Contract

**Scope:** `classes/local/wizard/services/{synchronizer_input_builder, synchronizer_output_contract, synchronizer_prompt_builder, synchronizer_routing_service, language_policy_service, localized_string_service, runtime_context_block_builder, trigger_result_util, assistant_state_guidance_service, orchestrator_prompt_profile_service}.php`  ·  **Files audited:** 10  ·  **Methods audited:** 41
**Arch chapter(s):** docs/architecture/12-synchronizer.md  ·  **Flowchart nodes:** SYNC (SYNC_RUN, SYNC_CTX, SYNC_PPB, SYNC_LANG, SYNC_GATE, SYNC_ROUTE, SCONTRACT, LG_SYNC, LG_RISK_SYNC)
**Auditor verdict:** ⚠️ issues (no blocker)

The synchronizer contract is implemented faithfully and defensively. The `commands=[]` rule,
the semantic-drift rollback set (`SYNC_*_REJECTED`), the error-faithfulness flag
(`error_presentation_requested`), the `llm_polish`-only entry, the language-follows-latest-message
policy, and the R3/R2 reply requirements are all present in code and match both the architecture
chapter and the flowchart. Findings are limited to one MEDIUM correctness gap in the fact-conflict
guard, plus low-severity style/duplication cruft. No security issue, no SQL, no IDOR surface in
this cluster (these services receive already-authorized threadid/contextid/userid from
`agent_runtime`; no direct external entry point lives here).

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass   | No DB access, no external entry, no output rendering in these services; ids arrive pre-authorized from `agent_runtime`/`orchestrator`. Anonymizer is applied to completed_commands/observations in `runtime_context_block_builder`. YAML/JSON values are quote-escaped. One INFO note on the fact-conflict regex being best-effort. |
| D2 Moodle API      | pass   | `get_string` via `localized_string_service` (correct `force_current_language` switch + `finally` restore); all 6 sync-relevant string ids exist in `lang/en`; `get_config`, `context::instance_by_id`, `get_fast_modinfo`, `format_string` used correctly. One LOW docblock/phpcs defect (orphan docblock). |
| D3 Structure       | issues | One orphan/mis-attached docblock (10-F02). All 10 classes have real callers (verified by tree grep). Clean SRP; no engine→domain leak (no `mod_booking.*` heuristics in these services). |
| D4 Duplication     | issues | `json_encode_or_empty` duplicated (cross-ref C3-F04); `normalize_nonempty_string_list` exists in three places with near-identical bodies (10-F03). |
| D5 Flowchart       | pass   | Behaviour matches SYNC subgraph node-for-node; SCONTRACT, SYNC_LANG, SYNC_ROUTE, LG_RISK_SYNC all confirmed. No behavioural deviation. |
| D6 Docs coverage   | pass   | ch.12 is accurate (corroborated by C5-docs-coverage.md row `12-synchronizer`: "accurate"). One material behaviour under-documented: R2-missing degrades softer than R3-missing (10-F01 note / 10-F04). |

## B. Findings

### [10-F01] 🟡 MEDIUM · D1 Security · `services/synchronizer_output_contract.php:124-136`
**What:** The fact-conflict guard `has_fact_conflict_with_source()` only verifies that the *single latest* source option id survives into the sync message; a multi-option (bulk) source result can have earlier-created ids silently dropped or swapped by the polish without triggering `SYNC_FACT_CONFLICT_REJECTED`.
**Evidence:** `extract_latest_source_option_id()` walks results newest-first and returns `(int)end($ids)` of the *first* row that contains any id (line 161), i.e. exactly one id. `has_fact_conflict_with_source()` then only checks `!in_array($latestsourceoptionid, $syncoptionids, true)` (line 135). If a bulk skill created options 41, 42, 43 and the polish text only mentions 41 and 99, the guard sees 43 present-or-absent only; a message that mentions 41 but omits 43 *would* be caught, but one that mentions a fabricated 99 alongside 43 passes, and ids other than the latest are never required to be present.
**Impact:** A polished reply for a bulk mutation could under-report or mis-attribute created entity ids without the contract rolling back to planner output. The execution itself is unaffected (semantics are not mutated — only the user-facing message), so this is a message-fidelity gap, not a mis-execution.
**Compensating control:** Strong but partial: the `[OUTPUT_CONTRACT]` FACT PRIORITY block instructs the model to treat observations as authoritative and never re-assert stale/contradicted success details, and per-skill post-mutation verification (project memory `agent_post_mutation_verification`) deterministically re-checks the mutation outcome upstream. The latest-id case (the most common single-create path) *is* enforced.
**Recommendation:** Generalise the guard to extract *all* source option ids (not just the latest) and require each that the sync message references-or-should-reference to be present, or at minimum reject when the sync message introduces an option id that is absent from the source facts. Document the "latest-only" limitation in ch.12 §5 if the broader check is deferred.

### [10-F02] 🟢 LOW · D2 Moodle API · `services/synchronizer_output_contract.php:282-300`
**What:** An orphan docblock ("Append deterministic issue code to result payload.") at lines 282-289 is not attached to the method it precedes — the very next line opens a *second* docblock (lines 289-295) that actually documents `apply_sync_message()`. The stray block is a leftover from a method move/rename.
**Evidence:** Lines 282-288 describe `@param array $payload / @param string $issuecode / @return array` (the signature of `with_issue_code`, defined later at line 309), but are immediately followed by `/** Apply the sync message … */ private function apply_sync_message(...)`. Two consecutive docblocks with no code between them will trip `moodle` phpcs (`Comment` / `MissingDocblock` style group) and misleads readers.
**Impact:** phpcs noise (likely a warning/error, blocking the project's "0 errors / 0 warnings" gate) and minor confusion; no runtime effect.
**Compensating control:** None needed — purely cosmetic.
**Recommendation:** Delete the orphan docblock at lines 282-289; keep only the `apply_sync_message` block.

### [10-F03] 🟢 LOW · D4 Duplication · `services/synchronizer_input_builder.php:296-306` + `services/assistant_state_guidance_service.php:39-57`
**What:** `normalize_nonempty_string_list()` is implemented with near-identical bodies in `synchronizer_input_builder` (private, no caps) and `assistant_state_guidance_service` (public, with `$maxitems`/`$maxchars` caps); a third trim-dedup variant also appears in `synchronizer_output_contract::extract_option_ids` post-processing.
**Evidence:** `synchronizer_input_builder.php:296` returns `array_values(array_unique($normalized))` of trimmed non-empty strings — a strict subset of `assistant_state_guidance_service::normalize_nonempty_string_list()` (line 39) with `$maxitems=0,$maxchars=0`.
**Impact:** Trivial drift risk; both are pure.
**Compensating control:** Both private/pure; behaviour is stable.
**Recommendation:** Have `synchronizer_input_builder` delegate to the existing `assistant_state_guidance_service::normalize_nonempty_string_list($values)` (already the public, more general home) and drop the private copy. (See also cross-cutting C3-F04 for the parallel `json_encode_or_empty` duplication in `runtime_context_block_builder.php:522`.)

### [10-F04] ⚪ INFO · D6 Docs coverage · `agent_runtime.php:464-481` vs ch.12 §5
**What:** Confirmed-correct asymmetry worth a one-line doc note: an R3 `sufficient` reply with a missing `irreversibility_notice` **rolls back to planner output** (`return $result` at line 468, merge never runs), whereas an R2 `sufficient` reply with a missing `affected_scope_summary` **still accepts the polished message** and only appends the `SYNC_AFFECTED_SCOPE_SUMMARY_MISSING` issue code (lines 471-479, then `merge()` runs).
**Evidence:** `apply_synchronizer_message_polish()` — R3 path returns early on empty notice; R2 path mutates `issue_codes` then falls through to `merge($result, $syncresult)`.
**Impact:** None (this matches ch.12 §5 — "absent → keep planner output" for R3, "absent → tag …MISSING" for R2 — and the flowchart SCONTRACT/LG_RISK_SYNC nodes). Documenting the *reason* for the different severities (R3 is irreversible, so a missing warning is fail-closed; R2 is reversible, so a tagged telemetry note suffices) would help future maintainers.
**Compensating control:** n/a (correct behaviour).
**Recommendation:** Add one sentence to ch.12 §5 clarifying that R3-missing is fail-closed (rollback) while R2-missing is fail-open-with-telemetry, by design.

### [10-F05] ⚪ INFO · D1 Security · `services/synchronizer_output_contract.php:180-197`
**What:** `extract_option_ids()` uses a broad regex `/(?:option\s*id\s*=\s*|id\s*=\s*|optionid\s*=\s*)(\d+)/i` to harvest ids from free text; the bare `id=` alternative can match unrelated key/value text (e.g. `courseid=`, `cmid=` would NOT match because of word boundary, but `userid=` matches the `id=` tail).
**Evidence:** The pattern has no leading `\b`, so `userid=7` matches the `id\s*=` branch and yields 7.
**Impact:** Negligible — it only feeds the latest-id fact-conflict comparison (10-F01), which is itself best-effort; a false id at worst causes an over-eager rollback to the (safe) planner output, never a missed rejection that mis-executes anything. The error degrades safe.
**Compensating control:** Rollback target is the deterministic planner output; over-rejection is harmless.
**Recommendation:** Optionally anchor the `id=` branch (`/\boption(?:_?id)?\s*=\s*(\d+)/i`) when 10-F01 is addressed, so the harvested set is precise.

## C. Per-file / per-method checklist

#### `services/synchronizer_output_contract.php` (class `synchronizer_output_contract`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (D4 see C3-F04 cross-ref)
- methods:
  - [ ] `merge(array $source, array $sync)` — see 10-F01 (D1 fact-conflict latest-only); enforcement-mode branching, commands=[] reject, source-conflict reject all clean
  - [ ] `private has_fact_conflict_with_source()` — see 10-F01 (D1)
  - [x] `private extract_latest_source_option_id()` — D1✓ D3✓ (newest-first walk correct)
  - [ ] `private extract_option_ids()` — see 10-F05 (D1, INFO)
  - [x] `private reject_reason()` — D1✓ D5✓ (response_type=error, CONTRACT_*, parse/raw-excerpt rejects match SCONTRACT)
  - [x] `private source_conflict_reason()` — D1✓ D5✓ (error_presentation_requested honoured per flowchart ERROR-FAITHFULNESS GUARD)
  - [x] `private latest_source_result_is_error()` — clean
  - [ ] `private apply_sync_message()` — see 10-F02 (D2 orphan docblock above it)
  - [x] `private with_issue_code()` — D2✓ D3✓
  - [x] `private with_gate_telemetry()` — clean

#### `services/synchronizer_input_builder.php` (class `synchronizer_input_builder`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `build_observations()` — D1✓ D3✓ D5✓ (state-first, loop_results fallback, source/phase/exec/error observations appended — matches SYNC_CTX)
  - [x] `private build_error_observation()` — D1✓ D5✓ (REQUIRES_PRO→[UPGRADE_REQUIRED], SKILL_DENIED→[UNAVAILABLE], else [ERROR] honest-reply; matches feedback_synchronizer_always_answers)
  - [x] `private build_phase_trace_observation()` — D1✓ (sanitized, no full schema)
  - [x] `private sanitize_phase_trace_snapshot()` — clean
  - [x] `private build_execution_feedback_observation()` — D5✓ (status counts + skills on confirm path)
  - [x] `private build_source_observation()` — D1✓ (message capped 600, whitespace-normalized)
  - [ ] `private normalize_nonempty_string_list()` — see 10-F03 (D4)

#### `services/synchronizer_prompt_builder.php` (class `synchronizer_prompt_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `build_system_prompt()` — D2✓ D5✓ (placeholder stabilisation for prefix caching; admin summarise prefix merge guarded by is_default; {{bookingname}} alias retained)
  - [x] `build_prompt()` — D1✓ D2✓ D5✓ (cache ordering [SYSTEM]→[SYSTEM_RUNTIME]→history→[SYSTEM_RUNTIME_STATE]→observations→[OUTPUT_CONTRACT]→[ASSISTANT]; commands=[] + FACT PRIORITY + CLARIFICATION RELAY + PENDING STEPS + LINK/ENTITY policies; PRO_LICENSE_POLICY gated on `!has_full_access()`; pro url via get_string)

#### `services/synchronizer_routing_service.php` (class `synchronizer_routing_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (pure delegation to `orchestrator::process_synchronizer`; single LLM call confirmed; framework-invoked from agent_runtime)
  - [x] `call_synchronizer_step()` — D5✓ (SYNC_ROUTE)

#### `services/language_policy_service.php` (class `language_policy_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `normalize_iso_language()` — D2✓ (core_text::strtolower, ISO-639-1 guard)
  - [x] `resolve_output_language()` — D5✓ (user_lang → lang → current_language → 'en'; matches SYNC_LANG "follows latest user message", no de/en token routing)
  - [x] `fallback_string_id_for_response_type()` — D2✓ (all 4 ids exist in lang/en)
  - [x] `preflight_retry_hint_string_id()` — D2✓ (id exists)

#### `services/localized_string_service.php` (class `localized_string_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
  - [x] `static get()` — D2✓ (force_current_language switch with `finally` restore; respects outputlang per feedback_all_strings_via_get_string)

#### `services/runtime_context_block_builder.php` (class `runtime_context_block_builder`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (D4: `json_encode_or_empty` dup — cross-ref C3-F04)
- methods:
  - [x] `__construct()` — DI of store/history/catalog services; clean
  - [x] `build()` — D1✓ D2✓ D5✓ (stable/volatile split; synchronization channel → full moodle_context; anonymizer applied to completed_commands & observations; ledger dedup vs live observations; now_iso last)
  - [x] `private append_moodle_context_section()` — D1✓ D2✓ (format_string + yamlsafe quote-escape; get_fast_modinfo MUC-backed; Throwable-guarded)
  - [x] `private append_page_context_section()` — D1✓ (yamlsafe escapes `\\ " \n \r`; best-effort hint, "not authorization" noted; Throwable-guarded)
  - [x] `private describe_page_family()` — clean (pure mapping)
  - [x] `private memory_channel_for_phase()` — clean
  - [x] `private append_user_memory_section()` — D1✓ (thread-owner userid resolved; per-channel filter)
  - [x] `private append_json_object_section()` — clean
  - [x] `private append_json_list_section()` — clean
  - [ ] `private json_encode_or_empty()` — see C3-F04 (D4 cross-cutting dup)
  - [x] `private normalize_for_observation_dedup()` — clean

#### `services/trigger_result_util.php` (class `trigger_result_util`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (real callers in `agent_decision_service`; pure)
  - [x] `static has_trigger()` — clean

#### `services/assistant_state_guidance_service.php` (class `assistant_state_guidance_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (D4 see 10-F03)
  - [ ] `normalize_nonempty_string_list()` — see 10-F03 (canonical home; duplicated in synchronizer_input_builder)

#### `services/orchestrator_prompt_profile_service.php` (class `orchestrator_prompt_profile_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `observations_are_framework_retry_hints()` — clean
  - [x] `get_planner_initial_prompt_config_key_for_phase()` — D2✓
  - [x] `get_history_limit_for_phase()` — clean
  - [x] `select_history_messages()` — D3✓ (preserves first user message + tail window; relevant to synchronizer history injection)
  - [x] `normalize_config_prompt_template()` — D2✓ (CRLF/LF normalization for seeded-default detection)
  - [x] `private normalize_phase()` — clean

## D. Go-live blockers from this section
None. No BLOCKER and no HIGH findings. The one MEDIUM (10-F01, latest-only fact-conflict guard) is a message-fidelity gap on the bulk-create path, defended by the FACT PRIORITY prompt contract and upstream post-mutation verification — it does not gate launch but should be scheduled. Remaining items are LOW (orphan docblock, duplication) and INFO.
