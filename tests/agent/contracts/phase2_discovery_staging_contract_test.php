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

namespace bookingextension_agent\local\wbagent\tests;

use bookingextension_agent\local\wbagent\services\discovery\discovery_budget_policy;
use bookingextension_agent\local\wbagent\services\discovery\discovery_confidence_policy;
use bookingextension_agent\local\wbagent\services\discovery\discovery_stage_controller;
use bookingextension_agent\local\wbagent\services\discovery\family_ranker;
use bookingextension_agent\local\wbagent\services\discovery\family_signal_ranker;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for phase-2 staged discovery policies and escalation.
 *
 * @covers \bookingextension_agent\local\wbagent\services\discovery\discovery_budget_policy
 * @covers \bookingextension_agent\local\wbagent\services\discovery\discovery_confidence_policy
 * @covers \bookingextension_agent\local\wbagent\services\discovery\family_signal_ranker
 * @covers \bookingextension_agent\local\wbagent\services\discovery\family_ranker
 * @covers \bookingextension_agent\local\wbagent\services\discovery\discovery_stage_controller
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phase2_discovery_staging_contract_test extends TestCase {
    /**
     * Budget policy must enforce monotonic stage budgets.
     */
    public function test_budget_policy_stage_caps_are_monotonic(): void {
        $policy = new discovery_budget_policy();

        $this->assertGreaterThan(0, $policy->get_stage_budget('A'));
        $this->assertGreaterThan($policy->get_stage_budget('A'), $policy->get_stage_budget('B'));
        $this->assertGreaterThan($policy->get_stage_budget('B'), $policy->get_stage_budget('C'));
    }

    /**
     * Confidence policy must normalize and threshold scores deterministically.
     */
    public function test_confidence_policy_thresholds(): void {
        $policy = new discovery_confidence_policy();

        $this->assertFalse($policy->is_sufficient(0.30, 'A'));
        $this->assertTrue($policy->is_sufficient(0.65, 'A'));
        $this->assertFalse($policy->is_sufficient(0.30, 'B'));
        $this->assertTrue($policy->is_sufficient(0.50, 'B'));
        $this->assertSame(1.0, $policy->normalize_score(1.5));
        $this->assertSame(0.0, $policy->normalize_score(-1.0));
    }

    /**
     * Signal ranker must prefer namespace-hinted families.
     */
    public function test_signal_ranker_prefers_namespace_hint(): void {
        $ranker = new family_signal_ranker();
        $scores = $ranker->score_families(
            ['mod_booking.options', 'core.general', 'local_entities.general'],
            ['namespace_hint' => 'mod_booking'],
            []
        );

        $this->assertGreaterThan($scores['core.general'], $scores['mod_booking.options']);
        $this->assertGreaterThan($scores['local_entities.general'], $scores['mod_booking.options']);
    }

    /**
     * Stage controller should stay in A when confidence is sufficient.
     */
    public function test_stage_controller_stays_in_a_when_confident(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'core.general'],
            ['mod_booking.options' => 0.80, 'core.general' => 0.30],
            []
        );

        $result = (new discovery_stage_controller())->resolve($ranked, ['mod_booking.options'], ['core.general']);

        $this->assertSame('A', $result['discovery_stage']);
        $this->assertSame('none', $result['escalation_reason']);
        $this->assertGreaterThanOrEqual(0.60, (float)$result['confidence_score']);
    }

    /**
     * Stage controller should escalate to C when A/B confidence is insufficient.
     */
    public function test_stage_controller_escalates_to_c_when_confidence_is_low(): void {
        $ranked = (new family_ranker())->rank(
            ['mod_booking.options', 'core.general', 'local_entities.general'],
            ['mod_booking.options' => 0.20, 'core.general' => 0.15, 'local_entities.general' => 0.10],
            []
        );

        $result = (new discovery_stage_controller())->resolve($ranked, ['mod_booking.options'], ['core.general']);

        $this->assertSame('C', $result['discovery_stage']);
        $this->assertSame('stage_b_low_confidence', $result['escalation_reason']);
        $this->assertIsArray($result['selected_families']);
        $this->assertNotEmpty($result['selected_families']);
    }
}
