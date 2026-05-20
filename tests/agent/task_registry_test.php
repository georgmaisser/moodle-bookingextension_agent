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
 * Tests for task registry behavior.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Tests for task registry duplicate handling.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class task_registry_test extends booking_advanced_testcase {
    /**
     * Duplicate task names should not throw and should keep the first registered task.
     */
    public function test_duplicate_task_name_keeps_first_registered_task(): void {
        $registry = new task_registry();

        $firsttask = $this->make_task('booking.duplicate_task', true);
        $secondtask = $this->make_task('booking.duplicate_task', false);

        $registry->register($this->make_provider('local_first', [$firsttask]));
        $registry->register($this->make_provider('local_second', [$secondtask]));
        $this->assertDebuggingCalled(
            'Duplicate AI task name detected: booking.duplicate_task (component: local_second). Keeping first registered task.',
            DEBUG_DEVELOPER
        );

        $resolved = $registry->get_task('booking.duplicate_task');
        $this->assertSame($firsttask, $resolved);
        $this->assertTrue($registry->is_read_only_task('booking.duplicate_task'));
    }

    /**
     * Empty task names should be ignored.
     */
    public function test_empty_task_name_is_ignored(): void {
        $registry = new task_registry();

        $registry->register($this->make_provider('local_empty', [
            $this->make_task('', true),
            $this->make_task('booking.valid_task', true),
        ]));
        $this->assertDebuggingCalled(
            'Ignoring AI task with empty name from component local_empty',
            DEBUG_DEVELOPER
        );

        $this->assertNull($registry->get_task(''));
        $this->assertNotNull($registry->get_task('booking.valid_task'));
    }

    /**
     * Strict governance mode should fail fast on contract diagnostics.
     */
    public function test_strict_mode_throws_on_contract_diagnostics(): void {
        $this->resetAfterTest(true);
        set_config('aigovernancestrictmode', 1, 'bookingextension_agent');

        $registry = new task_registry();
        $registry->register($this->make_provider('local_first', [
            $this->make_task('booking.strict_duplicate', true),
        ]));

        $this->expectException(\coding_exception::class);
        $registry->register($this->make_provider('local_second', [
            $this->make_task('booking.strict_duplicate', false),
        ]));
    }

    /**
     * Task toggle config should override metadata active flag.
     */
    public function test_task_active_uses_config_toggle_override(): void {
        $this->resetAfterTest(true);

        $registry = new task_registry();
        $registry->register($this->make_provider('bookingextension_agent', [
            $this->make_task('booking.toggle_case', true),
        ]));

        $settingname = task_registry::get_task_toggle_setting_name('booking.toggle_case');

        set_config($settingname, 0, 'bookingextension_agent');
        $this->assertFalse($registry->is_task_active('booking.toggle_case'));

        set_config($settingname, 1, 'bookingextension_agent');
        $this->assertTrue($registry->is_task_active('booking.toggle_case'));
    }

    /**
     * Task should default to inactive when no per-task toggle is configured.
     */
    public function test_task_active_defaults_to_off_when_unconfigured(): void {
        $this->resetAfterTest(true);

        $registry = new task_registry();
        $registry->register($this->make_provider('bookingextension_agent', [
            $this->make_task('booking.default_off_case', true),
        ]));

        $this->assertFalse($registry->is_task_active('booking.default_off_case'));
    }

    /**
     * Global enable-all setting should override per-task toggle values.
     */
    public function test_task_active_global_enable_all_overrides_toggles(): void {
        $this->resetAfterTest(true);

        $registry = new task_registry();
        $registry->register($this->make_provider('bookingextension_agent', [
            $this->make_task('booking.enable_all_case', true),
        ]));

        $settingname = task_registry::get_task_toggle_setting_name('booking.enable_all_case');
        set_config($settingname, 0, 'bookingextension_agent');
        set_config('aitaskenableall', 1, 'bookingextension_agent');

        $this->assertTrue($registry->is_task_active('booking.enable_all_case'));
    }

    /**
     * Create a lightweight task double.
     *
     * @param string $name
     * @param bool $readonly
     * @return task_interface
     */
    private function make_task(string $name, bool $readonly): task_interface {
        return new class ($name, $readonly) implements task_interface {
            /** @var string */
            private string $name;
            /** @var bool */
            private bool $readonly;

            /**
             * Constructor.
             *
             * @param string $name
             * @param bool $readonly
             */
            public function __construct(string $name, bool $readonly) {
                $this->name = $name;
                $this->readonly = $readonly;
            }

            /**
             * Get task name.
             *
             * @return string
             */
            public function get_name(): string {
                return $this->name;
            }

            /**
             * Get task schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return [];
            }

            /**
             * Get example input.
             *
             * @return array
             */
            public function get_example_input(): array {
                return [];
            }

            /**
             * Structural input check.
             *
             * @param array $input
             * @return array
             */
            public function check_structure(array $input): array {
                return ['valid' => true, 'errors' => []];
            }

            /**
             * Preflight check (no-op for this test double).
             *
             * @param array $input
             * @param int $cmid
             * @param int $userid
             * @return \bookingextension_agent\local\wbagent\task_preflight_result
             */
            public function preflight(array $input, int $cmid, int $userid): \bookingextension_agent\local\wbagent\task_preflight_result {
                return \bookingextension_agent\local\wbagent\task_preflight_result::ok($input);
            }

            /**
             * Validate task input.
             *
             * @param array $input
             * @param int $cmid
             * @return array
             */
            public function validate(array $input, int $cmid): array {
                return ['valid' => true, 'errors' => [], 'ambiguities' => []];
            }

            /**
             * Execute task.
             *
             * @param array $input
             * @param int $cmid
             * @param int $userid
             * @return array
             */
            public function execute(array $input, int $cmid, int $userid): array {
                return ['status' => 'executed', 'detail' => 'ok', 'resultid' => null];
            }

            /**
             * Whether task is read-only.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                return $this->readonly;
            }
        };
    }

    /**
     * Create a lightweight provider double.
     *
     * @param string $component
     * @param array $tasks
     * @return task_provider_interface
     */
    private function make_provider(string $component, array $tasks): task_provider_interface {
        return new class ($component, $tasks) implements task_provider_interface {
            /** @var string */
            private string $component;
            /** @var array */
            private array $tasks;

            /**
             * Constructor.
             *
             * @param string $component
             * @param array $tasks
             */
            public function __construct(string $component, array $tasks) {
                $this->component = $component;
                $this->tasks = $tasks;
            }

            /**
             * Get component name.
             *
             * @return string
             */
            public function get_component(): string {
                return $this->component;
            }

            /**
             * Get tasks.
             *
             * @return array
             */
            public function get_tasks(): array {
                return $this->tasks;
            }

            /**
             * Get contextual prompt packs.
             *
             * @return array
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }

            /**
             * Get optional issue code provider.
             *
             * @return \bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface|null
             */
            public function get_issue_code_provider(): ?\bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface {
                return null;
            }

            /**
             * Get optional prompt guidance metadata.
             *
             * @return array<string,mixed>
             */
            public function get_prompt_guidance(): array {
                return [];
            }
        };
    }
}
