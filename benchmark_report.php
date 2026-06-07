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
 * Benchmark report overview page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

use bookingextension_agent\local\wbagent\benchmark\benchmark_db_writer;
use bookingextension_agent\local\wbagent\benchmark\benchmark_metrics_calculator;

$page    = optional_param('page', 0, PARAM_INT);
$perpage = 30;
$action  = optional_param('action', '', PARAM_ALPHA);
$runid   = optional_param('runid', 0, PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/benchmark_report.php'));
$PAGE->set_title(get_string('benchmark_report_title', 'bookingextension_agent', 'Benchmark Report'));
$PAGE->set_heading(get_string('benchmark_report_title', 'bookingextension_agent', 'Benchmark Report'));
$PAGE->set_pagelayout('admin');

// Handle actions.
if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey()) {
    $label = optional_param('baselinelabel', date('Y-m-d'), PARAM_TEXT);
    $writer = new benchmark_db_writer();
    $writer->pin_baseline($runid, $label, '', $USER->id);
    redirect($PAGE->url, 'Baseline pinned.', 2);
}

echo $OUTPUT->header();

$DB->get_manager(); // Ensure DB is loaded.

// Check tables exist.
if (!$DB->get_manager()->table_exists('local_wbagent_benchmark_runs')) {
    echo $OUTPUT->notification('Benchmark tables not installed. Run Moodle upgrade first.', 'error');
    echo $OUTPUT->footer();
    exit;
}

$total = $DB->count_records('local_wbagent_benchmark_runs');
$runs  = $DB->get_records_sql(
    'SELECT r.*, b.label AS baseline_label
       FROM {local_wbagent_benchmark_runs} r
       LEFT JOIN {local_wbagent_benchmark_baselines} b ON b.run_id = r.id
      ORDER BY r.timecreated DESC',
    [],
    $page * $perpage,
    $perpage
);

$calc = new benchmark_metrics_calculator();
$thresholds = $calc->get_thresholds();

// Trend chart — all runs.
// Select m.id first so get_records_sql keys by m.id (unique per row).
// Multiple metrics per run share the same r.id, which would cause overwrites otherwise.
$trendruns = $DB->get_records_sql(
    'SELECT m.id, r.id AS run_id, r.timecreated, m.metric_key, m.metric_value
       FROM {local_wbagent_benchmark_runs} r
       JOIN {local_wbagent_benchmark_metrics} m ON m.run_id = r.id
      WHERE m.metric_key IN (\'e2e_success_rate\', \'task_hit_rate\', \'json_validity_rate\')
        AND m.scenario_class IS NULL
      ORDER BY r.timecreated ASC'
);

// Group by run_id; each run contributes up to 3 metric rows.
$runmetrics = [];
$runorder   = [];
foreach ($trendruns as $t) {
    $rid = (int)$t->run_id;
    if (!isset($runmetrics[$rid])) {
        $runmetrics[$rid] = ['timecreated' => (int)$t->timecreated];
        $runorder[] = $rid;
    }
    $runmetrics[$rid][$t->metric_key] = (float)$t->metric_value;
}

$chartdata = ['labels' => [], 'success' => [], 'taskhit' => [], 'jsonok' => []];
foreach ($runorder as $rid) {
    $m = $runmetrics[$rid];
    $chartdata['labels'][]  = date('d.m H:i', $m['timecreated']);
    $chartdata['success'][] = $m['e2e_success_rate'] ?? null;
    $chartdata['taskhit'][] = $m['task_hit_rate']    ?? null;
    $chartdata['jsonok'][]  = $m['json_validity_rate'] ?? null;
}

echo '<h2>Benchmark Runs</h2>';

// Trend chart (Moodle Chart API) + fallback trend table.
if (!empty($chartdata['labels'])) {
    $nruns = count($chartdata['labels']);
    echo '<h3>Trend (' . $nruns . ' runs)</h3>';

    // Moodle line chart.
    if (class_exists('\core\chart_line')) {
        $chart = new \core\chart_line();
        $chart->set_smooth(true);

        $sset = new \core\chart_series('e2e Success %', array_pad($chartdata['success'], $nruns, null));
        $sset->set_color('#2d6a4f');
        $chart->add_series($sset);

        $tset = new \core\chart_series('Task Hit %', array_pad($chartdata['taskhit'], $nruns, null));
        $tset->set_color('#457b9d');
        $chart->add_series($tset);

        $jset = new \core\chart_series('JSON Valid %', array_pad($chartdata['jsonok'], $nruns, null));
        $jset->set_color('#e9c46a');
        $chart->add_series($jset);

        $chart->set_labels($chartdata['labels']);

        $xaxis = new \core\chart_axis();
        $chart->set_xaxis($xaxis);
        $yaxis = new \core\chart_axis();
        $yaxis->set_min(0);
        $yaxis->set_max(100);
        $chart->set_yaxis($yaxis);
        $charthtml = $OUTPUT->render($chart);
        echo $OUTPUT->render_from_template('bookingextension_agent/benchmark_trend_chart', [
            'containerid' => 'benchmark-chart-container',
            'minwidth' => max(800, $nruns * 35),
            'charthtml' => $charthtml,
        ]);
    }

    // Compact trend table (C3c — no-JS fallback + quick scan).
    echo '<h4>Trend Table</h4>';
    echo '<table class="table table-sm table-bordered" style="font-size:0.85em">';
    echo '<tr><th>Run</th><th>e2e Success</th><th>Task Hit</th><th>JSON Valid</th></tr>';
    foreach ($chartdata['labels'] as $idx => $lbl) {
        $suc = $chartdata['success'][$idx];
        $tsk = $chartdata['taskhit'][$idx];
        $jsn = $chartdata['jsonok'][$idx];
        $sucfmt = $suc !== null ? $suc . '%' : '—';
        $tskfmt = $tsk !== null ? $tsk . '%' : '—';
        $jsnfmt = $jsn !== null ? $jsn . '%' : '—';
        $succlass = $suc === null ? '' : ($suc < 85 ? 'table-danger' : ($suc < 95 ? 'table-warning' : 'table-success'));
        echo "<tr><td>{$lbl}</td>"
            . "<td class='{$succlass}'>{$sucfmt}</td>"
            . "<td>{$tskfmt}</td>"
            . "<td>{$jsnfmt}</td></tr>";
    }
    echo '</table>';
}

// Runs table.
echo '<table class="table table-hover generaltable">';
echo '<thead><tr>'
    . '<th>ID</th><th>Label</th><th>Model</th><th>Set</th>'
    . '<th>Success</th><th>Passed</th><th>Duration</th><th>Tokens</th>'
    . '<th>Env</th><th>Git</th><th>Date</th><th>Actions</th>'
    . '</tr></thead><tbody>';

foreach ($runs as $run) {
    $rate    = (float)$run->success_rate;
    $color   = $rate >= 95 ? 'success' : ($rate >= 85 ? 'warning' : 'danger');
    $baseline = $run->is_baseline ? ' <span class="badge badge-primary">baseline</span>' : '';
    $regression = $run->regression_detected ? ' <span class="badge badge-danger">regression</span>' : '';

    $detailurl  = new moodle_url('/mod/booking/bookingextension/agent/benchmark_run_detail.php', ['id' => $run->id]);
    $compareurl = new moodle_url('/mod/booking/bookingextension/agent/benchmark_compare.php', ['run_a' => $run->id]);

    echo "<tr>"
        . "<td>{$run->id}</td>"
        . "<td>" . htmlspecialchars($run->label) . $baseline . $regression . "</td>"
        . "<td>" . htmlspecialchars($run->model_id) . "</td>"
        . "<td>" . htmlspecialchars($run->task_set) . "</td>"
        . "<td><span class='badge badge-{$color}'>{$rate}%</span></td>"
        . "<td>{$run->passed}/{$run->total_scenarios}</td>"
        . "<td>" . number_format($run->duration_ms / 1000, 1) . "s</td>"
        . "<td>" . number_format((int)$run->total_tokens) . "</td>"
        . "<td>" . htmlspecialchars($run->environment) . "</td>"
        . "<td><small>" . htmlspecialchars(substr($run->git_ref, 0, 8)) . "</small></td>"
        . "<td><small>" . userdate($run->timecreated, '%d.%m %H:%M') . "</small></td>"
        . "<td>"
        . "<a href='{$detailurl}' class='btn btn-xs btn-secondary'>Detail</a> "
        . "<a href='{$compareurl}' class='btn btn-xs btn-info'>Compare</a>"
        . "</td>"
        . "</tr>";
}
echo '</tbody></table>';

echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
echo $OUTPUT->footer();
