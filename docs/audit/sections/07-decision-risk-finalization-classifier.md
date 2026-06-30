# Audit Section 07 — Decision, Risk & Finalization Classifier

**Scope:** `classes/local/wizard/services/decision/agent_decision_service.php`,
`services/risk/risk_class_resolver.php`, `services/retry_policy_service.php`,
`services/finalization_classifier.php`, `services/finalization_template_service.php`,
`dto/skill_risk_class.php`, `services/issue_code_normalizer.php`, `booking_issue_code_provider.php`
(+ its `interfaces/issue_code_provider_interface.php`)
· **Files audited:** 8 · **Methods audited:** 41
**Arch chapter(s):** docs/architecture/08-decision-service.md + 13-finalization-and-observations.md + 15-risk-classes.md
**Flowchart nodes:** `DECIDSVC`, `D_PROMOTE`, `D_TARGET_NOTE`, `LG_MATRIX`, `LG_RISK*`, `LG_CLASS`
**Auditor verdict:** ⚠️ issues (no blocker)

The core safety spine is sound. The guard-chain order (preview → pending → lookup → promotion),
the fail-safe unknown-skill → R3 default, the risk→confirmation mapping, the finalization
precedence cascade, and the retryable-category set (TECHNICAL + EXTERNAL_DEPENDENCY) all match
the architecture and the flowchart. Issues are concentrated in D2 (un-localised English
fallback strings reaching users), D4 (the retry/error-classifier code-map duplication already
flagged cross-cutting), and minor D5/D6 doc-lag. No engine→domain leak: the decision service
sources domain issue codes through the injected `issue_code_provider_interface`.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | No `$DB`/SQL in scope (all DB access delegated to store/queue); context resolved via core `context::instance_by_id(IGNORE_MISSING)`; course name escaped with `format_string`; read-only auto-exec deanonymizes before lookup; guard token bound to operating context. No IDOR/CSRF surface here (entry-point authz is upstream). |
| D2 Moodle API      | issues | All `localized_string_service` keys exist in `lang/en|de`. BUT several template-only issue codes always emit **hard-coded English** (07-F01). `booking_issue_code_provider.php` omits `declare(strict_types=1)` (07-F05). |
| D3 Structure       | issues | Clean DI, single-responsibility services, centralised risk resolver. 4 of 6 `issue_code_provider_interface` methods have **zero callers** engine-wide (07-F04, contract methods → not dead, but over-specified). |
| D4 Duplication     | issues | `retry_policy_service::resolve_retry_hint_category()` substring map overlaps `preflight_error_classifier::infer_from_issue_codes()` (07-F02, corroborates C3-F02). |
| D5 Flowchart       | issues | `D_PROMOTE` names a stale method `resolve_command_risk_class()` (now `risk_class_resolver::resolve_for_command()`) — doc-lag (07-F03). Behaviour otherwise matches. |
| D6 Docs coverage   | issues | ch.13 §2 lists 5 `TEMPLATE_ERROR_CLASSES`; code has 7 (`provider_error`, `internal_status` added) — doc omission (07-F06). `retry_policy_service` circuit-breaker behaviour undocumented in any chapter (07-F07). |

---

## B. Findings

### [07-F01] 🟡 MEDIUM · D2 Moodle API · `services/finalization_template_service.php`:30-46,108-111
**What:** Several template-only finalization messages are emitted as hard-coded English regardless of `outputlang`, because their issue codes have no entry in `ISSUE_CODE_LANG_KEYS` (only `PERMISSION_ERROR` is mapped to a lang key).
**Evidence:** `resolve_message()` (line 100) first consults `ISSUE_CODE_LANG_KEYS` — which contains only `'PERMISSION_ERROR' => 'error_ai_permission_denied'`. For `BUDGET_EXCEEDED`, `BLOCKED_TIMEOUT`, `RETRY_EXHAUSTED`, `VALIDATION_ERROR`, `CONTEXT_INVALID`, `CONTRACT_SELECTION_SKILL_MISSING` it falls straight to the literal English strings in `ISSUE_CODE_MESSAGES` (lines 30-46, e.g. `'BUDGET_EXCEEDED' => 'Execution stopped because the loop budget is exhausted…'`). These are user-facing terminal messages.
**Impact:** A German (or any non-English) user who hits a budget/timeout/retry-exhausted/validation/context-invalid terminal state receives an English sentence, violating the project rule "every user-visible string via `get_string`, bound to `outputlang`" (memory `feedback_all_strings_via_get_string`). The error-class fallbacks (`ERROR_CLASS_MESSAGES`) are partly covered by `ERROR_CLASS_LANG_KEYS`, but the issue-code path is not.
**Compensating control:** `error_class`-driven terminals (provider_timeout, quota_exceeded, etc.) are localised; only the issue-code-only terminals leak English. The strings are correct, just monolingual.
**Recommendation:** Add lang keys for the six issue codes to `ISSUE_CODE_LANG_KEYS` (mirroring `ERROR_CLASS_LANG_KEYS`) and keep the English arrays as the last-resort fallback only.

### [07-F02] 🟡 MEDIUM · D4 Duplication · `services/retry_policy_service.php`:72-117 (corroborates C3-F02)
**What:** The substring-matching issue-code → category map in `retry_policy_service::resolve_retry_hint_category()` duplicates the parallel classification in `preflight_error_classifier::infer_from_issue_codes()`, with overlapping-but-not-identical token sets that can drift.
**Evidence:** `resolve_retry_hint_category()` matches `VALIDATION|CONFLICT|DOMAIN|MISSING_|PERMISSION → DOMAIN`, `TIMEOUT|TRANSIENT|CONTRACT_|PARSE|SELECTION|RETRY_WAITING|EXECUTION_GUARD → TECHNICAL`, `AUTH|QUOTA|RATE_LIMIT|PROVIDER|EXTERNAL → EXTERNAL_DEPENDENCY` (lines 72-101); the preflight classifier maintains its own overlapping list (per cross-cutting C3-F02). Two services each "know" codes the other does not.
**Impact:** A new issue code added to one map but not the other yields inconsistent retry/error-class behaviour. Maintainability risk, not a live bug.
**Compensating control:** `integration_agent_framework_test` (lines 756-800) and `preflight_layers_contract_test` pin current behaviour, so drift would surface as a test diff.
**Recommendation:** Extract one canonical `issue_code → {error_class, retry_category}` table consumed by both (same fix C3-F02 / 08-F01 recommends).

### [07-F03] 🟢 LOW · D5 Flowchart · `D_PROMOTE` node (flowchart line 178) vs `risk_class_resolver`
**What:** The `D_PROMOTE` node label names the method `resolve_command_risk_class()`, which no longer exists; risk resolution was extracted to `risk_class_resolver::resolve_for_command()` (the documented LG_RISK centralisation point).
**Evidence:** Flowchart `D_PROMOTE` reads "`risk_class via resolve_command_risk_class()`"; the decision service calls `risk_class_resolver::resolve_for_command($command, $this->registry)` (agent_decision_service.php:1415,1443,1485). The flowchart-guide already records the resolver consolidation but the node label was not updated.
**Impact:** Doc-lag only; the unknown-skill → R3 fail-safe annotation on the node is behaviourally correct.
**Compensating control:** Behaviour matches the diagram's intent; only the method name is stale.
**Recommendation:** Relabel `D_PROMOTE` to `risk_class_resolver::resolve_for_command()` (per flowchart policy: report, don't silently reconcile).

### [07-F04] ⚪ INFO · D3 Structure · `interfaces/issue_code_provider_interface.php` + `booking_issue_code_provider.php`
**What:** Four of the six interface methods have zero callers anywhere in `classes/`: `get_duplicate_confirmation_issue_codes()`, `get_token_subscription_issue_codes()`, `get_basic_subscription_url()`, `get_premium_subscription_url()`.
**Evidence:** `grep -rn` over `classes/` (whole tree) finds callers only for `get_prevalidation_confirmable_issue_codes()` (agent_decision_service.php:1529, preflight_domain_check_runner.php:83). The other four resolve to no in-repo consumer.
**Impact:** None functionally — these are declared contract methods (excluded from "dead code" per the audit guardrail). But the interface is over-specified relative to actual use, and the hard-coded showroom subscription URLs (booking_issue_code_provider.php:81,90) ship unused.
**Compensating control:** Interface contract; harmless.
**Recommendation:** Either wire the subscription-URL / token-code methods into the trial/upgrade messaging path, or trim them from the interface to keep the engine→domain boundary minimal.

### [07-F05] 🟢 LOW · D2 Moodle API · `booking_issue_code_provider.php`:17
**What:** The file omits `declare(strict_types=1)` while every other in-scope class declares it.
**Evidence:** The file jumps from the GPL header straight to `namespace bookingextension_agent\local\wizard;` with no `declare` line (unlike e.g. `finalization_classifier.php:17`, `risk_class_resolver.php:25`).
**Impact:** Inconsistent strictness; trivial.
**Compensating control:** Methods return well-typed arrays/strings; low coercion risk.
**Recommendation:** Add `declare(strict_types=1);` for consistency (verify against the deliberate orchestrator exception noted in `feedback_strict_types_extraction_coercion` — that exception does not apply here).

### [07-F06] 🟢 LOW · D6 Docs coverage · docs/architecture/13-finalization-and-observations.md §2
**What:** ch.13 lists `TEMPLATE_ERROR_CLASSES` as five entries (`provider_timeout, transient_io, auth_failed, quota_exceeded, runtime_disabled`); the code has seven, adding `provider_error` and `internal_status`.
**Evidence:** `finalization_classifier.php:80-91` includes the two extra classes with an explanatory comment ("Provider classes never route to the synchronizer…"). The chapter's §2 set and §4 template mapping do not mention them.
**Impact:** Doc omission; behaviour is correct and intentional. The `LG_MATRIX` flowchart node lists representative subsets (flowchart-guide already acknowledges "added detail").
**Compensating control:** None needed; behavioural correctness is unaffected.
**Recommendation:** Append `provider_error` and `internal_status` to ch.13 §2's `TEMPLATE_ERROR_CLASSES` list.

### [07-F07] ⚪ INFO · D6 Docs coverage · `services/retry_policy_service.php`:140-174
**What:** `evaluate_provider_circuit_breaker()` (auth/quota → non-retryable terminal with `PROVIDER_CIRCUIT_OPEN_*`) is in the audited scope but is not described in any architecture chapter.
**Evidence:** Called by `preflight_execution_gate.php:55` and `queue_transition_service.php:278`; ch.15 documents retryable *categories* but not the circuit-breaker terminalisation.
**Impact:** None; behaviour is correct and test-pinned (integration_agent_framework_test.php:792-800).
**Compensating control:** Tests cover it.
**Recommendation:** One sentence in ch.15 §4 or ch.16 noting the provider circuit-breaker.

---

## C. Per-file / per-method checklist

#### `services/decision/agent_decision_service.php`  (class `agent_decision_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (no SQL; context validated; guard chain order matches `DECIDSVC`)
- methods:
  - [x] `__construct()` — D1✓ D2✓ D3✓ (DI of registry/store/authz; default `booking_issue_code_provider` is the documented engine-default, no domain leak)
  - [x] `process()` — guard chain preview→pending→lookup→promotion verified against ch.08 §2 and `DECIDSVC`
  - [x] `should_block_new_intent_while_pending()` — clean (matches guard #2)
  - [x] `build_pending_resolution_clarification()` — clean (queue is single source of truth)
  - [x] `build_pending_intent_summary()` — clean
  - [x] `build_operating_context_note()` — clean (ambient fallback so target always named; matches `D_TARGET_NOTE`)
  - [x] `describe_target_context()` — D1✓ `format_string($course->fullname)` escaped; `get_course()` in IGNORE_MISSING-guarded try/catch
  - [x] `build_fallback_message()` — clean (skill-schema fallback string keys)
  - [x] `handle_confirm_pending()` — clean (re-runs preflight, refreshes prepared_input + guard tokens; matches ch.08 §4)
  - [x] `handle_command_routing()` — clean (R0 inline / mutating → confirmation_request; DAG validation on depends_on)
  - [x] `handle_preflight()` — clean (delegates risk differentiation to `queue_transition_service`; target note appended post-resolve)
  - [x] `apply_execution_guard_tokens()` — D1✓ token bound to operating contextid via `preflight_execution_gate::build_guard_token`
  - [x] `persist_pending_intent_pointer()` — clean
  - [x] `resolve_queue_item_risk_classes()` — clean (invalid → R3 fail-safe, line 1102)
  - [x] `execute_readonly_commands()` — D1✓ deanonymizes input before lookup; atomic `try_mark_running`; exception → empty message (synchronizer presents cause)
  - [x] `inject_output_language_into_commands()` — clean
  - [x] `with_output_language()` — clean (restores language in finally)
  - [x] `has_mutating_commands()` — clean (R0 vs non-R0 via resolver)
  - [x] `split_commands_by_risk_class()` — D1✓ non-array/unknown → R3 (line 1440)
  - [x] `split_commands_by_mutability()` — clean (R1/R2/R3 merged to mutating; differentiation downstream)
  - [x] `inject_risk_class_into_commands()` — clean
  - [x] `execution_result_has_failures()` — clean
  - [x] `has_confirmable_prevalidation_issues()` — D3✓ sources codes from injected provider (no domain leak)
  - [x] `clarification_result()` / `clarification_result_with_context()` — clean
  - [x] `build_confirm_pending_no_intent_fallback()` — clean
  - [x] `localized()` / `normalize_queue_item_ids()` — clean

#### `services/risk/risk_class_resolver.php`  (class `risk_class_resolver`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (LG_RISK centralisation point; fail-safe R3)
- methods:
  - [x] `normalize()` — invalid/empty → R3 (test-pinned)
  - [x] `resolve_for_command()` — command class → skill class → R3 cascade (matches ch.08 §3)
  - [x] `rank()` — R0=0…R3=3, default 3

#### `services/retry_policy_service.php`  (class `retry_policy_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [ ] D6 — see 07-F02 (D4), 07-F07 (D6)
- methods:
  - [ ] `resolve_retry_hint_category()` — see 07-F02 (substring map duplicates preflight_error_classifier)
  - [x] `is_retryable_category()` — TECHNICAL + EXTERNAL_DEPENDENCY only (matches ch.15 §3)
  - [ ] `evaluate_provider_circuit_breaker()` — correct + test-pinned; see 07-F07 (undocumented)

#### `services/finalization_classifier.php`  (class `finalization_classifier`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (LG_CLASS / LG_MATRIX precedence cascade matches code rule-for-rule)
- methods:
  - [x] `classify()` — direct_final > template_only > llm_polish; constant sets verified
  - [x] `requires_irreversibility_notice()` — sufficient + explicit R3 (ch.13 §3)
  - [x] `requires_affected_scope_summary()` — sufficient + explicit R2 (ch.13 §3)
  - [x] `resolve_explicit_risk_class()` — explicit-only (no implicit inference)
  - [x] `has_commands()` — list + single-assoc handling
  - [x] `contains_any()` — clean

#### `services/finalization_template_service.php`  (class `finalization_template_service`)
- [ ] D1 [ ] D2 [x] D3 [x] D4 [x] D5 [x] D6 — see 07-F01 (D2: un-localised issue-code messages)
- methods:
  - [ ] `resolve_message()` — D1✓ raw error details gated behind `is_siteadmin()` (good); D2 issue-F01 (only PERMISSION_ERROR has a lang key, others emit English)

#### `dto/skill_risk_class.php`  (class `skill_risk_class`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — pure DTO; constants R0-R3 + `is_valid()` match ch.15 §1

#### `services/issue_code_normalizer.php`  (class `issue_code_normalizer`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — `normalize()` (coerces) / `from_result()` (array-guarded) both test-pinned (result_normalizers_test)

#### `booking_issue_code_provider.php`  (class `booking_issue_code_provider` impl `issue_code_provider_interface`)
- [x] D1 [ ] D2 [x] D3 [x] D4 n/a D5 [x] D6 — see 07-F05 (no `declare(strict_types=1)`); 07-F04 (4/6 methods uncalled)
- methods:
  - [x] `get_prevalidation_confirmable_issue_codes()` — used (decision + preflight_domain_check_runner)
  - [ ] `get_duplicate_confirmation_issue_codes()` — 07-F04 (zero callers; contract method)
  - [ ] `get_token_subscription_issue_codes()` — 07-F04 (zero callers)
  - [ ] `get_basic_subscription_url()` / `get_premium_subscription_url()` — 07-F04 (zero callers; hard-coded showroom URLs)

#### `interfaces/issue_code_provider_interface.php`  (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — declared contract; over-specified (07-F04)

---

## D. Go-live blockers from this section

None. No BLOCKER or HIGH findings. The decision/risk/finalization spine is fail-safe and
matches the architecture; the open items (07-F01 localisation, 07-F02 duplication) are
quality/i18n fixes scheduled post-launch, and 07-F03/F06 are doc-lag.
