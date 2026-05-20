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
 * Adhoc task to rebuild task-catalog embeddings CSV.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use context_system;
use core\di;
use core_ai\manager as ai_manager;
use bookingextension_agent\local\wbagent\embeddings_action_config_resolver;
use bookingextension_agent\local\wbagent\embeddings_catalog_builder_service;
use bookingextension_agent\local\wbagent\embeddings_csv_repository;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Rebuilds embeddings for the full task catalog.
 */
class rebuild_task_catalog_embeddings_adhoc extends \core\task\adhoc_task {
    /**
     * Execute task.
     *
     * @return void
     */
    public function execute(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            return;
        }

        $customdata = (array)$this->get_custom_data();
        $resolvedsettings = (new embeddings_action_config_resolver())->resolve();

        $model = trim((string)($customdata['model'] ?? ($resolvedsettings['model'] ?? orchestrator::EMBEDDINGS_DEFAULT_MODEL)));
        if ($model === '') {
            $model = orchestrator::EMBEDDINGS_DEFAULT_MODEL;
        }

        $dimensions = (int)($customdata['dimensions']
            ?? ($resolvedsettings['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS));
        if ($dimensions < 1) {
            $dimensions = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;
        }
        $forcefullregen = !empty($customdata['force']);

        $registry = task_registry_factory::get_default();
        $builder = new embeddings_catalog_builder_service();
        $repo = new embeddings_csv_repository();

        $rows = $builder->build_full_catalog_rows($registry, $model, $dimensions);
        if (empty($rows)) {
            return;
        }

        $existingrows = $repo->read_rows();
        $existingbytask = [];
        if ($repo->is_valid_schema($existingrows)) {
            foreach ($existingrows as $existingrow) {
                $taskname = trim((string)($existingrow['task'] ?? ''));
                if ($taskname !== '') {
                    $existingbytask[$taskname] = $existingrow;
                }
            }
        }

        $currenttasknames = [];
        $taskstates = [];
        foreach ($rows as $row) {
            $taskname = trim((string)($row['task'] ?? ''));
            if ($taskname !== '') {
                $currenttasknames[] = $taskname;
                if (!isset($existingbytask[$taskname])) {
                    $taskstates[$taskname] = 'created';
                } else if ($forcefullregen) {
                    // Forced rebuild regenerates all existing entries.
                    $taskstates[$taskname] = 'updated';
                } else if (
                    trim((string)($existingbytask[$taskname]['content_hash'] ?? ''))
                    === trim((string)($row['content_hash'] ?? ''))
                ) {
                    $taskstates[$taskname] = 'untouched';
                } else {
                    $taskstates[$taskname] = 'updated';
                }
            }
        }
        $currenttasknames = array_values(array_unique($currenttasknames));
        sort($currenttasknames);
        $removedtasks = array_values(array_diff(array_keys($existingbytask), $currenttasknames));
        sort($removedtasks);
        foreach ($removedtasks as $taskname) {
            $taskstates[$taskname] = 'deleted';
        }

        $context = context_system::instance();
        $admin = get_admin();
        $userid = !empty($admin->id) ? (int)$admin->id : 2;
        $embeddedtasks = [];
        $reusedtasks = [];

        $manager = di::get(ai_manager::class);
        foreach ($rows as $idx => $row) {
            $taskname = trim((string)($row['task'] ?? ''));
            $contenthash = trim((string)($row['content_hash'] ?? ''));
            $existingrow = ($taskname !== '' && isset($existingbytask[$taskname])) ? $existingbytask[$taskname] : null;

            // Reuse unchanged embeddings from current CSV to avoid unnecessary API calls.
            if (
                !$forcefullregen
                &&
                is_array($existingrow)
                && trim((string)($existingrow['content_hash'] ?? '')) === $contenthash
                && trim((string)($existingrow['embedding_json'] ?? '')) !== ''
            ) {
                $rows[$idx]['embedding_json'] = (string)$existingrow['embedding_json'];
                if ($taskname !== '') {
                    $reusedtasks[] = $taskname;
                }
                unset($rows[$idx]['_embedding_input']);
                continue;
            }

            $inputtext = (string)($row['_embedding_input'] ?? '');
            if ($inputtext === '') {
                continue;
            }

            $actionclass = '\\aiprovider_wunderbyte\\aiactions\\generate_embeddings';
            $action = new $actionclass(
                contextid: (int)$context->id,
                userid: $userid,
                inputtext: $inputtext,
                dimensions: $dimensions,
            );

            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                continue;
            }

            $responsedata = $response->get_response_data();
            $embedding = (array)($responsedata['embedding'] ?? []);
            if (empty($embedding)) {
                continue;
            }

            $rows[$idx]['embedding_json'] = json_encode($embedding, JSON_UNESCAPED_UNICODE);
            if ($taskname !== '') {
                $embeddedtasks[] = $taskname;
            }
            unset($rows[$idx]['_embedding_input']);
        }

        foreach ($rows as $idx => $row) {
            unset($rows[$idx]['_embedding_input']);
        }

        $repo->write_rows($rows);

        $embeddedtasks = array_values(array_unique($embeddedtasks));
        sort($embeddedtasks);
        $reusedtasks = array_values(array_unique($reusedtasks));
        sort($reusedtasks);

        mtrace('mod_booking embeddings rebuild: generated embeddings for '
            . count($embeddedtasks) . ' tasks.');
        mtrace('mod_booking embeddings rebuild: reused embeddings for '
            . count($reusedtasks) . ' tasks.');
        mtrace('mod_booking embeddings rebuild: removed stale tasks from CSV: '
            . count($removedtasks) . '.');

        $statecounts = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'untouched' => 0,
        ];
        foreach ($taskstates as $state) {
            if (isset($statecounts[$state])) {
                $statecounts[$state]++;
            }
        }
        mtrace('mod_booking embeddings rebuild states summary: '
            . 'created=' . $statecounts['created']
            . ', updated=' . $statecounts['updated']
            . ', deleted=' . $statecounts['deleted']
            . ', untouched=' . $statecounts['untouched']);
        if (!empty($taskstates)) {
            ksort($taskstates);
            mtrace('mod_booking embeddings rebuild task states:');
            foreach ($taskstates as $taskname => $state) {
                mtrace(' - ' . $state . ' ' . $taskname);
            }
        }
    }
}
