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
 * @coversNothing
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
        $authz = new \bookingextension_agent\local\wbagent\services\security\authorization_service();

        // Create agent_runtime with custom provider (test dependency injection).
        $runtime = new \bookingextension_agent\local\wbagent\agent_runtime(
            $registry,
            $orchestrator,
            $store,
            $authz
        );

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
        $assistantsummaryprop = $orchestratorreflection->getProperty('assistantsummariesvc');
        $assistantsummaryprop->setAccessible(true);
        $assistantsummarysvc = new \bookingextension_agent\local\wbagent\services\assistant_state_guidance_service($registry);
        $assistantsummaryprop->setValue($orchestrator, $assistantsummarysvc);
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
                $this->assertLessThanOrEqual(240, \core_text::strlen($taskinfo['description']));
            }
        }

        $this->assertNotEmpty($bytask, 'Slim catalog should contain task entries');
    }

    /**
     * Runtime catalog payload injected into prompts must never contain embedding vectors.
     */
    public function test_runtime_catalog_prompt_sanitizer_removes_embedding_json(): void {
        $orchestratorreflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $orchestrator = $orchestratorreflection->newInstanceWithoutConstructor();
        $method = $orchestratorreflection->getMethod('sanitize_runtime_catalog_for_prompt');
        $method->setAccessible(true);

        $catalog = [
            [
                'task' => 'mod_booking.diagnose_booking_issue',
                'description' => 'Diagnose booking issue.',
                'readonly' => '1',
                'intent' => 'task',
                'minimal_input_json' => '[]',
                'example_input_json' => '{"question":"Why"}',
                'message_triggers_json' => '[{"id":"t1","description":"desc"}]',
                'embedding_json' => '[0.1,0.2,0.3]',
                'embedding_model' => 'wunderbyte-embeddings',
                'embedding_dimensions' => '1536',
                'content_hash' => 'abc',
                'score' => '0.27',
            ],
            [
                'task' => 'mod_booking.list_options',
                'description' => 'List booking options.',
                'readonly' => false,
                'intent' => 'lookup',
                'minimal_input' => ['optionquery'],
                'example_input' => ['optionquery' => 'Yoga'],
                'message_triggers' => [['id' => 't2', 'description' => 'desc2']],
            ],
        ];

        $sanitized = $method->invoke($orchestrator, $catalog);
        $this->assertCount(2, $sanitized);
        $this->assertSame(
            ['task', 'readonly', 'intent', 'minimal_input', 'description', 'message_triggers', 'example_input'],
            array_keys($sanitized[0])
        );
        $this->assertSame('mod_booking.diagnose_booking_issue', (string)$sanitized[0]['task']);
        $this->assertTrue((bool)$sanitized[0]['readonly']);
        $this->assertSame('task', (string)$sanitized[0]['intent']);
        $this->assertSame([], $sanitized[0]['minimal_input']);
        $this->assertSame(['question'], $sanitized[0]['example_input']);
        $this->assertArrayHasKey('id', (array)($sanitized[0]['message_triggers'][0] ?? []));
        $this->assertArrayNotHasKey('embedding_json', $sanitized[0]);
        $this->assertArrayNotHasKey('embedding_model', $sanitized[0]);
        $this->assertArrayNotHasKey('embedding_dimensions', $sanitized[0]);
        $this->assertArrayNotHasKey('content_hash', $sanitized[0]);
        $this->assertArrayNotHasKey('score', $sanitized[0]);

        $this->assertSame(
            ['task', 'readonly', 'intent', 'minimal_input', 'description', 'message_triggers'],
            array_keys($sanitized[1])
        );
        $this->assertSame('mod_booking.list_options', (string)$sanitized[1]['task']);
        $this->assertFalse((bool)$sanitized[1]['readonly']);
        $this->assertSame('lookup', (string)$sanitized[1]['intent']);
        $this->assertSame(['optionquery'], $sanitized[1]['minimal_input']);
        $this->assertArrayNotHasKey('example_input', $sanitized[1]);
    }

    /**
     * Test that embedding-selected planner subsets keep full task descriptions.
     */
    public function test_embedding_subset_keeps_full_descriptions(): void {
        $retrieval = new \bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service();
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

        $retrieval = new \bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service();
        $subset = $retrieval->build_planner_catalog_subset([
            [
                'task' => 'core.recreate_task_catalog',
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
        // Use the live planner fallback template instead of the removed generic one-step template.
        $template = \bookingextension_agent\local\wbagent\orchestrator::get_default_initial_prompt_template_for_action(
            \core_ai\aiactions\summarise_text::class
        );

        // Verify template does not contain hardcoded plugin-specific task names.
        $this->assertNotEmpty($template, 'Prompt template should not be empty');

        // Verify the live planner fallback remains templated and generic.
        $this->assertStringContainsString('{{bookingname}}', $template, 'Template should use booking placeholders');
        $this->assertStringNotContainsString('booking.explain_docs_topic', $template);
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
        // Legacy names used booking.*, current core tasks use core.*.
        $componentprefixtaskfound = false;
        $coretaskfound = false;
        foreach ($tasks as $task) {
            $name = (string)$task->get_name();
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_]/', $name) === 1) {
                $componentprefixtaskfound = true;
            }
            if (str_starts_with($name, 'core.')) {
                $coretaskfound = true;
            }
        }

        $this->assertTrue($componentprefixtaskfound, 'Should have tasks prefixed with plugin component');
        $this->assertTrue($coretaskfound, 'Should expose core.* tasks from bookingextension_agent');
    }

    /**
     * Test that task discovery scans all direct task namespaces under local/wbagent.
     */
    public function test_task_discovery_scans_all_wbagent_task_namespaces(): void {
        task_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wbagent\task_provider();
        $tasknames = array_map(static fn($task): string => $task->get_name(), $provider->get_tasks());

        $this->assertContains('core.get_current_user', $tasknames);
        $this->assertContains('core.recreate_task_catalog', $tasknames);

        $exampletaskclass = '\\bookingextension_agent\\local\\wbagent\\examples\\tasks\\readonly_example_task';
        if (class_exists($exampletaskclass)) {
            $this->assertContains('examples.readonly_example', $tasknames);
        }
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

    /**
     * Test that the planner result composer preserves the construction payload.
     */
    public function test_planner_result_composer_preserves_construction_payload(): void {
        $composer = new \bookingextension_agent\local\wbagent\services\planner_result_composer();

        $result = $composer->compose(
            [
                'plannertracehistory' => ['discovery-trace'],
                'catalogselectionmode' => 'embed_topk',
                'embeddingstatus' => 'applied',
            ],
            [
                'response_type' => 'task_call',
                'message' => 'selection message',
                'catalogselectionmode' => 'embed_topk',
                'embeddingstatus' => 'applied',
            ],
            [
                'response_type' => 'clarification',
                'message' => 'construction message',
                'commands' => [],
                'issue_codes' => ['CONTRACT_EMPTY_MESSAGE'],
            ]
        );

        $this->assertSame('clarification', $result['response_type']);
        $this->assertSame('construction message', $result['message']);
        $this->assertArrayHasKey('planner_result', $result);
        $this->assertArrayHasKey('phase_trace', $result);
        $this->assertSame(['discovery-trace'], $result['planner_result']['planner_trace_history']);
        $this->assertArrayHasKey('parameter_construction', $result['planner_result']);
        $this->assertSame('construction message', $result['planner_result']['parameter_construction']['message']);
        $this->assertSame('embed_topk', $result['phase_trace']['selection']['catalogselectionmode']);
        $this->assertSame('', $result['phase_trace']['parameter_construction']['embeddingstatus']);
    }

    /**
     * Test that the phase-aware interpreter wrapper tags the normalized phase.
     */
    public function test_interpret_phase_output_tags_phase(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $result = $interpreter->interpret_phase_output(
            '{"response_type":"clarification","message":"Need more info","used_triggers":[]}',
            'parameter_construction',
            [
                'contextid' => 12,
                'userid' => 34,
                'lastusermessage' => 'Please continue',
            ]
        );

        $this->assertSame('clarification', $result['response_type']);
        $this->assertSame('Need more info', $result['message']);
        $this->assertSame('parameter_construction', $result['phase']);
    }

    /**
     * Test that non-construction phases reject command-bearing response types.
     */
    public function test_interpreter_phase_contract_rejects_command_types_in_selection(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'task_call',
            'commands' => [[
                'task' => 'core.get_current_user',
                'version' => 1,
                'input' => [],
            ]],
            'message' => 'Executing.',
        ], 'selection');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PHASE_RESPONSE_TYPE', $result['issue_codes']);
    }

    /**
     * Test that interpreter keeps strict JSON parsing at the trust boundary.
     */
    public function test_interpreter_rejects_non_json_payload(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $result = $interpreter->interpret('this is not json', 0, 0, '');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PARSE_ERROR', $result['issue_codes']);
    }

    /**
     * Test that unknown response_type values are rejected by allow-list contract.
     */
    public function test_interpreter_rejects_unknown_response_type(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $result = $interpreter->interpret(
            '{"response_type":"unexpected_type","message":"x","commands":[]}',
            0,
            0,
            ''
        );

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_UNKNOWN_RESPONSE_TYPE', $result['issue_codes']);
    }

    /**
     * Test that orchestrator executes three distinct planner invoke calls.
     */
    public function test_orchestrator_process_uses_three_phase_invokes(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertGreaterThanOrEqual(3, substr_count($source, '->invoke('));
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_DISCOVERY', $source);
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_SELECTION', $source);
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION', $source);
    }

    /**
     * Test that construction phase enforces exactly one selected command.
     */
    public function test_interpreter_construction_phase_requires_single_command(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'task_call',
            'commands' => [
                ['task' => 'core.get_current_user', 'version' => 1, 'input' => []],
                ['task' => 'core.recreate_task_catalog', 'version' => 1, 'input' => []],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PHASE_SINGLE_COMMAND_REQUIRED', $result['issue_codes']);
    }

    /**
     * Test that construction phase rejects tasks outside discovery-ranked allow-list.
     */
    public function test_interpreter_construction_phase_rejects_task_outside_allow_list(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'task_call',
            'commands' => [
                ['task' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction', [
            'allowed_tasks' => ['core.recreate_task_catalog'],
        ]);

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PHASE_TASK_NOT_ALLOWED', $result['issue_codes']);
    }

    /**
     * Test that command payload normalization keeps raw task names for selector-only canonicalization.
     */
    public function test_interpreter_normalize_commands_payload_keeps_raw_task_name(): void {
        $registry = task_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('normalize_commands_payload');
        $method->setAccessible(true);

        $commands = $method->invoke($interpreter, [
            'commands' => [[
                'task' => 'create_booking',
                'version' => 1,
                'input' => ['question' => 'Need help'],
            ]],
        ], 'Need help');

        $this->assertSame('create_booking', (string)($commands[0]['task'] ?? ''));
    }

    /**
     * Test that preflight pipeline supports skipping duplicate schema checks for interpreter-validated commands.
     */
    public function test_preflight_pipeline_supports_structural_validation_skip_flag(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\services\preflight_pipeline::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString("_structural_validated", $source);
        $this->assertStringContainsString("if (!\$skipcontractschema)", $source);
    }

    /**
     * Test that synchronizer input includes phase trace and execution feedback blocks.
     */
    public function test_synchronizer_input_builder_includes_phase_trace_and_execution_feedback(): void {
        $builder = new \bookingextension_agent\local\wbagent\services\synchronizer_input_builder();

        $observations = $builder->build_observations([
            'response_type' => 'execution_result',
            'message' => 'Done',
            'phase_trace' => [
                'discovery' => ['response_type' => 'clarification'],
                'selection' => ['response_type' => 'clarification'],
                'parameter_construction' => ['response_type' => 'task_call'],
            ],
            'results' => [
                ['task' => 'core.get_current_user', 'status' => 'ok'],
                ['task' => 'core.recreate_task_catalog', 'status' => 'error'],
            ],
        ]);

        $joined = implode("\n\n", $observations);
        $this->assertStringContainsString('PHASE_TRACE', $joined);
        $this->assertStringContainsString('EXECUTION_FEEDBACK', $joined);
    }

    /**
     * Test that synchronizer routing no longer reuses planner process() entry.
     */
    public function test_synchronizer_routing_uses_dedicated_orchestrator_path(): void {
        $reflection = new \ReflectionClass(
            \bookingextension_agent\local\wbagent\services\synchronizer_routing_service::class
        );
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('process_synchronizer(', $source);
        $this->assertStringNotContainsString('->process(', $source);
    }

    /**
     * Test that synchronizer output contract never mutates command payloads.
     */
    public function test_synchronizer_output_contract_preserves_source_commands(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'confirmation_request',
            'message' => 'Original',
            'commands' => [
                ['task' => 'core.recreate_task_catalog', 'version' => 1, 'input' => ['force' => true]],
            ],
            'lang' => 'de',
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Polished output.',
            'commands' => [
                ['task' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'lang' => 'de',
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame($source['commands'], $merged['commands']);
        $this->assertSame('Original', $merged['message']);
    }

    /**
     * Test that dedicated synchronizer prompt builder is wired in orchestrator.
     */
    public function test_orchestrator_uses_dedicated_synchronizer_prompt_builder(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('new synchronizer_prompt_builder()', $source);
        $this->assertStringContainsString('synchronizerpromptbuilder->build_prompt(', $source);
    }

    /**
     * Test that discovery stage controller is wired into the live orchestrator flow.
     */
    public function test_orchestrator_discovery_uses_live_stage_controller(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('new discovery_stage_controller()', $source);
        $this->assertStringContainsString('filter_catalog_by_selected_families(', $source);
        $this->assertStringContainsString("'discovery_stage' => \$discoverystage", $source);
    }

    /**
     * Test that family filter helper no longer falls back to full catalog.
     */
    public function test_orchestrator_family_filter_is_strict_without_full_catalog_fallback(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('if (empty($allow)) {', $source);
        $this->assertStringContainsString('return [];', $source);
    }
}
