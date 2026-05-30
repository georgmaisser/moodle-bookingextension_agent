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

namespace bookingextension_agent\local\wbagent\tests;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\services\pending_intent_service;
use bookingextension_agent\local\wbagent\services\queue_transition_service;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for pending intent and queue transition services.
 *
 * @covers \bookingextension_agent\local\wbagent\services\pending_intent_service
 * @covers \bookingextension_agent\local\wbagent\services\queue_transition_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending_intent_and_queue_transition_contract_test extends TestCase {
    /**
     * Pending intent service writes and returns confirmation code from store.
     */
    public function test_pending_intent_service_set_returns_confirmation_code(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_pending_intent', 'get_pending_intent'])
            ->getMock();

        $store->expects($this->once())
            ->method('set_pending_intent');
        $store->expects($this->once())
            ->method('get_pending_intent')
            ->with(42)
            ->willReturn(['confirmationcode' => 'C123456']);

        $service = new pending_intent_service($store);
        $code = $service->set(42, 7, 99, [
            'queue_item_ids' => ['q1'],
        ]);

        $this->assertSame('C123456', $code);
    }

    /**
     * Queue transition service maps retry_waiting transition to canonical update_status call.
     */
    public function test_queue_transition_service_retry_waiting_transition(): void {
        $queue = $this->getMockBuilder(queue_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['update_status'])
            ->getMock();

        $queue->expects($this->once())
            ->method('update_status')
            ->with(
                12,
                'q12_1',
                'retry_waiting',
                ['TRANSIENT_IO'],
                'transient_io',
                'temporary I/O issue',
                [
                    'retry_count' => 2,
                    'retry_after_ms' => 500,
                    'reason_code' => 'EXECUTION_RETRY_HINT',
                ]
            );

        $service = new queue_transition_service();
        $service->to_retry_waiting(
            $queue,
            12,
            'q12_1',
            'EXECUTION_RETRY_HINT',
            ['TRANSIENT_IO'],
            'transient_io',
            'temporary I/O issue',
            ['retry_count' => 2, 'retry_after_ms' => 500]
        );
    }
}
