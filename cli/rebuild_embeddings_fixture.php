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
 * CLI helper to rebuild the task-catalog embeddings fixture CSV for tests.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use bookingextension_agent\local\wbagent\embeddings_action_config_resolver;
use bookingextension_agent\local\wbagent\embeddings_catalog_builder_service;
use bookingextension_agent\local\wbagent\embeddings_csv_repository;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry_factory;
use core\di;
use core_ai\manager as ai_manager;

$help = <<<EOF
Rebuild test fixture CSV for task-catalog embeddings.

Usage:
  php mod/booking/bookingextension/agent/cli/rebuild_embeddings_fixture.php [options]

Options:
  --embed            Generate embeddings for changed/new rows via provider action.
  --force            With --embed: regenerate embeddings for all current tasks.
  --model=MODEL      Embedding model override.
  --dimensions=N     Embedding dimensions override.
    --output=PATH      Output CSV path (default: tests/agent/fixtures/task_catalog_embeddings.csv).
  --help             Show this help.

Default behavior (without --embed):
  - Rebuild catalog metadata + content_hash for all tasks.
  - Reuse existing embedding_json when available.
  - Keep stale embedding_json for changed tasks unless --embed is used.
EOF;

[$options] = cli_get_params(
    [
        'embed' => false,
        'force' => false,
        'model' => '',
        'dimensions' => 0,
        'output' => '',
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($options['help'])) {
    echo $help . PHP_EOL;
    exit(0);
}

$doembed = !empty($options['embed']);
$force = !empty($options['force']);
if ($force && !$doembed) {
    mtrace('Warning: --force has no effect without --embed.');
}

$outputpath = trim((string)$options['output']);
if ($outputpath === '') {
    $outputpath = dirname(__DIR__) . '/tests/agent/fixtures/task_catalog_embeddings.csv';
}

$resolver = new embeddings_action_config_resolver();
$resolved = $resolver->resolve();

$model = trim((string)$options['model']);
if ($model === '') {
    $model = trim((string)($resolved['model'] ?? orchestrator::EMBEDDINGS_DEFAULT_MODEL));
}
if ($model === '') {
    $model = orchestrator::EMBEDDINGS_DEFAULT_MODEL;
}

$dimensions = (int)$options['dimensions'];
if ($dimensions < 1) {
    $dimensions = (int)($resolved['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS);
}
if ($dimensions < 1) {
    $dimensions = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;
}

$registry = task_registry_factory::get_default();
$builder = new embeddings_catalog_builder_service();
$rows = $builder->build_full_catalog_rows($registry, $model, $dimensions);
if (empty($rows)) {
    cli_error('No task rows generated from registry.');
}

$existingrows = read_fixture_rows($outputpath);
$existingbytask = [];
foreach ($existingrows as $row) {
    $taskname = trim((string)($row['task'] ?? ''));
    if ($taskname !== '') {
        $existingbytask[$taskname] = $row;
    }
}

$statecounts = [
    'created' => 0,
    'updated' => 0,
    'untouched' => 0,
    'deleted' => 0,
];

$embeddedcount = 0;
$reusedcount = 0;
$stalereusedcount = 0;
$emptycount = 0;

$manager = null;
$userid = 2;
$contextid = 0;

if ($doembed) {
    if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
        cli_error('Embedding action class not available. Install/enable aiprovider_wunderbyte first.');
    }

    $manager = di::get(ai_manager::class);
    $contextid = (int)\context_system::instance()->id;
    $admin = get_admin();
    if (!empty($admin->id)) {
        $userid = (int)$admin->id;
    }
}

$currenttasks = [];
foreach ($rows as $idx => $row) {
    $taskname = trim((string)($row['task'] ?? ''));
    if ($taskname === '') {
        continue;
    }

    $currenttasks[] = $taskname;
    $contenthash = trim((string)($row['content_hash'] ?? ''));
    $existing = $existingbytask[$taskname] ?? null;
    $existinghash = trim((string)($existing['content_hash'] ?? ''));
    $existingembedding = trim((string)($existing['embedding_json'] ?? ''));

    $isnew = !is_array($existing);
    $unchanged = (!$isnew && $existinghash !== '' && $existinghash === $contenthash);

    if ($isnew) {
        $statecounts['created']++;
    } else if ($unchanged) {
        $statecounts['untouched']++;
    } else {
        $statecounts['updated']++;
    }

    // Fast path: unchanged row with existing embedding can always be reused.
    if (!$force && $unchanged && $existingembedding !== '') {
        $rows[$idx]['embedding_json'] = $existingembedding;
        unset($rows[$idx]['_embedding_input']);
        $reusedcount++;
        continue;
    }

    // Without embedding generation we still refresh catalog rows and keep old embedding when possible.
    if (!$doembed) {
        if ($existingembedding !== '') {
            $rows[$idx]['embedding_json'] = $existingembedding;
            $stalereusedcount++;
        } else {
            $rows[$idx]['embedding_json'] = '[]';
            $emptycount++;
        }
        unset($rows[$idx]['_embedding_input']);
        continue;
    }

    $inputtext = (string)($row['_embedding_input'] ?? '');
    if ($inputtext === '') {
        $rows[$idx]['embedding_json'] = ($existingembedding !== '') ? $existingembedding : '[]';
        if ($existingembedding !== '') {
            $reusedcount++;
        } else {
            $emptycount++;
        }
        unset($rows[$idx]['_embedding_input']);
        continue;
    }

    $actionclass = '\\aiprovider_wunderbyte\\aiactions\\generate_embeddings';
    $action = new $actionclass(
        contextid: $contextid,
        userid: $userid,
        inputtext: $inputtext,
        dimensions: $dimensions
    );

    $response = $manager->process_action($action);
    $responsedata = $response->get_response_data();
    $embedding = (array)($responsedata['embedding'] ?? []);

    if ($response->get_success() && !empty($embedding)) {
        $rows[$idx]['embedding_json'] = json_encode($embedding, JSON_UNESCAPED_UNICODE);
        $embeddedcount++;
    } else if ($existingembedding !== '') {
        // Keep previously known embedding if provider call fails.
        $rows[$idx]['embedding_json'] = $existingembedding;
        $reusedcount++;
    } else {
        $rows[$idx]['embedding_json'] = '[]';
        $emptycount++;
    }

    unset($rows[$idx]['_embedding_input']);
}

$currenttasks = array_values(array_unique($currenttasks));
sort($currenttasks);

$deletedtasks = array_values(array_diff(array_keys($existingbytask), $currenttasks));
if (!empty($deletedtasks)) {
    $statecounts['deleted'] = count($deletedtasks);
}

foreach ($rows as $idx => $row) {
    unset($rows[$idx]['_embedding_input']);
}

write_fixture_rows($outputpath, $rows);

mtrace('bookingextension_agent fixture rebuild done: ' . $outputpath);
mtrace('model=' . $model . ', dimensions=' . $dimensions . ', embed=' . ($doembed ? 'yes' : 'no')
    . ', force=' . ($force ? 'yes' : 'no'));
mtrace('states: created=' . $statecounts['created']
    . ', updated=' . $statecounts['updated']
    . ', deleted=' . $statecounts['deleted']
    . ', untouched=' . $statecounts['untouched']);
mtrace('embeddings: generated=' . $embeddedcount
    . ', reused=' . $reusedcount
    . ', stale_reused=' . $stalereusedcount
    . ', empty=' . $emptycount);

if (!empty($deletedtasks)) {
    mtrace('deleted tasks:');
    foreach ($deletedtasks as $taskname) {
        mtrace(' - ' . $taskname);
    }
}

/**
 * Read fixture rows from CSV path.
 *
 * @param string $path
 * @return array<int,array<string,string>>
 */
function read_fixture_rows(string $path): array {
    if (!is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers) || $headers !== embeddings_csv_repository::HEADERS) {
        fclose($handle);
        return [];
    }

    $rows = [];
    while (($cols = fgetcsv($handle)) !== false) {
        if (!is_array($cols) || count($cols) !== count(embeddings_csv_repository::HEADERS)) {
            continue;
        }
        $rows[] = array_combine(embeddings_csv_repository::HEADERS, $cols);
    }

    fclose($handle);
    return $rows;
}

/**
 * Write fixture rows to CSV path.
 *
 * @param string $path
 * @param array<int,array<string,string>> $rows
 * @return void
 */
function write_fixture_rows(string $path, array $rows): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            cli_error('Cannot create output directory: ' . $dir);
        }
    }

    $tmppath = $path . '.tmp';
    $handle = fopen($tmppath, 'wb');
    if ($handle === false) {
        cli_error('Cannot write temporary fixture file: ' . $tmppath);
    }

    fputcsv($handle, embeddings_csv_repository::HEADERS);
    foreach ($rows as $row) {
        $line = [];
        foreach (embeddings_csv_repository::HEADERS as $header) {
            $line[] = (string)($row[$header] ?? '');
        }
        fputcsv($handle, $line);
    }

    fclose($handle);

    if (!rename($tmppath, $path)) {
        @unlink($tmppath);
        cli_error('Cannot move temporary fixture to final path: ' . $path);
    }
}
