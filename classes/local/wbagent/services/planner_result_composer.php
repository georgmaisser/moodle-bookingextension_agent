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

namespace bookingextension_agent\local\wbagent\services;

/**
 * Compose the planner result from discovery, selection, and construction phases.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner_result_composer {
    /**
     * Compose a unified planner result while preserving the construction payload.
     *
     * @param array<string,mixed> $discoverystate
     * @param array<string,mixed> $selectionstate
     * @param array<string,mixed> $constructionstate
     * @return array<string,mixed>
     */
    public function compose(array $discoverystate, array $selectionstate, array $constructionstate): array {
        $phasetrace = [
            'discovery' => $this->build_phase_snapshot($discoverystate),
            'selection' => $this->build_phase_snapshot($selectionstate),
            'parameter_construction' => $this->build_phase_snapshot($constructionstate),
        ];

        $plannerresult = [
            'discovery' => $discoverystate,
            'selection' => $selectionstate,
            'parameter_construction' => $constructionstate,
            'phase_trace' => $phasetrace,
            'planner_trace_history' => (array)($discoverystate['plannertracehistory'] ?? []),
        ];

        $result = $constructionstate;
        $result['phase_trace'] = $phasetrace;
        $result['planner_result'] = $plannerresult;
        return $result;
    }

    /**
     * Reduce a phase state to a stable trace snapshot.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function build_phase_snapshot(array $state): array {
        return [
            'response_type' => (string)($state['response_type'] ?? ''),
            'message' => (string)($state['message'] ?? ''),
            'phase' => (string)($state['phase'] ?? ''),
            'catalogselectionmode' => (string)($state['catalogselectionmode'] ?? ''),
            'embeddingstatus' => (string)($state['embeddingstatus'] ?? ''),
            'issue_codes' => (array)($state['issue_codes'] ?? []),
            'errors' => (array)($state['errors'] ?? []),
        ];
    }
}
