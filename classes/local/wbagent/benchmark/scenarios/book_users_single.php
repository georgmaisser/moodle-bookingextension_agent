<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Scenario book_users_single.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wbagent\benchmark\scenarios;
use bookingextension_agent\local\wbagent\benchmark\abstract_benchmark_scenario;

/**
 * Scenario book_users_single.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_users_single extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'book_users_single';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'mutation_r1';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Book a single user into an existing option';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Buche Anna Berger fuer den Kurs "Erste Hilfe Grundkurs".';
    }
    /**
     * Get the expected response type.
     *
     * @return string
     */
    public function get_expected_response_type(): string {
        return 'skill_call';
    }
    /**
     * Get the expected skill.
     *
     * Empty on purpose: the strict first-command check moved to assert_additional,
     * which also accepts the legitimate find-then-book pattern (search_options
     * first with a planned follow-up step).
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return '';
    }

    /**
     * Get the stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.book_users","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Book Anna Berger","used_triggers":["mod_booking.book_users_for_option"],'
            . '"lang":"de","user_lang":"de"}';
    }

    /**
     * Perform additional assertions.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $skill = trim((string)($result['commands'][0]['skill'] ?? ''));
        $hasfollowup = !empty($result['planned_steps']) || trim((string)($result['next_step_intent'] ?? '')) !== '';
        $direct = $skill === 'mod_booking.book_users';
        $findthenbook = $skill === 'mod_booking.search_options' && $hasfollowup;

        return [
            [
                'label'  => 'Booking intent selected (book_users directly, or search_options with planned follow-up)',
                'passed' => $direct || $findthenbook,
                'detail' => "skill: {$skill}; followup: " . ($hasfollowup ? 'yes' : 'no'),
            ],
        ];
    }
}
