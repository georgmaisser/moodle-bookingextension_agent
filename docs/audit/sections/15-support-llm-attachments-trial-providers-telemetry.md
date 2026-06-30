# Audit Section 15 — Support: LLM, Attachments, Trial, Providers, Telemetry

**Scope:** `classes/local/wizard/services/llm/*`, `services/attachment/*`, `services/trial/trial_provisioner.php`, `trial_challenge.php`, `request_trial_key` flow (`classes/external/request_trial_key.php` + `ai_upload_attachment.php` + `store_provider_apikey.php` + `configure_provider_from_existing.php`), `services/provider_compat.php`, `provider_routing_util.php`, `provider_status_service.php`, `services/telemetry/routing_decision_log_service.php`, `services/debug/skill_selection_debug_service.php`, `services/introspection/skill_introspection_service.php`, `services/governance/skill_governance_service.php`, `llm_debug_logger.php`, `prompt_policy_builder.php`, `services/attempt_budget_dto.php`, `services/retry_policy_service.php`, `interfaces/external_dependency_checker_interface.php`, `services/noop_external_dependency_checker.php`, `classes/event/trial_consent_given.php`  ·  **Files audited:** 22  ·  **Methods audited:** ~75
**Arch chapter(s):** docs/architecture/16-support-services.md  ·  **Flowchart nodes:** `SUPPORT`, `SLLM`/`SPLLM`/`CPLLM`, `ASM_ATTACH`, `ASM_UPLOAD`, `AZ4`
**Auditor verdict:** ⚠️ issues (no blocker)

## A. Dimension scorecard
| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues  | All WS entry points gated (sesskey + readiness + `validate_context` + cap). pdftotext shell uses `escapeshellarg` (no injection). Upload sniffs MIME from binary, caps size, random temp names. Token IDOR closed (256-bit token bound to userid+contextid). Two MEDIUMs: unconditional full-prompt LLM debug logging (PII) with no retention task; provider-credential write gated on a manager-grantable cap. |
| D2 Moodle API      | issues  | `$DB` parameterised, caps all defined in `db/access.php`, strings via `get_string`, finfo/file API correct. One LOW: `provider_routing_util.php` omits `declare(strict_types=1)` that its siblings carry. |
| D3 Structure       | pass    | Clean layering, DI seams via interfaces, no engine→domain leak. No dead code found (all entry points framework-invoked; greps below). |
| D4 Duplication     | issues  | `build_actionconfig()` and `build_cloned_actionconfig()` in `trial_provisioner` are ~90% identical action maps (MEDIUM). Provider-class→component string-munging duplicated across `provider_compat`/`provider_routing_util` (LOW). |
| D5 Flowchart       | issues  | Doc-lag only: flowchart nodes `SLLM`/`SPLLM`/`CPLLM` name `llm_call_service::invoke(...)`; the real method is `invoke_for_context(...)` (renamed in the context-agnostic refactor). Behaviour matches. |
| D6 Docs coverage   | issues  | Chapter 16 documents a *different* support cluster (anonymizer, language policy, error classifier). **None** of this section's files (LLM call/debug, attachments, trial, provider_compat, telemetry, governance, introspection, retry policy) are described anywhere in the architecture chapters. Material omission. |

## B. Findings

### [15-F01] 🟠 HIGH · D1 Security · classes/local/wizard/llm_debug_logger.php:101 + services/llm/llm_call_service.php:111,192
**What:** Every LLM call persists the full request prompt and full model response to `bx_agent_ai_llm_debug` **unconditionally** (independent of the `aidebugmode` setting), and no retention/cleanup task exists for that table.
**Evidence:** `llm_call_service::invoke_for_context()` (line 111) and `invoke_embeddings_for_context()` (line 192) both call `llm_debug_logger::log_exchange_always(...)`, which calls `log_exchange(..., $forcelog=true)` and therefore skips the `is_enabled()` gate (`llm_debug_logger.php:71`). These two methods are the call sites for every planner/selection/construction/embedding/synthesizer/docs/question LLM invocation (9 caller files). Planner prompts embed conversation content, i.e. user PII (names, emails — the anonymizer masks values fed *back* into observations, but the original user message and assembled prompt are logged here verbatim). A grep of `db/` and `classes/task/` shows no DELETE/purge/retention job for `bx_agent_ai_llm_debug`; only the privacy provider references it.
**Impact:** The table grows one row per LLM call forever and retains full user-message/prompt text containing PII even when the admin believes debug logging is off (the chapter's own claim is "persist raw LLM exchanges *in booking debug mode*"). Unbounded storage growth + a standing PII store with no TTL.
**Compensating control:** `classes/privacy/provider.php` declares the table (export + `delete_data_for_*`), so GDPR subject-access/erasure works. Access to the raw rows requires `get_llm_debug_entries` (thread-scoped) and the admin debug pages are capability-gated. This is why it is HIGH, not BLOCKER.
**Recommendation:** Gate the always-on logging behind `is_enabled()` for the normal path (keep `log_exchange_always` only for the genuinely diagnostic flows, if any), OR add a scheduled cleanup task that prunes `bx_agent_ai_llm_debug` older than N days (mirror `cleanup_attachment_temp_files_adhoc`). At minimum, document the unconditional retention in chapter 16 and add a retention setting.

### [15-F02] 🟡 MEDIUM · D1 Security · classes/external/store_provider_apikey.php:86 + configure_provider_from_existing.php:79
**What:** Writing site-global AI-provider credentials (an API key, or cloning another provider's key+endpoint into the Wunderbyte provider instance) is gated only on `bookingextension/agent:requesttrial`, a capability that is grantable to managers — not on `moodle/site:config`.
**Evidence:** `store_provider_apikey::execute()` calls `require_capability('bookingextension/agent:requesttrial', context_system::instance())` then `trial_provisioner::configure_from_apikey()`, which `upsert_provider_instance()`s a site-level core_ai provider. `configure_provider_from_existing::execute()` is gated identically and calls `configure_from_existing_provider()`, which reads another configured provider's `apikey`+`endpoint` and writes them onto the Wunderbyte instance. On Moodle 4.5 (`provider_compat::configure_legacy_provider`) this overwrites the plugin's *single* global config slot.
**Impact:** A manager (not a site admin) can set or replace the site-wide AI provider key/endpoint. They cannot choose an arbitrary endpoint on the apikey path (it is pinned to `trial_provisioner::BASE_URL`), and the clone path reuses an already-admin-configured local endpoint, so this is a privilege-scope concern rather than an open SSRF/exfiltration hole.
**Compensating control:** Endpoint is hard-coded (apikey path) or admin-pre-configured (clone path); `requesttrial` defaults exclude unprivileged users; key format is validated (`/^sk-[A-Za-z0-9_\-]{20,}$/`). Residual risk is limited to a manager substituting a key.
**Recommendation:** Either define a dedicated provider-config capability with `riskbitmask => RISK_CONFIG` and `archetypes => [manager? or none]`, or add `require_capability('moodle/site:config', ...)` for the credential-writing endpoints. Document the 4.5 single-slot overwrite as a deliberate trade-off (it already is, in `provider_compat`).

### [15-F03] 🟡 MEDIUM · D4 Duplication · classes/local/wizard/services/trial/trial_provisioner.php:260 & 498
**What:** `build_cloned_actionconfig()` and `build_actionconfig()` produce near-identical core_ai/Wunderbyte action maps (generate_embeddings / planner_decide / generate_agent_reply / generate_text) with the same `systeminstruction` literals and temperatures (0.0 / 0.3); they drift only in endpoint/model derivation.
**Evidence:** Both methods hard-code the same four action keys and the same three system-instruction strings (`"Act as a compact planner..."`, `"Compose the final user-facing response..."`, `'[[action_generate_text_instruction]]'`) and the same temperatures. Any change to the planner/reply instruction must be edited in two places.
**Impact:** Maintenance hazard: the two action maps will silently diverge (e.g. a temperature or instruction tweak applied to only one trial path).
**Compensating control:** none.
**Recommendation:** Extract one private `action_template(string $chat, string $embeddings, string $chatmodel, string $embmodel): array` and have both builders call it with their resolved endpoints/models.

### [15-F04] 🟡 MEDIUM · D6 Docs · docs/architecture/16-support-services.md
**What:** The architecture chapter assigned to the `SUPPORT` subgraph documents a disjoint set of helpers (privacy_anonymizer, language_policy_service, message_trigger_registry, ai_error_classifier, issue-code provider, prompt_policy_builder, provider_routing_util, shared_json_payload_extractor, trigger_result_util) and never mentions any of this section's LLM-call/debug, attachment, trial-provisioning, provider_compat/status, telemetry, governance, introspection, or retry-policy code.
**Evidence:** `grep -ic` for `trial_provisioner|attachment|llm_call_service|provider_compat|provider_status|routing_decision_log|skill_governance|skill_introspection|retry_policy` against `16-support-services.md` returns 0. `prompt_policy_builder` and `provider_routing_util` *are* named (overlap with another section's ownership), but the rest of this cluster is undocumented.
**Impact:** A reader of the support chapter gets no description of how trial keys are minted, how attachments become prompt text, how provider config is read version-agnostically, or that LLM exchanges are logged — all material, security-relevant behaviours.
**Compensating control:** The flowchart `SUPPORT`/`ASM_*` nodes describe attachment + provider-availability behaviour at a high level.
**Recommendation:** Add a chapter (or extend 16) covering: trial provisioning chain + back-channel `trial_challenge.php`; attachment upload/token/extraction pipeline; `provider_compat` 4.5↔5.x seam; the LLM call wrapper + debug-logging behaviour (and its retention).

### [15-F05] 🟢 LOW · D5 Flowchart (doc-lag) · docs/Blueprints/flowcharts/AGENT_IMPLEMENTATION_FLOWCHART.mmd:109,112,162
**What:** Nodes `SPLLM`, `CPLLM`, `SLLM` label the call as `llm_call_service::invoke(...)`, but the class exposes `invoke_for_context(...)` / `invoke_embeddings_for_context(...)`; there is no `invoke()` method.
**Evidence:** `llm_call_service.php` defines only `invoke_for_context` and `invoke_embeddings_for_context`. Flowchart lines 109/112/162 say `invoke`.
**Impact:** Stale method name in the diagram; behaviour (one entry point per call, source tag, debug log) is correct. Per `feedback_flowchart_policy` this is reported, not reconciled.
**Recommendation:** Update the three node labels to `invoke_for_context(...)`.

### [15-F06] 🟢 LOW · D2 Moodle API · classes/local/wizard/services/provider_routing_util.php:25
**What:** `provider_routing_util.php` is the only file in this cluster without `declare(strict_types=1);` (its sibling services in the same namespace all declare it).
**Evidence:** Header jumps from the license block straight to `namespace ...;` with no `declare(strict_types=1);`. Compare `attempt_budget_dto.php`, `retry_policy_service.php`, `provider_status_service.php`, all of which declare strict types.
**Impact:** Inconsistent type strictness; a non-string passed to `short_provider_for_debug()` would coerce silently rather than TypeError. Low practical risk (callers pass strings).
**Recommendation:** Add `declare(strict_types=1);` for consistency.

### [15-F07] 🟢 LOW · D4 Duplication · provider_compat.php:203/215 vs provider_routing_util.php:61
**What:** Provider-class/component string munging (`aiprovider_` prefix stripping, `\provider` suffix splitting) is reimplemented in two helpers.
**Evidence:** `provider_compat::component_from_providerclass()` / `short_name_from_component()` and `provider_routing_util::short_provider_for_debug()` independently parse the `aiprovider_*\provider` shape.
**Impact:** Minor drift risk; the debug variant truncates to 10 chars while the compat variant does not.
**Compensating control:** Different outputs (component vs debug token) justify some separation.
**Recommendation:** Optional: share a single `aiprovider` name parser; low priority.

### [15-F08] ⚪ INFO · D1 Security · classes/local/wizard/services/attachment/attachment_processor.php:84
**What:** Extracted PDF text and `\moodle_exception::getMessage()` are interpolated verbatim into the message string that is later stored and sent to the model.
**Evidence:** `$prefixes[] = "--- DOCUMENT: {$filename} ---\n{$text}\n--- END DOCUMENT ---";` — `$text` is untrusted PDF content, `$filename` is `clean_param(PARAM_FILE)` at upload.
**Impact:** None at this layer: the string is a model prompt (plaintext), not HTML; XSS escaping is the responsibility of the render path (poll/display), which de-anonymises and escapes. Prompt-injection via PDF content is an inherent LLM property mitigated by the deterministic 2-call planner contract.
**Compensating control:** Token consumed immediately for PDFs; 15k-char cap in `pdf_text_extractor`; render-layer escaping.
**Recommendation:** None required; noted for completeness.

### [15-F09] ⚪ INFO · D1 Security · trial_challenge.php:26
**What:** Public no-login back-channel endpoint that echoes the nonce when it matches a cached value.
**Evidence:** `require_once(config.php)` with `phpcs:disable moodle.Files.RequireLogin.Missing`; `optional_param('token', '', PARAM_ALPHANUMEXT)`; only returns the token if `cache->get('nonce_'.$token) === $token`. Nonce is `random_string(32)` (unguessable), TTL 600s, GET-only (405 otherwise), 400 on empty, 403 on mismatch.
**Impact:** Confirmed-correct: an attacker cannot learn or forge a nonce; the endpoint only confirms origin to the Wunderbyte trial service. No PII, no state change, no enumeration value.
**Compensating control:** Intrinsic to the design (proof-of-origin).
**Recommendation:** None.

## C. Per-file / per-method checklist

#### `classes/local/wizard/services/llm/llm_call_service.php` (class `llm_call_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (no unused imports; all 5 action `use`s referenced)
- methods:
  - [x] `__construct()` — clean
  - [ ] `invoke_for_context()` — see 15-F01 (D1: unconditional debug log)
  - [ ] `invoke_embeddings_for_context()` — see 15-F01 (D1)
  - [x] `private build_prompt_action()` — clean
  - [x] `private resolve_wunderbyte_prompt_action_class()` — clean (class_exists guard, no injection)

#### `classes/local/wizard/services/llm/query_english_normalizer.php` (class `query_english_normalizer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (fail-open, sentinel-protected, length-guarded)
- methods:
  - [x] `__construct()` — clean
  - [x] `to_english()` — clean (deterministic quote protection, fail-open)

#### `classes/local/wizard/llm_debug_logger.php` (class `llm_debug_logger`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `is_enabled()` — clean
  - [x] `log_exchange()` — clean (gating logic correct)
  - [ ] `log_exchange_always()` — see 15-F01 (D1: bypasses gate for all callers)

#### `classes/local/wizard/services/attachment/attachment_processor.php` (class `attachment_processor`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (see 15-F08 INFO)
- methods:
  - [x] `augment_message()` — clean (token ownership enforced via resolve(); fail-silent on invalid token)

#### `classes/local/wizard/services/attachment/attachment_token_service.php` (class `attachment_token_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (256-bit token, userid+contextid+TTL checks = IDOR closed)
- methods:
  - [x] `create()` — clean (random_bytes(32))
  - [x] `resolve()` — clean (ownership + TTL + file-exists)
  - [x] `invalidate()` — clean (unlink + delete)
  - [x] `cleanup_expired()` — clean (mtime cutoff; `glob('wizard_*')` scoped to plugin temp dir)

#### `classes/local/wizard/services/attachment/pdf_text_extractor.php` (class `pdf_text_extractor`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `is_available()` — clean
  - [x] `extract()` — clean
  - [x] `private truncate()` — clean (15k cap)
  - [x] `private has_pdftotext()` — clean
  - [x] `private has_pdfparser()` — clean (vendored, thirdpartylibs.xml)
  - [x] `private static ensure_pdfparser_autoloader()` — clean (prefix-scoped, `is_readable` guard)
  - [x] `private extract_via_shell()` — clean (**`escapeshellarg($filepath)` — no command injection**; set_time_limit(30))
  - [x] `private extract_via_pdfparser()` — clean (try/catch)

#### `classes/local/wizard/services/trial/trial_provisioner.php` (class `trial_provisioner`)
- [ ] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [ ] D6 — file-level (D1/D4/D6 see 15-F02/F03/F04)
- methods:
  - [x] `provision()` — clean (nonce cached before POST per back-channel design)
  - [ ] `configure_from_existing_provider()` — see 15-F02 (D1 cap scope)
  - [ ] `configure_from_apikey()` — see 15-F02 (D1); key format validated, endpoint pinned
  - [x] `private verify_apikey()` — clean (only 401 hard-rejects; no key logged)
  - [ ] `private build_cloned_actionconfig()` — see 15-F03 (D4)
  - [x] `private upsert_wunderbyte_from_clone()` — clean
  - [x] `private detect_strategy()` — clean
  - [x] `private exchange_nonce()` — clean (ignores echoed internal endpoint; status-coded messages; no key logged)
  - [x] `private upsert_provider_instance()` — clean
  - [ ] `private build_actionconfig()` — see 15-F03 (D4)
  - [x] `private fail()` — clean (debug detail only when DEBUG_DEVELOPER or aidebugmode; never includes a key)

#### `trial_challenge.php` (top-level script)
- [x] D1 [x] D2 [x] D3 n/a D4 [x] D5 n/a D6 — file-level (see 15-F09 INFO; correct no-login back-channel)

#### `classes/external/request_trial_key.php` (class `request_trial_key`)
- [x] D1 [x] D2 [x] D3 n/a D4 [x] D5 [x] D6 — file-level
- methods:
  - [x] `execute_parameters()` — clean (PARAM_INT/BOOL/ALPHA)
  - [x] `execute()` — clean (sesskey + readiness + validate_context + `requesttrial` cap + consent gate + audit event)
  - [x] `execute_returns()` — clean

#### `classes/external/ai_upload_attachment.php` (class `ai_upload_attachment`)
- [x] D1 [x] D2 [x] D3 n/a D4 [x] D5 [x] D6 — file-level (sesskey, finfo MIME whitelist, size caps, random temp names, ownership token)
- methods:
  - [x] `execute_parameters()` — clean
  - [x] `execute()` — clean (server-side MIME sniff overrides browser claim; PARAM_FILE filename)
  - [x] `execute_returns()` — clean
  - [x] `private error_response()` — clean
  - [x] `private safe_extension()` — clean (match whitelist)
  - [x] `private build_thumbnail_html()` — clean (alt escaped with `htmlspecialchars`)

#### `classes/external/store_provider_apikey.php` (class `store_provider_apikey`) — request_trial_key flow
- [ ] D1 [x] D2 [x] D3 n/a D4 [x] D5 [x] D6 — see 15-F02 (D1 cap scope); sesskey/validate_context/format-validation present

#### `classes/external/configure_provider_from_existing.php` (class `configure_provider_from_existing`) — request_trial_key flow
- [ ] D1 [x] D2 [x] D3 n/a D4 [x] D5 [x] D6 — see 15-F02 (D1 cap scope)

#### `classes/local/wizard/services/provider_compat.php` (class `provider_compat`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [x] D5 [ ] D6 — file-level (D4 see 15-F07; D6 see 15-F04)
- methods:
  - [x] `supports_provider_instances()` — clean
  - [x] `get_provider_views()` — clean (4.5/5.x seam)
  - [x] `configure_provider()` — clean (5.x instance vs 4.5 flat config)
  - [x] `enable_provider_view()` — clean
  - [x] `private configure_legacy_provider()` — clean (set_config per key; documents 4.5 single-slot overwrite)
  - [ ] `private component_from_providerclass()` — see 15-F07 (D4)
  - [ ] `private short_name_from_component()` — see 15-F07 (D4)
  - [x] `private synthesise_legacy_views()` — clean
  - [x] `private legacy_actionconfig()` — clean

#### `classes/local/wizard/services/provider_routing_util.php` (class `provider_routing_util`)
- [x] D1 [ ] D2 [x] D3 [ ] D4 [x] D5 [x] D6 — file-level (D2 see 15-F06 missing strict_types; D4 see 15-F07)
- methods:
  - [x] `resolve_primary_provider_for_action()` — clean (try/catch)
  - [ ] `short_provider_for_debug()` — see 15-F07 (D4)

#### `classes/local/wizard/services/provider_status_service.php` (class `provider_status_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (D6 see 15-F04)
- methods:
  - [x] `__construct()` — clean
  - [x] `get_status()` — clean (availability layer = capability `ignoreaiavailability` bypass; (int) cast on `$context->instanceid` under strict_types; defensive try/catch)

#### `classes/local/wizard/services/telemetry/routing_decision_log_service.php` (class `routing_decision_log_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (thread metadata, 50-entry cap; no PII beyond routing labels; D6 see 15-F04)
- methods:
  - [x] `persist_thread_routing_decision()` — clean (LOG_LIMIT slice)
  - [x] `normalize_telemetry()` — clean
  - [x] `build_shadow_result()` — clean
  - [x] `build_embeddings_comparison()` — clean
  - [x] `private derive_embedding_path()` — clean

#### `classes/local/wizard/services/debug/skill_selection_debug_service.php` (class `skill_selection_debug_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (callers `skill_selection_debug.php`/`cli/*` gate on `debugskillselection` cap + sesskey; D6 see 15-F04)
- methods:
  - [x] `__construct()` — clean
  - [x] `simulate_selection()` — clean (topk clamped 1..50)
  - [x] `rank_simulation_candidates()` — clean (pure)
  - [x] `analyze_collisions()` — clean (limit clamped 1..500; keeps all high/warn pairs)
  - [x] `private get_prompt_contracts_for_context()` — clean
  - [x] `private build_lexical_ranking()` — clean (reference-only, never drives live ranking)
  - [x] `private build_embedding_ranking()` — clean (fail-open)
  - [x] `private generate_query_embedding()` — clean (try/catch)
  - [x] `private resolve_contextid_from_cmid()` — clean
  - [x] `private tokenize()` — clean
  - [x] `private classify_collision_risk()` — clean

#### `classes/local/wizard/services/introspection/skill_introspection_service.php` (class `skill_introspection_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (executability + access-scoped; D6 see 15-F04)
- methods:
  - [x] `__construct()` — clean
  - [x] `list_actions()` — clean (scope filter, evaluator gate)
  - [x] `render_full_skill_catalog()` — clean (no-full-access strips mutating skills)

#### `classes/local/wizard/services/governance/skill_governance_service.php` (class `skill_governance_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [ ] D6 — file-level (admin updatedcallback; caller `skill_governance.php` gates `managegovernance` + sesskey; D6 see 15-F04)
- methods:
  - [x] `static sync_enableall_toggles()` — clean (one-shot reset in finally; try/catch)

#### `classes/local/wizard/prompt_policy_builder.php` (class `prompt_policy_builder`)
- [x] D1 [x] D2 [x] D3 [x] D4 [x] D5 [x] D6 — file-level (named in chapter 16; static prompt-text assembly, no input)
- methods:
  - [x] `static build_planner_policies()` — clean
  - [x] `private static build_response_contract_policy()` — clean
  - [x] `private static build_trigger_policy_compact()` — clean
  - [x] `private static build_routing_determinism_policy()` — clean
  - [x] `private static build_step_intent_policy()` — clean
  - [x] `private static build_docs_answer_policy()` — clean
  - [x] `private static build_sufficiency_policy()` — clean
  - [x] `private static normalize_phase()` — clean

#### `classes/local/wizard/services/attempt_budget_dto.php` (class `attempt_budget_dto`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (immutable DTO, clamped inputs)
- methods:
  - [x] `__construct()` / `static from_loop()` / `static from_queue_item()` / `to_array()` — all clean

#### `classes/local/wizard/services/retry_policy_service.php` (class `retry_policy_service`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (pure classification)
- methods:
  - [x] `resolve_retry_hint_category()` — clean
  - [x] `is_retryable_category()` — clean
  - [x] `evaluate_provider_circuit_breaker()` — clean (auth/quota → circuit open)

#### `classes/local/wizard/interfaces/external_dependency_checker_interface.php` (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (declared contract; one in-repo implementer = noop; framework seam)

#### `classes/local/wizard/services/noop_external_dependency_checker.php` (class `noop_external_dependency_checker`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (default PF_L3_EXT no-op; DI target, not dead)
- methods:
  - [x] `check()` — clean (returns ok())

#### `classes/event/trial_consent_given.php` (class `trial_consent_given`)
- [x] D1 [x] D2 [x] D3 [x] D4 n/a D5 [x] D6 — file-level (audit event; framework-invoked)
- methods:
  - [x] `init()` / `static get_name()` / `get_description()` — clean

> **Dead-code sweep:** I grepped the whole `classes/` + `tests/` + top-level `*.php` + `cli/` tree for every public method/class in scope. `skill_selection_debug_service` (page + 2 CLI), `skill_introspection_service` (executor + list_skills_skill), `skill_governance_service` (settings updatedcallback + governance page), `attachment_token_service`/`pdf_text_extractor`/`attachment_processor` (upload WS + adhoc cleanup task + send pipeline), `provider_compat`/`provider_status_service`/`provider_routing_util` (trial + orchestrator routing/status), `retry_policy_service`/`attempt_budget_dto` (preflight/execution layers), `noop_external_dependency_checker` (DI default for PF_L3_EXT) all have real callers. No dead code found.

## D. Go-live blockers from this section
- **None are hard blockers.** One HIGH item is strongly recommended pre-launch:
  - **[15-F01] HIGH** — unconditional full-prompt LLM debug logging (PII) to `bx_agent_ai_llm_debug` with no retention/cleanup task. Gate it behind `aidebugmode` and/or add a retention task before go-live; the privacy provider covers export/erasure so it is not a launch-blocking data-loss/exploit hole.
