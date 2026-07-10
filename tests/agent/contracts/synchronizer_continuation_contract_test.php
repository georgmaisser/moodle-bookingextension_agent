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

declare(strict_types=1);

namespace bookingextension_agent\tests\agent\contracts;

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use PHPUnit\Framework\TestCase;

/**
 * Continuation-truth contract (thread 558): the synchronizer must KNOW, from engine state,
 * whether anything runs after its reply — never guess or promise automatic follow-up.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_prompt_builder
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_continuation_contract_test extends TestCase {
    /**
     * Terminal turns (the default) carry the TURN END POLICY: nothing runs after the reply,
     * announcing automatic follow-up is forbidden. The old unconditional "agent will continue"
     * policy must be gone.
     */
    public function test_prompt_contract_defaults_to_turn_end_policy(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt('SYSTEM PROMPT', [], ['some observation']);

        $this->assertStringContainsString('TURN END POLICY', $prompt);
        $this->assertStringContainsString('NOTHING runs automatically after it', $prompt);
        $this->assertStringNotContainsString('state that the agent will continue with the remaining steps', $prompt);
        $this->assertStringNotContainsString('PENDING STEPS POLICY', $prompt);
    }

    /**
     * Only a confirmation_request turn may bind follow-up work to the user's confirmation.
     */
    public function test_prompt_contract_awaiting_confirmation_binds_followup_to_confirm(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt(
            'SYSTEM PROMPT',
            [],
            ['some observation'],
            '',
            '',
            synchronizer_prompt_builder::CONTINUATION_AWAITING_CONFIRMATION
        );

        $this->assertStringContainsString('PENDING STEPS POLICY', $prompt);
        $this->assertStringContainsString('ONLY after the user confirms', $prompt);
        $this->assertStringNotContainsString('TURN END POLICY', $prompt);
    }

    /**
     * confirm_pending without a pending confirmation while planned placeholders remain is a
     * recoverable planner flake: the fallback must be a retryable error (re-plan the step),
     * not a terminal clarification that orphans the rest of the series.
     */
    public function test_no_intent_fallback_with_planned_steps_is_retryable_error(): void {
        $service = $this->build_service_with_placeholders(true);

        $fallback = $this->invoke_fallback($service, [
            'response_type' => 'confirm_pending',
            'next_step_intent' => 'Create Sprint 5 (Friday) booking option',
        ], 'Sprint 5 (Freitag) ist noch ausstehend.');

        $this->assertSame('error', $fallback['response_type']);
        $this->assertContains('CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS', (array)($fallback['issue_codes'] ?? []));
    }

    /**
     * Without planned placeholders the fallback stays a terminal clarification — and must NOT
     * carry the model's next_step_intent: nothing follows the turn, and a copied intent fed
     * the synchronizer the false "further actions are queued" signal (thread 558).
     */
    public function test_no_intent_fallback_without_planned_steps_drops_next_step_intent(): void {
        $service = $this->build_service_with_placeholders(false);

        $fallback = $this->invoke_fallback($service, [
            'response_type' => 'confirm_pending',
            'next_step_intent' => 'Create Sprint 5 (Friday) booking option',
        ], 'Sprint 5 (Freitag) ist noch ausstehend.');

        $this->assertSame('clarification', $fallback['response_type']);
        $this->assertSame('Sprint 5 (Freitag) ist noch ausstehend.', $fallback['message']);
        $this->assertArrayNotHasKey('next_step_intent', $fallback);
    }

    /**
     * The runtime grants the planner flake exactly one framework retry with a hint that names
     * the valid repair (skill_call for the next planned step).
     */
    public function test_runtime_retries_confirm_pending_planned_steps_flake_once(): void {
        $reflection = new \ReflectionClass(agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS'],
            'commands' => [],
        ];

        $this->assertSame('CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS', $method->invoke($runtime, $result, []));
        $this->assertNull($method->invoke($runtime, $result, ['CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS' => 1]));

        $hintmethod = $reflection->getMethod('build_framework_retry_observation');
        $hintmethod->setAccessible(true);
        $hint = $hintmethod->invoke($runtime, 'CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS');
        $this->assertStringStartsWith('RETRY_HINT:', $hint);
        $this->assertStringContainsString('NO pending confirmation', $hint);
        $this->assertStringContainsString('skill_call', $hint);
    }

    /**
     * Build a decision service whose queue reports the given placeholder state.
     *
     * @param bool $hasplaceholders
     * @return agent_decision_service
     */
    private function build_service_with_placeholders(bool $hasplaceholders): agent_decision_service {
        $reflection = new \ReflectionClass(agent_decision_service::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $queue = $this->getMockBuilder(queue_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['has_planned_placeholders'])
            ->getMock();
        $queue->method('has_planned_placeholders')->willReturn($hasplaceholders);

        $property = $reflection->getProperty('queuesvc');
        $property->setAccessible(true);
        $property->setValue($service, $queue);

        return $service;
    }

    /**
     * Invoke the private no-intent fallback with a non-placeholder model message.
     *
     * @param agent_decision_service $service
     * @param array $result
     * @param string $modelmessage
     * @return array
     */
    private function invoke_fallback(agent_decision_service $service, array $result, string $modelmessage): array {
        $method = new \ReflectionMethod($service, 'build_confirm_pending_no_intent_fallback');
        $method->setAccessible(true);
        return $method->invoke($service, $result, $modelmessage, false, 'de', 558);
    }
}
