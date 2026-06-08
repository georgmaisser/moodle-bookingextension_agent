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
 * Scenario: transient preflight error followed by recovery.
 * Selector must still produce the correct skill; retry handling is infrastructure-level.
 * @package bookingextension_agent
 */
class retry_preflight_recovery extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'retry_preflight_recovery';
    }
    public function get_class(): string {
        return 'error_retry';
    }
    public function get_description(): string {
        return 'Selector picks mutation skill after simulated preflight transient error context';
    }
    public function get_user_message(): string {
        return 'Buche Peter Mayer fuer den Kurs "Notfallkurs", der Kurs hatte gestern einen Fehler.';
    }
    public function get_expected_response_type(): string {
        return 'skill_call';
    }
    public function get_expected_skill(): string {
        return 'mod_booking.book_users';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.book_users","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Book Peter Mayer for Notfallkurs",'
            . '"used_triggers":["mod_booking.book_users_for_option"],"lang":"de","user_lang":"de"}';
    }
    public function assert_additional(array $result): array {
        return [
            [
                'label'  => 'Correct skill selected despite error context in message',
                'passed' => ($result['commands'][0]['skill'] ?? '') === 'mod_booking.book_users',
                'detail' => 'skill: ' . ($result['commands'][0]['skill'] ?? 'none'),
            ],
        ];
    }
}
