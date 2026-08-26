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
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use PHPUnit\Framework\TestCase;

/**
 * Continuation-truth contract (thread 558): the synchronizer must KNOW, from engine state,
 * whether anything runs after its reply — never guess or promise automatic follow-up.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_prompt_builder
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder
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
     * Concrete values may only come from observations — never reconstructed from the wish
     * (a wrong stored date must be reported, not soothingly replaced by the request's date).
     */
    public function test_prompt_contract_forbids_reconstructing_values_from_the_request(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt('SYSTEM PROMPT', [], ['some observation']);

        $this->assertStringContainsString('NEVER reconstruct them from the user', $prompt);
    }

    /**
     * Without active anonymizer tokens the prompt carries no token policy.
     */
    public function test_prompt_contract_has_no_anon_token_policy_by_default(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt('SYSTEM PROMPT', [], ['some observation']);

        $this->assertStringNotContainsString('ANON_TOKEN_POLICY', $prompt);
    }

    /**
     * With active tokens the policy forbids treating token-vs-cleartext differences as
     * discrepancies — the fake "Title Discrepancy" with destructive suggestions must be
     * impossible.
     */
    public function test_prompt_contract_injects_anon_token_policy_from_engine_state(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt(
            'SYSTEM PROMPT',
            [],
            ['some observation'],
            '',
            '',
            synchronizer_prompt_builder::CONTINUATION_NONE,
            [],
            ['ANON_USER_1_lastname', ' ANON_USER_1_lastname ', '']
        );

        $this->assertStringContainsString('[ANON_TOKEN_POLICY]', $prompt);
        $this->assertStringContainsString('ANON_USER_1_lastname', $prompt);
        $this->assertStringContainsString('NEVER report a difference', $prompt);
        $this->assertSame(1, substr_count($prompt, 'ANON_USER_1_lastname'), 'token listed exactly once in the policy');
        $this->assertGreaterThan(strpos($prompt, '[OUTPUT_CONTRACT]'), strpos($prompt, '[ANON_TOKEN_POLICY]'));
    }

    /**
     * A clarification turn adds the question-turn policy: nothing was executed, nothing runs —
     * the reply is a question (fabricated "wurde erfolgreich erstellt" must be impossible).
     */
    public function test_prompt_contract_awaiting_answer_adds_question_policy(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt(
            'SYSTEM PROMPT',
            [],
            ['some observation'],
            '',
            '',
            'awaiting_answer'
        );

        $this->assertStringContainsString('QUESTION TURN POLICY', $prompt);
        $this->assertStringContainsString('NOTHING was executed', $prompt);
        $this->assertStringContainsString('TURN END POLICY', $prompt);
        $this->assertStringContainsString(
            'carries NO confirmation button',
            $prompt,
            'a question turn must never be worded as a confirmation request (F30)'
        );

        $default = $builder->build_prompt('SYSTEM PROMPT', [], ['some observation']);
        $this->assertStringNotContainsString('QUESTION TURN POLICY', $default);
        $this->assertStringNotContainsString('carries NO confirmation button', $default);
    }

    /**
     * The continuation state is derived from the final response_type in ONE place.
     */
    public function test_continuation_mapping_is_deterministic(): void {
        $this->assertSame(
            synchronizer_prompt_builder::CONTINUATION_AWAITING_CONFIRMATION,
            synchronizer_prompt_builder::continuation_for_response_type('confirmation_request')
        );
        $this->assertSame(
            'awaiting_answer',
            synchronizer_prompt_builder::continuation_for_response_type('clarification')
        );
        $this->assertSame(
            synchronizer_prompt_builder::CONTINUATION_NONE,
            synchronizer_prompt_builder::continuation_for_response_type('sufficient')
        );
        $this->assertSame(
            synchronizer_prompt_builder::CONTINUATION_NONE,
            synchronizer_prompt_builder::continuation_for_response_type('error')
        );
    }

    /**
     * Error causes are enumerated one per line — a pipe-joined single line let the relay swap
     * attributions between independent errors.
     */
    public function test_error_observation_enumerates_causes_separately(): void {
        $builder = new synchronizer_input_builder();

        $observations = $builder->build_observations([
            'response_type' => 'error',
            'errors' => [
                'No user matched miriam@firma.at.',
                'Multiple users matched Peter: Peter Alt, Peter Neu.',
            ],
        ]);

        $errorobservation = '';
        foreach ($observations as $observation) {
            if (str_contains((string)$observation, '[ERROR]')) {
                $errorobservation = (string)$observation;
            }
        }
        $this->assertNotSame('', $errorobservation);
        $this->assertStringContainsString('cause 1: No user matched miriam@firma.at.', $errorobservation);
        $this->assertStringContainsString('cause 2: Multiple users matched Peter: Peter Alt, Peter Neu.', $errorobservation);
        $this->assertStringNotContainsString(' | ', $errorobservation);
    }

    /**
     * Omitted-fields truth: the policy block exists ONLY when the engine collected fields a read
     * skill did not look up. A turn without such fields must carry no trace of it, so the cached
     * prompt prefix and the default contract stay unchanged.
     */
    public function test_prompt_contract_has_no_omitted_fields_policy_by_default(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt('SYSTEM PROMPT', [], ['some observation']);

        $this->assertStringNotContainsString('OMITTED_FIELDS_POLICY', $prompt);
    }

    /**
     * With omitted fields the block names them and forbids presenting them as absent — the
     * "no seat limit defined" answer next to a card reading "0 / 12" must be impossible.
     */
    public function test_prompt_contract_injects_omitted_fields_policy_from_engine_state(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt(
            'SYSTEM PROMPT',
            [],
            ['some observation'],
            '',
            '',
            synchronizer_prompt_builder::CONTINUATION_NONE,
            ['availability', 'imageurl', ' availability ', '']
        );

        $this->assertStringContainsString('[OMITTED_FIELDS_POLICY]', $prompt);
        $this->assertStringContainsString('availability, imageurl', $prompt);
        $this->assertStringContainsString('NEVER state or imply that any of these fields is missing', $prompt);
        // Duplicates and blanks are normalised away; the field list appears exactly once.
        $this->assertSame(1, substr_count($prompt, 'availability, imageurl'));
        // The block sits in the volatile tail, after the shared output contract.
        $this->assertGreaterThan(strpos($prompt, '[OUTPUT_CONTRACT]'), strpos($prompt, '[OMITTED_FIELDS_POLICY]'));
    }

    /**
     * The condition is read from the structured result rows (loop steps and top-level rows),
     * de-duplicated across rows — never from observation text.
     */
    public function test_input_builder_collects_omitted_fields_from_result_rows(): void {
        $builder = new synchronizer_input_builder();

        $result = [
            'response_type' => 'sufficient',
            'loop_results' => [
                ['results' => [
                    ['status' => 'executed', 'detail_capabilities' => ['omitted_fields' => ['availability', 'imageurl']]],
                    ['status' => 'executed'],
                ]],
                ['results' => 'not-an-array'],
            ],
            'results' => [
                ['status' => 'executed', 'detail_capabilities' => ['omitted_fields' => ['imageurl', 'location']]],
            ],
        ];

        $this->assertSame(['availability', 'imageurl', 'location'], $builder->collect_omitted_fields($result));
        $this->assertSame([], $builder->collect_omitted_fields(['response_type' => 'sufficient']));
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
