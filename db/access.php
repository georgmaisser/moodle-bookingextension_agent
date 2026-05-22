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
 * Capabilities for bookingextension_agent tasks.
 *
 * @package    bookingextension_agent
 * @category   access
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'bookingextension/agent:useaiinstructions' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
        ],
    ],
];

$teachertasks = [
    'booking_add_price_category',
    'booking_book_users',
    'booking_bulk_update_options',
    'booking_core_get_activity_completion_status',
    'booking_core_get_course_overview',
    'booking_core_get_current_user',
    'booking_core_get_group_members',
    'booking_core_get_module_details',
    'booking_core_list_course_calendar_events',
    'booking_core_list_course_groups',
    'booking_core_list_course_modules',
    'booking_core_list_course_participants',
    'booking_core_list_course_sections',
    'booking_core_list_grade_items',
    'booking_core_list_user_calendar_events',
    'booking_create_option',
    'booking_create_selflearning_option',
    'booking_create_slotbooking_option',
    'booking_diagnose_booking_issue',
    'booking_diagnose_cancellation_issue',
    'booking_explain_docs_topic',
    'booking_explain_task_schema',
    'booking_get_current_user',
    'booking_get_option_details',
    'booking_list_actions',
    'booking_list_option_properties',
    'booking_recall_memory',
    'booking_search_courses',
    'booking_search_options',
    'booking_search_users',
    'booking_update_option',
];

$managertasks = [
    'booking_analyze_rules',
    'booking_configure_booking_instance',
    'booking_core_create_calendar_event',
    'booking_core_create_group',
    'booking_core_delete_calendar_event',
    'booking_core_delete_group',
    'booking_core_enrol_user_manual',
    'booking_core_get_site_summary',
    'booking_core_get_user_completion_report',
    'booking_core_get_user_enrolments',
    'booking_core_get_user_grades_for_course',
    'booking_core_get_user_preferences',
    'booking_core_get_user_profile',
    'booking_core_get_user_roles_in_course',
    'booking_core_search_course_enrolments',
    'booking_core_send_user_message',
    'booking_core_set_user_preference',
    'booking_core_unenrol_user_manual',
    'booking_core_update_calendar_event',
    'booking_core_update_group',
    'booking_create_rule_from_template',
    'booking_update_rule_from_template',
];

$adminonlytasks = [
    'booking_create_user',
    'booking_recreate_task_catalog',
];

$buildtaskcapability = static function (string $tasksuffix, string $role): array {
    $definition = [
        'riskbitmask' => RISK_DATALOSS | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
    ];

    if ($role === 'teacher') {
        $definition['captype'] = 'write';
        $definition['contextlevel'] = CONTEXT_MODULE;
        $definition['archetypes'] = [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ];
    } else if ($role === 'manager') {
        $definition['captype'] = 'write';
        $definition['contextlevel'] = CONTEXT_MODULE;
        $definition['archetypes'] = [
            'manager' => CAP_ALLOW,
        ];
    }
    return ['bookingextension/agent:task_' . $tasksuffix => $definition];
};

foreach ($teachertasks as $tasksuffix) {
    $capabilities += $buildtaskcapability($tasksuffix, 'teacher');
}

foreach ($managertasks as $tasksuffix) {
    $capabilities += $buildtaskcapability($tasksuffix, 'manager');
}

foreach ($adminonlytasks as $tasksuffix) {
    $capabilities += $buildtaskcapability($tasksuffix, 'admin');
}
