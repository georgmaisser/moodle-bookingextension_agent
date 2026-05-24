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

defined('MOODLE_INTERNAL') || die();

/**
 * Ensure AI messages carry the owning user id from their thread.
 *
 * @return void
 */
function xmldb_bookingextension_agent_ensure_ai_messages_userid(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $table = new xmldb_table('local_wbagent_ai_messages');
    $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'threadid');

    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    $records = $DB->get_recordset_sql(
        'SELECT m.id, t.userid
           FROM {local_wbagent_ai_messages} m
           JOIN {local_wbagent_ai_threads} t
             ON t.id = m.threadid
          WHERE m.userid = :emptyuserid',
        ['emptyuserid' => 0]
    );
    foreach ($records as $record) {
        $DB->set_field('local_wbagent_ai_messages', 'userid', (int)$record->userid, ['id' => (int)$record->id]);
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
    if ($oldversion < 2026052205) {
        xmldb_bookingextension_agent_ensure_ai_messages_userid();
        upgrade_plugin_savepoint(true, 2026052205, 'bookingextension', 'agent');
    }

    if ($oldversion < 2026052300) {
        xmldb_bookingextension_agent_ensure_ai_messages_userid();
        upgrade_plugin_savepoint(true, 2026052300, 'bookingextension', 'agent');
    }

    return true;
}
