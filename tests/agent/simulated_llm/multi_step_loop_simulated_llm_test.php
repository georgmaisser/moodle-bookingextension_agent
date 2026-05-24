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
 * Simulated-LLM multi-step loop tests.
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
 * Deterministic multi-step tests with scripted LLM output.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class multi_step_loop_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated multi-step read-only loop then final clarification.
     */
    public function test_simulated_loop_resolves_user_and_option(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Sim Loop Option ' . uniqid('', true), ['maxanswers' => 5]);
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Loop',
            'lastname' => 'Target',
            'email' => 'loop.target.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        $responses = [
            self::task_call_response('booking.search_users', ['query' => fullname($target)], 'Searching users.'),
            self::task_call_response('booking.search_options', ['query' => (string)$option->text], 'Searching options.'),
            self::clarification_response('I resolved user and option references.'),
        ];

        [$store, $runtime, $threadid] = $this->build_scripted_runtime($responses);
        $result = $this->chat('Resolve user and option', $threadid, $store, $runtime);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''));
        $this->assertNotEmpty($result['results'] ?? []);

        $userresult = $this->extract_task_result($result, 'booking.search_users');
        $this->assertNotNull($userresult);
        $this->assertSame('executed', (string)($userresult['status'] ?? ''));

        $optionresult = $this->extract_task_result($result, 'booking.search_options');
        $this->assertNotNull($optionresult);
        $this->assertSame('executed', (string)($optionresult['status'] ?? ''));
    }
}
