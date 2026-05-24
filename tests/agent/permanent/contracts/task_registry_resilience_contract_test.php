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
 * Registry resilience contract tests for AI task providers.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

use bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Ensures broken third-party tasks are isolated during registration.
 *
 * @coversNothing
 */
final class task_registry_resilience_contract_test extends \advanced_testcase {
    /**
     * A broken task must not prevent valid sibling tasks from registering.
     */
    public function test_faulty_task_is_isolated_from_valid_provider_tasks(): void {
        $registry = new task_registry();

        $registry->register(new resilience_contract_provider());

        $this->assertContains('booking.test_resilience_first', $registry->get_task_names());
        $this->assertContains('booking.test_resilience_second', $registry->get_task_names());
        $this->assertNull($registry->get_task('booking.test_resilience_broken'));

        $diagnostics = implode("\n", $registry->get_contract_diagnostics());
        $this->assertStringContainsString('get_name() failed', $diagnostics);
        $this->assertStringContainsString('boom from broken task', $diagnostics);
    }

    /**
     * A provider-level task list failure must be captured as diagnostics.
     */
    public function test_provider_get_tasks_failure_is_captured_as_diagnostic(): void {
        $registry = new task_registry();

        $registry->register(new throwing_resilience_contract_provider());

        $this->assertSame([], $registry->get_task_names());
        $diagnostics = implode("\n", $registry->get_contract_diagnostics());
        $this->assertStringContainsString('provider error', $diagnostics);
        $this->assertStringContainsString('provider task list failed', $diagnostics);
    }
}

/**
 * Test provider with valid tasks around one faulty task.
 */
class resilience_contract_provider implements task_provider_interface {
    /**
     * Return provider component.
     *
     * @return string
     */
    public function get_component(): string {
        return 'bookingextension/agent';
    }

    /**
     * Return task instances.
     *
     * @return array<int,task_interface>
     */
    public function get_tasks(): array {
        return [
            new resilience_contract_task('booking.test_resilience_first'),
            new broken_resilience_contract_task(),
            new resilience_contract_task('booking.test_resilience_second'),
        ];
    }

    /**
     * Return prompt packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [];
    }

    /**
     * Return issue code provider.
     *
     * @return issue_code_provider_interface|null
     */
    public function get_issue_code_provider(): ?issue_code_provider_interface {
        return null;
    }

    /**
     * Return prompt guidance.
     *
     * @return array<string,mixed>
     */
    public function get_prompt_guidance(): array {
        return [];
    }
}

/**
 * Provider that fails while listing tasks.
 */
final class throwing_resilience_contract_provider extends resilience_contract_provider {
    /**
     * Return task instances.
     *
     * @return array<int,task_interface>
     */
    public function get_tasks(): array {
        throw new \coding_exception('provider task list failed');
    }
}

/**
 * Valid test task.
 */
class resilience_contract_task implements task_interface {
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
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return $this->taskname;
    }

    /**
     * Return schema.
     *
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'readonly' => true,
            'properties' => [],
        ];
    }

    /**
     * Return example input.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [];
    }

    /**
     * Check structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Preflight task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
        return preflight_result_v2::ok($input);
    }

    /**
     * Execute task.
     *
     * @param array $preparedinput
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array {
        return ['status' => 'executed'];
    }

    /**
     * Whether task is readonly.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return true;
    }
}

/**
 * Broken task that fails during registration.
 */
final class broken_resilience_contract_task extends resilience_contract_task {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct('booking.test_resilience_broken');
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        throw new \coding_exception('boom from broken task');
    }
}