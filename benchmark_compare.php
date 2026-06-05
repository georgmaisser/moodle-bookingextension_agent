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
 * Compare two benchmark runs side-by-side.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

use bookingextension_agent\local\wbagent\benchmark\benchmark_metrics_calculator;
use bookingextension_agent\local\wbagent\benchmark\benchmark_db_writer;

$runaid  = required_param('run_a', PARAM_INT);
$runbid  = optional_param('run_b', 0, PARAM_INT);

// If run_b not given, compare against latest baseline.
$runa = $DB->get_record('local_wbagent_benchmark_runs', ['id' => $runaid], '*', MUST_EXIST);

$writer = new benchmark_db_writer();
if ($runbid <= 0) {
    $runb = $writer->get_latest_baseline();
    if (!$runb) {
        // Fall back to second most recent run.
        $rows = $DB->get_records_sql(
            'SELECT * FROM {local_wbagent_benchmark_runs} WHERE id != :id ORDER BY timecreated DESC LIMIT 1',
            ['id' => $runaid]
        );
        $runb = $rows ? reset($rows) : null;
    }
} else {
    $runb = $DB->get_record('local_wbagent_benchmark_runs', ['id' => $runbid], '*', MUST_EXIST);
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/benchmark_compare.php', ['run_a' => $runaid, 'run_b' => $runbid]));
$PAGE->set_title('Compare Benchmark Runs');
$PAGE->set_heading('Compare: Run #' . $runaid . ' vs ' . ($runb ? 'Run #' . $runb->id : 'no baseline'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$calc = new benchmark_metrics_calculator();

// Run selector form.
$allruns = $DB->get_records_sql('SELECT id, label, timecreated FROM {local_wbagent_benchmark_runs} ORDER BY timecreated DESC LIMIT 50');
echo '<form method="get" class="form-inline mb-3">';
echo '<input type="hidden" name="run_a" value="' . $runaid . '">';
echo '<label class="mr-2">Compare with:</label>';
echo '<select name="run_b" class="form-control mr-2">';
foreach ($allruns as $r) {
    $sel = ($runb && $r->id == $runb->id) ? ' selected' : '';
    echo '<option value="' . $r->id . '"' . $sel . '>#' . $r->id . ' ' . htmlspecialchars($r->label) . ' (' . date('d.m', $r->timecreated) . ')</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btn-primary">Compare</button></form>';

if (!$runb) {
    echo $OUTPUT->notification('No comparison run available.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Load metrics.
$metaa = $DB->get_records('local_wbagent_benchmark_metrics', ['run_id' => $runa->id]);
$metab = $DB->get_records('local_wbagent_benchmark_metrics', ['run_id' => $runb->id]);
$mapa  = array_column((array)$metaa, 'metric_value', 'metric_key');
$mapb  = array_column((array)$metab, 'metric_value', 'metric_key');

$comparison = $calc->compare(
    array_map('floatval', $mapa),
    array_map('floatval', $mapb)
);

// Header summary.
echo '<div class="row mb-3">';
foreach ([['A', $runa], ['B', $runb]] as [$tag, $r]) {
    echo '<div class="col-md-6"><div class="card">';
    echo '<div class="card-header"><strong>Run ' . $tag . '</strong>: #' . $r->id . ' ' . htmlspecialchars($r->label) . '</div>';
    echo '<div class="card-body p-2"><small>';
    echo 'Model: ' . htmlspecialchars($r->model_id) . '<br>';
    echo 'Set: ' . htmlspecialchars($r->task_set) . '<br>';
    echo 'Success: ' . $r->success_rate . '% (' . $r->passed . '/' . $r->total_scenarios . ')<br>';
    echo 'Date: ' . userdate($r->timecreated, '%d.%m.%Y %H:%M');
    echo '</small></div></div></div>';
}
echo '</div>';

// Delta table.
echo '<h3>Metric Delta (A vs B)</h3>';
echo '<table class="table table-bordered table-sm">';
echo '<thead><tr><th>Metric</th><th>Run A</th><th>Run B</th><th>Delta</th><th>Threshold</th><th>Status</th></tr></thead><tbody>';

$allkeys = array_unique(array_merge(array_keys($mapa), array_keys($mapb)));
sort($allkeys);
foreach ($allkeys as $key) {
    $va   = isset($mapa[$key]) ? round((float)$mapa[$key], 2) : '—';
    $vb   = isset($mapb[$key]) ? round((float)$mapb[$key], 2) : '—';
    $comp = $comparison[$key] ?? null;
    $delta = $comp ? ($comp['delta'] >= 0 ? '+' : '') . $comp['delta'] : '—';
    $thresh = $comp ? $comp['threshold'] : '—';
    $status = '';
    $rowclass = '';
    if ($comp) {
        if ($comp['status'] === 'green') {
            $status = '✅';
        } else if ($comp['status'] === 'yellow') {
            $status = '⚠️';
            $rowclass = 'table-warning';
        } else {
            $status = '❌';
            $rowclass = 'table-danger';
        }
    }
    $unit = strpos($key, 'ms') !== false ? 'ms' : (strpos($key, 'token') !== false || strpos($key, 'count') !== false ? '' : '%');
    echo "<tr class='{$rowclass}'>"
        . "<td>{$key}</td>"
        . "<td>{$va}{$unit}</td>"
        . "<td>{$vb}{$unit}</td>"
        . "<td><strong>{$delta}</strong></td>"
        . "<td>{$thresh}</td>"
        . "<td>{$status}</td>"
        . "</tr>";
}
echo '</tbody></table>';

// Scenario diff.
$scenaa = $DB->get_records('local_wbagent_benchmark_scenarios', ['run_id' => $runa->id]);
$scenab = $DB->get_records('local_wbagent_benchmark_scenarios', ['run_id' => $runb->id]);
$bykeya = array_combine(array_column((array)$scenaa, 'scenario_key'), (array)$scenaa);
$bykeyb = array_combine(array_column((array)$scenab, 'scenario_key'), (array)$scenab);
$allscenkeys = array_unique(array_merge(array_keys($bykeya), array_keys($bykeyb)));
sort($allscenkeys);

$diffs = array_filter($allscenkeys, function ($k) use ($bykeya, $bykeyb) {
    $pa = isset($bykeya[$k]) ? (int)$bykeya[$k]->passed : -1;
    $pb = isset($bykeyb[$k]) ? (int)$bykeyb[$k]->passed : -1;
    return $pa !== $pb;
});

if (!empty($diffs)) {
    echo '<h3>Scenario Differences</h3>';
    echo '<table class="table table-sm table-bordered">';
    echo '<thead><tr><th>Scenario</th><th>Run A</th><th>Run B</th></tr></thead><tbody>';
    foreach ($diffs as $k) {
        $pa = isset($bykeya[$k]) ? ($bykeya[$k]->passed ? '✅ pass' : '❌ fail') : '—';
        $pb = isset($bykeyb[$k]) ? ($bykeyb[$k]->passed ? '✅ pass' : '❌ fail') : '—';
        $rowclass = (strpos($pa, 'fail') !== false || strpos($pb, 'fail') !== false) ? 'table-warning' : '';
        echo "<tr class='{$rowclass}'><td><small>{$k}</small></td><td>{$pa}</td><td>{$pb}</td></tr>";
    }
    echo '</tbody></table>';
} else {
    echo '<p class="text-muted">No scenario differences between these two runs.</p>';
}

echo $OUTPUT->footer();
