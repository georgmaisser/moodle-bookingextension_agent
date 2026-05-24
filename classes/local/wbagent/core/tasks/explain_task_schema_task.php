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

namespace bookingextension_agent\local\wbagent\core\tasks;

use context_module;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\task_contract_validator;
use bookingextension_agent\local\wbagent\task_executability_evaluator;
use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Task definition for booking.explain_task_schema.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explain_task_schema_task extends \bookingextension_agent\local\wbagent\booking\tasks\booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.explain_task_schema';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Return the full schema for one specific task name.'
                . ' Use this for capability introspection when the user asks for the exact inputs/fields'
                . ' of one task (for example booking.create_option).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'taskname' => [
                    'type' => 'string',
                    'description' => 'Exact task name, e.g. booking.create_option.',
                    'required' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for wrapper messages, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.explain_task_schema_request',
            'description' => 'User asks for the full schema/fields of one specific agent task.',
        ]];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $taskname = trim((string)($input['taskname'] ?? ''));

        if ($taskname === '') {
            $errors[] = get_string('agent_booking_explain_task_schema_taskname_required', 'bookingextension_agent');
        } else {
            $registry = task_registry_factory::get_default();
            if ($registry->get_task($taskname) === null) {
                $errors[] = get_string('agent_booking_unknown_task', 'bookingextension_agent', $taskname);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $taskname = trim((string)($input['taskname'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $registry = task_registry_factory::get_default();
        $contextid = (int)context_module::instance($cmid)->id;
        $evaluator = new task_executability_evaluator($registry, new authorization_service());
        $task = $registry->get_task($taskname);

        if ($task === null) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_unknown_task', 'bookingextension_agent', $taskname),
                'resultid' => null,
            ];
        }

        $schema = $registry->explain_task_schema_for_context($taskname, $evaluator, $userid, $contextid);
        if ($schema === null) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_unknown_task', 'bookingextension_agent', $taskname),
                'resultid' => null,
            ];
        }

        if ((string)($schema['executable_state'] ?? 'deny') !== 'allow') {
            $denyreason = (string)($schema['deny_reason'] ?? task_contract_validator::DENY_NOT_REGISTERED);
            return [
                'status' => 'error',
                'detail' => 'Task denied by governance gate (' . $denyreason . '): ' . $taskname,
                'resultid' => null,
                'deny_reason' => $denyreason,
                'diagnostics' => (array)($schema['governance_diagnostics'] ?? []),
            ];
        }

        $summary = $this->localized_string('agent_booking_explain_task_schema_found', $taskname, $outputlang);
        $debugmessage = implode("\n", [
            'Task: ' . self::TASK_NAME,
            'Requested task: ' . $taskname,
            'Readonly: ' . ($task->is_read_only() ? '1' : '0'),
        ]);

        $payload = [
            'task' => $taskname,
            'label' => booking_task_support::get_localized_action_label_for_output($taskname),
            'readonly' => $task->is_read_only(),
            'schema' => $schema,
        ];

        return [
            'status' => 'executed',
            'detail' => $summary,
            'resultid' => null,
            'usermessage' => $summary,
            'debugmessage' => $debugmessage,
            'taskname' => $taskname,
            'schema' => $schema,
            'task_details' => $payload,
            'observation_full' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ];
    }
}
