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

use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\task_contract_validator;
use bookingextension_agent\local\wbagent\task_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for task namespace/version governance rules.
 *
 * @covers \bookingextension_agent\local\wbagent\task_contract_validator
 * @covers \bookingextension_agent\local\wbagent\task_registry
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_contract_validator_contract_test extends TestCase {
    /**
     * Validate namespaced task-name format helper.
     */
    public function test_namespaced_task_name_format(): void {
        $this->assertTrue(task_contract_validator::is_namespaced_task_name('mod_booking.create_option'));
        $this->assertTrue(task_contract_validator::is_namespaced_task_name('entities.search'));
        $this->assertFalse(task_contract_validator::is_namespaced_task_name('create_option'));
        $this->assertFalse(task_contract_validator::is_namespaced_task_name('booking.create.option'));
    }

    /**
     * Validate reserved namespace ownership rules.
     */
    public function test_reserved_namespace_ownership(): void {
        $this->assertTrue(task_contract_validator::component_may_register_namespace('bookingextension_agent', 'booking'));
        $this->assertTrue(task_contract_validator::component_may_register_namespace('bookingextension_agent', 'core'));
        $this->assertFalse(task_contract_validator::component_may_register_namespace('local_dummy', 'booking'));
        $this->assertFalse(task_contract_validator::component_may_register_namespace('local_dummy', 'core'));
        $this->assertTrue(task_contract_validator::component_may_register_namespace('local_dummy', 'entities'));
    }

    /**
     * Validate alias version mismatch detection in registry-wide contracts.
     */
    public function test_validate_registry_contracts_rejects_alias_version_mismatch(): void {
        $contracts = [
            'entities.search' => [
                'taskname' => 'entities.search',
                'namespace' => 'entities',
                'version' => 1,
                'component' => 'local_entities',
                'capabilities' => ['local/entities:task_entities_search'],
                'active' => true,
                'alias_of' => '',
                'deprecated_since' => '',
                'readonly' => true,
            ],
            'entities.lookup' => [
                'taskname' => 'entities.lookup',
                'namespace' => 'entities',
                'version' => 2,
                'component' => 'local_entities',
                'capabilities' => ['local/entities:task_entities_lookup'],
                'active' => true,
                'alias_of' => 'entities.search',
                'deprecated_since' => '',
                'readonly' => true,
            ],
        ];

        $errors = task_contract_validator::validate_registry_contracts($contracts);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Alias version mismatch', (string)$errors[0]);
    }

    /**
     * Validate registry rejects third-party tasks in reserved booking/core namespaces.
     */
    public function test_registry_rejects_reserved_namespace_for_third_party_provider(): void {
        $task = $this->createMock(task_interface::class);
        $task->method('get_name')->willReturn('booking.hijack');
        $task->method('get_schema')->willReturn([
            'description' => 'Invalid task in reserved namespace.',
            'version' => 1,
            'governance' => [],
            'properties' => [],
            'required' => [],
        ]);
        $task->method('is_read_only')->willReturn(true);

        $provider = $this->createMock(task_provider_interface::class);
        $provider->method('get_component')->willReturn('local_dummy');
        $provider->method('get_tasks')->willReturn([$task]);
        $provider->method('get_contextual_prompt_packs')->willReturn([]);
        $provider->method('get_issue_code_provider')->willReturn(null);
        $provider->method('get_prompt_guidance')->willReturn([]);

        $registry = new task_registry();
        $registry->register($provider);

        $this->assertNull($registry->get_task('booking.hijack'));
        $this->assertNotEmpty($registry->get_contract_diagnostics());
        $this->assertStringContainsString('namespace is reserved', $registry->get_contract_diagnostics()[0]);
    }

    /**
     * Validate that a demo task can be onboarded via provider registration only.
     */
    public function test_demo_task_onboards_via_provider_registration_only(): void {
        $task = $this->createMock(task_interface::class);
        $task->method('get_name')->willReturn('demo.lookup');
        $task->method('get_schema')->willReturn([
            'description' => 'Demo lookup task.',
            'version' => 1,
            'governance' => [],
            'properties' => [
                'query' => ['type' => 'string'],
            ],
            'required' => ['query'],
        ]);
        $task->method('is_read_only')->willReturn(true);
        $task->method('get_example_input')->willReturn(['query' => 'demo']);
        $task->method('get_prompt_contract')->willReturn(new \bookingextension_agent\local\wbagent\services\task_prompt_contract([
            'intent' => 'search',
            'anchors' => ['demo'],
            'minimal_input' => ['query'],
            'example_input' => ['query' => 'demo'],
            'namespace' => 'demo',
            'version' => 1,
            'capabilities' => ['local/demo:task_demo_lookup'],
            'context_scopes' => ['module'],
        ]));

        $provider = $this->createMock(task_provider_interface::class);
        $provider->method('get_component')->willReturn('local_demo');
        $provider->method('get_tasks')->willReturn([$task]);
        $provider->method('get_contextual_prompt_packs')->willReturn([]);
        $provider->method('get_issue_code_provider')->willReturn(null);
        $provider->method('get_prompt_guidance')->willReturn([]);

        $registry = new task_registry();
        $registry->register($provider);

        $this->assertNotNull($registry->get_task('demo.lookup'));
        $contracts = $registry->get_all_prompt_contracts();
        $this->assertCount(1, $contracts);
        $this->assertSame('demo.lookup', (string)$contracts[0]['task']);
        $this->assertSame('demo', (string)$contracts[0]['namespace']);
        $this->assertSame(1, (int)$contracts[0]['version']);
        $this->assertContains('local/demo:task_demo_lookup', (array)$contracts[0]['capabilities']);
    }

    /**
     * Validate that a failing provider does not block already registered providers.
     */
    public function test_failing_provider_does_not_block_other_registered_tasks(): void {
        $goodtask = $this->createMock(task_interface::class);
        $goodtask->method('get_name')->willReturn('demo.healthy_task');
        $goodtask->method('get_schema')->willReturn([
            'description' => 'Healthy demo task.',
            'version' => 1,
            'governance' => [],
            'properties' => [],
            'required' => [],
        ]);
        $goodtask->method('is_read_only')->willReturn(true);

        $goodprovider = $this->createMock(task_provider_interface::class);
        $goodprovider->method('get_component')->willReturn('local_demo');
        $goodprovider->method('get_tasks')->willReturn([$goodtask]);
        $goodprovider->method('get_contextual_prompt_packs')->willReturn([]);
        $goodprovider->method('get_issue_code_provider')->willReturn(null);
        $goodprovider->method('get_prompt_guidance')->willReturn([]);

        $badprovider = $this->createMock(task_provider_interface::class);
        $badprovider->method('get_component')->willReturn('local_broken');
        $badprovider->method('get_contextual_prompt_packs')->willReturn([]);
        $badprovider->method('get_issue_code_provider')->willReturn(null);
        $badprovider->method('get_prompt_guidance')->willReturn([]);
        $badprovider->method('get_tasks')->willThrowException(new \RuntimeException('broken provider'));

        $registry = new task_registry();
        $registry->register($goodprovider);
        $registry->register($badprovider);

        $this->assertNotNull($registry->get_task('demo.healthy_task'));
        $this->assertNotEmpty($registry->get_contract_diagnostics());
    }
}
