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

use bookingextension_agent\local\wbagent\services\preflight_version_validator;
use bookingextension_agent\local\wbagent\services\task_version_policy;
use bookingextension_agent\local\wbagent\task_registry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for layer-1 task version validation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class preflight_version_validator_test extends TestCase {
    /**
     * Unknown task names must fail with TASK_NOT_REGISTERED.
     */
    public function test_validate_rejects_unregistered_task(): void {
        $registry = $this->createMock(task_registry::class);
        $registry->method('get_task_contract')->willReturn(null);

        $validator = new preflight_version_validator($registry);
        $result = $validator->validate([
            'task' => 'booking.unknown_task',
            'input' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains(preflight_version_validator::ISSUE_TASK_NOT_REGISTERED, $result['issue_codes']);
    }

    /**
     * Higher command task_version than supported must fail.
     */
    public function test_validate_rejects_unsupported_task_version(): void {
        $registry = $this->createMock(task_registry::class);
        $registry->method('get_task_contract')->willReturn([
            'taskname' => 'booking.create_option',
            'version' => 1,
            'deprecated_since' => '',
        ]);

        $validator = new preflight_version_validator($registry);
        $result = $validator->validate([
            'task' => 'booking.create_option',
            'task_version' => 2,
            'input' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains(task_version_policy::ISSUE_UNSUPPORTED, $result['issue_codes']);
    }

    /**
     * Runtime command payloads using version should be accepted as the canonical field.
     */
    public function test_validate_accepts_runtime_version_field(): void {
        $registry = $this->createMock(task_registry::class);
        $registry->method('get_task_contract')->willReturn([
            'taskname' => 'booking.create_option',
            'version' => 1,
            'deprecated_since' => '',
        ]);

        $validator = new preflight_version_validator($registry);
        $result = $validator->validate([
            'task' => 'booking.create_option',
            'version' => 1,
            'input' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['issue_codes']);
    }

    /**
     * Deprecated task metadata should surface warning issue code but still validate.
     */
    public function test_validate_returns_deprecated_issue_for_supported_deprecated_task(): void {
        $registry = $this->createMock(task_registry::class);
        $registry->method('get_task_contract')->willReturn([
            'taskname' => 'booking.create_option',
            'version' => 1,
            'deprecated_since' => '2026-01',
        ]);

        $validator = new preflight_version_validator($registry);
        $result = $validator->validate([
            'task' => 'booking.create_option',
            'task_version' => 1,
            'input' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertContains(task_version_policy::ISSUE_DEPRECATED, $result['issue_codes']);
    }
}
