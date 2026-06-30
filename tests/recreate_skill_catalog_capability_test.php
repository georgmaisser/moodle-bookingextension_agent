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
use context_system;
use bookingextension_agent\local\wizard\services\security\native_capability_guard;
use bookingextension_agent\local\wizard\wizard\skills\recreate_skill_catalog_skill;

/**
 * Capability-fidelity tests for wizard.recreate_skill_catalog (audit CAP-03).
 *
 * The global, cost-bearing embeddings rebuild must require moodle/site:config (Gate 2), so a
 * teacher cannot trigger it; only site:config holders (admins) may execute it.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\recreate_skill_catalog_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recreate_skill_catalog_capability_test extends advanced_testcase {
    /**
     * The skill declares moodle/site:config as a native capability (Gate 2 is no longer a no-op).
     */
    public function test_declares_site_config_native_capability(): void {
        $this->assertSame(
            ['moodle/site:config'],
            (new recreate_skill_catalog_skill())->get_required_native_capabilities()
        );
    }

    /**
     * A teacher lacks site:config, so Gate 2 reports it missing (rebuild denied).
     */
    public function test_teacher_is_denied_by_gate2(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $missing = native_capability_guard::missing_capabilities(
            new recreate_skill_catalog_skill(),
            (int)context_system::instance()->id,
            (int)$teacher->id
        );

        $this->assertContains(
            'moodle/site:config',
            $missing,
            'A teacher must not be able to trigger the global catalog rebuild.'
        );
    }

    /**
     * A site admin holds site:config, so Gate 2 is satisfied.
     */
    public function test_admin_passes_gate2(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $missing = native_capability_guard::missing_capabilities(
            new recreate_skill_catalog_skill(),
            (int)context_system::instance()->id,
            (int)get_admin()->id
        );

        $this->assertSame([], $missing, 'An admin must be able to run the rebuild.');
    }
}
