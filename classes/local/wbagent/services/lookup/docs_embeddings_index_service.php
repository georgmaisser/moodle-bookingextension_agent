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
 * Index service for documentation chunk embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\lookup;

use bookingextension_agent\local\wbagent\embeddings_action_config_resolver;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;
use context_system;

/**
 * Builds and persists the documentation embeddings index.
 *
 * Each registered corpus (identified by corpus_id + docs root path) is scanned
 * for .md files. Each file becomes one chunk. Content hashes prevent redundant
 * re-embedding on unchanged files.
 *
 * Corpus registry is driven by docs_corpus_registry, which discovers provider-declared
 * corpora and merges the admin-configured corpus (aidocsroot / aidocs_corpusid) on top.
 */
class docs_embeddings_index_service {
    /**
     * Rebuild the documentation embeddings index.
     *
     * Scans all registered corpus roots, computes content hashes for .md files,
     * skips unchanged chunks (hash match + existing embedding), generates new
     * embeddings for new/changed files, removes stale entries.
     *
     * @param string|null $model    Override embedding model (uses config if null).
     * @param int|null    $dimensions Override dimensions (uses config if null).
     * @param bool        $force    Force re-embedding of all chunks.
     * @return array<string,mixed>  Summary: status, embedded, reused, deleted, written.
     */
    public function rebuild(
        ?string $model = null,
        ?int $dimensions = null,
        bool $force = false
    ): array {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            return [
                'status' => 'skipped',
                'reason' => 'embeddings_provider_unavailable',
                'written' => 0, 'embedded' => 0, 'reused' => 0, 'deleted' => 0,
            ];
        }

        $settings = (new embeddings_action_config_resolver())->resolve();
        $resolvedmodel = trim((string)($model ?? ($settings['model'] ?? orchestrator::EMBEDDINGS_DEFAULT_MODEL)));
        if ($resolvedmodel === '') {
            $resolvedmodel = orchestrator::EMBEDDINGS_DEFAULT_MODEL;
        }
        $resolveddimensions = (int)($dimensions ?? ($settings['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS));
        if ($resolveddimensions < 1) {
            $resolveddimensions = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;
        }

        $corpora = $this->get_registered_corpora();
        if (empty($corpora)) {
            return [
                'status' => 'empty',
                'reason' => 'no_corpora_registered',
                'written' => 0, 'embedded' => 0, 'reused' => 0, 'deleted' => 0,
            ];
        }

        $repo = new docs_embeddings_csv_repository();
        $existingrows = $repo->read_rows();
        $existingbykey = [];
        foreach ($existingrows as $row) {
            $key = ($row['corpus_id'] ?? '') . '||' . ($row['chunk_path'] ?? '');
            if ($key !== '||') {
                $existingbykey[$key] = $row;
            }
        }

        $context = context_system::instance();
        $admin = get_admin();
        $userid = !empty($admin->id) ? (int)$admin->id : 2;
        $llm = new llm_call_service(new conversation_store());

        $newrows = [];
        $embedded = 0;
        $reused = 0;

        foreach ($corpora as $corpusid => $docsroot) {
            $files = $this->scan_md_files($docsroot);
            foreach ($files as $abspath) {
                $relpath = ltrim(substr($abspath, strlen($docsroot)), '/\\');
                $content = @file_get_contents($abspath);
                if ($content === false) {
                    continue;
                }

                $title = $this->extract_title($content);
                $lines = substr_count($content, "\n") + 1;
                $contenthash = sha1($content . '|m=' . $resolvedmodel . '|d=' . $resolveddimensions);
                $key = $corpusid . '||' . $relpath;
                $existing = $existingbykey[$key] ?? null;

                if (
                    !$force
                    && is_array($existing)
                    && trim((string)($existing['content_hash'] ?? '')) === $contenthash
                    && trim((string)($existing['embedding_json'] ?? '')) !== ''
                ) {
                    $newrows[] = $existing;
                    $reused++;
                    continue;
                }

                $inputtext = $this->build_embedding_input($corpusid, $relpath, $title, $content);
                $embeddingcall = $llm->invoke_embeddings_for_context(
                    0,
                    (int)$context->id,
                    $userid,
                    'docs_idx|corpus=' . $corpusid,
                    $inputtext,
                    $resolveddimensions
                );

                if (empty($embeddingcall['success']) || empty($embeddingcall['embedding'])) {
                    continue;
                }

                $newrows[] = [
                    'corpus_id' => $corpusid,
                    'chunk_path' => $relpath,
                    'chunk_title' => $title,
                    'line_start' => '1',
                    'line_end' => (string)$lines,
                    'embedding_model' => $resolvedmodel,
                    'embedding_dimensions' => (string)$resolveddimensions,
                    'content_hash' => $contenthash,
                    'embedding_json' => (string)json_encode($embeddingcall['embedding'], JSON_UNESCAPED_UNICODE),
                ];
                $embedded++;
            }
        }

        $deleted = count($existingrows) - $reused;
        if ($deleted < 0) {
            $deleted = 0;
        }

        $repo->write_rows($newrows);

        return [
            'status' => 'ok',
            'written' => count($newrows),
            'embedded' => $embedded,
            'reused' => $reused,
            'deleted' => $deleted,
        ];
    }

    /**
     * Return registered corpus roots keyed by corpus_id.
     *
     * Delegates to the docs_corpus_registry (the single corpus_id → root authority): all corpora
     * declared by component docs_providers plus the admin-configured corpus. Every one of them is
     * scanned and indexed (rows are tagged with their corpus_id).
     *
     * @return array<string,string>  corpus_id => absolute docs root path
     */
    public function get_registered_corpora(): array {
        return (new docs_corpus_registry())->list();
    }

    /**
     * Scan a directory for all .md files recursively (excluding pix/ subdirs).
     *
     * @param string $rootdir
     * @return array<int,string>  Absolute file paths, sorted.
     */
    private function scan_md_files(string $rootdir): array {
        if (!is_dir($rootdir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootdir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }

            $path = $fileinfo->getPathname();

            // Skip pix/ subdirectories (images only).
            if (strpos(str_replace('\\', '/', $path), '/pix/') !== false) {
                continue;
            }

            if (strtolower($fileinfo->getExtension()) !== 'md') {
                continue;
            }

            $files[] = $path;
        }

        sort($files);
        return $files;
    }

    /**
     * Extract the first H1 heading from markdown content as the chunk title.
     *
     * @param string $content
     * @return string
     */
    private function extract_title(string $content): string {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Build a rich text input for embedding generation.
     *
     * Prepends corpus, path, and title as context so that the embedding captures
     * both the structural location and the semantic content of the chunk.
     *
     * @param string $corpusid
     * @param string $relpath
     * @param string $title
     * @param string $content
     * @return string
     */
    private function build_embedding_input(string $corpusid, string $relpath, string $title, string $content): string {
        $parts = [];
        $parts[] = 'corpus: ' . $corpusid;
        $parts[] = 'path: ' . $relpath;
        if ($title !== '') {
            $parts[] = 'title: ' . $title;
        }
        // Truncate content to ~6000 chars to stay within typical embedding token limits.
        $trimmedcontent = mb_substr($content, 0, 6000);
        $parts[] = $trimmedcontent;

        return implode("\n", $parts);
    }
}
