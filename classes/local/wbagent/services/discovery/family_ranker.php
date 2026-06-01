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

namespace bookingextension_agent\local\wbagent\services\discovery;

/**
 * Merge ranking signals into one deterministic family ranking.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_ranker {
    /**
     * Rank families by combined signal and semantic scores.
     *
     * @param array<int,string> $families
     * @param array<string,float> $signalscores
     * @param array<string,float> $semanticscores
     * @return array<int,array<string,mixed>>
     */
    public function rank(array $families, array $signalscores, array $semanticscores = []): array {
        $rows = [];
        foreach ($families as $family) {
            $signal = (float)($signalscores[$family] ?? 0.0);
            $semantic = (float)($semanticscores[$family] ?? 0.0);
            if (empty($semanticscores)) {
                $score = $signal;
            } else {
                $score = (0.7 * $signal) + (0.3 * $semantic);
            }

            $rows[] = [
                'family' => $family,
                'score' => min(1.0, max(0.0, $score)),
                'signal_score' => min(1.0, max(0.0, $signal)),
                'semantic_score' => min(1.0, max(0.0, $semantic)),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $scorecmp = ((float)$b['score'] <=> (float)$a['score']);
            if ($scorecmp !== 0) {
                return $scorecmp;
            }

            return strcmp((string)$a['family'], (string)$b['family']);
        });

        return $rows;
    }
}
