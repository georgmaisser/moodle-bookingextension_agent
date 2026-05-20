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

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wbagent\conversation_store;

/**
 * Tests for booking.recall_memory task.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 */
final class recall_memory_task_test extends abstract_agent_testcase {
    /**
     * Override message creation timestamp in DB.
     *
     * @param int $messageid
     * @param int $timestamp
     * @return void
     */
    private function set_message_time(int $messageid, int $timestamp): void {
        global $DB;
        $record = new \stdClass();
        $record->id = $messageid;
        $record->timecreated = $timestamp;
        $DB->update_record('local_wbagent_ai_messages', $record);
    }

    /**
     * last_thread must return previous thread content in sequential observation format.
     */
    public function test_last_thread_returns_sequential_observation_blocks(): void {
        $this->setUser($this->teacher);
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);

        $store->add_message((int)$thread->id, 'user', 'First memory user text');
        $store->add_message((int)$thread->id, 'assistant', 'First memory assistant text');
        $store->create_fresh_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);

        $result = $this->exec_command('booking.recall_memory', [
            'mode' => 'last_thread',
        ]);

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertNotEmpty((array)($result['messages'] ?? []));
        $this->assertStringContainsString('[MEMORY_CONTEXT]', (string)($result['memory_observation_text'] ?? ''));
        $this->assertStringContainsString('[USER_PREVIOUS 1]', (string)($result['memory_observation_text'] ?? ''));
        $this->assertStringContainsString('[ASSISTANT_PREVIOUS 1]', (string)($result['memory_observation_text'] ?? ''));
        $this->assertSame(
            trim((string)($result['memory_observation_text'] ?? '')),
            trim((string)($result['observation_full'] ?? ''))
        );
    }

    /**
     * date_window with "last friday" must filter messages by the resolved day window.
     */
    public function test_date_window_last_friday_filters_by_time_window(): void {
        $this->setUser($this->teacher);
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);

        $tz = new \DateTimeZone('UTC');
        $lastfriday = new \DateTimeImmutable('last friday', $tz);
        $insidetimestamp = $lastfriday->setTime(11, 0, 0)->getTimestamp();
        $outsidetimestamp = $lastfriday->modify('+2 days')->setTime(11, 0, 0)->getTimestamp();

        $insideid = $store->add_message((int)$thread->id, 'user', 'Friday memory marker');
        $outsideid = $store->add_message((int)$thread->id, 'user', 'Not friday marker');
        $this->set_message_time($insideid, $insidetimestamp);
        $this->set_message_time($outsideid, $outsidetimestamp);

        $result = $this->exec_command('booking.recall_memory', [
            'mode' => 'date_window',
            'date_hint' => 'last friday',
            'query' => 'marker',
        ]);

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $messages = (array)($result['messages'] ?? []);
        $this->assertCount(1, $messages);
        $this->assertSame('Friday memory marker', (string)($messages[0]['content'] ?? ''));
        $this->assertNotNull($result['from_timestamp'] ?? null);
        $this->assertNotNull($result['to_timestamp'] ?? null);
    }

    /**
     * query filter must match structuredjson even when plain content is anonymized.
     */
    public function test_query_filter_matches_structured_payload(): void {
        $this->setUser($this->teacher);
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);

        $day = new \DateTimeImmutable('last friday', new \DateTimeZone('UTC'));
        $messageid = $store->add_message((int)$thread->id, 'assistant', 'ANON_USER_1 document', [
            'topic' => 'user_x',
            'kind' => 'document',
        ]);
        $this->set_message_time($messageid, $day->setTime(9, 30, 0)->getTimestamp());

        $result = $this->exec_command('booking.recall_memory', [
            'mode' => 'date_window',
            'date_hint' => $day->format('Y-m-d'),
            'query' => 'user_x',
            'include_structured' => true,
        ]);

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $messages = (array)($result['messages'] ?? []);
        $this->assertCount(1, $messages);
        $this->assertIsArray($messages[0]['structured'] ?? null);
        $this->assertSame('user_x', (string)(($messages[0]['structured'] ?? [])['topic'] ?? ''));
    }

    /**
     * User A must never retrieve User B memory even with manipulated userid input.
     */
    public function test_memory_recall_never_leaks_other_user_data(): void {
        $this->setUser($this->teacher);
        $store = new conversation_store();
        $threadteacher = $store->get_or_create_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);
        $threadstudent = $store->get_or_create_thread((int)$this->student->id, (int)$this->booking->cmid, (int)$this->booking->id);

        $store->add_message((int)$threadteacher->id, 'user', 'Teacher-safe-message');
        $store->add_message((int)$threadstudent->id, 'user', 'STUDENT_SECRET_MEMORY');
        $store->create_fresh_thread((int)$this->teacher->id, (int)$this->booking->cmid, (int)$this->booking->id);

        $result = $this->exec_command('booking.recall_memory', [
            'mode' => 'last_thread',
            'query' => 'STUDENT_SECRET_MEMORY',
            'userid' => (int)$this->student->id,
        ], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $payload = json_encode($result);
        $this->assertStringNotContainsString('STUDENT_SECRET_MEMORY', (string)$payload);
    }
}
