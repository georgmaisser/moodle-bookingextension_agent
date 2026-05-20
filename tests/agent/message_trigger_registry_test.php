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
 * Tests for trigger catalog and normalization.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\booking\tasks\add_price_category_task;
use bookingextension_agent\local\wbagent\booking\tasks\bulk_update_options_task;
use bookingextension_agent\local\wbagent\booking\tasks\create_option_task;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\message_trigger_registry;
use bookingextension_agent\local\wbagent\booking\tasks\search_options_task;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\booking\tasks\update_option_task;

/**
 * Trigger registry tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class message_trigger_registry_test extends booking_advanced_testcase {
    /**
     * Unknown trigger ids returned by the LLM must be dropped.
     *
     * @covers \bookingextension_agent\local\wbagent\message_trigger_registry::normalize_used_triggers
     */
    public function test_normalize_used_triggers_filters_unknown_ids(): void {
        $registry = new task_registry();
        $registry->register($this->make_provider('local_trigger', [
            $this->make_task_with_trigger('booking.trigger_task', false, 'booking.custom_trigger'),
        ]));

        $triggerregistry = new message_trigger_registry($registry);
        $normalized = $triggerregistry->normalize_used_triggers([
            'core.is_lookup_request',
            'booking.custom_trigger',
            'unknown.trigger',
            'core.is_lookup_request',
        ]);

        $this->assertSame(['core.is_lookup_request', 'booking.custom_trigger'], $normalized);
    }

    /**
     * Task-provided triggers should be exposed via registry.
     *
     * @covers \bookingextension_agent\local\wbagent\message_trigger_registry::get_available_triggers
     */
    public function test_available_triggers_include_task_contributions(): void {
        $registry = new task_registry();
        $registry->register($this->make_provider('local_trigger', [
            $this->make_task_with_trigger('booking.trigger_task', false, 'booking.custom_trigger'),
        ]));

        $triggerregistry = new message_trigger_registry($registry);
        $triggers = $triggerregistry->get_available_triggers();
        $ids = array_values(array_map(static fn(array $trigger): string => (string)$trigger['id'], $triggers));

        $this->assertContains('core.is_confirmation_message', $ids);
        $this->assertContains('booking.custom_trigger', $ids);
    }

    /**
     * Unknown response types must be normalized explicitly.
     *
     * @covers \bookingextension_agent\local\wbagent\message_trigger_registry::normalize_response_type
     */
    public function test_normalize_response_type_returns_unknown_marker_for_invalid_values(): void {
        $registry = new task_registry();
        $triggerregistry = new message_trigger_registry($registry);

        $this->assertSame('task_call', $triggerregistry->normalize_response_type('task_call'));
        $this->assertSame('clarification', $triggerregistry->normalize_response_type(' Clarification '));
        $this->assertSame(
            message_trigger_registry::UNKNOWN_RESPONSE_TYPE,
            $triggerregistry->normalize_response_type('totally_new_type')
        );
        $this->assertSame(
            message_trigger_registry::UNKNOWN_RESPONSE_TYPE,
            $triggerregistry->normalize_response_type('')
        );
    }

    /**
     * High/medium-priority mod_booking tasks should expose dedicated trigger ids.
     *
     * @covers \bookingextension_agent\local\wbagent\booking\tasks\add_price_category_task::get_message_triggers
     * @covers \bookingextension_agent\local\wbagent\booking\tasks\bulk_update_options_task::get_message_triggers
     * @covers \bookingextension_agent\local\wbagent\booking\tasks\create_option_task::get_message_triggers
     * @covers \bookingextension_agent\local\wbagent\booking\tasks\search_options_task::get_message_triggers
     * @covers \bookingextension_agent\local\wbagent\booking\tasks\update_option_task::get_message_triggers
     */
    public function test_selected_booking_tasks_expose_message_triggers(): void {
        $tasks = [
            new create_option_task(),
            new update_option_task(),
            new bulk_update_options_task(),
            new add_price_category_task(),
            new search_options_task(),
        ];

        $allids = [];
        foreach ($tasks as $task) {
            $this->assertInstanceOf(task_trigger_provider_interface::class, $task);
            foreach ($task->get_message_triggers() as $trigger) {
                $allids[] = (string)($trigger['id'] ?? '');
            }
        }

        $this->assertContains('booking.force_create_duplicate_title', $allids);
        $this->assertContains('booking.create_booking_request', $allids);
        $this->assertContains('booking.use_preview_context_for_update', $allids);
        $this->assertContains('booking.bulk_update_apply_to_all_confirmed', $allids);
        $this->assertContains('booking.confirm_duplicate_price_category', $allids);
        $this->assertContains('booking.search_options_exact_title_match', $allids);
    }

    /**
     * Build a task that contributes one custom trigger.
     *
     * @param string $name
     * @param bool $readonly
     * @param string $triggerid
     * @return task_interface
     */
    private function make_task_with_trigger(string $name, bool $readonly, string $triggerid): task_interface {
        return new class ($name, $readonly, $triggerid) implements task_interface, task_trigger_provider_interface {
            /** @var string */
            private string $name;
            /** @var bool */
            private bool $readonly;
            /** @var string */
            private string $triggerid;

            /**
             * Constructor.
             *
             * @param string $name
             * @param bool $readonly
             * @param string $triggerid
             */
            public function __construct(string $name, bool $readonly, string $triggerid) {
                $this->name = $name;
                $this->readonly = $readonly;
                $this->triggerid = $triggerid;
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
             * Get input schema.
             *
             * @return array
             */
            public function get_schema(): array {
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

            /**
             * Return message triggers provided by task.
             *
             * @return array
             */
            public function get_message_triggers(): array {
                return [[
                    'id' => $this->triggerid,
                    'description' => 'Custom trigger emitted by task.',
                ]];
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
             * Get provider component.
             *
             * @return string
             */
            public function get_component(): string {
                return $this->component;
            }

            /**
             * Get provider tasks.
             *
             * @return array
             */
            public function get_tasks(): array {
                return $this->tasks;
            }

            /**
             * Get optional contextual prompt packs.
             *
             * @return array
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }
        };
    }
}
