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

namespace bookingextension_agent\local\wbagent\tests;

use bookingextension_agent\local\wbagent\task_registry_factory;
use context_module;
use mod_booking\local\testing\booking_advanced_testcase;
use mod_booking\singleton_service;

/**
 * Contracts for canonical mod_booking option task discovery and behavior.
 *
 * @covers \mod_booking\local\wbagent\options\tasks\create_option_task
 * @covers \mod_booking\local\wbagent\options\tasks\create_selflearning_option_task
 * @covers \mod_booking\local\wbagent\options\tasks\create_slotbooking_option_task
 * @covers \mod_booking\local\wbagent\options\tasks\update_option_task
 * @covers \bookingextension_agent\local\wbagent\task_registry
 * @covers \bookingextension_agent\local\wbagent\task_registry_factory
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mod_booking_option_tasks_contract_test extends booking_advanced_testcase {
    /**
     * Ensure canonical mod_booking option tasks are discoverable.
     */
    public function test_registry_discovers_canonical_mod_booking_option_tasks(): void {
        task_registry_factory::reset();
        $registry = task_registry_factory::get_default();

        $expected = [
            'mod_booking.create_option',
            'mod_booking.create_selflearning_option',
            'mod_booking.create_slotbooking_option',
            'mod_booking.update_option',
        ];

        foreach ($expected as $taskname) {
            $this->assertNotNull($registry->get_task($taskname), 'Missing discovered task: ' . $taskname);
        }
    }

    /**
     * Ensure normal create task creates a type-0 option when no extra hints are given.
     */
    public function test_create_option_defaults_to_type_zero(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        task_registry_factory::reset();
        $registry = task_registry_factory::get_default();
        $task = $registry->get_task('mod_booking.create_option');

        $this->assertNotNull($task);

        $input = [
            'text' => 'Default type option',
        ];

        $preflight = $task->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight must pass for canonical create.');

        $result = $task->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertGreaterThan(0, (int)($result['optionid'] ?? 0));
        $this->assertSame($bookingid, (int)($result['bookingid'] ?? 0));

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$result['optionid']);
        $this->assertSame(0, (int)$settings->type, 'Normal create task must persist option type 0.');
    }

    /**
     * Ensure normal create task emits a rich observation payload for follow-up planning.
     */
    public function test_create_option_emits_rich_observation_summary(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        task_registry_factory::reset();
        $registry = task_registry_factory::get_default();
        $task = $registry->get_task('mod_booking.create_option');

        $this->assertNotNull($task);

        $input = [
            'text' => 'Observation option',
            'maxanswers' => 7,
            'invisible' => 0,
        ];

        $preflight = $task->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight must pass for create observation test.');

        $result = $task->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''));

        $observation = trim((string)($result['observation_full'] ?? ''));
        $this->assertStringContainsString('Booking option created:', $observation);
        $this->assertStringContainsString('optionid=' . (int)($result['optionid'] ?? 0), $observation);
        $this->assertStringContainsString('title="Observation option"', $observation);
        $this->assertStringContainsString('bookingid=' . $bookingid, $observation);
        $this->assertStringContainsString('type=0', $observation);
        $this->assertStringContainsString('maxanswers=7', $observation);
        $this->assertStringContainsString('invisible=0', $observation);
    }

    /**
     * Ensure selflearning update task persists option type 1.
     */
    public function test_update_option_sets_type_one_for_selflearning_input(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        /** @var \mod_booking_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => $bookingid,
            'text' => 'Initial option',
            'maxanswers' => 8,
        ]);

        task_registry_factory::reset();
        $registry = task_registry_factory::get_default();
        $task = $registry->get_task('mod_booking.update_option');

        $this->assertNotNull($task);

        $input = [
            'optionid' => (int)$option->id,
            'text' => 'Selflearning option',
            'maxanswers' => 16,
            'optiontype' => 'selflearning',
        ];

        $preflight = $task->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight must pass for canonical selflearning update.');

        $result = $task->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertSame((int)$option->id, (int)($result['optionid'] ?? 0));

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        $this->assertSame(1, (int)$settings->type, 'Selflearning update task must persist option type 1.');
        $this->assertSame('Selflearning option', (string)$settings->text);
        $this->assertSame(16, (int)$settings->maxanswers);
    }

    /**
     * Ensure slotbooking create task blocks when slot form fields are missing.
     */
    public function test_create_slotbooking_option_requires_slot_fields(): void {
        [$teacher, $contextid] = $this->create_booking_test_context();

        task_registry_factory::reset();
        $registry = task_registry_factory::get_default();
        $task = $registry->get_task('mod_booking.create_slotbooking_option');

        $this->assertNotNull($task);

        $input = [
            'text' => 'Slot without required fields',
        ];

        $preflight = $task->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('hard_block', $preflight->status, 'Slotbooking create must fail without required slot fields.');
        $this->assertNotEmpty($preflight->issues);
        $this->assertStringContainsString(
            'Missing required slot field: slot_opening_time.',
            (string)($preflight->issues[0]['message'] ?? '')
        );
    }

    /**
     * Ensure slotbooking contracts expose explicit slotbooking intent metadata.
     */
    public function test_slotbooking_prompt_contracts_are_explicit(): void {
        task_registry_factory::reset();
        $registry = task_registry_factory::get_default();

        $create = $registry->get_task('mod_booking.create_slotbooking_option');
        $update = $registry->get_task('mod_booking.update_option');

        $this->assertNotNull($create);
        $this->assertNotNull($update);

        $createcontract = $create->get_prompt_contract()->to_array();
        $updatecontract = $update->get_prompt_contract()->to_array();

        $this->assertSame('create_slotbooking', (string)($createcontract['intent'] ?? ''));
        $this->assertSame('task', (string)($updatecontract['intent'] ?? ''));
        $this->assertContains('slot_opening_time', (array)($createcontract['minimal_input'] ?? []));
        $this->assertContains('optionid', array_keys((array)$update->get_schema()['properties']));
    }

    /**
     * Create booking/module context and a teacher with required booking capabilities.
     *
     * @return array{0:\stdClass,1:int,2:int}
     */
    private function create_booking_test_context(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Option task contract test',
        ]);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        $this->setUser($teacher);

        $cm = get_coursemodule_from_instance('booking', (int)$booking->id, (int)$course->id, false, MUST_EXIST);
        $context = context_module::instance((int)$cm->id);

        $this->grant_booking_option_task_capabilities((int)$teacher->id, (int)$context->id);

        return [$teacher, (int)$context->id, (int)$booking->id];
    }

    /**
     * Ensure editingteacher has required booking capabilities in module context.
     *
     * @param int $userid
     * @param int $contextid
     * @return void
     */
    private function grant_booking_option_task_capabilities(int $userid, int $contextid): void {
        $roles = get_archetype_roles('editingteacher');
        if (empty($roles)) {
            $this->fail('editingteacher role archetype not found');
        }

        $role = reset($roles);
        $roleid = (int)$role->id;

        assign_capability('mod/booking:addoption', CAP_ALLOW, $roleid, $contextid, true);
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $contextid, true);
        role_assign($roleid, $userid, $contextid);

        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }
}
