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
 * Compute language-agnostic signal scores for family candidates.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_signal_ranker {
    /**
     * Score families from context priors and recency signals.
     *
     * @param array<int,string> $families
     * @param array<string,mixed> $contextprior
     * @param array<int,string> $recenttasknames
     * @return array<string,float>
     */
    public function score_families(array $families, array $contextprior, array $recenttasknames = []): array {
        $scores = [];
        $namespacehint = trim((string)($contextprior['namespace_hint'] ?? ''));

        $recentnamespaces = [];
        foreach ($recenttasknames as $taskname) {
            $dot = strpos((string)$taskname, '.');
            if ($dot === false || $dot <= 0) {
                continue;
            }
            $recentnamespaces[] = substr((string)$taskname, 0, $dot);
        }

        foreach ($families as $family) {
            $score = 0.20;

            if (strpos($family, 'core.') === 0) {
                $score += 0.10;
            }

            if ($namespacehint !== '' && strpos($family, $namespacehint . '.') === 0) {
                $score += 0.35;
            }

            foreach ($recentnamespaces as $namespace) {
                if (strpos($family, $namespace . '.') === 0) {
                    $score += 0.20;
                    break;
                }
            }

            $scores[$family] = min(1.0, max(0.0, $score));
        }

        return $scores;
    }
}
