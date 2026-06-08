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

/** "mach weiter" after event creation -> next downstream skill, not create again. @package bookingextension_agent */
class short_confirm_weiter extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'short_confirm_weiter';
    }
    public function get_class(): string {
        return 'multistep';
    }
    public function get_description(): string {
        return '"mach weiter" selects next pending skill (not create_option again)';
    }
    public function get_user_message(): string {
        return 'mach weiter';
    }

    public function get_prior_messages(): array {
        return [
            ['role' => 'user', 'content' => 'Erstelle EventA und EventB, dann buche User1 fuer EventA.'],
            ['role' => 'assistant', 'content' => "Beide Veranstaltungen wurden erstellt.\n\nNoch ausstehend: User1 fuer EventA buchen."],
        ];
    }

    public function get_expected_response_type(): string {
        return 'skill_call';
    }
    public function get_expected_skill(): string {
        return 'mod_booking.book_users';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.book_users","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Book User1 for EventA","used_triggers":[],"lang":"de","user_lang":"de"}';
    }
}
