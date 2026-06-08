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
 * Scenario: R1 mutation with session-allow active -> autoconfirm flag present.
 * @package bookingextension_agent
 */
class auto_confirm_session extends abstract_benchmark_scenario {
    public function get_key(): string {
        return 'auto_confirm_session_r1';
    }
    public function get_class(): string {
        return 'mutation_r1';
    }
    public function get_description(): string {
        return 'R1 mutation with session-allow: selector picks skill, autoconfirm path active';
    }
    public function get_user_message(): string {
        return 'Erstelle schnell eine Veranstaltung "AutoTest" morgen um 14 Uhr, 10 Plaetze.';
    }
    public function get_expected_response_type(): string {
        return 'skill_call';
    }
    public function get_expected_skill(): string {
        return 'mod_booking.create_option';
    }

    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","commands":[{"skill":"mod_booking.create_option","input":{}}],'
            . '"planned_steps":[],"next_step_intent":"Create AutoTest option",'
            . '"used_triggers":[],"lang":"de","user_lang":"de"}';
    }
    public function assert_additional(array $result): array {
        return [
            [
                'label'  => 'next_step_intent is non-null string',
                'passed' => isset($result['next_step_intent']) && is_string($result['next_step_intent']),
                'detail' => 'next_step_intent type: ' . gettype($result['next_step_intent'] ?? null),
            ],
        ];
    }
}
