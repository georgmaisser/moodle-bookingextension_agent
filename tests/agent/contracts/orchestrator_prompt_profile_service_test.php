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
 * Tests for planner and runtime prompt-profile helpers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class orchestrator_prompt_profile_service_test extends advanced_testcase {
    /**
     * Verifies that runtime and planner normalization are intentionally separated.
     *
     * @covers \bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service::normalize_runtime_step_type
     * @covers \bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service::normalize_planner_step_type
     * @covers \bookingextension_agent\local\wbagent\services\orchestrator_prompt_profile_service::get_planner_initial_prompt_config_key
     */
    public function test_runtime_and_planner_profiles_are_separated(): void {
        $service = new orchestrator_prompt_profile_service(
            'tool_call_parse',
            'simple_retrieval',
            'wbplanner'
        );

        $this->assertSame('tool_call_parse', $service->normalize_runtime_step_type('final_synthesis'));
        $this->assertSame('tool_call_parse', $service->normalize_planner_step_type('final_synthesis'));
        $this->assertSame('aiinitialprompt_tool_call_parse', $service->get_planner_initial_prompt_config_key('final_synthesis'));
    }
}
