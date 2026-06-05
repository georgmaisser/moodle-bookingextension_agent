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
 * Scenario: "ja" after agent listed pending steps — correct task must be selected.
 *
 * @package bookingextension_agent
 */
class short_confirm_ja extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'short_confirm_ja';
    }
    public function get_class(): string {
        return 'multistep';
    }
    public function get_description(): string {
        return '"ja" after agent listed pending trainer+booking steps — correct downstream task selected';
    }
    public function get_user_message(): string {
        return 'ja';
    }

    public function get_prior_messages(): array {
        return [
            ['role' => 'user', 'content' => 'Erstelle TestA am Dienstag, dann Trainer setzen und User buchen.'],
            ['role' => 'assistant', 'content' => "TestA wurde erstellt.\n\n## Noch ausstehend\n"
                . "1. Trainer fuer TestA setzen\n2. User fuer TestA buchen\n\nMoechtest du dass ich weitermache?"],
        ];
    }

    public function get_expected_response_type(): string {
        return 'task_call';
    }
    public function get_expected_task(): string {
        return 'mod_booking.update_option_trainer';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"task_call","commands":[{"task":"mod_booking.update_option_trainer","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Set trainer for TestA","used_triggers":[],"lang":"de","user_lang":"de"}';
    }

    public function assert_additional(array $result): array {
        $task = $result['commands'][0]['task'] ?? '';
        return [
            [
                'label'  => 'Short "ja" selects downstream task, not create_option',
                'passed' => $task !== 'mod_booking.create_option',
                'detail' => "Selected task: {$task}",
            ],
        ];
    }
}
