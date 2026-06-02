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

use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\services\construction\parameter_constructor;
use bookingextension_agent\local\wbagent\services\construction\parameter_contract_validator;
use bookingextension_agent\local\wbagent\services\selection\lazy_task_loader;
use bookingextension_agent\local\wbagent\services\selection\task_selector;
use bookingextension_agent\local\wbagent\task_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for phase-3 selection and parameter construction.
 *
 * @covers \bookingextension_agent\local\wbagent\services\selection\lazy_task_loader
 * @covers \bookingextension_agent\local\wbagent\services\selection\task_selector
 * @covers \bookingextension_agent\local\wbagent\services\construction\parameter_constructor
 * @covers \bookingextension_agent\local\wbagent\services\construction\parameter_contract_validator
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phase3_selection_construction_contract_test extends TestCase {
    /**
     * Lazy loader must reject tasks outside the phase allow-list.
     */
    public function test_lazy_task_loader_respects_allowed_tasks(): void {
        $registry = $this->getMockBuilder(task_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_task'])
            ->getMock();

        $registry->expects($this->never())->method('get_task');

        $loader = new lazy_task_loader($registry);
        $loaded = $loader->load_task('mod_booking.create_booking', ['mod_booking.update_booking']);

        $this->assertNull($loaded);
    }

    /**
     * Unique suffix selection should resolve to the canonical task name.
     */
    public function test_task_selector_resolves_unique_suffix(): void {
        $task = $this->createMock(task_interface::class);

        $registry = $this->getMockBuilder(task_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_task'])
            ->getMock();

        $registry->method('get_task')->willReturnCallback(static function (string $taskname) use ($task): ?task_interface {
            if ($taskname === 'mod_booking.create_booking') {
                return $task;
            }
            return null;
        });

        $selector = new task_selector(new lazy_task_loader($registry));
        $result = $selector->select(
            ['task' => 'create_booking', 'version' => 2, 'input' => ['foo' => 'bar']],
            ['mod_booking.create_booking'],
            'Command #1'
        );

        $this->assertTrue($result->valid);
        $this->assertSame('mod_booking.create_booking', $result->taskname);
        $this->assertSame(2, $result->version);
        $this->assertSame($task, $result->task);
    }

    /**
     * Parameter construction should normalize inputs and hydrate missing questions.
     */
    public function test_parameter_constructor_normalizes_and_hydrates_question(): void {
        $task = $this->createMock(task_interface::class);
        $task->method('get_schema')->willReturn([
            'properties' => [
                'question' => ['type' => 'string'],
            ],
        ]);

        $registry = $this->getMockBuilder(task_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['normalize_task_input', 'get_task'])
            ->getMock();

        $registry->method('normalize_task_input')->willReturnCallback(static function (string $taskname, array $input): array {
            $input['normalized_by_registry'] = $taskname;
            return $input;
        });
        $registry->method('get_task')->willReturn($task);

        $constructor = new parameter_constructor($registry);
        $result = $constructor->build('mod_booking.create_booking', [
            'search_queries' => 'alpha, beta',
            'question' => '',
            'empty_list' => [],
        ], 'Need help with the booking flow');

        $this->assertTrue($result->valid);
        $this->assertSame('Need help with the booking flow', $result->input['question']);
        $this->assertSame(['alpha', 'beta'], $result->input['search_queries']);
        $this->assertSame('mod_booking.create_booking', $result->input['normalized_by_registry']);
        $this->assertArrayNotHasKey('empty_list', $result->input);
    }

    /**
     * Structural validation should surface task-level errors without mutation.
     */
    public function test_parameter_contract_validator_propagates_structural_errors(): void {
        $task = $this->createMock(task_interface::class);
        $task->method('check_structure')->willReturn([
            'valid' => false,
            'errors' => ['Missing required field.'],
            'issue_codes' => ['REQUIRED_FIELD_MISSING'],
        ]);

        $validator = new parameter_contract_validator();
        $result = $validator->validate($task, ['foo' => 'bar'], 'Command #1');

        $this->assertFalse($result->valid);
        $this->assertSame(['Command #1: Missing required field.'], $result->errors);
        $this->assertSame(['REQUIRED_FIELD_MISSING'], $result->issuecodes);
    }
}
