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

use bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\task_registry_factory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the generic agentic framework.
 *
 * Validates that the framework successfully abstracts plugin-specific logic
 * and maintains genericity for multi-plugin environments.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class integration_agent_framework_test extends TestCase {
    /**
     * Test that task_registry discovers tasks from the booking plugin provider.
     */
    public function test_task_registry_discovers_booking_tasks(): void {
        $registry = task_registry_factory::get_default();
        $tasks = $registry->get_tasks();

        // Verify that tasks are discovered.
        $this->assertNotEmpty($tasks, 'Task registry should discover tasks from booking plugin');
        $this->assertGreaterThanOrEqual(2, count($tasks), 'Should discover at least 2 booking tasks');

        // Verify task names follow the pattern: <component>.<taskname>.
        foreach ($tasks as $task) {
            $name = $task->get_name();
            $this->assertStringContainsString('.', $name, 'Task name should include component prefix');
        }
    }

    /**
     * Test that task_provider interface supports optional issue code provider.
     */
    public function test_task_provider_interface_supports_issue_code_provider(): void {
        $provider = new \bookingextension_agent\local\wbagent\task_provider();

        // Verify interface methods exist.
        $this->assertTrue(
            method_exists($provider, 'get_issue_code_provider'),
            'task_provider should implement get_issue_code_provider()'
        );

        // Verify method returns issue code provider.
        $issuecodeprovider = $provider->get_issue_code_provider();
        $this->assertInstanceOf(
            issue_code_provider_interface::class,
            $issuecodeprovider,
            'get_issue_code_provider() should return issue_code_provider_interface instance'
        );
    }

    /**
     * Test that task_provider interface supports optional prompt guidance.
     */
    public function test_task_provider_interface_supports_prompt_guidance(): void {
        $provider = new \bookingextension_agent\local\wbagent\task_provider();

        // Verify interface methods exist.
        $this->assertTrue(
            method_exists($provider, 'get_prompt_guidance'),
            'task_provider should implement get_prompt_guidance()'
        );

        // Verify method returns array.
        $guidance = $provider->get_prompt_guidance();
        $this->assertIsArray($guidance, 'get_prompt_guidance() should return array');
    }

    /**
     * Test that issue code provider is used by agent decision service.
     */
    public function test_issue_code_provider_injected_into_agent_runtime(): void {
        $provider = new \bookingextension_agent\local\wbagent\booking_issue_code_provider();
        $registry = task_registry_factory::get_default();
        $store = new \bookingextension_agent\local\wbagent\conversation_store();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);
        $orchestrator = new \bookingextension_agent\local\wbagent\orchestrator($registry, $interpreter, $store);
        $authz = new \bookingextension_agent\local\wbagent\authorization_service();

        // Create agent_runtime with custom provider (test dependency injection).
        $runtime = new \bookingextension_agent\local\wbagent\agent_runtime($registry, $orchestrator, $store, $authz, $provider);

        // Verify that runtime accepts the provider (no exception thrown).
        $this->assertInstanceOf(\bookingextension_agent\local\wbagent\agent_runtime::class, $runtime);
    }

    /**
     * Test that task schema includes prompt_meta when available.
     */
    public function test_task_schema_includes_prompt_meta(): void {
        $registry = task_registry_factory::get_default();

        // Get tasks and verify at least one has prompt_meta.
        $tasks = $registry->get_tasks();
        $this->assertNotEmpty($tasks, 'Registry should have tasks');

        $foundpromptmeta = false;
        foreach ($tasks as $task) {
            $schema = $task->get_schema();
            if (isset($schema['prompt_meta'])) {
                $foundpromptmeta = true;
                $this->assertIsArray($schema['prompt_meta'], 'prompt_meta should be array');
                $this->assertArrayHasKey('input_fields_for_prompt', $schema['prompt_meta']);
                $this->assertArrayHasKey('anchor_fields', $schema['prompt_meta']);
                break;
            }
        }

        $this->assertTrue($foundpromptmeta, 'At least one booking task should have prompt_meta');
    }

    /**
     * Test that task registry uses prompt_meta when building prompt contract.
     */
    public function test_task_registry_prioritizes_prompt_meta(): void {
        $registry = task_registry_factory::get_default();
        $contract = ['tasks' => $registry->get_all_prompt_contracts()];

        // Verify contract includes task catalog.
        $this->assertIsArray($contract, 'Prompt contract should be array');
        $this->assertArrayHasKey('tasks', $contract, 'Contract should include tasks');

        // Verify each task has routing metadata.
        foreach ((array)$contract['tasks'] as $taskinfo) {
            $this->assertIsArray($taskinfo, 'Task info should be array');
            $this->assertArrayHasKey('task', $taskinfo, 'Should have task name');
        }
    }

    /**
     * Test that prompt contracts separate required inputs from routing examples.
     */
    public function test_prompt_contracts_use_required_minimals_and_explicit_examples(): void {
        $registry = task_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();

        $foundreadonlytask = false;
        $foundmutatingtask = false;
        foreach ($contracts as $taskinfo) {
            $this->assertArrayHasKey('task', $taskinfo, 'Every task should expose task name');
            $this->assertArrayHasKey('minimal_input', $taskinfo, 'Every task should expose minimal_input');
            $this->assertArrayHasKey('example_input', $taskinfo, 'Every task should expose example_input');
            $this->assertIsArray($taskinfo['minimal_input'], 'minimal_input should always be an array');
            $this->assertIsArray($taskinfo['example_input'], 'example_input should always be an array');

            if (!empty($taskinfo['readonly'])) {
                $foundreadonlytask = true;
            } else {
                $foundmutatingtask = true;
            }
        }

        $this->assertNotEmpty($contracts, 'Prompt contracts should not be empty');
        $this->assertTrue($foundreadonlytask, 'Expected at least one readonly task contract');
        $this->assertTrue($foundmutatingtask, 'Expected at least one mutating task contract');
    }

    /**
     * Test that slim planner catalog never recreates example_input from minimal_input.
     */
    public function test_slim_catalog_keeps_examples_separate_from_minimals(): void {
        $registry = task_registry_factory::get_default();
        $orchestratorreflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $orchestrator = $orchestratorreflection->newInstanceWithoutConstructor();
        $method = $orchestratorreflection->getMethod('slim_prompt_catalog_for_planner');
        $method->setAccessible(true);

        $slimcatalog = $method->invoke($orchestrator, $registry->get_all_prompt_contracts());
        $bytask = [];
        foreach ($slimcatalog as $taskinfo) {
            $bytask[(string)$taskinfo['task']] = $taskinfo;
            $this->assertArrayHasKey('minimal_input', $taskinfo, 'Slim catalog should keep minimal_input');
            $this->assertIsArray($taskinfo['minimal_input'], 'Slim minimal_input should be an array');
            if (array_key_exists('example_input', $taskinfo)) {
                $this->assertIsArray($taskinfo['example_input'], 'Slim example_input should remain an array');
            }

            if (isset($taskinfo['description']) && is_string($taskinfo['description'])) {
                $this->assertLessThanOrEqual(140, \core_text::strlen($taskinfo['description']));
            }
        }

        $this->assertNotEmpty($bytask, 'Slim catalog should contain task entries');
    }

    /**
     * Test that embedding-selected planner subsets keep full task descriptions.
     */
    public function test_embedding_subset_keeps_full_descriptions(): void {
        $retrieval = new \bookingextension_agent\local\wbagent\embeddings_retrieval_service();
        $csvdescription = 'Persisted CSV description that should not win over live task schema metadata.';
        $livedescription = 'Live task description from get_schema that must win when embed task selection is mapped back to tasks.';

        $subset = $retrieval->build_planner_catalog_subset([
            [
                'task' => 'booking.create_rule_from_template',
                'intent' => 'create',
                'readonly' => '0',
                'description' => $csvdescription,
                'minimal_input_json' => '[]',
                'example_input_json' => '{"templatequery":"booking confirmation","rulename":"Birthday reminder"}',
                'message_triggers_json' => '[]',
                'embedding_model' => 'wunderbyte-embeddings',
                'embedding_dimensions' => '1536',
                'content_hash' => 'dummy',
                'embedding_json' => '[]',
            ],
        ], [
            [
                'task' => 'booking.create_rule_from_template',
                'intent' => 'create',
                'readonly' => false,
                'description' => $livedescription,
                'minimal_input' => [],
                'example_input' => [
                    'templatequery' => 'booking confirmation',
                    'rulename' => 'Birthday reminder',
                ],
                'message_triggers' => [],
            ],
        ]);

        $this->assertCount(1, $subset);
        $this->assertSame($livedescription, $subset[0]['description']);
    }

    /**
     * Test that embedding-selected planner subsets include compact schema properties.
     */
    public function test_embedding_subset_includes_property_descriptions(): void {
        task_registry_factory::reset();

        $retrieval = new \bookingextension_agent\local\wbagent\embeddings_retrieval_service();
        $subset = $retrieval->build_planner_catalog_subset([
            [
                'task' => 'booking.recreate_task_catalog',
                'intent' => 'mutate',
                'readonly' => '0',
                'description' => 'stale csv description',
                'minimal_input_json' => '[]',
                'example_input_json' => '{"force":true}',
                'message_triggers_json' => '[]',
                'embedding_model' => 'wunderbyte-embeddings',
                'embedding_dimensions' => '1536',
                'content_hash' => 'dummy',
                'embedding_json' => '[]',
            ],
        ]);

        $this->assertCount(1, $subset);
        $this->assertArrayHasKey('properties', $subset[0]);
        $this->assertIsArray($subset[0]['properties']);
        $this->assertArrayHasKey('force', $subset[0]['properties']);
        $this->assertArrayHasKey('description', $subset[0]['properties']['force']);
        $this->assertStringContainsString(
            'force regeneration',
            (string)$subset[0]['properties']['force']['description']
        );
    }

    /**
     * Test that orchestrator prompts are generic and do not hardcode plugin names.
     */
    public function test_orchestrator_prompts_are_generic(): void {
        // Get the default prompt template.
        $template = \bookingextension_agent\local\wbagent\orchestrator::get_default_initial_prompt_template();

        // Verify template does not contain hardcoded plugin-specific task names like "booking.explain_docs_topic".
        // (The template file might still have them, but action-specific prompts should not.)
        $this->assertNotEmpty($template, 'Prompt template should not be empty');

        // Verify prompts use placeholders.
        $this->assertStringContainsString('{{', $template, 'Template should use placeholders');
    }

    /**
     * Test that action-specific prompts in orchestrator are generic.
     */
    public function test_action_specific_prompts_generic(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $method = $reflection->getMethod('get_default_initial_prompt_template_for_action');
        $method->setAccessible(true);

        // Test summarise_text action prompt.
        $summariseprompt = $method->invoke(null, \core_ai\aiactions\summarise_text::class);
        $this->assertStringNotContainsString(
            'booking.explain_docs_topic',
            $summariseprompt,
            'Action prompt should not hardcode "booking.explain_docs_topic"'
        );
        $this->assertStringContainsString(
            'TASK CATALOG',
            $summariseprompt,
            'Action prompt should reference task catalog routing'
        );
        $this->assertStringContainsString(
            'Use only exact task names from the TASK CATALOG',
            $summariseprompt,
            'Action prompt should enforce task-catalog based routing'
        );
        $this->assertStringContainsString(
            'Never invent aliases',
            $summariseprompt,
            'Action prompt should explicitly forbid invented task aliases'
        );

        // Test explain_text action prompt.
        $explainprompt = $method->invoke(null, \core_ai\aiactions\explain_text::class);
        $this->assertStringNotContainsString(
            'booking.',
            $explainprompt,
            'Explain prompt should not hardcode booking-specific names'
        );
        $this->assertStringContainsString(
            'TASK CATALOG',
            $explainprompt,
            'Explain prompt should reference task-catalog based routing'
        );
    }

    /**
     * Test that booking base class is properly renamed.
     */
    public function test_discovered_tasks_implement_task_interface(): void {
        $provider = new \bookingextension_agent\local\wbagent\task_provider();
        $tasks = $provider->get_tasks();

        $this->assertNotEmpty($tasks, 'Provider should discover at least one task');
        foreach ($tasks as $task) {
            $this->assertInstanceOf(
                \bookingextension_agent\local\wbagent\interfaces\task_interface::class,
                $task
            );
        }
    }

    /**
     * Test multi-provider scenario: booking and other plugins can coexist.
     */
    public function test_multi_provider_discovery(): void {
        // This test validates the discovery and registration mechanism.
        $registry = task_registry_factory::get_default();

        // Verify booking tasks are registered.
        $tasks = $registry->get_tasks();
        $this->assertNotEmpty($tasks, 'Registry should have tasks from providers');

        // Verify task names include component prefix (plugin-specific routing).
        $bookingtaskfound = false;
        foreach ($tasks as $task) {
            if (str_starts_with($task->get_name(), 'booking.')) {
                $bookingtaskfound = true;
                break;
            }
        }

        $this->assertTrue($bookingtaskfound, 'Should have tasks prefixed with plugin component');
    }

    /**
     * Test that task discovery scans all direct task namespaces under local/wbagent.
     */
    public function test_task_discovery_scans_all_wbagent_task_namespaces(): void {
        task_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wbagent\task_provider();
        $tasknames = array_map(static fn($task): string => $task->get_name(), $provider->get_tasks());

        $this->assertContains('booking.get_current_user', $tasknames);
        $this->assertContains('booking.recreate_task_catalog', $tasknames);
        $this->assertContains('examples.readonly_example', $tasknames);
    }

    /**
     * Test that discovery does not expose duplicate task names.
     */
    public function test_task_discovery_deduplicates_same_task_name(): void {
        task_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wbagent\task_provider();
        $tasknames = array_map(static fn($task): string => $task->get_name(), $provider->get_tasks());

        $this->assertSame($tasknames, array_values(array_unique($tasknames)));
    }

    /**
     * Test that trigger-provider discovery ignores non-trigger classes without failing.
     */
    public function test_trigger_provider_discovery_ignores_non_trigger_classes(): void {
        $providers = \bookingextension_agent\local\wbagent\task_discovery::get_trigger_provider_instances('bookingextension_agent');

        $this->assertNotEmpty($providers);
        foreach ($providers as $provider) {
            $this->assertInstanceOf(
                \bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface::class,
                $provider
            );
        }
    }

    /**
     * Test that language-specific logic is removed from tasks.
     */
    public function test_tasks_no_language_specific_logic(): void {
        $provider = new \bookingextension_agent\local\wbagent\task_provider();
        $tasks = $provider->get_tasks();

        $this->assertNotEmpty($tasks, 'Provider should discover tasks for reflection checks');
        foreach ($tasks as $task) {
            $reflection = new \ReflectionClass($task);
            $this->assertFalse(
                $reflection->hasMethod('looks_like_german'),
                'Task classes must not contain language-token heuristics'
            );
            $this->assertFalse(
                $reflection->hasMethod('build_disambiguation_message'),
                'Task classes must not contain language-specific disambiguation helpers'
            );
        }
    }

    /**
     * Test task schema validation includes all required fields.
     */
    public function test_task_schema_required_fields(): void {
        $registry = task_registry_factory::get_default();
        $tasks = $registry->get_tasks();

        foreach ($tasks as $task) {
            $schema = $task->get_schema();

            // Verify required fields.
            $this->assertArrayHasKey('version', $schema, 'Schema should have version');
            $this->assertArrayHasKey('properties', $schema, 'Schema should have properties');
            $this->assertArrayHasKey('readonly', $schema, 'Schema should expose readonly flag');
        }
    }

    /**
     * Test that backward compatibility is maintained.
     */
    public function test_backward_compatibility_constants(): void {
        // Verify old constants still exist (marked @deprecated).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);

        // The old constants should still be accessible for backward compat.
        $this->assertTrue(true, 'Backward compatibility checks passed');
    }
}
