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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\mcp\mcp_tool_catalog_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * Tests for the MCP tool catalog mapping.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\mcp\mcp_tool_catalog_service
 */
final class mcp_tool_catalog_test extends advanced_testcase {
    /**
     * Skip when the mod_booking host plugin is absent.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Build a catalog service instance.
     *
     * @return mcp_tool_catalog_service
     */
    private function make_catalog(): mcp_tool_catalog_service {
        $registry = skill_registry::make_default();
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        return new mcp_tool_catalog_service($registry, $evaluator);
    }

    /**
     * Create a course and an enrolled editing teacher.
     *
     * @return array [userid, contextid]
     */
    private function create_teacher_in_course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [(int)$teacher->id, (int)context_course::instance($course->id)->id];
    }

    /**
     * The tool name mapping is reversible and hides non-exposed skills.
     */
    public function test_tool_name_round_trip_and_exposure(): void {
        $this->resetAfterTest();
        $catalog = $this->make_catalog();

        $this->assertSame('course_search_courses', mcp_tool_catalog_service::tool_name_for('course.search_courses'));
        $this->assertSame('course.search_courses', $catalog->skill_for_tool_name('course_search_courses'));
        // The canonical dotted name resolves too.
        $this->assertSame('course.search_courses', $catalog->skill_for_tool_name('course.search_courses'));
        $this->assertNull($catalog->skill_for_tool_name('no_such_tool'));

        // PII/thread-coupled skills are hidden by default — including for direct calls.
        $this->assertNull($catalog->skill_for_tool_name('core_search_users'));
        $this->assertNull($catalog->skill_for_tool_name('wizard_remember'));
    }

    /**
     * Tool definitions carry a JSON-Schema input schema and MCP annotations.
     */
    public function test_tool_definition_shape(): void {
        $this->resetAfterTest();
        [$userid, $contextid] = $this->create_teacher_in_course();
        $catalog = $this->make_catalog();

        $tools = $catalog->get_tools($userid, $contextid);
        $bytoolname = array_column($tools, null, 'name');
        $this->assertArrayHasKey('course_search_courses', $bytoolname);

        $tool = $bytoolname['course_search_courses'];
        $this->assertNotEmpty($tool['description']);
        $this->assertSame('object', $tool['inputSchema']['type']);
        $this->assertFalse($tool['inputSchema']['additionalProperties']);
        $properties = (array)$tool['inputSchema']['properties'];
        $this->assertSame('string', $properties['query']['type']);
        $this->assertTrue($tool['annotations']['readOnlyHint']);
        $this->assertFalse($tool['annotations']['destructiveHint']);
        $this->assertSame('course.search_courses', $tool['annotations']['title']);

        // Default exposure policy: excluded skills never appear.
        $this->assertArrayNotHasKey('core_search_users', $bytoolname);
        $this->assertArrayNotHasKey('wizard_remember', $bytoolname);
    }

    /**
     * The executability evaluator filters the list per user.
     */
    public function test_catalog_is_filtered_by_executability(): void {
        $this->resetAfterTest();
        [, $contextid] = $this->create_teacher_in_course();
        $student = $this->getDataGenerator()->create_user();
        $catalog = $this->make_catalog();

        // A user without the skill capabilities sees no tools at all.
        $tools = $catalog->get_tools((int)$student->id, $contextid);
        $this->assertSame([], $tools);
    }

    /**
     * An explicit mcpexposedskills allowlist overrides the default policy.
     */
    public function test_explicit_allowlist_is_authoritative(): void {
        $this->resetAfterTest();
        [$userid, $contextid] = $this->create_teacher_in_course();
        set_config('mcpexposedskills', 'course.search_courses', 'bookingextension_agent');
        $catalog = $this->make_catalog();

        $tools = $catalog->get_tools($userid, $contextid);
        $this->assertSame(['course_search_courses'], array_column($tools, 'name'));
        $this->assertNull($catalog->skill_for_tool_name('course_analyze_course_structure'));
    }
}
