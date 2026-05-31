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
     * Terminal confirm success (no further mutating queue item) must run finalizer polish path.
     */
    public function test_terminal_confirm_success_triggers_finalizer_when_no_follow_up_queue_item_exists(): void {
        global $DB;

        $this->setUser($this->teacher);

        $registry = task_registry::make_default();
        $task = $registry->get_task('mod_booking.create_option');
        if ($task === null) {
            $this->fail('mod_booking.create_option is not available in the current task catalog.');
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid, (int)$this->booking->id);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $DB->delete_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Terminal finalizer contract option %',
        ]);

        $command = [
            'task' => 'mod_booking.create_option',
            'version' => 1,
            'input' => [
                'text' => 'Terminal finalizer contract option 1',
            ],
        ];

        $preflight = $task->preflight((array)$command['input'], $contextid, $userid);
        $this->assertSame('pass', $preflight->status);

        $queued = $queuesvc->enqueue_command($threadid, 0, 0, $command, 'mutating', 'blocked_confirmation');
        $queueitemid = (string)$queued['queue_item_id'];
        $queuesvc->set_prepared_input(
            $threadid,
            $queueitemid,
            $contextid,
            $preflight->preparedinput
        );

        $store->set_pending_intent(
            $threadid,
            hash('sha256', $userid . ':' . $threadid . ':terminal'),
            $userid,
            $contextid,
            ['queue_item_ids' => [$queueitemid]]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute(
            $contextid,
            $threadid,
            $queueitemid,
            true
        );

        $this->assertTrue((bool)($result['success'] ?? false), 'Terminal queued mutation should execute successfully.');
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''),
            'Terminal confirm path without follow-up queue item must end in sufficient.');
        $this->assertSame('', (string)($result['queueitemid'] ?? ''),
            'Terminal confirm path must not expose a follow-up queue item.');

        $pendingintent = $store->get_pending_intent($threadid);
        $this->assertNull($pendingintent, 'No pending intent should remain in terminal confirm path.');

        $created = $DB->get_records_select('booking_options', 'bookingid = :bookingid AND text = :title', [
            'bookingid' => (int)$this->booking->id,
            'title' => 'Terminal finalizer contract option 1',
        ]);
        $this->assertCount(1, $created, 'Terminal confirm path must execute the queued mutation exactly once.');

        $entries = $DB->get_records('local_wbagent_ai_llm_debug', ['threadid' => $threadid], 'id ASC');
        $this->assertNotEmpty($entries, 'Expected LLM debug entries for terminal confirm thread.');

        $hassynchronizercall = false;
        foreach ($entries as $entry) {
            $source = (string)($entry->source ?? '');
            if (strpos($source, 'st=final_synthesis') !== false || strpos($source, 'ac=wpr') !== false) {
                $hassynchronizercall = true;
                break;
            }
        }

        $this->assertTrue(
            $hassynchronizercall,
            'Terminal confirm path without follow-up queue item must call final synthesis/finalizer (st=final_synthesis or ac=wpr).'
        );
    }

    /**
     * A follow-up pending intent for the next queued mutation must always
     * surface as confirmation_request so autoconfirm can continue.
     */
    public function test_follow_up_pending_intent_forces_confirmation_request(): void {
        global $DB;

        $this->setUser($this->teacher);

        $registry = task_registry::make_default();
        $task = $registry->get_task('mod_booking.create_option');
        if ($task === null) {
            $this->fail('mod_booking.create_option is not available in the current task catalog.');
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
            'task' => 'mod_booking.create_option',
            'version' => 1,
            'input' => [
                'text' => 'Follow-up contract option 1',
            ],
        ];
        $command2 = [
            'task' => 'mod_booking.create_option',
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
            hash('sha256', $userid . ':' . $threadid . ':initial'),
            $userid,
            $contextid,
            [
                'queue_item_ids' => [
                    (string)$queued1['queue_item_id'],
                    (string)$queued2['queue_item_id'],
                ],
            ]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute(
            $contextid,
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
        $this->assertContains(
            (int)($result['autoconfirm'] ?? 0),
            [0, 1],
            'Autoconfirm flag must stay in canonical boolean-int range for follow-up step.'
        );
        $queueitemid = (string)($result['queueitemid'] ?? '');
        $this->assertNotSame('', $queueitemid, 'Follow-up step must expose a queue item id.');
        $this->assertNotSame((string)$queued1['queue_item_id'], $queueitemid, 'Follow-up step must advance to a different queue item.');
        $this->assertSame(
            '[]',
            (string)($result['errorsjson'] ?? '[]'),
            'Follow-up confirmation should not surface stale planner errors.'
        );
        $this->assertSame(
            '[]',
            (string)($result['issuecodesjson'] ?? '[]'),
            'Follow-up confirmation should not surface stale planner issue codes.'
        );
        $message = (string)($result['message'] ?? '');
        $messagetext = trim(strtolower(strip_tags($message)));
        $this->assertTrue(
            str_contains($messagetext, 'booking option')
                && (str_contains($messagetext, 'created') || str_contains($messagetext, 'creating')),
            'Follow-up confirmation message should describe the executed create-option step. Message: ' . $message
        );

        $pendingintent = $store->get_pending_intent($threadid);
        $this->assertIsArray($pendingintent, 'Expected next pending intent for the remaining queue item.');
        $pendingqueueids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        $this->assertNotEmpty($pendingqueueids, 'Expected a remaining pending queue item after the first execution.');
        $this->assertContains($queueitemid, $pendingqueueids, 'Follow-up queue id must be tracked in pending intent.');

        $created = $DB->get_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Follow-up contract option %',
        ]);
        $this->assertCount(1, $created, 'Exactly the first queued mutation should have executed so far.');

        $_POST['sesskey'] = sesskey();
        $result2 = ai_confirm_run::execute(
            $contextid,
            $threadid,
            (string)$queued2['queue_item_id'],
            true
        );

        $this->assertTrue((bool)($result2['success'] ?? false), 'Second queued mutation should execute successfully.');
        $previewids = json_decode((string)($result2['previewoptionidsjson'] ?? '[]'), true);
        $this->assertIsArray($previewids);
        $this->assertCount(
            2,
            array_values(array_unique(array_map('intval', $previewids))),
            'Preview ids should aggregate all created options across the confirm chain.'
        );
    }
}
