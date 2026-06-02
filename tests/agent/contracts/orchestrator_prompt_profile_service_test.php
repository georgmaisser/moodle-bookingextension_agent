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

use bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service;
use advanced_testcase;

/**
 * Tests for phase-based planner prompt-profile helpers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class orchestrator_prompt_profile_service_test extends advanced_testcase {
    /**
     * Verifies that phase-based prompt-profile keys remain stable.
     *
     * @covers \bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service::get_planner_initial_prompt_config_key_for_phase
     * @covers \bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service::get_history_limit_for_phase
     */
    public function test_phase_profiles_use_expected_config_keys(): void {
        $service = new orchestrator_prompt_profile_service();

        $this->assertSame(
            'aiinitialprompt_discovery',
            $service->get_planner_initial_prompt_config_key_for_phase('discovery')
        );
        $this->assertSame(
            'aiinitialprompt_selection',
            $service->get_planner_initial_prompt_config_key_for_phase('selection')
        );
        $this->assertSame(
            'aiinitialprompt_parameter_construction',
            $service->get_planner_initial_prompt_config_key_for_phase('parameter_construction')
        );
        $this->assertSame(PHP_INT_MAX, $service->get_history_limit_for_phase('discovery'));
        $this->assertSame(PHP_INT_MAX, $service->get_history_limit_for_phase('selection'));
        $this->assertSame(PHP_INT_MAX, $service->get_history_limit_for_phase('parameter_construction'));
    }
}
