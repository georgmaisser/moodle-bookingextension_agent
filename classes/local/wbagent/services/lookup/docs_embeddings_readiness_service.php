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
 * Readiness service for documentation embeddings index.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\lookup;

/**
 * Determines whether the docs embeddings index is ready and triggers async rebuilds.
 */
class docs_embeddings_readiness_service {
    /** Fully qualified class name of the rebuild adhoc task. */
    private const REBUILD_TASK_CLASS = '\\bookingextension_agent\\task\\rebuild_docs_embeddings_adhoc';

    /**
     * Check if the wunderbyte embeddings provider is available.
     *
     * @return bool
     */
    public function is_embeddings_provider_available(): bool {
        return class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings');
    }

    /**
     * Check if the docs embeddings index is present and has valid schema.
     *
     * Does NOT verify content hashes — for a fast runtime readiness check.
     *
     * @return bool
     */
    public function is_index_ready(): bool {
        if (!$this->is_embeddings_provider_available()) {
            return false;
        }

        $repo = docs_embeddings_csv_repository::for_active_variant();
        if (!$repo->exists()) {
            return false;
        }

        $rows = $repo->read_rows();
        return $repo->is_valid_schema($rows);
    }

    /**
     * Cheap coverage check used on the synchronous skill-use path.
     *
     * Unlike {@see is_index_ready()} (schema only), this also asks "does every currently resolvable
     * corpus have at least one row?". It detects a freshly added corpus without any per-file hashing,
     * so the expensive diff/prune can be deferred to the adhoc task (eventual consistency).
     *
     * @return bool
     */
    public function is_index_covered(): bool {
        return $this->get_status()['ready'];
    }

    /**
     * Return full readiness status including provider, schema and corpus-coverage checks.
     *
     * @return array{ready:bool,status:string,reason:string}
     */
    public function get_status(): array {
        if (!$this->is_embeddings_provider_available()) {
            return ['ready' => false, 'status' => 'unavailable', 'reason' => 'embeddings_provider_missing'];
        }

        $repo = docs_embeddings_csv_repository::for_active_variant();
        if (!$repo->exists()) {
            return ['ready' => false, 'status' => 'missing', 'reason' => 'index_csv_not_found'];
        }

        $rows = $repo->read_rows();
        if (!$repo->is_valid_schema($rows)) {
            return ['ready' => false, 'status' => 'invalid', 'reason' => 'index_csv_invalid_schema'];
        }

        if (!$this->declared_corpora_covered($rows)) {
            return ['ready' => false, 'status' => 'incomplete', 'reason' => 'corpora_not_covered'];
        }

        return ['ready' => true, 'status' => 'ready', 'reason' => ''];
    }

    /**
     * Whether every currently resolvable corpus has at least one row in the index.
     *
     * Coverage is measured against the *resolvable* set (existing roots), not the full *declared*
     * set: a declared-but-unreadable corpus can never be indexed, so demanding a row for it would
     * reschedule the rebuild forever. Pruning, by contrast, is measured against the declared set
     * (see docs_embeddings_index_service::rebuild()), so such a corpus is kept, just not required.
     *
     * @param array<int,array<string,string>> $rows Already-read index rows.
     * @return bool
     */
    private function declared_corpora_covered(array $rows): bool {
        $resolvable = array_keys((new docs_corpus_registry())->list());
        if (empty($resolvable)) {
            return true;
        }

        $present = [];
        foreach ($rows as $row) {
            $cid = trim((string)($row['corpus_id'] ?? ''));
            if ($cid !== '') {
                $present[$cid] = true;
            }
        }

        foreach ($resolvable as $cid) {
            if (empty($present[$cid])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Settings updated-callback: schedule a rebuild when the corpus textarea changes.
     *
     * This is only the proactive fast path so a freshly added corpus does not have to wait for the
     * next skill invocation; the cheap skill-use coverage check remains the safety net, and the
     * expensive per-file diff/prune happens inside the task. The scheduling itself is gated on the
     * docs skill being active (see {@see ensure_rebuild_scheduled_if_needed()}).
     *
     * @param string $name The full setting name Moodle passes to updated-callbacks (unused).
     * @return void
     */
    public static function on_corpus_setting_updated(string $name = ''): void {
        (new self())->ensure_rebuild_scheduled_if_needed();
    }

    /**
     * Trigger an async rebuild adhoc task if the index is not ready.
     *
     * Uses a simple file-based debounce: if a task was queued in the last
     * $debounceseconds, skip queuing another one.
     *
     * @param int $debounceseconds Minimum seconds between task enqueues.
     * @return bool True when a task was queued.
     */
    public function ensure_rebuild_scheduled_if_needed(int $debounceseconds = 300): bool {
        // E1 gate: when the docs skill is inactive, never schedule any embedding work. This covers
        // every trigger that routes through here — skill use (B3) and the settings save (A5).
        if (!docs_embeddings_gate::is_docs_skill_active()) {
            return false;
        }

        $status = $this->get_status();
        if ($status['ready']) {
            return false;
        }

        if (!class_exists(self::REBUILD_TASK_CLASS)) {
            return false;
        }

        $lastqueued = (int)get_config('bookingextension_agent', 'docs_embeddings_rebuild_queued_at');
        if ($lastqueued > 0 && (time() - $lastqueued) < $debounceseconds) {
            return false;
        }

        $task = new \bookingextension_agent\task\rebuild_docs_embeddings_adhoc();
        \core\task\manager::queue_adhoc_task($task, true);
        set_config('docs_embeddings_rebuild_queued_at', (string)time(), 'bookingextension_agent');

        return true;
    }
}
