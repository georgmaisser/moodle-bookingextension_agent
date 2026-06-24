# WS2: Benchmarking und Performance-Messung — Implementierungsplan

Status: Abgeschlossen (ohne E3)
Owner: bookingextension_agent Team
Ziel: Modell- und Agent-Performance versioniert speichern, historisch vergleichen, Regressionen automatisch erkennen.

---

## Architekturüberblick

```
Benchmark Runner (CLI)
  └─ Scenario Definitions (PHP / YAML)
       └─ LLM Stub / Live Provider
            └─ Result Collector
                 └─ DB: benchmark_runs + benchmark_metrics + benchmark_baselines
                          └─ Report Page (Moodle Admin)
                               ├─ Run-Übersicht (Tabelle + Filter)
                               ├─ Run-Vergleich (Delta zur Baseline)
                               ├─ Trend-Chart (historisch)
                               └─ Ampelstatus (grün/gelb/rot pro Metrik)
```

---

## A — DB-Schema

### A1 — Tabelle `local_wizard_benchmark_runs`

Einen Eintrag pro Benchmark-Lauf (eine Run = ein vollständiges Durchlaufen aller Szenarien).

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| id | INT PK | Auto-increment |
| run_uuid | VARCHAR(36) | UUID, stable identifier across envs |
| label | VARCHAR(120) | Human-readable label (e.g. "release-5.1.2-pre") |
| model_id | VARCHAR(80) | e.g. "claude-sonnet-4-6" |
| model_version | VARCHAR(40) | Provider-version hash if available |
| prompt_profile | VARCHAR(80) | Prompt variant name (e.g. "plain_text_catalog_v1") |
| skill_set | VARCHAR(80) | Scenario set name (e.g. "core_booking_v1") |
| total_scenarios | INT | Number of scenarios executed |
| passed | INT | Scenarios where expected outcome matched |
| failed | INT | Scenarios where outcome diverged |
| skipped | INT | Scenarios not executed (timeout, provider error) |
| success_rate | DECIMAL(5,2) | passed / total_scenarios * 100 |
| baseline_run_id | INT NULL | FK → id of baseline run for comparison |
| is_baseline | TINYINT(1) | 1 = this run is a pinned baseline |
| regression_detected | TINYINT(1) | 1 = ≥1 critical metric regressed vs baseline |
| total_tokens | INT | Sum of prompt+completion tokens across all scenarios |
| total_cost_estimate | DECIMAL(10,4) | Estimated cost in EUR/USD |
| duration_ms | INT | Total wall-clock time for the run |
| timecreated | BIGINT | Unix timestamp |
| environment | VARCHAR(80) | "ci", "local", "staging" |
| git_ref | VARCHAR(80) | Git commit SHA or branch |

### A2 — Tabelle `local_wizard_benchmark_scenario_results`

Ein Eintrag pro Szenario pro Run.

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| id | INT PK | Auto-increment |
| run_id | INT FK | → benchmark_runs.id |
| scenario_key | VARCHAR(120) | e.g. "create_option_basic", "multistep_trainer_booking" |
| scenario_class | VARCHAR(40) | "readonly", "mutation_r1", "mutation_r2", "error_retry", "multistep" |
| passed | TINYINT(1) | 1 = outcome matched golden label |
| response_type_expected | VARCHAR(40) | Expected response_type |
| response_type_actual | VARCHAR(40) | Actual response_type |
| skill_selected | VARCHAR(120) | Task selected by selector |
| skill_expected | VARCHAR(120) | Expected task |
| json_valid | TINYINT(1) | Selector/constructor output was valid JSON |
| contract_compliant | TINYINT(1) | Output passed contract validation |
| planned_steps_present | TINYINT(1) | planned_steps field present for multi-step scenarios |
| tokens_prompt | INT | Prompt tokens for this scenario |
| tokens_completion | INT | Completion tokens |
| duration_ms | INT | Wall-clock time for this scenario |
| step_count | INT | Number of planner steps taken |
| error_message | TEXT NULL | Error detail if failed |
| result_json | MEDIUMTEXT | Full normalized result payload |
| timecreated | BIGINT | Unix timestamp |

### A3 — Tabelle `local_wizard_benchmark_baselines`

Pinned baselines als Referenzpunkte für Vergleiche.

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| id | INT PK | Auto-increment |
| run_id | INT FK | → benchmark_runs.id |
| label | VARCHAR(120) | e.g. "stable-5.1.0", "pre-release-5.2" |
| locked | TINYINT(1) | 1 = baseline darf nicht überschrieben werden |
| description | TEXT | Freitext-Notiz |
| timecreated | BIGINT | Unix timestamp |
| createdby | INT | Moodle userid |

### A4 — Tabelle `local_wizard_benchmark_metric_snapshots`

Aggregierte Metriken pro Run (für schnelle Trend-Abfragen ohne JSON-Parsing).

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| id | INT PK | Auto-increment |
| run_id | INT FK | → benchmark_runs.id |
| metric_key | VARCHAR(80) | e.g. "skill_hit_rate", "json_validity_rate", "p95_duration_ms" |
| metric_value | DECIMAL(10,4) | Numeric value |
| metric_unit | VARCHAR(20) | "percent", "ms", "count", "tokens" |
| scenario_class | VARCHAR(40) | NULL = global, or filtered by class |
| timecreated | BIGINT | Unix timestamp |

---

## B — Benchmark Runner (CLI)

### B1 — Runner-Architektur

```
php benchmark_runner.php [--scenario-set=core_booking_v1] [--model=claude-sonnet-4-6] [--env=local] [--baseline=auto]
```

- [x] **B1a** `classes/local/wizard/benchmark/benchmark_runner.php` — Haupt-Entry-Point:
  - Lädt Scenario-Set
  - Iteriert Szenarien
  - Ruft LLM auf (live oder stub)
  - Sammelt Results
  - Schreibt in DB (benchmark_runs + scenario_results + metric_snapshots)
  - Gibt Exit-Code 0 (kein Regression) oder 1 (Regression) zurück

- [x] **B1b** `classes/local/wizard/benchmark/benchmark_result_collector.php` — Result-Aggregation:
  - Vergleicht actual vs. expected per Szenario
  - Berechnet Gesamt-Metriken
  - Erkennt Regressionen gegenüber Baseline

- [x] **B1c** `classes/local/wizard/benchmark/benchmark_db_writer.php` — DB-Persistierung:
  - Schreibt run + scenario_results + metric_snapshots atomar
  - Gibt run_id zurück

### B2 — Scenario-Definitionen

- [x] **B2a** `benchmark/scenarios/core_booking_v1/` — PHP-Klassen-basierte Szenarien:
  - Jede Klasse implementiert `benchmark_scenario_interface`
  - Felder: `scenario_key`, `scenario_class`, `user_message`, `expected_response_type`, `expected_task`, `expected_planned_steps_count`, `golden_observations`

- [x] **B2b** Mindest-Szenario-Set `core_booking_v1` (≥15 Szenarien):
  - [x] `create_option_basic` — einfaches Datum+Titel
  - [x] `create_option_multistep_trainer_booking` — 4 Schritte: create×2 + trainer + book
  - [x] `update_option_trainer_by_name` — Trainer-Zuweisung via Name
  - [x] `book_users_single` — einzelner User buchen
  - [x] `diagnose_booking_issue` — Readonly Diagnose
  - [x] `clarification_missing_date` — fehlende Pflichtfelder → clarification
  - [x] `short_confirm_ja` — "ja" nach multi-step intent → korrekte Task-Auswahl
  - [x] `short_confirm_weiter` — "mach weiter" Kurzbestätigung
  - [x] `duplicate_prevention` — gleiche Option zweimal → skip/sufficient
  - [x] `confirmation_request_r1` — R1-Mutation → confirmation_request
  - [x] `auto_confirm_session` — R1 mit session-allow → autoconfirm
  - [x] `retry_preflight_recovery` — transient error → retry → success
  - [x] `budget_exceeded` — MAX_LOOP_STEPS erreicht → BUDGET_EXCEEDED
  - [x] `task_not_in_catalog` — Task nicht im Katalog → clarification (nicht halluzinieren)
  - [x] `get_current_user_readonly` — R0 direkt ausführen ohne Confirmation

- [x] **B2c** `benchmark/scenarios/scenario_seed_data.php` — Seed-Daten für reproduzierbare Tests:
  - Feste Option-IDs, User-IDs (anonymisiert), Thread-IDs
  - DB-unabhängig (kein echter Moodle-DB-Zugriff nötig für LLM-Tests)

### B3 — LLM-Aufruf im Runner

- [x] **B3a** `benchmark/benchmark_llm_adapter.php` — Adapter-Interface:
  - Live-Mode: ruft echten Provider via `llm_call_service`
  - Stub-Mode: liefert vorberechnete Responses (für strukturelle Tests ohne API-Kosten)

- [x] **B3b** Stub-Response-Bibliothek für alle 15 Kern-Szenarien

---

## C — Report-Seite (Moodle Admin)

### C1 — Run-Übersicht

- [x] **C1a** `report/benchmark_report.php` — Moodle-Admin-Seite mit:
  - Tabelle aller Runs: label, model_id, prompt_profile, success_rate, tokens, cost, duration, baseline-Flag, Regressionswarnung
  - Filter: Datum, model, environment, scenario_class
  - Sortierung: timecreated DESC (default)
  - Pagination (50 per page)

- [x] **C1b** Ampelstatus pro Metrik in der Tabelle:
  - 🟢 ≥ 95% success_rate / keine Regression
  - 🟡 90–95% / Warnung
  - 🔴 < 90% / Regression oder critical failure

- [x] **C1c** "Als Baseline setzen"-Button pro Run (nur Admin)

### C2 — Run-Detail und Vergleich

- [x] **C2a** `report/benchmark_run_detail.php?run_id=X` — Detailansicht eines Runs:
  - Header: run_uuid, label, model, git_ref, timecreated, totals
  - Tabelle aller scenario_results: key, class, passed, response_type expected/actual, task expected/actual, tokens, duration
  - Filter: passed/failed, scenario_class
  - JSON-Expand für result_json pro Szenario

- [x] **C2b** `report/benchmark_compare.php?run_a=X&run_b=Y` — Delta-Vergleich zweier Runs:
  - Side-by-side Tabelle: metric_key | Run A | Run B | Delta | Status
  - Szenarien die in einem Run pass/fail sind und im anderen nicht
  - Hervorhebung von Regressionen (rot) und Verbesserungen (grün)

- [x] **C2c** Baseline-Vergleich automatisch einblenden wenn `baseline_run_id` gesetzt

### C3 — Trend-Chart (historisch)

- [x] **C3a** Zeitreihe für key metrics über alle gespeicherten Runs:
  - X-Achse: timecreated
  - Y-Achse: success_rate, json_validity_rate, avg_tokens, avg_duration_ms
  - Linie pro metric_key
  - Baseline-Marker als vertikale Linie

- [x] **C3b** Chart-Implementierung: Moodle-native Chart API (`\core\chart_line`)

- [x] **C3c** Trend-Tabelle (ohne JS-Chart-Dependency): letzte 20 Runs als kompakte Tabelle mit Sparkline-artigem Delta

### C4 — Navigation und Capabilities

- [x] **C4a** Moodle-Capability `bookingextension_agent:viewbenchmarks` — sichtbar für Site-Admin + Agent-Manager
- [x] **C4b** Moodle-Capability `bookingextension_agent:managebenchmarks` — Baseline setzen, Runs löschen
- [x] **C4c** Link in `bookingextension_agent` Admin-Navigationsblock

---

## D — Historische Datenspeicherung

- [x] **D1** `db/install.xml` — 4 neue Tabellen: `local_wizard_benchmark_runs`, `_scenario_results`, `_baselines`, `_metric_snapshots`
- [x] **D2** Retention-Policy: Runs älter als N Tage (konfigurierbar, default 365) werden automatisch bereinigt — außer Baselines (immer permanent)
- [x] **D3** `task/cleanup_old_benchmark_runs_task.php` — Scheduled Task für Retention-Policy
- [x] **D4** Export-Funktion: Run als JSON exportieren (für CI-Artefakte und manuelle Archivierung)
- [x] **D5** Import-Funktion: Exportierten Run re-importieren (für Cross-Environment-Vergleiche)

---

## E — CI-Gate

- [x] **E1** `benchmark/ci_gate.php` — Exit-Code-basierter Gate für CI:
  - Liest letzten Benchmark-Run aus DB
  - Vergleicht gegen pinned Baseline
  - Exit 0 wenn kein kritischer Rückgang, Exit 1 sonst
  - Konfigurierbare Schwellwerte: `skill_hit_rate < 90%`, `json_validity < 95%`, `success_rate < 85%`

- [x] **E2** CI-Konfiguration (GitHub Actions / Gitlab CI):
  ```yaml
  benchmark:
    runs-on: ubuntu-latest
    steps:
      - run: php benchmark_runner.php --env=ci --scenario-set=core_booking_v1
      - run: php benchmark/ci_gate.php --fail-on-regression
  ```

- [ ] **E3** Regression-Benachrichtigung: Bei Exit 1 → Slack/Email mit Run-URL und Delta-Summary

---

## F — Metriken-Berechnung

### Modell-Metriken (werden aus scenario_results aggregiert)

| Metrik | Definition | Zielwert |
|--------|-----------|---------|
| `skill_hit_rate` | skill_selected == skill_expected / total | ≥ 90% |
| `json_validity_rate` | json_valid = 1 / total | ≥ 99% |
| `contract_compliance_rate` | contract_compliant = 1 / total | ≥ 98% |
| `response_type_accuracy` | response_type_actual == expected / total | ≥ 95% |
| `planned_steps_coverage` | planned_steps_present = 1 / multistep scenarios | ≥ 95% |
| `avg_tokens_per_scenario` | avg(tokens_prompt + tokens_completion) | Trendmonitoring |
| `avg_cost_per_scenario` | avg(cost_estimate) | Trendmonitoring |

### Agent-Metriken (werden aus benchmark_runs aggregiert)

| Metrik | Definition | Zielwert |
|--------|-----------|---------|
| `e2e_success_rate` | passed / total_scenarios * 100 | ≥ 85% |
| `clarification_rate` | scenarios ending in clarification / total | ≤ 10% |
| `retry_rate` | scenarios with retry events / total | ≤ 5% |
| `p50_duration_ms` | Median duration per scenario | Trendmonitoring |
| `p95_duration_ms` | 95th percentile duration | ≤ 8000ms |
| `avg_step_count` | avg(step_count) per scenario | ≤ 3 |
| `multistep_completion_rate` | multistep scenarios fully completed / total multistep | ≥ 80% |

- [x] **F1** `benchmark/benchmark_metrics_calculator.php` — Berechnung aller obigen Metriken aus DB-Daten
- [x] **F2** Metriken werden nach jedem Run in `benchmark_metric_snapshots` geschrieben
- [x] **F3** Schwellwerte konfigurierbar via Moodle Admin Config (nicht hardcoded)

---

## G — Definition of Done

- [x] 15+ Kern-Szenarien implementiert und reproduzierbar lauffähig
- [x] DB-Schema deployed via `install.xml`
- [x] Benchmark-Runner erzeugt validen DB-Run mit allen Metriken
- [x] Report-Seite zeigt Run-Übersicht, Detail und Baseline-Vergleich
- [x] Trend-Chart zeigt letzte 30 Runs korrekt
- [x] CI-Gate gibt Exit 1 bei kritischer Regression
- [x] Baseline-Pin und -Vergleich funktioniert
- [x] Export/Import-Roundtrip ist verlustfrei
- [x] Retention-Policy löscht alte Runs (außer Baselines)
- [x] Capabilities korrekt registriert und enforced

---

## H — Priorisierung und Reihenfolge

| Prio | Schritt | Aufwand | Impact |
|------|---------|---------|--------|
| P0 | A (DB-Schema) | Klein | Fundament |
| P0 | B2 (Szenarien definieren) | Mittel | Kern |
| P0 | B1 (Runner) | Mittel | Ausführung |
| P0 | F (Metriken) | Klein | Auswertung |
| P1 | C1 (Report-Übersicht) | Mittel | Sichtbarkeit |
| P1 | C2 (Detail + Vergleich) | Mittel | Analyse |
| P1 | D (Historische Daten) | Klein | Persistenz |
| P2 | C3 (Trend-Chart) | Mittel | Langzeit-Trends |
| P2 | E (CI-Gate) | Klein | Automation |
| P2 | C4 (Capabilities/Nav) | Klein | Rollout |
