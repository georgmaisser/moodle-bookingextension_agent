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

    return true;
}
