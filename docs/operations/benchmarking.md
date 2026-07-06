# Operations · Benchmarking

> **Scope.** The benchmark harness: scenarios, runner, metrics, the CI gate, and the report
> pages. It exists to catch regressions in routing/selection/execution behavior before they
> ship.

**Files:** `classes/local/wizard/benchmark/*` (+ `scenarios/*`), `benchmark_report.php`,
`benchmark_compare.php`, `benchmark_run_detail.php`, `cli/benchmark_*`,
`task/cleanup_old_benchmark_runs_task.php`.

---

## 1. Scenarios

A scenario is a fixed input with an expected outcome (response type, selected skill, JSON
validity, …). `benchmark_scenario_registry` ships the **`core_booking_v1`** set of 15
scenarios:

`create_option_basic`, `create_option_multistep`, `update_option_trainer`,
`book_users_single`, `short_confirm_ja`, `short_confirm_weiter`, `clarification_missing_date`,
`confirmation_request_r1`, `duplicate_prevention`, `readonly_diagnose`, `skill_not_in_catalog`,
`auto_confirm_session`, `retry_preflight_recovery`, `budget_exceeded`,
`get_current_user_readonly`.

Each implements `benchmark_scenario_interface` (via `abstract_benchmark_scenario`); seed data
is provided by `benchmark_seed_data`.

---

## 2. Running

`cli/benchmark_runner.php` builds the orchestrator stack once and runs each scenario,
collecting results and metrics, then writes a run + per-scenario rows + metrics to the
[benchmark tables](../developer-guides/data-model-and-db.md#5-benchmark-tables). Useful
options:

```
--scenario-set=core_booking_v1   --model=claude-sonnet-4-6   --label=release-x.y.z
--env=local|ci|staging           --git-ref=abc1234           --stub   (no live LLM)
--pin-baseline --baseline-label=stable-x.y.z   --cmid=25 --userid=2
```

`--stub` runs against stub responses (deterministic, no provider cost). `--pin-baseline`
marks the run as the comparison baseline.

---

## 3. Metrics & thresholds

`benchmark_metrics_calculator` aggregates per-scenario results into rates. The CI thresholds
(also admin-configurable) are:

| Metric | Threshold |
|--------|-----------|
| skill hit rate | ≥ **90 %** (`benchmark_threshold_skill_hit_rate`) |
| JSON validity | ≥ **99 %** (`benchmark_threshold_json_validity`) |
| contract compliance | ≥ 98 % |
| response-type accuracy | ≥ 95 % |
| planned-steps coverage | ≥ 95 % |
| end-to-end success | ≥ **85 %** (`benchmark_threshold_e2e_success`) |

It also tracks latency and tokens per scenario.

---

## 4. The CI gate

`cli/benchmark_ci_gate.php [--run-id=N]` loads a run's metrics, compares against the latest
baseline, and exits **0** if every gated metric meets its threshold, **1** on a critical
regression (blocks rollout), **2** if no run is found. This is the hook a CI pipeline calls
after `benchmark_runner.php`.

---

## 5. Reports & retention

| Page | Capability | Shows |
|------|------------|-------|
| `benchmark_report.php` | `…:viewbenchmarks` | paginated runs + a trend chart (skill-hit / JSON / e2e over time) + pin-baseline |
| `benchmark_run_detail.php` | `moodle/site:config` | one run's per-scenario table |
| `benchmark_compare.php` | `moodle/site:config` | side-by-side diff of two runs |

`cli/benchmark_export.php` / `benchmark_import.php` move datasets between environments;
`cleanup_old_benchmark_runs_task` enforces `benchmark_retention_days` (baselines preserved).
`cli/skill_selection_dataset.php` and `cli/rebuild_embeddings_fixture.php` support
selection-quality datasets and embedding fixtures.
