# Audit Section 18 — Benchmark Harness & CLI

**Scope:** `classes/local/wizard/benchmark/*` (10 classes + `scenarios/*` 42 files), `cli/benchmark_*`
(`benchmark_aggregate`, `benchmark_ci_gate`, `benchmark_export`, `benchmark_import`, `benchmark_matrix`,
`benchmark_runner`), `cli/rebuild_embeddings_fixture`, `cli/skill_selection_dataset`,
`cli/skill_selection_debug`, root admin pages `benchmark_report.php`, `benchmark_run_detail.php`,
`benchmark_compare.php`, plus the two off-list collaborators reached from the harness
(`classes/task/run_benchmark_adhoc.php`, `classes/task/cleanup_old_benchmark_runs_task.php`).
· **Files audited:** 23 (PHP, excluding the 42 near-identical scenario data files, sampled) · **Methods audited:** ~55
**Arch chapter(s):** `docs/operations/benchmarking.md` · **Flowchart nodes:** none (operations tier, off the request path)
**Auditor verdict:** ⚠️ issues (no blocker)

> Severity calibration: this entire tier is **off the live request path**. The only web-reachable
> surface is the three admin report pages, all behind `require_login` + `require_capability`. CLI
> scripts run only from a trusted shell. No finding here rises to BLOCKER.

---

## A. Dimension scorecard

| Dimension | Verdict | Notes |
|-----------|---------|-------|
| D1 Security        | issues | All 3 admin pages gate on `require_login` + `require_capability`; `pinbaseline` write is sesskey-protected. One real defect: the `pinbaseline` **write** action is gated only by the **read** cap `viewbenchmarks` (18-F01; = C1-F01). No SQL injection (all `$DB` parameterised / `get_in_or_equal`). No API-key logging anywhere — `benchmark_envkey_manager` / `benchmark_provider_preview` read keys via `getenv` and never echo/persist them (18-F07, INFO confirm). CLI scripts correctly `define('CLI_SCRIPT', true)`. |
| D2 Moodle API      | issues | `get_string` used throughout the pages; capabilities defined in `db/access.php`; tasks registered in `db/tasks.php`; CLI uses `cli_get_params`/`cli_writeln`/`cli_error`. Defects: admin pages hand-roll `htmlspecialchars()` instead of `s()`/`format_string()` (18-F04, LOW), and `benchmark_run_detail.php` / `benchmark_compare.php` double-escape the run label by pre-escaping then passing into `set_heading()` (18-F05, LOW). |
| D3 Structure       | issues | Core classes are clean, single-responsibility, all `declare(strict_types=1)`. The whole "run benchmark from the interface" feature is **half-built / dead**: `benchmark_run_service`, `benchmark_provider_preview`, the `run_benchmark_adhoc` task, and the `runbenchmarks`/`managebenchmarks` capabilities exist but **no web page queues the task or renders the preview** (18-F02, 18-F03). |
| D4 Duplication     | issues | `benchmark_run_service::run()` is a near-verbatim copy of the scenario loop in `cli/benchmark_runner.php` (18-F06, MEDIUM) — the runner was supposed to delegate to the service but still carries its own inline copy. The three admin pages also repeat a metric-unit→suffix map and a status-emoji map (LOW, folded into 18-F06). |
| D5 Flowchart       | n/a | This subsystem owns no flowchart nodes (operations, off the request path). |
| D6 Docs coverage   | issues | `docs/operations/benchmarking.md` §1 lists scenarios that **do not exist** (`confirmation_request_r1`, `budget_exceeded`) and omits ones that do (`catalog_gap_bulk_cancel`, `ambiguous_request_no_hallucination`); states the default set is `core_booking_v1`/15 scenarios when the runner default is `decision_core` (10) and the full universe auto-discovers ~30+ `route_*` scenarios. The "run from interface" feature + `runbenchmarks` cap are undocumented (and, per 18-F02, unwired). (18-F08, MEDIUM) |

---

## B. Findings

### [18-F01] 🟡 MEDIUM · D1 Security · benchmark_report.php:31,48-53
**What:** The state-changing `pinbaseline` action (writes `is_baseline=1` + inserts a
`bx_agent_benchmark_baselines` row) is authorised only by the **read** capability
`bookingextension/agent:viewbenchmarks`, not by a write capability.
**Evidence:** The page gates with `require_capability('bookingextension/agent:viewbenchmarks', context_system::instance())`
(line 31). The only write branch is `if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey()) { … $writer->pin_baseline(...) }`
(lines 48-53) — no second `require_capability` for the write. A dedicated write cap
`bookingextension/agent:managebenchmarks` exists in `db/access.php:226` but is never used.
**Impact:** A user holding only the read-only `viewbenchmarks` cap (e.g. a manager delegated report
viewing) can repoint which run is the CI comparison baseline.
**Compensating control:** Strong. `confirm_sesskey()` blocks CSRF; the cap is system-context and
manager-archetype only; the only thing mutated is the benchmark baseline used by the CI gate — **no
PII, no booking data, no user-facing mutation**. The CI gate is an internal tooling signal.
**Recommendation:** Require `bookingextension/agent:managebenchmarks` on the `pinbaseline` branch,
keeping `viewbenchmarks` for read. This also gives `managebenchmarks` its intended consumer (18-F02).
**Cross-ref:** C1-F01 / C2-F03 rate the same issue HIGH at the whole-tree level; the residual here is
benchmark-tooling-only, but defer to C1-F01's HIGH for the executive blocker list.

### [18-F02] 🟢 LOW · D3 Structure · db/access.php:226-240 (+ run_benchmark feature)
**What:** The "Run a benchmark from the interface" feature is half-implemented and its two
capabilities are dead. `bookingextension/agent:managebenchmarks` and
`bookingextension/agent:runbenchmarks` are defined but **enforced nowhere**.
**Evidence:** `grep -rn 'runbenchmarks\|managebenchmarks'` over `classes/ cli/ *.php` (excluding
`db/access.php` and `lang/`) returns **zero** matches. The `runbenchmarks` cap's own comment
(`db/access.php:232`) says it gates "the 'Run benchmark' button on benchmark_report.php" — but
`benchmark_report.php` has no such button and never references the cap or queues the task.
**Impact:** Two defined capabilities have no effect; the documented read/write split is not realised.
**Compensating control:** n/a — no security exposure (an unenforced cap denies nothing).
**Recommendation:** Either wire the feature (web button → cap check `runbenchmarks` →
`queue_adhoc_task(run_benchmark_adhoc)` with the preview) or remove the two caps, the task, the
service and the preview class. Confirmed-dead grep performed across `classes/` + root `*.php`.

### [18-F03] 🟢 LOW · D3 Structure · classes/local/wizard/benchmark/benchmark_provider_preview.php
**What:** `benchmark_provider_preview` has **zero callers** — it is the unrendered preview half of the
unwired feature in 18-F02.
**Evidence:** `grep -rn 'benchmark_provider_preview\|->describe('` (non-worktree) returns only the
class definition and a `@see` docblock reference in `benchmark_run_service.php:39`. No page, task, WS
or test instantiates it. `benchmark_run_service` itself is reached only by `run_benchmark_adhoc`,
which is itself never queued (18-F02), so the service is reachable only in principle.
**Impact:** Dead class (~135 LOC).
**Compensating control:** n/a.
**Recommendation:** Remove with the rest of the unwired feature, or wire a page that renders it.
(Not flagged as a false-positive "dead entry point": this is a plain service class, not a
framework-invoked `execute()`.)

### [18-F04] 🟢 LOW · D2 Moodle API · benchmark_report.php, benchmark_run_detail.php, benchmark_compare.php
**What:** The three admin pages escape output with raw `htmlspecialchars()` instead of Moodle's
`s()` / `format_string()`.
**Evidence:** e.g. `benchmark_report.php:223` `htmlspecialchars($run->label)`, `:225`
`htmlspecialchars($run->model_id)`, `:231` `htmlspecialchars(substr($run->git_ref, 0, 8))`;
`benchmark_run_detail.php:118` `htmlspecialchars((string)$v)`; `benchmark_compare.php:129,135,137`.
**Impact:** Non-idiomatic; `s()` is the Moodle convention and handles `null`/encoding consistently.
Not a security hole — `htmlspecialchars()` does escape the HTML metacharacters.
**Compensating control:** Values are admin/CLI/import-sourced, not end-user input.
**Recommendation:** Replace `htmlspecialchars(...)` with `s(...)`.

### [18-F05] 🟢 LOW · D2 Moodle API · benchmark_run_detail.php:39-42
**What:** The run label is double-escaped: it is pre-escaped with `htmlspecialchars()` and then passed
into `$PAGE->set_heading()` (and `set_title` consumers), which escapes again.
**Evidence:** `set_heading(get_string('benchmark_run_detail_heading', …, (object)['id'=>$id, 'label'=>htmlspecialchars($run->label)]))`
— `set_heading()` renders through `format_string()`, so an `&` in a label becomes `&amp;amp;`.
**Impact:** Cosmetic mangling of labels containing HTML metacharacters in the page heading.
**Compensating control:** Labels are admin-set and rarely contain `&`/`<`.
**Recommendation:** Pass the raw `$run->label` into the lang string; let the page layer escape once.

### [18-F06] 🟡 MEDIUM · D4 Duplication · benchmark_run_service.php:54-248 vs cli/benchmark_runner.php:99-343
**What:** `benchmark_run_service::run()` is a near-verbatim duplicate of the scenario-execution loop
in `cli/benchmark_runner.php`. The service's docblock claims "the CLI now delegates here", but the CLI
still contains its own full copy and does **not** call the service.
**Evidence:** Both carry the identical option-parsing block, the identical `di::set(ai_manager…)`
env-key injection, the identical `create_fresh_thread → add_message → orchestrator::process →
SELECT … FROM {bx_agent_ai_llm_debug} WHERE source LIKE 'orc|p=sel%'` per-scenario body, the identical
stub-JSON fallback, and the identical metrics/write_run tail. The same selector-debug SQL string and
the same `archived` `set_field` appear in both (`benchmark_runner.php:227-238`,
`benchmark_run_service.php:157-167`). The metric-unit→suffix map (`percent`→`%`, `ms`→`ms`,
`tokens`→``) is also repeated across `benchmark_run_detail.php:143-150` and `benchmark_compare.php:185-193`.
**Impact:** Two copies of the harness body drift independently (the runner already diverges: it prints
the extra "Credentials:" / per-sub-metric summary the service omits). A fix to the LLM-debug capture
or thread-archival must be applied twice.
**Compensating control:** Behaviour is currently equivalent; both are off the request path.
**Recommendation:** Make `cli/benchmark_runner.php` delegate to `benchmark_run_service::run()` with a
`cli_writeln` progress callback, as the docblock already claims. Extract the unit→suffix map to a
shared helper.

### [18-F07] ⚪ INFO · D1 Security · benchmark_envkey_manager.php, benchmark_provider_preview.php, benchmark_runner.php
**What:** Confirmed-correct: benchmark API-key handling never logs or persists the real key.
**Evidence:** `benchmark_envkey_manager` reads `BOOKING_TEST_AI_KEY` via `getenv` and injects it only
into an in-memory cloned provider config (`patch_provider_for_env`, lines 97-127) — "No DB writes are
performed" (docblock + verified: no `$DB->...` write, no `set_config`). `benchmark_runner.php:163-168`
prints only the literal `"BOOKING_TEST_AI_KEY set"`, never the value. `benchmark_provider_preview::describe()`
returns the key's *source label* (`'env'` / a `get_string`), never the key bytes (lines 107-113).
**Impact:** None. **Recommendation:** None.

### [18-F08] 🟡 MEDIUM · D6 Docs coverage · docs/operations/benchmarking.md §1-§2,§5
**What:** The benchmarking chapter materially misdescribes the scenario inventory and omits the
run-from-interface surface.
**Evidence:**
- §1 lists `confirmation_request_r1` and `budget_exceeded` as members of `core_booking_v1` —
  **neither file exists** under `scenarios/`. It omits `catalog_gap_bulk_cancel` and
  `ambiguous_request_no_hallucination`, which **are** in the registry (`benchmark_scenario_registry.php:55,61`).
- §1/§2 state the set is `core_booking_v1` with "15 scenarios" and is the default; the CLI runner
  default is `decision_core` (`benchmark_runner.php:57`, 10 keys), and `build_full_universe()` auto-
  discovers every `scenarios/route_*.php` (~30+) — the chapter never mentions the route-cluster set or
  the `--tier` probabilistic/deterministic filter that both the runner and service apply.
- §5 documents the report pages but omits the (currently unwired, see 18-F02) "run benchmark from
  interface" feature, the `runbenchmarks`/`managebenchmarks` capabilities, and `benchmark_provider_preview`.
**Impact:** A reader cannot reconstruct what actually runs from the chapter.
**Compensating control:** Source is self-describing.
**Recommendation:** Regenerate §1 from `benchmark_scenario_registry` + the glob, document `decision_core`
as the default and the `--tier` filter, and either describe or drop the run-from-interface feature once
18-F02 is resolved.

---

## C. Per-file / per-method checklist

#### `benchmark_report.php`
- [ ] D1 (18-F01) [ ] D2 (18-F04) [x] D3 [x] D4 [n/a] D5 [x] D6 — file-level: `require_login` + `require_capability(viewbenchmarks)` + `confirm_sesskey` on pin; SQL parameterised; chart via Moodle Chart API.
- procedural: `pinbaseline` action → 18-F01; runs/trend queries clean; output escaping → 18-F04.

#### `benchmark_run_detail.php`
- [x] D1 [ ] D2 (18-F05/04) [x] D3 [x] D4 [n/a] D5 [x] D6 — `require_capability('moodle/site:config')`; `required_param('id', PARAM_INT)` + `MUST_EXIST`; pin form carries sesskey hidden field.

#### `benchmark_compare.php`
- [x] D1 [ ] D2 (18-F04) [x] D3 [ ] D4 (18-F06 unit map) [n/a] D5 [x] D6 — `require_capability('moodle/site:config')`; `required_param/optional_param` PARAM_INT; `single_select` escapes options.

#### `cli/benchmark_runner.php`
- [x] D1 [x] D2 [x] D3 [ ] D4 (18-F06) [n/a] D5 [ ] D6 (18-F08) — `define('CLI_SCRIPT', true)`; `cli_get_params`; SQL named-param; duplicated harness body (18-F06).

#### `cli/benchmark_aggregate.php`  ·  `cli/benchmark_matrix.php`
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — CLI-guarded; `sql_like`/`get_in_or_equal` parameterised; read-only reporting.

#### `cli/benchmark_ci_gate.php`
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — CLI-guarded; exit 0/1/2 per spec; reads metrics + baseline.

#### `cli/benchmark_export.php`  ·  `cli/benchmark_import.php`
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — CLI-guarded; export via `MUST_EXIST`; import idempotent on `run_uuid`, strips `id`, version-checked. `file_put_contents`/`file_get_contents` take CLI-supplied paths (trusted shell).

#### `cli/rebuild_embeddings_fixture.php`
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — CLI-guarded; delegates CSV I/O to `embeddings_csv_repository` (no bespoke parsing); `get_admin()` for embed actor.

#### `cli/skill_selection_dataset.php`  ·  `cli/skill_selection_debug.php`
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — CLI-guarded; `--file` read with `is_readable` guard; delegates to `skill_selection_debug_service`.

#### `classes/local/wizard/benchmark/benchmark_db_writer.php` (class `benchmark_db_writer`)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — file-level: all `$DB` calls parameterised; UUID via `random_bytes`.
- methods: `write_run()` ✓ · `pin_baseline()` ✓ · `get_latest_baseline()` ✓ · `purge_old_runs()` ✓ (deletes scenarios+metrics+runs, keeps baselines via `is_baseline=0` filter) · `private generate_uuid()` ✓

#### `classes/local/wizard/benchmark/benchmark_result_collector.php` (class `benchmark_result_collector`)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6
- methods: `evaluate()` ✓ · `private decode_response_tolerantly()` ✓ (delegates to `shared_json_payload_extractor`) · `private check_contract_compliance()` ✓

#### `classes/local/wizard/benchmark/benchmark_metrics_calculator.php` (class `benchmark_metrics_calculator`)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6
- methods: `calculate()` ✓ · `compare()` ✓ · `has_critical_regression()` ✓ · `get_thresholds()` ✓ (admin-config override) · `private pct()` ✓ · `private percentile()` ✓

#### `classes/local/wizard/benchmark/benchmark_scenario_registry.php` (class `benchmark_scenario_registry`)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — `DECISION_CORE` keys verified against `get_key()` (incl. `skill_not_in_catalog`→`skill_not_in_catalog_no_hallucination`); `build_full_universe()` glob is sorted for determinism.
- methods: `get_scenarios()` ✓ · `private build_full_universe()` ✓

#### `classes/local/wizard/benchmark/benchmark_run_service.php` (class `benchmark_run_service`)
- [x] D1 [x] D2 [x] D3 [ ] D4 (18-F06) [n/a] D5 [x] D6 — `run()` duplicates the CLI runner body.

#### `classes/local/wizard/benchmark/benchmark_envkey_manager.php` (class `benchmark_envkey_manager`)
- [x] D1 (18-F07 confirm) [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — env-key never persisted/logged; reflection clone via `provider->with()`.
- methods: `get_sorted_providers()` ✓ · `private patch_provider_for_env()` ✓ · `get_providers_for_actions()` ✓

#### `classes/local/wizard/benchmark/benchmark_provider_preview.php` (class `benchmark_provider_preview`)
- [x] D1 (18-F07) [x] D2 [ ] D3 (18-F03 zero callers) [x] D4 [n/a] D5 [ ] D6 (18-F08)
- methods: `describe()` ✓ (returns key *source*, never bytes)

#### `classes/local/wizard/benchmark/benchmark_scenario_interface.php` (interface)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — pure contract.

#### `classes/local/wizard/benchmark/abstract_benchmark_scenario.php` (abstract class)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — sensible defaults; `setup_state()` no-op default.

#### `classes/local/wizard/benchmark/abstract_routing_scenario.php` (abstract class)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — `is_mutating()` carries booking-domain skill-name heuristics, but this is the **harness** (allowed to know the domain it measures), not the engine — INFO, not an engine→domain leak.
- methods: `get_forbidden_siblings()` ✓ · `is_mutating()` ✓ · `get_acceptable_response_types()` ✓ · `get_class()` ✓ · `get_expected_response_type()` ✓ · `get_stub_selector_response()` ✓ · `assert_additional()` ✓

#### `classes/local/wizard/benchmark/scenarios/*.php` (42 files, sampled `catalog_gap_bulk_cancel`, `skill_not_in_catalog`, `ambiguous_request_no_hallucination`)
- [x] D1 [x] D2 [x] D3 [ ] D4 [n/a] D5 [x] D6 — file-level (data classes): each implements the scenario contract; getters return literals; no DB/IO/secrets. **D4:** the 42 files are highly repetitive scaffolding (identical getter shapes) — acceptable per scope note ("large scenario set — dead/duplicated scenario scaffolding acceptable but note"). Noted, not separately filed.

#### `classes/task/run_benchmark_adhoc.php` (class `run_benchmark_adhoc`)
- [x] D1 [x] D2 [ ] D3 (18-F02: never queued) [x] D4 [n/a] D5 [ ] D6 (18-F08) — `execute()` is a framework entry point (not flagged dead per guardrail), but nothing queues it.

#### `classes/task/cleanup_old_benchmark_runs_task.php` (class `cleanup_old_benchmark_runs_task`)
- [x] D1 [x] D2 [x] D3 [x] D4 [n/a] D5 [x] D6 — registered in `db/tasks.php`; reads `benchmark_retention_days`; delegates to `purge_old_runs()`.

---

## D. Go-live blockers from this section

**None.** This tier is off the live request path and contains no BLOCKER.

For the executive summary, the items worth gating on (already tracked at the whole-tree level):

- **18-F01 / C1-F01** — ✅ FIXED 2026-06-30. `pinbaseline` write gated by the read cap `viewbenchmarks` (C1 rated HIGH;
  residual here was benchmark-baseline-only + sesskey + manager-context, so MEDIUM in isolation).
  Fixed with `require_capability('…:managebenchmarks')` on the write branch, which also resolves the dead cap (18-F02).

All other findings (18-F02..F08) are LOW/MEDIUM cleanup/doc items, not launch gates.
