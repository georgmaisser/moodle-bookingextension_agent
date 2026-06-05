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

declare(strict_types=1);
namespace bookingextension_agent\local\wbagent\benchmark\scenarios;
use bookingextension_agent\local\wbagent\benchmark\abstract_benchmark_scenario;

/**
 * Scenario: create a simple dated booking option.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_basic extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'create_option_basic';
    }
    public function get_class(): string {
        return 'mutation_r1';
    }
    public function get_description(): string {
        return 'Single create_option with date and title';
    }
    public function get_user_message(): string {
        return 'Erstelle eine Veranstaltung "Jahresmeeting" am naechsten Montag von 9 bis 11 Uhr, maximal 20 Teilnehmer.';
    }
    public function get_expected_response_type(): string {
        return 'task_call';
    }
    public function get_expected_task(): string {
        return 'mod_booking.create_option';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"task_call","commands":[{"task":"mod_booking.create_option","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Create Jahresmeeting","used_triggers":[],"lang":"de","user_lang":"de"}';
    }

    public function assert_additional(array $result): array {
        return [
            [
                'label'  => 'planned_steps is empty array for single-step',
                'passed' => isset($result['planned_steps']) && $result['planned_steps'] === [],
                'detail' => 'planned_steps: ' . json_encode($result['planned_steps'] ?? 'missing'),
            ],
        ];
    }
}
