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
 * Shared scenario matrix for LLM smoke tests.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Provides one reusable task scenario matrix for real and simulated LLM suites.
 */
final class llm_task_matrix_scenario_provider {
    /**
     * Build provider rows for all tasks currently registered in the live registry.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function provide_registered_task_scenarios(): array {
        $definitions = self::get_scenario_definitions();
        $registry = task_registry_factory::get_default();
        $contracts = $registry->get_task_contracts();
        ksort($contracts);

        $rows = [];
        foreach (array_keys($contracts) as $taskname) {
            $scenario = $definitions[$taskname] ?? [
                'prompt' => '',
                'missing_definition' => true,
            ];
            $scenario['task'] = $taskname;
            $scenario['mode'] = $scenario['mode'] ?? ($registry->is_read_only_task($taskname) ? 'readonly' : 'mutating');
            $rows[$taskname] = [$scenario];
        }

        return $rows;
    }

    /**
     * Return registry task names that still have no explicit scenario definition.
     *
     * @return array<int,string>
     */
    public static function get_missing_registered_task_scenarios(): array {
        $definitions = self::get_scenario_definitions();
        $registry = task_registry_factory::get_default();
        $missing = [];

        foreach ($registry->get_task_names() as $taskname) {
            if (!array_key_exists($taskname, $definitions)) {
                $missing[] = $taskname;
            }
        }

        sort($missing);
        return $missing;
    }

    /**
     * Return the explicit scenario definitions keyed by task name.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function get_scenario_definitions(): array {
        return [
            'core.get_current_user' => [
                'prompt' => 'Who am I in this booking context? Show my current user profile.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'userid',
                        'value' => '{{teacher_id}}',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'email',
                        'value' => '{{teacher_email}}',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'observation_full',
                        'value' => '{{course_fullname}}',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.list_actions' => [
                'prompt' => 'What can you do here? List the available agent actions for this booking.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_count_gte',
                        'field' => 'actions',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'actions.0.provider',
                        'value' => 'bookingextension/agent',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'bookingextension/agent',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'core.recall_memory' => [
                'prompt' => 'What did we talk about last time about "{{memory_token}}"?',
                'setup' => 'prepare_recall_memory_scenario',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'No previous memory was found for your request.',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_count_gte',
                        'field' => 'messages',
                        'value' => 0,
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'core.recreate_task_catalog' => [
                'prompt' => 'Recreate the task catalog embeddings now.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'queued',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'queued_task_class',
                        'value' => 'rebuild_task_catalog_embeddings_adhoc',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wpl',
                    ],
                ],
            ],
            'core.search_courses' => [
                'prompt' => 'Search the course "{{course_fullname}}" for me.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_count_gte',
                        'field' => 'courses',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'courses.0.fullname',
                        'value' => '{{course_fullname}}',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.search_users' => [
                'prompt' => 'Search the user "{{search_user_fullname}}" for me.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_count_gte',
                        'field' => 'users',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'previewdata.query',
                        'value' => '{{search_user_fullname}}',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'entities.create_entity' => [
                'prompt' => 'Create a new entity called "{{entity_name}}".',
                'setup' => 'prepare_entity_scenario',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '{{entity_name}}',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'entity.name',
                        'value' => '{{entity_name}}',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'entity.link',
                        'value' => '/local/entities/',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'entities.list_all_entities' => [
                'prompt' => 'List all entities with a limit of 5.',
                'setup' => 'prepare_entity_scenario',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_count_gte',
                        'field' => 'entities',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'entit(y/ies)',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'entities.search' => [
                'prompt' => 'Search entities for "{{entity_search_query}}".',
                'setup' => 'prepare_entity_scenario',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_count_gte',
                        'field' => 'entities',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'matching entit(y/ies)',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'previewdata.query',
                        'value' => '{{entity_search_query}}',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'examples.multistep_example' => [
                'prompt' => 'Run the examples.multistep_example task with objective "{{example_objective}}" '
                    . 'and the steps "{{example_step_one}}", "{{example_step_two}}", and "{{example_step_three}}".',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-B] multistep example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wpl',
                    ],
                ],
            ],
            'examples.readonly_example' => [
                'prompt' => 'Run the examples.readonly_example task with query "{{example_query}}" and limit 2.',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-A] readonly example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'examples.spawn_child_example' => [
                'prompt' => 'Run the examples.spawn_child_example task with child label "{{child_label}}", '
                    . 'batch label "{{batch_label}}", and ticket id "{{ticket_id}}".',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-C-CHILD] spawn child example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wgr',
                    ],
                ],
            ],
            'examples.spawn_parent_example' => [
                'prompt' => 'Run the examples.spawn_parent_example task with batch label "{{batch_label}}" '
                    . 'and child count 2.',
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'final',
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => '[SCENARIO-C-PARENT] spawn parent example executed',
                    ],
                    [
                        'target' => 'debug',
                        'type' => 'debug_source_contains',
                        'value' => 'ac=wpl',
                    ],
                ],
            ],
        ];
    }
}
