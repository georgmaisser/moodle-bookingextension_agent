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
 * Simulated-LLM conversation tests for booking.create_option.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_simulated_llm_testcase.php');

/**
 * Deterministic create-option tests with scripted LLM output.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class create_option_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated happy path: confirmation_request -> execute -> DB row exists.
     */
    public function test_simulated_create_option_happy_path(): void {
        $this->setUser($this->teacher);

        $title = 'Sim Create ' . uniqid('', true);
        $response = self::confirmation_response(
            'booking.create_option',
            [
                'text' => $title,
                'optiontype' => 'normal',
                'maxanswers' => 12,
                'coursestarttime' => '2045-11-01T14:00:00',
                'courseendtime' => '2045-11-01T16:00:00',
                'teacherquery' => 'current',
            ],
            'Please confirm creating this booking option.'
        );

        [$store, $runtime, $threadid] = $this->build_scripted_runtime([$response]);
        $result = $this->chat('Create option request', $threadid, $store, $runtime);

        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''));

        $command = $this->extract_command($result, 'booking.create_option');
        $this->assertNotNull($command);

        $execresult = $this->execute_command($command);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $optionid = (int)($execresult['resultid'] ?? 0);
        $this->assertGreaterThan(0, $optionid);

        $option = $this->get_option_from_db($optionid);
        $this->assertSame($title, (string)$option->text);
        $this->assertSame(12, (int)$option->maxanswers);
    }
}
