# Benchmark Files Compliance & Refactoring Analysis

This report presents a thorough analysis of the benchmark reporting files in `bookingextension_agent` against Moodle's coding standards, architectural best practices, and security guidelines.

---

## 1. Compliance Assessment Table

| Compliance Area | Status | Comments / Findings |
| :--- | :---: | :--- |
| **Language String Compliance** | ❌ **Non-Compliant** | Almost all UI headings, page titles, table headers, labels, and status messages are hardcoded in English inside the PHP files rather than loaded via `get_string()`. |
| **Table rendering API** | ❌ **Non-Compliant** | Raw HTML tables (`<table>`, `<tr>`, `<td>`) are echo'ed directly, bypassing Moodle's `html_table` object and `html_writer::table()` renderer. |
| **Form & Navigation Components** | ⚠️ **Suboptimal** | `benchmark_compare.php` uses raw HTML forms and dropdown loops rather than Moodle's standardized `single_select` UI component. |
| **Database Access (`$DB`)** | ✅ **Compliant** | Queries use standard `$DB` methods, appropriate placeholders, and bind parameters securely. No raw SQL injection risks were found. |
| **Moodle Form API (`mform`)** | ✅ **Not Required** | Input requirements are limited to single-parameter actions/redirections. Moodle Form API is not needed; `single_select` is sufficient. |
| **CLI Script Guidelines** | ✅ **Compliant** | `cli/benchmark_runner.php` strictly follows CLI script rules (defines `CLI_SCRIPT`, imports `clilib.php`, uses `cli_get_params()`). |
| **Web Services** | ✅ **Not Required** | These are standard administrator report pages (layout `admin`), so rendering HTML directly via page controllers is appropriate. |

---

## 2. Identified Issues & UX Gaps

### A. Missing "Pin Baseline" UI
`benchmark_report.php` handles a `pinbaseline` action:
```php
if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey()) { ... }
```
However, there is no button or link anywhere in the UI of `benchmark_report.php`, `benchmark_run_detail.php`, or `benchmark_compare.php` to invoke it. Baselines can only be pinned by manually fabricating the URL query parameters.

### B. Hardcoded Strings
Key titles, buttons, and status labels are hardcoded, e.g.:
* `Compare Benchmark Runs`
* `No comparison run available.`
* `Metric Delta (A vs B)`
* `Scenario Differences`

### C. Direct HTML Generation
Echoing raw HTML strings makes layouts fragile and ignores changes in Moodle's core Bootstrap themes.
Example:
```php
echo '<table class="table table-bordered table-sm">';
echo '<thead><tr><th>Metric</th><th>Run A</th><th>Run B</th>...';
```
Should be:
```php
$table = new html_table();
$table->head = [
    get_string('benchmark_metric', 'bookingextension_agent'),
    get_string('benchmark_run_a', 'bookingextension_agent'),
    ...
];
// ... populate $table->data
echo html_writer::table($table);
```

---

## 3. Recommended Refactoring Plan

We can implement these improvements in a single pass across the three benchmark reporting files:

### Step 1: Update Language Strings
Define the required string keys in [bookingextension_agent.php](file:///var/www/moodle/public/mod/booking/bookingextension/agent/lang/en/bookingextension_agent.php):
```php
$string['benchmark_run'] = 'Run';
$string['benchmark_runs'] = 'Benchmark Runs';
$string['benchmark_compare_runs'] = 'Compare Benchmark Runs';
$string['benchmark_run_detail'] = 'Benchmark Run #{$a}';
...
```

### Step 2: Refactor [benchmark_compare.php](file:///var/www/moodle/public/mod/booking/bookingextension/agent/benchmark_compare.php)
1. Replace the raw HTML comparison select form with a Moodle `single_select` object.
2. Refactor the Delta and Scenario Diff tables to use `html_table` and `html_writer::table()`.
3. Wrap action links using `html_writer::link()` or `html_writer::span()`.

### Step 3: Refactor [benchmark_run_detail.php](file:///var/www/moodle/public/mod/booking/bookingextension/agent/benchmark_run_detail.php)
1. Refactor raw HTML description list (`<dl>`) and tables to use `html_writer` and `html_table`.
2. Add a **"Pin as Baseline"** button in the header card if the run is not yet a baseline, linking to the pinning action handler in the main report page.

### Step 4: Refactor [benchmark_report.php](file:///var/www/moodle/public/mod/booking/bookingextension/agent/benchmark_report.php)
1. Add a **"Pin Baseline"** button inside the Runs table's Actions column for any run that is not currently marked as a baseline.
2. Replace the compact trend table and main runs table with Moodle's `html_table`.
3. Relocate all remaining hardcoded strings to the language file.
