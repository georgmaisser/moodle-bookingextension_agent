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

namespace bookingextension_agent\local\wbagent\services;

use core_text;

/**
 * Synthesis and sufficiency policy for runtime loop completion decisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime_synthesis_policy_service {
    /**
     * Determine whether any executed/planned task indicates explain/diagnose behavior.
     *
     * @param array<int,string> $tasknames
     * @return bool
     */
    public function has_explain_or_diagnose_task(array $tasknames): bool {
        foreach ($tasknames as $taskname) {
            $normalized = trim(core_text::strtolower((string)$taskname));
            if ($normalized === '') {
                continue;
            }

            if (
                str_contains($normalized, 'explain_')
                || str_contains($normalized, 'diagnose_')
                || str_contains($normalized, '.explain_')
                || str_contains($normalized, '.diagnose_')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert premature planner sufficiency into clarification for read-only loops.
     *
     * @param array $result
     * @param int $stepcount
     * @param bool $hasobservations
     * @param bool $onlyreadonlyresults
     * @param array<int,string> $alltasks
     * @return bool
     */
    public function should_convert_sufficient_to_readonly_clarification(
        array $result,
        int $stepcount,
        bool $hasobservations,
        bool $onlyreadonlyresults,
        array $alltasks
    ): bool {
        if ((string)($result['response_type'] ?? '') !== 'sufficient') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        if ($stepcount < 1 || !$hasobservations || !$onlyreadonlyresults) {
            return false;
        }

        return !$this->has_explain_or_diagnose_task($alltasks);
    }

    /**
     * Determine whether planner output is a strict sufficiency-exit signal.
     *
     * @param array $result
     * @param int $observationcount
     * @return bool
     */
    public function is_sufficiency_exit_signal(array $result, int $observationcount): bool {
        $rt = (string)($result['response_type'] ?? '');

        if ($rt === 'sufficient') {
            return empty((array)($result['commands'] ?? []));
        }

        if ($rt !== 'clarification') {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return false;
        }

        if ($observationcount < 1) {
            return false;
        }

        return trim((string)($result['message'] ?? '')) === 'observation_sufficient';
    }
}
