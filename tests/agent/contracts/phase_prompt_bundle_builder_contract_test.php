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
use bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wbagent\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Contracts for phase-local output prompt constraints.
 *
 * @covers \bookingextension_agent\local\wbagent\services\phase_prompt_bundle_builder
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

        $contract = $this->invoke_private_method($builder, 'build_local_output_contract_block', [
            orchestrator_prompt_profile_service::PHASE_SELECTION,
            false,
        ]);

        $this->assertStringContainsString('Allowed response_type: task_call, clarification, confirm_pending, sufficient, error.', $contract);
        $this->assertStringContainsString(
            'For task_call: commands must contain exactly one command object that selects exactly one task',
            $contract
        );
        $this->assertStringContainsString(
            'Selection command input must be omitted or {}: no field-level construction, no inferred defaults.',
            $contract
        );
        $this->assertStringContainsString(
            'This phase is a tool-selector call: it chooses exactly one task, and construction handles parameters.',
            $contract
        );
    }

    /**
     * Construction output contract must enforce exactly one command for command-bearing responses.
     */
    public function test_construction_output_contract_requires_exactly_one_command(): void {
        $builder = $this->build_builder();

        $contract = $this->invoke_private_method($builder, 'build_local_output_contract_block', [
            orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION,
            false,
        ]);

        $expected = 'For task_call/confirmation_request: '
            . 'commands must contain exactly one command object.';
        $this->assertStringContainsString($expected, $contract);
    }

    /**
     * Full schema payload must stay hidden outside construction phase.
     */
    public function test_full_schema_payload_is_construction_only(): void {
        $builder = $this->build_builder();

        $selectionpayload = $this->invoke_private_method($builder, 'build_full_schema_json_for_phase', [
            orchestrator_prompt_profile_service::PHASE_SELECTION,
            ['task.a' => ['properties' => ['id' => ['type' => 'integer']]]],
        ]);
        $constructionpayload = $this->invoke_private_method($builder, 'build_full_schema_json_for_phase', [
            orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION,
            ['task.a' => ['properties' => ['id' => ['type' => 'integer']]]],
        ]);

        $this->assertSame('{}', $selectionpayload);
        $this->assertStringContainsString('task.a', $constructionpayload);
    }

    /**
     * Build phase prompt bundle builder with minimal dependencies.
     *
     * @return phase_prompt_bundle_builder
     */
    private function build_builder(): phase_prompt_bundle_builder {
        $registry = $this->createMock(task_registry::class);
        $profilesvc = new orchestrator_prompt_profile_service();

        return new phase_prompt_bundle_builder($registry, $profilesvc);
    }

    /**
     * Invoke a private helper method through reflection.
     *
     * @param object $instance
     * @param string $method
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invoke_private_method(object $instance, string $method, array $args) {
        $reflection = new \ReflectionClass($instance);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($instance, $args);
    }
}
