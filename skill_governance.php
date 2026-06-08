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
            redirect($PAGE->url, get_string('rebuild_skills_catalog_queued', 'bookingextension_agent'), null, \core\output\notification::NOTIFY_SUCCESS);
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
        $skills_posted = optional_param_array('skills', [], PARAM_RAW);
        foreach ($contracts as $skillname => $meta) {
            $settingname = \bookingextension_agent\local\wbagent\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            $value = isset($skills_posted[$skillname]) ? '1' : '0';
            set_config($settingname, $value, 'bookingextension_agent');
        }
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Fetch Collision analyzer results.
$collisionanalyzer = new \bookingextension_agent\local\wbagent\services\debug\skill_selection_debug_service();
$collisionresult = $collisionanalyzer->analyze_collisions(250);
$has_embeddings = !empty($collisionresult['has_embeddings']);
$skill_collisions = [];
$high_collision_count = 0;

if ($has_embeddings && !empty($collisionresult['pairs'])) {
    foreach ($collisionresult['pairs'] as $pair) {
        $risk = $pair['risk'] ?? 'ok';
        if ($risk === 'high' || $risk === 'warning') {
            $skill_collisions[$pair['skill_a']][] = [
                'other' => $pair['skill_b'],
                'similarity' => $pair['similarity'],
                'risk' => $risk,
            ];
            $skill_collisions[$pair['skill_b']][] = [
                'other' => $pair['skill_a'],
                'similarity' => $pair['similarity'],
                'risk' => $risk,
            ];
            if ($risk === 'high') {
                $high_collision_count++;
            }
        }
    }
    // High collision count represents pairs, so divide by 2 for unique pair counts
    $high_collision_count = (int)ceil($high_collision_count / 2);
}

// Set up Moodle Page.
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/skill_governance.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('skillgovernance', 'bookingextension_agent'));
$PAGE->set_heading(get_string('skillgovernance', 'bookingextension_agent'));

echo $OUTPUT->header();

// Title and description.
echo $OUTPUT->heading(get_string('skillgovernance', 'bookingextension_agent'), 2);
echo html_writer::tag('p', get_string('aiskillgovernanceheading_desc', 'bookingextension_agent'));

// Top Actions Bar & Status Warnings.
if ($high_collision_count > 0) {
    echo $OUTPUT->notification(
        'Warning: There are ' . $high_collision_count . ' high-similarity embedding collision pair(s) detected. This may cause prompt selection confusion in the planner.',
        'warning'
    );
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
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rebuild']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('rebuild_skills_catalog', 'bookingextension_agent')]);
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
    $isactive = get_config('bookingextension_agent', $settingname) !== '0'; // default true/active

    $capabilities = (array)($meta['capabilities'] ?? []);
    $capabilitylabel = implode('<br/>', array_map('s', $capabilities));
    if ($capabilitylabel === '') {
        $capabilitylabel = '<span class="text-muted">-</span>';
    }

    $component = s((string)($meta['component'] ?? ''));

    // Collision badge.
    $collisions_html = '<span class="badge badge-success">Clear</span>';
    $collision_list = $skill_collisions[$skillname] ?? [];
    if (!empty($collision_list)) {
        $highest_risk = 'warning';
        $collision_details = [];
        foreach ($collision_list as $col) {
            if ($col['risk'] === 'high') {
                $highest_risk = 'danger';
            }
            $percent = round($col['similarity'] * 100);
            $collision_details[] = s($col['other']) . ' (' . $percent . '%)';
        }
        $tooltip = implode(', ', $collision_details);
        $badge_class = $highest_risk === 'danger' ? 'badge-danger' : 'badge-warning';
        $collisions_html = '<span class="badge ' . $badge_class . '" title="' . $tooltip . '" style="cursor: help;">'
            . count($collision_list) . ' Collision(s)</span>';
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

    // Skill Name / Component.
    echo html_writer::start_tag('td');
    echo html_writer::tag('strong', s((string)$skillname)) . '<br/>';
    echo html_writer::tag('small', 'Component: ' . $component, ['class' => 'text-muted']);
    echo html_writer::end_tag('td');

    // Capabilities.
    echo html_writer::tag('td', $capabilitylabel);

    // Collisions.
    echo html_writer::tag('td', $collisions_html);

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
    echo html_writer::start_tag('td', ['colspan' => 5, 'style' => 'padding: 0; border-top: none;']);

    // Build the collapsible inner content.
    $bodycontent = '';

    // Description.
    $description = s((string)($meta['schema']['description'] ?? ''));
    $bodycontent .= html_writer::tag('h6', 'Description');
    $bodycontent .= html_writer::tag('p', $description ?: '<span class="text-muted">No description.</span>');

    // Example Input.
    $example_html = '<span class="text-muted">No example input.</span>';
    if ($skill) {
        try {
            $example = $skill->get_example_input();
            if (!empty($example)) {
                $example_html = html_writer::tag('pre', s(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            }
        } catch (\Throwable $e) {
            $example_html = '<span class="text-danger">Error loading example: ' . s($e->getMessage()) . '</span>';
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Example Parameter Input', ['class' => 'mt-3']);
    $bodycontent .= $example_html;

    // Message Triggers.
    $triggers_html = '<span class="text-muted">No message triggers.</span>';
    if ($skill instanceof \bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface) {
        try {
            $triggers = $skill->get_message_triggers();
            if (!empty($triggers)) {
                $trigger_items = [];
                foreach ($triggers as $trigger) {
                    $desc = s((string)($trigger['description'] ?? ''));
                    $examples = (array)($trigger['examples'] ?? []);
                    $ex_label = !empty($examples) ? ' (e.g. "' . implode('", "', array_map('s', $examples)) . '")' : '';
                    $trigger_items[] = html_writer::tag('li', '<strong>' . s((string)($trigger['id'] ?? '')) . '</strong>: ' . $desc . $ex_label);
                }
                $triggers_html = html_writer::tag('ul', implode('', $trigger_items), ['class' => 'mb-0 pl-3']);
            }
        } catch (\Throwable $e) {
            $triggers_html = '<span class="text-danger">Error loading triggers: ' . s($e->getMessage()) . '</span>';
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Message Triggers', ['class' => 'mt-3']);
    $bodycontent .= $triggers_html;

    // Guidance / Prompt Packs.
    $guidance_html = '<span class="text-muted">No contextual guidance.</span>';
    $packs = [];
    if ($skill && method_exists($skill, 'get_contextual_prompt_packs')) {
        try {
            $packs = $skill->get_contextual_prompt_packs();
        } catch (\Throwable $e) {
            // Ignore error.
        }
    }
    if (empty($packs) && $provider && method_exists($provider, 'get_contextual_prompt_packs')) {
        try {
            $allpacks = $provider->get_contextual_prompt_packs();
            foreach ($allpacks as $pack) {
                if (isset($pack['id']) && (strpos($skillname, $pack['id']) !== false || strpos($pack['id'], $skillname) !== false)) {
                    $packs[] = $pack;
                }
            }
        } catch (\Throwable $e) {
            // Ignore error.
        }
    }

    if (!empty($packs)) {
        $guidance_items = [];
        foreach ($packs as $pack) {
            $lines = (array)($pack['guidance'] ?? []);
            foreach ($lines as $line) {
                $guidance_items[] = html_writer::tag('li', s((string)$line));
            }
        }
        if (!empty($guidance_items)) {
            $guidance_html = html_writer::tag('ul', implode('', $guidance_items), ['class' => 'mb-0 pl-3']);
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Contextual Guidance (Prompts)', ['class' => 'mt-3']);
    $bodycontent .= $guidance_html;

    // Output collapsible structure matching htmlcomponents.php
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
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-lg', 'value' => get_string('savechanges')]);
echo html_writer::end_div();

echo html_writer::end_tag('form');

// Inject JavaScript for search/filter.
$js = "
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('skill-search-input');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        var query = searchInput.value.toLowerCase().trim();
        var rows = document.querySelectorAll('#skills-governance-table tbody .skill-row');
        
        rows.forEach(function(row) {
            var skillname = row.getAttribute('data-skillname').toLowerCase();
            var component = row.getAttribute('data-component').toLowerCase();
            var capabilities = row.getAttribute('data-capabilities').toLowerCase();
            
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
});
";
$PAGE->requires->js_amd_inline($js);

echo $OUTPUT->footer();
