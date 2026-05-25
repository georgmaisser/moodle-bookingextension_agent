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
 * Real-LLM phase-7 scenario tests for third-party example tasks.
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
use context_module;

/**
 * Real provider tests for phase-7 scenario examples A/B/C.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class phase7_examples_real_llm_test extends abstract_agent_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->ensure_contextid_columns_for_legacy_phpunit_schema();
        $this->require_real_llm();

        // This test drives webservice endpoints directly.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Ensure legacy PHPUnit schemas contain contextid columns expected by contextid-first code.
     *
     * Some local environments still provide old cmid-based table variants in the
     * PHPUnit DB. This helper upgrades those tables in-test so the real-LLM
     * scenarios can run against the current contract without touching production DB schema.
     *
     * @return void
     */
    private function ensure_contextid_columns_for_legacy_phpunit_schema(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $targets = [
            'local_wbagent_ai_threads',
            'local_wbagent_ai_runs',
            'local_wbagent_ai_llm_debug',
        ];

        foreach ($targets as $tablename) {
            $table = new \xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                continue;
            }

            $contextfield = new \xmldb_field('contextid');
            if ($dbman->field_exists($table, $contextfield)) {
                continue;
            }

            $addfield = new \xmldb_field(
                'contextid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'userid'
            );
            $dbman->add_field($table, $addfield);

            $cmidfield = new \xmldb_field('cmid');
            if ($dbman->field_exists($table, $cmidfield)) {
                $DB->execute('UPDATE {' . $tablename . '} SET contextid = cmid WHERE contextid = 0');
            }
        }
    }

    /**
     * Scenario A: readonly example should execute and emit the phase marker.
     */
    public function test_scenario_a_readonly_example_executes_with_real_llm(): void {
        $this->setUser($this->teacher);
        $contextid = $this->get_booking_contextid();

        $result = $this->run_scenario_until_done(
            $contextid,
            [
                'Nutze ausschliesslich die Task examples.phase7_readonly_example und fuehre sie jetzt aus. '
                    . 'Input: query="phase7-a readonly", limit=2.',
                'Gib genau einen task_call aus: task="examples.phase7_readonly_example", '
                    . 'version=1, input={"query":"phase7-a readonly","limit":2}.',
                'Keine Rueckfrage, keine Zusammenfassung. Fuehre nur examples.phase7_readonly_example '
                    . 'mit query="phase7-a readonly" und limit=2 aus.',
                'Return only one command for execution: '
                    . 'task=examples.phase7_readonly_example version=1 input.query="phase7-a readonly" input.limit=2.',
            ],
            'examples.phase7_readonly_example',
            []
        );

        $this->assertTrue($result['success'], $result['debug']);
    }

    /**
     * Scenario B: multistep example should complete and emit the phase marker.
     */
    public function test_scenario_b_multistep_example_executes_with_real_llm(): void {
        $this->setUser($this->teacher);
        $contextid = $this->get_booking_contextid();

        $result = $this->run_scenario_until_done(
            $contextid,
            [
                'Nutze ausschliesslich die Task examples.phase7_multistep_example und fuehre sie aus. '
                    . 'Input: objective="phase7-b", steps=["collect","validate","summarize"].',
                'Gib genau einen bestaetigbaren task_call aus: task="examples.phase7_multistep_example", '
                    . 'version=1, input={"objective":"phase7-b","steps":["collect","validate","summarize"]}.',
            ],
            'examples.phase7_multistep_example',
            ['[PHASE7-B]']
        );

        $this->assertTrue($result['success'], $result['debug']);
    }

    /**
     * Scenario C: spawn parent example should run and include parent+child markers.
     */
    public function test_scenario_c_spawn_example_executes_with_real_llm(): void {
        $this->setUser($this->teacher);
        $contextid = $this->get_booking_contextid();

        $result = $this->run_scenario_until_done(
            $contextid,
            [
                'Nutze ausschliesslich die Task examples.phase7_spawn_parent_example und fuehre sie aus. '
                    . 'Input: batch_label="phase7-c", child_count=2.',
                'Gib genau einen bestaetigbaren task_call aus: task="examples.phase7_spawn_parent_example", '
                    . 'version=1, input={"batch_label":"phase7-c","child_count":2}.',
            ],
            'examples.phase7_spawn_parent_example',
            ['[PHASE7-C-PARENT]', '[PHASE7-C-CHILD]']
        );

        $this->assertTrue($result['success'], $result['debug']);
    }

    /**
     * Run one scenario with retries until terminal state or failure.
     *
     * @param int $contextid
     * @param array<int,string> $prompts Prompt sequence (initial + repair prompts).
     * @param string $expectedtaskname
     * @param array<int,string> $requiredmarkers
     * @return array{success:bool,debug:string}
     */
    private function run_scenario_until_done(
        int $contextid,
        array $prompts,
        string $expectedtaskname,
        array $requiredmarkers
    ): array {
        $trace = [];
        $seentasks = [];
        $aggregate = '';
        $threadid = 0;

        if (empty($prompts)) {
            return [
                'success' => false,
                'debug' => 'No prompts provided.',
            ];
        }

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute($contextid, (string)$prompts[0], 0);
        $threadid = (int)($response['threadid'] ?? 0);
        $trace[] = $this->trace_line('send', 0, $response);
        $aggregate .= $this->payload_text($response);
        $this->collect_tasks($response, $seentasks);

        $repairindex = 1;

        for ($step = 1; $step <= 10; $step++) {
            $responsetype = trim((string)($response['response_type'] ?? ''));

            if ($responsetype === 'sufficient' || $responsetype === 'execution_result' || $responsetype === 'clarification') {
                $hastaskevidence = $this->has_task($seentasks, $expectedtaskname)
                    || strpos($aggregate, $expectedtaskname) !== false;
                if (!$hastaskevidence) {
                    return [
                        'success' => false,
                        'debug' => 'Expected task never seen. ' . implode(' | ', $trace),
                    ];
                }

                foreach ($requiredmarkers as $marker) {
                    if (strpos($aggregate, $marker) === false) {
                        return [
                            'success' => false,
                            'debug' => 'Missing marker ' . $marker . '. ' . implode(' | ', $trace),
                        ];
                    }
                }

                return [
                    'success' => true,
                    'debug' => implode(' | ', $trace),
                ];
            }

            if ($responsetype === 'confirmation_request' || $responsetype === 'task_call' || $responsetype === 'confirm_pending') {
                $queueitemid = trim((string)($response['queueitemid'] ?? ''));
                if ($queueitemid === '') {
                    return [
                        'success' => false,
                        'debug' => 'Missing queue item id. ' . implode(' | ', $trace),
                    ];
                }

                $_POST['sesskey'] = sesskey();
                $response = ai_confirm_run::execute($contextid, $threadid, $queueitemid, true);
                $trace[] = $this->trace_line('confirm', $step, $response);
                $aggregate .= $this->payload_text($response);
                $this->collect_tasks($response, $seentasks);
                continue;
            }

            if ($responsetype === 'clarification' || $responsetype === 'error') {
                if (!isset($prompts[$repairindex])) {
                    return [
                        'success' => false,
                        'debug' => 'No repair prompt left. ' . implode(' | ', $trace),
                    ];
                }

                $_POST['sesskey'] = sesskey();
                $response = ai_send_message::execute($contextid, (string)$prompts[$repairindex], $threadid);
                $trace[] = $this->trace_line('repair_send', $step, $response);
                $aggregate .= $this->payload_text($response);
                $this->collect_tasks($response, $seentasks);
                $repairindex++;
                continue;
            }

            return [
                'success' => false,
                'debug' => 'Unexpected response type: ' . $responsetype . '. ' . implode(' | ', $trace),
            ];
        }

        return [
            'success' => false,
            'debug' => 'Loop limit reached. ' . implode(' | ', $trace),
        ];
    }

    /**
     * Return module context id for the shared booking fixture.
     *
     * @return int
     */
    private function get_booking_contextid(): int {
        global $DB;

        $record = $DB->get_record(
            'context',
            [
                'contextlevel' => CONTEXT_MODULE,
                'instanceid' => (int)$this->booking->cmid,
            ],
            'id',
            MUST_EXIST
        );

        return (int)$record->id;
    }

    /**
     * Collect task names from the commands payload.
     *
     * @param array<string,mixed> $payload
     * @param array<int,string> $seen
     * @return void
     */
    private function collect_tasks(array $payload, array &$seen): void {
        $commandsraw = $payload['commands'] ?? '[]';
        $commands = [];

        if (is_string($commandsraw)) {
            $decoded = json_decode($commandsraw, true);
            if (is_array($decoded)) {
                $commands = $decoded;
            }
        } else if (is_array($commandsraw)) {
            $commands = $commandsraw;
        }

        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $task = trim((string)($command['task'] ?? ''));
            if ($task !== '' && !in_array($task, $seen, true)) {
                $seen[] = $task;
            }
        }
    }

    /**
     * @param array<int,string> $seentasks
     * @param string $expectedtaskname
     * @return bool
     */
    private function has_task(array $seentasks, string $expectedtaskname): bool {
        return in_array($expectedtaskname, $seentasks, true);
    }

    /**
     * Build compact trace output.
     *
     * @param string $phase
     * @param int $step
     * @param array<string,mixed> $payload
     * @return string
     */
    private function trace_line(string $phase, int $step, array $payload): string {
        $responsetype = trim((string)($payload['response_type'] ?? ''));
        $queueitemid = trim((string)($payload['queueitemid'] ?? ''));
        $message = trim((string)($payload['displaymessage'] ?? $payload['message'] ?? ''));

        return $phase . '[' . $step . ']'
            . ': type=' . $responsetype
            . ' queue=' . ($queueitemid === '' ? '-' : $queueitemid)
            . ' msg=' . $message;
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
