# Cross-cutting Audit C3 — Duplicated Code (horizontal)

**Section id:** C3 · **Sweep:** D4 across the whole `classes/` tree (cluster boundaries)
**Files swept:** ~291 PHP files under `classes/` · **Methods sampled:** ~140
**Corroborating section reports:** `docs/audit/sections/08-preflight-pipeline.md` (08-F01)
**Auditor verdict:** ⚠️ issues (no blockers)

> This is a horizontal D4-only report. Dimensions D1/D2/D3/D5/D6 are scored `n/a` here; they are
> owned by the per-subsystem section reports and the other cross-cutting sweeps. Each finding names
> the canonical home, the duplicate sites, and a consolidation recommendation, ranked by the
> maintenance risk of drift.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | n/a | not this sweep |
| D2 Moodle API      | n/a | not this sweep |
| D3 Structure       | n/a | not this sweep (overlaps D4 where parallel maps drift; see C3-F02) |
| D4 Duplication     | issues | One MEDIUM drift hazard (provider-action FQCN constants in 9 classes), two further MEDIUMs (parallel classifiers, duplicated corpus scan), and several LOW near-duplicates. Crucially, the *big* duplication surfaces the brief worried about — CSV repositories, risk-class resolution, planner-vs-synchronizer prompt assembly, the cosine math, the rebuild scheduler — are **already consolidated** behind shared bases/services (C3-F10). |
| D5 Flowchart       | n/a | not this sweep |
| D6 Docs coverage   | n/a | not this sweep |

---

## B. Findings

### [C3-F01] ✅ RESOLVED (was 🟡 MEDIUM) · D4 Duplication · 9 classes redeclare the `WB_ACTION_*` provider-action FQCNs
**✅ Resolved 2026-06-30:** added a single canonical holder `bookingextension_agent\local\wizard\wb_action_names` (consts `PLANNER_DECIDE` / `GENERATE_AGENT_REPLY` / `GENERATE_EMBEDDINGS`). All redeclared `WB_ACTION_*` consts now alias it (`= wb_action_names::X`), so the FQCN string lives in exactly one place — across the original 9 classes **plus** the newer `benchmark_provider_preview` (a 10th, same pattern). The drift this finding warned about (`skill_selection_debug_service` carried a leading backslash) is eliminated. The 6 inline `class_exists('\\…generate_embeddings')` literals in the rebuild tasks / index+readiness services were also re-pointed at `wb_action_names::GENERATE_EMBEDDINGS`. (Only `trial_provisioner` still embeds the FQCNs — there they are provider-actionconfig **map keys**, a different pattern; left as-is.) phpcs clean on all touched files. — _Original finding below._
**Evidence:** `private const WB_ACTION_*` declarations in:
- `local/wizard/orchestrator.php:88,91`
- `local/wizard/embeddings_action_config_resolver.php:34`
- `local/wizard/benchmark/benchmark_envkey_manager.php:58,61,64`
- `local/wizard/services/provider_status_service.php:43,46`
- `local/wizard/services/agent_access_service.php:54,57`
- `local/wizard/services/discovery_phase_service.php:67`
- `local/wizard/services/phase_prompt_bundle_builder.php:40,43`
- `local/wizard/services/llm/llm_call_service.php:43,46,49`
- `local/wizard/services/debug/skill_selection_debug_service.php:43`

The `generate_embeddings` literal *also* appears inline (no constant) in six more places: `task/rebuild_docs_embeddings_adhoc.php:50`, `task/rebuild_skill_catalog_embeddings_adhoc.php:44`, `services/embeddings/family_embeddings_index_service.php:55`, `services/embeddings/embeddings_readiness_service.php:45`, `services/lookup/docs_embeddings_index_service.php:79`, `services/lookup/docs_embeddings_readiness_service.php:44`. **The copies have already begun to drift:** `skill_selection_debug_service.php:43` uses the leading-backslash form `'\\aiprovider_wunderbyte\\aiactions\\generate_embeddings'` while the others use the unprefixed `'aiprovider_wunderbyte\\aiactions\\generate_embeddings'`.
**Impact:** These strings are the contract with an *external* plugin. If `aiprovider_wunderbyte` renames an action class, ~9 constant sites plus ~6 inline sites must be updated in lockstep; a missed one silently disables the corresponding capability/availability probe (e.g. embeddings readiness reporting "unavailable", or the planner action class not matching). The existing leading-backslash divergence proves the drift is real.
**Compensating control:** `class_exists()` guards fail safe (a stale name simply reports "unavailable" rather than fataling), and these are dev-time constants not user input.
**Recommendation:** Introduce one canonical holder, e.g. `bookingextension_agent\local\wizard\contracts\provider_action_names` with `const PLANNER_DECIDE`, `GENERATE_AGENT_REPLY`, `GENERATE_EMBEDDINGS` (and a single `embeddings_provider_available(): bool` helper wrapping the `class_exists`), and have all 15 sites reference it. Add a one-line unit test asserting the three constants are non-empty FQCNs so a rename is caught.

### [C3-F02] ✅ RESOLVED (was 🟡 MEDIUM) · D4 Duplication · preflight_error_classifier + retry_policy_service (= 08-F01)
**✅ Resolved 2026-06-30 (behaviour-preserving):** the issue-code knowledge now lives in one canonical class, `services/issue_code_taxonomy`, with two methods — `error_class_for()` (the display error_class) and `retry_category_for()` (the retry category, holding the `CATEGORY_*` constants). NOT a single ordered table: the two views have a **deliberately different match precedence** (a code with both `PERMISSION` and `TIMEOUT` → `provider_timeout` as an error_class but `DOMAIN` as a retry category), so each rule set is kept verbatim as its own method — adding/changing a code now touches one file. `preflight_error_classifier::infer_from_issue_codes()` and `retry_policy_service::resolve_retry_hint_category()` are thin delegators; `retry_policy_service::CATEGORY_*` now alias the taxonomy constants (external `retry_policy_service::CATEGORY_*` references unchanged). Equivalence proven: a standalone harness ran 28 cases (incl. the precedence-conflict + fallback paths) through the ORIGINAL bodies vs the taxonomy — all identical. `tests/issue_code_taxonomy_test.php` locks both precedences. php -l + phpcs clean. **This also resolves 08-F01** (same finding). — _Original finding below._
**Evidence:** `preflight_error_classifier::infer_from_issue_codes()` matches `TIMEOUT`/`TRANSIENT_IO`/`PERMISSION`/`CONFLICT`/`VALIDATION`/`MISSING_`; `retry_policy_service::resolve_retry_hint_category()` matches an overlapping-but-not-identical set (`…→DOMAIN`, `TIMEOUT|TRANSIENT|CONTRACT_|PARSE|…→TECHNICAL`, `AUTH|QUOTA|RATE_LIMIT|PROVIDER|EXTERNAL→EXTERNAL_DEPENDENCY`). Each map knows codes the other does not.
**Impact:** A new issue code added to one map but not the other yields inconsistent gating (one layer retryable, the other terminal). No current correctness defect — the current values line up — but a pure maintenance landmine because the two consumers are in different files/layers.
**Compensating control:** `preflight_layers_contract_test` / `preflight_pipeline_risk_class_contract_test` pin current behaviour.
**Recommendation:** Extract one canonical `issue_code → {error_class, retry_category}` table (a shared map class) consumed by both. This is the same fix 08-F01 recommends; flagging here because it spans the preflight *and* retry/queue clusters.

### [C3-F03] 🟡 MEDIUM · D4 Duplication · `services/lookup/docs_lookup_service.php:376` + `services/lookup/docs_embeddings_index_service.php:298`
**What:** The corpus markdown-file enumeration (recursive directory walk, `/pix/` skip, `.md`-only filter) is implemented twice, once per service.
**Evidence:** `docs_lookup_service::load_docs()` (line 376) and `docs_embeddings_index_service::scan_md_files()` (line 298) both do `new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, SKIP_DOTS), SELF_FIRST)`, then `if (!$fileinfo->isFile()) continue; if (strpos(str_replace('\\','/',$path), '/pix/') !== false) continue; if (strtolower($fileinfo->getExtension()) !== 'md') continue;`. The skip rules (pix/, .md) are byte-for-byte the same; only the post-filter payload differs (lookup builds title/excerpt records, the index just collects paths).
**Impact:** The "what counts as an indexable doc file" rule is the single most drift-sensitive invariant of the docs corpus: if lookup and index disagree (e.g. one starts skipping `CHANGELOG.md` or a new image dir), the index can embed files the lookup will never read back, or vice-versa, producing dangling semantic hits. Two copies guarantee they can diverge.
**Compensating control:** `docs_lookup_service::search_semantic()` already self-heals dangling hits by scheduling a rebuild, so a divergence degrades gracefully rather than erroring.
**Recommendation:** Add a single `docs_corpus_registry::scan_md_files(string $root): array` (or a small `docs_corpus_scanner`) that owns the SKIP_DOTS/`/pix/`/`.md` rule, and have both callers consume it; each keeps its own per-file payload mapping.

### [C3-F04] 🟢 LOW · D4 Duplication · `services/planner_phase_prompt_trait.php:129` + `services/runtime_context_block_builder.php:522`
**What:** The trivial helper `private function json_encode_or_empty($value, int $flags): string { … return $json === false ? '' : $json; }` is defined twice with slightly different bodies (one tests `=== false`, the other `!is_string()`) and different default-arg signatures.
**Evidence:** `planner_phase_prompt_trait.php:129` (`int $flags = 0`, `$json === false ? ''`) vs `runtime_context_block_builder.php:522` (`int $flags` required, `!is_string($json)`).
**Impact:** Negligible behavioural risk (both collapse a failed encode to `''`), but two copies of a one-liner with divergent guards is exactly the kind of helper that should live once.
**Compensating control:** Both are private, both return `''` on failure.
**Recommendation:** Move to a tiny shared static (e.g. `shared_json_payload_extractor::encode_or_empty()` since that class already owns the JSON-codec helpers) and delete both private copies.

### [C3-F05] 🟢 LOW · D4 Duplication · `interpreter.php:1021` reimplements `services/shared_json_payload_extractor.php`
**What:** `interpreter::sanitize_json_payload()` hand-rolls the markdown ```` ```json ```` fence-stripping regex that already lives in the canonical `shared_json_payload_extractor::extract_json_candidates()`.
**Evidence:** `interpreter.php:1035` uses `preg_match('/^\x60\x60\x60(?:json)?\s*([\s\S]*?)\s*\x60\x60\x60$/i', …)`; `shared_json_payload_extractor.php:47` uses the all-matches variant of the same fence regex. The canonical extractor is currently consumed only by `benchmark/benchmark_result_collector.php` — the interpreter, the most important JSON consumer, does not use it.
**Impact:** Two fence-parsers can diverge (e.g. one starts accepting ```` ~~~ ```` fences or leading prose); the interpreter is on the hot planner path, so a divergence there is the higher-consequence copy. Low severity because the interpreter intentionally also enforces a stricter single-object shape (`[0]==='{'`/`-1==='}'`) that the candidate extractor does not.
**Compensating control:** Interpreter's stricter post-checks catch most malformed output regardless.
**Recommendation:** Have `interpreter::sanitize_json_payload()` first run `shared_json_payload_extractor::extract_json_candidates()` and then apply its strict single-object selection, so the fence vocabulary lives in exactly one place.

### [C3-F06] 🟢 LOW · D4 Duplication · embedding-row decode+score micro-loop ×5
**What:** The block "`$embedding = json_decode((string)($row['embedding_json'] ?? '[]'), true); if (!is_array($embedding) || empty($embedding)) continue; $score = vector_math::cosine_similarity($queryvector, $embedding);`" is copy-pasted across the retrieval services.
**Evidence:** `embeddings_retrieval_service.php` lines 57–62, 110–115, 182–186; `family_embeddings_retrieval_service.php` lines 67–72. The cosine math itself is already centralised in `vector_math::cosine_similarity` — only the decode+empty-guard wrapper is duplicated.
**Impact:** Low; the guard logic is identical and stable. If the stored vector encoding ever changes (e.g. base64 instead of JSON), five sites must change.
**Compensating control:** The math is shared; only the row-decode wrapper repeats.
**Recommendation:** Add `vector_math::decode_row_embedding(array $row): array` (or a `score_row($qv,$row): ?float`) and call it from the five loops.

### [C3-F07] 🟢 LOW · D4 Duplication · TTL literals 900 / 300 spread across confirm/queue/conversation
**What:** The confirmation/blocked-confirmation TTL value `900` and the debounce `300` appear as independent literals/constants in several layers instead of one shared constant.
**Evidence:** `conversation_store.php:41` `PENDING_INTENT_TTL = 900`, `:47` `CONFIRMATION_SESSION_ALLOWLIST_TTL = 900`; `queue/queue_manager.php:54` `DEFAULT_BLOCKED_TTL_SECONDS = 900` and a hard-coded `return 300;` at `:847`; `services/lookup/docs_embeddings_readiness_service.php:275` `$debounceseconds = 300`. (This is the same 900≠300 split noted in the dead-code/flowchart audit memory.)
**Impact:** Low — these are intentionally per-domain knobs and `queue_manager` already supports a config override (`queue_blocked_ttl_seconds`). But three independent `900` constants for conceptually the same "how long a pending confirmation lives" value can drift apart unnoticed.
**Compensating control:** Each constant is named and documented in place; queue TTL is admin-configurable.
**Recommendation:** If `PENDING_INTENT_TTL` and `DEFAULT_BLOCKED_TTL_SECONDS` are meant to be the same horizon, hoist to one shared constant; otherwise add a one-line comment at each documenting why they are independent, so a future reader does not "helpfully" unify them.

### [C3-F08] 🟢 LOW · D4 Duplication · external WS context-resolution preamble copy-pasted across ~13 entry points
**What:** Every external WS repeats the same 4-step preamble: `self::validate_parameters(...)` → `context::instance_by_id($contextid, MUST_EXIST)` → `self::validate_context($context)` → `$authz->check_use_readiness((int)$USER->id, …)`.
**Evidence:** `context::instance_by_id(…, MUST_EXIST)` at `ai_confirm_run.php:95`, `ai_get_doc_content.php:82`, `ai_discard_pending.php:75`, `activate_trial_context.php:76`, `ai_send_message.php:131`, `ai_get_thread_debug_logs.php:79`, `ai_privacy_precheck.php:96`, `ai_poll_thread.php:86`, `request_trial_key.php:88`, `ai_upload_attachment.php:121`, `configure_provider_from_existing.php:76`, `store_provider_apikey.php:83`; each followed by `validate_context` + `check_use_readiness`. The thread-ownership IDOR guard `$store->thread_belongs_to_user(...)` is itself repeated verbatim in 4 of them (`ai_confirm_run`, `ai_discard_pending`, `ai_poll_thread`, `ai_get_thread_debug_logs`).
**Impact:** Low. Each primitive is already a shared, correct call (`check_use_readiness` is centralised in `authorization_service`; `validate_context` is core). The duplication is of the *sequence*, which is largely idiomatic Moodle WS boilerplate. Residual risk is that a new WS could omit a step (e.g. forget `check_use_readiness`) — a security-relevant omission, but that is D1's concern and the section-1 security sweep should confirm each entry point individually.
**Compensating control:** The individual guards are shared; the readiness/IDOR checks are single-implementation.
**Recommendation:** Optional: a `protected static function resolve_authorized_context(int $contextid): array{context,problem}` trait/base for the externals to collapse the 4 lines into one and make "did this WS run the readiness gate?" structurally guaranteed rather than reviewer-checked.

### [C3-F09] 🟢 LOW · D4 Duplication · preview label/value HTML-table builder duplicated across skill previews
**What:** `get_result_preview()` implementations hand-build a Bootstrap `<table>` of escaped label/value rows with `\html_writer::tag('tr'|'th'|'td', s(...))` rather than sharing a row-table renderer.
**Evidence:** `core/skills/search_users_skill.php:330-355` and `core/skills/get_current_user_skill.php:204-218` both build `table table-sm booking-ai-preview-*` tables the same way; `services/preview_support.php` already offers `push()`/`text()` to *assemble rows as data* but no shared renderer to turn those rows into the table HTML.
**Impact:** Low and localized (two files today). Note in passing: these two previews hard-code English column labels via `s('Name')`/`s('Email')`/`s('ID')`, which is a D1/D2 string-API issue (contradicts the get_string convention) for the skills section to own — not counted as a D4 finding here.
**Compensating control:** All cell content is escaped via `s()`; the pattern is small.
**Recommendation:** Add `preview_support::render_rows_table(array $rows, string $cssclass): string` and have both skills feed it `preview_support::push()`-built rows; this also gives one place to fix the hard-coded labels.

### [C3-F10] ⚪ INFO · D4 Duplication · confirmed-correct: the high-risk duplication surfaces are already consolidated
**What:** The brief's prime suspects for hidden duplication are, on inspection, already factored behind a single shared home — worth recording so they are not "fixed" twice.
**Evidence:**
- **CSV / embeddings plumbing:** `embeddings_csv_repository` and `docs_embeddings_csv_repository` both `extend embeddings_csv_repository_base` (`local/wizard/embeddings_csv_repository_base.php`), which owns all parsing, RFC-4180 escaping, atomic round-trip-verified write, streaming, and key-offset indexing. The two subclasses only declare `headers()`/`required_nonempty_columns()`/`default_csv_path()`/`store_label()`. No drift possible. (Directly answers the `embeddings_csv_repository` vs `docs_embeddings_csv_repository` concern.)
- **Risk-class resolution:** centralised in `services/risk/risk_class_resolver.php` (`normalize`/`resolve_for_command`/`rank`) + the `dto/skill_risk_class` constants; consumed uniformly by preflight, decision, queue, queue_command_mapper, queue_transition, confirm_run. The documented LG_RISK centralisation holds.
- **Cosine math:** single implementation in `services/embeddings/vector_math.php` (only the row-decode wrapper around it duplicates — C3-F06).
- **Planner-phase prompt assembly:** the discovery and selection/construction phase services share `planner_phase_prompt_trait`, which thinly delegates to the single `phase_prompt_bundle_builder`; the synchronizer's `synchronizer_prompt_builder` is a deliberately separate prompt domain, not a copy. So the "planner phases vs synchronizer prompt assembly" pair is intentionally distinct, not duplicated.
- **Embeddings rebuild scheduling:** both readiness services route through one `embeddings_rebuild_scheduler::queue_if_due()` (config-marker debounce + deduped adhoc enqueue); only the readiness *algorithms* differ (anchor-hash vs fingerprint+coverage), which is genuine divergence of purpose, not copy-paste.
- **Preview-row data helpers + diagnostics foundation:** `services/preview_support.php` and the `diagnostics/diagnostic_{result,link}_builder` + `diagnostic_checklist_preview` are shared foundations consumed by the skill/diagnose families.
**Impact:** None — these are the "did it right" cases.
**Recommendation:** None.

---

## C. Top blockers

**None.** No BLOCKER or HIGH duplication findings. The duplication that exists is maintenance risk, not a launch gate:

- The one finding worth fixing **before** the codebase grows further is **C3-F01** (provider-action FQCN constants in 9 classes, already drifting) — cheap to fix, prevents a class of silent "provider unavailable" regressions.
- **C3-F02** (parallel issue-code classifiers) and **C3-F03** (duplicated corpus md-scan) are the other two MEDIUMs; both are single-table/single-method extractions.
- C3-F04..F09 are LOW cleanups for the backlog.

The structurally important result of this sweep is the negative one (C3-F10): the expensive-to-duplicate subsystems (CSV stores, risk classification, vector math, planner prompt assembly, rebuild scheduling) are already behind shared bases/services, so there is no large hidden duplication debt blocking go-live.
