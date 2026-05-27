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
 * Real-LLM regression test for core.list_actions output ordering.
 *
 * The task output should be grouped as:
 * provider -> readonly/write -> capability/task entries.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\external\ai_send_message;

/**
 * Real-LLM test for the list-actions task.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class list_actions_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();

        // This test drives webservice endpoints directly.
        $this->enforcegeneratetextassertion = false;
    }

    public function test_list_actions_groups_by_provider_then_readonly_write_then_capability(): void {
        global $DB;

        $this->setUser($this->teacher);

        $this->course->enableaitools = 1;
        $DB->update_record('course', $this->course);

        $cmrecord = $DB->get_record('course_modules', ['id' => (int)$this->booking->cmid], '*', MUST_EXIST);
        $cmrecord->enableaitools = 1;
        $DB->update_record('course_modules', $cmrecord);

        rebuild_course_cache((int)$this->course->id, true);

        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);

        $prompt = 'List the booking agent actions. Present them grouped by provider, then readonly/write, then capability. '
            . 'Show the actual task names and no repair flow.';

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$this->booking->cmid, $prompt, (int)$threadid);

        $this->assertGreaterThan(0, (int)($response['threadid'] ?? 0), 'Thread id must be present.');

        $responsetype = (string)($response['response_type'] ?? '');
        $this->assertContains(
            $responsetype,
            ['clarification', 'sufficient', 'execution_result'],
            'Unexpected initial response type: ' . $responsetype . "\n" . $this->payload_text($response)
        );

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $taskresult = $this->make_executor()->execute_commands(
            [['task' => 'core.list_actions', 'version' => 1, 'input' => []]],
            $contextid,
            (int)$this->teacher->id,
            hash('sha256', 'core.list_actions:' . uniqid('', true)),
            0
        )[0];
        $this->assertSame('executed', (string)($taskresult['status'] ?? ''));

        $actions = (array)($taskresult['actions'] ?? []);
        $this->assertNotEmpty($actions, 'Expected structured actions in the list-actions result.');

        $firstprovider = (string)($actions[0]['provider'] ?? '');
        $this->assertContains(
            $firstprovider,
            ['mod/booking', 'bookingextension/agent'],
            'Booking provider should be listed first.'
        );

        $seenexamples = false;
        foreach ($actions as $action) {
            $provider = (string)($action['provider'] ?? '');
            $readonly = (bool)($action['readonly'] ?? false);
            if ($provider === 'examples') {
                $seenexamples = true;
            }

            if ($seenexamples) {
                $this->assertSame('examples', $provider, 'Example actions should stay grouped after booking actions.');
            }
        }

        $capabilities = (array)($taskresult['capabilities'] ?? []);
        $this->assertNotEmpty($capabilities, 'Expected structured capability groups in the result.');
        $this->assertContains(
            (string)($capabilities[0]['provider'] ?? ''),
            ['mod/booking', 'bookingextension/agent']
        );
        $this->assertArrayHasKey('groups', (array)($capabilities[0] ?? []));
        $this->assertArrayHasKey('readonly', (array)($capabilities[0]['groups'] ?? []));
        $this->assertArrayHasKey('write', (array)($capabilities[0]['groups'] ?? []));

        $usermessage = (string)($taskresult['usermessage'] ?? '');
        $readonlypos = strpos($usermessage, 'readonly:');
        $writepos = strpos($usermessage, 'write:');
        $this->assertNotFalse($readonlypos, 'Expected readonly group in the user message.');
        $this->assertNotFalse($writepos, 'Expected write group in the user message.');
        $this->assertLessThan($writepos, $readonlypos, 'Readonly group should appear before write group.');
    }

    /**
     * Flatten relevant payload text for marker assertions.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    private function payload_text(array $payload): string {
        $chunks = [
            (string)($payload['message'] ?? ''),
            (string)($payload['displaymessage'] ?? ''),
            (string)($payload['resultsjson'] ?? ''),
            (string)($payload['commands'] ?? ''),
        ];

        return "\n" . implode("\n", $chunks) . "\n";
    }
}
