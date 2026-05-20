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

/**
 * Internal agent loop state value object.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * Tracks the state of a single run_loop() invocation.
 *
 * State is purely in-memory and is NEVER persisted to the database.
 * It lives only for the duration of run_loop() and is discarded once
 * the final user-visible response is returned.
 *
 * Design contract:
 * - One agent_state per run_loop() call.
 * - current_step is 1-based (set by the loop before each run_internal()).
 * - Observations are plain-text summaries of completed tool executions,
 *   passed back to the orchestrator so the LLM can reason about results.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_state {
    /** @var int Current step index (1-based, set by the loop before each internal step). */
    public int $currentstep = 0;

    /** @var int Maximum number of steps for this invocation. */
    public readonly int $maxsteps;

    /**
     * Ordered list of completed step records.
     *
     * Each entry: ['step' => int, 'tool_calls' => array, 'results' => array, 'observation' => string]
     *
     * @var array<int,array>
     */
    private array $steps = [];

    /**
     * Ordered list of structured observation strings, one per completed step.
     *
     * These are injected into the next LLM prompt as context so the model can
     * reason about what the tools returned.
     *
     * @var string[]
     */
    private array $observations = [];

    /**
     * Private constructor — use agent_state::make().
     *
     * @param int $maxsteps
     */
    private function __construct(int $maxsteps) {
        $this->maxsteps = $maxsteps;
    }

    /**
     * Create a fresh agent state for a new loop invocation.
     *
     * @param  int  $maxsteps  Maximum loop steps (enforced by run_loop()).
     * @return self
     */
    public static function make(int $maxsteps): self {
        return new self(max(1, $maxsteps));
    }

    /**
     * Create an agent state pre-loaded with observations from a previous run.
     *
     * Used when resuming a loop that previously hit the step limit and stored
     * its observations in thread metadata (_loop_resume).
     *
     * @param  int      $maxsteps      Maximum loop steps.
     * @param  string[] $observations  Observation strings from the previous loop.
     * @return self
     */
    public static function make_resumed(int $maxsteps, array $observations): self {
        $instance = new self(max(1, $maxsteps));
        foreach ($observations as $obs) {
            $trimmed = trim((string)$obs);
            if ($trimmed !== '') {
                $instance->observations[] = $trimmed;
            }
        }
        return $instance;
    }

    /**
     * Record a completed tool-execution step together with its observation.
     *
     * Called once per loop iteration where read-only tools were executed and
     * produced an execution_result.  The observation is the human-readable
     * summary injected into the next LLM call.
     *
     * @param  array  $toolcalls   The commands that were executed (may be empty for auto-executed readonly).
     * @param  array  $results     The sanitized result payloads returned by the executor.
     * @param  string $observation Structured observation string (e.g. "Step 1: Found 3 options: Yoga, Pilates, Swim.").
     * @return void
     */
    public function record_step(array $toolcalls, array $results, string $observation): void {
        $this->steps[] = [
            'step'       => $this->currentstep,
            'tool_calls' => $toolcalls,
            'results'    => $results,
            'observation' => trim($observation),
        ];

        $trimmed = trim($observation);
        if ($trimmed !== '') {
            $this->observations[] = $trimmed;
        }
    }

    /**
     * Return accumulated observation strings (one per completed step).
     *
     * These are passed to orchestrator::process() on the next iteration so the
     * LLM can incorporate tool results into its next decision.
     *
     * @return string[]
     */
    public function get_observations(): array {
        return $this->observations;
    }

    /**
     * Return all recorded step records (for debugging / testing).
     *
     * @return array<int,array>
     */
    public function get_steps(): array {
        return $this->steps;
    }

    /**
     * Number of completed internal steps recorded so far.
     *
     * @return int
     */
    public function step_count(): int {
        return count($this->steps);
    }

    /**
     * Whether any observations have been accumulated so far.
     *
     * @return bool
     */
    public function has_observations(): bool {
        return !empty($this->observations);
    }

    /**
     * Extract command signatures from all completed steps.
     *
     * Returns an array of signature strings (task|inputhash) that have been
     * executed in prior steps. Used for loop-guard detection to prevent
     * redundant same-signature re-calls when observations already exist.
     *
     * @return string[]
     */
    public function extract_observed_command_signatures(): array {
        $signatures = [];

        foreach ($this->steps as $step) {
            $toolcalls = (array)($step['tool_calls'] ?? []);

            foreach ($toolcalls as $command) {
                if (!is_array($command)) {
                    continue;
                }

                $taskname = trim((string)($command['task'] ?? ''));
                if ($taskname === '') {
                    continue;
                }

                // Normalize input for comparison (sorted keys, recursively).
                $input = $command['input'] ?? [];
                if (!is_array($input)) {
                    $input = [];
                }

                $normalized = self::normalize_command_input($input);
                $encoded = json_encode(
                    $normalized,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $signature = $taskname . '|' . (is_string($encoded) ? $encoded : '{}');
                $signatures[] = $signature;
            }
        }

        return array_values(array_unique($signatures));
    }

    /**
     * Recursively normalize command input for stable signature comparison.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_command_input($value) {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn($item) => self::normalize_command_input($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize_command_input($item);
        }

        return $value;
    }
}
