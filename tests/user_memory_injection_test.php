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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\services\user_memory_service;
use bookingextension_agent\local\wbagent\skill_registry_factory;

/**
 * Tests that stored user memories are injected into the runtime context at the
 * selection phase (which feeds both the planner selection call and the synchronizer),
 * and nowhere a memory would never reach a model.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wbagent\orchestrator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_memory_injection_test extends advanced_testcase {
    /**
     * Invoke the private runtime-context builder for a phase.
     *
     * @param orchestrator $orc
     * @param int $threadid
     * @param int $contextid
     * @param string $phase
     * @return string
     */
    private function build_block(orchestrator $orc, int $threadid, int $contextid, string $phase): string {
        $method = new \ReflectionMethod(orchestrator::class, 'build_runtime_context_block');
        $method->setAccessible(true);
        return (string)$method->invoke($orc, $threadid, $contextid, $phase, false, false, [], [], []);
    }

    /**
     * The memory appears at selection (planner + synchronizer share this phase) but not at
     * discovery (no LLM call) or construction (skill-parameter only).
     */
    public function test_memory_injected_at_selection_only(): void {
        $this->resetAfterTest();

        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $context = \context_system::instance();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, (int)$context->id);

        (new user_memory_service())->add($userid, "Sprich mich immer mit 'Lieber Dr. Maisser' an.");

        $registry = skill_registry_factory::get_default();
        $orc = new orchestrator($registry, new interpreter($registry), $store);

        $selection = $this->build_block($orc, (int)$thread->id, (int)$context->id, orchestrator::PHASE_SELECTION);
        $this->assertStringContainsString('USER MEMORY', $selection);
        $this->assertStringContainsString('Lieber Dr. Maisser', $selection);

        $discovery = $this->build_block($orc, (int)$thread->id, (int)$context->id, orchestrator::PHASE_DISCOVERY);
        $this->assertStringNotContainsString('USER MEMORY', $discovery);

        $construction = $this->build_block(
            $orc,
            (int)$thread->id,
            (int)$context->id,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION
        );
        $this->assertStringNotContainsString('USER MEMORY', $construction);
    }

    /**
     * No memory block is emitted when the user has nothing stored.
     */
    public function test_no_block_when_empty(): void {
        $this->resetAfterTest();

        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $context = \context_system::instance();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, (int)$context->id);

        $registry = skill_registry_factory::get_default();
        $orc = new orchestrator($registry, new interpreter($registry), $store);

        $selection = $this->build_block($orc, (int)$thread->id, (int)$context->id, orchestrator::PHASE_SELECTION);
        $this->assertStringNotContainsString('USER MEMORY', $selection);
    }
}
