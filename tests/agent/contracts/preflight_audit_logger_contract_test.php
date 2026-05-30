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
use bookingextension_agent\local\wbagent\services\preflight_audit_logger;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for audit reason-code monitoring summary.
 *
 * @covers \bookingextension_agent\local\wbagent\services\preflight_audit_logger
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preflight_audit_logger_contract_test extends TestCase {
    /**
     * Summary groups events by reason_code, layer and status.
     */
    public function test_summarize_reason_codes_groups_counts(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_thread_metadata_value'])
            ->getMock();

        $store->expects($this->once())
            ->method('get_thread_metadata_value')
            ->with(77, '_preflight_audit_log')
            ->willReturn([
                [
                    'reason_code' => 'EXECUTION_SUCCEEDED',
                    'layer' => 'execution',
                    'status' => 'succeeded',
                ],
                [
                    'reason_code' => 'EXECUTION_SUCCEEDED',
                    'layer' => 'execution',
                    'status' => 'succeeded',
                ],
                [
                    'reason_code' => 'EXECUTION_RETRY_HINT',
                    'layer' => 'execution',
                    'status' => 'retry_waiting',
                ],
                [
                    'reason_code' => '',
                    'layer' => 'preflight',
                    'status' => 'hard_block',
                ],
            ]);

        $logger = new preflight_audit_logger($store);
        $summary = $logger->summarize_reason_codes(77);

        $this->assertSame(4, $summary['total_events']);
        $this->assertSame(2, $summary['reason_code_counts']['EXECUTION_SUCCEEDED']);
        $this->assertSame(1, $summary['reason_code_counts']['EXECUTION_RETRY_HINT']);
        $this->assertSame(1, $summary['reason_code_counts']['UNSPECIFIED']);
        $this->assertSame(3, $summary['layer_counts']['execution']);
        $this->assertSame(1, $summary['layer_counts']['preflight']);
        $this->assertSame(2, $summary['status_counts']['succeeded']);
        $this->assertSame(1, $summary['status_counts']['retry_waiting']);
        $this->assertSame(1, $summary['status_counts']['hard_block']);
    }
}
