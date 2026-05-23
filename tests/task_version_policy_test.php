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

use bookingextension_agent\local\wbagent\services\task_version_policy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for task version policy evaluation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class task_version_policy_test extends TestCase {
    /**
     * Unsupported version above current must hard-fail.
     */
    public function test_evaluate_reports_unsupported_for_version_above_supported(): void {
        $policy = new task_version_policy();

        $result = $policy->evaluate([
            'version' => 1,
        ], 2);

        $this->assertSame(task_version_policy::STATUS_UNSUPPORTED, $result['status']);
        $this->assertContains(task_version_policy::ISSUE_UNSUPPORTED, $result['issue_codes']);
    }

    /**
     * Deprecated contract marker must produce deprecation signal.
     */
    public function test_evaluate_reports_deprecated_when_contract_has_deprecated_since(): void {
        $policy = new task_version_policy();

        $result = $policy->evaluate([
            'version' => 1,
            'deprecated_since' => '2026-01',
        ], 1);

        $this->assertSame(task_version_policy::STATUS_DEPRECATED, $result['status']);
        $this->assertContains(task_version_policy::ISSUE_DEPRECATED, $result['issue_codes']);
    }

    /**
     * Matching supported version without deprecation should pass cleanly.
     */
    public function test_evaluate_reports_supported_for_current_version(): void {
        $policy = new task_version_policy();

        $result = $policy->evaluate([
            'version' => 1,
            'deprecated_since' => '',
        ], 1);

        $this->assertSame(task_version_policy::STATUS_SUPPORTED, $result['status']);
        $this->assertSame([], $result['issue_codes']);
    }
}
