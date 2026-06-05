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

/** Selector must not hallucinate a task that is not in the catalog. @package bookingextension_agent */
class task_not_in_catalog extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'task_not_in_catalog_no_hallucination';
    }
    public function get_class(): string {
        return 'clarification';
    }
    public function get_description(): string {
        return 'Request for unavailable action -> clarification, no hallucinated task';
    }
    public function get_user_message(): string {
        return 'Erstelle einen Zoom-Link fuer den Kurs "Online Workshop".';
    }
    public function get_expected_response_type(): string {
        return 'clarification';
    }
    public function get_expected_task(): string {
        return '';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"clarification","message":"Das Erstellen von Zoom-Links ist nicht verfuegbar.",'
            . '"commands":[],"planned_steps":[],"next_step_intent":"","used_triggers":[],"lang":"de","user_lang":"de"}';
    }

    public function assert_additional(array $result): array {
        $task = $result['commands'][0]['task'] ?? '';
        return [
            [
                'label'  => 'No command emitted for unavailable action',
                'passed' => empty($result['commands']),
                'detail' => "commands: " . json_encode($result['commands'] ?? []),
            ],
            [
                'label'  => 'No hallucinated task name',
                'passed' => $task === '' || strpos($task, 'zoom') === false,
                'detail' => "task: {$task}",
            ],
        ];
    }
}
