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
 * AI Skill Governance and Analysis admin page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$context = context_system::instance();

try {
    admin_externalpage_setup('bookingextension_agent_skillgovernance');
} catch (\core\exception\moodle_exception $e) {
    if ($e->errorcode !== 'sectionerror') {
        throw $e;
    }
    require_login();
    $PAGE->set_pagelayout('admin');
}

require_capability('moodle/site:config', $context);

$registry = \bookingextension_agent\local\wbagent\skill_registry_factory::get_default();
$contracts = $registry->get_skill_contracts();
ksort($contracts);

// Handle POST actions.
if (data_submitted() && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    $bulk = optional_param('bulk', '', PARAM_ALPHA);

    if ($action === 'rebuild') {
        if (class_exists('\\bookingextension_agent\\task\\rebuild_skill_catalog_embeddings_adhoc')) {
            $task = new \bookingextension_agent\task\rebuild_skill_catalog_embeddings_adhoc();
            \core\task\manager::queue_adhoc_task($task, true);
            redirect(
                $PAGE->url,
                get_string('rebuild_skills_catalog_queued', 'bookingextension_agent'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } else if ($bulk === 'enableall') {
        foreach ($contracts as $skillname => $meta) {
            $settingname = \bookingextension_agent\local\wbagent\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            set_config($settingname, '1', 'bookingextension_agent');
        }
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($bulk === 'disableall') {
        foreach ($contracts as $skillname => $meta) {
            $settingname = \bookingextension_agent\local\wbagent\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            set_config($settingname, '0', 'bookingextension_agent');
        }
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // Save individual toggles.
        $skillsposted = optional_param_array('skills', [], PARAM_RAW);
        foreach ($contracts as $skillname => $meta) {
            $settingname = \bookingextension_agent\local\wbagent\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            $value = isset($skillsposted[$skillname]) ? '1' : '0';
            set_config($settingname, $value, 'bookingextension_agent');
        }
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Fetch Collision analyzer results.
$collisionanalyzer = new \bookingextension_agent\local\wbagent\services\debug\skill_selection_debug_service();
$collisionresult = $collisionanalyzer->analyze_collisions(250);
$hasembeddings = !empty($collisionresult['has_embeddings']);
$skillcollisions = [];
$highcollisioncount = 0;

if ($hasembeddings && !empty($collisionresult['pairs'])) {
    foreach ($collisionresult['pairs'] as $pair) {
        $risk = $pair['risk'] ?? 'ok';
        if ($risk === 'high' || $risk === 'warning') {
            $skillcollisions[$pair['skill_a']][] = [
                'other' => $pair['skill_b'],
                'similarity' => $pair['similarity'],
                'risk' => $risk,
            ];
            $skillcollisions[$pair['skill_b']][] = [
                'other' => $pair['skill_a'],
                'similarity' => $pair['similarity'],
                'risk' => $risk,
            ];
            if ($risk === 'high') {
                $highcollisioncount++;
            }
        }
    }
    // High collision count represents pairs, so divide by 2 for unique pair counts.
    $highcollisioncount = (int)ceil($highcollisioncount / 2);
}

// Real governance-gate evaluation (skill_executability_evaluator) for a chosen user + context.
// Defaults: the current admin and the system context. Override to test a concrete teacher in a
// concrete booking module (paste a user id and that module's context id).
$evaluserid = optional_param('evaluserid', (int)$USER->id, PARAM_INT);
$evalcontextid = optional_param('evalcontextid', (int)$context->id, PARAM_INT);

$evaluator = new \bookingextension_agent\local\wbagent\skill_executability_evaluator(
    $registry,
    new \bookingextension_agent\local\wbagent\services\security\authorization_service()
);

$evaluations = [];
foreach ($contracts as $skillname => $meta) {
    $evaluations[(string)$skillname] = $evaluator->evaluate_skill((string)$skillname, $evaluserid, $evalcontextid);
}

// Resolve the evaluation context label and a readable user label for the header note.
$evalcontextlabel = 'context #' . $evalcontextid;
try {
    $evalcontextlabel = context::instance_by_id($evalcontextid, MUST_EXIST)->get_context_name(false, true);
} catch (\Throwable $e) {
    unset($e);
}
$evaluserlabel = 'user #' . $evaluserid;
if ($evaluser = \core_user::get_user($evaluserid, '*', IGNORE_MISSING)) {
    $evaluserlabel = fullname($evaluser) . ' (#' . $evaluserid . ')';
}

// Map a deny reason + diagnostics to a precise, human-readable hint.
$describedeny = static function (array $evaluation) use ($evalcontextid): string {
    $reason = (string)($evaluation['deny_reason'] ?? '');
    $diagnostics = (array)($evaluation['diagnostics'] ?? []);
    switch ($reason) {
        case \bookingextension_agent\local\wbagent\skill_contract_validator::DENY_NOT_REGISTERED:
            return get_string('skillgovernance_gate_deny_not_registered', 'bookingextension_agent');
        case \bookingextension_agent\local\wbagent\skill_contract_validator::DENY_RUNTIME_DISABLED:
            return get_string('skillgovernance_gate_deny_runtime_disabled', 'bookingextension_agent');
        case \bookingextension_agent\local\wbagent\skill_contract_validator::DENY_INACTIVE:
            return get_string('skillgovernance_gate_deny_inactive', 'bookingextension_agent');
        case \bookingextension_agent\local\wbagent\skill_contract_validator::DENY_CONTEXT_INVALID:
            return get_string('skillgovernance_gate_deny_context_invalid', 'bookingextension_agent', $evalcontextid);
        case \bookingextension_agent\local\wbagent\skill_contract_validator::DENY_SKILL_VERSION_UNSUPPORTED:
            return get_string('skillgovernance_gate_deny_version_unsupported', 'bookingextension_agent');
        case \bookingextension_agent\local\wbagent\skill_contract_validator::DENY_MISSING_CAPABILITY:
            $caps = (array)($diagnostics['required_capabilities'] ?? []);
            if (empty($caps)) {
                return get_string('skillgovernance_gate_deny_no_capability', 'bookingextension_agent');
            }
            $parts = [];
            foreach ($caps as $cap) {
                $cap = (string)$cap;
                $key = get_capability_info($cap)
                    ? 'skillgovernance_gate_cap_user_lacks'
                    : 'skillgovernance_gate_cap_not_defined';
                $parts[] = get_string($key, 'bookingextension_agent', $cap);
            }
            return implode('; ', $parts) . '.';
        default:
            return $reason !== '' ? $reason : get_string('skillgovernance_gate_deny_generic', 'bookingextension_agent');
    }
};

// Set up Moodle Page.
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/skill_governance.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('skillgovernance', 'bookingextension_agent'));
$PAGE->set_heading(get_string('skillgovernance', 'bookingextension_agent'));

echo $OUTPUT->header();

// Title and description.
echo $OUTPUT->heading(get_string('skillgovernance', 'bookingextension_agent'), 2);
echo html_writer::tag('p', get_string('aiskillgovernanceheading_desc', 'bookingextension_agent'));

// Evaluation target selector (real governance gate). GET form so it is shareable/bookmarkable.
echo html_writer::start_div('card card-body bg-light mb-3');
echo html_writer::tag(
    'p',
    get_string('skillgovernance_gate_intro', 'bookingextension_agent', (object)[
        'user' => s($evaluserlabel),
        'context' => s($evalcontextlabel),
    ]),
    ['class' => 'mb-2 small text-muted']
);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url, 'class' => 'form-inline']);
echo html_writer::tag(
    'label',
    get_string('skillgovernance_gate_userid', 'bookingextension_agent'),
    ['for' => 'evaluserid', 'class' => 'mr-1']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'evaluserid',
    'name' => 'evaluserid',
    'value' => $evaluserid,
    'class' => 'form-control mr-3',
    'style' => 'width: 120px;',
]);
echo html_writer::tag(
    'label',
    get_string('skillgovernance_gate_contextid', 'bookingextension_agent'),
    ['for' => 'evalcontextid', 'class' => 'mr-1']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'evalcontextid',
    'name' => 'evalcontextid',
    'value' => $evalcontextid,
    'class' => 'form-control mr-3',
    'style' => 'width: 120px;',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-outline-primary',
    'value' => get_string('skillgovernance_gate_evaluate', 'bookingextension_agent'),
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();

// Top Actions Bar & Status Warnings.
if ($highcollisioncount > 0) {
        $message = 'Warning: There are ' . $highcollisioncount .
            ' high-similarity embedding collision pair(s) detected. ' .
            'This may cause prompt selection confusion in the planner.';
        echo $OUTPUT->notification($message, 'warning');
}

echo html_writer::start_div('row mb-4 align-items-center');

// Search Box (Left side).
echo html_writer::start_div('col-md-4');
echo html_writer::start_div('input-group');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'skill-search-input',
    'class' => 'form-control',
    'placeholder' => 'Search skills by name, component or capability...',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Bulk Buttons & Rebuild (Right side).
echo html_writer::start_div('col-md-8 text-right d-flex justify-content-end');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulk', 'value' => 'enableall']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-success mr-1', 'value' => 'Enable All']);
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulk', 'value' => 'disableall']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-danger mr-1', 'value' => 'Disable All']);
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('rebuild_skills_catalog', 'bookingextension_agent'),
]);
echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();

// Main Table.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_tag('table', ['class' => 'table table-hover align-middle', 'id' => 'skills-governance-table']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', 'Active', ['style' => 'width: 80px; text-align: center;']);
echo html_writer::tag('th', get_string('skillgovernance_gate_status', 'bookingextension_agent'), ['style' => 'width: 220px;']);
echo html_writer::tag('th', 'Skill Name / Component');
echo html_writer::tag('th', 'Required Capabilities');
echo html_writer::tag('th', 'Collision Status', ['style' => 'width: 200px;']);
echo html_writer::tag('th', 'Actions', ['style' => 'width: 120px; text-align: center;']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

$rowindex = 0;
foreach ($contracts as $skillname => $meta) {
    $rowindex++;
    $skill = $registry->get_skill((string)$skillname);
    $provider = $registry->get_provider_for_skill((string)$skillname);
    $settingname = \bookingextension_agent\local\wbagent\skill_registry::get_skill_toggle_setting_name((string)$skillname);
    $isactive = get_config('bookingextension_agent', $settingname) !== '0'; // Default true/active.

    $capabilities = (array)($meta['capabilities'] ?? []);
    $capabilitylabel = implode('<br/>', array_map('s', $capabilities));
    if ($capabilitylabel === '') {
        $capabilitylabel = '<span class="text-muted">-</span>';
    }

    $component = s((string)($meta['component'] ?? ''));

    // Collision badge.
    $collisionshtml = '<span class="badge badge-success">Clear</span>';
    $collisionlist = $skillcollisions[$skillname] ?? [];
    if (!empty($collisionlist)) {
        $highestrisk = 'warning';
        $collisiondetails = [];
        foreach ($collisionlist as $col) {
            if ($col['risk'] === 'high') {
                $highestrisk = 'danger';
            }
            $percent = round($col['similarity'] * 100);
            $collisiondetails[] = s($col['other']) . ' (' . $percent . '%)';
        }
        $tooltip = implode(', ', $collisiondetails);
        $badgeclass = $highestrisk === 'danger' ? 'badge-danger' : 'badge-warning';
        $collisionshtml = '<span class="badge ' . $badgeclass . '" title="' . $tooltip . '" style="cursor: help;">'
            . count($collisionlist) . ' Collision(s)</span>';
    }

    // Row class for search filtering.
    echo html_writer::start_tag('tr', [
        'class' => 'skill-row',
        'data-skillname' => s((string)$skillname),
        'data-component' => $component,
        'data-capabilities' => implode(' ', $capabilities),
    ]);

    // Checkbox.
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'skills[' . s((string)$skillname) . ']',
        'value' => '1',
        'checked' => $isactive ? 'checked' : null,
    ]);
    echo html_writer::end_tag('td');

    // Status (real governance gate result for the chosen user + context).
    $evaluation = $evaluations[(string)$skillname] ?? ['executable_state' => 'deny', 'deny_reason' => ''];
    $isexecutable = (string)($evaluation['executable_state'] ?? '') === 'allow';
    if ($isexecutable) {
        $statushtml = '<span class="badge badge-success">&#10003; '
            . s(get_string('skillgovernance_gate_available', 'bookingextension_agent')) . '</span>';
    } else {
        $hint = $describedeny($evaluation);
        $statushtml = '<span class="badge badge-danger" title="' . s($hint) . '" style="cursor: help;">&#10007; '
            . s(get_string('skillgovernance_gate_blocked', 'bookingextension_agent')) . '</span>'
            . '<br/><small class="text-danger">' . s($hint) . '</small>';
    }
    echo html_writer::tag('td', $statushtml);

    // Skill Name / Component.
    echo html_writer::start_tag('td');
    echo html_writer::tag('strong', s((string)$skillname)) . '<br/>';
    echo html_writer::tag('small', 'Component: ' . $component, ['class' => 'text-muted']);
    echo html_writer::end_tag('td');

    // Capabilities.
    echo html_writer::tag('td', $capabilitylabel);

    // Collisions.
    echo html_writer::tag('td', $collisionshtml);

    // Actions button.
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    echo html_writer::link(
        '#collapse-details-' . $rowindex,
        'Details',
        [
            'class' => 'btn btn-sm btn-outline-secondary',
            'data-toggle' => 'collapse',
            'data-bs-toggle' => 'collapse',
            'data-bs-target' => '#collapse-details-' . $rowindex,
            'role' => 'button',
            'aria-expanded' => 'false',
            'aria-controls' => 'collapse-details-' . $rowindex,
        ]
    );
    echo html_writer::end_tag('td');

    echo html_writer::end_tag('tr');

    // Collapsible Row.
    echo html_writer::start_tag('tr', ['class' => 'skill-detail-row', 'id' => 'detail-row-' . $rowindex]);
    echo html_writer::start_tag('td', ['colspan' => 6, 'style' => 'padding: 0; border-top: none;']);

    // Build the collapsible inner content.
    $bodycontent = '';

    // Description.
    $description = s((string)($meta['schema']['description'] ?? ''));
    $bodycontent .= html_writer::tag('h6', 'Description');
    $bodycontent .= html_writer::tag('p', $description ?: '<span class="text-muted">No description.</span>');

    // Example Input.
    $examplehtml = '<span class="text-muted">No example input.</span>';
    if ($skill) {
        try {
            $example = $skill->get_example_input();
            if (!empty($example)) {
                $examplehtml = html_writer::tag(
                    'pre',
                    s(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                );
            }
        } catch (\Throwable $e) {
            $examplehtml = '<span class="text-danger">Error loading example: ' . s($e->getMessage()) . '</span>';
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Example Parameter Input', ['class' => 'mt-3']);
    $bodycontent .= $examplehtml;

    // Message Triggers.
    $triggershtml = '<span class="text-muted">No message triggers.</span>';
    if ($skill instanceof \bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface) {
        try {
            $triggers = $skill->get_message_triggers();
            if (!empty($triggers)) {
                $triggeritems = [];
                foreach ($triggers as $trigger) {
                    $desc = s((string)($trigger['description'] ?? ''));
                    $examples = (array)($trigger['examples'] ?? []);
                    $exlabel = !empty($examples) ? ' (e.g. "' . implode('", "', array_map('s', $examples)) . '")' : '';
                    $triggeritems[] = html_writer::tag(
                        'li',
                        '<strong>' . s((string)($trigger['id'] ?? '')) . '</strong>: ' . $desc . $exlabel
                    );
                }
                $triggershtml = html_writer::tag('ul', implode('', $triggeritems), ['class' => 'mb-0 pl-3']);
            }
        } catch (\Throwable $e) {
            $triggershtml = '<span class="text-danger">Error loading triggers: ' . s($e->getMessage()) . '</span>';
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Message Triggers', ['class' => 'mt-3']);
    $bodycontent .= $triggershtml;

    // Guidance / Prompt Packs.
    $guidancehtml = '<span class="text-muted">No contextual guidance.</span>';
    $packs = [];
    if ($skill && method_exists($skill, 'get_contextual_prompt_packs')) {
        try {
            $packs = $skill->get_contextual_prompt_packs();
        } catch (\Throwable $e) {
            unset($e);
        }
    }
    if (empty($packs) && $provider && method_exists($provider, 'get_contextual_prompt_packs')) {
        try {
            $allpacks = $provider->get_contextual_prompt_packs();
            foreach ($allpacks as $pack) {
                if (
                    isset($pack['id']) &&
                    (strpos($skillname, $pack['id']) !== false || strpos($pack['id'], $skillname) !== false)
                ) {
                    $packs[] = $pack;
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    if (!empty($packs)) {
        $guidanceitems = [];
        foreach ($packs as $pack) {
            $lines = (array)($pack['guidance'] ?? []);
            foreach ($lines as $line) {
                $guidanceitems[] = html_writer::tag('li', s((string)$line));
            }
        }
        if (!empty($guidanceitems)) {
            $guidancehtml = html_writer::tag('ul', implode('', $guidanceitems), ['class' => 'mb-0 pl-3']);
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Contextual Guidance (Prompts)', ['class' => 'mt-3']);
    $bodycontent .= $guidancehtml;

    // Output collapsible structure matching htmlcomponents.php.
    echo html_writer::div(
        html_writer::div(
            $bodycontent,
            'card card-body'
        ),
        '',
        [
            'class' => 'collapse',
            'id' => 'collapse-details-' . $rowindex,
        ]
    );

    echo html_writer::end_tag('td');
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// Submit changes button.
echo html_writer::start_div('mt-3 text-left');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary btn-lg',
    'value' => get_string('savechanges'),
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');

// Inject JavaScript for search/filter.
// The AMD footer block runs after DOMContentLoaded has already fired, so waiting
// for that event would never attach the listener — init directly when ready.
$js = "
(function() {
    var init = function() {
        var searchInput = document.getElementById('skill-search-input');
        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', function() {
            var query = searchInput.value.toLowerCase().trim();
            var rows = document.querySelectorAll('#skills-governance-table tbody .skill-row');

            rows.forEach(function(row) {
                var skillname = (row.getAttribute('data-skillname') || '').toLowerCase();
                var component = (row.getAttribute('data-component') || '').toLowerCase();
                var capabilities = (row.getAttribute('data-capabilities') || '').toLowerCase();

                var match = skillname.indexOf(query) !== -1 ||
                            component.indexOf(query) !== -1 ||
                            capabilities.indexOf(query) !== -1;

                var nextRow = row.nextElementSibling;
                if (match) {
                    row.style.display = '';
                    if (nextRow && nextRow.classList.contains('skill-detail-row')) {
                        nextRow.style.display = '';
                    }
                } else {
                    row.style.display = 'none';
                    if (nextRow && nextRow.classList.contains('skill-detail-row')) {
                        nextRow.style.display = 'none';
                    }
                }
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
";
$PAGE->requires->js_amd_inline($js);

echo $OUTPUT->footer();
