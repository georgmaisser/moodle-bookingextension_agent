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

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;

/**
 * A new message is activity on its thread: add_message() moves the thread's timemodified, so the
 * thread's last activity can be read from the thread row (Wunderbyte-GmbH/Wunderbyte-GmbH#2585).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\conversation_store
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class conversation_store_message_activity_test extends advanced_testcase {
    /**
     * Create a thread whose timemodified lies 100 days in the past.
     *
     * @param conversation_store $store
     * @return int Thread id.
     */
    private function create_old_thread(conversation_store $store): int {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $thread = $store->get_or_create_thread((int)$user->id, (int)\context_system::instance()->id);
        $DB->set_field('bx_agent_ai_threads', 'timemodified', time() - (100 * DAYSECS), ['id' => $thread->id]);

        return (int)$thread->id;
    }

    /**
     * Adding a user or assistant message updates the thread's timemodified.
     */
    public function test_add_message_updates_thread_timemodified(): void {
        global $DB;
        $this->resetAfterTest();

        $store = new conversation_store();
        $threadid = $this->create_old_thread($store);

        $before = time();
        $messageid = $store->add_message($threadid, 'user', 'hello');

        $timemodified = (int)$DB->get_field('bx_agent_ai_threads', 'timemodified', ['id' => $threadid]);
        $messagetime = (int)$DB->get_field('bx_agent_ai_messages', 'timecreated', ['id' => $messageid]);
        $this->assertGreaterThanOrEqual($before, $timemodified);
        $this->assertGreaterThanOrEqual($messagetime, $timemodified);
    }

    /**
     * A step message goes through the same path and also counts as activity.
     */
    public function test_add_step_message_updates_thread_timemodified(): void {
        global $DB;
        $this->resetAfterTest();

        $store = new conversation_store();
        $threadid = $this->create_old_thread($store);

        $before = time();
        $store->add_step_message($threadid, 1, 'Step 1');

        $timemodified = (int)$DB->get_field('bx_agent_ai_threads', 'timemodified', ['id' => $threadid]);
        $this->assertGreaterThanOrEqual($before, $timemodified);
    }
}
