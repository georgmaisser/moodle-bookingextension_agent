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
 * Index service for family-level task catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\embeddings;

use bookingextension_agent\local\wbagent\embeddings_action_config_resolver;
use bookingextension_agent\local\wbagent\embeddings_csv_repository;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;
use context_system;

/**
 * Rebuilds and persists the task-catalog embeddings index.
 */
class family_embeddings_index_service {
    /**
     * Rebuild the embeddings CSV from the current registry.
     *
     * @param task_registry $registry
     * @param string|null $model
     * @param int|null $dimensions
     * @param bool $forcefullregen
     * @return array<string,mixed>
     */
    public function rebuild_catalog(
        task_registry $registry,
        ?string $model = null,
        ?int $dimensions = null,
        bool $forcefullregen = false
    ): array {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            return [
                'status' => 'skipped',
                'reason' => 'embeddings_provider_unavailable',
                'written' => 0,
                'embedded' => 0,
                'reused' => 0,
                'deleted' => 0,
            ];
        }

        $resolvedsettings = (new embeddings_action_config_resolver())->resolve();
        $resolvedmodel = trim((string)($model ?? ($resolvedsettings['model'] ?? orchestrator::EMBEDDINGS_DEFAULT_MODEL)));
        if ($resolvedmodel === '') {
            $resolvedmodel = orchestrator::EMBEDDINGS_DEFAULT_MODEL;
        }

        $resolveddimensions = (int)($dimensions
            ?? ($resolvedsettings['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS));
        if ($resolveddimensions < 1) {
            $resolveddimensions = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;
        }

        $builder = new embeddings_catalog_builder_service();
        $repo = new embeddings_csv_repository();
        $rows = $builder->build_full_catalog_rows($registry, $resolvedmodel, $resolveddimensions);
        if (empty($rows)) {
            return [
                'status' => 'empty',
                'written' => 0,
                'embedded' => 0,
                'reused' => 0,
                'deleted' => 0,
            ];
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
        foreach ($rows as $idx => $row) {
            $taskname = trim((string)($row['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $currenttasknames[] = $taskname;
            if (!isset($existingbytask[$taskname])) {
                $taskstates[$taskname] = 'created';
            } else if ($forcefullregen) {
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
        $llm = new llm_call_service(new conversation_store());

        foreach ($rows as $idx => $row) {
            $taskname = trim((string)($row['task'] ?? ''));
            $contenthash = trim((string)($row['content_hash'] ?? ''));
            $existingrow = ($taskname !== '' && isset($existingbytask[$taskname])) ? $existingbytask[$taskname] : null;

            if (
                !$forcefullregen
                && is_array($existingrow)
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

            $embeddingcall = $llm->invoke_embeddings_for_context(
                0,
                (int)$context->id,
                $userid,
                'idx|p=disc|st=emb|ac=emb|rt=wb',
                $inputtext,
                $resolveddimensions
            );

            if (empty($embeddingcall['success'])) {
                continue;
            }

            $embedding = (array)($embeddingcall['embedding'] ?? []);
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

        return [
            'status' => 'written',
            'model' => $resolvedmodel,
            'dimensions' => $resolveddimensions,
            'written' => count($rows),
            'embedded' => count($embeddedtasks),
            'reused' => count($reusedtasks),
            'deleted' => count($removedtasks),
            'taskstates' => $taskstates,
        ];
    }
}
