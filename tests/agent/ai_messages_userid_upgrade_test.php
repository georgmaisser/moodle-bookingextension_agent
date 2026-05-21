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

namespace bookingextionsion_agent;

use mod_booking\local\testing\booking_advanced_testcase;

/**
 * Upgrade tests for local_wbagent_ai_messages.userid migration.
 *
 * @package    bookingextension_agent
 * @category   test
 * @coversNothing
 */
final class ai_messages_userid_upgrade_test extends booking_advanced_testcase {
    /**
     * Upgrade must backfill local_wbagent_ai_messages.userid from local_wbagent_ai_threads.userid.
     */
    public function test_upgrade_backfills_local_wbagent_ai_messages_userid(): void {
        global $DB;

        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_wbagent_ai_messages');
        $index = new \xmldb_index('useridthreadidx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'threadid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $field = new \xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'threadid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $threada = (int)$DB->insert_record('local_wbagent_ai_threads', (object)[
            'userid' => 301,
            'cmid' => 11,
            'bookingid' => 21,
            'status' => 'archived',
            'metadatajson' => null,
            'timecreated' => time() - 200,
            'timemodified' => time() - 200,
        ]);
        $threadb = (int)$DB->insert_record('local_wbagent_ai_threads', (object)[
            'userid' => 302,
            'cmid' => 11,
            'bookingid' => 21,
            'status' => 'archived',
            'metadatajson' => null,
            'timecreated' => time() - 100,
            'timemodified' => time() - 100,
        ]);

        $messagea = (int)$DB->insert_record('local_wbagent_ai_messages', (object)[
            'threadid' => $threada,
            'role' => 'user',
            'content' => 'Upgrade A',
            'structuredjson' => null,
            'timecreated' => time() - 90,
        ]);
        $messageb = (int)$DB->insert_record('local_wbagent_ai_messages', (object)[
            'threadid' => $threadb,
            'role' => 'assistant',
            'content' => 'Upgrade B',
            'structuredjson' => null,
            'timecreated' => time() - 80,
        ]);

        require_once(__DIR__ . '/../../db/upgrade.php');
        xmldb_booking_upgrade(2026042204);

        $notnullfield = new \xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'threadid');
        $this->assertTrue($dbman->field_exists($table, $notnullfield));
        $this->assertTrue($dbman->index_exists($table, $index));

        $rowa = $DB->get_record('local_wbagent_ai_messages', ['id' => $messagea], 'id,userid', MUST_EXIST);
        $rowb = $DB->get_record('local_wbagent_ai_messages', ['id' => $messageb], 'id,userid', MUST_EXIST);

        $this->assertSame(301, (int)$rowa->userid);
        $this->assertSame(302, (int)$rowb->userid);
    }
}
