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
 * Base testcase for simulated-LLM agent tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Shared helpers for deterministic agent tests with scripted orchestrator output.
 */
abstract class abstract_simulated_llm_testcase extends abstract_agent_testcase {
    /**
     * Build a runtime that returns scripted orchestrator responses.
     *
     * @param array<int,array<string,mixed>> $responses
     * @return array{0: conversation_store, 1: agent_runtime, 2: int}
     */
    protected function build_scripted_runtime(array $responses): array {
        $store = new conversation_store();
        $registry = task_registry::make_default();
        $authz = new authorization_service();

        $index = 0;
        $fallback = end($responses) ?: self::clarification_response('No scripted response.');

        $mockorchestrator = $this->getMockBuilder(orchestrator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockorchestrator->method('process')->willReturnCallback(
            static function () use (&$index, $responses, $fallback): array {
                $response = $responses[$index] ?? $fallback;
                $index++;
                return $response;
            }
        );

        $runtime = new agent_runtime($registry, $mockorchestrator, $store, $authz);
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );

        return [$store, $runtime, (int)$thread->id];
    }

    /**
     * Build a scripted clarification response.
     *
     * @param string $message
     * @return array<string,mixed>
     */
    protected static function clarification_response(string $message): array {
        return [
            'response_type' => 'clarification',
            'lang' => 'en',
            'message' => $message,
            'used_triggers' => [],
            'commands' => [],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => [],
            'issue_codes' => [],
        ];
    }

    /**
     * Build a scripted confirmation response for one command.
     *
     * @param string $task
     * @param array<string,mixed> $input
     * @param string $message
     * @return array<string,mixed>
     */
    protected static function confirmation_response(string $task, array $input, string $message): array {
        return [
            'response_type' => 'confirmation_request',
            'lang' => 'en',
            'message' => $message,
            'used_triggers' => [],
            'commands' => [[
                'task' => $task,
                'version' => 1,
                'input' => $input,
            ]],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => [],
            'issue_codes' => [],
        ];
    }

    /**
     * Build a scripted task_call response for one command.
     *
     * @param string $task
     * @param array<string,mixed> $input
     * @param string $message
     * @return array<string,mixed>
     */
    protected static function task_call_response(string $task, array $input, string $message): array {
        return [
            'response_type' => 'task_call',
            'lang' => 'en',
            'message' => $message,
            'used_triggers' => [],
            'commands' => [[
                'task' => $task,
                'version' => 1,
                'input' => $input,
            ]],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'attempted_tasks' => [],
            'issue_codes' => [],
        ];
    }
}
