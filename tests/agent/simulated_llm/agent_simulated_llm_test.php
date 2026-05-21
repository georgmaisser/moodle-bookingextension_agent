<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Simulated-LLM smoke tests for booking agent runtime.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_simulated_llm_testcase.php');

/**
 * Basic runtime smoke checks with scripted orchestrator responses.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class agent_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Confirmation smoke: runtime returns confirmation_request for mutating task.
     */
    public function test_simulated_confirmation_smoke(): void {
        $this->setUser($this->teacher);

        $response = self::confirmation_response(
            'booking.create_option',
            [
                'text' => 'Sim Smoke Option ' . uniqid('', true),
                'maxanswers' => 7,
                'coursestarttime' => '2045-11-01T09:00:00',
                'courseendtime' => '2045-11-01T11:00:00',
                'teacherquery' => 'current',
            ],
            'Please confirm this change.'
        );

        [$store, $runtime, $threadid] = $this->build_scripted_runtime([$response]);
        $result = $this->chat('Create an option', $threadid, $store, $runtime);

        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''));
        $this->assertNotNull($this->extract_command($result, 'booking.create_option'));
    }

    /**
     * Read-only loop smoke: task_call is auto-executed and ends in clarification.
     */
    public function test_simulated_read_only_loop_smoke(): void {
        $this->setUser($this->teacher);

        $title = 'Sim Smoke Search ' . uniqid('', true);
        $this->create_option($title, ['maxanswers' => 5]);

        $responses = [
            self::task_call_response('booking.search_options', ['query' => $title], 'Searching.'),
            self::clarification_response('Found matching options.'),
        ];

        [$store, $runtime, $threadid] = $this->build_scripted_runtime($responses);
        $result = $this->chat('Search options', $threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertNotEmpty($result['results'] ?? []);
    }
}
