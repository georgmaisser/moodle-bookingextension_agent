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

        return $observations;
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
