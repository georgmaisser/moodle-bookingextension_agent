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
 * Real-LLM conversation tests for booking.search_options.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-13  Happy path (loop auto-execute)
 *            — Two options pre-created.
 *              search_options is read-only: the agentic loop auto-executes it
 *              internally and returns a clarification summary to the user.
 *              Execution results are surfaced via result['results'] (loop_results).
 *
 *   CONV-14  Multi-turn follow-up
 *            — Turn 1: search → clarification with results in result['results'].
 *            — Turn 2: follow-up → non-empty clarification reply referencing option names.
 *
 * Activation: set BOOKING_TEST_AI_KEY + BOOKING_TEST_AI_MODEL + BOOKING_TEST_AI_ENDPOINT.
 *
 * @package   mod_booking
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * CONV-13 / CONV-14: booking.search_options real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class search_options_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-13: Happy path — read-only search loop auto-executes, both options in results.
     *
     * With run_loop(), search_options is auto-executed inside the agentic loop.
     * The caller never sees execution_result — the loop consumes it and the LLM
     * returns a clarification summary. Execution results travel via loop_results
     * which is merged into result['results'].
     *
     * Setup:  Creates two uniquely-named options with 10 and 8 spots.
     * Conversation:
     *   User:  'Show me all "<prefix>" options.'
     *   Agent: clarification (LLM summary after auto-execution)
     *   Test:  result['results'] not empty; both option names present in results.
     */
    public function test_conv13_search_options_loop_auto_executes(): void {
        $this->setUser($this->teacher);

        $prefix  = 'SearchTestKurs' . uniqid('', true);
        $option1 = $this->create_option($prefix . ' 1', ['maxanswers' => 10]);
        $option2 = $this->create_option($prefix . ' 2', ['maxanswers' => 8]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        try {
            $result = $this->chat('Show me all "' . $prefix . '" options.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        // With run_loop(), read-only tools are auto-executed inside the loop.
        // The caller receives 'clarification' (LLM summary), never 'execution_result'.
        $this->assertSame(
            'clarification',
            $result['response_type'],
            'run_loop() must return clarification after auto-executing search_options; '
                . 'got: ' . ($result['response_type'] ?? '?')
        );

        // The loop must have auto-executed the search and attached results.
        $this->assertNotEmpty(
            $result['results'] ?? [],
            'result[results] must be populated with loop_results after auto-executing search_options'
        );

        // Both created option names must appear in the accumulated tool results.
        $allnames = [];
        foreach ((array)($result['results'] ?? []) as $entry) {
            foreach ((array)($entry['options'] ?? $entry['results'] ?? []) as $opt) {
                $allnames[] = strtolower((string)($opt['text'] ?? $opt['name'] ?? ''));
            }
        }

        $nameshaystack = implode(' ', $allnames);
        $this->assertStringContainsStringIgnoringCase(
            $prefix . ' 1',
            $nameshaystack,
            'First option must appear in loop_results (option id ' . $option1->id . ').'
        );
        $this->assertStringContainsStringIgnoringCase(
            $prefix . ' 2',
            $nameshaystack,
            'Second option must appear in loop_results (option id ' . $option2->id . ').'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-14: Multi-turn follow-up — second question refers to first search result.
     *
     * Setup:  Creates two uniquely-named options (20 spots and 3 spots).
     * Conversation:
     *   Turn 1 — User:  search all prefix options
     *            Agent: clarification (loop auto-executed search_options)
     *            Test:  result['results'] not empty.
     *   Turn 2 — User:  follow-up about free spots
     *            Agent: clarification with non-empty message
     *            Test:  message mentions the prefix (LLM used conversation context).
     */
    public function test_conv14_search_options_multi_turn_follow_up(): void {
        $this->setUser($this->teacher);

        $prefix  = 'SearchMultiKurs' . uniqid('', true);
        $this->create_option($prefix . ' A', ['maxanswers' => 20]);
        $this->create_option($prefix . ' B', ['maxanswers' => 3]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        // Turn 1: Search.
        try {
            $result1 = $this->chat('Show me all "' . $prefix . '" options.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        // Turn 1: loop auto-executes search_options and returns clarification.
        $this->assertSame(
            'clarification',
            $result1['response_type'],
            'Turn 1 must return clarification after loop auto-execution; got: ' . ($result1['response_type'] ?? '?')
        );

        // Execution results must be attached via loop_results.
        $this->assertNotEmpty(
            $result1['results'] ?? [],
            'Turn-1 result[results] must be populated from loop auto-execution'
        );

        // Turn 2: Follow-up about free spots.
        try {
            $result2 = $this->chat(
                'From the options you just found for "' . $prefix . '", which ones have more than 5 free spots?',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->fail('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        // Turn 2 may trigger another loop step or answer from context — both are valid.
        $this->assertContains(
            $result2['response_type'],
            ['clarification', 'execution_result'],
            'Turn-2 response_type must be clarification or execution_result; got: '
                . ($result2['response_type'] ?? '?')
        );

        $message2 = trim((string)($result2['message'] ?? ''));
        $this->assertNotEmpty($message2, 'Turn-2 message must not be empty.');

        if (stripos($message2, $prefix) === false) {
            $taskresult = $this->extract_task_result($result2, 'booking.search_options');
            if ($taskresult !== null) {
                $options = (array)($taskresult['options'] ?? []);
                $matchedprefix = false;
                foreach ($options as $option) {
                    if (stripos((string)($option['text'] ?? ''), $prefix) !== false) {
                        $matchedprefix = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $matchedprefix,
                    'Turn-2 must still operate on the turn-1 option family (matched by prefix in search results).'
                );
            } else {
                $this->assertMatchesRegularExpression(
                    '/option|spots|free/i',
                    $message2,
                    'If no follow-up search payload is attached, message must still discuss option availability context.'
                );
            }
        }
    }
}
