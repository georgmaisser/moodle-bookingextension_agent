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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Permanent architecture contracts for booking AI agent stack.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\message_trigger_registry;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Contract tests intended to detect unnoticed architecture drift.
 *
 * @coversNothing
 */
final class agent_architecture_contract_test extends booking_advanced_testcase {
    /**
     * Core response types must remain available in interpreter output contract.
     */
    public function test_interpreter_allows_core_response_types_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Contract Booking',
        ]);

        $registry = task_registry::make_default();
        $interpreter = new interpreter($registry);

        $cases = [
            ['response_type' => 'clarification', 'message' => 'clarify'],
            ['response_type' => 'error', 'message' => 'error'],
            ['response_type' => 'confirm_pending', 'message' => ''],
        ];

        foreach ($cases as $case) {
            $result = $interpreter->interpret(json_encode($case), (int)$booking->cmid, 1);
            $this->assertSame($case['response_type'], $result['response_type']);
            $this->assertArrayHasKey('commands', $result);
            $this->assertArrayHasKey('ambiguities', $result);
            $this->assertArrayHasKey('errors', $result);
        }
    }

    /**
     * Task registry must contain the mandatory baseline tasks.
     */
    public function test_task_registry_mandatory_baseline_contract(): void {
        $registry = task_registry::make_default();
        $names = $registry->get_task_names();

        $required = [
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

        foreach ($required as $taskname) {
            $this->assertContains($taskname, $names, 'Missing mandatory task: ' . $taskname);
        }
    }

    /**
     * Message trigger catalog must include core trigger ids used by guarded flow control.
     */
    public function test_message_trigger_catalog_core_ids_contract(): void {
        $registry = task_registry::make_default();
        $triggerregistry = new message_trigger_registry($registry);
        $triggerids = $triggerregistry->get_available_trigger_ids();

        $required = [
            'core.is_lookup_request',
            'core.is_confirmation_message',
            'core.is_preview_request',
            'core.force_new_duplicate_option',
        ];

        foreach ($required as $triggerid) {
            $this->assertContains($triggerid, $triggerids, 'Missing core trigger id: ' . $triggerid);
        }
    }

    /**
     * Conversation store baseline methods should preserve key lifecycle data.
     */
    public function test_conversation_store_pending_intent_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Pending Contract Booking',
        ]);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread(11, (int)$booking->cmid, (int)$booking->id);
        $threadid = (int)$thread->id;
        $commands = [[
            'task' => 'booking.create_option',
            'version' => 1,
            'input' => ['text' => 'Contract Option'],
        ]];
        $intentkey = hash('sha256', 'contract-intent');

        $store->set_pending_intent($threadid, $commands, $intentkey, 11, (int)$booking->cmid);
        $pending = $store->get_pending_intent($threadid);

        $this->assertIsArray($pending);
        $this->assertSame(11, (int)($pending['userid'] ?? 0));
        $this->assertSame((int)$booking->cmid, (int)($pending['cmid'] ?? 0));
        $this->assertSame('booking.create_option', (string)($pending['commands'][0]['task'] ?? ''));
        $this->assertNotSame('', (string)($pending['confirmationcode'] ?? ''));

        $store->clear_pending_intent($threadid);
        $this->assertNull($store->get_pending_intent($threadid));
    }

    /**
     * Every task schema readonly flag must match the task capability declaration.
     */
    public function test_task_schema_readonly_matches_capability_contract(): void {
        $registry = task_registry::make_default();
        foreach ($registry->get_tasks() as $taskname => $task) {
            $schema = (array)$task->get_schema();
            $this->assertArrayHasKey('readonly', $schema, 'Task schema must expose readonly: ' . $taskname);
            $this->assertSame(
                (bool)$task->is_read_only(),
                (bool)($schema['readonly'] ?? false),
                'Task readonly mismatch between schema and capability: ' . $taskname
            );
        }
    }

    /**
     * Readonly task_call must be executed inside run_loop and not leak as final response_type.
     */
    public function test_run_loop_contains_readonly_taskcalls_contract(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Readonly Contract Booking',
        ]);

        // Create at least one option so booking.search_options can execute successfully.
        $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option([
            'bookingid' => (int)$booking->id,
            'text' => 'Readonly Contract Option',
            'status' => 0,
            'maxanswers' => 5,
            'coursestarttime' => time() + 86400,
            'courseendtime' => time() + 90000,
        ]);

        $registry = task_registry::make_default();
        $store = new conversation_store();
        $authz = new authorization_service();

        $step1 = [
            'response_type' => 'task_call',
            'lang' => 'en',
            'message' => 'Searching options.',
            'used_triggers' => [],
            'commands' => [[
                'task' => 'booking.search_options',
                'version' => 1,
                'input' => ['query' => 'Readonly Contract Option'],
            ]],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => [],
            'issue_codes' => [],
        ];
        $step2 = [
            'response_type' => 'clarification',
            'lang' => 'en',
            'message' => 'Completed.',
            'used_triggers' => [],
            'commands' => [],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => [],
            'issue_codes' => [],
        ];

        $callcount = 0;
        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturnCallback(
            static function () use (&$callcount, $step1, $step2): array {
                $callcount++;
                return $callcount === 1 ? $step1 : $step2;
            }
        );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread((int)$USER->id, (int)$booking->cmid, (int)$booking->id);
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'find options');

        $result = $runtime->run_loop($threadid, (int)$booking->cmid, (int)$USER->id);

        $this->assertNotSame('task_call', (string)($result['response_type'] ?? ''));
        $this->assertContains('booking.search_options', (array)($result['attempted_tasks'] ?? []));
        $this->assertNotEmpty((array)($result['results'] ?? []));
    }

    /**
     * UNKNOWN_TYPE response normalization should trigger recovery enrichment, not leak as final response.
     *
     * Regression test for the Loose End where UNKNOWN_TYPE was not routed to recovery enrichment.
     * An unknown response_type from the LLM should be normalized to UNKNOWN_TYPE, then treated
     * as a dead-end requiring recovery (not passed through as-is to the caller).
     */
    public function test_unknown_response_type_triggers_recovery_enrichment_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Unknown Type Contract Booking',
        ]);

        $registry = task_registry::make_default();
        $store = new conversation_store();
        $authz = new authorization_service();

        // Mock an orchestrator result with an unknown response_type.
        // This simulates the LLM returning an invalid response_type that gets normalized to UNKNOWN_TYPE.
        $unknowntyperesult = [
            'response_type' => 'UNKNOWN_TYPE',
            'lang' => 'en',
            'message' => 'This should be treated as a dead-end.',
            'used_triggers' => [],
            'commands' => [],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => [],
            'issue_codes' => [],
            'next_step_intent' => '',
        ];

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mockorchestrator->method('process')->willReturn($unknowntyperesult);

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread(11, (int)$booking->cmid, (int)$booking->id);
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'show me something');

        $result = $runtime->run($threadid, (int)$booking->cmid, 11);

        // The result should NOT be 'UNKNOWN_TYPE' in the final output.
        // It should either be handled by recovery enrichment or converted to clarification.
        $this->assertNotSame('UNKNOWN_TYPE', (string)($result['response_type'] ?? ''), 'UNKNOWN_TYPE should not leak as final response_type; recovery enrichment should have handled it');
    }
}
