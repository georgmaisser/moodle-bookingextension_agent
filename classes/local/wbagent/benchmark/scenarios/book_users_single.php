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

/** @package bookingextension_agent */
class book_users_single extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'book_users_single';
    }
    public function get_class(): string {
        return 'mutation_r1';
    }
    public function get_description(): string {
        return 'Book a single user into an existing option';
    }
    public function get_user_message(): string {
        return 'Buche Anna Berger fuer den Kurs "Erste Hilfe Grundkurs".';
    }
    public function get_expected_response_type(): string {
        return 'task_call';
    }
    public function get_expected_task(): string {
        return 'mod_booking.book_users';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"task_call","commands":[{"task":"mod_booking.book_users","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Book Anna Berger","used_triggers":["mod_booking.book_users_for_option"],'
            . '"lang":"de","user_lang":"de"}';
    }
}
