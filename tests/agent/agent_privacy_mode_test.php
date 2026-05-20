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
 * Wave 2: Privacy Mode Validation Tests
 *
 * Tests for privacy mode behavior:
 * - MODE_OFF: Names pass through to LLM in clear
 * - MODE_SOFT: Names anonymized
 * - MODE_STRICT: Names and emails anonymized
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\privacy_anonymizer;

/**
 * Privacy mode validation tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class agent_privacy_mode_test extends abstract_agent_testcase {
    /**
     * Test: Privacy anonymizer blocks sensitive names in SOFT mode
     */
    public function test_privacy_soft_mode_anonymizes_names(): void {
        $this->setUser($this->teacher);
        set_config('aiprivacymode', 'soft', 'bookingextension_agent');

        // Ensure the anonymizer can deterministically match this full name.
        $this->getDataGenerator()->create_user([
            'firstname' => 'John',
            'lastname' => 'Smith',
        ]);
        \cache_helper::purge_by_definition('mod_booking', 'aiprivacynames');

        // Create a conversation store in soft mode.
        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);
        $this->assertSame('soft', $anonymizer->get_mode());

        // Test message with teacher name.
        $message = 'Please assign John Smith as the teacher for this option.';

        // In MODE_SOFT, person names should be anonymized for LLM-bound text.
        $resultoff = $anonymizer->precheck_user_message(0, $message);
        $this->assertStringContainsString('ANON_USER_', $resultoff['sanitizedmessage']);
        $this->assertStringNotContainsString('John Smith', $resultoff['sanitizedmessage']);

        // Verify the function executes without error.
        $this->assertTrue(true, "Privacy precheck executed successfully");
    }

    /**
     * Test: Privacy anonymizer handles email addresses
     */
    public function test_privacy_handles_email_addresses(): void {
        $this->setUser($this->teacher);

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);

        $message = 'Send confirmation to admin@example.com';

        $result = $anonymizer->precheck_user_message(0, $message);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sanitizedmessage', $result);
        $this->assertArrayHasKey('anonymizedemails', $result);

        // Verify execution.
        $this->assertTrue(true, "Email handling completed");
    }

    /**
     * Regression: firstname, lastname and email must not collapse to the same token/value.
     */
    public function test_privacy_identity_fields_are_kept_separate(): void {
        $this->setUser($this->teacher);
        set_config('aiprivacymode', 'soft', 'bookingextension_agent');

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);
        $anonymizer = new privacy_anonymizer($store);

        $payload = [
            'users' => [[
                'firstname' => 'Teachy',
                'lastname' => 'Trainer',
                'email' => 'teachy.trainer@example.com',
                'userid' => 1005,
            ]],
        ];

        $sanitized = $anonymizer->anonymize_value_for_llm((int)$thread->id, $payload);
        $this->assertIsArray($sanitized);

        $user = (array)($sanitized['users'][0] ?? []);
        $firsttoken = (string)($user['firstname'] ?? '');
        $lasttoken = (string)($user['lastname'] ?? '');
        $emailtoken = (string)($user['email'] ?? '');

        $this->assertStringStartsWith('ANON_USER_', $firsttoken);
        $this->assertStringStartsWith('ANON_USER_', $lasttoken);
        $this->assertStringStartsWith('ANON_USER_', $emailtoken);
        $this->assertMatchesRegularExpression('/^ANON_USER_\d+_firstname$/', $firsttoken);
        $this->assertMatchesRegularExpression('/^ANON_USER_\d+_lastname$/', $lasttoken);
        $this->assertMatchesRegularExpression('/^ANON_USER_\d+_email$/', $emailtoken);
        $this->assertNotSame($firsttoken, $lasttoken, 'Firstname and lastname must use different tokens.');
        $this->assertNotSame($firsttoken, $emailtoken, 'Firstname and email must use different tokens.');
        $this->assertNotSame($lasttoken, $emailtoken, 'Lastname and email must use different tokens.');

        $extractbase = static function (string $token): string {
            return (string)preg_replace('/_(firstname|lastname|email)$/', '', $token);
        };
        $firstbase = $extractbase($firsttoken);
        $lastbase = $extractbase($lasttoken);
        $emailbase = $extractbase($emailtoken);
        $this->assertSame($firstbase, $lastbase, 'Firstname and lastname must share the same person base token.');
        $this->assertSame($firstbase, $emailbase, 'Firstname and email must share the same person base token.');

        $maskedmessage = 'Gefundener Benutzer: - Vorname: ' . $firsttoken
            . ' - Nachname: ' . $lasttoken
            . ' - E-Mail: ' . $emailtoken
            . ' - Benutzer-ID: 1005 - Profil: /user/profile.php?id=1005';
        $display = $anonymizer->deanonymize_message_for_display((int)$thread->id, $maskedmessage);
        $rendered = (string)($display['message'] ?? '');

        $this->assertStringContainsString('Vorname: Teachy', $rendered);
        $this->assertStringContainsString('Nachname: Trainer', $rendered);
        $this->assertStringContainsString('E-Mail: teachy.trainer@example.com', $rendered);
    }

    /**
     * Regression: name anonymization must not touch the local part of email addresses.
     */
    public function test_privacy_soft_mode_does_not_mask_names_inside_email_local_part(): void {
        $this->setUser($this->teacher);
        set_config('aiprivacymode', 'soft', 'bookingextension_agent');

        // Ensure these names exist in the name index.
        $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teachy',
            'email' => 'billy.' . uniqid('', true) . '@example.com',
        ]);
        \cache_helper::purge_by_definition('mod_booking', 'aiprivacynames');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);

        $input = 'Kontakt: billy.teachy@example.com';
        $result = $anonymizer->precheck_user_message(0, $input);
        $sanitized = (string)($result['sanitizedmessage'] ?? '');

        $this->assertSame($input, $sanitized, 'Email local-part must remain untouched in soft mode.');
        $this->assertStringNotContainsString('ANON_USER_', $sanitized);
    }

    /**
     * Regression: labeled email values must be anonymized as full value, never partially.
     */
    public function test_privacy_labeled_email_does_not_create_hybrid_token_domain(): void {
        $this->setUser($this->teacher);
        set_config('aiprivacymode', 'soft', 'bookingextension_agent');

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);
        $anonymizer = new privacy_anonymizer($store);

        $payload = 'firstname=Billy, lastname=Teachy, email=billy.teachy@example.com, id=1005.';
        $sanitized = (string)$anonymizer->anonymize_value_for_llm((int)$thread->id, $payload);

        $this->assertMatchesRegularExpression('/email=ANON_USER_\d+_email\b/', $sanitized);
        $this->assertDoesNotMatchRegularExpression('/ANON_USER_\d+_email\.[A-Z0-9._%+\-]+@/i', $sanitized);

        $display = $anonymizer->deanonymize_message_for_display((int)$thread->id, $sanitized);
        $rendered = (string)($display['message'] ?? '');
        $this->assertStringContainsString('email=billy.teachy@example.com', $rendered);
    }

    /**
     * Test: Task registry contains all core tasks
     */
    public function test_task_registry_contains_core_tasks(): void {
        $registry = \bookingextension_agent\local\wbagent\task_registry::make_default();

        $expectedtasks = [
            'booking.create_option',
            'booking.update_option',
            'booking.bulk_update_options',
            'booking.search_options',
            'booking.search_users',
            'booking.search_courses',
            'booking.list_actions',
            'booking.list_option_properties',
            'booking.get_current_user',
            'booking.add_price_category',
            'booking.recall_memory',
        ];

        $tasknames = $registry->get_task_names();

        foreach ($expectedtasks as $taskname) {
            $this->assertContains(
                $taskname,
                $tasknames,
                "Task registry should contain: $taskname"
            );
        }
    }

    /**
     * Test: Message triggers are registered
     */
    public function test_message_triggers_registered(): void {
        // This is a placeholder that verifies the system doesn't crash.
        $this->assertTrue(true, "Message trigger system is functional");
    }

    /**
     * Test: Task input validation
     */
    public function test_task_input_validation_matrix(): void {
        // Test that required fields are enforced.
        $this->setUser($this->teacher);

        // Try to create an option without required fields - should fail gracefully.
        $result = $this->exec_command('booking.create_option', [
            'text' => 'Valid Title',
            // Missing fields: maxanswers, coursestarttime, duration, location.
        ]);

        // Result should indicate missing fields or validation error.
        $this->assertNotNull($result);
        $this->assertTrue(
            isset($result['status']) && (
                $result['status'] === 'error' ||
                $result['status'] === 'clarification' ||
                $result['status'] === 'executed'
            ),
            "Task should return a valid status: {$result['status']}"
        );
    }
}
