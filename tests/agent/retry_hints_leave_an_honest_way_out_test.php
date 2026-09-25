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

/**
 * Retry hints and the construction contract never push the model into inventing, and name what it needs.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\orchestrator;

/**
 * Wave-30 Nachlauf 31, paired raw-prompt analysis (engine text only - no user text is inspected):
 *  - UO-3 thread 11896: the structural-mismatch hint said "map the user's values" and the repair "optiondates needs
 *    a date range"; with no honest way out the model invented 18:00-20:00.
 *  - DMD-2 thread 11903: the choices hint said only "if clearly exactly one"; the model asked between "Antrag
 *    eröffnet" (onrequestcreated) and "Antrag geschlossen" for "request opened".
 *  - GQ-1 thread 11915: the re-plan hint "ask for a value only the user can give" licensed "which file?".
 *  - DMD-2 thread 11878: "skill_fits" stood only in a rule far above the output contract and was left out.
 *  - DWL-2 thread 11872: rule 20 "exactly the user's words" beat "no article"; "die Wanderung" missed the option.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\orchestrator
 */
final class retry_hints_leave_an_honest_way_out_test extends \advanced_testcase {
    /**
     * The retry hint for a code.
     *
     * @param string $code
     * @return string
     */
    private function hint(string $code): string {
        $method = new \ReflectionMethod(agent_runtime::class, 'build_framework_retry_observation');
        $method->setAccessible(true);
        $runtime = (new \ReflectionClass(agent_runtime::class))->newInstanceWithoutConstructor();
        return (string)$method->invoke($runtime, $code);
    }

    /**
     * A hint that asks for values always offers the honest way out and forbids inventing.
     */
    public function test_hints_that_ask_for_values_forbid_inventing(): void {
        foreach (['CONTRACT_STRUCTURAL_MISMATCH', 'CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION'] as $code) {
            $hint = $this->hint($code);
            $this->assertStringContainsString('response_type=clarification', $hint, $code);
            $this->assertStringContainsString('Never invent a value', $hint, $code);
        }
        $this->assertStringContainsString(
            'no field description says the skill resolves or asks for itself',
            $this->hint('CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION')
        );
    }

    /**
     * The choices hint says how to match: by meaning in any language and by the attributes, into the named field.
     */
    public function test_the_choices_hint_says_how_to_match(): void {
        $hint = $this->hint('PREFLIGHT_CHOICES_OFFERED');
        $this->assertStringContainsString('by meaning, in any language', $hint);
        $this->assertStringContainsString('by their attributes', $hint);
        $this->assertStringContainsString('CHOICES for', $hint);
        $this->assertStringContainsString('several fit equally', $hint);
    }

    /**
     * Rule 20 keeps "no article"; the unfit flag is part of the constructor's output contract.
     */
    public function test_rule_20_and_the_output_contract(): void {
        $template = orchestrator::get_default_constructor_prompt_template();
        $this->assertStringContainsString('(no article, as above)', $template);
        $this->assertStringNotContainsString("put exactly the user's words", $template);

        $builderclass = \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder::class;
        $builder = (new \ReflectionClass($builderclass))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($builderclass, 'build_output_contract_block');
        $method->setAccessible(true);
        $contract = (string)$method->invoke($builder, 'parameter_construction');
        $this->assertStringContainsString('"skill_fits": false', $contract);
    }
}
