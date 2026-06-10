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

namespace bookingextension_agent\privacy;

use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for bookingextension_agent.
 *
 * Covers user-identifiable agent data stored at user-context level. Currently the
 * user-stated memory table; future agent tables needing coverage live here too.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /** User memory table. */
    private const MEMORY_TABLE = 'local_wbagent_user_memory';

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            self::MEMORY_TABLE,
            [
                'userid' => 'privacy:metadata:local_wbagent_user_memory:userid',
                'memory' => 'privacy:metadata:local_wbagent_user_memory:memory',
                'timecreated' => 'privacy:metadata:local_wbagent_user_memory:timecreated',
                'timemodified' => 'privacy:metadata:local_wbagent_user_memory:timemodified',
            ],
            'privacy:metadata:local_wbagent_user_memory'
        );

        return $collection;
    }

    /**
     * Return the user contexts holding personal data for the given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists(self::MEMORY_TABLE, ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Return the users having personal data within the given context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }

        if ($DB->record_exists(self::MEMORY_TABLE, ['userid' => $context->instanceid])) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export the personal data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user || (int)$context->instanceid !== (int)$userid) {
                continue;
            }

            $records = $DB->get_records(self::MEMORY_TABLE, ['userid' => $userid], 'timecreated ASC, id ASC');
            if (empty($records)) {
                continue;
            }

            $data = [];
            foreach ($records as $record) {
                $data[] = (object)[
                    'memory' => (string)$record->memory,
                    'timecreated' => \core_privacy\local\request\transform::datetime((int)$record->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime((int)$record->timemodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:metadata:local_wbagent_user_memory', 'bookingextension_agent')],
                (object)['memories' => $data]
            );
        }
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_user) {
            return;
        }

        $DB->delete_records(self::MEMORY_TABLE, ['userid' => $context->instanceid]);
    }

    /**
     * Delete data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user && (int)$context->instanceid === (int)$userid) {
                $DB->delete_records(self::MEMORY_TABLE, ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete data for the approved set of users in the given context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }

        $userid = $context->instanceid;
        if (in_array($userid, $userlist->get_userids())) {
            $DB->delete_records(self::MEMORY_TABLE, ['userid' => $userid]);
        }
    }
}
