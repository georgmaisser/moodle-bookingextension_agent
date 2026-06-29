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

use bookingextension_agent\local\wizard\orchestrator;
use core_ai\aiactions\summarise_text;

if (class_exists('bookingextension_agent\local\wizard\orchestrator')) {
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

// Coexistence: when the standalone local_wizard plugin owns the engine, every knob on this page is
// inert (local_wizard carries its own settings). Rather than offer admins controls that do nothing,
// replace the page body with a single notice and stop building it here. This is a permanent no-op
// until local_wizard exists, and reverts automatically if local_wizard is removed.
if (\bookingextension_agent\local\wizard\services\security\authorization_service::local_wizard_is_active()) {
    $aisettingspage->add(
        new admin_setting_heading(
            'bookingextension_agent_handedover_notice',
            get_string('settings_handedover_heading', 'bookingextension_agent'),
            get_string('settings_handedover_desc', 'bookingextension_agent')
        )
    );
    $adminroot->add('modbookingfolder', $aisettingspage);
    return;
}

// One-time announcement of the Wunderbyte Agent. The whole notice lives on a single checkbox so it
// also shows on the post-upgrade "new settings" review page: that page only lists storable settings,
// so a separate admin_setting_heading (pure info) would be skipped there. The checkbox name carries
// the headline and its description carries the announcement body; ticking it (hideupdatehint = 1)
// dismisses the notice. An unset value counts as "not yet dismissed", so it appears once after the
// feature ships. Kept entirely in settings.php (no upgrade/version hook).
if ((int)get_config('bookingextension_agent', 'hideupdatehint') !== 1) {
    $aisettingspage->add(
        new admin_setting_configcheckbox(
            'bookingextension_agent/hideupdatehint',
            get_string('updatehint_heading', 'bookingextension_agent'),
            html_writer::tag(
                'p',
                html_writer::tag('strong', get_string('hideupdatehint_desc', 'bookingextension_agent'))
            )
                . get_string('updatehint_body', 'bookingextension_agent'),
            0
        )
    );
}

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

// Register the AI settings page now, before the agent's other (benchmark) page is registered below,
// so "AI settings" is listed first — including on the post-install "new settings" review page, which
// shows settings in page-registration order. Settings added to $aisettingspage further down are
// reflected because the admin tree keeps the page object by reference.
$adminroot->add('modbookingfolder', $aisettingspage);

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
    $accessservice = '\\bookingextension_agent\\local\\wizard\\services\\agent_access_service';
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
            'bookingextension_agent/inject_in_navbar',
            get_string('inject_in_navbar', 'bookingextension_agent'),
            get_string('inject_in_navbar_desc', 'bookingextension_agent'),
            1
        )
    );

    // Auto-generate a security token the first time so the [wizard] shortcode is
    // protected out of the box; an admin can rotate it by clearing/editing the field.
    $shortcodetoken = get_config('bookingextension_agent', 'shortcodetoken');
    if (empty($shortcodetoken)) {
        $shortcodetoken = random_string(8);
        set_config('shortcodetoken', $shortcodetoken, 'bookingextension_agent');
    }
    $aisettingspage->add(
        new admin_setting_configtext(
            'bookingextension_agent/shortcodetoken',
            get_string('shortcodetoken', 'bookingextension_agent'),
            get_string('shortcodetoken_desc', 'bookingextension_agent'),
            $shortcodetoken,
            PARAM_ALPHANUM
        )
    );

    // Seed the two default corpora (agent + mod_booking) the first time only, so existing sites
    // that predate the textarea setting also get the out-of-the-box documentation sources. A
    // deliberately-emptied value (saved as '') is preserved.
    if (get_config('bookingextension_agent', 'aidocsroot') === false) {
        set_config('aidocsroot', "bookingextension_agent\nmod_booking", 'bookingextension_agent');
    }

    // E4 indicator: show whether the docs skill is active (embeddings only run when it is), with a
    // link to the skill governance page where it is toggled.
    $docsskillgovurl = new moodle_url('/mod/booking/bookingextension/agent/skill_governance.php');
    if (\bookingextension_agent\local\wizard\services\lookup\docs_embeddings_gate::is_docs_skill_active()) {
        $docsskillindicator = get_string('aidocsroot_skill_active', 'bookingextension_agent');

        // Per-corpus breakdown so admins see exactly which documentation is indexed and which
        // configured corpus is still waiting to be embedded (read-only; never triggers a rebuild).
        $docssummary = (new \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service())
            ->get_corpus_index_summary();
        if (!$docssummary['provideravailable']) {
            $docsskillindicator .= html_writer::div(
                get_string('aidocsroot_index_provider_missing', 'bookingextension_agent'),
                'mt-2'
            );
        } else if (empty($docssummary['corpora'])) {
            $docsskillindicator .= html_writer::div(
                get_string('aidocsroot_index_empty', 'bookingextension_agent'),
                'mt-2'
            );
        } else {
            $docscorpusitems = [];
            foreach ($docssummary['corpora'] as $docscorpus) {
                $docscorpusitems[] = get_string(
                    'aidocsroot_corpus_' . $docscorpus['state'],
                    'bookingextension_agent',
                    (object)[
                        'corpus' => s($docscorpus['corpusid']),
                        'documents' => $docscorpus['documents'],
                        'chunks' => $docscorpus['chunks'],
                    ]
                );
            }
            $docsskillindicator .= html_writer::div(
                get_string('aidocsroot_index_heading', 'bookingextension_agent'),
                'fw-bold mt-2'
            ) . html_writer::alist($docscorpusitems, ['class' => 'mb-0']);
        }
    } else {
        $docsskillindicator = get_string(
            'aidocsroot_skill_inactive',
            'bookingextension_agent',
            html_writer::link($docsskillgovurl, get_string('skillgovernance', 'bookingextension_agent'))
        );
    }
    $docscorporadesc = get_string('aidocsroot_desc', 'bookingextension_agent')
        . html_writer::div($docsskillindicator, 'alert alert-info mt-2');

    $docscorpora = new \bookingextension_agent\admin\setting_docs_corpora(
        'bookingextension_agent/aidocsroot',
        get_string('aidocsroot', 'bookingextension_agent'),
        $docscorporadesc,
        "bookingextension_agent\nmod_booking",
        PARAM_RAW
    );
    // Fast path: a corpus change schedules an (gated) incremental rebuild straight away.
    $docscorpora->set_updatedcallback(
        '\\bookingextension_agent\\local\\wizard\\services\\lookup\\docs_embeddings_readiness_service::on_corpus_setting_updated'
    );
    $aisettingspage->add($docscorpora);

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
            'bookingextension_agent/aidebugmode',
            get_string('aidebugmode', 'bookingextension_agent'),
            get_string('aidebugmode_desc', 'bookingextension_agent'),
            0
        )
    );

    // Queue depends_on DAG validation and blocked-confirmation TTL expiry are always on (no admin
    // toggle). Governance strict mode (fail registry init on contract diagnostics) is a CI/dev tool,
    // not an admin setting — it stays off by default and can be forced via set_config in CI.
    // Preflight audit logging was retired (wrote to thread metadata, never surfaced) — no setting.

    // These agent admin pages are kept out of the mod/booking settings category page (and the admin
    // tree) via hidden=true, so the category view only shows "AI settings". They stay reachable by URL
    // through the "Agent admin pages" table of contents inside the AI settings page.
    $adminroot->add('modbookingfolder', new admin_externalpage(
        'bookingextension_agent_skillgovernance',
        get_string('skillgovernance', 'bookingextension_agent'),
        $skillgovurl,
        'moodle/site:config',
        true
    ));

    $adminroot->add('modbookingfolder', new admin_externalpage(
        'bookingextension_agent_skillselectiondebug',
        get_string('skillselectiondebug', 'bookingextension_agent'),
        $skillselectiondebugurl,
        'bookingextension/agent:debugskillselection',
        true
    ));

    $adminroot->add('modbookingfolder', new admin_externalpage(
        'bookingextension_agent_benchmarkreport',
        get_string('benchmark_report_nav', 'bookingextension_agent'),
        $benchmarkreporturl,
        'bookingextension/agent:viewbenchmarks',
        true
    ));

    // Benchmark settings.
    // Hidden from the category/tree (reachable via the AI settings table of contents); the category
    // page should only show "AI settings".
    $benchmarkpage = new admin_settingpage(
        'bookingextension_agent_benchmark',
        get_string('benchmark_settings_nav', 'bookingextension_agent'),
        'moodle/site:config',
        true
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
