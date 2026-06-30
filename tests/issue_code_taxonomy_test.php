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
 * Tests for the canonical issue_code_taxonomy (audit C3-F02 / 08-F01).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\issue_code_taxonomy;

/**
 * Locks the two issue-code views, including their DIFFERENT match precedence.
 *
 * @covers \bookingextension_agent\local\wizard\services\issue_code_taxonomy
 */
final class issue_code_taxonomy_test extends \advanced_testcase {
    /**
     * error_class_for: first match wins (TIMEOUT before TRANSIENT/PERMISSION/CONFLICT/VALIDATION).
     */
    public function test_error_class_precedence(): void {
        $this->assertSame('provider_timeout', issue_code_taxonomy::error_class_for(['SOME_TIMEOUT']));
        $this->assertSame('transient_io', issue_code_taxonomy::error_class_for(['TRANSIENT_IO']));
        $this->assertSame('permission_error', issue_code_taxonomy::error_class_for(['PERMISSION_DENIED']));
        $this->assertSame('domain_conflict', issue_code_taxonomy::error_class_for(['DOMAIN_CONFLICT']));
        $this->assertSame('validation_error', issue_code_taxonomy::error_class_for(['MISSING_FIELD']));
        $this->assertSame('', issue_code_taxonomy::error_class_for(['AUTH_FAILED']));
        // TIMEOUT is checked before PERMISSION, so a code carrying both is a timeout here.
        $this->assertSame('provider_timeout', issue_code_taxonomy::error_class_for(['PERMISSION_TIMEOUT']));
    }

    /**
     * retry_category_for: DOMAIN-set is checked before TECHNICAL — the OPPOSITE precedence to
     * error_class_for, which is exactly why the two views can't share one ordered table.
     */
    public function test_retry_category_precedence_differs(): void {
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_DOMAIN,
            issue_code_taxonomy::retry_category_for('', ['PERMISSION_TIMEOUT'], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('', ['SOME_TIMEOUT'], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_EXTERNAL_DEPENDENCY,
            issue_code_taxonomy::retry_category_for('', ['RATE_LIMIT'], '')
        );
    }

    /**
     * retry_category_for: error-class and layer fallbacks when no issue code matches.
     */
    public function test_retry_category_fallbacks(): void {
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('provider_timeout', [], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('something', [], 'execution')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_UNDEFINED,
            issue_code_taxonomy::retry_category_for('', [], 'execution')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_UNDEFINED,
            issue_code_taxonomy::retry_category_for('', [], '')
        );
    }
}
