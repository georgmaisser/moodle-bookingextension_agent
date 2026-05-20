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
 * Simulated-LLM conversation tests for booking.book_users.
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
 * Deterministic book-users tests with scripted LLM output.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class book_users_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated happy path: booking command confirmed and executed.
     */
    public function test_simulated_book_users_happy_path(): void {
        global $DB;

        $this->setUser($this->teacher);

        $option = $this->create_option('Sim Book Users ' . uniqid('', true), ['maxanswers' => 5]);
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Sim',
            'lastname' => 'Booker',
            'email' => 'sim.booker.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        $response = self::confirmation_response(
            'booking.book_users',
            [
                'optionid' => (int)$option->id,
                'userids' => [(int)$target->id],
                'bookusersquery' => (string)(int)$target->id,
            ],
            'Please confirm booking the user.'
        );

        [$store, $runtime, $threadid] = $this->build_scripted_runtime([$response]);
        $result = $this->chat('Book user request', $threadid, $store, $runtime);

        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''));

        $command = $this->extract_command($result, 'booking.book_users');
        $this->assertNotNull($command);

        $execresult = $this->execute_command($command);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $answer = $DB->get_record('booking_answers', [
            'optionid' => (int)$option->id,
            'userid' => (int)$target->id,
        ]);

        $this->assertNotFalse($answer);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
    }
}
