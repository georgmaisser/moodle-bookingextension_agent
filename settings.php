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
 * Admin settings for the bookingextension_agent plugin.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\skill_registry;
use bookingextension_agent\local\wbagent\skill_registry_factory;
use core_ai\aiactions\summarise_text;

global $CFG;

if (class_exists('bookingextension_agent\local\wbagent\orchestrator')) {
    $defaultsummarypromptprefix = orchestrator::get_default_summary_prompt_prefix();
    $defaultplannerprompttemplate = orchestrator::get_default_initial_prompt_template_for_action(summarise_text::class);
} else {
    $defaultsummarypromptprefix = '';
    $defaultplannerprompttemplate = '';
}

if (get_config('bookingextension_agent', 'aiinitialprompt_discovery') === false) {
    set_config('aiinitialprompt_discovery', $defaultplannerprompttemplate, 'bookingextension_agent');
}

if (get_config('bookingextension_agent', 'aiinitialprompt_selection') === false) {
    set_config('aiinitialprompt_selection', $defaultplannerprompttemplate, 'bookingextension_agent');
}

if (get_config('bookingextension_agent', 'aiinitialprompt_parameter_construction') === false) {
    set_config('aiinitialprompt_parameter_construction', $defaultplannerprompttemplate, 'bookingextension_agent');
}

if (get_config('bookingextension_agent', 'aiinitialprompt_summarise_text') === false) {
    set_config('aiinitialprompt_summarise_text', $defaultsummarypromptprefix, 'bookingextension_agent');
}

$aisettingspage = new admin_settingpage(
    'bookingextension_agent_aisettings',
    get_string('aisettings', 'bookingextension_agent'),
    'moodle/site:config'
);

$aisettingspage->add(
    new admin_setting_heading(
        'bookingextension_agent_aisettings_heading',
        get_string('aisettings', 'bookingextension_agent'),
        get_string('aisettings_desc', 'bookingextension_agent')
    )
);

$aisettingspage->add(
    new admin_setting_configselect(
        'bookingextension_agent/aiexecutionmode',
        get_string('aiexecutionmode', 'bookingextension_agent'),
        get_string('aiexecutionmode_desc', 'bookingextension_agent'),
        'direct',
        [
            'direct' => get_string('aiexecutionmode_direct', 'bookingextension_agent'),
            'adhoc' => get_string('aiexecutionmode_adhoc', 'bookingextension_agent'),
        ]
    )
);

$aisettingspage->add(
    new admin_setting_configcheckbox(
        'bookingextension_agent/aidebugmode',
        get_string('aidebugmode', 'bookingextension_agent'),
        get_string('aidebugmode_desc', 'bookingextension_agent'),
        0
    )
);

$aisettingspage->add(
    new admin_setting_configtext(
        'bookingextension_agent/aidocsroot',
        get_string('aidocsroot', 'bookingextension_agent'),
        get_string('aidocsroot_desc', 'bookingextension_agent'),
        '',
        PARAM_TEXT
    )
);

$aisettingspage->add(
    new admin_setting_configtext(
        'bookingextension_agent/aidocsentry',
        get_string('aidocsentry', 'bookingextension_agent'),
        get_string('aidocsentry_desc', 'bookingextension_agent'),
        'README.md',
        PARAM_TEXT
    )
);

$aisettingspage->add(
    new admin_setting_configselect(
        'bookingextension_agent/aiprivacymode',
        get_string('aiprivacymode', 'bookingextension_agent'),
        get_string('aiprivacymode_desc', 'bookingextension_agent'),
        'strict',
        [
            'off' => get_string('aiprivacymode_off', 'bookingextension_agent'),
            'soft' => get_string('aiprivacymode_soft', 'bookingextension_agent'),
            'strict' => get_string('aiprivacymode_strict', 'bookingextension_agent'),
        ]
    )
);

$aisettingspage->add(
    new admin_setting_configtext(
        'bookingextension_agent/aifollowupsuggestionscount',
        get_string('aifollowupsuggestionscount', 'bookingextension_agent'),
        get_string('aifollowupsuggestionscount_desc', 'bookingextension_agent'),
        '0',
        PARAM_INT
    )
);

$aisettingspage->add(
    new admin_setting_configtextarea(
        'bookingextension_agent/aiinitialprompt_discovery',
        get_string('aiinitialprompt_discovery', 'bookingextension_agent'),
        get_string('aiinitialprompt_discovery_desc', 'bookingextension_agent'),
        $defaultplannerprompttemplate,
        PARAM_RAW,
        120,
        8
    )
);

$aisettingspage->add(
    new admin_setting_configtextarea(
        'bookingextension_agent/aiinitialprompt_selection',
        get_string('aiinitialprompt_selection', 'bookingextension_agent'),
        get_string('aiinitialprompt_selection_desc', 'bookingextension_agent'),
        $defaultplannerprompttemplate,
        PARAM_RAW,
        120,
        8
    )
);

$aisettingspage->add(
    new admin_setting_configtextarea(
        'bookingextension_agent/aiinitialprompt_parameter_construction',
        get_string('aiinitialprompt_parameter_construction', 'bookingextension_agent'),
        get_string('aiinitialprompt_parameter_construction_desc', 'bookingextension_agent'),
        $defaultplannerprompttemplate,
        PARAM_RAW,
        120,
        8
    )
);

$aisettingspage->add(
    new admin_setting_configtextarea(
        'bookingextension_agent/aiinitialprompt_summarise_text',
        get_string('aiinitialprompt_summarise_text', 'bookingextension_agent'),
        get_string('aiinitialprompt_summarise_text_desc', 'bookingextension_agent'),
        $defaultsummarypromptprefix,
        PARAM_RAW,
        120,
        8
    )
);

$aisettingspage->add(
    new admin_setting_configcheckbox(
        'bookingextension_agent/aigovernancestrictmode',
        get_string('aigovernancestrictmode', 'bookingextension_agent'),
        get_string('aigovernancestrictmode_desc', 'bookingextension_agent'),
        0
    )
);

$aisettingspage->add(
    new admin_setting_configcheckbox(
        'bookingextension_agent/queue_dag_validation_enabled',
        get_string('queue_dag_validation_enabled', 'bookingextension_agent'),
        get_string('queue_dag_validation_enabled_desc', 'bookingextension_agent'),
        1
    )
);

$aisettingspage->add(
    new admin_setting_configcheckbox(
        'bookingextension_agent/queue_blocked_ttl_enabled',
        get_string('queue_blocked_ttl_enabled', 'bookingextension_agent'),
        get_string('queue_blocked_ttl_enabled_desc', 'bookingextension_agent'),
        1
    )
);

$aisettingspage->add(
    new admin_setting_configcheckbox(
        'bookingextension_agent/preflight_audit_enabled',
        get_string('preflight_audit_enabled', 'bookingextension_agent'),
        get_string('preflight_audit_enabled_desc', 'bookingextension_agent'),
        0
    )
);

$aisettingspage->add(
    new admin_setting_heading(
        'bookingextension_agent_aiskillgovernance_heading',
        get_string('aiskillgovernanceheading', 'bookingextension_agent'),
        get_string('aiskillgovernanceheading_desc', 'bookingextension_agent')
    )
);

try {
    $registry = skill_registry_factory::get_default();
    $contracts = $registry->get_skill_contracts();
    ksort($contracts);

    foreach ($contracts as $skillname => $meta) {
        $capabilities = (array)($meta['capabilities'] ?? []);
        $capabilitylabel = implode(', ', $capabilities);
        if ($capabilitylabel === '') {
            $capabilitylabel = '-';
        }

        $settingkey = 'bookingextension_agent/' . skill_registry::get_skill_toggle_setting_name((string)$skillname);
        $settingtitle = get_string('aiskillenabled_label', 'bookingextension_agent', (string)$skillname);
        $settingdesc = get_string(
            'aiskillenabled_desc',
            'bookingextension_agent',
            (object)[
                'component' => (string)($meta['component'] ?? ''),
                'capability' => $capabilitylabel,
            ]
        );

        $aisettingspage->add(
            new admin_setting_configcheckbox(
                $settingkey,
                $settingtitle,
                $settingdesc,
                0
            )
        );
    }
} catch (\Throwable $e) {
    $aisettingspage->add(
        new admin_setting_heading(
            'bookingextension_agent_aiskillgovernance_unavailable',
            get_string('aiskillgovernanceunavailable', 'bookingextension_agent'),
            get_string('aiskillgovernanceunavailable_desc', 'bookingextension_agent')
        )
    );
}

// Enable all is added last so Moodle processes individual skill toggles first.
// When this checkbox fires its callback, all per-skill configs are already written
// to the DB and the sync can safely overwrite them with 1.
$enableallsetting = new admin_setting_configcheckbox(
    'bookingextension_agent/aiskillenableall',
    get_string('aiskillenableall', 'bookingextension_agent'),
    get_string('aiskillenableall_desc', 'bookingextension_agent'),
    0
);
$enableallsetting->set_updatedcallback(
    '\\bookingextension_agent\\local\\wbagent\\skill_governance_service::sync_enableall_toggles'
);
$aisettingspage->add($enableallsetting);

$adminroot->add('modbookingfolder', new admin_externalpage(
    'bookingextension_agent_skillselectiondebug',
    get_string('skillselectiondebug', 'bookingextension_agent'),
    new moodle_url('/mod/booking/bookingextension/agent/skill_selection_debug.php'),
    'bookingextension/agent:debugskillselection'
));

$adminroot->add('modbookingfolder', new admin_externalpage(
    'bookingextension_agent_benchmarkreport',
    get_string('benchmark_report_nav', 'bookingextension_agent'),
    new moodle_url('/mod/booking/bookingextension/agent/benchmark_report.php'),
    'bookingextension/agent:viewbenchmarks'
));

// Benchmark settings.
$benchmarkpage = new admin_settingpage(
    'bookingextension_agent_benchmark',
    get_string('benchmark_settings_nav', 'bookingextension_agent'),
    'moodle/site:config',
    !$hassiteconfig
);

$benchmarkpage->add(new admin_setting_configtext(
    'bookingextension_agent/benchmark_retention_days',
    get_string('benchmark_retention_days', 'bookingextension_agent'),
    get_string('benchmark_retention_days_desc', 'bookingextension_agent'),
    '365',
    PARAM_INT
));

$benchmarkpage->add(new admin_setting_configtext(
    'bookingextension_agent/benchmark_threshold_skill_hit_rate',
    get_string('benchmark_threshold_skill_hit_rate', 'bookingextension_agent'),
    get_string('benchmark_threshold_skill_hit_rate_desc', 'bookingextension_agent'),
    '90',
    PARAM_FLOAT
));

$benchmarkpage->add(new admin_setting_configtext(
    'bookingextension_agent/benchmark_threshold_json_validity',
    get_string('benchmark_threshold_json_validity', 'bookingextension_agent'),
    get_string('benchmark_threshold_json_validity_desc', 'bookingextension_agent'),
    '99',
    PARAM_FLOAT
));

$benchmarkpage->add(new admin_setting_configtext(
    'bookingextension_agent/benchmark_threshold_e2e_success',
    get_string('benchmark_threshold_e2e_success', 'bookingextension_agent'),
    get_string('benchmark_threshold_e2e_success_desc', 'bookingextension_agent'),
    '85',
    PARAM_FLOAT
));

$adminroot->add('modbookingfolder', $benchmarkpage);
$adminroot->add('modbookingfolder', $aisettingspage);
