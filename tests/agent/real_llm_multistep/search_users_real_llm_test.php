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
 * Real-LLM regression test for core.search_users.
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
 * Real provider regression test for the user-search task.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class search_users_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    public function test_search_users_observation_contains_roles_courses_and_profile(): void {
        $this->setUser($this->teacher);

        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Search',
            'lastname' => 'Target',
            'email' => 'search.target.' . uniqid('', true) . '@example.com',
            'department' => 'QA',
            'institution' => 'Wunderbyte',
            'city' => 'Vienna',
        ]);
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);

        $prompt = 'Nutze ausschliesslich die Task core.search_users. '
            . 'Suche nach dem Benutzer mit der E-Mail "' . $student->email . '". '
            . 'Gib genau einen task_call mit task="core.search_users", version=1 und input={'
            . '"query":"' . $student->email . '"} aus.';

        $response = $this->chat($prompt, $threadid, $store, $runtime);
        if (!$this->has_task_evidence($response, 'core.search_users')) {
            $response = $this->chat(
                'Fuehre jetzt nur core.search_users mit query="' . $student->email . '" aus. Keine andere Task.',
                $threadid,
                $store,
                $runtime
            );
        }

        $this->assertTrue(
            $this->has_task_evidence($response, 'core.search_users'),
            'Expected core.search_users evidence from real LLM response. Payload: ' . $this->payload_text($response)
        );

        $result = $this->exec_command(
            'core.search_users',
            ['query' => (string)$student->email],
            (int)$this->booking->cmid,
            (int)$this->teacher->id
        );

        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertSame((int)$student->id, (int)($result['resultid'] ?? 0));

        $users = array_values((array)($result['users'] ?? []));
        $this->assertNotEmpty($users, 'Expected at least one structured user payload.');

        $user = (array)$users[0];
        $observation = trim((string)($result['observation_full'] ?? ''));
        $coursepayload = json_encode($user['enrolledcourses'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rolepayload = json_encode($user['roles'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame((int)$student->id, (int)($user['userid'] ?? 0));
        $this->assertSame((string)$student->email, (string)($user['email'] ?? ''));
        $this->assertSame('QA', (string)($user['department'] ?? ''));
        $this->assertSame('Wunderbyte', (string)($user['institution'] ?? ''));
        $this->assertNotEmpty((array)($user['enrolledcourses'] ?? []), 'Expected enrolledcourses in search result payload.');
        $this->assertNotEmpty((array)($user['roles'] ?? []), 'Expected roles in search result payload.');

        $this->assertStringContainsString((string)$this->course->fullname, (string)$coursepayload);
        $this->assertStringContainsString('student', (string)$rolepayload);

        $this->assertStringContainsString('Found 1 user(s):', $observation);
        $this->assertStringContainsString('profile=', $observation);
        $this->assertStringContainsString('enrolledcourses=[', $observation);
        $this->assertStringContainsString('roles=[', $observation);
        $this->assertStringContainsString((string)$student->email, $observation);
        $this->assertStringContainsString((string)$this->course->fullname, $observation);
        $this->assertStringContainsString('student', $observation);
        $this->assertStringContainsString('department=QA', $observation);
        $this->assertStringContainsString('institution=Wunderbyte', $observation);
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
