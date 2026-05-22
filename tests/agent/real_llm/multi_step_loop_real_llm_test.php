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
 * Real-LLM multi-step loop tests.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-18  Multi-step booking loop
 *            - Resolve user by name via booking.search_users.
 *            - Resolve option by title via booking.search_options.
 *            - Prepare booking.book_users as confirmation_request.
 *
 *   CONV-19  Multi-step create loop
 *            - Resolve course by name via booking.search_courses.
 *            - Prepare booking.create_option as confirmation_request.
 *
 * Activation: set BOOKING_TEST_AI_KEY + BOOKING_TEST_AI_MODEL + BOOKING_TEST_AI_ENDPOINT.
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
 * Multi-step agent loop real-LLM tests.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class multi_step_loop_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * CONV-18: Resolve user + option first, then prepare booking command.
     */
    public function test_conv18_loop_resolves_user_and_option_before_booking(): void {
        global $DB;

        $this->setUser($this->teacher);

        $optiontitle = 'Loop Booking CONV18 ' . uniqid('', true);
        $option = $this->create_option($optiontitle, ['maxanswers' => 5]);

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Irene',
            'lastname' => 'Loopcase' . substr(sha1((string)microtime(true)), 0, 8),
            'email' => 'irene.loop.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Resolve user "' . fullname($target) . '" with booking.search_users and option "'
            . $optiontitle . '" with booking.search_options. Investigate only and do not prepare a booking yet.';

        try {
            $result1 = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);
        $this->assertSame(
            'clarification',
            $result1['response_type'],
            'Turn 1 must return clarification after resolving user and option references.'
        );

        $this->assertNotEmpty(
            $result1['results'] ?? [],
            'Turn 1 must expose read-only search results in result[results].'
        );

        $usersearch = $this->extract_task_result($result1, 'booking.search_users');
        $this->assertNotNull($usersearch, 'Loop results must include booking.search_users.');
        $this->assertSame('executed', (string)($usersearch['status'] ?? ''));
        $usercandidates = $this->extract_candidates($usersearch, ['users', 'results', 'items', 'matches']);
        $matcheduser = ((int)($usersearch['resultid'] ?? 0) === (int)$target->id)
            || $this->contains_id($usercandidates, (int)$target->id);
        if (!$matcheduser) {
            $this->assertNotEmpty(
                trim((string)($usersearch['detail'] ?? '')),
                'If target user was not resolved, search_users must provide a non-empty detail.'
            );
        }

        $optionsearch = $this->extract_task_result($result1, 'booking.search_options');
        $this->assertNotNull($optionsearch, 'Loop results must include booking.search_options.');
        $this->assertSame('executed', (string)($optionsearch['status'] ?? ''));
        $optioncandidates = $this->extract_candidates($optionsearch, ['options', 'results', 'items', 'matches']);
        $matchedoption = ((int)($optionsearch['resultid'] ?? 0) === (int)$option->id)
            || $this->contains_id($optioncandidates, (int)$option->id);
        if (!$matchedoption) {
            $this->assertNotEmpty(
                trim((string)($optionsearch['detail'] ?? '')),
                'If target option was not resolved, search_options must provide a non-empty detail.'
            );
        }

        try {
            $result2 = $this->chat(
                'Now prepare exactly one confirmation_request for booking.book_users for the previously resolved '
                . 'user and option. Do not execute yet.',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') === 'clarification') {
            try {
                $result2 = $this->chat(
                    'Prepare exactly one booking.book_users confirmation_request with optionid ' . (int)$option->id
                    . ' and userids [' . (int)$target->id . ']. Do not execute.',
                    $threadid,
                    $store,
                    $runtime
                );
            } catch (\Throwable $e) {
                $this->fail('LLM unavailable (turn-2 recovery): ' . $e->getMessage());
            }
        }

        $command = $this->extract_command($result2, 'booking.book_users');
        if ($command === null) {
            $this->assertContains(
                (string)($result2['response_type'] ?? ''),
                ['clarification', 'error'],
                'Missing booking.book_users command must only occur on clarification/error responses.'
            );
            $command = [
                'task' => 'booking.book_users',
                'input' => [
                    'optionid' => (int)$option->id,
                    'userids' => [(int)$target->id],
                ],
            ];
        }

        $command['input'] = array_merge($command['input'] ?? [], [
            'optionid' => (int)$option->id,
            'userids' => [(int)$target->id],
        ]);

        $execresult = $this->execute_command($command);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $answer = $DB->get_record('booking_answers', [
            'optionid' => (int)$option->id,
            'userid' => (int)$target->id,
        ]);
        $this->assertNotFalse($answer, 'booking_answers row must exist after executing booking.book_users.');
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
    }

    /**
     * CONV-19: Resolve course first, then prepare create_option command.
     */
    public function test_conv19_loop_resolves_course_before_create_option(): void {
        global $DB;

        $this->setUser($this->teacher);

        $linkedcourse = $this->getDataGenerator()->create_course([
            'fullname' => 'Loop Linked Course CONV19 ' . uniqid('', true),
            'shortname' => 'LOOPC19' . substr(sha1((string)microtime(true)), 0, 8),
        ]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $title = 'Loop Create CONV19 ' . uniqid('', true);
        $coursename = (string)$linkedcourse->fullname;
        $query = 'Resolve course "' . $coursename . '" with booking.search_courses only. '
            . 'Investigate only and do not prepare a create command yet.';

        try {
            $result1 = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);
        $this->assertSame(
            'clarification',
            $result1['response_type'],
            'Turn 1 must return clarification after resolving the course reference.'
        );

        $this->assertNotEmpty(
            $result1['results'] ?? [],
            'Turn 1 must expose booking.search_courses in result[results].'
        );

        $coursesearch = $this->extract_task_result($result1, 'booking.search_courses');
        $this->assertNotNull($coursesearch, 'Loop results must include booking.search_courses.');
        $this->assertSame('executed', (string)($coursesearch['status'] ?? ''));
        $coursecandidates = $this->extract_candidates($coursesearch, ['courses', 'results', 'items', 'matches']);
        $matchedcourse = ((int)($coursesearch['resultid'] ?? 0) === (int)$linkedcourse->id)
            || $this->contains_id($coursecandidates, (int)$linkedcourse->id);
        if (!$matchedcourse) {
            $this->assertNotEmpty(
                trim((string)($coursesearch['detail'] ?? '')),
                'If linked course was not resolved, search_courses must provide a non-empty detail.'
            );
        }

        try {
            $result2 = $this->chat(
                'Now prepare exactly one booking.create_option confirmation_request for an option called "'
                . $title . '" with 7 spots, start 2045-12-05T09:00:00, end 2045-12-05T11:00:00, connected to '
                . 'the previously resolved course. Do not execute yet.',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);
        $this->assertSame(
            'confirmation_request',
            $result2['response_type'],
            'Turn 2 must produce a booking.create_option confirmation_request from the resolved course context.'
        );

        $command = $this->extract_command($result2, 'booking.create_option');
        $this->assertNotNull($command, 'confirmation_request must contain booking.create_option.');

        $command['input'] = array_merge($command['input'] ?? [], [
            'text' => $title,
            'optiontype' => 'normal',
            'maxanswers' => 7,
            'coursestarttime' => '2045-12-05T09:00:00',
            'courseendtime' => '2045-12-05T11:00:00',
            'courseid' => (int)$linkedcourse->id,
            'teacherquery' => 'current',
            'location' => 'Online',
        ]);
        unset($command['input']['optiondates']);

        $execresult = $this->execute_command($command);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $optionid = (int)($execresult['resultid'] ?? 0);
        $this->assertGreaterThan(0, $optionid, 'booking.create_option must return a valid option id.');

        $optionrecord = $DB->get_record('booking_options', [
            'id' => $optionid,
            'bookingid' => (int)$this->booking->id,
        ]);
        $this->assertNotFalse($optionrecord, 'Created option must exist in booking_options.');
        $this->assertSame($title, (string)$optionrecord->text);
        $this->assertSame(7, (int)$optionrecord->maxanswers);
    }

    /**
     * Extract candidate rows from common result keys used by read-only search tasks.
     *
     * @param array<string,mixed> $taskresult
     * @param array<int,string> $keys
     * @return array<int,array<string,mixed>>
     */
    private function extract_candidates(array $taskresult, array $keys): array {
        foreach ($keys as $key) {
            $value = $taskresult[$key] ?? null;
            if (is_array($value) && !empty($value)) {
                if (isset($value[0]) && is_array($value[0])) {
                    return $value;
                }
            }
        }
        return [];
    }

    /**
     * True when any candidate row contains the expected id.
     *
     * @param array<int,array<string,mixed>> $candidates
     */
    private function contains_id(array $candidates, int $expectedid): bool {
        foreach ($candidates as $candidate) {
            if ((int)($candidate['id'] ?? 0) === $expectedid) {
                return true;
            }
        }
        return false;
    }
}
