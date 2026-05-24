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

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wbagent\task_registry;

/**
 * Contract and integration tests for new core Moodle tasks.
 *
 * @package    bookingextension_agent
 * @category   test
 * @coversNothing
 */
final class core_moodle_tasks_test extends abstract_agent_testcase {
    /**
     * All new core task names.
     *
     * @return array<int,array{0:string}>
     */
    public static function core_task_names_provider(): array {
        return array_map(static fn(string $name): array => [$name], [
            'booking.core_get_user_profile',
            'booking.core_get_user_preferences',
            'booking.core_set_user_preference',
            'booking.core_get_user_enrolments',
            'booking.core_get_current_user',
            'booking.core_enrol_user_manual',
            'booking.core_unenrol_user_manual',
            'booking.core_list_course_participants',
            'booking.core_get_user_roles_in_course',
            'booking.core_search_course_enrolments',
            'booking.core_list_course_groups',
            'booking.core_get_group_members',
            'booking.core_create_group',
            'booking.core_update_group',
            'booking.core_delete_group',
            'booking.core_get_course_overview',
            'booking.core_list_course_sections',
            'booking.core_list_course_modules',
            'booking.core_get_module_details',
            'booking.core_get_activity_completion_status',
            'booking.core_get_user_completion_report',
            'booking.core_list_course_calendar_events',
            'booking.core_list_user_calendar_events',
            'booking.core_create_calendar_event',
            'booking.core_update_calendar_event',
            'booking.core_delete_calendar_event',
            'booking.core_list_grade_items',
            'booking.core_get_user_grades_for_course',
            'booking.core_send_user_message',
            'booking.core_get_site_summary',
        ]);
    }

    /**
     * Every new task should be discoverable with schema and triggers.
     *
     * @dataProvider core_task_names_provider
     * @param string $taskname
     */
    public function test_core_tasks_are_registered_with_schema_and_triggers(string $taskname): void {
        $task = task_registry::make_default()->get_task($taskname);
        $this->assertNotNull($task, 'Task missing: ' . $taskname);

        $schema = $task->get_schema();
        $this->assertArrayHasKey('readonly', $schema);
        $this->assertArrayHasKey('properties', $schema);

        $structure = $task->check_structure([]);
        $this->assertArrayHasKey('valid', $structure);
        $this->assertArrayHasKey('errors', $structure);

        $this->assertTrue(method_exists($task, 'get_message_triggers'));
        $triggers = $task->get_message_triggers();
        $this->assertNotEmpty($triggers, 'Expected triggers for ' . $taskname);
        $first = $triggers[0] ?? [];
        $this->assertNotSame('', trim((string)($first['id'] ?? '')));
        $this->assertNotSame('', trim((string)($first['description'] ?? '')));
        $examples = (array)($first['examples'] ?? []);
        $this->assertNotEmpty($examples, 'Expected bilingual examples for ' . $taskname);
    }

    /**
     * Mutating tasks should expose confirmation issues by default.
     */
    public function test_mutating_tasks_require_confirmation_in_validation(): void {
        $cases = [
            ['booking.core_set_user_preference', ['name' => 'bookanyone', 'value' => '1']],
            ['booking.core_enrol_user_manual', ['userquery' => (string)$this->student->id, 'coursequery' => (string)$this->course->id]],
            ['booking.core_unenrol_user_manual', ['userquery' => (string)$this->student->id, 'coursequery' => (string)$this->course->id]],
            ['booking.core_create_group', ['coursequery' => (string)$this->course->id, 'name' => 'Alpha']],
            ['booking.core_update_group', ['coursequery' => (string)$this->course->id, 'groupquery' => '1', 'name' => 'Beta']],
            ['booking.core_delete_group', ['coursequery' => (string)$this->course->id, 'groupquery' => '1']],
            ['booking.core_create_calendar_event', ['title' => 'T', 'timestart' => time() + 100, 'timeend' => time() + 500]],
            ['booking.core_update_calendar_event', ['eventid' => 1]],
            ['booking.core_delete_calendar_event', ['eventid' => 1]],
            ['booking.core_send_user_message', ['recipient' => (string)$this->student->id, 'message' => 'Hi']],
        ];

        foreach ($cases as [$taskname, $input]) {
            $task = task_registry::make_default()->get_task($taskname);
            $this->assertNotNull($task);
            $preflight = $task->preflight($input, (int)$this->booking->cmid, (int)$this->teacher->id);
            $issues = (array)$preflight->issues;
            $this->assertNotEmpty($issues, 'Expected confirmation issue for ' . $taskname);
            $this->assertSame('needs_confirmation', (string)($issues[0]['severity'] ?? ''));
        }
    }

    /**
     * Read-only tasks should execute with structured payloads.
     */
    public function test_readonly_tasks_happy_path_and_localization(): void {
        set_user_preference('bookanyone', '1', (int)$this->teacher->id);

        $cases = [
            ['booking.core_get_current_user', [], 'user'],
            ['booking.core_get_user_profile', ['userquery' => (string)$this->teacher->id], 'profile'],
            ['booking.core_get_user_preferences', ['userquery' => (string)$this->teacher->id], 'preferences'],
            ['booking.core_get_user_enrolments', ['userquery' => (string)$this->teacher->id], 'courses'],
            ['booking.core_list_course_participants', ['coursequery' => (string)$this->course->id], 'participants'],
            ['booking.core_get_user_roles_in_course', ['coursequery' => (string)$this->course->id, 'userquery' => (string)$this->teacher->id], 'roles'],
            ['booking.core_search_course_enrolments', ['coursequery' => (string)$this->course->id], 'users'],
            ['booking.core_list_course_groups', ['coursequery' => (string)$this->course->id], 'groups'],
            ['booking.core_get_course_overview', ['coursequery' => (string)$this->course->id], 'sections'],
            ['booking.core_list_course_sections', ['coursequery' => (string)$this->course->id], 'sections'],
            ['booking.core_list_course_modules', ['coursequery' => (string)$this->course->id], 'modules'],
            ['booking.core_list_course_calendar_events', ['coursequery' => (string)$this->course->id], 'events'],
            ['booking.core_list_user_calendar_events', ['userquery' => (string)$this->teacher->id], 'events'],
            ['booking.core_list_grade_items', ['coursequery' => (string)$this->course->id], 'gradeitems'],
            ['booking.core_get_user_grades_for_course', ['coursequery' => (string)$this->course->id, 'userquery' => (string)$this->teacher->id], 'grades'],
            ['booking.core_get_site_summary', [], 'site'],
        ];

        foreach ($cases as [$taskname, $input, $expectedkey]) {
            $result = $this->exec_command($taskname, $input + ['outputlang' => 'de']);
            $this->assertSame('executed', $result['status'], $taskname . ' failed: ' . (string)($result['detail'] ?? ''));
            $this->assertArrayHasKey($expectedkey, $result, 'Missing structured key for ' . $taskname);
            $this->assertIsString((string)($result['detail'] ?? ''));
        }
    }

    /**
     * Cross-user profile lookup should be denied for student without privilege.
     */
    public function test_profile_cross_user_permission_gate(): void {
        $roles = get_archetype_roles('student');
        $role = reset($roles);
        $this->assertNotFalse($role);
        assign_capability(
            'bookingextension/agent:useaiinstructions',
            CAP_ALLOW,
            (int)$role->id,
            (int)\context_module::instance((int)$this->booking->cmid)->id,
            true
        );
        assign_capability(
            'bookingextension/agent:task_booking_core_get_user_profile',
            CAP_ALLOW,
            (int)$role->id,
            (int)\context_module::instance((int)$this->booking->cmid)->id,
            true
        );
        accesslib_clear_all_caches(true);

        $this->setUser($this->student);
        $result = $this->exec_command('booking.core_get_user_profile', [
            'userquery' => (string)$this->teacher->id,
        ], (int)$this->booking->cmid, (int)$this->student->id);

        $this->assertSame('error', $result['status']);
        $this->assertSame(get_string('agent_booking_core_user_permission_denied', 'bookingextension_agent'), (string)($result['detail'] ?? ''));
    }

    /**
     * Calendar create/update/delete integration smoke test.
     */
    public function test_calendar_mutation_happy_path_with_confirmation_flag(): void {
        $create = $this->exec_command('booking.core_create_calendar_event', [
            'title' => 'Core Task Event',
            'timestart' => time() + 3600,
            'timeend' => time() + 7200,
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $create['status'], (string)($create['detail'] ?? ''));
        $eventid = (int)($create['eventid'] ?? 0);
        $this->assertGreaterThan(0, $eventid);

        $update = $this->exec_command('booking.core_update_calendar_event', [
            'eventid' => $eventid,
            'title' => 'Core Task Event Updated',
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $update['status'], (string)($update['detail'] ?? ''));

        $delete = $this->exec_command('booking.core_delete_calendar_event', [
            'eventid' => $eventid,
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $delete['status'], (string)($delete['detail'] ?? ''));
    }

    /**
     * Group create/update/delete integration smoke test.
     */
    public function test_group_mutation_happy_path_with_confirmation_flag(): void {
        $create = $this->exec_command('booking.core_create_group', [
            'coursequery' => (string)$this->course->id,
            'name' => 'CoreTaskGroupA',
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $create['status'], (string)($create['detail'] ?? ''));
        $groupid = (int)($create['groupid'] ?? 0);
        $this->assertGreaterThan(0, $groupid);

        $update = $this->exec_command('booking.core_update_group', [
            'coursequery' => (string)$this->course->id,
            'groupquery' => (string)$groupid,
            'name' => 'CoreTaskGroupB',
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $update['status'], (string)($update['detail'] ?? ''));

        $delete = $this->exec_command('booking.core_delete_group', [
            'coursequery' => (string)$this->course->id,
            'groupquery' => (string)$groupid,
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $delete['status'], (string)($delete['detail'] ?? ''));
    }

    /**
     * Enrol and unenrol smoke test with confirmation flag.
     */
    public function test_manual_enrolment_mutation_happy_path_with_confirmation_flag(): void {
        $target = $this->getDataGenerator()->create_user();

        $enrol = $this->exec_command('booking.core_enrol_user_manual', [
            'userquery' => (string)$target->id,
            'coursequery' => (string)$this->course->id,
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $enrol['status'], (string)($enrol['detail'] ?? ''));

        $unenrol = $this->exec_command('booking.core_unenrol_user_manual', [
            'userquery' => (string)$target->id,
            'coursequery' => (string)$this->course->id,
            'confirmed' => true,
        ]);
        $this->assertSame('executed', $unenrol['status'], (string)($unenrol['detail'] ?? ''));
    }

    /**
     * Messaging task should send message with explicit confirmation flag.
     */
    public function test_send_user_message_happy_path_with_confirmation_flag(): void {
        $result = $this->exec_command('booking.core_send_user_message', [
            'recipient' => (string)$this->student->id,
            'message' => 'Hallo vom Core Task Test',
            'confirmed' => true,
        ]);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertGreaterThan(0, (int)($result['messageid'] ?? 0));
    }
}
