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

namespace bookingextension_agent\local\wbagent\examples\tasks;

use bookingextension_agent\local\wbagent\base_task;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\services\task_prompt_contract;

/**
 * Scenario B reference: ideal multistep task.
 *
 * This task demonstrates how a single command can execute multiple internal
 * deterministic steps while still keeping framework contracts simple.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multistep_example_task extends base_task {
    /** Stable task name. */
    public const TASK_NAME = 'examples.multistep_example';

    /**
     * Constructor.
     */
    public function __construct() {
        // Marked as mutating to demonstrate explicit confirmation flow in tests.
        parent::__construct(false);
    }

    /**
     * Get the stable task name.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Get the schema for the task.
     *
     * @return array
     *
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Example multistep task for scenario B.',
            'readonly' => false,
            'properties' => [
                'objective' => [
                    'type' => 'string',
                    'description' => 'High-level goal label for the deterministic multistep demo.',
                    'required' => true,
                ],
                'steps' => [
                    'type' => 'array',
                    'description' => 'Ordered list of 2..6 short step labels.',
                    'required' => true,
                ],
            ],
            'required' => ['objective', 'steps'],
        ];
    }

    /**
     * Get example input for the task.
     *
     * @return array
     *
     */
    public function get_example_input(): array {
        return [
            'objective' => 'Prepare rollout checklist',
            'steps' => ['validate input', 'build plan', 'summarize'],
        ];
    }

    /**
     * Explicit prompt contract for planner/executor.
     *
     * @return task_prompt_contract
     *
     */
    public function get_prompt_contract(): task_prompt_contract {
        return new task_prompt_contract([
            'intent' => 'Execute a deterministic multistep workflow in one task call.',
            'anchors' => ['objective', 'steps'],
            'minimal_input' => ['objective', 'steps'],
            'example_input' => $this->get_example_input(),
            'namespace' => 'examples',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
        ]);
    }

    /**
     * [Description for check_structure]
     *
     * @param array $input
     *
     * @return array
     *
     */
    public function check_structure(array $input): array {
        $errors = [];
        $issuecodes = [];

        $objective = trim((string)($input['objective'] ?? ''));
        if ($objective === '') {
            $errors[] = 'Field "objective" is required.';
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }

        $steps = $input['steps'] ?? [];
        if (!is_array($steps)) {
            $errors[] = 'Field "steps" must be an array.';
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        } else {
            $stepcount = count($steps);
            if ($stepcount < 2 || $stepcount > 6) {
                $errors[] = 'Field "steps" must contain between 2 and 6 entries.';
                $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
            }
            foreach ($steps as $idx => $step) {
                if (trim((string)$step) === '') {
                    $errors[] = 'Step at index ' . $idx . ' must not be empty.';
                    $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Preflight check for the task.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     *
     * @return preflight_result_v2
     *
     */
    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = array_map(static fn(string $error): array => [
                'code' => 'EXAMPLE_INPUT_INVALID',
                'severity' => 'hard_block',
                'message' => $error,
            ], $structure['errors']);
            return preflight_result_v2::invalid($issues);
        }

        $steps = [];
        foreach ((array)$input['steps'] as $step) {
            $steps[] = trim((string)$step);
        }

        return preflight_result_v2::ok([
            'objective' => trim((string)$input['objective']),
            'steps' => $steps,
            'contextid' => $contextid,
            'userid' => $userid,
        ]);
    }

    /**
     * Execute the task.
     *
     * @param array $preparedinput
     * @param int $contextid
     * @param int $userid
     *
     * @return array
     *
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $objective = trim((string)($preparedinput['objective'] ?? ''));
        $steps = (array)($preparedinput['steps'] ?? []);

        $executedsteps = [];
        foreach ($steps as $index => $step) {
            $executedsteps[] = [
                'step' => $index + 1,
                'label' => (string)$step,
                'status' => 'done',
            ];
        }

        $ticketid = 'MS-' . strtoupper(substr(sha1($objective . '|' . $contextid . '|' . $userid), 0, 10));

        return [
            'status' => 'executed',
            'detail' => '[SCENARIO-B] multistep example executed',
            'usermessage' => '[SCENARIO-B] Multistep scenario completed successfully.',
            'resultid' => 0,
            'ticketid' => $ticketid,
            'objective' => $objective,
            'executedsteps' => $executedsteps,
            'produced_outputs' => [
                'multistep_ticket_id' => $ticketid,
                'multistep_step_count' => count($executedsteps),
            ],
        ];
    }
}
