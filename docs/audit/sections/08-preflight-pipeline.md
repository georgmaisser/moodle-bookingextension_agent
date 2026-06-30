# Audit Section 08 — Preflight Pipeline

**Scope:** `classes/local/wizard/services/preflight_pipeline.php`, `preflight_schema_validator.php`, `preflight_version_validator.php`, `preflight_contract_validator.php`, `preflight_domain_check_runner.php`, `preflight_execution_gate.php`, `preflight_error_classifier.php`, `services/noop_external_dependency_checker.php`, `services/retry_policy_service.php`, `dto/preflight_result_v2.php`, `preflight_clarification.php`, `interfaces/external_dependency_checker_interface.php`, `interfaces/issue_code_provider_interface.php` · **Files audited:** 12 · **Methods audited:** 33
**Arch chapter(s):** docs/architecture/09-preflight-pipeline.md · **Flowchart nodes:** PREFLIGHT (PP_RUN, PF_L1, PF_L2P, PF_L2D, PF_L3, PF_L3P, PF_L3CB, PF_L3_EXT, PRV2, EXC_GUARD)
**Auditor verdict:** ⚠️ issues (no blockers)

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | pass | Gate 2 (native caps) correctly enforced at the **operating** context before `skill::preflight` and re-checked by the executor backstop; guard token = `sha256(skill:operating_context:json(prepared_input))` with `hash_equals` verify, stable key-sorted normalization; no SQL here; all error strings derived from validated/internal data. One LOW: candidate names interpolated into a clarification string (D1-mitigated, see 08-F03). |
| D2 Moodle API      | issues | `noop_external_dependency_checker.php` lacks `declare(strict_types=1)` (every other in-scope file has it). Hand-rolled JSON-Schema validation rather than a schema library, but that is an accepted engine pattern. `get_string` used for user-facing clarification text. |
| D3 Structure       | issues | Two parallel issue-code classification maps (`preflight_error_classifier` vs `retry_policy_service`) that can drift; `infer_from_issue_codes` returning `''` for the no-signal case combined with the R2/R3 retry guard makes the L3 path effectively timeout/transient-only (matches docs, but the double `is_retryable_error_class` check inside `evaluate()` line 95 is redundant given the category gate). No engine→domain leak: booking-specific confirmable codes correctly injected via `issue_code_provider_interface`. |
| D4 Duplication     | issues | See 08-F01: overlapping substring-classification logic duplicated across `preflight_error_classifier::infer_from_issue_codes` and `retry_policy_service::resolve_retry_hint_category`. |
| D5 Flowchart       | pass | Behaviour matches PP_RUN / PF_L1 / PF_L2P / PF_L2D / PF_L3 / PRV2 / EXC_GUARD. Risk→layer gating (R0 none / R1 L1+L2 / R2 +L3 / R3 +ext), constants 500/200/4/4000, circuit-breaker auth/quota→hard_block, guard_token NOT on DTO, CONTEXT_TARGET_UNRESOLVED with candidate list — all confirmed against code. |
| D6 Docs coverage   | issues | Ch.09 is accurate on the layer model and the leak inversion, but omits two material behaviours: (a) the L2 **shared-timeout** path in `preflight_domain_check_runner` (elapsed > 500 ms → `retry_hint`/`DOMAIN_CHECK_TIMEOUT`, fires for ANY risk class incl. R0/R1, bypassing the documented "L3 only for R2/R3" gate); (b) the `!legacyvalid` → hard_block coercion in `run()` / `build_output()`. See 08-F02. |

## B. Findings

### [08-F01] 🟡 MEDIUM · D4 Duplication · services/preflight_error_classifier.php:46 + services/retry_policy_service.php:72
**What:** Two independent substring-matching classifiers over the same issue-code vocabulary live side by side and can silently drift.
**Evidence:** `preflight_error_classifier::infer_from_issue_codes()` matches `TIMEOUT`/`TRANSIENT_IO`/`PERMISSION`/`CONFLICT`/`VALIDATION`/`MISSING_` to error classes; `retry_policy_service::resolve_retry_hint_category()` matches an overlapping but NOT identical set (`VALIDATION|CONFLICT|DOMAIN|MISSING_|PERMISSION` → DOMAIN; `TIMEOUT|TRANSIENT|CONTRACT_|PARSE|SELECTION|RETRY_WAITING|EXECUTION_GUARD` → TECHNICAL; `AUTH|QUOTA|RATE_LIMIT|PROVIDER|EXTERNAL` → EXTERNAL_DEPENDENCY). The classifier knows nothing of `CONTRACT_`/`PARSE`/`AUTH`/`QUOTA`, the policy knows nothing of the `transient_io` distinction. A new issue code added to one map but not the other yields inconsistent gating.
**Impact:** Maintenance hazard; a future code could be deemed retryable by one layer and terminal by the other, producing confusing retry behaviour. No current correctness defect — the pipeline only uses the classifier to *gate entry* to the gate and the policy to *categorise inside* it, and the current code values line up.
**Compensating control:** `preflight_layers_contract_test` and `preflight_pipeline_risk_class_contract_test` pin current behaviour.
**Recommendation:** Extract a single canonical issue-code → {error_class, retry_category} table (e.g. a shared map service) and have both consumers read it.

### [08-F02] 🟡 MEDIUM · D6 Docs coverage · services/preflight_domain_check_runner.php:57 + services/preflight_pipeline.php:269
**What:** Two real behaviours of the pipeline are absent from ch.09.
**Evidence:**
1. `preflight_domain_check_runner::run()` lines 58–68: if `elapsed_ms > SHARED_TIMEOUT_MS (500)` it returns `status=retry_hint, issue=['DOMAIN_CHECK_TIMEOUT'], retry_after=500` **before** classifying any issue code, and this is reachable for R0/R1 batches (the risk gate at pipeline:282 only governs the *execution gate*, not the domain runner). Ch.09 §3/§4 frames retry_hint as an L3/execution-gate outcome only.
2. `preflight_pipeline::run()` lines 269–278 and `build_output()` lines 364–367: when `errors` are present but the domain runner returned `pass`, the result is force-coerced to `hard_block` / `blocking_layer=domain`. This "legacy valid" override is the actual block source for many failures and is undocumented.
**Impact:** A reader of ch.09 would not predict a `retry_hint` from an R1 command, nor the error-driven hard_block coercion; both are load-bearing.
**Compensating control:** Behaviour is benign (retry on a slow read; block on accumulated errors). Covered by contract tests.
**Recommendation:** Add a short §3.1 documenting the L2 shared-timeout retry and the `!legacyvalid`→hard_block coercion.

### [08-F03] 🟢 LOW · D1 Security · services/preflight_pipeline.php:180-189
**What:** Ambiguous-target candidate `name`/`coursename` values are concatenated into a clarification message string returned to the user.
**Evidence:** Lines 181–189 build `'- ' . $name . ' (' . $coursename . ')'` from `$candidate['name']`/`['coursename']` (course/module display names) without escaping, then store it in `errors[]`/`issues[].message`.
**Impact:** If a course/activity name contains markup, it is surfaced verbatim into the clarification text. Residual XSS risk depends entirely on the downstream renderer.
**Compensating control:** Per `feedback_no_format_text_on_llm_answer`, agent replies are rendered via `clean_text`, not `format_text`; and the candidate set is limited to courses/modules the resolver already deemed user-visible (the user could see those names anyway). Names are not attacker-injected through the agent input path.
**Recommendation:** Either `s()` the interpolated names here, or rely on (and assert in a test) the clean_text render of the final synchronizer reply.

### [08-F04] 🟢 LOW · D2 Moodle API · services/noop_external_dependency_checker.php:17
**What:** File omits `declare(strict_types=1);` while every other in-scope service/DTO declares it.
**Evidence:** Lines 16–17 jump straight from the license header `namespace` with no `declare(strict_types=1);`; contrast `preflight_pipeline.php:17`, `preflight_execution_gate.php:17`, etc.
**Impact:** Inconsistent strictness; a non-string/array slipping through `check()` would coerce rather than throw. Inert today (the only caller passes well-formed arrays and it returns `preflight_result_v2::ok($input)`).
**Compensating control:** Default shipped implementation is a pure no-op; the interface return type is enforced.
**Recommendation:** Add `declare(strict_types=1);` for parity.

### [08-F05] 🟢 LOW · D3 Structure · services/preflight_execution_gate.php:95
**What:** Redundant retryability re-check inside `evaluate()`.
**Evidence:** Line 95 `if (!preflight_error_classifier::is_retryable_error_class($errorclass))` re-derives retryability after the category gate at lines 57–81 already rejected non-retryable categories. For any error class that survives the category + circuit-breaker gates and is not `provider_timeout`/`transient_io`, this would hard_block — but the only error classes that reach a retryable category via `resolve_retry_hint_category` for the pipeline's inputs are already timeout/transient. The branch is therefore largely unreachable from the pipeline (it can matter for the `confirm_run_service` caller, which passes broader error classes), so this is a structure/clarity note, not dead code.
**Impact:** Two overlapping gates make the retry contract harder to reason about.
**Compensating control:** Behaviour is conservative (extra hard_block, never an unintended retry).
**Recommendation:** Collapse the category gate and the error-class gate into one documented predicate, or comment why both exist.

### [08-F06] ⚪ INFO · D1 Security · services/preflight_pipeline.php:204-214
**What:** Confirmed-correct: Gate 2 (native capability) is enforced centrally at the **operating** context, before `skill::preflight`, and emits `NO_NATIVE_CAPABILITY` with no guard token when missing.
**Evidence:** `native_capability_guard::missing_capabilities($skill, $operatingcontextid, $userid)` at line 207; on non-empty it `continue`s without preparing the command (so no token is built downstream). The executor (`executor.php:262+`) re-checks as a backstop. Matches PF_L2P and `LG_AVAIL`.
**Impact:** None — defensive depth is correct.
**Compensating control:** n/a.
**Recommendation:** None.

### [08-F07] ⚪ INFO · D3 Structure · services/preflight_domain_check_runner.php:46-85
**What:** Confirmed-correct: the engine carries no booking-specific confirmable issue codes; `DUPLICATE_TITLE_*` arrive via `issue_code_provider_interface::get_prevalidation_confirmable_issue_codes()`, with `DOMAIN_CONFLICT` the only engine-generic soft-block code.
**Evidence:** Lines 79–85 merge `['DOMAIN_CONFLICT']` with provider-supplied codes; the provider defaults to `booking_issue_code_provider` (line 47), mirroring `agent_decision_service`. This is the "leak inverted" state ch.09 §7 and the flowchart claim.
**Impact:** None — leak correctly inverted.
**Compensating control:** n/a.
**Recommendation:** None.

## C. Per-file / per-method checklist

#### `classes/local/wizard/services/preflight_pipeline.php`  (class `preflight_pipeline`)
- [x] D1 [x] D2 [x] D3 [x] D4 [ ] D5 [ ] D6 — file-level (D5✓; D6 see 08-F02)
- methods:
  - [x] `__construct()` — D1✓ D2✓ D3✓
  - [ ] `run()` — D1✓ (08-F03 candidate interpolation, LOW); D6 see 08-F02; logic otherwise clean (deanonymize → resolve op-ctx → Gate 2 → skill preflight → R3 ext check)
  - [x] `private resolve_batch_risk_class()` — clean
  - [ ] `private build_output()` — see 08-F02 (D6: undocumented hard_block coercion)

#### `classes/local/wizard/services/preflight_schema_validator.php`  (class `preflight_schema_validator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level
- methods:
  - [x] `validate()` — D1✓ (field name sanitised via preg_replace before echo, line 57) D2✓ D3✓
  - [x] `private get_schema()` — static cache of `config/command_schema.json` (data-file load by path; not dead code)

#### `classes/local/wizard/services/preflight_version_validator.php`  (class `preflight_version_validator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level (wired into pipeline via `preflight_contract_validator`)
- methods:
  - [x] `__construct()` — clean
  - [x] `validate()` — clean
  - [x] `private resolve_requested_version()` — clean (string/int version coercion guarded)

#### `classes/local/wizard/services/preflight_contract_validator.php`  (class `preflight_contract_validator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level
- methods:
  - [x] `__construct()` — DI of schema+version validators, clean
  - [x] `validate()` — schema-then-version short-circuit, clean

#### `classes/local/wizard/services/preflight_domain_check_runner.php`  (class `preflight_domain_check_runner`)
- [x] D1 [x] D2 [x] D3 [x] D4 [ ] D6 — file-level (D6 see 08-F02; structure confirmed clean per 08-F07)
- methods:
  - [x] `__construct()` — provider injection, clean (08-F07)
  - [ ] `run()` — D6 shared-timeout retry_hint undocumented (08-F02)

#### `classes/local/wizard/services/preflight_execution_gate.php`  (class `preflight_execution_gate`)
- [x] D1 [x] D2 [ ] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [ ] `evaluate()` — see 08-F05 (D3 redundant retryability gate, LOW); constants/backoff correct
  - [x] `static build_guard_token()` — D1✓ stable key-sorted JSON normalize, sha256
  - [x] `static verify_guard_token()` — D1✓ `hash_equals`, empty-token fail-closed
  - [x] `private static normalize_for_guard()` — clean (recursive trim + ksort for assoc, preserves list order)

#### `classes/local/wizard/services/preflight_error_classifier.php`  (class `preflight_error_classifier`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (D4 see 08-F01)
- methods:
  - [ ] `static infer_from_issue_codes()` — D4 parallel map (08-F01); logic clean
  - [x] `static is_retryable_error_class()` — clean

#### `classes/local/wizard/services/retry_policy_service.php`  (class `retry_policy_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (D4 see 08-F01)
- methods:
  - [ ] `resolve_retry_hint_category()` — D4 parallel map (08-F01)
  - [x] `is_retryable_category()` — clean (TECHNICAL + EXTERNAL_DEPENDENCY)
  - [x] `evaluate_provider_circuit_breaker()` — clean (auth/quota → hard-block signals)

#### `classes/local/wizard/services/noop_external_dependency_checker.php`  (class `noop_external_dependency_checker`)
- [x] D1 [ ] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (D2 see 08-F04)
- methods:
  - [x] `check()` — framework-invoked via interface (PF_L3_EXT default); returns `ok($input)`; clean

#### `classes/local/wizard/dto/preflight_result_v2.php`  (class `preflight_result_v2`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (readonly DTO; guard_token correctly NOT a field — PRV2 confirmed)
- methods:
  - [x] `__construct()` — status whitelist (invalid→hard_block fail-closed), clamps, clean
  - [x] `private normalize_blocking_layer()` — alias map, clean
  - [x] `to_array()` — clean
  - [x] `static ok()` / `static confirmable()` / `static invalid()` — DTO-free skill helpers (framework-used), clean
  - [x] `private static extract_issue_codes_from_issues()` — clean

#### `classes/local/wizard/preflight_clarification.php`  (trait `preflight_clarification`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level (used by add/update activity + add/update quiz skills — grepped, 4 consumers)
- methods:
  - [x] `private clarify()` — delegates to `invalid()`; clean (DTO-free)

#### `classes/local/wizard/interfaces/external_dependency_checker_interface.php`  (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level (contract; noop + provider implementers)
  - [x] `check()` — interface method, clean

#### `classes/local/wizard/interfaces/issue_code_provider_interface.php`  (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5(n/a) [x] D6 — file-level (implemented by `booking_issue_code_provider`; consumed by domain runner + decision service)
  - [x] `get_duplicate_confirmation_issue_codes()` / `get_token_subscription_issue_codes()` / `get_prevalidation_confirmable_issue_codes()` / `get_basic_subscription_url()` / `get_premium_subscription_url()` — contract methods, clean

## D. Go-live blockers from this section
None. No BLOCKER or HIGH findings. The pipeline's security-critical paths (Gate 2 at operating context with no-token-on-deny, fail-closed guard-token verify via `hash_equals`, status whitelist defaulting to hard_block, risk→layer gating, leak-inverted issue-code provider) are correct and test-covered. Recommended pre/post-launch cleanups are MEDIUM/LOW: unify the two issue-code classification maps (08-F01), document the L2 shared-timeout retry and the legacy-valid hard_block coercion (08-F02), and the minor LOW items (08-F03/04/05).
