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

use bookingextension_agent\local\wizard\skill_contract_validator;

/**
 * Every governance denial has a text from the language packs.
 *
 * Marketplace review MMR-403 (Wunderbyte-GmbH/moodle-local_wizard#14, reported before in #7): a denial without a
 * text of its own fell back to an English sentence built in three places (interpreter, executor, skill selector).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\skill_contract_validator
 */
final class governance_denial_text_is_translated_test extends \advanced_testcase {
    /**
     * Every deny reason of the validator, and an unknown one, yields a text.
     */
    public function test_every_deny_reason_has_a_text(): void {
        $reflection = new \ReflectionClass(skill_contract_validator::class);
        $reasons = ['not_allowed'];
        foreach ($reflection->getConstants() as $name => $value) {
            if (strpos($name, 'DENY_') === 0) {
                $reasons[] = (string)$value;
            }
        }
        $this->assertGreaterThan(5, count($reasons));
        foreach ($reasons as $reason) {
            $text = skill_contract_validator::get_user_facing_deny_message($reason, 'mod_booking.search_options');
            $this->assertIsString($text, $reason);
            $this->assertNotSame('', trim($text), $reason);
            $this->assertStringNotContainsString('[[', $text, $reason);
        }
        $generic = skill_contract_validator::get_user_facing_deny_message('context_invalid', 'mod_booking.search_options');
        $this->assertStringContainsString('mod_booking.search_options', $generic);
        $this->assertStringContainsString('context_invalid', $generic);
    }

    /**
     * The generic text exists in every shipped language pack.
     */
    public function test_the_generic_text_is_in_every_language_pack(): void {
        $dir = \core_component::get_component_directory('bookingextension_agent') . '/lang';
        foreach (glob($dir . '/*/bookingextension_agent.php') as $file) {
            $string = [];
            include($file);
            $this->assertArrayHasKey('agent_skill_denied_generic', $string, $file);
        }
    }

    /**
     * No class builds the denial sentence itself any more.
     */
    public function test_no_class_builds_the_denial_sentence(): void {
        $dir = \core_component::get_component_directory('bookingextension_agent') . '/classes';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $this->assertStringNotContainsString(
                'denied by governance gate',
                (string)file_get_contents($file->getPathname()),
                $file->getPathname()
            );
        }
    }
}
