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
use core_ai\aiactions\summarise_text;

if (class_exists('bookingextension_agent\local\wbagent\orchestrator')) {
    $defaultsummarypromptprefix = orchestrator::get_default_summary_prompt_prefix();
    $defaultplannerprompttemplate = orchestrator::get_default_initial_prompt_template_for_action(summarise_text::class);
} else {
    $defaultsummarypromptprefix = '';
    $defaultplannerprompttemplate = '';
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
    new admin_setting_configcheckbox(
        'bookingextension_agent/agent_enabled',
        get_string('agent_enabled', 'bookingextension_agent'),
        get_string('agent_enabled_desc', 'bookingextension_agent'),
        1
    )
);

// The trial/LiteLLM service base URL is hard-coded in trial_provisioner
// (https://llm.wunderbyte.at) and intentionally NOT exposed as an admin setting.

// Default is on: an unsaved (false) value must count as enabled.
$agentenabledraw = get_config('bookingextension_agent', 'agent_enabled');
$agentenabled = $agentenabledraw === false || !empty($agentenabledraw);

if ($agentenabled) {
    // Admin pages that belong to the agent. Listed right below the enable
    // checkbox so it is clear they are only available while the agent is on.
    $skillgovurl = new moodle_url('/mod/booking/bookingextension/agent/skill_governance.php');
    $skillselectiondebugurl = new moodle_url('/mod/booking/bookingextension/agent/skill_selection_debug.php');
    $benchmarkreporturl = new moodle_url('/mod/booking/bookingextension/agent/benchmark_report.php');
    $benchmarksettingsurl = new moodle_url('/admin/settings.php', ['section' => 'bookingextension_agent_benchmark']);
    $agentpageslist = html_writer::alist([
        html_writer::link($skillgovurl, get_string('skillgovernance', 'bookingextension_agent')),
        html_writer::link($skillselectiondebugurl, get_string('skillselectiondebug', 'bookingextension_agent')),
        html_writer::link($benchmarkreporturl, get_string('benchmark_report_nav', 'bookingextension_agent')),
        html_writer::link($benchmarksettingsurl, get_string('benchmark_settings_nav', 'bookingextension_agent')),
    ]);
    $aisettingspage->add(
        new admin_setting_heading(
            'bookingextension_agent_adminpages_heading',
            get_string('agent_adminpages', 'bookingextension_agent'),
            get_string('agent_adminpages_desc', 'bookingextension_agent') . $agentpageslist
        )
    );

    // License key (same mechanism as Booking PRO; products 'wbagent' or combined
    // 'bookingagent'). A combined key in Booking's licensekey field also unlocks
    // the agent — the status text reflects whichever candidate key is valid.
    $licensekeydesc = get_string('licensekeydesc', 'bookingextension_agent');
    if (class_exists('bookingextension_agent\local\wb_license')) {
        $ownkey = trim((string)get_config('bookingextension_agent', 'licensekey'));
        if ($ownkey !== '') {
            $license = \bookingextension_agent\local\wb_license::parse_licensekey_for_agent($ownkey);
            if ($license['validforagent']) {
                $licensekeydesc = "<p style='color: green; font-weight: bold'>"
                    . get_string('licenseactivated', 'bookingextension_agent', $license['expirationdate'])
                    . '</p>';
            } else if (
                $license['expirationdate'] !== ''
                && in_array($license['product'], [
                    \bookingextension_agent\local\wb_license::PRODUCT_AGENT,
                    \bookingextension_agent\local\wb_license::PRODUCT_BOOKING_AGENT,
                ], true)
            ) {
                $licensekeydesc = "<p style='color: red; font-weight: bold'>"
                    . get_string('licenseexpired', 'bookingextension_agent', $license['expirationdate'])
                    . '</p>';
            } else {
                $licensekeydesc = "<p style='color: red; font-weight: bold'>"
                    . get_string('licenseinvalid', 'bookingextension_agent')
                    . '</p>';
            }
        } else if (\bookingextension_agent\local\wb_license::agent_license_is_activated()) {
            // Unlocked via a combined key in Booking's licensekey setting.
            $licensekeydesc = "<p style='color: green; font-weight: bold'>"
                . get_string('licenseactivatedviabooking', 'bookingextension_agent')
                . '</p>';
        }
    }

    // If no PRO license grants access but the agent's LLM calls run over the Wunderbyte LLM
    // endpoint (active trial or subscription at llm.wunderbyte.at), make clear that a Pro license
    // is not currently required. Note: trial vs. subscription cannot be distinguished Moodle-side -
    // both simply route through the Wunderbyte endpoint; enforcement happens at the gateway.
    $accessservice = '\\bookingextension_agent\\local\\wbagent\\services\\agent_access_service';
    if (
        class_exists('bookingextension_agent\\local\\wb_license')
        && class_exists($accessservice)
        && !\bookingextension_agent\local\wb_license::agent_license_is_activated()
        && $accessservice::runs_on_wunderbyte_llm()
    ) {
        $licensekeydesc = "<p style='color: green; font-weight: bold'>"
            . get_string('licensenotrequiredviaendpoint', 'bookingextension_agent')
            . '</p>' . $licensekeydesc;
    }

    $aisettingspage->add(
        new admin_setting_configtext(
            'bookingextension_agent/licensekey',
            get_string('licensekey', 'bookingextension_agent'),
            $licensekeydesc,
            ''
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
        new admin_setting_configcheckbox(
            'bookingextension_agent/inject_in_navbar',
            get_string('inject_in_navbar', 'bookingextension_agent'),
            get_string('inject_in_navbar_desc', 'bookingextension_agent'),
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
        new admin_setting_configtextarea(
            'bookingextension_agent/aiprivacyprotectedwords',
            get_string('aiprivacyprotectedwords', 'bookingextension_agent'),
            get_string('aiprivacyprotectedwords_desc', 'bookingextension_agent'),
            get_string('aiprivacyprotectedwords_default', 'bookingextension_agent'),
            PARAM_RAW,
            60,
            4
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

    $adminroot->add('modbookingfolder', new admin_externalpage(
        'bookingextension_agent_skillgovernance',
        get_string('skillgovernance', 'bookingextension_agent'),
        $skillgovurl,
        'moodle/site:config'
    ));

    $adminroot->add('modbookingfolder', new admin_externalpage(
        'bookingextension_agent_skillselectiondebug',
        get_string('skillselectiondebug', 'bookingextension_agent'),
        $skillselectiondebugurl,
        'bookingextension/agent:debugskillselection'
    ));

    $adminroot->add('modbookingfolder', new admin_externalpage(
        'bookingextension_agent_benchmarkreport',
        get_string('benchmark_report_nav', 'bookingextension_agent'),
        $benchmarkreporturl,
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
}

$adminroot->add('modbookingfolder', $aisettingspage);
