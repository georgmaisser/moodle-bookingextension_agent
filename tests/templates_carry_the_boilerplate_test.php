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

/**
 * Every Mustache template starts with the Moodle GPL boilerplate and names its template, package, copyright and license.
 *
 * Marketplace review MMR-403 (Wunderbyte-GmbH/moodle-local_wizard#15): 11 of 13 templates had no GPL block and two
 * had the block without the package, copyright and license tags.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class templates_carry_the_boilerplate_test extends \advanced_testcase {
    /**
     * The header of every template of the plugin is complete.
     */
    public function test_every_template_has_the_standard_header(): void {
        $component = 'bookingextension_agent';
        $dir = \core_component::get_component_directory($component) . '/templates';
        $files = glob($dir . '/*.mustache');
        $this->assertNotEmpty($files);
        $boilerplate = "{{!\n    This file is part of Moodle - http://moodle.org/\n";
        foreach ($files as $file) {
            $name = basename($file, '.mustache');
            $text = (string)file_get_contents($file);
            $this->assertStringStartsWith($boilerplate, $text, $name . ': GPL block first');
            $this->assertStringContainsString('GNU General Public License', $text, $name);
            $this->assertStringContainsString('along with Moodle.  If not, see <http://www.gnu.org/licenses/>.', $text, $name);
            $this->assertStringContainsString('@template ' . $component . '/' . $name, $text, $name);
            $this->assertMatchesRegularExpression('/@package\s+' . $component . '\b/', $text, $name);
            $this->assertMatchesRegularExpression('/@copyright\s+\d{4} /', $text, $name);
            $this->assertStringContainsString('http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later', $text, $name);
            $this->assertMatchesRegularExpression('/@license\s+http/', $text, $name);
        }
    }
}
