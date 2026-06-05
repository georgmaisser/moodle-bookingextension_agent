<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Benchmark run detail page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

use bookingextension_agent\local\wbagent\benchmark\benchmark_metrics_calculator;

$id = required_param('id', PARAM_INT);

$run = $DB->get_record('local_wbagent_benchmark_runs', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/benchmark_run_detail.php', ['id' => $id]));
$PAGE->set_title('Benchmark Run #' . $id);
$PAGE->set_heading('Benchmark Run #' . $id . ': ' . $run->label);
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$scenarios = $DB->get_records('local_wbagent_benchmark_scenarios', ['run_id' => $id], 'scenario_key ASC');
$metrics   = $DB->get_records('local_wbagent_benchmark_metrics', ['run_id' => $id], 'metric_key ASC');

$calc       = new benchmark_metrics_calculator();
$thresholds = $calc->get_thresholds();
$metricsmap = [];
foreach ($metrics as $m) {
    if ($m->scenario_class === null) {
        $metricsmap[$m->metric_key] = (float)$m->metric_value;
    }
}

$backurl = new moodle_url('/mod/booking/bookingextension/agent/benchmark_report.php');

echo '<p><a href="' . $backurl . '" class="btn btn-secondary">← All Runs</a></p>';

// Run header.
echo '<div class="card mb-3"><div class="card-body">';
echo '<dl class="row mb-0">';
$fields = [
    'Run UUID'   => $run->run_uuid,
    'Model'      => $run->model_id,
    'Task Set'   => $run->task_set,
    'Env'        => $run->environment,
    'Git Ref'    => $run->git_ref,
    'Date'       => userdate($run->timecreated, '%d.%m.%Y %H:%M'),
    'Duration'   => number_format($run->duration_ms / 1000, 2) . 's',
    'Tokens'     => number_format((int)$run->total_tokens),
];
foreach ($fields as $k => $v) {
    echo "<dt class='col-sm-3'>{$k}</dt><dd class='col-sm-9'>" . htmlspecialchars((string)$v) . "</dd>";
}
echo '</dl></div></div>';

// Metric summary.
echo '<h3>Metrics</h3>';
echo '<table class="table table-sm table-bordered">';
echo '<thead><tr><th>Metric</th><th>Value</th><th>Threshold</th><th>Status</th></tr></thead><tbody>';
foreach ($metricsmap as $key => $val) {
    $threshold = $thresholds[$key] ?? null;
    $status = $threshold === null ? '' : ($val >= $threshold ? '✅' : ($val >= $threshold * 0.95 ? '⚠️' : '❌'));
    $unit = strpos($key, 'ms') !== false ? 'ms' : (strpos($key, 'count') !== false ? '' : '%');
    echo "<tr><td>{$key}</td><td>{$val}{$unit}</td><td>" . ($threshold ?? '—') . "</td><td>{$status}</td></tr>";
}
echo '</tbody></table>';

// Scenario results.
echo '<h3>Scenario Results (' . count($scenarios) . ')</h3>';

$filter = optional_param('filter', '', PARAM_ALPHA);
echo '<p>';
echo '<a href="' . $PAGE->url . '" class="btn btn-xs btn-outline-secondary">All</a> ';
echo '<a href="' . new moodle_url($PAGE->url, ['filter' => 'failed']) . '" class="btn btn-xs btn-outline-danger">Failed only</a>';
echo '</p>';

echo '<table class="table table-sm generaltable">';
echo '<thead><tr><th>Key</th><th>Class</th><th>Pass</th><th>RT exp.</th><th>RT act.</th>'
    . '<th>Task exp.</th><th>Task sel.</th><th>JSON</th><th>Contract</th><th>Planned</th>'
    . '<th>ms</th><th>Error</th></tr></thead><tbody>';

foreach ($scenarios as $s) {
    if ($filter === 'failed' && $s->passed) {
        continue;
    }
    $rowclass = $s->passed ? '' : 'table-danger';
    $pass     = $s->passed ? '✅' : '❌';
    $json     = $s->json_valid ? '✅' : '❌';
    $contract = $s->contract_compliant ? '✅' : '❌';
    $planned  = $s->planned_steps_present ? '✅' : '—';
    echo "<tr class='{$rowclass}'>"
        . "<td><small>" . htmlspecialchars($s->scenario_key) . "</small></td>"
        . "<td><small>{$s->scenario_class}</small></td>"
        . "<td>{$pass}</td>"
        . "<td><small>" . htmlspecialchars($s->response_type_expected) . "</small></td>"
        . "<td><small>" . htmlspecialchars($s->response_type_actual) . "</small></td>"
        . "<td><small>" . htmlspecialchars($s->task_expected) . "</small></td>"
        . "<td><small>" . htmlspecialchars($s->task_selected) . "</small></td>"
        . "<td>{$json}</td>"
        . "<td>{$contract}</td>"
        . "<td>{$planned}</td>"
        . "<td>{$s->duration_ms}</td>"
        . "<td><small style='color:red'>" . htmlspecialchars((string)$s->error_message) . "</small></td>"
        . "</tr>";
}
echo '</tbody></table>';

echo $OUTPUT->footer();
