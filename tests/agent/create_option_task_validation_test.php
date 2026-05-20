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
 * Validation tests for create_option_task.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\booking\tasks\create_option_task;

/**
 * Task-level tests for explicit override behavior.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_option_task_validation_test extends booking_advanced_testcase {
    /** @var int */
    private int $cmid;

    /**
     * Set up course module context.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Task Validation Booking',
        ]);
        $this->cmid = (int)$booking->cmid;
    }

    /**
     * Missing location should be allowed when explicit location/address override is present.
     */
    public function test_allows_missing_location_with_override(): void {
        global $USER;

        $task = new create_option_task();

        $result = $task->validate([
            'text' => 'Meine Veranstaltung um 12',
            'maxanswers' => 20,
            'coursestarttime' => '2036-06-04T12:00:00',
            'duration' => 3600,
            'teacheremail' => (string)$USER->email,
            'override' => ['location', 'address'],
        ], $this->cmid);

        $this->assertTrue($result['valid'], implode(' | ', $result['errors'] ?? []));
        $this->assertEmpty($result['errors']);
    }

    /**
     * Missing location without override must not block preflight anymore.
     */
    public function test_missing_location_without_override_is_valid(): void {
        global $USER;

        $task = new create_option_task();

        $result = $task->validate([
            'text' => 'Meine Veranstaltung um 12',
            'maxanswers' => 20,
            'coursestarttime' => '2036-06-04T12:00:00',
            'duration' => 3600,
            'teacheremail' => (string)$USER->email,
        ], $this->cmid);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Title-only payload must pass validation.
     */
    public function test_title_only_payload_is_valid(): void {
        $task = new create_option_task();

        $result = $task->validate([
            'text' => 'Nur Titel',
        ], $this->cmid);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Common aliases should be accepted and normalized to canonical create_option keys.
     */
    public function test_aliases_are_normalized_for_create_option(): void {
        global $USER;

        $task = new create_option_task();

        $result = $task->validate([
            'title' => 'Aliased Option Title',
            'limit' => 25,
            'starttime' => '2036-06-04T12:00:00',
            'endtime' => '2036-06-04T14:00:00',
            'teacheremail' => (string)$USER->email,
            'location' => 'Room 1',
        ], $this->cmid);

        $this->assertTrue($result['valid'], implode(' | ', $result['errors'] ?? []));
        $this->assertEmpty($result['errors']);
    }

    /**
     * Structural validation should return a clear retry hint for bad keys.
     */
    public function test_missing_title_returns_retry_guidance(): void {
        $task = new create_option_task();

        $result = $task->check_structure([
            'foo' => 'bar',
            'limit' => 7,
            'starttime' => '2045-11-01T09:00:00',
            'endtime' => '2045-11-01T11:00:00',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('observation_full', $result);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString(
            'Retry booking.create_option once with corrected canonical keys.',
            $observation
        );
        $this->assertStringContainsString('EN label -> key map:', $observation);
        $this->assertStringContainsString('Applied alias mapping: limit -> maxanswers', $observation);
        $this->assertStringContainsString('Remove unknown keys: foo', $observation);
    }
}
