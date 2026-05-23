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
 * Tests for task executability evaluator governance checks.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use context_module;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\task_contract_validator;
use bookingextension_agent\local\wbagent\task_executability_evaluator;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Tests for evaluator deny reasons with task toggles and capabilities.
 *
 * @package    bookingextension_agent
 * @category   test
 * @coversNothing
 */
final class task_executability_evaluator_test extends abstract_agent_testcase {
    /**
     * A disabled task must be denied as inactive before capability checks.
     */
    public function test_evaluator_denies_inactive_task_when_toggle_disabled(): void {
        $this->resetAfterTest(true);

        $registry = task_registry::make_default();
        $taskname = 'booking.create_user';
        $settingname = task_registry::get_task_toggle_setting_name($taskname);
        set_config('aitaskenableall', 0, 'bookingextension_agent');
        set_config($settingname, 0, 'bookingextension_agent');

        $authz = new authorization_service();
        $evaluator = new task_executability_evaluator($registry, $authz);
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;

        $result = $evaluator->evaluate_task($taskname, (int)$this->teacher->id, $contextid);

        $this->assertSame('deny', $result['executable_state']);
        $this->assertSame(task_contract_validator::DENY_INACTIVE, $result['deny_reason']);
    }

    /**
     * An enabled admin-only task must be denied for teacher due to capability.
     */
    public function test_evaluator_denies_missing_capability_for_enabled_admin_task(): void {
        $this->resetAfterTest(true);

        $taskname = 'booking.undefined_capability_task';
        $registry = new task_registry();
        $registry->register($this->make_provider_with_single_task('local_undefined', $taskname));
        $settingname = task_registry::get_task_toggle_setting_name($taskname);
        set_config($settingname, 1, 'bookingextension_agent');

        $authz = new authorization_service();
        $evaluator = new task_executability_evaluator($registry, $authz);
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;

        $result = $evaluator->evaluate_task($taskname, (int)$this->teacher->id, $contextid);

        $this->assertSame('deny', $result['executable_state']);
        $this->assertSame(task_contract_validator::DENY_MISSING_CAPABILITY, $result['deny_reason']);
    }

    /**
     * Build a lightweight provider with one task for deterministic evaluator tests.
     *
     * @param string $component
     * @param string $taskname
     * @return task_provider_interface
     */
    private function make_provider_with_single_task(string $component, string $taskname): task_provider_interface {
        $task = new class($taskname) implements task_interface {
            /** @var string */
            private string $taskname;

            /**
             * Constructor.
             *
             * @param string $taskname
             */
            public function __construct(string $taskname) {
                $this->taskname = $taskname;
            }

            /**
             * @return string
             */
            public function get_name(): string {
                return $this->taskname;
            }

            /**
             * @return array
             */
            public function get_schema(): array {
                return [];
            }

            /**
             * @return array<string,mixed>
             */
            public function get_example_input(): array {
                return [];
            }

            /**
             * @param array $input
             * @return array{valid:bool,errors:array<int,string>}
             */
            public function check_structure(array $input): array {
                return ['valid' => true, 'errors' => []];
            }

            /**
             * @param array $input
             * @param int $cmid
             * @param int $userid
             * @return preflight_result_v2
             */
            public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
                return preflight_result_v2::ok($input);
            }

            /**
             * @param array $preparedinput
             * @param int $cmid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $cmid, int $userid): array {
                return ['status' => 'executed'];
            }

            /**
             * @return bool
             */
            public function is_read_only(): bool {
                return true;
            }
        };

        return new class($component, $task) implements task_provider_interface {
            /** @var string */
            private string $component;
            /** @var task_interface */
            private task_interface $task;

            /**
             * Constructor.
             *
             * @param string $component
             * @param task_interface $task
             */
            public function __construct(string $component, task_interface $task) {
                $this->component = $component;
                $this->task = $task;
            }

            /**
             * @return string
             */
            public function get_component(): string {
                return $this->component;
            }

            /**
             * @return array
             */
            public function get_tasks(): array {
                return [$this->task];
            }

            /**
             * @return array
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }

            /**
             * @return \bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface|null
             */
            public function get_issue_code_provider(): ?\bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface {
                return null;
            }

            /**
             * @return array<string,mixed>
             */
            public function get_prompt_guidance(): array {
                return [];
            }
        };
    }
}
