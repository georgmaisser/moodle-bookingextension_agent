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
 * Upgrade hook.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Ensure AI messages carry the owning user id from their thread.
 *
 * @return void
 */
function xmldb_bookingextension_agent_ensure_ai_messages_userid(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $table = new xmldb_table('local_wizard_ai_messages');
    $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'threadid');

    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    $records = $DB->get_recordset_sql(
        'SELECT m.id, t.userid
           FROM {local_wizard_ai_messages} m
           JOIN {local_wizard_ai_threads} t
             ON t.id = m.threadid
          WHERE m.userid = :emptyuserid',
        ['emptyuserid' => 0]
    );
    foreach ($records as $record) {
        $DB->set_field('local_wizard_ai_messages', 'userid', (int)$record->userid, ['id' => (int)$record->id]);
    }
    $records->close();

    $index = new xmldb_index('useridthreadidx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'threadid']);
    if (!$dbman->index_exists($table, $index)) {
        $dbman->add_index($table, $index);
    }
}

/**
 * Upgrade function.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_bookingextension_agent_upgrade(int $oldversion): bool {
    global $DB;
    if ($oldversion < 2026052205) {
        xmldb_bookingextension_agent_ensure_ai_messages_userid();
        upgrade_plugin_savepoint(true, 2026052205, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026052300) {
        xmldb_bookingextension_agent_ensure_ai_messages_userid();
        upgrade_plugin_savepoint(true, 2026052300, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026053100) {
        // No DB schema changes. Savepoint exists to roll out updated WS registrations.
        upgrade_plugin_savepoint(true, 2026053100, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026060401) {
        $dbman = $DB->get_manager(); // phpcs:ignore

        // Benchmark runs table.
        $table = new xmldb_table('local_wizard_benchmark_runs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('run_uuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
            $table->add_field('label', XMLDB_TYPE_CHAR, '120', null, null, null, null);
            $table->add_field('model_id', XMLDB_TYPE_CHAR, '80', null, null, null, null);
            $table->add_field('model_version', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('prompt_profile', XMLDB_TYPE_CHAR, '80', null, null, null, null);
            $table->add_field('skill_set', XMLDB_TYPE_CHAR, '80', null, null, null, null);
            $table->add_field('total_scenarios', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('passed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('failed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('skipped', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('success_rate', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('baseline_run_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('is_baseline', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('regression_detected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('total_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('total_cost_estimate', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('duration_ms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('environment', XMLDB_TYPE_CHAR, '80', null, null, null, null);
            $table->add_field('git_ref', XMLDB_TYPE_CHAR, '80', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('run_uuid_idx', XMLDB_INDEX_UNIQUE, ['run_uuid']);
            $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $table->add_index('is_baseline_idx', XMLDB_INDEX_NOTUNIQUE, ['is_baseline']);
            $dbman->create_table($table);
        }

        // Benchmark scenario results table.
        $table = new xmldb_table('local_wizard_benchmark_scenarios');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('run_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scenario_key', XMLDB_TYPE_CHAR, '120', null, XMLDB_NOTNULL, null, '');
            $table->add_field('scenario_class', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('passed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('response_type_expected', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('response_type_actual', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('skill_expected', XMLDB_TYPE_CHAR, '120', null, null, null, null);
            $table->add_field('skill_selected', XMLDB_TYPE_CHAR, '120', null, null, null, null);
            $table->add_field('json_valid', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('contract_compliant', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('planned_steps_present', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('tokens_prompt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('tokens_completion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('duration_ms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('step_count', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('result_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('run_scenario_idx', XMLDB_INDEX_NOTUNIQUE, ['run_id', 'scenario_key']);
            $dbman->create_table($table);
        }

        // Benchmark baselines table.
        $table = new xmldb_table('local_wizard_benchmark_baselines');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('run_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('label', XMLDB_TYPE_CHAR, '120', null, null, null, null);
            $table->add_field('locked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // Benchmark metric snapshots table.
        $table = new xmldb_table('local_wizard_benchmark_metrics');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('run_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('metric_key', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, '');
            $table->add_field('metric_value', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('metric_unit', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('scenario_class', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('run_metric_idx', XMLDB_INDEX_NOTUNIQUE, ['run_id', 'metric_key']);
            $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060401, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026061001) {
        $dbman = $DB->get_manager(); // phpcs:ignore

        // User-stated memories/instructions for the AI agent (global per user).
        $table = new xmldb_table('local_wizard_user_memory');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('memory', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            // Foreign key on userid already provides the userid index — no separate add_index().
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026061001, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026061004) {
        $dbman = $DB->get_manager(); // phpcs:ignore

        // Per-memory injection channels (selection,construction,synchronization); empty = all.
        // Guarded by field_exists so it is a no-op where install.xml/an earlier run already added it.
        $table = new xmldb_table('local_wizard_user_memory');
        $field = new xmldb_field('scopes', XMLDB_TYPE_CHAR, '120', null, null, null, null, 'memory');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026061004, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026061702) {
        // The trial endpoint base URL is now hard-coded (https://llm.wunderbyte.at) and no longer an
        // admin setting; drop the orphaned stored config value.
        unset_config('trial_endpoint_base_url', 'bookingextension_agent');

        upgrade_plugin_savepoint(true, 2026061702, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026061703) {
        // The aiexecutionmode setting (direct/adhoc) and the adhoc AI-run execution path were
        // removed; AI runs always execute inline now. Drop the orphaned stored config value.
        unset_config('aiexecutionmode', 'bookingextension_agent');

        upgrade_plugin_savepoint(true, 2026061703, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026061704) {
        // Three rollout/CI guard settings were removed: queue DAG validation and blocked-confirmation
        // TTL expiry are always on now, and governance strict mode is no longer an admin setting
        // (it stays off by default; CI forces it via set_config). Drop the orphaned config values.
        unset_config('queue_dag_validation_enabled', 'bookingextension_agent');
        unset_config('queue_blocked_ttl_enabled', 'bookingextension_agent');
        unset_config('aigovernancestrictmode', 'bookingextension_agent');
        // Preflight audit logging was retired; its setting is gone (gate now always false).
        unset_config('preflight_audit_enabled', 'bookingextension_agent');

        upgrade_plugin_savepoint(true, 2026061704, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026062406) {
        // Deploy-time reconcile of both embeddings indexes. Docs and skills ship in the plugin code
        // and change at release time, but nothing re-triggered their rebuild on upgrade. Scheduling now
        // compares the live source fingerprint (docs) / expected catalog incl. orphans (skills) against
        // what the index was last built from, so this is a no-op when a release changed neither and a
        // real rebuild when it added/removed docs or skills — every deploy self-reconciles. The work is
        // gated/deduped/debounced inside the schedulers and runs later on cron, never inline here. A
        // debounce of 0 ensures the deploy reconcile is not suppressed by a recent enqueue marker.
        if (class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            // Docs corpus index (self-gated on the docs skill being active).
            (new \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service())
                ->ensure_rebuild_scheduled_if_needed(0);

            // Skill-catalog index (active model/dimensions).
            $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
            $settings = (new \bookingextension_agent\local\wizard\embeddings_action_config_resolver())->resolve();
            $model = (string)($settings['model'] ?? '');
            $dimensions = (int)($settings['dimensions'] ?? 0);
            $skillreadiness = new \bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service();
            $status = $skillreadiness->get_catalog_status($registry, $model, $dimensions);
            $skillreadiness->ensure_rebuild_scheduled_if_needed($status, $model, $dimensions, 0);
        }

        upgrade_plugin_savepoint(true, 2026062406, 'bookingextension', 'agent');
    }

    return true;
}
