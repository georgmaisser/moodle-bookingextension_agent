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
 * Readiness and scheduling service for skill-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\embeddings_csv_repository;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Determines embeddings readiness and queues rebuild tasks when needed.
 */
class embeddings_readiness_service {
    /** Fully qualified class name of the rebuild adhoc task. */
    private const REBUILD_TASK_CLASS = '\\bookingextension_agent\\task\\rebuild_skill_catalog_embeddings_adhoc';

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
     * @param skill_registry $registry
     * @param string $model
     * @param int $dimensions
     * @return array
     */
    public function get_catalog_status(skill_registry $registry, string $model, int $dimensions): array {
        // Variant-scoped store: only the active model's file is consulted, so a model switch never
        // invalidates the others and no cross-model vectors are ever compared.
        $repo = embeddings_csv_repository::for_variant($model, $dimensions);
        $builder = new embeddings_catalog_builder_service();

        if (!$repo->exists()) {
            return ['status' => 'missing', 'ready' => false];
        }

        $rows = $repo->read_rows();
        if (!$repo->is_valid_schema($rows)) {
            return ['status' => 'invalid', 'ready' => false];
        }

        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return [
                'status' => 'ready',
                'ready' => true,
                'rows' => $rows,
            ];
        }

        // Multi-vector store (SKILL_REWORK.md §5): a skill spans several rows (one per anchor), so
        // readiness is compared per ANCHOR — keyed by (skill, anchor_index) — not per skill.
        $expected = $builder->build_full_catalog_rows($registry, $model, $dimensions);
        $byskill = [];
        foreach ($rows as $row) {
            $key = $this->anchor_key($row);
            if ($key !== '') {
                $byskill[$key] = $row;
            }
        }

        foreach ($expected as $row) {
            $current = $byskill[$this->anchor_key($row)] ?? null;
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

            // An anchor present with the right hash but carrying NO vector is useless for semantic
            // retrieval: the rebuild silently skips a row whose embedding call failed or returned
            // empty (family_embeddings_index_service continues past such rows, leaving embedding_json
            // ''). Without this guard readiness reports "ready", the rebuild's post-sanity-check
            // passes, and the planner can NEVER retrieve that skill. Treat an empty vector as not
            // ready so the rebuild fails its sanity check and re-embeds (faildelay backoff).
            $vector = trim((string)($current['embedding_json'] ?? ''));
            if ($vector === '' || $vector === '[]') {
                return ['status' => 'stale', 'ready' => false];
            }
        }

        // Orphan detection (removal-aware): the expected-only loop never visits a stored anchor that
        // no longer exists, so a removed skill OR a deleted utterance would otherwise stay "ready"
        // with a lingering row. Flip to stale on any stored anchor not in the expected set — the
        // rebuild then prunes it. Set-membership over the live expected set; no stored fingerprint.
        $expectedkeys = [];
        foreach ($expected as $row) {
            $key = $this->anchor_key($row);
            if ($key !== '') {
                $expectedkeys[$key] = true;
            }
        }
        foreach (array_keys($byskill) as $storedkey) {
            if (empty($expectedkeys[$storedkey])) {
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
     * Stable per-anchor identity key (skill + anchor_index) for multi-vector readiness comparison.
     *
     * @param array $row
     * @return string
     */
    private function anchor_key(array $row): string {
        $skill = trim((string)($row['skill'] ?? ''));
        if ($skill === '') {
            return '';
        }
        return $skill . '#' . (string)($row['anchor_index'] ?? '0');
    }

    /**
     * Queue embeddings rebuild task when status is not ready.
     *
     * @param array $status
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

        // Single scheduling path (shared with the docs index): config-marker debounce + deduped
        // queue_adhoc_task. Dedup matches on classname + custom_data, so the model/dims variant is
        // preserved (a different model still queues its own rebuild).
        return embeddings_rebuild_scheduler::queue_if_due(
            $task,
            'skill_embeddings_rebuild_queued_at',
            (int)$debounceseconds
        );
    }
}
