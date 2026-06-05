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

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\tests;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\dto\task_risk_class;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\services\decision\agent_decision_service;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\task_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for risk-class driven decision gating helpers.
 *
 * @covers \bookingextension_agent\local\wbagent\services\decision\agent_decision_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decision_service_risk_gating_test extends TestCase {
    /**
     * Risk resolution must fall back to the task registry when the command omits risk_class.
     */
    public function test_resolve_command_risk_class_falls_back_to_registry_task_value(): void {
        $service = $this->build_service([
            'demo.write' => task_risk_class::R2,
        ]);

        $riskclass = $this->invoke_private_method($service, 'resolve_command_risk_class', [
            ['task' => 'demo.write', 'input' => []],
        ]);

        $this->assertSame(task_risk_class::R2, $riskclass);
    }

    /**
     * Command batches must be split and annotated by risk class before routing.
     */
    public function test_split_commands_by_risk_class_injects_resolved_risk_classes(): void {
        $service = $this->build_service([
            'demo.read' => task_risk_class::R0,
            'demo.write' => task_risk_class::R2,
            'demo.external' => task_risk_class::R3,
        ]);

        $groups = $this->invoke_private_method($service, 'split_commands_by_risk_class', [[
            ['task' => 'demo.read', 'input' => []],
            ['task' => 'demo.write', 'input' => []],
            ['task' => 'demo.external', 'input' => []],
        ]]);

        $this->assertCount(1, $groups['r0']);
        $this->assertCount(1, $groups['r2']);
        $this->assertCount(1, $groups['r3']);
        $this->assertSame(task_risk_class::R0, $groups['r0'][0]['risk_class']);
        $this->assertSame(task_risk_class::R2, $groups['r2'][0]['risk_class']);
        $this->assertSame(task_risk_class::R3, $groups['r3'][0]['risk_class']);
    }

    /**
     * Build a decision service with a task registry mock that returns risk-class aware tasks.
     *
     * @param array<string,string> $taskriskmap
     * @return agent_decision_service
     */
    private function build_service(array $taskriskmap): agent_decision_service {
        $registry = $this->getMockBuilder(task_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_task'])
            ->getMock();

        $registry->method('get_task')->willReturnCallback(
            static function (string $taskname) use ($taskriskmap): ?task_interface {
                if (!array_key_exists($taskname, $taskriskmap)) {
                    return null;
                }

                $task = new class ($taskname, $taskriskmap[$taskname]) implements task_interface {
                    private string $name;
                    private string $riskclass;

                    public function __construct(string $name, string $riskclass) {
                        $this->name = $name;
                        $this->riskclass = $riskclass;
                    }

                    public function get_name(): string {
                        return $this->name;
                    }

                    public function get_schema(): array {
                        return ['version' => 1, 'properties' => []];
                    }

                    public function get_example_input(): array {
                        return [];
                    }

                    public function get_prompt_contract(): \bookingextension_agent\local\wbagent\services\task_prompt_contract {
                        return new \bookingextension_agent\local\wbagent\services\task_prompt_contract([
                            'intent' => 'demo',
                            'anchors' => [],
                            'minimal_input' => [],
                            'example_input' => [],
                            'namespace' => 'demo',
                            'version' => 1,
                            'capabilities' => [],
                            'context_scopes' => ['module'],
                            'risk_class' => $this->riskclass,
                        ]);
                    }

                    public function get_risk_class(): string {
                        return $this->riskclass;
                    }

                    public function check_structure(array $input): array {
                        return ['valid' => true, 'errors' => []];
                    }

                    public function preflight(array $input, int $contextid, int $userid): \bookingextension_agent\local\wbagent\services\preflight_result_v2 {
                        return \bookingextension_agent\local\wbagent\services\preflight_result_v2::ok($input);
                    }

                    public function execute(array $preparedinput, int $contextid, int $userid): array {
                        return [];
                    }

                    public function is_read_only(): bool {
                        return $this->riskclass === task_risk_class::R0;
                    }
                };

                return $task;
            }
        );

        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authz = $this->createMock(authorization_service::class);

        return new agent_decision_service($registry, $store, $authz);
    }

    /**
     * Invoke a private decision-service helper.
     *
     * @param agent_decision_service $service
     * @param string $method
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invoke_private_method(agent_decision_service $service, string $method, array $args) {
        $reflection = new \ReflectionClass(agent_decision_service::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($service, $args);
    }
}
