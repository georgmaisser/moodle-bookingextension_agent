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
 * Decide staged discovery escalation (A/B/C) using budget and confidence rules.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery_stage_controller {
    /** @var discovery_budget_policy */
    private discovery_budget_policy $budgetpolicy;

    /** @var discovery_confidence_policy */
    private discovery_confidence_policy $confidencepolicy;

    /**
     * Constructor.
     *
     * @param discovery_budget_policy|null $budgetpolicy
     * @param discovery_confidence_policy|null $confidencepolicy
     */
    public function __construct(
        ?discovery_budget_policy $budgetpolicy = null,
        ?discovery_confidence_policy $confidencepolicy = null
    ) {
        $this->budgetpolicy = $budgetpolicy ?? new discovery_budget_policy();
        $this->confidencepolicy = $confidencepolicy ?? new discovery_confidence_policy();
    }

    /**
     * Resolve staged discovery output.
     *
     * @param array<int,array<string,mixed>> $rankedfamilies
     * @param array<int,string> $contextfamilies
     * @param array<int,string> $corefamilies
     * @return array<string,mixed>
     */
    public function resolve(array $rankedfamilies, array $contextfamilies, array $corefamilies): array {
        if (empty($rankedfamilies)) {
            return [
                'discovery_stage' => 'none',
                'confidence_score' => null,
                'escalation_reason' => 'no_candidates',
                'selected_families' => [],
            ];
        }

        $rankmap = [];
        foreach ($rankedfamilies as $row) {
            $family = (string)($row['family'] ?? '');
            if ($family === '') {
                continue;
            }
            $rankmap[$family] = (float)($row['score'] ?? 0.0);
        }

        $stageafamilies = array_values(array_unique(array_merge($contextfamilies, $corefamilies)));
        $stagearows = $this->rows_for_families($rankedfamilies, $stageafamilies);
        $stagearows = $this->budgetpolicy->apply_budget($stagearows, 'A');
        $stageascore = $this->top_score($stagearows);

        if ($this->confidencepolicy->is_sufficient($stageascore, 'A')) {
            $selectedfamilies = array_values(array_map(
                static fn(array $row): string => (string)$row['family'],
                $stagearows
            ));
            return [
                'discovery_stage' => 'A',
                'confidence_score' => $this->confidencepolicy->normalize_score($stageascore),
                'escalation_reason' => 'none',
                'selected_families' => $selectedfamilies,
            ];
        }

        $stagebrows = $this->budgetpolicy->apply_budget($rankedfamilies, 'B');
        $stagebscore = $this->top_score($stagebrows);
        if ($this->confidencepolicy->is_sufficient($stagebscore, 'B')) {
            $selectedfamilies = array_values(array_map(
                static fn(array $row): string => (string)$row['family'],
                $stagebrows
            ));
            return [
                'discovery_stage' => 'B',
                'confidence_score' => $this->confidencepolicy->normalize_score($stagebscore),
                'escalation_reason' => 'stage_a_low_confidence',
                'selected_families' => $selectedfamilies,
            ];
        }

        $stagecrows = $this->budgetpolicy->apply_budget($rankedfamilies, 'C');
        $stagecscore = $this->top_score($stagecrows);

        return [
            'discovery_stage' => 'C',
            'confidence_score' => $this->confidencepolicy->normalize_score($stagecscore),
            'escalation_reason' => 'stage_b_low_confidence',
            'selected_families' => array_values(array_map(static fn(array $row): string => (string)$row['family'], $stagecrows)),
        ];
    }

    /**
     * Filter ranked rows by candidate family list while preserving rank order.
     *
     * @param array<int,array<string,mixed>> $rankedfamilies
     * @param array<int,string> $families
     * @return array<int,array<string,mixed>>
     */
    private function rows_for_families(array $rankedfamilies, array $families): array {
        $allowed = array_fill_keys($families, true);
        $rows = [];
        foreach ($rankedfamilies as $row) {
            $family = (string)($row['family'] ?? '');
            if ($family === '' || !isset($allowed[$family])) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Return top confidence score from ranked rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return float|null
     */
    private function top_score(array $rows): ?float {
        if (empty($rows)) {
            return null;
        }

        return (float)($rows[0]['score'] ?? 0.0);
    }
}
