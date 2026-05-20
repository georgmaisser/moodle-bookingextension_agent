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
 * Simulated-LLM conversation tests for booking.search_options.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_simulated_llm_testcase.php');

/**
 * Deterministic search-option loop tests with scripted LLM output.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class search_options_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated read-only loop: task_call auto-executes then clarification returns.
     */
    public function test_simulated_search_options_loop_auto_executes(): void {
        $this->setUser($this->teacher);

        $prefix = 'Sim Search ' . uniqid('', true);
        $this->create_option($prefix . ' A', ['maxanswers' => 10]);
        $this->create_option($prefix . ' B', ['maxanswers' => 8]);

        $responses = [
            self::task_call_response('booking.search_options', ['query' => $prefix], 'Searching options.'),
            self::clarification_response('I found matching options.'),
        ];

        [$store, $runtime, $threadid] = $this->build_scripted_runtime($responses);
        $result = $this->chat('Search options request', $threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertNotEmpty($result['results'] ?? []);

        $taskresult = $this->extract_task_result($result, 'booking.search_options');
        $this->assertNotNull($taskresult);
        $this->assertSame('executed', (string)($taskresult['status'] ?? ''));

        $allnames = [];
        foreach ((array)($taskresult['options'] ?? []) as $option) {
            $allnames[] = strtolower((string)($option['name'] ?? $option['text'] ?? ''));
        }

        $this->assertStringContainsStringIgnoringCase($prefix . ' a', implode(' ', $allnames));
        $this->assertStringContainsStringIgnoringCase($prefix . ' b', implode(' ', $allnames));
    }
}
