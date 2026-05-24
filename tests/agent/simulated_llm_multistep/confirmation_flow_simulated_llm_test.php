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
 * Simulated-LLM multistep conversation tests.
 *
 * Scenario:
 * - create booking option
 * - assign Billy as teacher
 * - make the option visible
 *
 * This folder mirrors the real_llm_multistep coverage with deterministic
 * scripted responses.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../simulated_llm/abstract_simulated_llm_testcase.php');

/**
 * Multistep confirmation flow with scripted responses.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class confirmation_flow_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Create option, then update teacher, then make visible.
     */
    public function test_multistep_create_assign_teacher_and_make_visible(): void {
        global $DB;

        $this->setUser($this->teacher);

        $billy = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teacher',
            'email' => 'billy.teacher.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($billy->id, $this->course->id, 'editingteacher');

        $title = 'Multistep Simulated ' . uniqid('', true);

        $responses = [
            self::confirmation_response(
                'booking.create_option',
                [
                    'text' => $title,
                    'optiontype' => 'normal',
                    'maxanswers' => 8,
                    'coursestarttime' => '2045-11-10T09:00:00',
                    'courseendtime' => '2045-11-10T11:00:00',
                    'teacherquery' => 'current',
                    'location' => 'Online',
                ],
                'Please confirm creating the booking option.'
            ),
            self::confirmation_response(
                'booking.update_option',
                [
                    'optionquery' => $title,
                    'teacherquery' => fullname($billy),
                ],
                'Please confirm assigning Billy as teacher.'
            ),
            self::confirmation_response(
                'booking.update_option',
                [
                    'optionquery' => $title,
                    'visible' => 1,
                ],
                'Please confirm making the option visible.'
            ),
        ];

        [$store, $runtime, $threadid] = $this->build_scripted_runtime($responses);

        $result1 = $this->chat('Create a booking option for the session.', $threadid, $store, $runtime);
        $this->assertSame('confirmation_request', (string)($result1['response_type'] ?? ''), json_encode($result1));
        $createcommand = $this->extract_command($result1, 'booking.create_option');
        $this->assertNotNull($createcommand, 'create_option command must be present.');

        $exec1 = $this->confirm_pending_result($result1, $threadid, $store, false);
        $this->assertTrue((bool)($exec1['success'] ?? false), (string)($exec1['message'] ?? ''));

        $option = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => $title,
        ]);
        $this->assertNotFalse($option, 'Created booking option must exist.');

        $result2 = $this->chat('Make Billy Teacher responsible for the option.', $threadid, $store, $runtime);
        $this->assertSame('confirmation_request', (string)($result2['response_type'] ?? ''), json_encode($result2));
        $teachercommand = $this->extract_command($result2, 'booking.update_option');
        $this->assertNotNull($teachercommand, 'update_option command for teacher must be present.');

        $exec2 = $this->confirm_pending_result($result2, $threadid, $store, false);
        $this->assertTrue((bool)($exec2['success'] ?? false), (string)($exec2['message'] ?? ''));

        $details = $this->exec_command('booking.get_option_details', [
            'optionquery' => $title,
            'requested_fields' => ['title', 'teachers'],
            'includesessions' => false,
        ]);
        $this->assertSame('executed', (string)($details['status'] ?? ''));
        $teachers = json_encode($details['optiondetails'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Billy', (string)$teachers);

        $result3 = $this->chat('Make the option visible.', $threadid, $store, $runtime);
        $this->assertSame('confirmation_request', (string)($result3['response_type'] ?? ''), json_encode($result3));
        $visiblecommand = $this->extract_command($result3, 'booking.update_option');
        $this->assertNotNull($visiblecommand, 'update_option command for visibility must be present.');

        $exec3 = $this->confirm_pending_result($result3, $threadid, $store, false);
        $this->assertTrue((bool)($exec3['success'] ?? false), (string)($exec3['message'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertSame(MOD_BOOKING_OPTION_VISIBLE, (int)$updated->invisible);
    }
}
