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
 * Scenario: R0 read-only skill - no confirmation required.
 * @package bookingextension_agent
 */
class get_current_user_readonly extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'get_current_user_readonly';
    }
    public function get_class(): string {
        return 'readonly';
    }
    public function get_description(): string {
        return 'R0 read-only: get_current_user selected, no confirmation_request';
    }
    public function get_user_message(): string {
        return 'Wer bin ich? Zeig mir mein Profil.';
    }
    public function get_expected_response_type(): string {
        return 'skill_call';
    }
    public function get_expected_skill(): string {
        return 'core.get_current_user';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"core.get_current_user","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Show user profile",'
            . '"used_triggers":["core.get_current_user_request"],"lang":"de","user_lang":"de"}';
    }
    public function assert_additional(array $result): array {
        return [
            [
                'label'  => 'R0 skill: response_type is skill_call (not confirmation_request)',
                'passed' => ($result['response_type'] ?? '') === 'skill_call',
                'detail' => 'response_type: ' . ($result['response_type'] ?? ''),
            ],
        ];
    }
}
