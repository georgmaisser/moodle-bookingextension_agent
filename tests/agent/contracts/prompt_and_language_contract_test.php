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

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\services\language_policy_service;
use bookingextension_agent\local\wbagent\services\task_prompt_contract;
use bookingextension_agent\local\wbagent\task_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for explicit prompt contracts and language policy.
 *
 * @covers \bookingextension_agent\local\wbagent\task_registry
 * @covers \bookingextension_agent\local\wbagent\services\language_policy_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_and_language_contract_test extends TestCase {
    /**
     * Ensure prompt contracts are explicit and no longer inferred from task naming conventions.
     */
    public function test_prompt_contracts_do_not_use_name_based_heuristics(): void {
        $registry = new task_registry();

        $task = $this->createMock(task_interface::class);
        $task->method('get_name')->willReturn('dummy.create_dummy');
        $task->method('get_schema')->willReturn([
            'description' => 'Dummy task for explicit prompt-contract tests.',
            'readonly' => false,
            'version' => 1,
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Query text.',
                ],
            ],
            'required' => [],
        ]);
        $task->method('is_read_only')->willReturn(false);
        $task->method('get_example_input')->willReturn([]);
        $task->method('get_prompt_contract')->willReturn(new task_prompt_contract([]));

        $provider = $this->createMock(task_provider_interface::class);
        $provider->method('get_component')->willReturn('local_dummy');
        $provider->method('get_tasks')->willReturn([$task]);
        $provider->method('get_contextual_prompt_packs')->willReturn([]);
        $provider->method('get_issue_code_provider')->willReturn(null);
        $provider->method('get_prompt_guidance')->willReturn([]);

        $registry->register($provider);
        $contracts = $registry->get_all_prompt_contracts();

        $this->assertCount(1, $contracts);
        $contract = $contracts[0];
        $this->assertSame('dummy.create_dummy', $contract['task']);
        $this->assertSame('task', $contract['intent']);
        $this->assertSame([], $contract['minimal_input']);
        $this->assertSame([], $contract['anchors']);
    }

    /**
     * Ensure language authority prefers persisted user input language over model language hints.
     */
    public function test_language_policy_prefers_user_input_language(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_thread_metadata_value'])
            ->getMock();

        $store->method('get_thread_metadata_value')
            ->willReturnCallback(static function (int $threadid, string $key) {
                if ($key === 'user_input_lang') {
                    return 'de';
                }
                if ($key === 'last_output_lang') {
                    return 'en';
                }
                return '';
            });

        $service = new language_policy_service();
        $resolved = $service->resolve_output_language($store, 42, [
            'user_lang' => 'fr',
            'lang' => 'it',
        ]);

        $this->assertSame('de', $resolved);
    }

    /**
     * Ensure fallback string mapping remains deterministic.
     */
    public function test_language_policy_fallback_string_mapping(): void {
        $service = new language_policy_service();

        $this->assertSame('ai_fallback_error', $service->fallback_string_id_for_response_type('error'));
        $this->assertSame(
            'ai_fallback_confirmation_request',
            $service->fallback_string_id_for_response_type('confirmation_request')
        );
        $this->assertSame('ai_fallback_task_call', $service->fallback_string_id_for_response_type('task_call'));
        $this->assertSame('ai_fallback_summary', $service->fallback_string_id_for_response_type('clarification'));
        $this->assertSame('ai_preflight_retry_hint', $service->preflight_retry_hint_string_id());
    }

    /**
     * Ensure language-policy authority behaves consistently for de/en/zh inputs.
     */
    public function test_language_policy_matrix_de_en_zh(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_thread_metadata_value'])
            ->getMock();

        $service = new language_policy_service();

        $store->method('get_thread_metadata_value')
            ->willReturnMap([
                [11, 'user_input_lang', 'de'],
                [11, 'last_output_lang', 'en'],
                [12, 'user_input_lang', 'en'],
                [12, 'last_output_lang', 'de'],
                [13, 'user_input_lang', 'zh'],
                [13, 'last_output_lang', 'en'],
            ]);

        $this->assertSame('de', $service->resolve_output_language($store, 11, ['user_lang' => 'fr', 'lang' => 'it']));
        $this->assertSame('en', $service->resolve_output_language($store, 12, ['user_lang' => 'fr', 'lang' => 'it']));
        $this->assertSame('zh', $service->resolve_output_language($store, 13, ['user_lang' => 'fr', 'lang' => 'it']));
    }
}
