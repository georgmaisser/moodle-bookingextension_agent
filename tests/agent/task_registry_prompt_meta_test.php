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
 * Unit tests for task_registry prompt metadata handling.
 *
 * Tests that task_registry correctly extracts and uses prompt_meta from task schemas
 * instead of relying on hardcoded fallback mappings.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\interfaces\task_interface;

/**
 * Tests for task_registry schema metadata handling.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @covers \bookingextension_agent\local\wbagent\task_registry::build_prompt_contract
 */
final class task_registry_prompt_meta_test extends booking_advanced_testcase {
    /**
     * Test that booking create_option task schema includes prompt_meta.
     */
    public function test_booking_create_option_task_has_prompt_meta(): void {
        // Get a real booking task.
        $registry = task_registry::make_default();
        $task = $registry->get_task('booking.create_option');

        $this->assertNotNull($task);
        $schema = $task->get_schema();

        $this->assertArrayHasKey('prompt_meta', $schema);
        $this->assertIsArray($schema['prompt_meta']);
        $this->assertArrayHasKey('input_fields_for_prompt', $schema['prompt_meta']);
        $this->assertArrayHasKey('anchor_fields', $schema['prompt_meta']);
    }

    /**
     * Test that task registry uses prompt_meta when building prompt contract.
     */
    public function test_task_registry_uses_prompt_meta_in_contract(): void {
        $registry = task_registry::make_default();

        $contracts = $registry->get_all_prompt_contracts();
        $this->assertNotEmpty($contracts);

        // Find the create_option contract.
        $createcontract = null;
        foreach ($contracts as $contract) {
            if (($contract['task'] ?? '') === 'booking.create_option') {
                $createcontract = $contract;
                break;
            }
        }

        $this->assertNotNull($createcontract, 'create_option contract not found');

        // Verify that the contract includes prompt-guided minimal input.
        $this->assertArrayHasKey('minimal_input', $createcontract);
        $this->assertArrayHasKey('anchors', $createcontract);
        $this->assertArrayHasKey('example_input', $createcontract);
        $this->assertArrayHasKey('message_triggers', $createcontract);

        // The minimal input should include fields from prompt_meta.
        $this->assertContains('text', $createcontract['minimal_input']);
        $this->assertNotContains('optiontype', $createcontract['minimal_input']);

        // The example should expose the most common create keys.
        $this->assertArrayHasKey('text', $createcontract['example_input']);
        $this->assertArrayHasKey('maxanswers', $createcontract['example_input']);
        $this->assertArrayHasKey('coursestarttime', $createcontract['example_input']);
        $this->assertArrayHasKey('courseendtime', $createcontract['example_input']);

        // The task-specific trigger hints should stay attached to the task catalog entry.
        $this->assertNotEmpty($createcontract['message_triggers']);

        // The anchors should include what was declared in prompt_meta.
        $this->assertContains('option', $createcontract['anchors']);
    }

    /**
     * Test that task registry includes correct anchor fields.
     */
    public function test_task_registry_anchor_fields(): void {
        $registry = task_registry::make_default();
        $contracts = $registry->get_all_prompt_contracts();

        // Find diagnose_booking_issue which should have option and user anchors.
        $diagnosecontract = null;
        foreach ($contracts as $contract) {
            if (($contract['task'] ?? '') === 'booking.diagnose_booking_issue') {
                $diagnosecontract = $contract;
                break;
            }
        }

        $this->assertNotNull($diagnosecontract);
        $this->assertArrayHasKey('anchors', $diagnosecontract);
        $anchors = $diagnosecontract['anchors'];

        // Should have both option and user anchors.
        $this->assertContains('option', $anchors);
        $this->assertContains('user', $anchors);
    }

    /**
     * Test that readonly search task has correct minimal input.
     */
    public function test_readonly_search_task_minimal_input(): void {
        $registry = task_registry::make_default();
        $contracts = $registry->get_all_prompt_contracts();

        // Find search_options.
        $searchcontract = null;
        foreach ($contracts as $contract) {
            if (($contract['task'] ?? '') === 'booking.search_options') {
                $searchcontract = $contract;
                break;
            }
        }

        $this->assertNotNull($searchcontract);
        $this->assertArrayHasKey('minimal_input', $searchcontract);

        // Search task should have 'query' as key input field.
        $this->assertContains('query', $searchcontract['minimal_input']);
    }

    /**
     * Test that list_actions task minimal input is correct.
     */
    public function test_list_actions_task_minimal_input(): void {
        $registry = task_registry::make_default();
        $contracts = $registry->get_all_prompt_contracts();

        // Find list_actions.
        $listcontract = null;
        foreach ($contracts as $contract) {
            if (($contract['task'] ?? '') === 'booking.list_actions') {
                $listcontract = $contract;
                break;
            }
        }

        $this->assertNotNull($listcontract);
        $this->assertArrayHasKey('minimal_input', $listcontract);

        // List actions should include 'scope' as input field.
        $this->assertContains('scope', $listcontract['minimal_input']);
    }

    /**
     * Test that readonly tasks are correctly marked.
     */
    public function test_readonly_tasks_correctly_marked(): void {
        $registry = task_registry::make_default();
        $contracts = $registry->get_all_prompt_contracts();

        // Filter to readonly tasks.
        $readonlytasks = array_filter($contracts, static fn($c) => !empty($c['readonly']));
        $readyonlynames = array_map(static fn($c) => $c['task'], $readonlytasks);

        // Should include search and explain tasks.
        $this->assertContains('booking.search_options', $readyonlynames);
        $this->assertContains('booking.explain_docs_topic', $readyonlynames);
        $this->assertContains('booking.get_current_user', $readyonlynames);
        $this->assertContains('booking.recall_memory', $readyonlynames);

        // Should NOT include mutating tasks.
        $this->assertNotContains('booking.create_option', $readyonlynames);
        $this->assertNotContains('booking.update_option', $readyonlynames);
    }
}
