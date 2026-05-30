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
                'prompt' => 'Wer bin ich?',
                'allow_direct_answer' => true,
                'assertions' => [
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.list_actions' => [
                'prompt' => 'Welche Aktionen stehen mir hier im Buchungskontext zur Verfuegung? Bitte nenne sie mir geordnet.',
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
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'core.recreate_task_catalog' => [
                'prompt' => 'Bitte fuehre jetzt die Admin-Aktion core.recreate_task_catalog aus und plane den Neuaufbau des Task-Katalogs.',
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
                'prompt' => 'Suche bitte nach dem Kurs "{{course_fullname}}".',
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
            'core.search_users' => [
                'prompt' => 'Search users with the query "{{teacher_email}}" and return the best match.',
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
            'entities.create_entity' => [
                'prompt' => 'Create a new entity called "{{entity_name}}" with shortname "{{entity_name}}".',
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
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'entities.list_all_entities' => [
                'prompt' => 'Use entities.list_all_entities and list entities with a limit of 5.',
                'setup' => 'prepare_entity_scenario',
                'allow_direct_answer' => true,
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
                        'value' => 'entities',
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
                'prompt' => 'Use entities.search to find entities for "{{entity_search_query}}" and return matches.',
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
                        'value' => 'matching entities',
                    ],
                    [
                        'target' => 'chat',
                        'type' => 'step_count_gte',
                        'value' => 1,
                    ],
                ],
            ],
            'examples.multistep_example' => [
                'prompt' => 'Ich brauche Hilfe bei folgendem Vorhaben: "{{example_objective}}". '
                    . 'Bitte gehe dabei in diesen Schritten vor: "{{example_step_one}}", "{{example_step_two}}" und "{{example_step_three}}".',
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
                'prompt' => 'Zeig mir bitte zu "{{example_query}}" genau zwei passende Ergebnisse.',
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
                'prompt' => 'Bitte starte einen neuen Arbeitsschritt mit der Bezeichnung "{{child_label}}" '
                    . 'in der Sammelaktion "{{batch_label}}" und nutze dabei die Ticketnummer "{{ticket_id}}".',
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
                'prompt' => 'Bitte fasse zwei zugehoerige Teilaufgaben unter der Sammelbezeichnung "{{batch_label}}" zusammen.',
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
                    . 'and name "Booking Price {{batch_label}}".',
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
                'prompt' => 'Bulk update the option with id {{existing_option_id}} and set maxanswers to 9.',
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
                'prompt' => 'Create exactly one standard booking option (type 0) titled "Workshop {{batch_label}}" '
                    . 'for maxanswers 6. Use only coursestarttime and courseendtime set to tomorrow 10:00 and '
                    . 'tomorrow 12:00.',
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
                    . '"Booking rule {{batch_label}}".',
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
                'prompt' => 'Create a self-learning booking option called "Learning session {{batch_label}}" '
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
                'prompt' => 'Create one slot booking option titled "Consultation slots {{batch_label}}" with opening 10:00, '
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
                'prompt' => 'Diagnose cancellation for booking option id {{existing_option_id}} '
                    . '({{existing_option_name}}) and explain why a participant may not be able to cancel right now.',
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
                'prompt' => 'Explain in three concise steps how to create a booking option, based on the booking docs.',
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
                'prompt' => 'Show details for booking option id {{existing_option_id}} ({{existing_option_name}}).',
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
                'prompt' => 'Welche Angaben brauche ich, um eine Buchungsmoeglichkeit anzulegen?',
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
                'prompt' => 'Update the booking option "{{existing_option_name}}" and set the title to '
                    . '"Updated booking {{batch_label}}" with max 9 participants.',
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
                    . '"Updated booking rule {{batch_label}}".',
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
