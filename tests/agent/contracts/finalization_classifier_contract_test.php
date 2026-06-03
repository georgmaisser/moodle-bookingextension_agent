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

use bookingextension_agent\local\wbagent\services\finalization_classifier;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for deterministic finalization classifier.
 *
 * @covers \bookingextension_agent\local\wbagent\services\finalization_classifier
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class finalization_classifier_contract_test extends TestCase {
    /**
     * Commands always force direct finalization.
     */
    public function test_classifies_has_commands_as_direct_final(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'sufficient',
            'commands' => [['task' => 'booking.list_options', 'input' => []]],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }

    /**
     * Confirmation-related response types always stay deterministic.
     */
    public function test_classifies_confirmation_request_as_direct_final(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'confirmation_request',
            'commands' => [],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }

    /**
     * Structural issue codes must not enter LLM polish.
     */
    public function test_classifies_structural_issue_code_as_direct_final(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'issue_codes' => ['SCHEMA_ERROR'],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }

    /**
     * Budget and technical-safe errors should use template-only path.
     */
    public function test_classifies_budget_exceeded_as_template_only(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'issue_codes' => ['BUDGET_EXCEEDED'],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_TEMPLATE_ONLY, $strategy);
    }

    /**
     * Provider transient failures are template-only by default.
     */
    public function test_classifies_provider_timeout_error_class_as_template_only(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'error_class' => 'provider_timeout',
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_TEMPLATE_ONLY, $strategy);
    }

    /**
     * Sufficient and clarification are polish candidates.
     */
    public function test_classifies_sufficient_as_llm_polish(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'sufficient',
            'commands' => [],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_LLM_POLISH, $strategy);
    }

    /**
     * Non-structural domain errors may be humanized.
     */
    public function test_classifies_non_structural_error_as_llm_polish(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'issue_codes' => ['DOMAIN_CONFLICT'],
            'structural_failure' => false,
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_LLM_POLISH, $strategy);
    }

    /**
     * Structural flag wins over generic error handling.
     */
    public function test_classifies_structural_flag_error_as_direct_final(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'structural_failure' => true,
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }

    /**
     * Direct rules have precedence over template-only rules.
     */
    public function test_direct_precedence_over_template_when_commands_present(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [['task' => 'booking.list_options', 'input' => []]],
            'issue_codes' => ['BUDGET_EXCEEDED'],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }

    /**
     * Structural issue code must keep direct finalization even with template issue.
     */
    public function test_direct_precedence_for_structural_issue_code(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'issue_codes' => ['SCHEMA_ERROR', 'BUDGET_EXCEEDED'],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }

    /**
     * Phase contract errors must not flow into LLM polish.
     */
    public function test_classifies_phase_contract_issue_as_direct_final(): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands' => [],
            'issue_codes' => ['CONTRACT_PHASE_SINGLE_COMMAND_REQUIRED'],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_DIRECT_FINAL, $strategy);
    }
}
