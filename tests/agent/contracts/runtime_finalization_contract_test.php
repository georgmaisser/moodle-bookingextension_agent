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

use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use PHPUnit\Framework\TestCase;

/**
 * Runtime finalization contract tests.
 *
 * @covers \bookingextension_agent\local\wbagent\agent_runtime
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runtime_finalization_contract_test extends TestCase {
    /**
     * Template-only path provides deterministic fallback message when source message is empty.
     */
    public function test_template_only_finalization_sets_deterministic_message(): void {
        $runtime = $this->build_runtime();

        $result = $this->invoke_private_method($runtime, 'apply_template_only_finalization', [
            123,
            [
                'response_type' => 'error',
                'message' => '',
                'issue_codes' => ['BUDGET_EXCEEDED'],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertNotSame('', trim((string)($result['message'] ?? '')));
        $this->assertStringContainsString('loop budget is exhausted', (string)($result['message'] ?? ''));
    }

    /**
     * Synchronizer message merge must rollback on response_type drift.
     */
    public function test_merge_rolls_back_when_response_type_drifts(): void {
        $runtime = $this->build_runtime();

        $source = [
            'response_type' => 'clarification',
            'message' => 'source message',
            'commands' => [],
            'lang' => 'de',
        ];
        $sync = [
            'response_type' => 'error',
            'message' => 'sync message',
            'commands' => [],
            'lang' => 'en',
        ];

        $merged = $this->invoke_private_method($runtime, 'merge_synchronized_message', [$source, $sync]);

        $this->assertSame($source, $merged);
    }

    /**
     * Synchronizer message merge may update message/lang when contract shape remains stable.
     */
    public function test_merge_accepts_stable_response_type_without_commands(): void {
        $runtime = $this->build_runtime();

        $source = [
            'response_type' => 'clarification',
            'message' => 'source message',
            'commands' => [],
            'lang' => 'de',
        ];
        $sync = [
            'response_type' => 'clarification',
            'message' => 'sync message',
            'commands' => [],
            'lang' => 'en',
        ];

        $merged = $this->invoke_private_method($runtime, 'merge_synchronized_message', [$source, $sync]);

        $this->assertSame('sync message', (string)($merged['message'] ?? ''));
        $this->assertSame('en', (string)($merged['lang'] ?? ''));
        $this->assertSame('clarification', (string)($merged['response_type'] ?? ''));
    }

    /**
     * Build a minimal agent_runtime with mocked dependencies.
     *
     * @return agent_runtime
     */
    private function build_runtime(): agent_runtime {
        $registry = $this->createMock(task_registry::class);
        $orchestrator = $this->createMock(orchestrator::class);
        $store = $this->createMock(conversation_store::class);
        $authz = $this->createMock(authorization_service::class);

        return new agent_runtime($registry, $orchestrator, $store, $authz);
    }

    /**
     * Invoke private method on runtime instance.
     *
     * @param agent_runtime $runtime
     * @param string $method
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invoke_private_method(agent_runtime $runtime, string $method, array $args) {
        $reflection = new \ReflectionClass(agent_runtime::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($runtime, $args);
    }
}
