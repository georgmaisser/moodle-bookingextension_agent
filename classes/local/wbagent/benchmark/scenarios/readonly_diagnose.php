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
class readonly_diagnose extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'readonly_diagnose_booking_issue';
    }
    public function get_class(): string {
        return 'readonly';
    }
    public function get_description(): string {
        return 'Read-only booking issue diagnosis';
    }
    public function get_user_message(): string {
        return 'Warum kann Max Mustermann sich nicht fuer den Kurs "Erste Hilfe" buchen?';
    }
    public function get_expected_response_type(): string {
        return 'skill_call';
    }
    public function get_expected_skill(): string {
        return 'mod_booking.diagnose_booking_issue';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.diagnose_booking_issue","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Diagnose booking issue","used_triggers":["mod_booking.diagnose_booking_issue_self_help"],'
            . '"lang":"de","user_lang":"de"}';
    }
}
