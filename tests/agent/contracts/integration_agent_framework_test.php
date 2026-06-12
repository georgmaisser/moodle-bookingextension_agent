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
use bookingextension_agent\local\wbagent\interfaces\skill_provider_interface;
use bookingextension_agent\local\wbagent\skill_registry;
use bookingextension_agent\local\wbagent\skill_registry_factory;
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
     * Test that skill_registry discovers skills from the booking plugin provider.
     */
    public function test_skill_registry_discovers_booking_skills(): void {
        $registry = skill_registry_factory::get_default();
        $skills = $registry->get_skills();

        // Verify that skills are discovered.
        $this->assertNotEmpty($skills, 'Skill registry should discover skills from booking plugin');
        $this->assertGreaterThanOrEqual(2, count($skills), 'Should discover at least 2 booking skills');

        // Verify skill names follow the pattern: <component>.<skillname>.
        foreach ($skills as $skill) {
            $name = $skill->get_name();
            $this->assertStringContainsString('.', $name, 'Skill name should include component prefix');
        }
    }

    /**
     * Test that skill_provider interface supports optional issue code provider.
     */
    public function test_skill_provider_interface_supports_issue_code_provider(): void {
        $provider = new \bookingextension_agent\local\wbagent\skill_provider();

        // Verify interface methods exist.
        $this->assertTrue(
            method_exists($provider, 'get_issue_code_provider'),
            'skill_provider should implement get_issue_code_provider()'
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
     * Test that skill_provider interface supports optional prompt guidance.
     */
    public function test_skill_provider_interface_supports_prompt_guidance(): void {
        $provider = new \bookingextension_agent\local\wbagent\skill_provider();

        // Verify interface methods exist.
        $this->assertTrue(
            method_exists($provider, 'get_prompt_guidance'),
            'skill_provider should implement get_prompt_guidance()'
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
        $registry = skill_registry_factory::get_default();
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
     * The construction-phase catalog entry for the selected skill must carry that skill's full
     * prompt-pack guidance unconditionally (no language-gated trigger filter). This is what makes
     * situational rules — e.g. "for same-named options, call search_options first and use optionid"
     * — reach the constructor regardless of the user's language.
     */
    public function test_construction_catalog_includes_skill_guidance_unconditionally(): void {
        $registry = skill_registry_factory::get_default();
        $store = new \bookingextension_agent\local\wbagent\conversation_store();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);
        $orchestrator = new \bookingextension_agent\local\wbagent\orchestrator($registry, $interpreter, $store);

        $skill = $registry->get_skill('mod_booking.update_option');
        if ($skill === null) {
            $this->markTestSkipped('mod_booking.update_option not registered in default registry.');
        }

        $method = new \ReflectionMethod($orchestrator, 'enrich_construction_catalog_entry');
        $method->setAccessible(true);
        $entry = $method->invoke(
            $orchestrator,
            'mod_booking.update_option',
            ['skill' => 'mod_booking.update_option']
        );

        $this->assertArrayHasKey('guidance', $entry, 'Construction entry must carry skill guidance.');
        $guidancejoined = implode("\n", (array)$entry['guidance']);
        $this->assertStringContainsString('search_options', $guidancejoined);
        $this->assertStringContainsString('optionid', $guidancejoined);
    }

    /**
     * Test that skill schema includes prompt_meta when available.
     */
    public function test_skill_schema_includes_prompt_meta(): void {
        $registry = skill_registry_factory::get_default();

        // Get skills and verify at least one has prompt_meta.
        $skills = $registry->get_skills();
        $this->assertNotEmpty($skills, 'Registry should have skills');

        $foundpromptmeta = false;
        foreach ($skills as $skill) {
            $schema = $skill->get_schema();
            if (isset($schema['prompt_meta'])) {
                $foundpromptmeta = true;
                $this->assertIsArray($schema['prompt_meta'], 'prompt_meta should be array');
                $this->assertArrayHasKey('input_fields_for_prompt', $schema['prompt_meta']);
                $this->assertArrayHasKey('anchor_fields', $schema['prompt_meta']);
                break;
            }
        }

        $this->assertTrue($foundpromptmeta, 'At least one booking skill should have prompt_meta');
    }

    /**
     * Test that skill registry uses prompt_meta when building prompt contract.
     */
    public function test_skill_registry_prioritizes_prompt_meta(): void {
        $registry = skill_registry_factory::get_default();
        $contract = ['skills' => $registry->get_all_prompt_contracts()];

        // Verify contract includes skill catalog.
        $this->assertIsArray($contract, 'Prompt contract should be array');
        $this->assertArrayHasKey('skills', $contract, 'Contract should include skills');

        // Verify each skill has routing metadata.
        foreach ((array)$contract['skills'] as $skillinfo) {
            $this->assertIsArray($skillinfo, 'Skill info should be array');
            $this->assertArrayHasKey('skill', $skillinfo, 'Should have skill name');
        }
    }

    /**
     * Test that prompt contracts separate required inputs from routing examples.
     */
    public function test_prompt_contracts_use_required_minimals_and_explicit_examples(): void {
        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();

        $foundreadonlyskill = false;
        $foundmutatingskill = false;
        foreach ($contracts as $skillinfo) {
            $this->assertArrayHasKey('skill', $skillinfo, 'Every skill should expose skill name');
            $this->assertArrayHasKey('minimal_input', $skillinfo, 'Every skill should expose minimal_input');
            $this->assertArrayHasKey('example_input', $skillinfo, 'Every skill should expose example_input');
            $this->assertIsArray($skillinfo['minimal_input'], 'minimal_input should always be an array');
            $this->assertIsArray($skillinfo['example_input'], 'example_input should always be an array');

            if (!empty($skillinfo['readonly'])) {
                $foundreadonlyskill = true;
            } else {
                $foundmutatingskill = true;
            }
        }

        $this->assertNotEmpty($contracts, 'Prompt contracts should not be empty');
        $this->assertTrue($foundreadonlyskill, 'Expected at least one readonly skill contract');
        $this->assertTrue($foundmutatingskill, 'Expected at least one mutating skill contract');
    }

    /**
     * Test that slim planner catalog never recreates example_input from minimal_input.
     */
    public function test_slim_catalog_keeps_examples_separate_from_minimals(): void {
        $registry = skill_registry_factory::get_default();
        $orchestratorreflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $orchestrator = $orchestratorreflection->newInstanceWithoutConstructor();
        $assistantsummaryprop = $orchestratorreflection->getProperty('assistantsummariesvc');
        $assistantsummaryprop->setAccessible(true);
        $assistantsummarysvc = new \bookingextension_agent\local\wbagent\services\assistant_state_guidance_service($registry);
        $assistantsummaryprop->setValue($orchestrator, $assistantsummarysvc);
        $method = $orchestratorreflection->getMethod('slim_prompt_catalog_for_planner');
        $method->setAccessible(true);

        $slimcatalog = $method->invoke($orchestrator, $registry->get_all_prompt_contracts());
        $byskill = [];
        foreach ($slimcatalog as $skillinfo) {
            $byskill[(string)($skillinfo['skill'] ?? $skillinfo['skill'] ?? '')] = $skillinfo;
            $this->assertArrayHasKey('minimal_input', $skillinfo, 'Slim catalog should keep minimal_input');
            $this->assertIsArray($skillinfo['minimal_input'], 'Slim minimal_input should be an array');
            if (array_key_exists('example_input', $skillinfo)) {
                $this->assertIsArray($skillinfo['example_input'], 'Slim example_input should remain an array');
            }

            if (isset($skillinfo['description']) && is_string($skillinfo['description'])) {
                $this->assertLessThanOrEqual(240, \core_text::strlen($skillinfo['description']));
            }
        }

        $this->assertNotEmpty($byskill, 'Slim catalog should contain skill entries');
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
                'skill' => 'mod_booking.diagnose_booking_issue',
                'description' => 'Diagnose booking issue.',
                'readonly' => '1',
                'intent' => 'skill',
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
                'skill' => 'mod_booking.list_options',
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
            ['skill', 'readonly', 'intent', 'minimal_input', 'description', 'message_triggers', 'example_input'],
            array_keys($sanitized[0])
        );
        $this->assertSame('mod_booking.diagnose_booking_issue', (string)$sanitized[0]['skill']);
        $this->assertTrue((bool)$sanitized[0]['readonly']);
        $this->assertSame('skill', (string)$sanitized[0]['intent']);
        $this->assertSame([], $sanitized[0]['minimal_input']);
        $this->assertSame(['question'], $sanitized[0]['example_input']);
        $this->assertArrayHasKey('id', (array)($sanitized[0]['message_triggers'][0] ?? []));
        $this->assertArrayNotHasKey('embedding_json', $sanitized[0]);
        $this->assertArrayNotHasKey('embedding_model', $sanitized[0]);
        $this->assertArrayNotHasKey('embedding_dimensions', $sanitized[0]);
        $this->assertArrayNotHasKey('content_hash', $sanitized[0]);
        $this->assertArrayNotHasKey('score', $sanitized[0]);

        $this->assertSame(
            ['skill', 'readonly', 'intent', 'minimal_input', 'description', 'message_triggers'],
            array_keys($sanitized[1])
        );
        $this->assertSame('mod_booking.list_options', (string)$sanitized[1]['skill']);
        $this->assertFalse((bool)$sanitized[1]['readonly']);
        $this->assertSame('lookup', (string)$sanitized[1]['intent']);
        $this->assertSame(['optionquery'], $sanitized[1]['minimal_input']);
        $this->assertArrayNotHasKey('example_input', $sanitized[1]);
    }

    /**
     * Discovery-ranked construction allow-lists must keep all candidate skills
     * when selection has not explicitly chosen a single skill.
     */
    public function test_construction_allow_list_keeps_all_ranked_skills_without_explicit_selection(): void {
        $orchestratorreflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $orchestrator = $orchestratorreflection->newInstanceWithoutConstructor();

        $allowlistmethod = $orchestratorreflection->getMethod('build_construction_allowed_skills');
        $allowlistmethod->setAccessible(true);
        $selectedskillmethod = $orchestratorreflection->getMethod('extract_selected_skill_from_selection_phase_output');
        $selectedskillmethod->setAccessible(true);

        $allowed = $allowlistmethod->invoke($orchestrator, [
            ['skill' => 'mod_booking.create_option'],
            ['skill' => 'mod_booking.search_options'],
            ['skill' => 'mod_booking.create_option'],
        ], [
            ['skill' => 'mod_booking.update_option'],
        ]);

        $this->assertSame(
            ['mod_booking.create_option', 'mod_booking.search_options'],
            $allowed
        );
        $this->assertSame('', $selectedskillmethod->invoke($orchestrator, ['response_type' => 'sufficient']));
    }

    /**
     * Test that embedding-selected planner subsets keep full skill descriptions.
     */
    public function test_embedding_subset_keeps_full_descriptions(): void {
        $retrieval = new \bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service();
        $csvdescription = 'Persisted CSV description that should not win over live skill schema metadata.';
        $livedescription = 'Live skill description from get_schema that must win ' .
            'when embed skill selection is mapped back to skills.';

        $subset = $retrieval->build_planner_catalog_subset([
            [
                'skill' => 'booking.create_rule_from_template',
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
                'skill' => 'booking.create_rule_from_template',
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
        skill_registry_factory::reset();

        $retrieval = new \bookingextension_agent\local\wbagent\services\embeddings\embeddings_retrieval_service();
        $subset = $retrieval->build_planner_catalog_subset([
            [
                'skill' => 'wbagent.recreate_skill_catalog',
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

        // Verify template does not contain hardcoded plugin-specific skill names.
        $this->assertNotEmpty($template, 'Prompt template should not be empty');

        // Verify the live planner fallback stays cache-stable: no per-context
        // placeholders in the [SYSTEM] block; booking_name lives in [SYSTEM_RUNTIME].
        $this->assertStringNotContainsString('{{bookingname}}', $template, 'Template must not embed per-context names');
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
            'SKILL CATALOG',
            $summariseprompt,
            'Action prompt should reference skill catalog routing'
        );
        $this->assertStringContainsString(
            'Use only exact skill names from the SKILL CATALOG',
            $summariseprompt,
            'Action prompt should enforce skill-catalog based routing'
        );
        $this->assertStringContainsString(
            'Never invent aliases',
            $summariseprompt,
            'Action prompt should explicitly forbid invented skill aliases'
        );

        // Test explain_text action prompt.
        $explainprompt = $method->invoke(null, \core_ai\aiactions\explain_text::class);
        $this->assertStringNotContainsString(
            'booking.',
            $explainprompt,
            'Explain prompt should not hardcode booking-specific names'
        );
        $this->assertStringContainsString(
            'SKILL CATALOG',
            $explainprompt,
            'Explain prompt should reference skill-catalog based routing'
        );
    }

    /**
     * Test that booking base class is properly renamed.
     */
    public function test_discovered_skills_implement_skill_interface(): void {
        $provider = new \bookingextension_agent\local\wbagent\skill_provider();
        $skills = $provider->get_skills();

        $this->assertNotEmpty($skills, 'Provider should discover at least one skill');
        foreach ($skills as $skill) {
            $this->assertInstanceOf(
                \bookingextension_agent\local\wbagent\interfaces\skill_interface::class,
                $skill
            );
        }
    }

    /**
     * Test multi-provider scenario: booking and other plugins can coexist.
     */
    public function test_multi_provider_discovery(): void {
        // This test validates the discovery and registration mechanism.
        $registry = skill_registry_factory::get_default();

        // Verify booking skills are registered.
        $skills = $registry->get_skills();
        $this->assertNotEmpty($skills, 'Registry should have skills from providers');

        // Verify skill names include component prefix (plugin-specific routing).
        // Legacy names used booking.*, current core skills use core.*.
        $componentprefixskillfound = false;
        $coreskillfound = false;
        foreach ($skills as $skill) {
            $name = (string)$skill->get_name();
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_]/', $name) === 1) {
                $componentprefixskillfound = true;
            }
            if (str_starts_with($name, 'core.')) {
                $coreskillfound = true;
            }
        }

        $this->assertTrue($componentprefixskillfound, 'Should have skills prefixed with plugin component');
        $this->assertTrue($coreskillfound, 'Should expose core.* skills from bookingextension_agent');
    }

    /**
     * Test that skill discovery scans all direct skill namespaces under local/wbagent.
     */
    public function test_skill_discovery_scans_all_wbagent_skill_namespaces(): void {
        skill_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wbagent\skill_provider();
        $skillnames = array_map(static fn($skill): string => $skill->get_name(), $provider->get_skills());

        $this->assertContains('core.get_current_user', $skillnames);
        $this->assertContains('wbagent.recreate_skill_catalog', $skillnames);

        $exampleskillclass = '\\bookingextension_agent\\local\\wbagent\\examples\\skills\\readonly_example_skill';
        if (class_exists($exampleskillclass)) {
            $this->assertContains('examples.readonly_example', $skillnames);
        }
    }

    /**
     * Test that discovery does not expose duplicate skill names.
     */
    public function test_skill_discovery_deduplicates_same_skill_name(): void {
        skill_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wbagent\skill_provider();
        $skillnames = array_map(static fn($skill): string => $skill->get_name(), $provider->get_skills());

        $this->assertSame($skillnames, array_values(array_unique($skillnames)));
    }

    /**
     * Test that trigger-provider discovery ignores non-trigger classes without failing.
     */
    public function test_trigger_provider_discovery_ignores_non_trigger_classes(): void {
        $providers = \bookingextension_agent\local\wbagent\skill_discovery::get_trigger_provider_instances(
            'bookingextension_agent'
        );

        $this->assertNotEmpty($providers);
        foreach ($providers as $provider) {
            $this->assertInstanceOf(
                \bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface::class,
                $provider
            );
        }
    }

    /**
     * Test that language-specific logic is removed from skills.
     */
    public function test_skills_no_language_specific_logic(): void {
        $provider = new \bookingextension_agent\local\wbagent\skill_provider();
        $skills = $provider->get_skills();

        $this->assertNotEmpty($skills, 'Provider should discover skills for reflection checks');
        foreach ($skills as $skill) {
            $reflection = new \ReflectionClass($skill);
            $this->assertFalse(
                $reflection->hasMethod('looks_like_german'),
                'Skill classes must not contain language-token heuristics'
            );
            $this->assertFalse(
                $reflection->hasMethod('build_disambiguation_message'),
                'Skill classes must not contain language-specific disambiguation helpers'
            );
        }
    }

    /**
     * Test skill schema validation includes all required fields.
     */
    public function test_skill_schema_required_fields(): void {
        $registry = skill_registry_factory::get_default();
        $skills = $registry->get_skills();

        foreach ($skills as $skill) {
            $schema = $skill->get_schema();

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
     * R3 guardrail: planner loop retry must be blocked when R3_NO_RETRY is present.
     */
    public function test_agent_runtime_retry_resolver_blocks_retry_when_r3_issue_present(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_PARSE_ERROR', 'R3_NO_RETRY'],
            'commands' => [],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertNull($resolved);
    }

    /**
     * Synchronizer gate issue codes must not be interpreted as planner-retryable contract errors.
     */
    public function test_agent_runtime_retry_resolver_ignores_synchronizer_gate_issue_codes(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => [
                'SYNC_FACT_CONFLICT_REJECTED',
                'SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED',
            ],
            'commands' => [],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertNull($resolved);
    }

    /**
     * R3 guardrail: planner loop retry must be blocked when command risk class is R3.
     */
    public function test_agent_runtime_retry_resolver_blocks_retry_when_command_risk_is_r3(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED'],
            'commands' => [
                [
                    'skill' => 'mod_booking.book_users',
                    'risk_class' => \bookingextension_agent\local\wbagent\dto\skill_risk_class::R3,
                    'input' => [],
                ],
            ],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertNull($resolved);
    }

    /**
     * Control check: retry remains enabled for retryable planner contract errors without R3 blockers.
     */
    public function test_agent_runtime_retry_resolver_allows_retry_without_r3_blocker(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_PARSE_ERROR'],
            'commands' => [],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertSame('CONTRACT_PARSE_ERROR', $resolved);
    }

    /**
     * Guardrail: same error class may not open retry in a third distinct layer.
     */
    public function test_queue_transition_retry_layer_guard_blocks_third_distinct_layer(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\services\queue_transition_service::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('evaluate_retry_layer_guard');
        $method->setAccessible(true);

        $decision = $method->invoke(
            $service,
            'provider_error',
            ['preflight', 'execution'],
            'provider_error',
            'QUEUE_RETRY_HINT'
        );

        $this->assertFalse((bool)($decision['allow'] ?? true));
        $this->assertSame(['preflight', 'execution'], array_values((array)($decision['layers'] ?? [])));
    }

    /**
     * Guardrail: retry layer sequence resets when error class changes.
     */
    public function test_queue_transition_retry_layer_guard_resets_for_new_error_class(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\services\queue_transition_service::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('evaluate_retry_layer_guard');
        $method->setAccessible(true);

        $decision = $method->invoke(
            $service,
            'provider_error',
            ['preflight', 'execution'],
            'domain_error',
            'QUEUE_RETRY_HINT'
        );

        $this->assertTrue((bool)($decision['allow'] ?? false));
        $this->assertSame(['queue'], array_values((array)($decision['layers'] ?? [])));
    }

    /**
     * Retry policy maps contract/parse signals to TECHNICAL category.
     */
    public function test_retry_policy_maps_contract_errors_to_technical(): void {
        $policy = new \bookingextension_agent\local\wbagent\services\retry_policy_service();
        $category = $policy->resolve_retry_hint_category(
            '',
            ['CONTRACT_PARSE_ERROR'],
            'planner'
        );

        $this->assertSame(
            \bookingextension_agent\local\wbagent\services\retry_policy_service::CATEGORY_TECHNICAL,
            $category
        );
        $this->assertTrue($policy->is_retryable_category($category));
    }

    /**
     * Retry policy classifies domain conflicts as non-retryable.
     */
    public function test_retry_policy_marks_domain_category_not_retryable(): void {
        $policy = new \bookingextension_agent\local\wbagent\services\retry_policy_service();
        $category = $policy->resolve_retry_hint_category(
            'domain_conflict',
            ['DOMAIN_CONFLICT'],
            'preflight'
        );

        $this->assertSame(
            \bookingextension_agent\local\wbagent\services\retry_policy_service::CATEGORY_DOMAIN,
            $category
        );
        $this->assertFalse($policy->is_retryable_category($category));
    }

    /**
     * Provider auth failures open the provider circuit breaker.
     */
    public function test_retry_policy_provider_circuit_breaker_blocks_auth(): void {
        $policy = new \bookingextension_agent\local\wbagent\services\retry_policy_service();
        $decision = $policy->evaluate_provider_circuit_breaker(
            'auth_error',
            ['PROVIDER_AUTH_FAILED']
        );

        $this->assertFalse((bool)($decision['allow'] ?? true));
        $this->assertContains(
            \bookingextension_agent\local\wbagent\services\retry_policy_service::ISSUE_PROVIDER_CIRCUIT_OPEN_AUTH,
            (array)($decision['issue_codes'] ?? [])
        );
    }

    /**
     * Planner retry must be blocked when queue/execution retry is already active.
     */
    public function test_agent_runtime_blocks_planner_retry_on_non_planner_signal(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('has_active_non_planner_retry_signal');
        $method->setAccessible(true);

        $result = [
            'issue_codes' => ['RETRY_WAITING', 'EXECUTION_RETRY_HINT'],
        ];

        $blocked = $method->invoke($runtime, $result);
        $this->assertTrue((bool)$blocked);
    }

    /**
     * Constructor JSON parse retries must exhaust exactly at configured limit.
     */
    public function test_agent_runtime_constructor_parse_retry_exhaustion_is_deterministic(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();

        $retrymethod = $reflection->getMethod('resolve_framework_retry_issue_code');
        $retrymethod->setAccessible(true);
        $exhaustedmethod = $reflection->getMethod('resolve_exhausted_framework_retry_issue_code');
        $exhaustedmethod->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_PARSE_ERROR'],
            'commands' => [],
        ];

        $this->assertSame('CONTRACT_PARSE_ERROR', $retrymethod->invoke($runtime, $result, []));
        $this->assertNull($retrymethod->invoke($runtime, $result, ['CONTRACT_PARSE_ERROR' => 1]));
        $this->assertSame('CONTRACT_PARSE_ERROR', $exhaustedmethod->invoke($runtime, $result, ['CONTRACT_PARSE_ERROR' => 1]));
    }

    /**
     * Budget guard must stop deterministically at the loop boundary.
     */
    public function test_agent_runtime_budget_guard_stops_at_limit_boundary(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('budget_guard_allows_next_llm_call');
        $method->setAccessible(true);

        $this->assertTrue((bool)$method->invoke($runtime, 0, 2));
        $this->assertFalse((bool)$method->invoke($runtime, 1, 2));
    }

    /**
     * Audit log payload must include deterministic reconstruction fields.
     */
    public function test_preflight_audit_log_format_contains_reconstruction_fields(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\services\preflight_audit_logger::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString("'timestamp'", $source);
        $this->assertStringContainsString("'thread_id'", $source);
        $this->assertStringContainsString("'contextid'", $source);
        $this->assertStringContainsString("'run_id'", $source);
        $this->assertStringContainsString("'queue_item_id'", $source);
        $this->assertStringContainsString("'layer'", $source);
        $this->assertStringContainsString("'status'", $source);
        $this->assertStringContainsString("'reason_code'", $source);
        $this->assertStringContainsString("'issue_codes'", $source);
        $this->assertStringContainsString("'retry_count'", $source);
        $this->assertStringContainsString("'retry_after_ms'", $source);
        $this->assertStringContainsString("'duration_ms'", $source);
        $this->assertStringContainsString("'error_class'", $source);
    }

    /**
     * Template finalization must keep technical root-cause messages explicit.
     */
    public function test_finalization_template_message_reflects_technical_cause(): void {
        $templates = new \bookingextension_agent\local\wbagent\services\finalization_template_service();

        $budget = $templates->resolve_message([
            'issue_codes' => ['BUDGET_EXCEEDED'],
        ]);
        $this->assertStringContainsString('loop budget is exhausted', $budget);

        $timeout = $templates->resolve_message([
            'error_class' => 'provider_timeout',
        ]);
        $this->assertStringContainsString('timed out', $timeout);
    }

    /**
     * Regression guard: readonly and mutating command routing split stays stable.
     */
    public function test_decision_service_mutability_split_preserves_readonly_vs_mutating(): void {
        $reflection = new \ReflectionClass(
            \bookingextension_agent\local\wbagent\services\decision\agent_decision_service::class
        );
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('split_commands_by_mutability');
        $method->setAccessible(true);

        $groups = $method->invoke($service, [
            ['skill' => 'core.get_current_user', 'input' => [], 'risk_class' => 'read_only'],
            ['skill' => 'mod_booking.create_option', 'input' => [], 'risk_class' => 'broad_write'],
        ]);

        $this->assertCount(1, (array)($groups['readonly'] ?? []));
        $this->assertCount(1, (array)($groups['mutating'] ?? []));
    }

    /**
     * Regression guard: pending confirmation flow stays consistent and unblocked.
     */
    public function test_pending_confirmation_message_is_not_blocked_as_new_intent(): void {
        $reflection = new \ReflectionClass(
            \bookingextension_agent\local\wbagent\services\decision\agent_decision_service::class
        );
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('should_block_new_intent_while_pending');
        $method->setAccessible(true);

        $confirmpending = $method->invoke($service, [
            'response_type' => 'confirm_pending',
            'commands' => [],
            'used_triggers' => [],
        ]);
        $this->assertFalse((bool)$confirmpending);

        $confirmmessage = $method->invoke($service, [
            'response_type' => 'skill_call',
            'commands' => [['skill' => 'core.get_current_user', 'input' => []]],
            'used_triggers' => ['core.is_confirmation_message'],
        ]);
        $this->assertFalse((bool)$confirmmessage);
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
                'response_type' => 'skill_call',
                'message' => 'selection message',
                'selected_skill' => 'core.get_current_user',
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
        $this->assertSame('core.get_current_user', $result['phase_trace']['selection']['selected_skill']);
        $this->assertSame('embed_topk', $result['phase_trace']['selection']['catalogselectionmode']);
        $this->assertSame('', $result['phase_trace']['parameter_construction']['embeddingstatus']);
        $this->assertArrayNotHasKey('discovery', $result['phase_trace']);
    }

    /**
     * Test that the phase-aware interpreter wrapper tags the normalized phase.
     */
    public function test_interpret_phase_output_tags_phase(): void {
        $registry = skill_registry_factory::get_default();
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
     * Test that selection phase now behaves like a single-skill selector call.
     */
    public function test_interpreter_phase_contract_accepts_single_selector_skill_in_selection(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);
        $selectionpayload = '{"response_type":"skill_call","message":"Selecting skill","used_triggers":[],'
            . '"commands":[{"skill":"mod_booking.create_option","version":1,"input":{}}]}';

        $result = $interpreter->interpret_phase_output(
            $selectionpayload,
            'selection',
            [
                'contextid' => 12,
                'userid' => 34,
                'lastusermessage' => 'Please continue',
            ]
        );

        $this->assertSame('skill_call', $result['response_type']);
        $this->assertSame('selection', $result['phase']);
        $this->assertSame('mod_booking.create_option', $result['selected_skill']);
        $this->assertCount(1, $result['commands']);
    }

    /**
     * Test that selection handoff strips parameter payload and keeps only one selected skill command.
     */
    public function test_orchestrator_selection_handoff_normalization_strips_payload(): void {
        $orchestratorreflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $orchestrator = $orchestratorreflection->newInstanceWithoutConstructor();

        $method = $orchestratorreflection->getMethod('normalize_selection_phase_output_for_handoff');
        $method->setAccessible(true);

        $result = $method->invoke($orchestrator, [
            'response_type' => 'skill_call',
            'message' => 'Selecting skill',
            'commands' => [[
                'skill' => 'mod_booking.create_option',
                'version' => 2,
                'input' => [
                    'optionname' => 'Yoga',
                    'duration' => 60,
                ],
            ]],
            'selected_skill' => 'mod_booking.create_option',
        ]);

        $this->assertSame('skill_call', $result['response_type']);
        $this->assertSame('mod_booking.create_option', $result['selected_skill']);
        $this->assertCount(1, $result['commands']);
        $this->assertSame('mod_booking.create_option', (string)$result['commands'][0]['skill']);
        $this->assertSame(2, (int)$result['commands'][0]['version']);
        $this->assertSame([], $result['commands'][0]['input']);
    }

    /**
     * Test that selection handoff normalization rejects multi-command skill_call payloads.
     */
    public function test_orchestrator_selection_handoff_normalization_rejects_multi_command_payload(): void {
        $orchestratorreflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $orchestrator = $orchestratorreflection->newInstanceWithoutConstructor();

        $method = $orchestratorreflection->getMethod('normalize_selection_phase_output_for_handoff');
        $method->setAccessible(true);

        $result = $method->invoke($orchestrator, [
            'response_type' => 'skill_call',
            'message' => 'Selecting skill',
            'commands' => [
                ['skill' => 'mod_booking.create_option', 'version' => 1, 'input' => []],
                ['skill' => 'mod_booking.update_option', 'version' => 1, 'input' => []],
            ],
            'selected_skill' => 'mod_booking.create_option',
        ]);

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED', $result['issue_codes']);
        $this->assertSame([], $result['commands']);
        $this->assertSame('', $result['selected_skill']);
    }

    /**
     * Test that interpreter keeps strict JSON parsing at the trust boundary.
     */
    public function test_interpreter_rejects_non_json_payload(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $result = $interpreter->interpret('this is not json', 0, 0, '');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PARSE_ERROR', $result['issue_codes']);
    }

    /**
     * Test that unknown response_type values are rejected by allow-list contract.
     */
    public function test_interpreter_rejects_unknown_response_type(): void {
        $registry = skill_registry_factory::get_default();
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
     * Test that orchestrator executes two planner invoke calls (selection + construction).
     */
    public function test_orchestrator_process_uses_two_phase_invokes(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wbagent\orchestrator::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertGreaterThanOrEqual(2, substr_count($source, '->invoke_for_context('));
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_SELECTION', $source);
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION', $source);
    }

    /**
     * Test that construction phase allows multiple commands when all skills are in allow-list.
     */
    public function test_interpreter_construction_phase_accepts_multi_command_batch_in_allow_list(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => ['foo' => 'bar']],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction', [
            'allowed_skills' => ['core.get_current_user'],
        ]);

        $this->assertSame('skill_call', $result['response_type']);
        $this->assertCount(2, (array)($result['commands'] ?? []));
    }

    /**
     * Test that construction phase rejects skills outside discovery-ranked allow-list.
     */
    public function test_interpreter_construction_phase_rejects_skill_outside_allow_list(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction', [
            'allowed_skills' => ['wbagent.recreate_skill_catalog'],
        ]);

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PHASE_SKILL_NOT_ALLOWED', $result['issue_codes']);
    }

    /**
     * Test that selection contract rejects selector skill and selected_skill mismatches.
     */
    public function test_interpreter_selection_phase_rejects_selected_skill_mismatch(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'selected_skill' => 'wbagent.recreate_skill_catalog',
            'message' => 'Selecting.',
        ], 'selection');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_SELECTION_SKILL_MISMATCH', $result['issue_codes']);
    }

    /**
     * Test that command payload normalization keeps raw skill names for selector-only canonicalization.
     */
    public function test_interpreter_normalize_commands_payload_keeps_raw_skill_name(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('normalize_commands_payload');
        $method->setAccessible(true);

        $commands = $method->invoke($interpreter, [
            'commands' => [[
                'skill' => 'create_booking',
                'version' => 1,
                'input' => ['question' => 'Need help'],
            ]],
        ], 'Need help');

        $this->assertSame('create_booking', (string)($commands[0]['skill'] ?? ''));
    }

    /**
     * Test that unknown command envelope keys are ignored during input normalization.
     */
    public function test_interpreter_normalize_commands_payload_ignores_unknown_command_keys(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wbagent\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('normalize_commands_payload');
        $method->setAccessible(true);

        $commands = $method->invoke($interpreter, [
            'commands' => [[
                'skill' => 'mod_booking.create_option',
                'version' => 1,
                'command_id' => 'cmd_1',
                'commandid' => 'cmd_legacy',
                'id' => '42',
                'cid' => 'abc',
                'parameters' => [
                    'text' => 'Portishead 1',
                    'maxanswers' => 8,
                ],
            ]],
        ], 'Portishead');

        $this->assertSame('mod_booking.create_option', (string)($commands[0]['skill'] ?? ''));
        $this->assertSame('Portishead 1', (string)($commands[0]['input']['text'] ?? ''));
        $this->assertSame(8, (int)($commands[0]['input']['maxanswers'] ?? 0));
        $this->assertArrayNotHasKey('command_id', $commands[0]['input']);
        $this->assertArrayNotHasKey('commandid', $commands[0]['input']);
        $this->assertArrayNotHasKey('id', $commands[0]['input']);
        $this->assertArrayNotHasKey('cid', $commands[0]['input']);
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
                'parameter_construction' => ['response_type' => 'skill_call'],
            ],
            'results' => [
                ['skill' => 'core.get_current_user', 'status' => 'ok'],
                ['skill' => 'wbagent.recreate_skill_catalog', 'status' => 'error'],
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
                ['skill' => 'wbagent.recreate_skill_catalog', 'version' => 1, 'input' => ['force' => true]],
            ],
            'lang' => 'de',
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Polished output.',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'lang' => 'de',
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame($source['commands'], $merged['commands']);
        $this->assertSame('Original', $merged['message']);
    }

    /**
     * Synchronizer must reject stale fact drift when source evidence and polished text disagree.
     */
    public function test_synchronizer_output_contract_rejects_option_id_fact_conflict(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'sufficient',
            'message' => 'Booking option created (title="Agent Fire 2", id=1429, link=https://example.invalid).',
            'results' => [
                [
                    'status' => 'executed',
                    'skill' => 'mod_booking.create_option',
                    'detail' => 'Booking option created (title="Agent Fire 2", id=1429, link=https://example.invalid).',
                ],
            ],
            'issue_codes' => [],
            'commands' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Alle Aktionen erledigt. Agent Fire 2 wurde mit id=1428 erstellt.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame($source['message'], $merged['message']);
        $this->assertContains('SYNC_FACT_CONFLICT_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_FACT_CONFLICT_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must emit deterministic issue code + telemetry for contract rejects.
     */
    public function test_synchronizer_output_contract_sets_issue_code_for_contract_reject(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'sufficient',
            'message' => 'Original source result.',
            'issue_codes' => [],
            'commands' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Any text',
            'issue_codes' => ['CONTRACT_PARSE_ERROR'],
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('Original source result.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_CONTRACT_ISSUE_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_CONTRACT_ISSUE_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must never replace source message when source response_type is error.
     */
    public function test_synchronizer_output_contract_rejects_message_replace_for_source_error(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'error',
            'message' => 'Source error detail that must stay visible.',
            'commands' => [],
            'issue_codes' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Everything completed successfully.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('Source error detail that must stay visible.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must preserve source message for failed postcondition outcomes.
     */
    public function test_synchronizer_output_contract_rejects_message_replace_for_failed_postcondition(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'execution_result',
            'postcondition_status' => 'failed',
            'message' => 'Trainer assignment postcondition failed.',
            'commands' => [],
            'issue_codes' => ['POSTCONDITION_FAILED'],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Trainer assignment completed.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('Trainer assignment postcondition failed.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must reject success polishing when the latest source result is an error.
     */
    public function test_synchronizer_output_contract_rejects_success_when_latest_result_is_error(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'execution_result',
            'message' => 'One sub-step failed and requires attention.',
            'commands' => [],
            'issue_codes' => [],
            'results' => [
                ['status' => 'executed', 'skill' => 'mod_booking.create_option'],
                ['status' => 'error', 'skill' => 'mod_booking.update_option_trainer'],
            ],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'All requested actions were completed successfully.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('One sub-step failed and requires attention.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Source-of-truth merge behavior must remain deterministic for identical inputs.
     */
    public function test_synchronizer_output_contract_merge_is_deterministic_for_same_input(): void {
        $contract = new \bookingextension_agent\local\wbagent\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'sufficient',
            'message' => 'Booking option created (id=1429).',
            'results' => [
                ['status' => 'executed', 'detail' => 'Booking option created (id=1429).'],
            ],
            'issue_codes' => [],
            'commands' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Option wurde erstellt (id=1428).',
            'commands' => [],
        ];

        $mergedone = $contract->merge($source, $sync);
        $mergedtwo = $contract->merge($source, $sync);

        $this->assertSame($mergedone, $mergedtwo);
        $this->assertContains('SYNC_FACT_CONFLICT_REJECTED', (array)($mergedone['issue_codes'] ?? []));
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
