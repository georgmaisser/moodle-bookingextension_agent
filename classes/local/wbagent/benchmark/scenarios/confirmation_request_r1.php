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
 * Scenario: "delete all bookings for a course" — no such task exists, agent must return error.
 *
 * There is no bulk-cancel-all-bookings task in the catalog. The correct selector
 * response is error, not task_call. This verifies the agent does not hallucinate a task.
 *
 * @package bookingextension_agent
 */
class confirmation_request_r1 extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'confirmation_request_r1';
    }
    public function get_class(): string {
        return 'catalog_gap';
    }
    public function get_description(): string {
        return 'No bulk-cancel task in catalog — agent must return error, not hallucinate a task_call';
    }
    public function get_user_message(): string {
        return 'Loesche alle Buchungen fuer den Kurs "Yoga Intensiv".';
    }
    public function get_expected_response_type(): string {
        return 'error';
    }
    public function get_expected_task(): string {
        return '';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"error","commands":[],'
            . '"planned_steps":[],"next_step_intent":"",'
            . '"message":"Das Loeschen aller Buchungen fuer einen Kurs ist mit den aktuellen Aufgaben nicht moeglich.",'
            . '"used_triggers":[],"lang":"de","user_lang":"de"}';
    }
}
