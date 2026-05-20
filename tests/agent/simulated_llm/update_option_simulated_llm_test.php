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
 * Simulated-LLM conversation tests for booking.update_option.
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
 * Deterministic update-option tests with scripted LLM output.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class update_option_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated happy path: update an existing option.
     */
    public function test_simulated_update_option_happy_path(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Sim Update Target', ['maxanswers' => 5]);

        $response = self::confirmation_response(
            'booking.update_option',
            [
                'optionid' => (int)$option->id,
                'maxanswers' => 20,
            ],
            'Please confirm updating this booking option.'
        );

        [$store, $runtime, $threadid] = $this->build_scripted_runtime([$response]);
        $result = $this->chat('Update option request', $threadid, $store, $runtime);

        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''));

        $command = $this->extract_command($result, 'booking.update_option');
        $this->assertNotNull($command);

        $execresult = $this->execute_command($command);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertSame(20, (int)$updated->maxanswers);
    }
}
