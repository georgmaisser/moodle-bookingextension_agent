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
class clarification_missing_date extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'clarification_missing_date';
    }
    public function get_class(): string {
        return 'clarification';
    }
    public function get_description(): string {
        return 'Missing required date/time -> clarification';
    }
    public function get_user_message(): string {
        return 'Erstelle eine Veranstaltung "Workshop Basics".';
    }
    public function get_expected_response_type(): string {
        return 'clarification';
    }
    public function get_expected_task(): string {
        return '';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"clarification","message":"Bitte nenne Datum und Uhrzeit fuer die Veranstaltung.",'
            . '"commands":[],"planned_steps":[],"next_step_intent":"","used_triggers":[],"lang":"de","user_lang":"de"}';
    }

    public function assert_additional(array $result): array {
        return [
            [
                'label'  => 'commands is empty for clarification',
                'passed' => empty($result['commands']),
                'detail' => 'commands: ' . json_encode($result['commands'] ?? []),
            ],
            [
                'label'  => 'non-empty clarification message',
                'passed' => strlen(trim((string)($result['message'] ?? ''))) > 5,
                'detail' => 'message length: ' . strlen($result['message'] ?? ''),
            ],
        ];
    }
}
