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
 * Runtime helper for step task/signature extraction and normalization.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime_step_analysis_service {
    /**
     * Extract task names for a completed loop step.
     *
     * @param array $commands
     * @param array $results
     * @return array<int,string>
     */
    public function extract_step_task_names(array $commands, array $results): array {
        $tasknames = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '') {
                $tasknames[] = $taskname;
            }
        }

        if (!empty($tasknames)) {
            return array_values(array_unique($tasknames));
        }

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $taskname = trim((string)($result['task'] ?? ''));
            if ($taskname !== '') {
                $tasknames[] = $taskname;
            }
        }

        return array_values(array_unique($tasknames));
    }

    /**
     * Convert technical task name into readable fallback label.
     *
     * @param string $taskname
     * @return string
     */
    public function humanize_task_name(string $taskname): string {
        $taskname = trim($taskname);
        if ($taskname === '') {
            return 'Processing';
        }

        $tail = $taskname;
        if (str_contains($taskname, '.')) {
            $parts = explode('.', $taskname);
            $tail = (string)end($parts);
        }

        $tail = str_replace('_', ' ', $tail);
        return ucfirst($tail);
    }

    /**
     * Extract comparable command signatures for a completed loop step.
     *
     * @param array $commands
     * @param array $results
     * @return array<int,string>
     */
    public function extract_step_command_signatures(array $commands, array $results): array {
        $signatures = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                continue;
            }

            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                $input = [];
            }

            $normalizedinput = $this->normalize_command_input_for_signature($input);
            $encodedinput = json_encode($normalizedinput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signatures[] = $taskname . '|' . (is_string($encodedinput) ? $encodedinput : '{}');
        }

        if (!empty($signatures)) {
            return array_values(array_unique($signatures));
        }

        return $this->extract_step_task_names($commands, $results);
    }

    /**
     * Collect unique task names recorded in loop state steps.
     *
     * @param agent_state $state
     * @return array<int,string>
     */
    public function extract_recorded_step_task_names(agent_state $state): array {
        $tasknames = [];
        foreach ($state->get_steps() as $step) {
            $names = $this->extract_step_task_names(
                (array)($step['tool_calls'] ?? []),
                (array)($step['results'] ?? [])
            );
            foreach ($names as $name) {
                $trimmed = trim((string)$name);
                if ($trimmed !== '') {
                    $tasknames[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($tasknames));
    }

    /**
     * Recursively normalize command input for stable signature comparison.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_command_input_for_signature($value) {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn($item) => $this->normalize_command_input_for_signature($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize_command_input_for_signature($item);
        }

        return $value;
    }
}
