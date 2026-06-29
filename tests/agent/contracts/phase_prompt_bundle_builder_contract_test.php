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

use advanced_testcase;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Contracts for phase-local output prompt constraints.
 *
 * @covers \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phase_prompt_bundle_builder_contract_test extends advanced_testcase {
    /**
     * Selection output contract must require a single selector command.
     */
    public function test_selection_output_contract_requires_single_selector_command(): void {
        $builder = $this->build_builder();

        $contract = $this->invoke_private_method($builder, 'build_output_contract_block', [
            orchestrator_prompt_profile_service::PHASE_SELECTION,
        ]);
        $this->assertStringContainsString(
            'Allowed response_type: skill_call, clarification, confirm_pending, sufficient, error.',
            $contract
        );
        $this->assertStringContainsString(
            'For skill_call: commands must contain exactly one command object that selects exactly one skill',
            $contract
        );
        $this->assertStringContainsString(
            'Selection command input must be omitted or {}: no field-level construction, no inferred defaults.',
            $contract
        );
        $this->assertStringContainsString(
            'This phase is a tool-selector call: it chooses exactly one skill, and construction handles parameters.',
            $contract
        );
    }

    /**
     * Construction output contract must enforce exactly one command for command-bearing responses.
     */
    public function test_construction_output_contract_requires_one_or_more_commands(): void {
        $builder = $this->build_builder();

        $contract = $this->invoke_private_method($builder, 'build_output_contract_block', [
            orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION,
        ]);

        $expected = 'For skill_call/confirmation_request: '
            . 'commands must contain one or more command objects.';
        $this->assertStringContainsString($expected, $contract);
    }

    /**
     * Full schema payload (field-level construction) must be restricted to the construction phase.
     *
     * In production this is enforced via the output-contract block, not by a separate
     * schema-injection method. The selection phase explicitly forbids any field-level
     * parameter construction (input must be omitted or {}), while the construction phase
     * enables full constructor semantics with a complete parameter payload.
     */
    public function test_full_schema_payload_is_construction_only(): void {
        $builder = $this->build_builder();

        $selectioncontract = $this->invoke_private_method($builder, 'build_output_contract_block', [
            orchestrator_prompt_profile_service::PHASE_SELECTION,
        ]);
        $constructioncontract = $this->invoke_private_method($builder, 'build_output_contract_block', [
            orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION,
        ]);

        // Selection phase: field-level construction is explicitly prohibited.
        $this->assertStringContainsString(
            'Selection command input must be omitted or {}: no field-level construction, no inferred defaults.',
            $selectioncontract
        );

        // Construction phase: full parameter payload is expected.
        $this->assertStringContainsString(
            'Apply constructor semantics only; do not perform routing in this phase.',
            $constructioncontract
        );

        // The constructor-semantics instruction must NOT appear in the selection phase.
        $this->assertStringNotContainsString(
            'Apply constructor semantics only',
            $selectioncontract,
            'Selection phase must not apply constructor semantics.'
        );
    }

    /**
     * The cached output contract carries no volatile auto-confirm guidance; that lives in the bottom
     * reminder near [ASSISTANT], which also points back to the cached contract.
     */
    public function test_autoconfirm_guidance_is_in_the_volatile_reminder_not_the_cached_contract(): void {
        $builder = $this->build_builder();
        $phase = orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION;

        $contract = $this->invoke_private_method($builder, 'build_output_contract_block', [$phase]);
        $reminderon = $this->invoke_private_method($builder, 'build_output_contract_reminder', [$phase, true]);
        $reminderoff = $this->invoke_private_method($builder, 'build_output_contract_reminder', [$phase, false]);

        // Cached contract stays autoconfirm-invariant (so it caches regardless of autoconfirm state).
        $this->assertStringNotContainsString('Auto-confirm mode is active.', $contract);

        // Volatile reminder carries the autoconfirm guidance only when active, plus the 1-line pointer.
        $this->assertStringContainsString('Auto-confirm mode is active.', $reminderon);
        $this->assertStringNotContainsString('Auto-confirm mode is active.', $reminderoff);
        $this->assertStringContainsString('[OUTPUT_CONTRACT] above', $reminderoff);
    }


    /**
     * Build phase prompt bundle builder with minimal dependencies.
     *
     * @return phase_prompt_bundle_builder
     */
    private function build_builder(): phase_prompt_bundle_builder {
        $registry = $this->createMock(skill_registry::class);
        $profilesvc = new orchestrator_prompt_profile_service();

        return new phase_prompt_bundle_builder($registry, $profilesvc);
    }

    /**
     * Invoke a private helper method through reflection.
     *
     * @param object $instance
     * @param string $method
     * @param mixed[] $args
     * @return mixed
     */
    private function invoke_private_method(object $instance, string $method, array $args) {
        $reflection = new \ReflectionClass($instance);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($instance, $args);
    }
}
