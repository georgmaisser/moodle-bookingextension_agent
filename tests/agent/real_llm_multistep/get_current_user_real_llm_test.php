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
 * Real-LLM regression test for booking.get_current_user.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Real provider regression test for the current-user task.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class get_current_user_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    public function test_get_current_user_observation_contains_full_user_payload(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);

        $response = $this->chat(
            'Nutze ausschliesslich die Task booking.get_current_user. '
                . 'Gib genau einen task_call mit task="booking.get_current_user", version=1 und input={} aus.',
            $threadid,
            $store,
            $runtime
        );

        if (!$this->has_task_evidence($response, 'booking.get_current_user')) {
            $response = $this->chat(
                'Fuehre jetzt nur booking.get_current_user aus. Keine andere Task.',
                $threadid,
                $store,
                $runtime
            );
        }

        $this->assertTrue(
            $this->has_task_evidence($response, 'booking.get_current_user'),
            'Expected booking.get_current_user evidence from real LLM response. Payload: ' . $this->payload_text($response)
        );

        $result = $this->exec_command('booking.get_current_user', [], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertSame((int)$this->teacher->id, (int)($result['resultid'] ?? 0));

        $user = (array)($result['user'] ?? []);
        $users = (array)($result['users'] ?? []);
        $observation = trim((string)($result['observation_full'] ?? ''));

        $this->assertNotEmpty($user, 'Expected structured current-user payload.');
        $this->assertCount(1, $users, 'Expected one current-user entry in users list.');
        $this->assertSame((int)$this->teacher->id, (int)($user['userid'] ?? 0));
        $this->assertSame((string)$this->teacher->email, (string)($user['email'] ?? ''));
        $this->assertNotEmpty((array)($user['enrolledcourses'] ?? []), 'Expected enrolledcourses in current-user payload.');
        $this->assertNotEmpty((array)($user['roles'] ?? []), 'Expected roles in current-user payload.');

        $coursepayload = json_encode($user['enrolledcourses'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rolepayload = json_encode($user['roles'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString((string)$this->course->fullname, (string)$coursepayload);
        $this->assertStringContainsString('editingteacher', (string)$rolepayload);

        $this->assertStringContainsString('Found 1 user(s):', $observation);
        $this->assertStringContainsString('profile=', $observation);
        $this->assertStringContainsString('enrolledcourses=[', $observation);
        $this->assertStringContainsString('roles=[', $observation);
        $this->assertStringContainsString((string)$this->teacher->email, $observation);
        $this->assertStringContainsString((string)$this->course->fullname, $observation);
        $this->assertStringContainsString('editingteacher', $observation);
    }

    /**
     * Flatten payload content for assertion diagnostics.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    private function payload_text(array $payload): string {
        $chunks = [
            (string)($payload['message'] ?? ''),
            (string)($payload['displaymessage'] ?? ''),
            json_encode($payload['commands'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($payload['results'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return "\n" . implode("\n", $chunks) . "\n";
    }

    /**
     * Check whether the LLM response references the expected task.
     *
     * @param array<string,mixed> $payload
     * @param string $taskname
     * @return bool
     */
    private function has_task_evidence(array $payload, string $taskname): bool {
        return $this->extract_command($payload, $taskname) !== null
            || $this->extract_task_result($payload, $taskname) !== null;
    }
}
