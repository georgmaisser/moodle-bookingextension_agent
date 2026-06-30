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
 * Capabilities for bookingextension_agent skills.
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
            // Managers can use the agent too, so the global navbar magic wand (gated by
            // agent:seemagicwand, manager-only) is functional for them.
            'manager' => CAP_ALLOW,
        ],
    ],
    // Visibility of the GLOBAL navbar "magic wand" entry point. It appears on every page and is a
    // more privileged, site-wide entry than the per-module Booking Wizard (useaiinstructions), so
    // its visibility is restricted to managers (site admins pass via moodle/site:doanything). This
    // only governs whether the wand is shown; actual agent usage is still gated by
    // useaiinstructions on every server-side call.
    'bookingextension/agent:seemagicwand' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'bookingextension/agent:debugskillselection' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Availability bypass: holders ignore the course/module "enableaitools" toggles.
    // The toggles are an AVAILABILITY layer aimed at non-privileged users (teachers,
    // later students) — site admins pass implicitly via moodle/site:doanything, and
    // managers get it by default. Assignable per course (category), so an admin can
    // also grant it to selected trusted teachers. See
    // docs/Blueprints/agent_permissions_concept_2026-06-10.md.
    'bookingextension/agent:ignoreaiavailability' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Start the guided AI trial setup (request a Wunderbyte trial key and create the
    // provider instance). Site-level action: admins pass via moodle/site:doanything,
    // and managers are granted explicitly so they can onboard without full site config.
    'bookingextension/agent:requesttrial' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];

$teacherskills = [
    'examples_multistep_example',
    'examples_readonly_example',
    'examples_spawn_child_example',
    'examples_spawn_parent_example',
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
    'booking_diagnose_user_booking',
    'course_add_activity',
    'course_update_activity',
    'course_add_quiz',
    'course_update_quiz',
    'course_diagnose_user_in_course',
    'core_diagnose_permissions',
    'core_diagnose_notifications',
    'course_analyze_course_structure',
    'wizard_explain_docs',
    'booking_explain_skill_schema',
    'core_get_current_user',
    'booking_get_option_details',
    'wizard_list_skills',
    'booking_list_option_properties',
    'wizard_recall_memory',
    'wizard_scaffold_skill',
    'course_search_courses',
    'booking_search_options',
    'core_search_users',
    'booking_update_option',
    'wizard_search_skills',
    'question_generate_questions',
];

$managerskills = [
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
    // Rebuilds the site-global skill-catalog embeddings (cost-bearing) — manager/admin only,
    // not teacher-grantable (audit CAP-03). Execution additionally requires moodle/site:config
    // via the skill's native capability (Gate 2).
    'wizard_recreate_skill_catalog',
];

$adminonlyskills = [
    'booking_create_user',
];

// Authorized-user skills: act only on the acting user's own data (e.g. their stored agent
// memories), so any authenticated user permitted to use the agent may run them.
$authorizeduserskills = [
    'wizard_forget',
    'wizard_list_memories',
    'wizard_remember',
];

$buildskillcapability = static function (string $skillsuffix, string $role): array {
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
    } else if ($role === 'authorizeduser') {
        $definition['captype'] = 'write';
        $definition['contextlevel'] = CONTEXT_MODULE;
        $definition['archetypes'] = [
            'user' => CAP_ALLOW,
        ];
    }
    return ['bookingextension/agent:skill_' . $skillsuffix => $definition];
};

foreach ($teacherskills as $skillsuffix) {
    $capabilities += $buildskillcapability($skillsuffix, 'teacher');
}

foreach ($managerskills as $skillsuffix) {
    $capabilities += $buildskillcapability($skillsuffix, 'manager');
}

foreach ($adminonlyskills as $skillsuffix) {
    $capabilities += $buildskillcapability($skillsuffix, 'admin');
}

foreach ($authorizeduserskills as $skillsuffix) {
    $capabilities += $buildskillcapability($skillsuffix, 'authorizeduser');
}

// Benchmark capabilities.
$capabilities['bookingextension/agent:viewbenchmarks'] = [
    'captype'      => 'read',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [
        'manager' => CAP_ALLOW,
    ],
];

$capabilities['bookingextension/agent:managebenchmarks'] = [
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
];

// Run a benchmark from the interface (the "Run benchmark" button on benchmark_report.php). Distinct
// from viewbenchmarks (reading reports): a live run issues real LLM calls and consumes credits, so it
// is admin-only by default (empty archetypes) and must be granted explicitly to delegate it.
$capabilities['bookingextension/agent:runbenchmarks'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
];

// Skill Governance & Analysis admin page: inspect skill contracts, enable/disable skills and
// rebuild the skill embedding catalog. This is a site-wide, config-style action. Previously the
// page was gated by moodle/site:config (admin-only); it now has its own capability so the page can
// be delegated to managers without granting full site config. Admins still pass implicitly via
// moodle/site:doanything.
$capabilities['bookingextension/agent:managegovernance'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [
        'manager' => CAP_ALLOW,
    ],
];

// Configure the site-wide AI provider credentials (store an API key, adopt an existing provider) used
// by the agent. This writes site-global secrets, so it is admin-only by default (empty archetypes) and
// must be granted explicitly to delegate it — there is deliberately no automatic role assignment.
// Previously these endpoints reused the manager-grantable requesttrial cap (audit 15-F02).
$capabilities['bookingextension/agent:manageaiproviders'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
];
