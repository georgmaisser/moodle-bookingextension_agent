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

namespace bookingextension_agent\local\wbagent\services\embeddings;

use bookingextension_agent\local\wbagent\contracts\task_family_contract;

/**
 * Family-level ranking helper for task-catalog embeddings.
 *
 * Aggregates task-row similarities into deterministic family scores and can
 * boost task rows by those family scores.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_embeddings_retrieval_service {
    /**
     * Compute family semantic scores from task-catalog rows.
     *
     * @param array<int,string> $families
     * @param array<int,float|int> $queryvector
     * @param array<int,array<string,string>> $catalogrows
     * @return array<string,float>
     */
    public function score_families(array $families, array $queryvector, array $catalogrows): array {
        $requested = [];
        foreach ($families as $family) {
            $family = task_family_contract::normalize_family((string)$family);
            if ($family !== task_family_contract::DEFAULT_FAMILY) {
                $requested[$family] = true;
            }
        }

        if (empty($requested) || empty($queryvector) || empty($catalogrows)) {
            return [];
        }

        $scores = [];
        foreach ($catalogrows as $row) {
            $task = trim((string)($row['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $family = task_family_contract::from_task_name($task);
            if (!isset($requested[$family])) {
                continue;
            }

            $embedding = json_decode((string)($row['embedding_json'] ?? '[]'), true);
            if (!is_array($embedding) || empty($embedding)) {
                continue;
            }

            $score = $this->cosine_similarity($queryvector, $embedding);
            if (!isset($scores[$family]) || $score > $scores[$family]) {
                $scores[$family] = $score;
            }
        }

        foreach (array_keys($requested) as $family) {
            if (!isset($scores[$family])) {
                $scores[$family] = 0.0;
            }
        }

        return $scores;
    }

    /**
     * Boost task rows with family scores and re-sort them deterministically.
     *
     * @param array<int,array<string,mixed>> $toprows
     * @param array<string,float> $familyscores
     * @param float $taskweight
     * @param float $familyweight
     * @return array<int,array<string,mixed>>
     */
    public function boost_task_rows(
        array $toprows,
        array $familyscores,
        float $taskweight = 0.7,
        float $familyweight = 0.3
    ): array {
        if (empty($toprows)) {
            return [];
        }

        $taskweight = max(0.0, min(1.0, $taskweight));
        $familyweight = max(0.0, min(1.0, $familyweight));
        if (($taskweight + $familyweight) <= 0.0) {
            $taskweight = 1.0;
            $familyweight = 0.0;
        }

        $boosted = [];
        foreach ($toprows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $task = trim((string)($row['task'] ?? ''));
            $family = task_family_contract::from_task_name($task);
            $taskscore = (float)($row['score'] ?? 0.0);
            $familyscore = (float)($familyscores[$family] ?? 0.0);
            $combined = ($taskweight * $taskscore) + ($familyweight * $familyscore);

            $row['family'] = $family;
            $row['family_score'] = $familyscore;
            $row['score'] = $combined;
            $boosted[] = $row;
        }

        usort($boosted, static function (array $a, array $b): int {
            $scorecmp = ((float)$b['score'] <=> (float)$a['score']);
            if ($scorecmp !== 0) {
                return $scorecmp;
            }

            $familycmp = strcmp((string)($a['family'] ?? ''), (string)($b['family'] ?? ''));
            if ($familycmp !== 0) {
                return $familycmp;
            }

            return strcmp((string)($a['task'] ?? ''), (string)($b['task'] ?? ''));
        });

        return array_values($boosted);
    }

    /**
     * Compute cosine similarity.
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
}
