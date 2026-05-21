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

namespace bookingextension_agent\local\wbagent\services\lookup;

/**
 * Deterministic lookup over local markdown documentation files.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docs_lookup_service {
    /** Maximum number of sample paths included per TOC topic. */
    private const MAX_TOPIC_SAMPLE_PATHS = 3;

    /** @var string */
    private string $docsroot;

    /** @var string */
    private string $rootdocpath;

    /**
     * Constructor.
     *
     * @param string|null $docsroot
     * @param string|null $rootdocpath
     */
    public function __construct(?string $docsroot = null, ?string $rootdocpath = null) {
        $this->docsroot = $docsroot ?? dirname(__DIR__, 5) . '/docs';
        $this->rootdocpath = trim((string)($rootdocpath ?? 'README.md'));
        if ($this->rootdocpath === '') {
            $this->rootdocpath = 'README.md';
        }
    }

    /**
     * Get configured root entry document path.
     *
     * @return string
     */
    public function get_root_doc_path(): string {
        return $this->rootdocpath;
    }

    /**
     * Read the configured root entry document.
     *
     * @param int $linestart
     * @param int $linecount
     * @return array<string,mixed>|null
     */
    public function read_root_doc(int $linestart = 1, int $linecount = 80): ?array {
        return $this->read_doc_by_path($this->rootdocpath, $linestart, $linecount);
    }

    /**
     * Search documentation files for the most relevant topic.
     *
     * @param string $question
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function search(string $question, int $limit = 3): array {
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
            $doc['exactbasenamehit'] = $this->has_exact_basename_hit($doc, $question);
            $docs[] = $doc;
        }

        usort($docs, static function (array $left, array $right): int {
            $scorecompare = ((int)($right['score'] ?? 0)) <=> ((int)($left['score'] ?? 0));
            if ($scorecompare !== 0) {
                return $scorecompare;
            }
            return strcmp((string)($left['path'] ?? ''), (string)($right['path'] ?? ''));
        });

        return array_slice($docs, 0, max(1, $limit));
    }

    /**
     * Search documentation files using multiple query variants and merge the results.
     *
     * Runs each query independently, then merges by keeping the best score per
     * document path.  Documents that are hit by more than one query receive a
     * small bonus (+15 per additional hit, capped at 2 extra hits = +30 max),
     * which nudges cross-query relevant documents to the top without drowning
     * single-query exact hits.
     *
     * @param  string[] $queries  List of search phrases (duplicates are removed).
     * @param  int      $limit    Maximum number of results to return.
     * @return array<int,array<string,mixed>>
     */
    public function search_multi(array $queries, int $limit = 3): array {
        $queries = array_values(array_unique(array_filter(array_map('trim', $queries))));
        if (empty($queries)) {
            return [];
        }

        $alldocs = $this->load_docs();

        // Track best score and hit count per doc path across all queries.
        $pathmap = [];
        foreach ($queries as $query) {
            $tokens = $this->extract_query_tokens($query);
            if (empty($tokens)) {
                continue;
            }
            foreach ($alldocs as $doc) {
                $score = $this->score_doc($doc, $tokens, $query);
                if ($score <= 0) {
                    continue;
                }
                $path = (string)($doc['path'] ?? '');
                if (!isset($pathmap[$path])) {
                    $pathmap[$path] = ['doc' => $doc, 'best_score' => 0, 'hit_count' => 0];
                }
                if ($score > $pathmap[$path]['best_score']) {
                    $pathmap[$path]['best_score'] = $score;
                    $pathmap[$path]['doc']        = $doc;
                }
                $pathmap[$path]['hit_count']++;
            }
        }

        $results = [];
        foreach ($pathmap as $entry) {
            $doc = $entry['doc'];
            // Multi-query bonus: +15 per additional hit beyond the first (max +30).
            $bonus = min($entry['hit_count'] - 1, 2) * 15;
            $doc['score'] = $entry['best_score'] + $bonus;
            // Exact-basename detection: true if any query triggers it.
            $doc['exactbasenamehit'] = false;
            foreach ($queries as $query) {
                if ($this->has_exact_basename_hit($doc, $query)) {
                    $doc['exactbasenamehit'] = true;
                    break;
                }
            }
            $results[] = $doc;
        }

        usort($results, static function (array $left, array $right): int {
            $scorecompare = ((int)($right['score'] ?? 0)) <=> ((int)($left['score'] ?? 0));
            if ($scorecompare !== 0) {
                return $scorecompare;
            }
            return strcmp((string)($left['path'] ?? ''), (string)($right['path'] ?? ''));
        });

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * Whether the given search result set should be treated as ambiguous.
     *
     * @param array $docs
     * @return bool
     */
    public function is_ambiguous(array $docs): bool {
        if (count($docs) < 2) {
            return false;
        }

        $first = $docs[0] ?? [];
        $second = $docs[1] ?? [];
        if (!empty($first['exactbasenamehit'])) {
            return false;
        }

        $topscore = (int)($first['score'] ?? 0);
        $secondscore = (int)($second['score'] ?? 0);
        if ($topscore <= 0 || $secondscore <= 0) {
            return false;
        }

        return $secondscore >= (int)floor($topscore * 0.7);
    }

    /**
     * Return human-readable top candidate titles for ambiguity prompts.
     *
     * @param array $docs
     * @param int $limit
     * @return array
     */
    public function get_ambiguity_candidates(array $docs, int $limit = 4): array {
        $candidates = [];
        foreach (array_slice($docs, 0, max(2, $limit)) as $doc) {
            $title = trim((string)($doc['title'] ?? ''));
            $path = trim((string)($doc['path'] ?? ''));
            if ($title === '') {
                $title = $path;
            }
            if ($title === '') {
                continue;
            }
            $candidates[] = $title;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Return a lightweight index of all docs (path, title, excerpt) for LLM-based selection.
     *
     * @return array<int,array{path:string,title:string,excerpt:string}>
     */
    public function get_all_doc_index(): array {
        $index = [];
        foreach ($this->load_docs() as $doc) {
            $index[] = [
                'path'    => (string)($doc['path'] ?? ''),
                'title'   => (string)($doc['title'] ?? ''),
                'excerpt' => (string)($doc['excerpt'] ?? ''),
            ];
        }
        return $index;
    }

    /**
     * Build a compact, deterministic topic catalog over all docs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_master_toc_index(): array {
        $topics = [];

        foreach ($this->load_docs() as $doc) {
            $path = (string)($doc['path'] ?? '');
            $topicid = $this->extract_topic_id_from_path($path);
            if (!isset($topics[$topicid])) {
                $topics[$topicid] = [
                    'topic_id' => $topicid,
                    'title' => $this->build_topic_title($topicid),
                    'intent' => 'documentation',
                    'doc_count' => 0,
                    'keywords' => [],
                    'sample_paths' => [],
                ];
            }

            $topics[$topicid]['doc_count']++;

            if (
                count($topics[$topicid]['sample_paths']) < self::MAX_TOPIC_SAMPLE_PATHS
                && $path !== ''
            ) {
                $topics[$topicid]['sample_paths'][] = $path;
            }

            $terms = $this->extract_topic_terms($doc);
            foreach ($terms as $term) {
                if (!isset($topics[$topicid]['keywords'][$term])) {
                    $topics[$topicid]['keywords'][$term] = 0;
                }
                $topics[$topicid]['keywords'][$term]++;
            }
        }

        foreach ($topics as &$topic) {
            $keywordscores = $topic['keywords'];
            arsort($keywordscores);
            $topic['keywords'] = array_slice(array_keys($keywordscores), 0, 8);
            sort($topic['sample_paths']);
        }
        unset($topic);

        usort($topics, static function (array $left, array $right): int {
            $countcompare = ((int)($right['doc_count'] ?? 0)) <=> ((int)($left['doc_count'] ?? 0));
            if ($countcompare !== 0) {
                return $countcompare;
            }
            return strcmp((string)($left['topic_id'] ?? ''), (string)($right['topic_id'] ?? ''));
        });

        return array_values($topics);
    }

    /**
     * Return only docs that belong to a specific topic id.
     *
     * @param string $topicid
     * @return array<int,array{path:string,title:string,excerpt:string}>
     */
    public function get_topic_doc_index(string $topicid): array {
        $topicid = trim($topicid);
        if ($topicid === '') {
            return [];
        }

        $index = [];
        foreach ($this->load_docs() as $doc) {
            $path = (string)($doc['path'] ?? '');
            if ($this->extract_topic_id_from_path($path) !== $topicid) {
                continue;
            }
            $index[] = [
                'path' => $path,
                'title' => (string)($doc['title'] ?? ''),
                'excerpt' => (string)($doc['excerpt'] ?? ''),
            ];
        }

        return $index;
    }

    /**
     * Render a compact TOC observation string for agent context injection.
     *
     * @param int $maxchars
     * @return string
     */
    public function render_master_toc_observation(int $maxchars = 3500): string {
        $payload = [
            'type' => 'docs_master_toc',
            'topics' => $this->get_master_toc_index(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return 'DOCS_MASTER_TOC: {}';
        }

        $maxchars = max(800, $maxchars);
        if (mb_strlen($json) > $maxchars) {
            $json = mb_substr($json, 0, $maxchars) . '...';
        }

        return 'DOCS_MASTER_TOC: ' . $json;
    }

    /**
     * Deterministically map a question to the most likely docs topic.
     *
     * @param string $question
     * @param string[] $queries
     * @return array{topic_id:string,topic_title:string,score:int,confidence:float,candidates:array<int,array<string,mixed>>}
     */
    public function detect_best_topic(string $question, array $queries = []): array {
        $queries = array_values(array_filter(array_unique(array_map('trim', $queries))));
        $basequeries = array_values(array_filter(array_unique(array_merge([$question], $queries))));

        $combinedtokens = [];
        foreach ($basequeries as $query) {
            $combinedtokens = array_merge($combinedtokens, $this->extract_query_tokens($query));
        }
        $combinedtokens = array_values(array_unique($combinedtokens));

        $topics = $this->get_master_toc_index();
        if (empty($topics) || empty($combinedtokens)) {
            return [
                'topic_id' => '',
                'topic_title' => '',
                'score' => 0,
                'confidence' => 0.0,
                'candidates' => [],
            ];
        }

        $candidates = [];
        foreach ($topics as $topic) {
            $score = $this->score_topic($topic, $combinedtokens, $basequeries);
            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'topic_id' => (string)($topic['topic_id'] ?? ''),
                'title' => (string)($topic['title'] ?? ''),
                'score' => $score,
                'doc_count' => (int)($topic['doc_count'] ?? 0),
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $scorecompare = ((int)($right['score'] ?? 0)) <=> ((int)($left['score'] ?? 0));
            if ($scorecompare !== 0) {
                return $scorecompare;
            }
            return strcmp((string)($left['topic_id'] ?? ''), (string)($right['topic_id'] ?? ''));
        });

        $first = $candidates[0] ?? ['topic_id' => '', 'title' => '', 'score' => 0];
        $secondscore = (int)(($candidates[1] ?? [])['score'] ?? 0);
        $topscore = (int)($first['score'] ?? 0);
        $confidence = 0.0;
        if ($topscore > 0) {
            $confidence = ($topscore - $secondscore) / max($topscore, 1);
        }

        return [
            'topic_id' => (string)($first['topic_id'] ?? ''),
            'topic_title' => (string)($first['title'] ?? ''),
            'score' => $topscore,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'candidates' => array_slice($candidates, 0, 3),
        ];
    }

    /**
     * Search only inside one topic and optionally merge multiple query variants.
     *
     * @param string $topicid
     * @param string $question
     * @param string[] $queries
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function search_in_topic(string $topicid, string $question, array $queries = [], int $limit = 3): array {
        $topicid = trim($topicid);
        if ($topicid === '') {
            return [];
        }

        $queries = array_values(array_filter(array_unique(array_map('trim', $queries))));
        $allqueries = array_values(array_filter(array_unique(array_merge([$question], $queries))));

        $docsintopic = [];
        foreach ($this->load_docs() as $doc) {
            $path = (string)($doc['path'] ?? '');
            if ($this->extract_topic_id_from_path($path) === $topicid) {
                $docsintopic[] = $doc;
            }
        }

        if (empty($docsintopic)) {
            return [];
        }

        return $this->search_docs($docsintopic, $allqueries, $limit);
    }

    /**
     * Load the full content of specific docs by path.
     * @param array $paths
     * @return array
     *
     */
    public function load_docs_by_paths(array $paths): array {
        $pathmap = array_fill_keys($paths, true);
        $result = [];
        foreach ($this->load_docs() as $doc) {
            if (isset($pathmap[(string)($doc['path'] ?? '')])) {
                $result[] = $doc;
            }
        }
        return $result;
    }

    /**
     * Search in a pre-filtered document list using one or more query variants.
     *
     * @param array<int,array<string,mixed>> $docs
     * @param string[] $queries
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    private function search_docs(array $docs, array $queries, int $limit): array {
        $queries = array_values(array_filter(array_unique(array_map('trim', $queries))));
        if (empty($queries)) {
            return [];
        }

        $pathmap = [];
        foreach ($queries as $query) {
            $tokens = $this->extract_query_tokens($query);
            if (empty($tokens)) {
                continue;
            }
            foreach ($docs as $doc) {
                $score = $this->score_doc($doc, $tokens, $query);
                if ($score <= 0) {
                    continue;
                }
                $path = (string)($doc['path'] ?? '');
                if (!isset($pathmap[$path])) {
                    $pathmap[$path] = ['doc' => $doc, 'best_score' => 0, 'hit_count' => 0];
                }
                if ($score > $pathmap[$path]['best_score']) {
                    $pathmap[$path]['best_score'] = $score;
                    $pathmap[$path]['doc'] = $doc;
                }
                $pathmap[$path]['hit_count']++;
            }
        }

        $results = [];
        foreach ($pathmap as $entry) {
            $doc = $entry['doc'];
            $bonus = min($entry['hit_count'] - 1, 2) * 15;
            $doc['score'] = $entry['best_score'] + $bonus;
            $doc['exactbasenamehit'] = false;
            foreach ($queries as $query) {
                if ($this->has_exact_basename_hit($doc, $query)) {
                    $doc['exactbasenamehit'] = true;
                    break;
                }
            }
            $results[] = $doc;
        }

        usort($results, static function (array $left, array $right): int {
            $scorecompare = ((int)($right['score'] ?? 0)) <=> ((int)($left['score'] ?? 0));
            if ($scorecompare !== 0) {
                return $scorecompare;
            }
            return strcmp((string)($left['path'] ?? ''), (string)($right['path'] ?? ''));
        });

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * Extract stable topic id from docs path.
     *
     * @param string $path
     * @return string
     */
    private function extract_topic_id_from_path(string $path): string {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || strpos($path, '/') === false) {
            return 'overview';
        }

        $parts = explode('/', $path);
        $topic = trim((string)($parts[0] ?? ''));
        return $topic !== '' ? $topic : 'overview';
    }

    /**
     * Build display title from topic id.
     *
     * @param string $topicid
     * @return string
     */
    private function build_topic_title(string $topicid): string {
        if ($topicid === 'overview') {
            return 'Overview';
        }

        $label = str_replace(['_', '-'], ' ', strtolower($topicid));
        return ucwords(trim($label));
    }

    /**
     * Collect candidate topic terms from a doc title/path/basename.
     *
     * @param array<string,mixed> $doc
     * @return array<int,string>
     */
    private function extract_topic_terms(array $doc): array {
        $raw = implode(' ', [
            (string)($doc['title'] ?? ''),
            (string)($doc['basename'] ?? ''),
            (string)($doc['path'] ?? ''),
        ]);

        $tokens = $this->extract_query_tokens($raw);
        return array_values(array_unique($tokens));
    }

    /**
     * Score one topic entry against combined query tokens.
     *
     * @param array<string,mixed> $topic
     * @param array<int,string> $tokens
     * @param array<int,string> $queries
     * @return int
     */
    private function score_topic(array $topic, array $tokens, array $queries): int {
        $score = 0;

        $topicid = strtolower((string)($topic['topic_id'] ?? ''));
        $title = strtolower((string)($topic['title'] ?? ''));
        $keywords = array_map('strtolower', (array)($topic['keywords'] ?? []));
        $samplepaths = array_map('strtolower', (array)($topic['sample_paths'] ?? []));

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (strpos($topicid, $token) !== false) {
                $score += 55;
            }
            if (strpos($title, $token) !== false) {
                $score += 35;
            }
            if (in_array($token, $keywords, true)) {
                $score += 25;
            }
            foreach ($samplepaths as $path) {
                if (strpos($path, $token) !== false) {
                    $score += 12;
                    break;
                }
            }
        }

        foreach ($queries as $query) {
            $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($query)) ?? '';
            if ($compact !== '' && strpos($compact, str_replace('_', '', $topicid)) !== false) {
                $score += 120;
            }
        }

        return $score;
    }

    /**
     * Read a single documentation file by path and return one chunk.
     *
     * Path traversal is prevented by stripping ".." segments.
     * Returns null when the file does not exist or is not a markdown file.
     *
     * @param  string $path       Relative path within docs root, e.g. booking_rules/overview.md.
     * @param  int    $linestart  1-based start line of the requested chunk.
     * @param  int    $linecount  Number of lines to return in the chunk.
     * @return array<string,mixed>|null
     */
    public function read_doc_by_path(string $path, int $linestart = 1, int $linecount = 80): ?array {
        // Normalise and reject path traversal attempts.
        $safepath = str_replace('\\', '/', $path);
        $safepath = implode('/', array_filter(
            explode('/', $safepath),
            static fn(string $segment): bool => $segment !== '..' && $segment !== '.'
        ));
        $safepath = ltrim($safepath, '/');

        if ($safepath === '') {
            return null;
        }

        $fullpath = $this->docsroot . '/' . $safepath;

        if (!is_file($fullpath) || strtolower(pathinfo($fullpath, PATHINFO_EXTENSION)) !== 'md') {
            return null;
        }

        $content = @file_get_contents($fullpath);
        if (!is_string($content) || $content === '') {
            return null;
        }

        $linecount = max(20, min(200, $linecount));
        $linestart = max(1, $linestart);

        $normalizedcontent = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $normalizedcontent);
        $totallines = count($lines);

        if ($totallines === 0) {
            return null;
        }

        $startindex = min(max(0, $linestart - 1), max(0, $totallines - 1));
        $chunklines = array_slice($lines, $startindex, $linecount);
        $lineend = $startindex + count($chunklines);
        $chunkcontent = implode("\n", $chunklines);
        $hasmore = $lineend < $totallines;
        $nextlinestart = $hasmore ? $lineend + 1 : null;

        $basename = pathinfo($fullpath, PATHINFO_FILENAME);
        $links = $this->extract_markdown_links_from_text($chunkcontent, $safepath);

        return [
            'path'     => $safepath,
            'title'    => $this->extract_title($content, $basename),
            'excerpt'  => $this->extract_excerpt($content),
            'basename' => $basename,
            'content'  => $content,
            'chunk_content' => $chunkcontent,
            'line_start' => $startindex + 1,
            'line_end' => $lineend,
            'total_lines' => $totallines,
            'has_more' => $hasmore,
            'next_line_start' => $nextlinestart,
            'chunk_links' => $links,
        ];
    }

    /**
     * Build a concise user-facing explanation from a matched doc.
     *
     * @param array $doc
     * @return string
     */
    public function build_summary(array $doc, int $cmid = 0, string $outputlang = '', string $question = ''): string {
        $source = trim((string)($doc['chunk_content'] ?? $doc['content'] ?? ''));
        $steps = $this->extract_first_ordered_steps($source);
        if ($steps !== '') {
            return $steps;
        }

        $excerpt = $this->strip_markdown((string)($doc['excerpt'] ?? ''));
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt);
        if ($excerpt === '') {
            $excerpt = $this->strip_markdown((string)($doc['title'] ?? ''));
        }

        if (preg_match('/^(.+?[.!?])(\s|$)/', $excerpt, $matches)) {
            return trim($matches[1]);
        }

        return trim($excerpt);
    }

    /**
     * Load and parse all markdown docs.
     *
     * @return array<int,array<string,string>>
     */
    private function load_docs(): array {
        if (!is_dir($this->docsroot)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docsroot, \FilesystemIterator::SKIP_DOTS)
        );

        $docs = [];
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo instanceof \SplFileInfo || !$fileinfo->isFile()) {
                continue;
            }

            if (strtolower($fileinfo->getExtension()) !== 'md') {
                continue;
            }

            $fullpath = $fileinfo->getPathname();
            $content = @file_get_contents($fullpath);
            if (!is_string($content) || $content === '') {
                continue;
            }

            $relativepath = ltrim(str_replace($this->docsroot, '', $fullpath), '/');
            $relativepath = str_replace('\\', '/', $relativepath);
            $title = $this->extract_title($content, $fileinfo->getBasename('.md'));
            $excerpt = $this->extract_excerpt($content);

            $docs[] = [
                'path' => $relativepath,
                'title' => $title,
                'excerpt' => $excerpt,
                'basename' => $fileinfo->getBasename('.md'),
                'content' => $content,
            ];
        }

        return $docs;
    }

    /**
     * Score a doc for a given question.
     *
     * @param array $doc
     * @param array $tokens
     * @param string $question
     * @return int
     */
    private function score_doc(array $doc, array $tokens, string $question): int {
        $score = 0;
        $path = mb_strtolower((string)($doc['path'] ?? ''));
        $title = mb_strtolower((string)($doc['title'] ?? ''));
        $excerpt = mb_strtolower((string)($doc['excerpt'] ?? ''));
        $content = mb_strtolower((string)($doc['content'] ?? ''));
        $basename = mb_strtolower((string)($doc['basename'] ?? ''));
        $questioncompact = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($question)) ?? '';
        $titlepathmatches = [];
        $contentmatches = [];

        if ($basename !== '' && $questioncompact !== '' && strpos($questioncompact, $basename) !== false) {
            $score += 250;
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (strpos($title, $token) !== false) {
                $score += 60;
                $titlepathmatches[$token] = true;
            }
            if (strpos($path, $token) !== false) {
                $score += 40;
                $titlepathmatches[$token] = true;
            }
            if (strpos($excerpt, $token) !== false) {
                $score += 20;
                $contentmatches[$token] = true;
            }
            if (strpos($content, $token) !== false) {
                $score += 5;
                $contentmatches[$token] = true;
            }
        }

        $tokencount = max(1, count($tokens));
        $score += (int)floor((count($titlepathmatches) / $tokencount) * 220);
        $score += (int)floor((count($contentmatches) / $tokencount) * 80);

        if (in_array($basename, ['readme', 'overview', 'index'], true)) {
            $score -= 25;
        }

        return $score;
    }

    /**
     * Detect whether the question explicitly contains the markdown basename.
     *
     * @param array $doc
     * @param string $question
     * @return bool
     */
    private function has_exact_basename_hit(array $doc, string $question): bool {
        $basename = mb_strtolower((string)($doc['basename'] ?? ''));
        if ($basename === '') {
            return false;
        }

        $genericbasenames = [
            'readme', 'action', 'actions', 'condition', 'conditions', 'overview',
        ];
        if (in_array($basename, $genericbasenames, true)) {
            return false;
        }

        $questioncompact = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($question)) ?? '';
        return $questioncompact !== '' && strpos($questioncompact, $basename) !== false;
    }

    /**
     * Extract significant query tokens.
     *
     * @param string $question
     * @return array
     */
    private function extract_query_tokens(string $question): array {
        $normalized = mb_strtolower($question);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || mb_strlen($part) < 3) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Extract the first ordered step list from markdown content.
     *
     * @param string $content
     * @return string
     */
    private function extract_first_ordered_steps(string $content): string {
        if ($content === '') {
            return '';
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $collecting = false;
        $steps = [];
        $itemcount = 0;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if (!$collecting && preg_match('/^\s*\d+\.\s+\S/u', $trimmed)) {
                $collecting = true;
            }

            if (!$collecting) {
                continue;
            }

            if (preg_match('/^\s*#{1,6}\s+/u', $trimmed)) {
                break;
            }

            if ($trimmed === '') {
                if (!empty($steps)) {
                    $steps[] = '';
                }
                continue;
            }

            if (
                preg_match('/^\s*\d+\.\s+\S/u', $trimmed)
                || preg_match('/^\s{2,}\S/u', $trimmed)
            ) {
                if (preg_match('/^\s*\d+\.\s+\S/u', $trimmed)) {
                    $itemcount++;
                }
                $steps[] = $trimmed;
                continue;
            }

            if (!empty($steps)) {
                break;
            }
        }

        if ($itemcount < 2 || empty($steps)) {
            return '';
        }

        $result = trim(implode("\n", $steps));
        return mb_substr($result, 0, 900);
    }

    /**
     * Extract the markdown H1 title.
     *
     * @param string $content
     * @param string $fallback
     * @return string
     */
    private function extract_title(string $content, string $fallback): string {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return $fallback;
    }

    /**
     * Extract a useful short excerpt.
     *
     * @param string $content
     * @return string
     */
    private function extract_excerpt(string $content): string {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $normalized);
        $headlines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $headlines[] = $trimmed;
            if (count($headlines) >= 18) {
                break;
            }
        }

        if (empty($headlines)) {
            return '';
        }

        $snippet = $this->strip_markdown(implode(' ', $headlines));

        // Collapse repeated spaces without regex.
        while (strpos($snippet, '  ') !== false) {
            $snippet = str_replace('  ', ' ', $snippet);
        }

        return mb_substr(trim($snippet), 0, 600);
    }

    /**
     * Extract markdown links from a text chunk and resolve relative paths.
     *
     * @param string $text
     * @param string $currentpath
     * @return array<int,string>
     */
    private function extract_markdown_links_from_text(string $text, string $currentpath): array {
        $links = [];
        $offset = 0;

        while (true) {
            $open = strpos($text, '[', $offset);
            if ($open === false) {
                break;
            }

            $labelend = strpos($text, '](', $open);
            if ($labelend === false) {
                break;
            }

            $urlstart = $labelend + 2;
            $urlend = strpos($text, ')', $urlstart);
            if ($urlend === false) {
                break;
            }

            $rawtarget = trim(substr($text, $urlstart, $urlend - $urlstart));
            $offset = $urlend + 1;
            if ($rawtarget === '' || str_starts_with($rawtarget, '#') || str_contains($rawtarget, '://')) {
                continue;
            }

            $resolved = $this->resolve_relative_doc_link($currentpath, $rawtarget);
            if ($resolved === '' || !str_ends_with(strtolower($resolved), '.md')) {
                continue;
            }

            $links[] = $resolved;
        }

        return array_values(array_unique($links));
    }

    /**
     * Resolve a markdown link against the current doc path.
     *
     * @param string $currentpath
     * @param string $target
     * @return string
     */
    private function resolve_relative_doc_link(string $currentpath, string $target): string {
        $target = explode('#', $target, 2)[0];
        $target = explode('?', $target, 2)[0];
        $target = str_replace('\\', '/', trim($target));
        if ($target === '') {
            return '';
        }

        $currentdir = dirname($currentpath);
        if ($currentdir === '.') {
            $currentdir = '';
        }

        if (str_starts_with($target, '/')) {
            $rawpath = ltrim($target, '/');
        } else {
            $rawpath = ($currentdir !== '' ? $currentdir . '/' : '') . $target;
        }

        $segments = explode('/', $rawpath);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }

    /**
     * Strip simple markdown markup.
     *
     * @param string $text
     * @return string
     */
    private function strip_markdown(string $text): string {
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $text) ?? $text;
        $text = str_replace(['**', '__', chr(96)], '', $text);
        return trim($text);
    }
}
