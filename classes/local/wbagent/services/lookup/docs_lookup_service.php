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
 * Documentation lookup service — semantic primary, lexical fallback.
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
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wbagent\services\llm\llm_call_service;

/**
 * Provides semantic and lexical search over registered documentation corpora.
 *
 * Retrieval strategy (in priority order):
 *  1. Semantic search via pre-built embeddings index (language-agnostic, preferred).
 *  2. Lexical token search over on-disk markdown files (fallback when index not ready).
 *
 * Line-windowed reading allows incremental document consumption without loading
 * full files into the LLM context on each call.
 */
class docs_lookup_service {
    /** Default number of lines to return per read window. */
    private const DEFAULT_LINE_COUNT = 80;

    /** Maximum number of result candidates from semantic search. */
    private const SEMANTIC_TOP_K = 5;

    /** Minimum cosine similarity score (0–1) to include a semantic result. */
    private const SEMANTIC_MIN_SCORE = 0.30;

    /** @var string Absolute path to the primary docs root. */
    private string $docsroot;

    /** @var string Relative path of the root entry document. */
    private string $rootdocpath;

    /** @var string Corpus id for semantic search filtering. */
    private string $corpusid;

    /**
     * Constructor.
     *
     * @param string|null $docsroot  Absolute path to docs directory.
     * @param string|null $rootdocpath  Relative path to root entry doc (default README.md).
     * @param string|null $corpusid  Corpus identifier for index filtering.
     */
    public function __construct(
        ?string $docsroot = null,
        ?string $rootdocpath = null,
        ?string $corpusid = null
    ) {
        if ($docsroot !== null && $docsroot !== '') {
            $this->docsroot = rtrim($docsroot, '/\\');
        } else {
            $this->docsroot = $this->resolve_default_docsroot();
        }

        $this->rootdocpath = trim((string)($rootdocpath ?? 'README.md'));
        if ($this->rootdocpath === '') {
            $this->rootdocpath = 'README.md';
        }

        $this->corpusid = trim((string)($corpusid ?? docs_embeddings_index_service::DEFAULT_CORPUS_ID));
    }

    // -------------------------------------------------------------------------
    // Public API
    // Separator.

    /**
     * Search using semantic embeddings (primary path, language-agnostic).
     *
     * Generates an embedding for $question, finds the closest doc chunks in the
     * pre-built index via cosine similarity, and returns enriched result records.
     *
     * Returns an empty array when the embeddings index is not ready — callers
     * should fall back to search_multi() in that case.
     *
     * @param string $question  User question in any language.
     * @param int    $contextid Moodle context id (for the embedding API call).
     * @param int    $userid    User id (for the embedding API call).
     * @param int    $limit     Maximum results to return.
     * @return array<int,array<string,mixed>>
     */
    public function search_semantic(
        string $question,
        int $contextid,
        int $userid,
        int $limit = self::SEMANTIC_TOP_K
    ): array {
        $readiness = new docs_embeddings_readiness_service();
        if (!$readiness->is_index_ready()) {
            return [];
        }

        $repo = new docs_embeddings_csv_repository();
        $rows = $repo->read_rows_for_corpus($this->corpusid);
        if (empty($rows)) {
            return [];
        }

        // Decode stored embedding vectors.
        $catalogrows = [];
        foreach ($rows as $row) {
            $vec = json_decode((string)($row['embedding_json'] ?? ''), true);
            if (!is_array($vec) || empty($vec)) {
                continue;
            }
            $catalogrows[] = array_merge($row, ['_vec' => $vec]);
        }

        if (empty($catalogrows)) {
            return [];
        }

        // Generate query embedding.
        $settings = (new embeddings_action_config_resolver())->resolve();
        $dimensions = (int)($settings['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS);

        $llm = new llm_call_service(new conversation_store());
        $embeddingcall = $llm->invoke_embeddings_for_context(
            0,
            $contextid,
            $userid,
            'core_explain_docs|semantic_search',
            $question,
            $dimensions
        );

        if (empty($embeddingcall['success']) || empty($embeddingcall['embedding'])) {
            return [];
        }

        $queryvec = (array)$embeddingcall['embedding'];

        // Rank by cosine similarity.
        $retrieval = new embeddings_retrieval_service();
        $toprows = $retrieval->search_top_k($queryvec, $catalogrows, max(1, $limit));

        $results = [];
        foreach ($toprows as $hit) {
            $score = (float)($hit['_similarity'] ?? 0.0);
            if ($score < self::SEMANTIC_MIN_SCORE) {
                continue;
            }

            $relpath = (string)($hit['chunk_path'] ?? '');
            $doc = $this->load_doc_meta($relpath);
            if ($doc === null) {
                continue;
            }

            $results[] = array_merge($doc, [
                'score' => (int)round($score * 1000),
                'search_method' => 'semantic',
            ]);
        }

        return $results;
    }

    /**
     * Lexical multi-query search over on-disk markdown files.
     *
     * Runs each query independently, merges results keeping best score per path,
     * applies a small cross-query bonus for docs that appear in multiple queries.
     *
     * Used as fallback when the semantic index is not available, and optionally
     * as a second-opinion signal alongside semantic results for short/exact terms.
     *
     * @param string[] $queries
     * @param int      $limit
     * @return array<int,array<string,mixed>>
     */
    public function search_multi(array $queries, int $limit = 3): array {
        $queries = array_values(array_unique(array_filter(array_map('trim', $queries))));
        if (empty($queries)) {
            return [];
        }

        $merged = [];
        $hitcounts = [];

        foreach ($queries as $query) {
            $singleresults = $this->search_lexical($query, max($limit * 2, 10));
            foreach ($singleresults as $doc) {
                $path = (string)($doc['path'] ?? '');
                if ($path === '') {
                    continue;
                }

                if (!isset($merged[$path])) {
                    $merged[$path] = $doc;
                    $hitcounts[$path] = 1;
                } else {
                    if ((int)($doc['score'] ?? 0) > (int)($merged[$path]['score'] ?? 0)) {
                        $merged[$path] = $doc;
                    }
                    $hitcounts[$path]++;
                }
            }
        }

        // Cross-query bonus: +15 per additional hit, capped at 2 extra = +30.
        foreach ($merged as $path => $doc) {
            $extrahits = min(2, ($hitcounts[$path] ?? 1) - 1);
            $merged[$path]['score'] = (int)($merged[$path]['score'] ?? 0) + ($extrahits * 15);
        }

        usort($merged, static function (array $a, array $b): int {
            $cmp = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
        });

        return array_slice(array_values($merged), 0, max(1, $limit));
    }

    /**
     * Read a documentation file by its relative path with line windowing.
     *
     * @param string $relpath   Relative path within docsroot.
     * @param int    $linestart First line to return (1-based).
     * @param int    $linecount Maximum lines to return.
     * @return array<string,mixed>|null  Doc payload or null if file not found.
     */
    public function read_doc_by_path(
        string $relpath,
        int $linestart = 1,
        int $linecount = self::DEFAULT_LINE_COUNT
    ): ?array {
        $relpath = $this->sanitize_rel_path($relpath);
        if ($relpath === '') {
            return null;
        }

        $abspath = $this->docsroot . '/' . $relpath;
        if (!is_readable($abspath)) {
            return null;
        }

        $content = @file_get_contents($abspath);
        if ($content === false) {
            return null;
        }

        return $this->build_windowed_doc($relpath, $content, $linestart, $linecount);
    }

    /**
     * Read the configured root entry document.
     *
     * @param int $linestart
     * @param int $linecount
     * @return array<string,mixed>|null
     */
    public function read_root_doc(int $linestart = 1, int $linecount = self::DEFAULT_LINE_COUNT): ?array {
        return $this->read_doc_by_path($this->rootdocpath, $linestart, $linecount);
    }

    /**
     * Return the configured root doc path.
     *
     * @return string
     */
    public function get_root_doc_path(): string {
        return $this->rootdocpath;
    }

    /**
     * Return a topic index derived from the root README link structure.
     *
     * Each entry has: topic_id (relative path), title (link text).
     *
     * @return array<int,array<string,string>>
     */
    public function get_master_toc_index(): array {
        $rootpath = $this->docsroot . '/' . $this->rootdocpath;
        if (!is_readable($rootpath)) {
            return [];
        }

        $content = @file_get_contents($rootpath);
        if ($content === false || $content === '') {
            return [];
        }

        $topics = [];
        // Match markdown links: [Title](path) where path ends with .md or is a directory README.
        if (preg_match_all('/\[([^\]]+)\]\(([^)]+\.md)\)/u', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $title = trim($match[1]);
                $linkpath = trim($match[2]);
                if ($title === '' || $linkpath === '' || strpos($linkpath, 'http') === 0) {
                    continue;
                }
                // Resolve relative links from root doc's directory.
                $rootdir = dirname($this->rootdocpath);
                if ($rootdir === '.') {
                    $resolved = $linkpath;
                } else {
                    $resolved = $rootdir . '/' . $linkpath;
                }
                $topics[] = [
                    'topic_id' => $resolved,
                    'title' => $title,
                ];
            }
        }

        return $topics;
    }

    /**
     * Build a short summary string from a doc payload.
     *
     * @param array<string,mixed> $doc
     * @param string $outputlang
     * @param string $question
     * @return string
     */
    public function build_summary(array $doc, string $outputlang = '', string $question = ''): string {
        $excerpt = trim((string)($doc['excerpt'] ?? $doc['content'] ?? ''));
        if ($excerpt === '') {
            return '';
        }

        // Return first sentence from excerpt.
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $excerpt, $matches)) {
            return trim($matches[1]);
        }

        return mb_substr($excerpt, 0, 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // Separator.

    /**
     * Perform a single-query lexical search over on-disk markdown files.
     *
     * @param string $question
     * @param int    $limit
     * @return array<int,array<string,mixed>>
     */
    private function search_lexical(string $question, int $limit = 5): array {
        $tokens = $this->extract_query_tokens($question);
        if (empty($tokens)) {
            return [];
        }

        $docs = [];
        foreach ($this->load_docs() as $doc) {
            $score = $this->score_doc($doc, $tokens, $question);
            if ($score <= 0) {
                continue;
            }
            $doc['score'] = $score;
            $doc['search_method'] = 'lexical';
            $docs[] = $doc;
        }

        usort($docs, static function (array $a, array $b): int {
            $cmp = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
        });

        return array_slice($docs, 0, max(1, $limit));
    }

    /**
     * Load metadata for all .md files in the docs root (without full content).
     *
     * @return array<int,array<string,mixed>>
     */
    private function load_docs(): array {
        if (!is_dir($this->docsroot)) {
            return [];
        }

        $docs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docsroot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }

            $abspath = $fileinfo->getPathname();
            if (strpos(str_replace('\\', '/', $abspath), '/pix/') !== false) {
                continue;
            }

            if (strtolower($fileinfo->getExtension()) !== 'md') {
                continue;
            }

            $content = @file_get_contents($abspath);
            if ($content === false) {
                continue;
            }

            $relpath = ltrim(substr($abspath, strlen($this->docsroot)), '/\\');
            $title = '';
            if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
                $title = trim($m[1]);
            }

            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
            $excerptlines = array_slice($lines, 0, 5);
            $excerpt = implode(' ', array_map('trim', $excerptlines));

            $docs[] = [
                'path' => $relpath,
                'basename' => strtolower(basename($relpath, '.md')),
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'score' => 0,
            ];
        }

        return $docs;
    }

    /**
     * Load minimal metadata for a single doc path (for semantic result enrichment).
     *
     * @param string $relpath
     * @return array<string,mixed>|null
     */
    private function load_doc_meta(string $relpath): ?array {
        $relpath = $this->sanitize_rel_path($relpath);
        if ($relpath === '') {
            return null;
        }

        $abspath = $this->docsroot . '/' . $relpath;
        if (!is_readable($abspath)) {
            return null;
        }

        $content = @file_get_contents($abspath);
        if ($content === false) {
            return null;
        }

        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $title = trim($m[1]);
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $excerptlines = array_slice($lines, 0, 5);

        return [
            'path' => $relpath,
            'basename' => strtolower(basename($relpath, '.md')),
            'title' => $title,
            'excerpt' => implode(' ', array_map('trim', $excerptlines)),
            'content' => $content,
        ];
    }

    /**
     * Build a line-windowed document payload.
     *
     * @param string $relpath
     * @param string $content
     * @param int    $linestart 1-based start line.
     * @param int    $linecount Lines to include.
     * @return array<string,mixed>
     */
    private function build_windowed_doc(string $relpath, string $content, int $linestart, int $linecount): array {
        $alllines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $totallines = count($alllines);

        $linestart = max(1, $linestart);
        $linecount = max(10, min($linecount, self::DEFAULT_LINE_COUNT * 2));

        $slicedlines = array_slice($alllines, $linestart - 1, $linecount);
        $windowcontent = implode("\n", $slicedlines);
        $nextlinestart = $linestart + count($slicedlines);
        $hasmore = $nextlinestart <= $totallines;

        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $title = trim($m[1]);
        }

        return [
            'path' => $relpath,
            'title' => $title,
            'content' => $windowcontent,
            'excerpt' => mb_substr($windowcontent, 0, 300),
            'line_start' => $linestart,
            'line_count' => count($slicedlines),
            'next_line_start' => $hasmore ? $nextlinestart : null,
            'has_more' => $hasmore,
            'total_lines' => $totallines,
            'score' => 0,
        ];
    }

    /**
     * Score a document against query tokens using lexical heuristics.
     *
     * @param array<string,mixed> $doc
     * @param string[]            $tokens
     * @param string              $question
     * @return int  Score ≥ 0; 0 means no match.
     */
    private function score_doc(array $doc, array $tokens, string $question): int {
        $path = mb_strtolower((string)($doc['path'] ?? ''));
        $title = mb_strtolower((string)($doc['title'] ?? ''));
        $excerpt = mb_strtolower((string)($doc['excerpt'] ?? ''));
        $content = mb_strtolower((string)($doc['content'] ?? ''));
        $basename = mb_strtolower((string)($doc['basename'] ?? ''));
        $questioncompact = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($question)) ?? '';

        $score = 0;

        foreach ($tokens as $token) {
            if (mb_strpos($path, $token) !== false) {
                $score += 10;
            }
            if (mb_strpos($title, $token) !== false) {
                $score += 30;
            }
            if (mb_strpos($excerpt, $token) !== false) {
                $score += 20;
            }
            if (mb_strpos($content, $token) !== false) {
                $score += 5;
            }
        }

        // Exact basename match with full question.
        if (
            $questioncompact !== '' && mb_strpos(
                preg_replace('/[^\p{L}\p{N}]+/u', '', $basename) ?? '',
                $questioncompact
            ) !== false
        ) {
            $score += 50;
        }

        return $score;
    }

    /**
     * Extract significant tokens from a query (language-safe, min 3 chars).
     *
     * @param string $question
     * @return string[]
     */
    private function extract_query_tokens(string $question): array {
        $normalized = mb_strtolower($question);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            if ($part !== '' && mb_strlen($part) >= 3) {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Resolve the default docsroot from plugin config or component autodetect.
     *
     * @return string
     */
    private function resolve_default_docsroot(): string {
        $configured = trim((string)get_config('bookingextension_agent', 'aidocsroot'));
        if ($configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/\\');
        }

        $modbookingdir = \core_component::get_component_directory('mod_booking');
        if ($modbookingdir !== null) {
            $fallback = $modbookingdir . '/docs';
            if (is_dir($fallback)) {
                return $fallback;
            }
        }

        return '';
    }

    /**
     * Sanitize a relative path to prevent directory traversal.
     *
     * @param string $relpath
     * @return string  Sanitized path, or empty string if rejected.
     */
    private function sanitize_rel_path(string $relpath): string {
        $relpath = trim($relpath, '/\\');
        // Reject traversal attempts.
        if (strpos($relpath, '..') !== false) {
            return '';
        }

        // Only allow .md files.
        if (!preg_match('/\.md$/i', $relpath)) {
            return '';
        }

        return $relpath;
    }
}
