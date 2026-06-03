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
 * Readiness and scheduling service for task-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\embeddings;

use bookingextension_agent\local\wbagent\embeddings_csv_repository;
use bookingextension_agent\local\wbagent\task_registry;
use core\task\manager as task_manager;

/**
 * Determines embeddings readiness and queues rebuild tasks when needed.
 */
class embeddings_readiness_service {
    /** Fully qualified class name of the rebuild adhoc task. */
    private const REBUILD_TASK_CLASS = '\\bookingextension_agent\\task\\rebuild_task_catalog_embeddings_adhoc';

    /**
     * Check if wunderbyte embeddings action can be used.
     *
     * @return bool
     */
    public function is_wunderbyte_embeddings_available(): bool {
        return class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings');
    }

    /**
     * Compute current catalog status.
     *
     * @param task_registry $registry
     * @param string $model
     * @param int $dimensions
     * @return array<string,mixed>
     */
    public function get_catalog_status(task_registry $registry, string $model, int $dimensions): array {
        $repo = new embeddings_csv_repository();
        $builder = new embeddings_catalog_builder_service();

        if (!$repo->exists()) {
            return ['status' => 'missing', 'ready' => false];
        }

        $rows = $repo->read_rows();
        if (!$repo->is_valid_schema($rows)) {
            return ['status' => 'invalid', 'ready' => false];
        }

        $expected = $builder->build_full_catalog_rows($registry, $model, $dimensions);
        $bytask = [];
        foreach ($rows as $row) {
            $task = (string)($row['task'] ?? '');
            if ($task !== '') {
                $bytask[$task] = $row;
            }
        }

        foreach ($expected as $row) {
            $task = (string)($row['task'] ?? '');
            $current = $bytask[$task] ?? null;
            if ($current === null) {
                return ['status' => 'stale', 'ready' => false];
            }

            if ((string)($current['embedding_model'] ?? '') !== $model) {
                return ['status' => 'stale', 'ready' => false];
            }

            if ((string)($current['embedding_dimensions'] ?? '') !== (string)$dimensions) {
                return ['status' => 'stale', 'ready' => false];
            }

            if ((string)($current['content_hash'] ?? '') !== (string)($row['content_hash'] ?? '')) {
                return ['status' => 'stale', 'ready' => false];
            }
        }

        return [
            'status' => 'ready',
            'ready' => true,
            'rows' => $rows,
        ];
    }

    /**
     * Queue embeddings rebuild task when status is not ready.
     *
     * @param array<string,mixed> $status
     * @param string $model
     * @param int $dimensions
     * @param int $debounceseconds
     * @return bool True when task was queued.
     */
    public function ensure_rebuild_scheduled_if_needed(
        array $status,
        string $model,
        int $dimensions,
        int $debounceseconds
    ): bool {
        // Kept for backward-compatible signature; scheduling no longer uses config debounce markers.
        $debounceseconds = (int)$debounceseconds;

        if (!empty($status['ready'])) {
            return false;
        }

        if (!class_exists(self::REBUILD_TASK_CLASS)) {
            return false;
        }

        $taskclass = self::REBUILD_TASK_CLASS;
        $task = new $taskclass();
        $task->set_custom_data([
            'model' => $model,
            'dimensions' => $dimensions,
        ]);
        task_manager::reschedule_or_queue_adhoc_task($task);
        return true;
    }
}
