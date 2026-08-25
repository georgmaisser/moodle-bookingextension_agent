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
 * Declarative person-centric read-only skill attribute (#2226 R3 foundation).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\core\skills\search_users_skill;
use bookingextension_agent\local\wizard\course\skills\enrol_user_skill;

/**
 * The anonymizer collision gate (#2226 R3) fires only for skills that declare
 * themselves person-centric AND read-only via the duck-typed attribute
 * is_person_centric_readonly(). Diagnosis/search skills whose direct object is
 * a person declare true; explicit person mutations and non-person searches do
 * not declare it (or declare false). Structural attribute — no word lists.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\core\skills\search_users_skill
 */
final class person_centric_skill_attribute_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Whether a skill instance declares the attribute as true.
     *
     * @param object $skill
     * @return bool
     */
    private function declares(object $skill): bool {
        return method_exists($skill, 'is_person_centric_readonly')
            && (bool)$skill->is_person_centric_readonly();
    }

    /**
     * Person-diagnosis and person-search skills declare the attribute.
     */
    public function test_person_diagnosis_skills_declare_attribute(): void {
        $this->resetAfterTest();

        $this->assertTrue(
            $this->declares(new search_users_skill()),
            'core.search_users resolves persons and executes without confirmation — it must declare '
                . 'is_person_centric_readonly() (#2226 R3).'
        );
        $this->assertTrue(
            $this->declares(new \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill()),
            'mod_booking.diagnose_user_booking is the SO-4 detour skill and must declare the attribute.'
        );
        $this->assertTrue(
            $this->declares(new \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill()),
            'mod_booking.diagnose_cancellation_issue accepts a target person and must declare the attribute.'
        );

        $this->assertTrue(
            $this->declares(new \mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill()),
            'mod_booking.diagnose_waitinglist resolves free-text option words that anonymization may
             have masked as person tokens - it must declare the attribute.'
        );
        $this->assertTrue(
            $this->declares(new \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill()),
            'mod_booking.diagnose_booking_issue takes the same free-text queries and must declare the attribute.'
        );
    }

    /**
     * Person mutations and non-person searches do not declare the attribute.
     */
    public function test_mutations_and_nonperson_skills_do_not_declare(): void {
        $this->resetAfterTest();

        $this->assertFalse($this->declares(new enrol_user_skill()));
        $this->assertFalse($this->declares(new \mod_booking\local\wizard\options\skills\book_users_skill()));
        $this->assertFalse($this->declares(new \mod_booking\local\wizard\options\skills\update_option_trainer_skill()));
        $this->assertFalse($this->declares(new \mod_booking\local\wizard\options\skills\search_options_skill()));
    }
}
