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

use bookingextension_agent\local\wbagent\agent_state;

/**
 * Builds synchronizer input from runtime result and loop state.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_input_builder {
    /**
     * Build the observation list for synchronizer finalization.
     *
     * @param array $result
     * @param agent_state|null $state
     * @return array
     */
    public function build_observations(array $result, ?agent_state $state = null): array {
        $observations = [];

        if ($state !== null && $state->has_observations()) {
            $observations = $state->get_observations();
        } else {
            $loopresults = (array)($result['loop_results'] ?? []);
            foreach ($loopresults as $step) {
                if (!is_array($step)) {
                    continue;
                }

                $observation = trim((string)($step['observation'] ?? ''));
                if ($observation !== '') {
                    $observations[] = $observation;
                }
            }
        }

        $sourceobservation = $this->build_source_observation($result);
        if ($sourceobservation !== '') {
            $observations[] = $sourceobservation;
        }

        $phasetraceobservation = $this->build_phase_trace_observation($result);
        if ($phasetraceobservation !== '') {
            $observations[] = $phasetraceobservation;
        }

        $executionfeedbackobservation = $this->build_execution_feedback_observation($result);
        if ($executionfeedbackobservation !== '') {
            $observations[] = $executionfeedbackobservation;
        }

        return $observations;
    }

    /**
     * Build a normalized phase trace observation for synchronization context.
     *
     * @param array $result
     * @return string
     */
    private function build_phase_trace_observation(array $result): string {
        $phasetrace = (array)($result['phase_trace'] ?? []);
        if (empty($phasetrace)) {
            return '';
        }

        $payload = [
            'discovery' => $this->sanitize_phase_trace_snapshot((array)($phasetrace['discovery'] ?? [])),
            'selection' => $this->sanitize_phase_trace_snapshot((array)($phasetrace['selection'] ?? [])),
            'parameter_construction' => $this->sanitize_phase_trace_snapshot(
                (array)($phasetrace['parameter_construction'] ?? [])
            ),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        return 'PHASE_TRACE' . "\n" . $json;
    }

    /**
     * Keep only minimal phase telemetry and exclude task-discovery payloads.
     *
     * @param array $snapshot
     * @return array
     */
    private function sanitize_phase_trace_snapshot(array $snapshot): array {
        return [
            'phase' => trim((string)($snapshot['phase'] ?? '')),
            'response_type' => trim((string)($snapshot['response_type'] ?? '')),
            'issue_codes' => $this->normalize_issue_codes((array)($snapshot['issue_codes'] ?? [])),
            'errors' => $this->normalize_nonempty_string_list((array)($snapshot['errors'] ?? [])),
        ];
    }

    /**
     * Build compact execution feedback observation for synchronizer prompts.
     *
     * @param array $result
     * @return string
     */
    private function build_execution_feedback_observation(array $result): string {
        $results = (array)($result['results'] ?? []);
        if (empty($results)) {
            return '';
        }

        $statuscounts = [];
        $tasks = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = trim((string)($row['status'] ?? 'unknown'));
            if ($status === '') {
                $status = 'unknown';
            }
            $statuscounts[$status] = (int)($statuscounts[$status] ?? 0) + 1;

            $task = trim((string)($row['task'] ?? ''));
            if ($task !== '') {
                $tasks[] = $task;
            }
        }

        if (empty($statuscounts) && empty($tasks)) {
            return '';
        }

        $payload = [
            'result_count' => count($results),
            'status_counts' => $statuscounts,
            'tasks' => array_values(array_unique($tasks)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        return 'EXECUTION_FEEDBACK' . "\n" . $json;
    }

    /**
     * Build a compact source result observation for finalization.
     *
     * @param array $result
     * @return string
     */
    private function build_source_observation(array $result): string {
        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            return '';
        }

        $responsetype = trim((string)($result['response_type'] ?? ''));
        $issuecodes = $this->normalize_issue_codes((array)($result['issue_codes'] ?? []));
        $attemptedtasks = $this->normalize_nonempty_string_list((array)($result['attempted_tasks'] ?? []));

        $lines = ['FINAL_SOURCE_RESULT'];
        if ($responsetype !== '') {
            $lines[] = 'response_type=' . $responsetype;
        }
        if (!empty($issuecodes)) {
            $lines[] = 'issue_codes=' . implode(',', array_slice($issuecodes, 0, 8));
        }
        if (!empty($attemptedtasks)) {
            $lines[] = 'attempted_tasks=' . implode(',', array_slice($attemptedtasks, 0, 8));
        }

        $normalizedmessage = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $lines[] = 'message=' . substr($normalizedmessage, 0, 600);

        return implode("\n", $lines);
    }

    /**
     * Normalize issue codes to unique uppercase entries.
     *
     * @param array $codes
     * @return array
     */
    private function normalize_issue_codes(array $codes): array {
        $normalized = [];
        foreach ($codes as $code) {
            $value = strtoupper(trim((string)$code));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize a list to non-empty strings.
     *
     * @param array $values
     * @return array
     */
    private function normalize_nonempty_string_list(array $values): array {
        $normalized = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values(array_unique($normalized));
    }
}
