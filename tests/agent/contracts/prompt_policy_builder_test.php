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

use bookingextension_agent\local\wbagent\prompt_policy_builder;
use advanced_testcase;

/**
 * Tests for planner policy extraction and final synthesis separation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_policy_builder_test extends advanced_testcase {
    /**
     * Verifies that planner policies stay free of final-synthesis text.
     *
     * @covers \bookingextension_agent\local\wbagent\prompt_policy_builder::build_planner_policies
     */
    public function test_planner_policies_do_not_contain_final_synthesis_policy(): void {
        $plannerpolicies = prompt_policy_builder::build_planner_policies('final_synthesis', false, false);

        $this->assertStringNotContainsString('SYNTHESIS RESPONSE POLICY', $plannerpolicies);
        $this->assertStringContainsString('NON-OPTIONAL SUFFICIENCY POLICY', $plannerpolicies);
    }
}
