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

declare(strict_types=1);

namespace bookingextension_agent\tests;

use bookingextension_agent\local\wbagent\task_contract_validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for task contract validator metadata checks.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_contract_validator_test extends TestCase {
    /**
     * Metadata with required fields should validate.
     */
    public function test_validate_task_metadata_accepts_valid_metadata(): void {
        $meta = [
            'taskname' => 'booking.create_option',
            'version' => 1,
            'capabilities' => ['mod/booking:writeoption'],
            'active' => true,
            'alias_of' => '',
            'deprecated_since' => '',
        ];

        $result = task_contract_validator::validate_task_metadata($meta);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * Missing taskname must be rejected.
     */
    public function test_validate_task_metadata_rejects_missing_taskname(): void {
        $meta = [
            'version' => 1,
            'capabilities' => ['mod/booking:writeoption'],
            'active' => true,
        ];

        $result = task_contract_validator::validate_task_metadata($meta);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field: taskname.', $result['errors']);
    }

    /**
     * Non-array capabilities must be rejected.
     */
    public function test_validate_task_metadata_rejects_invalid_capabilities(): void {
        $meta = [
            'taskname' => 'booking.create_option',
            'version' => 1,
            'capabilities' => 'mod/booking:writeoption',
            'active' => true,
        ];

        $result = task_contract_validator::validate_task_metadata($meta);

        $this->assertFalse($result['valid']);
        $this->assertContains('Invalid required field: capabilities must be a string array.', $result['errors']);
    }

    /**
     * Registry-level alias conflicts must be reported.
     */
    public function test_validate_registry_contracts_reports_missing_alias_target(): void {
        $contracts = [
            'booking.alias_task' => [
                'taskname' => 'booking.alias_task',
                'version' => 1,
                'capabilities' => [],
                'active' => true,
                'alias_of' => 'booking.missing_task',
                'deprecated_since' => '',
            ],
        ];

        $errors = task_contract_validator::validate_registry_contracts($contracts);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Alias target not found', $errors[0]);
    }

    /**
     * Task capability names should be built deterministically from component and task.
     */
    public function test_build_task_capability_name_is_deterministic(): void {
        $capability = task_contract_validator::build_task_capability_name(
            'bookingextension_agent',
            'booking.create_option'
        );

        $this->assertSame('bookingextension/agent:task_booking_create_option', $capability);
    }

    /**
     * Empty component or task should return empty capability name.
     */
    public function test_build_task_capability_name_empty_parts_return_empty(): void {
        $this->assertSame('', task_contract_validator::build_task_capability_name('', 'booking.create_option'));
        $this->assertSame('', task_contract_validator::build_task_capability_name('bookingextension_agent', ''));
    }
}
