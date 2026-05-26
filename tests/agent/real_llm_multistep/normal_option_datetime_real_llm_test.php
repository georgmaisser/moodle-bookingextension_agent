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
 * Real-LLM regression test for normal option routing with date/time prompts.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_send_message;

/**
 * Ensures single-event date/time prompts use normal option task (type 0).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class normal_option_datetime_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();

        // This test drives webservice endpoints directly.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Prompt should route to mod_booking.create_option_normal and persist type 0.
     */
    public function test_datetime_prompt_routes_to_create_option_normal_and_type_zero(): void {
        global $DB;

        $this->setUser($this->teacher);

        if (!$this->is_task_available('mod_booking.create_option_normal')) {
            $this->markTestSkipped('mod_booking.create_option_normal is not available in the current task catalog.');
        }

        // Keep test deterministic when rerun.
        $DB->delete_records('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => 'Buch 1',
        ]);

        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$this->booking->cmid, $threadid);

        $prompt = 'Erstelle eine Buchungsmöglichkeit mit dem Titel "Buch 1", für höchstens fünf Personen. '
            . 'Stattfinden soll sie übermorgen, von 10 bis 12h.';

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$this->booking->cmid, $prompt, (int)$threadid);

        $this->assertSame(
            'confirmation_request',
            (string)($response['response_type'] ?? ''),
            'Expected confirmation_request for mutating prompt. Payload: ' . $this->payload_text($response)
        );

        $normalcommand = $this->extract_command_from_payload($response, 'mod_booking.create_option_normal');
        $slotcommand = $this->extract_command_from_payload($response, 'mod_booking.create_option_slotbooking');

        $this->assertNotNull(
            $normalcommand,
            'Expected task mod_booking.create_option_normal in command payload. Payload: ' . $this->payload_text($response)
        );
        $this->assertNull(
            $slotcommand,
            'Did not expect task mod_booking.create_option_slotbooking for this prompt. Payload: ' . $this->payload_text($response)
        );

        $confirm = $this->confirm_pending_result($response, (int)$threadid, $store, false);
        $this->assertTrue((bool)($confirm['success'] ?? false), 'Confirmation failed. Payload: ' . $this->payload_text($confirm));
        $this->assertContains(
            (string)($confirm['response_type'] ?? ''),
            ['sufficient', 'execution_result'],
            'Unexpected confirm response type. Payload: ' . $this->payload_text($confirm)
        );

        $option = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => 'Buch 1',
        ]);
        $this->assertNotFalse($option, 'Expected created booking option "Buch 1".');
        $this->assertSame(0, (int)$option->type, 'Expected normal option type 0.');
    }

    /**
     * Check if a task currently exists in the registry.
     *
     * @param string $taskname
     * @return bool
     */
    private function is_task_available(string $taskname): bool {
        $registry = \bookingextension_agent\local\wbagent\task_registry_factory::get_default();
        return $registry->get_task($taskname) !== null;
    }

    /**
     * Extract first command by task name from endpoint payload.
     *
     * @param array<string,mixed> $payload
     * @param string $taskname
     * @return array<string,mixed>|null
     */
    private function extract_command_from_payload(array $payload, string $taskname): ?array {
        $commands = $this->decode_commands_from_payload($payload);
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if ((string)($command['task'] ?? '') === $taskname) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Decode commands array from endpoint payload.
     *
     * @param array<string,mixed> $payload
     * @return array<int,mixed>
     */
    private function decode_commands_from_payload(array $payload): array {
        $commandsraw = $payload['commands'] ?? [];
        if (is_array($commandsraw)) {
            return $commandsraw;
        }

        if (is_string($commandsraw)) {
            $decoded = json_decode($commandsraw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Flatten relevant payload text for assertion debugging.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    private function payload_text(array $payload): string {
        $commands = $payload['commands'] ?? '';
        if (is_array($commands)) {
            $commands = json_encode($commands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $chunks = [
            (string)($payload['response_type'] ?? ''),
            (string)($payload['message'] ?? ''),
            (string)($payload['displaymessage'] ?? ''),
            (string)$commands,
            (string)($payload['resultsjson'] ?? ''),
        ];

        return "\n" . implode("\n", $chunks) . "\n";
    }
}
