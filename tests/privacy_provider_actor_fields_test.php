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

namespace bookingextension_agent;

use bookingextension_agent\privacy\provider;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The privacy provider covers the two site-wide tables that name the acting user.
 *
 * Marketplace review MMR-403 (Wunderbyte-GmbH/moodle-local_wizard#13): the pinned benchmark baselines (createdby)
 * and the site search scope rules (usermodified) were missing from metadata, context discovery, export and deletion.
 * Both are site configuration: the rows stay, a deletion request clears the reference to the user.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\privacy\provider
 */
final class privacy_provider_actor_fields_test extends \core_privacy\tests\provider_testcase {
    /**
     * Store one pinned baseline and one scope rule written by the user.
     *
     * @param int $userid
     * @param string $label
     * @return void
     */
    private function write_actor_rows(int $userid, string $label): void {
        global $DB;
        $DB->insert_record('bx_agent_bm_baselines', (object)[
            'run_id' => 1,
            'label' => $label,
            'locked' => 0,
            'description' => 'Baseline of ' . $label,
            'createdby' => $userid,
            'timecreated' => time(),
        ]);
        $DB->insert_record('bx_agent_search_scope', (object)[
            'area' => 'mod_forum-post',
            'scopetype' => 'course',
            'scopeid' => $userid,
            'enabled' => 1,
            'includefiles' => 0,
            'usermodified' => $userid,
            'timemodified' => time(),
        ]);
    }

    /**
     * Both tables are declared in the metadata.
     */
    public function test_metadata_declares_baselines_and_scope_rules(): void {
        $collection = provider::get_metadata(new collection('bookingextension_agent'));
        $fields = [];
        foreach ($collection->get_collection() as $item) {
            $fields[$item->get_name()] = method_exists($item, 'get_privacy_fields') ? array_keys($item->get_privacy_fields()) : [];
        }

        $this->assertArrayHasKey('bx_agent_bm_baselines', $fields);
        $this->assertContains('createdby', $fields['bx_agent_bm_baselines']);
        $this->assertArrayHasKey('bx_agent_search_scope', $fields);
        $this->assertContains('usermodified', $fields['bx_agent_search_scope']);
    }

    /**
     * The system context is found for the acting user, the rows are exported, and a deletion for the user clears
     * the reference without removing the configuration rows or touching another user's rows.
     */
    public function test_export_and_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->write_actor_rows((int)$user->id, 'mine');
        $this->write_actor_rows((int)$other->id, 'theirs');
        $system = \context_system::instance();

        $this->assertContains(
            (int)$system->id,
            array_map('intval', provider::get_contexts_for_userid((int)$user->id)->get_contextids())
        );
        $this->assertNotContains(
            (int)$system->id,
            array_map('intval', provider::get_contexts_for_userid((int)$bystander->id)->get_contextids())
        );

        $approved = new approved_contextlist($user, 'bookingextension_agent', [$system->id]);
        provider::export_user_data($approved);
        $writer = writer::with_context($system);
        $baselines = $writer->get_data([get_string('privacy:path:baselines', 'bookingextension_agent')]);
        $this->assertNotEmpty($baselines);
        $this->assertCount(1, $baselines->baselines);
        $this->assertSame('mine', reset($baselines->baselines)->label);
        $rules = $writer->get_data([get_string('privacy:path:searchscope', 'bookingextension_agent')]);
        $this->assertNotEmpty($rules);
        $this->assertCount(1, $rules->rules);
        $this->assertSame('mod_forum-post', reset($rules->rules)->area);

        provider::delete_data_for_user($approved);
        $this->assertSame(2, $DB->count_records('bx_agent_bm_baselines'));
        $this->assertSame(2, $DB->count_records('bx_agent_search_scope'));
        $this->assertFalse($DB->record_exists('bx_agent_bm_baselines', ['createdby' => $user->id]));
        $this->assertFalse($DB->record_exists('bx_agent_search_scope', ['usermodified' => $user->id]));
        $this->assertTrue($DB->record_exists('bx_agent_bm_baselines', ['createdby' => $other->id]));
        $this->assertTrue($DB->record_exists('bx_agent_search_scope', ['usermodified' => $other->id]));
        $this->assertNotContains(
            (int)$system->id,
            array_map('intval', provider::get_contexts_for_userid((int)$user->id)->get_contextids())
        );
    }

    /**
     * The acting users are listed for the system context only; a deletion for a set of users clears their
     * references, a deletion for the whole context clears all of them, and the rows stay in both cases.
     */
    public function test_users_in_context_and_delete_for_context(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        foreach ([$user1, $user2, $user3] as $user) {
            $this->write_actor_rows((int)$user->id, 'user' . $user->id);
        }
        $system = \context_system::instance();
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);

        $list = new userlist($system, 'bookingextension_agent');
        provider::get_users_in_context($list);
        $found = array_map('intval', $list->get_userids());
        foreach ([$user1, $user2, $user3] as $user) {
            $this->assertContains((int)$user->id, $found);
        }
        $courselist = new userlist($coursectx, 'bookingextension_agent');
        provider::get_users_in_context($courselist);
        $this->assertSame([], $courselist->get_userids());

        // A deletion in another context leaves the site-wide rows alone.
        provider::delete_data_for_all_users_in_context($coursectx);
        $this->assertTrue($DB->record_exists('bx_agent_bm_baselines', ['createdby' => $user1->id]));

        provider::delete_data_for_users(new approved_userlist($system, 'bookingextension_agent', [$user1->id]));
        $this->assertFalse($DB->record_exists('bx_agent_bm_baselines', ['createdby' => $user1->id]));
        $this->assertFalse($DB->record_exists('bx_agent_search_scope', ['usermodified' => $user1->id]));
        $this->assertTrue($DB->record_exists('bx_agent_bm_baselines', ['createdby' => $user2->id]));
        $this->assertTrue($DB->record_exists('bx_agent_search_scope', ['usermodified' => $user2->id]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertSame(3, $DB->count_records('bx_agent_bm_baselines'));
        $this->assertSame(3, $DB->count_records('bx_agent_search_scope'));
        $this->assertSame(3, $DB->count_records('bx_agent_bm_baselines', ['createdby' => 0]));
        $this->assertSame(3, $DB->count_records('bx_agent_search_scope', ['usermodified' => 0]));
    }
}
