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
 * Adaptive task catalog reducer.
 *
 * Filters full task registry to Top-K candidates based on:
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

namespace bookingextension_agent\local\wbagent;

/**
 * Reduces full task catalog to tiered adaptive candidates with safety nets.
 *
 * Three-tier strategy:
 *  1. MANDATORY: Always visible (help, search, reset)
 *  2. RECENCY: Most recently used (Top-K per step-type)
 *  3. INTENT-REGISTRY: Metadata for LLM to request by intent
 *
 * Language-agnostic: Uses only structural signals (intent, recency), no text parsing.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adaptive_task_catalog_service {
    /** Top-K recency cutoff for Step 2+ (simple_retrieval, final_reasoning). */
    public const RECENCY_TOP_K_STEP2PLUS = 80;

    /** Mandatory tasks that should always be visible. */
    private const MANDATORY_TASK_KEYWORDS = ['help', 'search', 'list', 'get_tasks'];

    /**
     * Reduce full task catalog to tiered adaptive catalog.
     *
     * Step-type determines strategy:
     *  - tool_call_parse: FULL catalog (initial routing, must not miss tasks)
     *  - simple_retrieval: MANDATORY + RECENCY (Top-80)
     *  - final_reasoning/final_synthesis: MANDATORY + RECENCY (Top-50)
     *
     * @param array $fullcatalog Full task contracts from registry.
     * @param array $recenttaskhistory Recent tasks used in thread (in order).
     * @param string $steptype Current step type (tool_call_parse, simple_retrieval, etc).
     * @return array Structure: [
     *   'active_tasks' => [...],              // Shown to LLM
     * ]
     */
    public static function get_adaptive_catalog(
        array $fullcatalog,
        array $recenttaskhistory = [],
        string $steptype = 'tool_call_parse'
    ): array {
        // Strategy: Step 1 = Full, Step 2+ = Tiered.
        if ($steptype === 'tool_call_parse') {
            // Initial routing: FULL catalog, no filtering.
            return [
                'active_tasks' => $fullcatalog,
            ];
        }

        // Tier 1: Mandatory tasks (always visible).
        $mandatory = self::get_mandatory_tasks($fullcatalog);

        // Tier 2: Recency-based tasks (top recent, excluding mandatory).
        $topkforthis = ($steptype === 'final_reasoning' || $steptype === 'final_synthesis')
            ? 40  // Final steps: very compact.
            : self::RECENCY_TOP_K_STEP2PLUS;  // Iteration: more tasks.

        $recency = self::get_recency_filtered($fullcatalog, $recenttaskhistory, $topkforthis, $mandatory);

        // Merge and return tiered catalog.
        $activetasks = array_merge($mandatory, $recency);

        return [
            'active_tasks' => $activetasks,
        ];
    }

    /**
     * Extract mandatory tasks (help, search, list, get_tasks variants).
     *
     * These are always shown to LLM regardless of recency or step type.
     * Allows LLM to "reset" or request alternative catalog views.
     *
     * @param array $fullcatalog
     * @return array Mandatory task contracts.
     */
    private static function get_mandatory_tasks(array $fullcatalog): array {
        $mandatory = [];
        foreach ($fullcatalog as $task) {
            $taskname = strtolower((string)($task['task'] ?? ''));
            foreach (self::MANDATORY_TASK_KEYWORDS as $keyword) {
                if (strpos($taskname, $keyword) !== false) {
                    $mandatory[] = $task;
                    break;
                }
            }
        }
        return $mandatory;
    }

    /**
     * Filter tasks by recency (most recently used first).
     *
     * Excludes tasks already in mandatory list to avoid duplication.
     * Purely structural: no text parsing, language-agnostic.
     *
     * @param array $fullcatalog
     * @param array $recenttaskhistory Recent task names (most recent first).
     * @param int $topk Number of tasks to retain.
     * @param array $exclude Tasks to exclude from result.
     * @return array Recency-filtered task contracts (up to $topk).
     */
    private static function get_recency_filtered(
        array $fullcatalog,
        array $recenttaskhistory,
        int $topk,
        array $exclude = []
    ): array {
        // Build exclude set for quick lookup.
        $excludenameset = [];
        foreach ($exclude as $task) {
            $excludenameset[(string)($task['task'] ?? '')] = true;
        }

        // Score tasks by recency rank.
        $scored = [];
        foreach ($fullcatalog as $idx => $task) {
            $taskname = (string)($task['task'] ?? '');

            // Skip if in exclude set.
            if (isset($excludenameset[$taskname])) {
                continue;
            }

            // Find recency rank.
            $recencyrank = array_search($taskname, $recenttaskhistory, true);
            $score = ($recencyrank !== false) ? (1000 - $recencyrank) : 0;

            $scored[] = [
                'task' => $task,
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
            $recency[] = $item['task'];
            ++$count;
        }

        return $recency;
    }
}
