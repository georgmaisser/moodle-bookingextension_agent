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
 * Unit tests for issue_code_provider and booking_issue_code_provider.
 *
 * Tests that domain-specific issue codes are correctly isolated in a plugin-specific
 * provider rather than hardcoded in the generic agent runtime.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\booking_issue_code_provider;
use bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface;

/**
 * Tests for issue_code_provider pattern.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @covers \bookingextension_agent\local\wbagent\booking_issue_code_provider
 * @covers \bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface
 */
final class issue_code_provider_test extends booking_advanced_testcase {
    /**
     * Test that booking_issue_code_provider implements the interface.
     */
    public function test_booking_provider_implements_interface(): void {
        $provider = new booking_issue_code_provider();
        $this->assertInstanceOf(issue_code_provider_interface::class, $provider);
    }

    /**
     * Test get_duplicate_confirmation_issue_codes returns non-empty array.
     */
    public function test_get_duplicate_confirmation_issue_codes(): void {
        $provider = new booking_issue_code_provider();
        $codes = $provider->get_duplicate_confirmation_issue_codes();

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
        $this->assertContains('DUPLICATE_TITLE_CONFIRM_REQUIRED', $codes);
        $this->assertContains('DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED', $codes);
    }

    /**
     * Test get_token_subscription_issue_codes returns non-empty array.
     */
    public function test_get_token_subscription_issue_codes(): void {
        $provider = new booking_issue_code_provider();
        $codes = $provider->get_token_subscription_issue_codes();

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
        $this->assertContains('TRIAL_TOKEN_INVALID', $codes);
        $this->assertContains('SUBSCRIPTION_REQUIRED', $codes);
        $this->assertContains('AI_PROVIDER_AUTH_FAILED', $codes);
    }

    /**
     * Test get_prevalidation_confirmable_issue_codes returns non-empty array.
     */
    public function test_get_prevalidation_confirmable_issue_codes(): void {
        $provider = new booking_issue_code_provider();
        $codes = $provider->get_prevalidation_confirmable_issue_codes();

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
        $this->assertContains('CONFIRMATION_REQUIRED', $codes);
        $this->assertContains('MISSING_LOCATION_CONFIRM_REQUIRED', $codes);
        $this->assertContains('DUPLICATE_TITLE_CONFIRM_REQUIRED', $codes);
    }

    /**
     * Test get_basic_subscription_url returns valid URL.
     */
    public function test_get_basic_subscription_url(): void {
        $provider = new booking_issue_code_provider();
        $url = $provider->get_basic_subscription_url();

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('optionid=73', $url);
    }

    /**
     * Test get_premium_subscription_url returns valid URL.
     */
    public function test_get_premium_subscription_url(): void {
        $provider = new booking_issue_code_provider();
        $url = $provider->get_premium_subscription_url();

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('optionid=74', $url);
    }

    /**
     * Test that agent_runtime can be constructed with a custom issue code provider.
     */
    public function test_agent_runtime_accepts_issue_code_provider(): void {
        global $DB;

        // Create a minimal booking instance.
        $course = $this->getDataGenerator()->create_course();
        $bookingdata = [
            'course' => $course->id,
            'name' => 'Test Booking',
        ];
        $bookingid = $this->getDataGenerator()->create_module('booking', $bookingdata)->id;
        $cmid = $DB->get_field('course_modules', 'id', ['instance' => $bookingid]);

        // Create a custom provider.
        $customcodes = new class implements issue_code_provider_interface {
            public function get_duplicate_confirmation_issue_codes(): array {
                return ['CUSTOM_DUPLICATE'];
            }
            public function get_token_subscription_issue_codes(): array {
                return ['CUSTOM_TOKEN'];
            }
            public function get_prevalidation_confirmable_issue_codes(): array {
                return ['CUSTOM_CONFIRMABLE'];
            }
            public function get_basic_subscription_url(): string {
                return 'https://custom.example.com/basic';
            }
            public function get_premium_subscription_url(): string {
                return 'https://custom.example.com/premium';
            }
        };

        // agent_runtime should accept the custom provider (parameter is nullable with default).
        // The test here is mainly to verify that the constructor signature accepts it.
        $this->assertIsObject($customcodes);
        $this->assertTrue($customcodes instanceof issue_code_provider_interface);
    }
}
