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

    return true;
}
