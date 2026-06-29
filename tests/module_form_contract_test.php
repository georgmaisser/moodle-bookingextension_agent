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
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use context_course;

/**
 * Pins the headless mod_form contract for each whitelisted module (the documented brittleness).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\activities\module_form_contract
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_form_contract_test extends advanced_testcase {
    /**
     * Minimal valid inputs per whitelist module.
     *
     * @return array
     */
    public static function valid_inputs_provider(): array {
        return [
            'page' => ['page', 'A page', 'Some intro', ['content' => 'The page body.']],
            'url' => ['url', 'A link', '', ['externalurl' => 'https://moodle.org']],
            'label' => ['label', '', 'The label text.', []],
            'book' => ['book', 'A book', 'Book intro', []],
            'folder' => ['folder', 'A folder', 'Folder intro', []],
            'forum' => ['forum', 'A forum', 'Forum intro', []],
        ];
    }

    /**
     * Each whitelisted module's form builds headless and validates with minimal valid inputs.
     *
     * @dataProvider valid_inputs_provider
     * @param string $modname
     * @param string $name
     * @param string $intro
     * @param array $settings
     */
    public function test_form_builds_and_validates(string $modname, string $name, string $intro, array $settings): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance($course->id));

        $contract = new module_form_contract();
        $result = $contract->validate($course, $modname, 0, $name, $intro, $settings);

        $this->assertTrue($result['built'], "mod_form for {$modname} must build headless.");
        $this->assertTrue(
            $result['ok'],
            "Minimal valid input for {$modname} should validate; errors: " . json_encode($result['errors'])
        );

        // The prepared moduleinfo is complete enough for add_moduleinfo().
        $moduleinfo = $contract->build_prepared_moduleinfo($course, $modname, 0, $name, $intro, $settings);
        $this->assertSame($modname, $moduleinfo->modulename);
        $this->assertSame(0, (int)$moduleinfo->section);
    }

    /**
     * A URL without externalurl is reported as a real, missing required field.
     */
    public function test_required_field_detected(): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance($course->id));

        $result = (new module_form_contract())->validate($course, 'url', 0, 'A link', '', []);
        $this->assertTrue($result['built']);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('externalurl', $result['errors']);
    }
}
