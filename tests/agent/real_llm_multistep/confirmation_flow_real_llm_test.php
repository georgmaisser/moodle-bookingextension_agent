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
 * Real-LLM multistep conversation tests.
 *
 * Scenario:
 * - create booking option
 * - assign Billy as teacher
 * - make the option visible
 *
 * Each mutating step must remain separately confirmed. The test acts as the
 * multistep DoD for the confirmation-flow work.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Multistep confirmation flow with a real LLM.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class confirmation_flow_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

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

        [$store, $runtime, $threadid] = $this->build_runtime();

        if (!$this->is_skill_available('mod_booking.create_option')) {
            $this->enforcegeneratetextassertion = false;
            $this->fail('booking.create_option is not available in the current skill catalog.');
        }

        $title = 'Multistep Real LLM ' . uniqid('', true);

        $result1 = $this->chat(
            'Create a booking option called "' . $title . '" with 8 spots, optiontype normal, '
                . 'start 2045-11-10T09:00:00, end 2045-11-10T11:00:00.',
            $threadid,
            $store,
            $runtime
        );
        if (($result1['response_type'] ?? '') !== 'confirmation_request') {
            $result1 = $this->chat(
                'Please create a booking option called "' . $title . '" with 8 spots. '
                    . 'It should run from 2045-11-10T09:00:00 to 2045-11-10T11:00:00.',
                $threadid,
                $store,
                $runtime
            );
        }
        $createcommand = $this->extract_command($result1, 'mod_booking.create_option');
        $this->assertNotNull($createcommand, 'create_option command must be present.');
        $createcommand['input'] = array_merge($createcommand['input'] ?? [], [
            'text' => $title,
            'optiontype' => 'normal',
            'maxanswers' => 8,
            'coursestarttime' => '2045-11-10T09:00:00',
            'courseendtime' => '2045-11-10T11:00:00',
            'teacherquery' => 'current',
            'location' => 'Online',
        ]);
        unset($createcommand['input']['optiondates']);

        $createconfirm = $this->confirm_pending_result($result1, (int)$threadid, $store, false);
        $this->assertTrue((bool)($createconfirm['success'] ?? false), (string)($createconfirm['message'] ?? ''));

        $option = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => $title,
        ]);
        $this->assertNotFalse($option, 'Created booking option must exist.');

        $result2 = $this->chat(
            'Make Billy Teacher responsible for "' . $title . '". Use teacher email "' . $billy->email . '".',
            $threadid,
            $store,
            $runtime
        );
        if (($result2['response_type'] ?? '') !== 'confirmation_request') {
            $result2 = $this->chat(
                'Please assign Billy Teacher to the booking option "' . $title . '". '
                    . 'His email address is "' . $billy->email . '".',
                $threadid,
                $store,
                $runtime
            );
        }
        $teachercommand = $this->extract_teacher_assignment_command($result2);
        if (
            $teachercommand === null
            || empty($teachercommand['input'])
            || (!array_key_exists('teacherquery', (array)$teachercommand['input'])
                && !array_key_exists('teacheremail', (array)$teachercommand['input']))
        ) {
            $result2 = $this->chat(
                'Please assign Billy Teacher to the booking option with the title "' . $title . '". '
                    . 'His email address is "' . $billy->email . '".',
                $threadid,
                $store,
                $runtime
            );
            $teachercommand = $this->extract_teacher_assignment_command($result2);
        }
        // The whole point of step 2 is that the agent CAN assign a teacher: after the retries it must
        // have produced the command. Failing (instead of silently skipping) surfaces a real regression
        // where the agent stops handling teacher assignment.
        $this->assertNotNull(
            $teachercommand,
            'The agent must produce an update_option or update_option_trainer command to assign the teacher.'
        );
        $teacherconfirm = $this->confirm_pending_result($result2, (int)$threadid, $store, false);
        $this->assertTrue((bool)($teacherconfirm['success'] ?? false), (string)($teacherconfirm['message'] ?? ''));

        $details = $this->exec_command('mod_booking.get_option_details', [
            'optionquery' => $title,
            'requested_fields' => ['title', 'teachers'],
            'includesessions' => false,
        ]);
        if ((string)($details['status'] ?? '') !== 'executed') {
            $details = $this->exec_command('mod_booking.get_option_details', [
                'optionid' => (int)$option->id,
                'requested_fields' => ['title', 'teachers'],
                'includesessions' => false,
            ]);
        }
        // Deterministic post-condition: Billy is now a teacher of the option (LLM prose may vary, this
        // does not). Asserted unconditionally — previously it degraded to a non-empty check that passed
        // even when the assignment never happened.
        $this->assertSame('executed', (string)($details['status'] ?? ''), 'get_option_details must succeed.');
        $teachers = json_encode($details['optiondetails'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Billy', (string)$teachers, 'The assigned teacher must appear on the option.');

        $result3 = $this->chat(
            'Now make "' . $title . '" visible.',
            $threadid,
            $store,
            $runtime
        );
        if (($result3['response_type'] ?? '') !== 'confirmation_request') {
            $result3 = $this->chat(
                'Please make the booking option "' . $title . '" visible.',
                $threadid,
                $store,
                $runtime
            );
        }
        $visiblecommand = $this->extract_command($result3, 'mod_booking.update_option') ?? $this->extract_command($result3, 'booking.update_option');
        if (
            $visiblecommand === null
            || empty($visiblecommand['input'])
            || (!array_key_exists('visible', (array)$visiblecommand['input'])
                && !array_key_exists('invisible', (array)$visiblecommand['input']))
        ) {
            $result3 = $this->chat(
                'Please make the booking option with the title "' . $title . '" visible.',
                $threadid,
                $store,
                $runtime
            );
            $visiblecommand = $this->extract_command($result3, 'mod_booking.update_option') ?? $this->extract_command($result3, 'booking.update_option');
        }

        // Step 3 must go through the AGENT confirmation flow (no direct-exec fallback, which would mask
        // an agent that stopped handling visibility). Require the command, confirm it, then assert the
        // deterministic effect unconditionally.
        $this->assertNotNull($visiblecommand, 'The agent must produce an update_option command to set visibility.');
        $visibleconfirm = $this->confirm_pending_result($result3, (int)$threadid, $store, false);
        $this->assertTrue((bool)($visibleconfirm['success'] ?? false), (string)($visibleconfirm['message'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertSame(MOD_BOOKING_OPTION_VISIBLE, (int)$updated->invisible);
    }

    /**
     * Check if a skill currently exists in the registry.
     *
     * @param string $skillname
     * @return bool
     */
    private function is_skill_available(string $skillname): bool {
        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
        return $registry->get_skill($skillname) !== null;
    }

    /**
     * Extract the teacher-assignment command, whichever legitimate skill the planner chose.
     *
     * The specialized mod_booking.update_option_trainer is the preferred (and observed) routing
     * for "make X responsible / assign X as teacher"; the generic update_option with a
     * teacherquery/teacheremail field is equally correct. Commands may carry canonical
     * (mod_booking.*) or alias (booking.*) names depending on the normalization layer.
     *
     * @param array $result AgentRuntime result.
     * @return array|null The first matching command.
     */
    private function extract_teacher_assignment_command(array $result): ?array {
        foreach (
            [
                'mod_booking.update_option_trainer',
                'booking.update_option_trainer',
                'mod_booking.update_option',
                'booking.update_option',
            ] as $skillname
        ) {
            $command = $this->extract_command($result, $skillname);
            if ($command !== null) {
                return $command;
            }
        }
        return null;
    }
}
