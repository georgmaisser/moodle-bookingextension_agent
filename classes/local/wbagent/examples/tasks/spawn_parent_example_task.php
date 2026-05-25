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
 * Scenario C reference: parent task with spawn_commands.
 *
 * This class demonstrates the canonical produced_outputs + output_bindings
 * pattern for spawned child commands.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spawn_parent_example_task extends base_task {
    /** Stable task name. */
    public const TASK_NAME = 'examples.spawn_parent_example';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Example parent task that spawns deterministic child tasks.',
            'readonly' => false,
            'properties' => [
                'batch_label' => [
                    'type' => 'string',
                    'description' => 'Human-readable label shared with spawned children.',
                    'required' => true,
                ],
                'child_count' => [
                    'type' => 'integer',
                    'description' => 'How many child commands should be spawned (1..3).',
                    'required' => false,
                ],
            ],
            'required' => ['batch_label'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'batch_label' => 'example demo batch',
            'child_count' => 2,
        ];
    }

    /**
     * @return task_prompt_contract
     */
    public function get_prompt_contract(): task_prompt_contract {
        return new task_prompt_contract([
            'intent' => 'Run parent task and spawn deterministic child commands via output bindings.',
            'anchors' => ['batch_label'],
            'minimal_input' => ['batch_label'],
            'example_input' => $this->get_example_input(),
            'namespace' => 'examples',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $batchlabel = trim((string)($input['batch_label'] ?? ''));
        if ($batchlabel === '') {
            $errors[] = 'Field "batch_label" is required.';
        }

        if (array_key_exists('child_count', $input)) {
            $childcount = (int)$input['child_count'];
            if ($childcount < 1 || $childcount > 3) {
                $errors[] = 'Field "child_count" must be between 1 and 3.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param int $contextid
     * @param int $userid
     * @return preflight_result_v2
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

        return preflight_result_v2::ok([
            'batch_label' => trim((string)$input['batch_label']),
            'child_count' => max(1, min(3, (int)($input['child_count'] ?? 2))),
            'contextid' => $contextid,
            'userid' => $userid,
        ]);
    }

    /**
     * @param array<string,mixed> $preparedinput
     * @param int $contextid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $batchlabel = trim((string)($preparedinput['batch_label'] ?? ''));
        $childcount = max(1, min(3, (int)($preparedinput['child_count'] ?? 2)));

        $spawncommands = [];
        for ($index = 1; $index <= $childcount; $index++) {
            $spawncommands[] = [
                'task' => 'examples.spawn_child_example',
                'version' => 1,
                'input' => [
                    'child_label' => 'child-' . $index,
                ],
                'output_bindings' => [
                    'batch_label' => 'parent.batch_label',
                    'ticket_id' => 'parent.ticket_id',
                ],
                'depends_on' => [],
            ];
        }

        $ticketid = 'SP-' . strtoupper(substr(sha1($batchlabel . '|' . $contextid . '|' . $userid), 0, 10));

        return [
            'status' => 'executed',
            'detail' => '[SCENARIO-C-PARENT] spawn parent example executed',
            'usermessage' => '[SCENARIO-C] Spawn parent scenario started successfully.',
            'resultid' => 0,
            'produced_outputs' => [
                'batch_label' => $batchlabel,
                'ticket_id' => $ticketid,
                'requested_child_count' => $childcount,
            ],
            'spawn_commands' => $spawncommands,
        ];
    }
}
