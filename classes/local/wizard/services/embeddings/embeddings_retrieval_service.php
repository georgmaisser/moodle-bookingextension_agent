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
 * Retrieval service for skill-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Performs vector similarity search and builds planner-ready catalog subsets.
 */
class embeddings_retrieval_service {
    /**
     * Search top-k skill rows by cosine similarity.
     *
     * @param array<int,float|int> $queryvector
     * @param array<int,array<string,string>> $catalogrows
     * @param int $k
     * @return array<int,array<string,string>>
     */
    public function search_top_k(array $queryvector, array $catalogrows, int $k = 5): array {
        if ($k < 1 || empty($queryvector) || empty($catalogrows)) {
            return [];
        }

        $scored = [];
        foreach ($catalogrows as $row) {
            $embedding = json_decode((string)($row['embedding_json'] ?? '[]'), true);
            if (!is_array($embedding) || empty($embedding)) {
                continue;
            }

            $score = vector_math::cosine_similarity($queryvector, $embedding);
            $scored[] = [
                'score' => $score,
                'row' => $row,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $top = array_slice($scored, 0, $k);
        return array_values(array_map(static function (array $entry): array {
            $row = (array)$entry['row'];
            $row['score'] = (string)($entry['score'] ?? 0.0);
            return $row;
        }, $top));
    }

    /**
     * Stream top-k by cosine similarity over an iterable of rows, holding only k candidates in memory.
     *
     * Identical ranking/output to search_top_k() (rows tagged with a 'score' string, descending), but
     * the catalog is consumed one row at a time and the heavy embedding vector is dropped once scored,
     * so peak memory is O(k) instead of O(catalog). Use for large stores (e.g. the docs index).
     *
     * @param array<int,float|int> $queryvector
     * @param iterable<array<string,string>> $rows
     * @param int $k
     * @return array<int,array<string,string>>
     */
    public function search_top_k_streaming(array $queryvector, iterable $rows, int $k = 5): array {
        if ($k < 1 || empty($queryvector)) {
            return [];
        }

        // Min-heap on score: the lowest-scoring kept candidate sits on top, ready to be evicted.
        $heap = new class extends \SplHeap {
            protected function compare($value1, $value2): int {
                return $value2['score'] <=> $value1['score'];
            }
        };

        foreach ($rows as $row) {
            $embedding = json_decode((string)($row['embedding_json'] ?? '[]'), true);
            if (!is_array($embedding) || empty($embedding)) {
                continue;
            }
            $score = vector_math::cosine_similarity($queryvector, $embedding);

            // Drop the vector immediately; only metadata + score are needed downstream.
            unset($row['embedding_json'], $row['_vec']);

            if ($heap->count() < $k) {
                $heap->insert(['score' => $score, 'row' => $row]);
            } else if ($score > $heap->top()['score']) {
                $heap->extract();
                $heap->insert(['score' => $score, 'row' => $row]);
            }
        }

        // The heap yields lowest-first; collect then reverse to descending score.
        $ascending = [];
        foreach ($heap as $entry) {
            $ascending[] = $entry;
        }
        $out = [];
        foreach (array_reverse($ascending) as $entry) {
            $row = (array)$entry['row'];
            $row['score'] = (string)($entry['score'] ?? 0.0);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Build planner-skill contracts from retrieved CSV rows.
     *
     * @param array<int,array<string,string>> $toprows
     * @param array<int,array<string,mixed>> $livecontracts
     * @return array<int,array<string,mixed>>
     */
    public function build_planner_catalog_subset(array $toprows, array $livecontracts = []): array {
        $subset = [];
        $contractsbyskill = $this->build_live_contract_lookup($livecontracts);
        $skillregistry = null;
        try {
            $skillregistry = skill_registry_factory::get_default();
        } catch (\Throwable $e) {
            $skillregistry = null;
        }

        foreach ($toprows as $row) {
            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            if (isset($contractsbyskill[$skill])) {
                $contract = $contractsbyskill[$skill];
                if (empty($contract['properties']) && $skillregistry !== null) {
                    $liveskill = $skillregistry->get_skill($skill);
                    if ($liveskill !== null) {
                        $schema = (array)$liveskill->get_schema();
                        $contract['properties'] = $this->compact_properties_for_planner((array)($schema['properties'] ?? []));
                    }
                }
                $subset[] = $contract;
                continue;
            }

            $compactproperties = [];
            if ($skillregistry !== null) {
                $liveskill = $skillregistry->get_skill($skill);
                if ($liveskill !== null) {
                    $schema = (array)$liveskill->get_schema();
                    $compactproperties = $this->compact_properties_for_planner((array)($schema['properties'] ?? []));
                }
            }

            $subset[] = [
                'skill' => $skill,
                'intent' => (string)($row['intent'] ?? ''),
                'readonly' => ((string)($row['readonly'] ?? '0') === '1'),
                'description' => (string)($row['description'] ?? ''),
                'minimal_input' => $this->decode_json_array($row['minimal_input_json'] ?? '[]'),
                'example_input' => $this->decode_json_array($row['example_input_json'] ?? '[]'),
                'message_triggers' => $this->decode_json_array($row['message_triggers_json'] ?? '[]'),
                'properties' => $compactproperties,
            ];
        }

        return $subset;
    }

    /**
     * Build a skill-name keyed lookup of live prompt contracts.
     *
     * @param array<int,array<string,mixed>> $livecontracts
     * @return array<string,array<string,mixed>>
     */
    private function build_live_contract_lookup(array $livecontracts): array {
        $contractsbyskill = [];
        $skillregistry = null;
        try {
            $skillregistry = skill_registry_factory::get_default();
        } catch (\Throwable $e) {
            $skillregistry = null;
        }

        $register = function (array $contract) use (&$contractsbyskill, $skillregistry): void {
            $skillname = trim((string)($contract['skill'] ?? ''));
            if ($skillname === '') {
                return;
            }

            if (!isset($contract['properties']) && $skillregistry !== null) {
                $skill = $skillregistry->get_skill($skillname);
                if ($skill !== null) {
                    $schema = (array)$skill->get_schema();
                    $contract['properties'] = $this->compact_properties_for_planner((array)($schema['properties'] ?? []));
                }
            }

            $contractsbyskill[$skillname] = $contract;
        };

        foreach ($livecontracts as $contract) {
            if (is_array($contract)) {
                $register($contract);
            }
        }

        if (!empty($contractsbyskill)) {
            return $contractsbyskill;
        }

        try {
            $registry = skill_registry_factory::get_default();
            foreach ($registry->get_all_prompt_contracts() as $contract) {
                if (is_array($contract)) {
                    $register($contract);
                }
            }
        } catch (\Throwable $e) {
            return $contractsbyskill;
        }

        return $contractsbyskill;
    }

    /**
     * Build compact schema properties for planner prompts.
     *
     * @param array<string,mixed> $properties
     * @return array<string,array<string,mixed>>
     */
    private function compact_properties_for_planner(array $properties): array {
        $compact = [];
        $count = 0;

        foreach ($properties as $name => $spec) {
            if (!is_string($name) || $name === '' || !is_array($spec)) {
                continue;
            }

            $row = [
                'type' => (string)($spec['type'] ?? ''),
                'required' => !empty($spec['required']),
            ];

            $description = trim((string)($spec['description'] ?? ''));
            $description = trim((string)(preg_replace('/\s+/', ' ', $description) ?? $description));
            if ($description !== '') {
                $row['description'] = \core_text::substr($description, 0, 180);
            }

            $compact[$name] = $row;
            $count++;
            if ($count >= 40) {
                break;
            }
        }

        return $compact;
    }


    /**
     * Decode JSON array safely.
     *
     * @param string $json
     * @return array<int|string,mixed>
     */
    private function decode_json_array(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
