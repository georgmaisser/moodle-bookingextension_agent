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

namespace bookingextension_agent\local\wizard\services\debug;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\embeddings_csv_repository;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\embeddings\vector_math;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_module;
use core\di;
use core_ai\manager as ai_manager;

/**
 * Selection-debug helper: simulate skill selection and inspect collisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_selection_debug_service {
    /** Embeddings action class for wunderbyte provider. */
    private const WB_ACTION_GENERATE_EMBEDDINGS = '\\aiprovider_wunderbyte\\aiactions\\generate_embeddings';

    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->registry = skill_registry_factory::get_default();
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

        $byskill = [];
        foreach ($lexical as $row) {
            $skill = (string)($row['skill'] ?? '');
            $byskill[$skill] = [
                'skill' => $skill,
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
            $skill = (string)($row['skill'] ?? '');
            if ($skill === '') {
                continue;
            }

            $score = (float)($row['score'] ?? 0.0);
            // Multi-vector: which anchor (description/utterance + the exact phrase) won this skill.
            $matchedkind = (string)($row['matched_anchor_kind'] ?? ($row['anchor_kind'] ?? ''));
            $matchedtext = (string)($row['matched_anchor_text'] ?? ($row['anchor_text'] ?? ''));
            if (!isset($byskill[$skill])) {
                $byskill[$skill] = [
                    'skill' => $skill,
                    'lexical_score' => 0.0,
                    'embedding_score' => $score,
                    'combined_score' => $score,
                    'match_terms' => [],
                    'readonly' => ((string)($row['readonly'] ?? '0') === '1'),
                    'intent' => (string)($row['intent'] ?? ''),
                    'source' => 'embedding',
                    'matched_anchor_kind' => $matchedkind,
                    'matched_anchor_text' => $matchedtext,
                ];
            } else {
                $byskill[$skill]['embedding_score'] = $score;
                $byskill[$skill]['matched_anchor_kind'] = $matchedkind;
                $byskill[$skill]['matched_anchor_text'] = $matchedtext;
            }
        }

        foreach ($byskill as $skill => $row) {
            $lex = (float)($row['lexical_score'] ?? 0.0);
            $emb = $row['embedding_score'];
            if ($emb === null) {
                $byskill[$skill]['combined_score'] = $lex;
                continue;
            }

            $byskill[$skill]['combined_score'] = ((float)$emb * 0.75) + ($lex * 0.25);
            $byskill[$skill]['source'] = 'hybrid';
        }

        $candidates = array_values($byskill);
        usort($candidates, static function (array $a, array $b): int {
            return ((float)$b['combined_score']) <=> ((float)$a['combined_score']);
        });
        $candidates = array_slice($candidates, 0, $topk);

        return [
            'input' => $input,
            'contextid' => $contextid,
            'cmid' => $cmid,
            'selected_skill' => (string)($candidates[0]['skill'] ?? ''),
            'candidates' => $candidates,
            'contracts_count' => count($contracts),
            'embedding_enabled' => !empty($embedding),
        ];
    }

    /**
     * Analyze pairwise skill collisions using embedding vectors.
     *
     * @param int $limit
     * @return array<string,mixed>
     */
    public function analyze_collisions(int $limit = 50): array {
        $limit = max(1, min(500, $limit));
        $repo = embeddings_csv_repository::for_active_variant();
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

                $score = vector_math::cosine_similarity($av, $bv);
                $pairs[] = [
                    'skill_a' => (string)($a['skill'] ?? ''),
                    'skill_b' => (string)($b['skill'] ?? ''),
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
            'skill_count' => count($rows),
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

        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
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

            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $searchcorpus = [];
            $searchcorpus[] = (string)($contract['skill'] ?? '');
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

            $skillparts = $this->tokenize(str_replace(['.', '_'], ' ', $skill));
            $skillhits = array_values(array_intersect($inputtokens, $skillparts));
            if (!empty($skillhits)) {
                $score += 0.3;
            }

            if ($score <= 0.0) {
                continue;
            }

            $result[] = [
                'skill' => $skill,
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
     * Build embedding ranking from skill catalog vectors.
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
        // Multi-vector: top-k distinct skills (rows carry matched_anchor_kind/text + score for debug).
        return $retrieval->search_top_k_skills($queryembedding, (array)$status['rows'], $topk);
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
