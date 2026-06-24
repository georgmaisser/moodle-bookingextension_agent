<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Adaptive skill catalog reducer.
 *
 * Filters full skill registry to planner-oriented Top-K candidates based on:
 *  - Intent (schema metadata)
 *  - Keyword relevance (user message + recent context)
 *  - Recent usage history
 *
 * Generic, language-agnostic: no booking-specific heuristics.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent\services\catalog;


/**
 * Reduces full skill catalog to tiered adaptive candidates with safety nets.
 *
 * Three-tier strategy:
 *  1. MANDATORY: Always visible (help, search, reset)
 *  2. RECENCY: Most recently used (Top-K for planner steps)
 *  3. INTENT-REGISTRY: Metadata for LLM to request by intent
 *
 * Language-agnostic: Uses only structural signals (intent, recency), no text parsing.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adaptive_skill_catalog_service {
    /** Top-K recency cutoff for post-discovery planner phases. */
    public const RECENCY_TOP_K_STEP2PLUS = 80;

    /** Mandatory skills that should always be visible. */
    private const MANDATORY_SKILL_KEYWORDS = ['help', 'search', 'list', 'get_skills'];

    /**
     * Keyword patterns that make a skill mandatory (always shown regardless of recency/embedding).
     * Engine-level only: concrete domain skill names are never listed here.
     * Domain skills that must always be shown declare 'governance' => ['always_available' => true]
     * in their get_schema() return, which propagates to the 'always_available' catalog flag.
     */

        /**
         * Reduce full skill catalog to tiered adaptive catalog.
         *
         * Phase determines strategy:
         *  - discovery: FULL catalog (initial routing, must not miss skills)
         *  - selection / parameter_construction: MANDATORY + RECENCY (Top-80)
         *
         * @param array $fullcatalog Full skill contracts from registry.
         * @param array $recentskillhistory Recent skills used in thread (in order).
         * @param string $phase Current planner phase.
         * @return array Structure: [
         *   'active_skills' => [...],              // Shown to LLM
         * ]
         */
    public static function get_adaptive_catalog(
        array $fullcatalog,
        array $recentskillhistory = [],
        string $phase = 'discovery'
    ): array {
        // Discovery keeps the full catalog; later phases are tiered.
        if ($phase === 'discovery') {
            return [
                'active_skills' => $fullcatalog,
            ];
        }

        // Tier 1: Mandatory skills (always visible).
        $mandatory = self::get_mandatory_skills($fullcatalog);

        // Tier 2: Recency-based skills (top recent, excluding mandatory).
        $topkforthis = self::RECENCY_TOP_K_STEP2PLUS;

        $recency = self::get_recency_filtered($fullcatalog, $recentskillhistory, $topkforthis, $mandatory);

        // Merge and return tiered catalog.
        $activeskills = array_merge($mandatory, $recency);

        return [
            'active_skills' => $activeskills,
        ];
    }

     /**
      * Extract mandatory skills.
      *
      * A skill is mandatory (always shown regardless of recency or phase) when it matches
      * the MANDATORY_SKILL_KEYWORDS list (engine-level) OR when the skill declared
      * 'governance' => ['always_available' => true] in its schema (domain skills).
      *
      * @param array $fullcatalog
      * @return array Mandatory skill contracts.
      */
    private static function get_mandatory_skills(array $fullcatalog): array {
        $mandatory = [];
        foreach ($fullcatalog as $skill) {
            $skillnamelower = strtolower((string)($skill['skill'] ?? ''));
            $ismandatory = (bool)($skill['always_available'] ?? false);
            if (!$ismandatory) {
                foreach (self::MANDATORY_SKILL_KEYWORDS as $keyword) {
                    if (strpos($skillnamelower, $keyword) !== false) {
                        $ismandatory = true;
                        break;
                    }
                }
            }
            if ($ismandatory) {
                $mandatory[] = $skill;
            }
        }
        return $mandatory;
    }

    /**
     * Filter skills by recency (most recently used first).
     *
     * Excludes skills already in mandatory list to avoid duplication.
     * Purely structural: no text parsing, language-agnostic.
     *
     * @param array $fullcatalog
     * @param array $recentskillhistory Recent skill names (most recent first).
     * @param int $topk Number of skills to retain.
     * @param array $exclude Skills to exclude from result.
     * @return array Recency-filtered skill contracts (up to $topk).
     */
    private static function get_recency_filtered(
        array $fullcatalog,
        array $recentskillhistory,
        int $topk,
        array $exclude = []
    ): array {
        // Build exclude set for quick lookup.
        $excludenameset = [];
        foreach ($exclude as $skill) {
            $excludenameset[(string)($skill['skill'] ?? '')] = true;
        }

        // Score skills by recency rank.
        $scored = [];
        foreach ($fullcatalog as $idx => $skill) {
            $skillname = (string)($skill['skill'] ?? '');

            // Skip if in exclude set.
            if (isset($excludenameset[$skillname])) {
                continue;
            }

            // Find recency rank.
            $recencyrank = array_search($skillname, $recentskillhistory, true);
            $score = ($recencyrank !== false) ? (1000 - $recencyrank) : 0;

            $scored[] = [
                'skill_contract' => $skill,
                'score' => $score,
                'original_idx' => $idx,
            ];
        }

        // Sort by score descending (most recent first).
        usort($scored, function ($a, $b) {
            $cmp = $b['score'] <=> $a['score'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['original_idx'] <=> $b['original_idx'];
        });

        // Extract top-k.
        $recency = [];
        $count = 0;
        foreach ($scored as $item) {
            if ($count >= $topk) {
                break;
            }
            $recency[] = $item['skill_contract'];
            ++$count;
        }

        return $recency;
    }
}
