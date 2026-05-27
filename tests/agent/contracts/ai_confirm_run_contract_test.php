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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Contract tests for ai_confirm_run state handling.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 *
 * @covers \bookingextension_agent\external\ai_confirm_run
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_confirm_run_contract_test extends abstract_agent_testcase {
    /**
     * A follow-up pending intent for the next queued mutation must always
     * surface as confirmation_request so autoconfirm can continue.
     */
    public function test_follow_up_pending_intent_forces_confirmation_request(): void {
        global $DB;

        $this->setUser($this->teacher);

        $registry = task_registry::make_default();
        $task = $registry->get_task('mod_booking.create_option_normal');
        if ($task === null) {
            $this->markTestSkipped('mod_booking.create_option_normal is not available in the current task catalog.');
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid, (int)$this->booking->id);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $DB->delete_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Follow-up contract option %',
        ]);

        $command1 = [
            'task' => 'mod_booking.create_option_normal',
            'version' => 1,
            'input' => [
                'text' => 'Follow-up contract option 1',
            ],
        ];
        $command2 = [
            'task' => 'mod_booking.create_option_normal',
            'version' => 1,
            'input' => [
                'text' => 'Follow-up contract option 2',
            ],
        ];

        $preflight1 = $task->preflight((array)$command1['input'], $contextid, $userid);
        $preflight2 = $task->preflight((array)$command2['input'], $contextid, $userid);
        $this->assertSame('pass', $preflight1->status);
        $this->assertSame('pass', $preflight2->status);

        $queued1 = $queuesvc->enqueue_command($threadid, 0, 0, $command1, 'mutating', 'blocked_confirmation');
        $queuesvc->set_prepared_input(
            $threadid,
            (string)$queued1['queue_item_id'],
            $contextid,
            $preflight1->preparedinput
        );

        $queued2 = $queuesvc->enqueue_command(
            $threadid,
            0,
            0,
            $command2,
            'mutating',
            'blocked_confirmation',
            [(string)$queued1['queue_item_id']]
        );
        $queuesvc->set_prepared_input(
            $threadid,
            (string)$queued2['queue_item_id'],
            $contextid,
            $preflight2->preparedinput
        );

        $store->set_pending_intent(
            $threadid,
            [],
            hash('sha256', $userid . ':' . $threadid . ':initial'),
            $userid,
            $contextid,
            [
                'queue_item_ids' => [
                    (string)$queued1['queue_item_id'],
                    (string)$queued2['queue_item_id'],
                ],
                'queue_authoritative' => true,
            ]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute(
            (int)$this->booking->cmid,
            $threadid,
            (string)$queued1['queue_item_id'],
            true
        );

        $this->assertTrue((bool)($result['success'] ?? false), 'First queued mutation should execute successfully.');
        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'A fresh follow-up pending intent must surface as confirmation_request.'
        );
        $this->assertSame(1, (int)($result['autoconfirm'] ?? 0), 'Autoconfirm must remain active for the follow-up step.');
        $this->assertSame((string)$queued2['queue_item_id'], (string)($result['queueitemid'] ?? ''));

        $pendingintent = $store->get_pending_intent($threadid);
        $this->assertIsArray($pendingintent, 'Expected next pending intent for the remaining queue item.');
        $this->assertSame(
            [(string)$queued2['queue_item_id']],
            array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))))
        );

        $created = $DB->get_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Follow-up contract option %',
        ]);
        $this->assertCount(1, $created, 'Exactly the first queued mutation should have executed so far.');
    }
}
