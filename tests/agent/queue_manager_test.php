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
 * Tests for queue_manager helpers used by confirmation flow.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use mod_booking\local\testing\booking_advanced_testcase;

/**
 * Queue manager regression tests for queue-driven confirmation metadata.
 *
 * @coversNothing
 */
final class queue_manager_test extends booking_advanced_testcase {
    /**
     * Queue items should preserve version and accept prepared_input enrichment.
     */
    public function test_queue_item_preserves_version_and_prepared_input(): void {
        $this->resetAfterTest();

        $store = new conversation_store();
        $thread = $store->get_or_create_thread(91, 90, 23);
        $manager = new queue_manager($store);

        $item = $manager->enqueue_command(
            (int)$thread->id,
            0,
            1,
            [
                'task' => 'booking.create_option',
                'version' => 3,
                'input' => ['text' => 'Queue manager regression'],
            ],
            'mutating',
            'blocked_confirmation'
        );

        $this->assertSame(3, (int)($item['version'] ?? 0));

        $manager->set_prepared_input((int)$thread->id, (string)$item['queue_item_id'], [
            'text' => 'Queue manager regression',
            'resolved' => true,
        ]);

        $updated = $manager->get_queue_item((int)$thread->id, (string)$item['queue_item_id']);

        $this->assertNotNull($updated);
        $this->assertSame(3, (int)($updated['version'] ?? 0));
        $this->assertSame(true, (bool)($updated['prepared_input']['resolved'] ?? false));
        $this->assertSame('Queue manager regression', (string)($updated['input']['text'] ?? ''));
    }

    /**
     * Ready items with dependencies must wait until prerequisites succeeded.
     */
    public function test_can_pickup_now_requires_succeeded_dependencies(): void {
        $this->resetAfterTest();

        $store = new conversation_store();
        $thread = $store->get_or_create_thread(91, 90, 23);
        $manager = new queue_manager($store);

        $dependency = $manager->enqueue_command(
            (int)$thread->id,
            0,
            1,
            ['task' => 'booking.create_option', 'input' => ['name' => 'A']],
            'mutating',
            'ready'
        );
        $dependent = $manager->enqueue_command(
            (int)$thread->id,
            0,
            2,
            ['task' => 'booking.update_option', 'input' => ['name' => 'B']],
            'mutating',
            'ready',
            [(string)$dependency['queue_item_id']]
        );

        $this->assertFalse($manager->can_pickup_now($dependent));
        $this->assertFalse($manager->dependencies_succeeded((int)$thread->id, $dependent));

        $manager->update_status((int)$thread->id, (string)$dependency['queue_item_id'], 'succeeded');
        $updateddependent = $manager->get_queue_item((int)$thread->id, (string)$dependent['queue_item_id']);

        $this->assertNotNull($updateddependent);
        $this->assertTrue($manager->dependencies_succeeded((int)$thread->id, $updateddependent));
        $this->assertTrue($manager->can_pickup_now($updateddependent));
    }
}
