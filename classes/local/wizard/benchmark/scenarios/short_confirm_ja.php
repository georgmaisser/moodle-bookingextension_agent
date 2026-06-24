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

/**
 * Scenario short_confirm_ja.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario: "ja" after agent listed pending steps — selector must signal confirm_pending.
 *
 * When the user confirms ("ja") after the agent proposed pending multi-step work,
 * the correct selector response is confirm_pending (user acknowledged, execute pending queue).
 * The selector does NOT jump directly to skill_call here; the pipeline resolves the pending item.
 *
 * @package bookingextension_agent
 */
class short_confirm_ja extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'short_confirm_ja';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'multistep';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return '"ja" after agent listed pending trainer+booking steps — selector signals confirm_pending';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'ja';
    }

    /**

     * Get prior messages.

     *

     * @return array

     */
    public function get_prior_messages(): array {
        return [
            ['role' => 'user', 'content' => 'Erstelle TestA am Dienstag, dann Trainer setzen und User buchen.'],
            ['role' => 'assistant', 'content' => "TestA wurde erstellt.\n\n## Noch ausstehend\n"
                . "1. Trainer fuer TestA setzen\n2. User fuer TestA buchen\n\nMoechtest du dass ich weitermache?"],
        ];
    }

    /**

     * Get the expected response type.

     *

     * @return string

     */
    public function get_expected_response_type(): string {
        return 'confirm_pending';
    }
    /**
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return '';
    }

    /**

     * Get the stub selector response.

     *

     * @return string

     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"confirm_pending","commands":[],'
            . '"planned_steps":[],"next_step_intent":"Set trainer for TestA",'
            . '"message":"Alles klar, ich fahre fort mit dem Trainer-Schritt.",'
            . '"used_triggers":["core.is_confirmation_message"],"lang":"de","user_lang":"de"}';
    }

    /**

     * Perform additional assertions.

     *

     * @param array $result

     * @return array

     */
    public function assert_additional(array $result): array {
        $commands = $result['commands'] ?? [];
        return [
            [
                'label'  => 'confirm_pending must have empty commands array',
                'passed' => empty($commands),
                'detail' => 'commands: ' . json_encode($commands),
            ],
        ];
    }
}
