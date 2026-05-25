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
 * Real-LLM regression test for booking.list_actions output ordering.
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

        $responseType = (string)($response['response_type'] ?? '');
        $this->assertContains(
            $responseType,
            ['clarification', 'sufficient', 'execution_result'],
            'Unexpected initial response type: ' . $responseType . "\n" . $this->payload_text($response)
        );

        $finalresponse = $response;

        $payload = $this->payload_text($finalresponse);
        $this->assertStringContainsString('booking (provider)', $payload);
        $this->assertStringContainsString('examples (provider)', $payload);
        $this->assertStringContainsString('readonly:', $payload);
        $this->assertStringContainsString('write:', $payload);

        $providerpos = strpos($payload, 'booking (provider)');
        $examplespos = strpos($payload, 'examples (provider)');
        $readonlypos = strpos($payload, "readonly:", $providerpos === false ? 0 : $providerpos);
        $writepos = strpos($payload, "write:", $providerpos === false ? 0 : $providerpos);

        $this->assertNotFalse($providerpos, 'Expected provider block in output.');
        $this->assertNotFalse($examplespos, 'Expected examples provider block in output.');
        $this->assertNotFalse($readonlypos, 'Expected readonly section in output.');
        $this->assertNotFalse($writepos, 'Expected write section in output.');

        $this->assertLessThan($examplespos, $providerpos, 'Booking provider block should appear before examples provider block.');
        $this->assertLessThan($writepos, $readonlypos, 'Readonly heading should appear before write heading.');

        $this->assertStringContainsString('booking.list_actions', $payload);
        $this->assertStringContainsString('booking.recreate_task_catalog', $payload);
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