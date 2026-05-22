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
 * Optional real-LLM smoke tests for booking AI endpoints.
 *
 * Enabled only when BOOKING_AI_REAL_LLM=1.
 *
 * @package    bookingextension_agent
 * @category   test
 * @group      real_llm
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\conversation_store;

/**
 * Real-LLM smoke tests (opt-in).
 *
 * @coversNothing
 * @runTestsInSeparateProcesses
 */
final class agent_real_llm_test extends abstract_agent_testcase {
    /**
     * Shared setup for real-LLM tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setUser($this->teacher);
    }

    /**
     * Skip test unless a provider is configured for this context.
     */
    private function require_provider_available(): void {
        $registry = task_registry::make_default();
        $store = new conversation_store();
        $orc = new orchestrator($registry, new interpreter($registry), $store);

        if (!$orc->is_provider_available((int)$this->booking->cmid, (int)$this->teacher->id)) {
            $this->fail('Real-LLM credentials exist, but no core_ai text provider is configured/enabled for this context.');
        }
    }

    /**
     * Smoke: create prompt should not return hard error and should provide a run context.
     */
    public function test_real_llm_create_prompt_smoke(): void {
        $this->require_real_llm();
        $this->require_provider_available();

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Erstelle eine Buchungsoption namens "LLM Smoke Option" mit 7 Plaetzen, '
            . 'optiontype normal, Start 2045-11-01T09:00:00 und Ende 2045-11-01T11:00:00.'
        );

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                (int)$this->booking->cmid,
                'Bereite genau eine bestaetigungsfaehige booking.create_option Aktion vor: '
                . 'Titel "LLM Smoke Option", optiontype normal, maxanswers 7, '
                . 'coursestarttime 2045-11-01T09:00:00, courseendtime 2045-11-01T11:00:00. '
                . 'Nicht ausfuehren.'
            );
        }

        if (!empty($response['threadid'])) {
            $this->trackedllmthreadids[] = (int)$response['threadid'];
        }
        $this->assertNotEquals('error', (string)$response['response_type']);
        $this->assertGreaterThan(0, (int)$response['threadid']);
        $this->assert_generate_text_logged_for_thread((int)$response['threadid']);
    }

    /**
     * Smoke: confirming a generated command run should create a run with a non-failure state.
     */
    public function test_real_llm_confirm_run_smoke(): void {
        $this->require_real_llm();
        $this->require_provider_available();

        $response = [];
        $commandsjson = '[]';
        $commands = [];
        $prompts = [
            'Erstelle eine neue Buchungsoption mit folgenden festen Angaben:
                Titel "LLM Confirm Option", maxanswers 5, coursestarttime 2045-03-15T09:00:00, courseendtime 2045-03-15T17:00:00,
                teacherquery "current". Gib das als bestaetigbaren Befehl aus.',
            'Erstelle eine neue Buchungsoption mit Titel
                "LLM Confirm Option Retry", maxanswers 6, coursestarttime 2045-03-16T09:00:00,
                courseendtime 2045-03-16T17:00:00 und teacherquery "current". Gib nur einen bestaetigbaren Befehl aus.',
        ];

        foreach ($prompts as $prompt) {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute((int)$this->booking->cmid, $prompt);
            if (!empty($response['threadid'])) {
                $this->trackedllmthreadids[] = (int)$response['threadid'];
            }

            $commandsjson = (string)($response['commands'] ?? '[]');
            $commands = json_decode($commandsjson, true);
            if (is_array($commands) && !empty($commands)) {
                break;
            }
        }

        $this->assertIsArray($commands, 'commands must decode to an array.');
        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            ['confirm_pending', 'confirmation_request'],
            'Confirm smoke expects a confirmation response type from ai_send_message.'
        );
        $this->assertNotEmpty(
            $commands,
            'Confirm smoke expects non-empty commands for ai_confirm_run execution.'
        );

        $_POST['sesskey'] = sesskey();
        $confirm = ai_confirm_run::execute(
            (int)$this->booking->cmid,
            (int)$response['threadid'],
            $commandsjson
        );

        $this->assertTrue((bool)$confirm['success']);
        $this->assertGreaterThan(0, (int)$confirm['runid']);

        $this->assertContains((string)($confirm['response_type'] ?? ''), [
            'confirmation_request',
            'clarification',
            'sufficient',
            'execution_result',
            'error',
            'queued',
            'task_call',
            'confirm_pending',
        ]);
        $this->assert_generate_text_logged_for_thread((int)$response['threadid']);
    }

    /**
     * Smoke: read-only search should not return hard errors.
     */
    public function test_real_llm_search_prompt_smoke(): void {
        $this->require_real_llm();
        $this->require_provider_available();

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Zeige mir die vorhandenen Buchungsoptionen.'
        );

        if (!empty($response['threadid'])) {
            $this->trackedllmthreadids[] = (int)$response['threadid'];
        }
        $this->assertNotEquals('error', (string)$response['response_type']);
        $this->assertGreaterThan(0, (int)$response['threadid']);
        $this->assert_generate_text_logged_for_thread((int)$response['threadid']);
    }
}
