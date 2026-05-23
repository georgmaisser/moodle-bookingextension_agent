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

defined('MOODLE_INTERNAL') || die();

use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\task_registry_factory;

global $CFG;

if (class_exists('bookingextension_agent\local\wbagent\orchestrator')) {
    $defaultsummarypromptprefix = orchestrator::get_default_summary_prompt_prefix();
} else {
    $defaultsummarypromptprefix = '';
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
        'bookingextension_agent_aitaskgovernance_heading',
        get_string('aitaskgovernanceheading', 'bookingextension_agent'),
        get_string('aitaskgovernanceheading_desc', 'bookingextension_agent')
    )
);

$aisettingspage->add(
    new admin_setting_configcheckbox(
        'bookingextension_agent/aitaskenableall',
        get_string('aitaskenableall', 'bookingextension_agent'),
        get_string('aitaskenableall_desc', 'bookingextension_agent'),
        0
    )
);

try {
    $registry = task_registry_factory::get_default();
    $contracts = $registry->get_task_contracts();
    ksort($contracts);

    foreach ($contracts as $taskname => $meta) {
        $capabilities = (array)($meta['capabilities'] ?? []);
        $capabilitylabel = implode(', ', $capabilities);
        if ($capabilitylabel === '') {
            $capabilitylabel = '-';
        }

        $settingkey = 'bookingextension_agent/' . task_registry::get_task_toggle_setting_name((string)$taskname);
        $settingtitle = get_string('aitaskenabled_label', 'bookingextension_agent', (string)$taskname);
        $settingdesc = get_string(
            'aitaskenabled_desc',
            'bookingextension_agent',
            (object)[
                'component' => (string)($meta['component'] ?? ''),
                'capability' => $capabilitylabel,
            ]
        );
        $default = 0;

        $aisettingspage->add(
            new admin_setting_configcheckbox(
                $settingkey,
                $settingtitle,
                $settingdesc,
                $default
            )
        );
    }
} catch (\Throwable $e) {
    $aisettingspage->add(
        new admin_setting_heading(
            'bookingextension_agent_aitaskgovernance_unavailable',
            get_string('aitaskgovernanceunavailable', 'bookingextension_agent'),
            get_string('aitaskgovernanceunavailable_desc', 'bookingextension_agent')
        )
    );
}

$adminroot->add('modbookingfolder', $aisettingspage);
