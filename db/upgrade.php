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
 * Upgrade function.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_bookingextension_agent_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026063003) {
        // Record per benchmark run whether family/skill embeddings were live (vs keyword-only routing).
        // New, correctly-prefixed bx_agent_ field, guarded + idempotent.
        $table = new xmldb_table('bx_agent_benchmark_runs');
        $field = new xmldb_field('embeddings_used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'git_ref');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026063003, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026063008) {
        // Record the embedding model used when embeddings were live (catalog current) for a run.
        $table = new xmldb_table('bx_agent_benchmark_runs');
        $field = new xmldb_field('embeddings_model', XMLDB_TYPE_CHAR, '80', null, null, null, null, 'embeddings_used');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026063008, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070200) {
        // Retrieval foundation: DB-backed embeddings store. Idempotent create, guarded by table_exists;
        // mirrors db/install.xml exactly.
        $table = new xmldb_table('bx_agent_embeddings');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('area', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('owner', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('refkey', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('refindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('endindex', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('title', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('emodel', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $table->add_field('edims', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('identityhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('generation', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('embedding', XMLDB_TYPE_BINARY, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('docid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('owneruserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('variant_gen_idx', XMLDB_INDEX_NOTUNIQUE, ['area', 'emodel', 'edims', 'generation']);
            $table->add_index('reuse_idx', XMLDB_INDEX_NOTUNIQUE, ['area', 'emodel', 'edims', 'generation', 'identityhash']);
            $table->add_index('contextid_idx', XMLDB_INDEX_NOTUNIQUE, ['contextid']);
            $dbman->create_table($table);
        }

        $meta = new xmldb_table('bx_agent_embeddings_meta');
        if (!$dbman->table_exists($meta)) {
            $meta->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $meta->add_field('area', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $meta->add_field('emodel', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $meta->add_field('edims', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $meta->add_field('committedgeneration', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $meta->add_field('fingerprint', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $meta->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $meta->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $meta->add_index('variant_uix', XMLDB_INDEX_UNIQUE, ['area', 'emodel', 'edims']);
            $dbman->create_table($meta);
        }

        upgrade_plugin_savepoint(true, 2026070200, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070202) {
        // Site search foundation: per area x variant incremental indexing cursor (runtime state,
        // task-written) + governance scope table (admin-written config, default = everything off).
        // Idempotent create, guarded by table_exists; mirrors db/install.xml exactly.
        // The cursor column is named indexcursor because "cursor" is a MySQL reserved word.
        $state = new xmldb_table('bx_agent_sitesearch_state');
        if (!$dbman->table_exists($state)) {
            $state->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            // Length 100 (not 255): the unique index areakey+emodel+edims must stay under Moodle's
            // 333-char composed-index limit (255 + 128 chars would exceed it).
            $state->add_field('areakey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $state->add_field('emodel', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
            $state->add_field('edims', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $state->add_field('indexcursor', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $state->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $state->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $state->add_index('areavariant_uix', XMLDB_INDEX_UNIQUE, ['areakey', 'emodel', 'edims']);
            $dbman->create_table($state);
        }

        $scope = new xmldb_table('bx_agent_search_scope');
        if (!$dbman->table_exists($scope)) {
            $scope->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $scope->add_field('area', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $scope->add_field('scopetype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $scope->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $scope->add_field('enabled', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $scope->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $scope->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $scope->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $scope->add_index('areascope_idx', XMLDB_INDEX_NOTUNIQUE, ['area', 'scopetype', 'scopeid']);
            $dbman->create_table($scope);
        }

        upgrade_plugin_savepoint(true, 2026070202, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070203) {
        // The skills catalog computes sha256 content hashes (64 chars); the column was sized for
        // the docs sha1 (40) and truncation would break change detection. The column is not part
        // of any index, so widening is safe.
        $table = new xmldb_table('bx_agent_embeddings');
        $field = new xmldb_field('contenthash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'edims');
        if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070203, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070205) {
        // Site-search file indexing (PDF, v1): per area x scope flag — file indexing is governed
        // exactly like enablement itself, so it naturally extends to course/category scoping.
        // Default 0 (off, cost-sensitive); only effective while the row is enabled.
        $table = new xmldb_table('bx_agent_search_scope');
        $field = new xmldb_field('includefiles', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070205, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070701) {
        // Shorten table suffixes so the local_wizard sync generator can map tables by pure
        // prefix swap: every suffix after bx_agent_ must stay <= 15 chars, or the generated
        // local_wizard_* name would exceed Moodle's 28-char table name limit. Data-preserving
        // rename (benchmark history matters in dev).
        $renames = [
            'bx_agent_benchmark_runs' => 'bx_agent_bm_runs',
            'bx_agent_benchmark_scenarios' => 'bx_agent_bm_scenarios',
            'bx_agent_benchmark_baselines' => 'bx_agent_bm_baselines',
            'bx_agent_benchmark_metrics' => 'bx_agent_bm_metrics',
            'bx_agent_sitesearch_state' => 'bx_agent_search_state',
        ];
        foreach ($renames as $oldname => $newname) {
            $table = new xmldb_table($oldname);
            if ($dbman->table_exists($table) && !$dbman->table_exists(new xmldb_table($newname))) {
                $dbman->rename_table($table, $newname);
            }
        }

        upgrade_plugin_savepoint(true, 2026070701, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026070703) {
        // XMLDB forbids CHAR NOT NULL columns with '' as default (debugging noise on every
        // structure load, e.g. plugin uninstall). Both columns are always set explicitly on
        // insert, so the default is dropped entirely instead of inventing a meaningful one.
        $table = new xmldb_table('bx_agent_embeddings');
        if ($dbman->table_exists($table)) {
            $owner = new xmldb_field('owner', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'area');
            if ($dbman->field_exists($table, $owner)) {
                $dbman->change_field_default($table, $owner);
            }
            $identityhash = new xmldb_field(
                'identityhash',
                XMLDB_TYPE_CHAR,
                '40',
                null,
                XMLDB_NOTNULL,
                null,
                null,
                'contenthash'
            );
            if ($dbman->field_exists($table, $identityhash)) {
                // The identityhash column is part of the reuse index, and Moodle's DDL refuses to
                // modify a field a key depends on. Drop the index, change the default, restore it.
                $reuseindex = new xmldb_index(
                    'reuse_idx',
                    XMLDB_INDEX_NOTUNIQUE,
                    ['area', 'emodel', 'edims', 'generation', 'identityhash']
                );
                $hadreuseindex = $dbman->index_exists($table, $reuseindex);
                if ($hadreuseindex) {
                    $dbman->drop_index($table, $reuseindex);
                }
                $dbman->change_field_default($table, $identityhash);
                if ($hadreuseindex) {
                    $dbman->add_index($table, $reuseindex);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026070703, 'bookingextension', 'agent');
    }

    return true;
}
