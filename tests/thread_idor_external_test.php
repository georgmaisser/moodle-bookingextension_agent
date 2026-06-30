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
use context_course;
use bookingextension_agent\external\ai_poll_thread;
use bookingextension_agent\external\ai_get_thread_debug_logs;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\llm_debug_logger;

/**
 * IDOR regression at the webservice boundary: the threadid read endpoints must enforce thread
 * ownership themselves, not just expose a gate method. A second authorised agent user (so readiness
 * passes) who guesses another user's threadid must receive nothing — neither poll messages nor raw
 * LLM debug logs.
 *
 * thread_ownership_gate_test covers conversation_store::thread_belongs_to_user in isolation; this
 * test proves the actual entry points (ai_poll_thread, ai_get_thread_debug_logs) call it.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\external\ai_poll_thread
 * @covers     \bookingextension_agent\external\ai_get_thread_debug_logs
 */
final class thread_idor_external_test extends advanced_testcase {
    /**
     * Two editing teachers (both hold useaiinstructions, so readiness passes) in one course.
     *
     * @return array{0:\stdClass,1:\stdClass,2:int}  [owner, attacker, course context id]
     */
    private function two_agent_users(): array {
        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $attacker = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($owner->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($attacker->id, $course->id, 'editingteacher');
        return [$owner, $attacker, (int)context_course::instance($course->id)->id];
    }

    /**
     * ai_poll_thread: the owner reads their own thread; an authorised attacker guessing the id gets
     * the fail-closed empty result (threadid 0, no messages), never the owner's messages.
     */
    public function test_poll_thread_rejects_foreign_threadid(): void {
        $this->resetAfterTest();
        [$owner, $attacker, $ctxid] = $this->two_agent_users();

        $store = new conversation_store();
        $ownerthread = (int)$store->get_or_create_thread((int)$owner->id, $ctxid)->id;
        // A step message so the owner's poll is non-empty — proving the attacker's empty result is a
        // denial, not just an empty thread.
        $store->add_step_message($ownerthread, 1, 'Working on it', 'mod_booking.create_option');

        // Owner: sees their own thread and its message.
        $this->setUser($owner);
        $ownerview = ai_poll_thread::execute($ctxid, $ownerthread, 0);
        $this->assertSame($ownerthread, (int)$ownerview['threadid']);
        $this->assertNotEmpty($ownerview['messages'], 'Owner must see their own step messages.');

        // Attacker: same threadid, but fail-closed — id reset to 0 and no messages leaked.
        $this->setUser($attacker);
        $attackerview = ai_poll_thread::execute($ctxid, $ownerthread, 0);
        $this->assertSame(0, (int)$attackerview['threadid'], 'A foreign threadid must not resolve.');
        $this->assertSame([], $attackerview['messages'], 'No messages of another user may leak.');
    }

    /**
     * ai_get_thread_debug_logs: with debug mode on and a seeded log in the owner's thread, the owner
     * reads it but an authorised attacker guessing the id gets an empty payload — never the raw logs.
     */
    public function test_debug_logs_reject_foreign_threadid(): void {
        $this->resetAfterTest();
        [$owner, $attacker, $ctxid] = $this->two_agent_users();
        set_config('aidebugmode', 1, 'bookingextension_agent');

        $store = new conversation_store();
        $ownerthread = (int)$store->get_or_create_thread((int)$owner->id, $ctxid)->id;
        // With aidebugmode enabled above, log_exchange persists the entry.
        llm_debug_logger::log_exchange(
            $store,
            $ownerthread,
            0,
            (int)$owner->id,
            'unit-test',
            'the-request',
            'the-response',
            true
        );

        // Owner: their raw log is returned.
        $this->setUser($owner);
        $ownerview = ai_get_thread_debug_logs::execute($ctxid, $ownerthread, 100);
        $this->assertSame('', (string)$ownerview['error']);
        $this->assertStringContainsString('the-response', (string)$ownerview['debuglogsjson']);

        // Attacker: fail-closed empty payload, the owner's raw logs never leak.
        $this->setUser($attacker);
        $attackerview = ai_get_thread_debug_logs::execute($ctxid, $ownerthread, 100);
        $this->assertSame('[]', (string)$attackerview['debuglogsjson'], 'No raw logs of another user may leak.');
        $this->assertStringNotContainsString('the-response', (string)$attackerview['debuglogsjson']);
    }
}
