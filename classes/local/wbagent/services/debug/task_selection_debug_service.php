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

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\debug;

use bookingextension_agent\local\wbagent\embeddings_action_config_resolver;
use bookingextension_agent\local\wbagent\embeddings_csv_repository;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\task_executability_evaluator;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\task_registry_factory;
use context_module;
use core\di;
use core_ai\manager as ai_manager;

/**
 * Selection-debug helper: simulate task selection and inspect collisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_selection_debug_service {
    /** Embeddings action class for wunderbyte provider. */
    private const WB_ACTION_GENERATE_EMBEDDINGS = '\\aiprovider_wunderbyte\\aiactions\\generate_embeddings';

    /** @var task_registry */
    private task_registry $registry;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->registry = task_registry_factory::get_default();
    }

    /**
     * Return selection simulation for a user input.
     *
     * @param string $input
     * @param int $userid
     * @param int $cmid
     * @param int $topk
     * @param bool $includeunavailable
     * @return array<string,mixed>
     */
    public function simulate_selection(
        string $input,
        int $userid,
        int $cmid,
        int $topk = 10,
        bool $includeunavailable = true
    ): array {
        $topk = max(1, min(50, $topk));
        $contextid = $this->resolve_contextid_from_cmid($cmid);
        $contracts = $this->get_prompt_contracts_for_context($userid, $contextid, $includeunavailable);

        $lexical = $this->build_lexical_ranking($input, $contracts, $topk);
        $embedding = $this->build_embedding_ranking($input, $userid, $cmid, $topk);

        $bytask = [];
        foreach ($lexical as $row) {
            $task = (string)$row['task'];
            $bytask[$task] = [
                'task' => $task,
                'lexical_score' => (float)($row['lexical_score'] ?? 0.0),
                'embedding_score' => null,
                'combined_score' => (float)($row['lexical_score'] ?? 0.0),
                'match_terms' => (array)($row['match_terms'] ?? []),
                'readonly' => !empty($row['readonly']),
                'intent' => (string)($row['intent'] ?? ''),
                'source' => 'lexical',
            ];
        }

        foreach ($embedding as $row) {
            $task = (string)($row['task'] ?? '');
            if ($task === '') {
                continue;
            }

            $score = (float)($row['score'] ?? 0.0);
            if (!isset($bytask[$task])) {
                $bytask[$task] = [
                    'task' => $task,
                    'lexical_score' => 0.0,
                    'embedding_score' => $score,
                    'combined_score' => $score,
                    'match_terms' => [],
                    'readonly' => ((string)($row['readonly'] ?? '0') === '1'),
                    'intent' => (string)($row['intent'] ?? ''),
                    'source' => 'embedding',
                ];
            } else {
                $bytask[$task]['embedding_score'] = $score;
            }
        }

        foreach ($bytask as $task => $row) {
            $lex = (float)($row['lexical_score'] ?? 0.0);
            $emb = $row['embedding_score'];
            if ($emb === null) {
                $bytask[$task]['combined_score'] = $lex;
                continue;
            }

            $bytask[$task]['combined_score'] = ((float)$emb * 0.75) + ($lex * 0.25);
            $bytask[$task]['source'] = 'hybrid';
        }

        $candidates = array_values($bytask);
        usort($candidates, static function (array $a, array $b): int {
            return ((float)$b['combined_score']) <=> ((float)$a['combined_score']);
        });
        $candidates = array_slice($candidates, 0, $topk);

        return [
            'input' => $input,
            'contextid' => $contextid,
            'cmid' => $cmid,
            'selected_task' => (string)($candidates[0]['task'] ?? ''),
            'candidates' => $candidates,
            'contracts_count' => count($contracts),
            'embedding_enabled' => !empty($embedding),
        ];
    }

    /**
     * Analyze pairwise task collisions using embedding vectors.
     *
     * @param int $limit
     * @return array<string,mixed>
     */
    public function analyze_collisions(int $limit = 50): array {
        $limit = max(1, min(500, $limit));
        $repo = new embeddings_csv_repository();
        $rows = $repo->read_rows();
        $pairs = [];

        for ($i = 0; $i < count($rows); $i++) {
            $a = $rows[$i];
            $av = json_decode((string)($a['embedding_json'] ?? '[]'), true);
            if (!is_array($av) || empty($av)) {
                continue;
            }

            for ($j = $i + 1; $j < count($rows); $j++) {
                $b = $rows[$j];
                $bv = json_decode((string)($b['embedding_json'] ?? '[]'), true);
                if (!is_array($bv) || empty($bv)) {
                    continue;
                }

                $score = $this->cosine_similarity($av, $bv);
                $pairs[] = [
                    'task_a' => (string)($a['task'] ?? ''),
                    'task_b' => (string)($b['task'] ?? ''),
                    'similarity' => $score,
                    'risk' => $this->classify_collision_risk($score),
                ];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            return ((float)$b['similarity']) <=> ((float)$a['similarity']);
        });

        return [
            'has_embeddings' => !empty($rows),
            'task_count' => count($rows),
            'pairs' => array_slice($pairs, 0, $limit),
        ];
    }

    /**
     * Get active prompt contracts for context.
     *
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return array<int,array<string,mixed>>
     */
    private function get_prompt_contracts_for_context(int $userid, int $contextid, bool $includeunavailable): array {
        if ($contextid <= 0) {
            return $this->registry->get_all_prompt_contracts();
        }

        $evaluator = new task_executability_evaluator($this->registry, new authorization_service());
        return $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid, $includeunavailable);
    }

    /**
     * Build lexical ranking over prompt contracts.
     *
     * @param string $input
     * @param array<int,array<string,mixed>> $contracts
     * @param int $topk
     * @return array<int,array<string,mixed>>
     */
    private function build_lexical_ranking(string $input, array $contracts, int $topk): array {
        $inputtokens = $this->tokenize($input);
        $result = [];

        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $task = trim((string)($contract['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $searchcorpus = [];
            $searchcorpus[] = (string)($contract['task'] ?? '');
            $searchcorpus[] = (string)($contract['description'] ?? '');

            foreach ((array)($contract['minimal_input'] ?? []) as $entry) {
                $searchcorpus[] = (string)$entry;
            }
            foreach ((array)($contract['example_input'] ?? []) as $entry) {
                $searchcorpus[] = (string)$entry;
            }
            foreach ((array)($contract['message_triggers'] ?? []) as $trigger) {
                if (!is_array($trigger)) {
                    continue;
                }
                $searchcorpus[] = (string)($trigger['id'] ?? '');
                $searchcorpus[] = (string)($trigger['description'] ?? '');
                foreach ((array)($trigger['examples'] ?? []) as $example) {
                    $searchcorpus[] = (string)$example;
                }
            }

            $corpus = implode(' ', $searchcorpus);
            $corpustokens = $this->tokenize($corpus);
            if (empty($corpustokens)) {
                continue;
            }

            $intersect = array_values(array_intersect($inputtokens, $corpustokens));
            $score = 0.0;
            if (!empty($inputtokens)) {
                $score += (count($intersect) / max(1, count(array_unique($inputtokens)))) * 0.7;
            }

            $taskparts = $this->tokenize(str_replace(['.', '_'], ' ', $task));
            $taskhits = array_values(array_intersect($inputtokens, $taskparts));
            if (!empty($taskhits)) {
                $score += 0.3;
            }

            if ($score <= 0.0) {
                continue;
            }

            $result[] = [
                'task' => $task,
                'lexical_score' => min(1.0, $score),
                'match_terms' => array_slice(array_values(array_unique($intersect)), 0, 8),
                'readonly' => !empty($contract['readonly']),
                'intent' => (string)($contract['intent'] ?? ''),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return ((float)$b['lexical_score']) <=> ((float)$a['lexical_score']);
        });

        return array_slice($result, 0, $topk);
    }

    /**
     * Build embedding ranking from task catalog vectors.
     *
     * @param string $input
     * @param int $userid
     * @param int $cmid
     * @param int $topk
     * @return array<int,array<string,string>>
     */
    private function build_embedding_ranking(string $input, int $userid, int $cmid, int $topk): array {
        if ($cmid <= 0 || trim($input) === '') {
            return [];
        }

        if (!class_exists(self::WB_ACTION_GENERATE_EMBEDDINGS)) {
            return [];
        }

        $contextid = $this->resolve_contextid_from_cmid($cmid);
        if ($contextid <= 0) {
            return [];
        }

        $readiness = new embeddings_readiness_service();
        if (!$readiness->is_wunderbyte_embeddings_available()) {
            return [];
        }

        $resolver = new embeddings_action_config_resolver();
        $settings = $resolver->resolve();
        $model = (string)($settings['model'] ?? 'text-embedding-3-small');
        $dimensions = (int)($settings['dimensions'] ?? 1536);

        $status = $readiness->get_catalog_status($this->registry, $model, $dimensions);
        if (empty($status['ready']) || empty($status['rows']) || !is_array($status['rows'])) {
            return [];
        }

        $queryembedding = $this->generate_query_embedding($contextid, $userid, $input, $dimensions);
        if (empty($queryembedding)) {
            return [];
        }

        $retrieval = new embeddings_retrieval_service();
        return $retrieval->search_top_k($queryembedding, (array)$status['rows'], $topk);
    }

    /**
     * Generate query embedding using configured provider action.
     *
     * @param int $contextid
     * @param int $userid
     * @param string $input
     * @param int $dimensions
     * @return array<int,float|int>
     */
    private function generate_query_embedding(int $contextid, int $userid, string $input, int $dimensions): array {
        try {
            $manager = di::get(ai_manager::class);
            $actionclass = self::WB_ACTION_GENERATE_EMBEDDINGS;
            $action = new $actionclass(
                contextid: $contextid,
                userid: $userid,
                inputtext: $input,
                dimensions: $dimensions,
            );

            $response = $manager->process_action($action);
            $data = (array)$response->get_response_data();
            $embedding = (array)($data['embedding'] ?? []);
            if (empty($embedding)) {
                return [];
            }

            return $embedding;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve module context id from course module id.
     *
     * @param int $cmid
     * @return int
     */
    private function resolve_contextid_from_cmid(int $cmid): int {
        if ($cmid <= 0) {
            return 0;
        }

        try {
            return (int)context_module::instance($cmid)->id;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Normalize and tokenize text.
     *
     * @param string $text
     * @return array<int,string>
     */
    private function tokenize(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9_\s\.\-]/ui', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', $text) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Cosine similarity helper.
     *
     * @param array<int,float|int> $a
     * @param array<int,float|int> $b
     * @return float
     */
    private function cosine_similarity(array $a, array $b): float {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $norma = 0.0;
        $normb = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $av = (float)$a[$i];
            $bv = (float)$b[$i];
            $dot += $av * $bv;
            $norma += $av * $av;
            $normb += $bv * $bv;
        }

        if ($norma <= 0.0 || $normb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($norma) * sqrt($normb));
    }

    /**
     * Classify collision risk by similarity threshold.
     *
     * @param float $similarity
     * @return string
     */
    private function classify_collision_risk(float $similarity): string {
        if ($similarity >= 0.90) {
            return 'high';
        }
        if ($similarity >= 0.82) {
            return 'warn';
        }
        return 'ok';
    }
}
