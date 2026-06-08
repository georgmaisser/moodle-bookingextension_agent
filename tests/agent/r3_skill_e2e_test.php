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
 * End-to-end risk-class R3 confirmation flow test.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\services\pending_intent_service;

/**
 * Contract-level E2E check for R3 queue execution semantics.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class r3_skill_e2e_test extends abstract_agent_testcase {
    /**
     * R3 commands must stay in blocked_confirmation before confirm and never
     * transition to retry_waiting after execution attempt.
     */
    public function test_r3_book_users_confirm_flow_never_enters_retry_waiting(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('R3 E2E Option ' . uniqid('', true));
        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'R3',
            'lastname' => 'Student',
            'email' => 'r3.student.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$this->course->id, 'student');

        $contextid = $this->booking_contextid();
        [$store, , $threadid] = $this->build_runtime();

        $queuesvc = new queue_manager($store);

        $command = [
            'skill' => 'mod_booking.book_users',
            'input' => [
                'optionid' => (int)$option->id,
                'bookusersquery' => (string)$student->email,
            ],
            'risk_class' => skill_risk_class::R3,
        ];

        $queueditem = $queuesvc->enqueue_command(
            $threadid,
            1,
            1,
            $command,
            'mutating',
            'blocked_confirmation',
            []
        );
        $queueitemid = (string)($queueditem['queue_item_id'] ?? '');
        $this->assertNotSame('', $queueitemid);

        $pendingintent = new pending_intent_service($store);
        $pendingintent->set(
            $threadid,
            (int)$this->teacher->id,
            $contextid,
            ['queue_item_ids' => [$queueitemid]]
        );

        $beforeconfirm = $queuesvc->get_queue_item($threadid, $queueitemid);
        $this->assertNotNull($beforeconfirm);
        $this->assertSame('blocked_confirmation', (string)($beforeconfirm['status'] ?? ''));
        $this->assertSame(skill_risk_class::R3, (string)($beforeconfirm['risk_class'] ?? ''));

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute((int)$this->booking_contextid(), $threadid, $queueitemid, false);

        $this->assertTrue((bool)($result['success'] ?? false), (string)($result['message'] ?? ''));

        $afterconfirm = $queuesvc->get_queue_item($threadid, $queueitemid);
        $this->assertNotNull($afterconfirm);

        $status = (string)($afterconfirm['status'] ?? '');
        $this->assertNotSame(
            'retry_waiting',
            $status,
            'R3 executions must not enter retry_waiting after confirmation.'
        );
        $this->assertContains($status, ['succeeded', 'failed']);
    }
}
