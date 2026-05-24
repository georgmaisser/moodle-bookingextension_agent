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

namespace bookingextension_agent\local\wbagent\booking\tasks;

use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\services\lookup\docs_lookup_service;
use moodle_url;

/**
 * Task definition for booking.explain_docs_topic.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explain_docs_topic_task extends booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.explain_docs_topic';

    /** Minimum confidence required for topic-scoped retrieval. */
    private const TOPIC_CONFIDENCE_THRESHOLD = 0.45;

    /** Minimum topic score required before constraining retrieval to one topic. */
    private const TOPIC_MIN_SCORE = 180;

    /** Candidate pool size before final top-2 selection. */
    private const DOC_CANDIDATE_POOL_LIMIT = 20;

    /** Soft score for adding root doc as navigation context. */
    private const ROOT_DOC_NAV_SCORE = 50;

    /** Default line window per docs read step. */
    private const DEFAULT_LINE_COUNT = 80;

    /** Reduced line window for first observation to avoid context drift. */
    private const FIRST_STEP_LINE_COUNT = 40;

    /** High-confidence threshold for planner-driven direct doc-path selection. */
    private const PLANNER_DIRECT_DOC_CONFIDENCE = 0.72;

    /** Score ratio threshold for disambiguation when top candidates are too close. */
    private const DISAMBIGUATION_RATIO = 0.90;

    /** Lower ratio threshold when top candidates come from different topics. */
    private const DISAMBIGUATION_MIXED_TOPIC_RATIO = 0.82;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Explain documented features by searching local markdown documentation '
                . 'and using the two best matches.',
            'readonly' => $this->is_read_only(),
            'planner_capabilities' => [
                'semantic_input_enrichment',
            ],
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question about a documented feature or function.',
                    'required' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for task-authored wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
                'search_queries' => [
                    'type' => 'array',
                    'description' => 'Optional list of up to 2 alternative search phrases for the same user question.',
                    'required' => false,
                ],
                'doc_path' => [
                    'type' => 'string',
                    'description' => 'Optional relative path of a specific documentation file to read directly '
                        . '(e.g. "booking_rules/overview.md"). '
                        . 'When provided the keyword search is skipped and only one line window is returned. '
                        . 'Use this when the user refers to a document that is currently visible or previously mentioned.',
                    'required' => false,
                ],
                'line_start' => [
                    'type' => 'integer',
                    'description' => 'Optional 1-based start line for docs reading (default 1). '
                        . 'Use next_line_start from prior results to continue reading in steps.',
                    'required' => false,
                ],
                'line_count' => [
                    'type' => 'integer',
                    'description' => 'Optional number of lines to read per step (default 80; clamped to 20..200).',
                    'required' => false,
                ],
                'topic_hint' => [
                    'type' => 'string',
                    'description' => 'Optional planner hint for preferred docs topic/folder.',
                    'required' => false,
                ],
                'doc_path_candidates' => [
                    'type' => 'array',
                    'description' => 'Optional planner-proposed ranked candidate paths (max 3) for direct read.',
                    'required' => false,
                ],
                'retrieval_goal' => [
                    'type' => 'string',
                    'description' => 'Optional retrieval goal, e.g. configure_howto, concept_explanation, troubleshooting.',
                    'required' => false,
                ],
                'planner_confidence' => [
                    'type' => 'number',
                    'description' => 'Optional planner confidence in [0..1] for structured retrieval hints.',
                    'required' => false,
                ],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $question = trim((string)($input['question'] ?? ''));
        $lang = $this->get_output_language($input);

        if ($question === '') {
            $errors[] = $this->localized_string('ai_docs_explain_required_question', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $docpath = trim((string)($input['doc_path'] ?? ''));
        $linestart = $this->normalize_line_start((int)($input['line_start'] ?? 1));
        $linecount = $this->normalize_line_count((int)($input['line_count'] ?? self::DEFAULT_LINE_COUNT));
        $isfirststep = $linestart <= 1;
        $effectivelinecount = $isfirststep ? min($linecount, self::FIRST_STEP_LINE_COUNT) : $linecount;

        $plannerconfidence = max(0.0, min(1.0, (float)($input['planner_confidence'] ?? 0.0)));
        $docpathcandidates = array_values(array_filter(array_slice(array_map(
            'trim',
            (array)($input['doc_path_candidates'] ?? [])
        ), 0, 3)));
        $searchqueries = array_values(array_filter(array_slice(array_map(
            'trim',
            (array)($input['search_queries'] ?? [])
        ), 0, 2)));
        $topichint = trim((string)($input['topic_hint'] ?? ''));
        $retrievalgoal = trim((string)($input['retrieval_goal'] ?? ''));

        $service = $this->create_docs_lookup_service();
        $directdoc = null;
        $topicidused = '';

        // Deterministic path mode: direct doc_path has highest priority.
        // Ignore ungrounded root doc placeholders so semantic retrieval can choose better docs.
        if ($this->should_use_direct_doc_path($docpath, $docpathcandidates, $linestart)) {
            $directdoc = $service->read_doc_by_path($docpath, $linestart, $effectivelinecount);
            if ($directdoc !== null) {
                return $this->build_direct_doc_result(
                    $directdoc,
                    $question,
                    $service,
                    $cmid,
                    $outputlang,
                    [
                        'Mode: direct_path_read',
                        'Path: ' . $docpath,
                    ]
                );
            }
        }

        // Deterministic planner mode: use the first valid candidate from docs_index planning.
        if (!empty($docpathcandidates)) {
            foreach ($docpathcandidates as $candidatepath) {
                $directdoc = $service->read_doc_by_path($candidatepath, $linestart, $effectivelinecount);
                if ($directdoc === null) {
                    continue;
                }

                return $this->build_direct_doc_result(
                    $directdoc,
                    $question,
                    $service,
                    $cmid,
                    $outputlang,
                    [
                        'Mode: planner_direct_candidate',
                        'Planner confidence: ' . number_format($plannerconfidence, 3, '.', ''),
                        'Planner candidate: ' . $candidatepath,
                    ]
                );
            }
        }

        $allqueries = array_values(array_filter(array_unique(array_merge([$question], $searchqueries))));
        $docs = [];

        // Planner-guided topic retrieval using docs index topic IDs.
        if ($topichint !== '') {
            $resolvedtopic = $this->resolve_topic_hint_to_topic_id($service, $topichint);
            if ($resolvedtopic !== '') {
                $topicidused = $resolvedtopic;
                $docs = $service->search_in_topic(
                    $resolvedtopic,
                    $question,
                    $searchqueries,
                    self::DOC_CANDIDATE_POOL_LIMIT
                );
            }
        }

        // If planner topic hint is missing or weak, detect the best topic from docs index.
        if (empty($docs)) {
            $topicdecision = $service->detect_best_topic($question, $searchqueries);
            $topictouse = trim((string)($topicdecision['topic_id'] ?? ''));
            $topicscore = (int)($topicdecision['score'] ?? 0);
            $topicconfidence = (float)($topicdecision['confidence'] ?? 0.0);

            if (
                $topictouse !== ''
                && $topicscore >= self::TOPIC_MIN_SCORE
                && ($plannerconfidence >= self::TOPIC_CONFIDENCE_THRESHOLD || $topicconfidence >= self::TOPIC_CONFIDENCE_THRESHOLD)
            ) {
                $topicidused = $topictouse;
                $docs = $service->search_in_topic(
                    $topictouse,
                    $question,
                    $searchqueries,
                    self::DOC_CANDIDATE_POOL_LIMIT
                );
            }
        }

        if (empty($docs)) {
            $docs = $service->search_multi($allqueries, self::DOC_CANDIDATE_POOL_LIMIT);
        }

        $docs = $this->promote_topic_overview_for_broad_query($docs, $service, $topicidused);
        $docs = $this->apply_configure_intent_boost($docs, $question, $retrievalgoal);
        $docs = $this->prioritize_docs($docs);

        if (!empty($docs)) {
            $bestpath = trim((string)($docs[0]['path'] ?? ''));
            if ($bestpath !== '') {
                $bestdoc = $service->read_doc_by_path($bestpath, $linestart, $effectivelinecount);
                if ($bestdoc !== null) {
                    return $this->build_direct_doc_result(
                        $bestdoc,
                        $question,
                        $service,
                        $cmid,
                        $outputlang,
                        [
                            'Mode: docs_index_fallback',
                            'Selected path: ' . $bestpath,
                        ]
                    );
                }
            }
        }

        $rootdoc = $service->read_root_doc($linestart, $effectivelinecount);
        if ($rootdoc !== null) {
            return $this->build_direct_doc_result(
                $rootdoc,
                $question,
                $service,
                $cmid,
                $outputlang,
                [
                    'Mode: root_doc_fallback',
                    'Reason: no_ranked_doc_selected',
                ]
            );
        }

        $nomatch = $this->localized_string('ai_docs_explain_no_match', null, $outputlang);
        return [
            'status' => 'error',
            'detail' => $nomatch,
            'usermessage' => $nomatch,
            'resultid' => null,
            'docs' => [],
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Mode: docs_index_fallback',
                    'Reason: no_docs_match_and_no_root_doc',
                    'Provided doc_path: ' . ($docpath !== '' ? $docpath : '[empty]'),
                    'Candidate count: ' . count($docpathcandidates),
                ]
            ),
        ];
    }

    /**
     * Map planner topic hint to a concrete topic id from docs index.
     *
     * @param docs_lookup_service $service
     * @param string $topichint
     * @return string
     */
    private function resolve_topic_hint_to_topic_id(docs_lookup_service $service, string $topichint): string {
        $hint = strtolower(trim($topichint));
        if ($hint === '') {
            return '';
        }

        foreach ($service->get_master_toc_index() as $topic) {
            $topicid = strtolower(trim((string)($topic['topic_id'] ?? '')));
            $title = strtolower(trim((string)($topic['title'] ?? '')));
            if ($topicid === '' && $title === '') {
                continue;
            }
            if ($hint === $topicid || $hint === $title) {
                return (string)($topic['topic_id'] ?? '');
            }
            if (str_contains($topicid, $hint) || str_contains($title, $hint) || str_contains($hint, $topicid)) {
                return (string)($topic['topic_id'] ?? '');
            }
        }

        return '';
    }

    /**
     * Boost precise configuration docs for how-to style questions.
     *
     * @param array $docs
     * @param string $question
     * @param string $retrievalgoal
     * @return array
     */
    private function apply_configure_intent_boost(array $docs, string $question, string $retrievalgoal = ''): array {
        $goal = strtolower(trim($retrievalgoal));
        $isconfigure = $goal === 'configure_howto' || $this->looks_like_configuration_question($question);
        if (!$isconfigure) {
            return $docs;
        }

        foreach ($docs as &$doc) {
            $score = (int)($doc['score'] ?? 0);
            $path = strtolower((string)($doc['path'] ?? ''));
            $content = strtolower((string)($doc['content'] ?? ''));

            if (str_contains($path, 'booking_conditions/booking_time.md')) {
                $score += 220;
            }
            if (str_contains($path, 'booking-option/04-availability.md')) {
                $score += 180;
            }
            if (str_contains($content, 'bookable from') || str_contains($content, 'bookingopeningtime')) {
                $score += 120;
            }
            if (str_contains($content, 'how to configure') || str_contains($content, 'step 1')) {
                $score += 60;
            }
            if (preg_match('/\/readme\.md$/i', $path)) {
                $score -= 60;
            }

            $doc['score'] = $score;
        }
        unset($doc);

        return $docs;
    }

    /**
     * Detect configuration-style questions.
     *
     * @param string $question
     * @return bool
     */
    private function looks_like_configuration_question(string $question): bool {
        $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $question)));
        if ($normalized === '') {
            return false;
        }

        return (bool)preg_match(
            '/(wie\s+kann\s+ich|how\s+can\s+i|how\s+to|einstellen|konfigur|setup|set\s+up'
            . '|bookable\s+from|bookingopeningtime|ab\s+einem\s+zeitpunkt)/u',
            $normalized
        );
    }

    /**
     * Decide whether a provided doc_path should be used as deterministic direct path.
     *
     * Prevents weak placeholders like root README.md from bypassing semantic routing
     * when the planner did not provide grounded candidates.
     *
     * @param string $docpath
     * @param array $docpathcandidates
     * @param int $linestart
     * @return bool
     */
    private function should_use_direct_doc_path(string $docpath, array $docpathcandidates, int $linestart): bool {
        $path = trim($docpath);
        if ($path === '') {
            return false;
        }

        // Allow explicit continuation requests on already selected docs.
        if ($linestart > 1) {
            return true;
        }

        $normalizedpath = strtolower($path);
        $isrootreadme = $normalizedpath === 'readme.md' || $normalizedpath === '/readme.md';
        if ($isrootreadme && empty($docpathcandidates)) {
            return false;
        }

        return true;
    }

    /**
     * Prefer a topic overview when retrieval only knows the topic, not a specific doc basename.
     *
     * @param array $docs
     * @param docs_lookup_service $service
     * @param string $topicid
     * @return array
     */
    private function promote_topic_overview_for_broad_query(
        array $docs,
        docs_lookup_service $service,
        string $topicid
    ): array {
        $topicid = trim($topicid);
        if (empty($docs)) {
            return $docs;
        }

        if ($topicid === '') {
            $topicid = $this->extract_topic_id_from_doc_path((string)($docs[0]['path'] ?? ''));
        }
        if ($topicid === '') {
            return $docs;
        }

        foreach ($docs as $doc) {
            if (!empty($doc['exactbasenamehit'])) {
                return $docs;
            }
        }

        $overviewpath = $topicid === 'overview' ? $service->get_root_doc_path() : $topicid . '/README.md';
        $overview = $service->read_doc_by_path($overviewpath, 1, self::FIRST_STEP_LINE_COUNT);
        if ($overview === null) {
            return $docs;
        }

        $topscores = array_map(static fn(array $doc): int => (int)($doc['score'] ?? 0), $docs);
        $overview['score'] = (empty($topscores) ? 0 : max($topscores)) + self::ROOT_DOC_NAV_SCORE;
        $overview['exactbasenamehit'] = false;

        return $this->prepend_doc_candidate($docs, $overview);
    }

    /**
     * Extract the docs topic id from a relative documentation path.
     *
     * @param string $path
     * @return string
     */
    private function extract_topic_id_from_doc_path(string $path): string {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || strpos($path, '/') === false) {
            return '';
        }

        return trim((string)(explode('/', $path)[0] ?? ''));
    }

    /**
     * Apply generic score-first ordering before taking final top docs.
     *
     * @param array $docs
     * @return array
     */
    private function prioritize_docs(array $docs): array {
        usort($docs, static function (array $left, array $right): int {
            $leftscore = (int)($left['score'] ?? 0);
            $rightscore = (int)($right['score'] ?? 0);

            if ($rightscore !== $leftscore) {
                return $rightscore <=> $leftscore;
            }

            return strcmp((string)($left['path'] ?? ''), (string)($right['path'] ?? ''));
        });

        return $docs;
    }

    /**
     * Create the docs lookup service.
     *
     * @return docs_lookup_service
     */
    protected function create_docs_lookup_service(): docs_lookup_service {
        $docsroot = trim((string)(get_config('bookingextension_agent', 'aidocsroot') ?? ''));
        $rootdocpath = trim((string)(get_config('bookingextension_agent', 'aidocsentry') ?? 'README.md'));

        return new docs_lookup_service($docsroot !== '' ? $docsroot : null, $rootdocpath !== '' ? $rootdocpath : null);
    }

    /**
     * Prepend a preferred doc candidate and deduplicate by path.
     *
     * @param array $docs
     * @param array $candidate
     * @return array
     */
    private function prepend_doc_candidate(array $docs, array $candidate): array {
        $path = (string)($candidate['path'] ?? '');
        if ($path === '') {
            return $docs;
        }

        $filtered = array_values(array_filter($docs, static function (array $doc) use ($path): bool {
            return (string)($doc['path'] ?? '') !== $path;
        }));

        array_unshift($filtered, $candidate);
        return $filtered;
    }

    /**
     * Build the task result for a directly-addressed doc (doc_path fast path).
     *
     * Returns one line window so the LLM can decide whether to answer now,
     * continue with the next chunk, or follow a linked doc.
     *
     * @param  array             $doc         Doc array from read_doc_by_path().
     * @param  string            $question    Original user question.
     * @param  docs_lookup_service $service   Service instance (used for URL building).
     * @return array
     */
    private function build_direct_doc_result(
        array $doc,
        string $question,
        docs_lookup_service $service,
        int $cmid,
        string $outputlang,
        array $debuglines = []
    ): array {
        $path = (string)($doc['path'] ?? '');
        $url = $this->build_doc_url($path);

        $usermessage = $service->build_summary($doc, $cmid, $outputlang, $question);
        if ($url !== '') {
            $maxsummarylength = max(120, 500 - \core_text::strlen("\n" . $url));
            if (\core_text::strlen($usermessage) > $maxsummarylength) {
                $usermessage = rtrim(\core_text::substr($usermessage, 0, $maxsummarylength - 3)) . '...';
            }
            $usermessage .= "\n" . $url;
        }

        $structureddoc = $this->build_structured_doc_payload($doc, 0);

        return [
            'status'       => 'executed',
            'detail'       => $usermessage,
            'usermessage'  => $usermessage,
            'observation_full' => $this->build_full_observation_from_docs([$structureddoc]),
            'resultid'     => null,
            'docs'         => [$structureddoc],
            'selected_doc_path' => $path,
            'retrieval_mode' => 'direct_path',
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                ['question' => $question, 'doc_path' => $path],
                array_merge([
                    'Mode: direct_path_read',
                    'Path: ' . $path,
                    'Line window: start=' . (int)($structureddoc['line_start'] ?? 1)
                        . ' count=' . max(0, (int)($structureddoc['line_end'] ?? 0)
                            - (int)($structureddoc['line_start'] ?? 1) + 1),
                ], $debuglines)
            ),
        ];
    }

    /**
     * Build a full observation block from structured docs payload.
     *
     * This intentionally keeps the full chunk text so orchestrator observation
     * prompts can include complete documentation context via observation_full.
     *
     * @param array<int,array<string,mixed>> $docs
     * @return string
     */
    private function build_full_observation_from_docs(array $docs): string {
        $blocks = [];

        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $title = trim((string)($doc['title'] ?? ''));
            $body = trim((string)($doc['chunk_content'] ?? $doc['full_content'] ?? $doc['excerpt'] ?? ''));
            $url = trim((string)($doc['url'] ?? ''));
            $hasmore = !empty($doc['has_more']);
            $nextline = (int)($doc['next_line_start'] ?? 0);

            if ($body === '' && $url === '') {
                continue;
            }

            $block = '';
            if ($title !== '') {
                $block .= "## {$title}\n";
            }
            if ($body !== '') {
                $block .= $body;
            }
            if ($hasmore && $nextline > 0) {
                $block .= ($block !== '' ? "\n\n" : '') . 'Continue from line ' . $nextline . ' if needed.';
            }
            if ($url !== '') {
                $block .= ($block !== '' ? "\n\n" : '') . 'Link: ' . $url;
            }

            $block = trim($block);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return trim(implode("\n\n", $blocks));
    }

    /**
     * Normalize requested 1-based docs start line.
     *
     * @param int $linestart
     * @return int
     */
    private function normalize_line_start(int $linestart): int {
        return max(1, $linestart);
    }

    /**
     * Normalize requested docs line count.
     *
     * @param int $linecount
     * @return int
     */
    private function normalize_line_count(int $linecount): int {
        return max(20, min(200, $linecount > 0 ? $linecount : self::DEFAULT_LINE_COUNT));
    }

    /**
     * Build the docs payload consumed by the generic observation summarizer.
     *
     * @param array $doc
     * @param int $score
     * @return array
     */
    private function build_structured_doc_payload(array $doc, int $score): array {
        $path = (string)($doc['path'] ?? '');
        $chunklinks = array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            (array)($doc['chunk_links'] ?? [])
        )));

        return [
            'path' => $path,
            'url' => $this->build_doc_url($path),
            'title' => (string)($doc['title'] ?? ''),
            'excerpt' => (string)($doc['excerpt'] ?? ''),
            'chunk_content' => (string)($doc['chunk_content'] ?? ''),
            'line_start' => (int)($doc['line_start'] ?? 1),
            'line_end' => (int)($doc['line_end'] ?? 1),
            'total_lines' => (int)($doc['total_lines'] ?? 0),
            'has_more' => !empty($doc['has_more']),
            'next_line_start' => isset($doc['next_line_start']) ? (int)$doc['next_line_start'] : null,
            'chunk_links' => $chunklinks,
            'score' => $score,
        ];
    }

    /**
     * Build an absolute documentation URL on the current Moodle instance.
     *
     * @param string $path Relative docs path.
     * @return string
     */
    private function build_doc_url(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $encodedpath = str_replace('%2F', '/', rawurlencode($path));
        return (new moodle_url('/mod/booking/docs/' . $encodedpath))->out(false);
    }
}
