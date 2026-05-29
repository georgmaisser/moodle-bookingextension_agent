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
                        'type' => 'field_contains',
                        'field' => 'detail',
                        'value' => 'booking',
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
                'prompt' => 'Run core.search_courses and set query to "{{course_fullname}}".',
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
            'mod_booking.add_price_category' => [
                'prompt' => 'Please add a new booking price category with identifier "matrix_{{batch_label}}" '
                    . 'and name "Matrix Price {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'observation_full',
                        'value' => 'Booking option created',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.analyze_rules' => [
                'setup' => 'prepare_booking_rules_service_scenario',
                'prompt' => 'Analyze booking rules for "booking confirmation" and summarize findings.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'observation_full',
                        'value' => 'Booking option created',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.book_users' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Please book {{teacher_fullname}} into booking option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.bulk_update_options' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Bulk update options matching "{{existing_option_name}}" and set maxanswers to 9.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.configure_booking_instance' => [
                'prompt' => 'Which booking settings can I configure in this activity? Please list the available fields and current values.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_option' => [
                'prompt' => 'Create one booking option called "Matrix canonical {{batch_label}}" for max 6 participants '
                    . 'tomorrow from 10:00 to 12:00.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_rule_from_template' => [
                'setup' => 'prepare_booking_rules_service_scenario',
                'prompt' => 'Create a booking rule from template "booking confirmation" named '
                    . '"Matrix rule {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_selflearning_option' => [
                'prompt' => 'Create a self-learning booking option called "Matrix canonical selflearning {{batch_label}}" '
                    . 'with max 8 participants and a learning duration of 14400 seconds.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_slotbooking_option' => [
                'prompt' => 'Create one slot booking option titled "Matrix canonical slots {{batch_label}}" with opening 10:00, '
                    . 'closing 12:00, valid from 2026-06-01 until 2026-06-30, duration 30 minutes, '
                    . 'max 1 participant per slot, and slot_day_3=true.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.diagnose_booking_issue' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Diagnose why {{teacher_fullname}} cannot book option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.diagnose_cancellation_issue' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Diagnose why cancellation might fail for option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.explain_docs_topic' => [
                'prompt' => 'Explain how to create a booking option and include key steps.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.get_option_details' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Show details for booking option {{existing_option_name}}.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.list_option_properties' => [
                'prompt' => 'List supported properties for mod_booking.create_option.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.search_options' => [
                'prompt' => 'Search booking options for "{{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_option' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Update booking option {{existing_option_id}} and set title to '
                    . '"Matrix canonical updated {{batch_label}}" with max 9 participants.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_rule_from_template' => [
                'setup' => 'prepare_booking_rules_service_scenario',
                'prompt' => 'Update booking rule "Birthday reminder" and rename it to '
                    . '"Matrix updated rule {{batch_label}}".',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_option' => [
                'prompt' => 'Create one normal booking option called "Matrix normal {{batch_label}}" '
                    . 'for max 5 participants from tomorrow 10:00 to 12:00.',
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
                        'field' => 'observation_full',
                        'value' => 'type=0',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_selflearning_option' => [
                'prompt' => 'Create a self-learning booking option called "Matrix selflearning {{batch_label}}" '
                    . 'with a duration of 4 hours, max 8 participants, and assign {{teacher_email}} as the teacher.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'observation_full',
                        'value' => 'Booking option created',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.create_slotbooking_option' => [
                'prompt' => 'Create a slot booking option called "Matrix slots {{batch_label}}" with opening 10:00, '
                    . 'closing 12:00, valid from 2026-06-01 to 2026-06-30, 30-minute slots, '
                    . 'max 1 participant per slot, and make it bookable every Wednesday.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_option' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Update booking option {{existing_option_id}} to title "Matrix normal updated {{batch_label}}", '
                    . 'max 9 participants, and set it to tomorrow 14:00 to 16:00 as a normal option.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_option' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Update booking option {{existing_option_id}} to title "Matrix selflearning updated {{batch_label}}" '
                    . 'with max 11 participants as self-learning.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'mod_booking.update_option' => [
                'setup' => 'prepare_update_option_scenario',
                'prompt' => 'Update booking option {{existing_option_id}} as slot booking with opening 09:00, closing 11:00, '
                    . 'valid from 2026-06-01 00:00 until 2026-06-30 23:59, duration 20 minutes, '
                    . 'max 2 participants per slot, and enable weekday slot_day_2.',
                'assertions' => [
                    [
                        'target' => 'final',
                        'type' => 'field_equals',
                        'field' => 'status',
                        'value' => 'executed',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
        ];
    }
}
