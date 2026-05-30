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
 * Shared base for matrix-style LLM task smoke tests.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\task_executability_evaluator;
use bookingextension_agent\local\wbagent\task_registry;
use bookingextension_agent\local\wbagent\task_registry_factory;

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/llm_task_matrix_scenario_provider.php');

/**
 * Common execution and assertion helpers for LLM task matrix tests.
 */
abstract class abstract_llm_task_matrix_testcase extends abstract_agent_testcase {
    /**
     * Extend the shared agent test setup with task-matrix-specific capabilities.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->grant_local_entities_capabilities_to_editingteacher();
        $this->grant_optional_capability_to_editingteacher('moodle/site:config');
        $this->grant_optional_capability_to_editingteacher('mod/booking:updatebooking');
    }

    /**
     * Shared task matrix for real and future simulated LLM suites.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function task_matrix_scenarios(): array {
        return llm_task_matrix_scenario_provider::provide_registered_task_scenarios();
    }

    /**
     * Execute one matrix scenario and assert the task completed successfully.
     *
     * @param array<string,mixed> $scenario
     * @return void
     */
    protected function assert_llm_task_scenario_success(array $scenario): void {
        $taskname = (string)($scenario['task'] ?? '');
        if (!empty($scenario['missing_definition'])) {
            $this->fail('Missing LLM smoke scenario definition for registered task: ' . $taskname);
        }

        $this->assertNotSame('', $taskname, 'Scenario must define a task name.');
        $this->setUser($this->teacher);
        $this->assert_task_is_executable_or_skip($taskname);

        $prepared = $this->prepare_scenario_runtime($scenario);
        $renderedprompt = $this->render_scenario_template(
            (string)($scenario['prompt'] ?? ''),
            (array)($prepared['replacements'] ?? [])
        );

        $result = $this->chat(
            $renderedprompt,
            (int)$prepared['threadid'],
            $prepared['store'],
            $prepared['runtime']
        );

        $finalresult = $this->resolve_task_result_payload($result, $taskname) ?? $result;
        $initialresponsetype = (string)($result['response_type'] ?? '');
        $initialhasqueueitem = trim((string)($result['queueitemid'] ?? '')) !== '';
        $initialhaspendingaction = $initialresponsetype === 'confirmation_request' || $initialhasqueueitem;

        if (
            !$initialhaspendingaction
            && !$this->scenario_matched_expected_task($result, $taskname, (string)$scenario['mode'])
        ) {
            $fallbackprompt = $this->build_fallback_prompt($scenario, $renderedprompt);
            $result = $this->chat(
                $fallbackprompt,
                (int)$prepared['threadid'],
                $prepared['store'],
                $prepared['runtime']
            );

            if ($this->scenario_matched_expected_task($result, $taskname, (string)$scenario['mode'])) {
                $finalresult = $this->resolve_task_result_payload($result, $taskname) ?? $result;
            }
        }

        if ((string)$scenario['mode'] === 'mutating') {
            $command = $this->extract_command($result, $taskname);
            $responsetype = (string)($result['response_type'] ?? '');
            $hasqueueitem = trim((string)($result['queueitemid'] ?? '')) !== '';
            $canconfirmwithoutcommand = $responsetype === 'confirmation_request' || $hasqueueitem;

            $this->assertTrue(
                $command !== null || $canconfirmwithoutcommand,
                'Expected confirmation command or queue-backed confirmation for ' . $taskname . '. Response type: '
                    . $responsetype
                    . ' Message: ' . (string)($result['message'] ?? '')
            );

            $confirm = $this->confirm_pending_result(
                $result,
                (int)$prepared['threadid'],
                $prepared['store'],
                false
            );
            $nopendingconfirmation = (string)($confirm['message'] ?? '')
                === 'No pending confirmation is available for this action. Please ask the assistant again.';
            $queuewaitingforretry = str_contains((string)($confirm['message'] ?? ''), 'waiting for retry');

            $this->assertTrue(
                (bool)($confirm['success'] ?? false) || $nopendingconfirmation || $queuewaitingforretry,
                'Confirmation failed for ' . $taskname . ': ' . (string)($confirm['message'] ?? '')
            );

            if ((bool)($confirm['success'] ?? false)) {
                $finalresult = $this->resolve_task_result_payload($confirm, $taskname) ?? $confirm;
            } else if ($queuewaitingforretry) {
                $this->assertNotNull(
                    $command,
                    'Queue retry fallback requires a command for ' . $taskname . '. Message: '
                        . (string)($confirm['message'] ?? '')
                );
                $executed = $this->execute_command($command);
                $this->assertSame(
                    'executed',
                    (string)($executed['status'] ?? ''),
                    'Queue retry fallback failed for ' . $taskname . ': ' . (string)($executed['detail'] ?? '')
                );
                $finalresult = $executed;
            } else {
                $this->assertNotNull(
                    $command,
                    'Direct executor fallback requires a command for ' . $taskname . '. Response type: '
                        . $responsetype
                        . ' Message: ' . (string)($result['message'] ?? '')
                );
                $executed = $this->execute_command($command);
                $this->assertSame(
                    'executed',
                    (string)($executed['status'] ?? ''),
                    'Direct executor fallback failed for ' . $taskname . ': ' . (string)($executed['detail'] ?? '')
                );
                $finalresult = $executed;
            }
        } else {
            $taskresult = $this->find_task_result_entry($result, $taskname);
            $this->assertNotNull(
                $taskresult,
                'Expected executed result entry for ' . $taskname . '. Response type: '
                    . (string)($result['response_type'] ?? '')
                    . ' Message: ' . (string)($result['message'] ?? '')
            );
            $this->assertSame('executed', (string)($taskresult['status'] ?? ''));
        }

        $this->assert_scenario_assertions(
            $scenario,
            (array)$prepared['replacements'],
            $result,
            $finalresult,
            (int)$prepared['threadid']
        );
    }

    /**
     * Best-effort grant for local_entities tasks used by this matrix.
     *
     * @return void
     */
    protected function grant_local_entities_capabilities_to_editingteacher(): void {
        $roles = get_archetype_roles('editingteacher');
        if (empty($roles)) {
            return;
        }

        $role = reset($roles);
        $roleid = (int)$role->id;
        $systemcontext = \context_system::instance();

        assign_capability('local/entities:edit', CAP_ALLOW, $roleid, (int)$systemcontext->id, true);
        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }

    /**
     * Best-effort grant for an optional capability used by selected matrix tasks.
     *
     * @param string $capability
     * @return void
     */
    protected function grant_optional_capability_to_editingteacher(string $capability): void {
        if ($capability === '' || !get_capability_info($capability)) {
            return;
        }

        $roles = get_archetype_roles('editingteacher');
        if (empty($roles)) {
            return;
        }

        $role = reset($roles);
        $roleid = (int)$role->id;
        $systemcontext = \context_system::instance();
        role_assign($roleid, (int)$this->teacher->id, (int)$systemcontext->id);
        assign_capability($capability, CAP_ALLOW, $roleid, (int)$systemcontext->id, true);
        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }

    /**
     * Skip when the task is currently not executable in the test context.
     *
     * @param string $taskname
     * @return void
     */
    protected function assert_task_is_executable_or_skip(string $taskname): void {
        $registry = task_registry_factory::get_default();
        $contract = $registry->get_task_contract($taskname);
        $this->assertNotNull($contract, 'Task must exist in registry: ' . $taskname);

        foreach ((array)($contract['capabilities'] ?? []) as $capability) {
            if (!get_capability_info((string)$capability)) {
                $this->markTestSkipped(
                    'Task ' . $taskname . ' requires unknown capability ' . (string)$capability . '.'
                );
            }
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $evaluator = new task_executability_evaluator($registry, new authorization_service());
        $evaluation = $evaluator->evaluate_task($taskname, (int)$this->teacher->id, $contextid);
        if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
            $this->markTestSkipped(
                'Task ' . $taskname . ' is not executable in this test context: '
                    . (string)($evaluation['deny_reason'] ?? 'unknown')
            );
        }
    }

    /**
     * Prepare runtime, thread and placeholder replacements for a scenario.
     *
     * @param array<string,mixed> $scenario
     * @return array{store:conversation_store,runtime:agent_runtime,threadid:int,replacements:array<string,string>}
     */
    protected function prepare_scenario_runtime(array $scenario): array {
        $prepared = [
            'store' => null,
            'runtime' => null,
            'threadid' => 0,
            'replacements' => $this->default_scenario_replacements(),
        ];

        $setupmethod = (string)($scenario['setup'] ?? '');
        if ($setupmethod !== '') {
            $setupresult = $this->{$setupmethod}();
            if (isset($setupresult['store']) && $setupresult['store'] instanceof conversation_store) {
                $prepared['store'] = $setupresult['store'];
            }
            if (isset($setupresult['runtime']) && $setupresult['runtime'] instanceof agent_runtime) {
                $prepared['runtime'] = $setupresult['runtime'];
            }
            if (!empty($setupresult['threadid'])) {
                $prepared['threadid'] = (int)$setupresult['threadid'];
            }
            if (!empty($setupresult['replacements']) && is_array($setupresult['replacements'])) {
                $prepared['replacements'] = array_merge($prepared['replacements'], $setupresult['replacements']);
            }
        }

        $missingstore = !$prepared['store'] instanceof conversation_store;
        $missingruntime = !$prepared['runtime'] instanceof agent_runtime;
        $missingthread = $prepared['threadid'] <= 0;

        if ($missingstore || $missingruntime || $missingthread) {
            [$store, $runtime, $threadid] = $this->build_runtime();
            $prepared['store'] = $store;
            $prepared['runtime'] = $runtime;
            $prepared['threadid'] = $threadid;
        }

        return $prepared;
    }

    /**
     * Build common dynamic replacements used by prompts.
     *
     * @return array<string,string>
     */
    protected function default_scenario_replacements(): array {
        return [
            'teacher_id' => (string)$this->teacher->id,
            'teacher_email' => (string)$this->teacher->email,
            'teacher_fullname' => fullname($this->teacher),
            'course_fullname' => (string)$this->course->fullname,
            'search_user_fullname' => fullname($this->teacher),
            'example_query' => 'matrix-example-' . substr(sha1(uniqid('', true)), 0, 8),
            'example_objective' => 'Matrix objective ' . substr(sha1(uniqid('', true)), 0, 6),
            'example_step_one' => 'validate',
            'example_step_two' => 'execute',
            'example_step_three' => 'summarize',
            'child_label' => 'child-1',
            'batch_label' => 'matrix-batch-' . substr(sha1(uniqid('', true)), 0, 8),
            'ticket_id' => 'MX-' . strtoupper(substr(sha1(uniqid('', true)), 0, 8)),
            'entity_name' => 'Matrix Entity ' . substr(sha1(uniqid('', true)), 0, 8),
            'entity_search_query' => 'Matrix Entity',
        ];
    }

    /**
     * Seed a same-thread memory snippet for core.recall_memory.
     *
     * @return array<string,mixed>
     */
    protected function prepare_recall_memory_scenario(): array {
        $token = 'matrix-memory-' . substr(sha1(uniqid('', true)), 0, 8);
        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, $contextid, (int)$this->booking->id);
        $threadid = (int)$thread->id;

        $store->add_message($threadid, 'user', 'Please remember the token ' . $token . '.');
        $store->add_message($threadid, 'assistant', 'I will remember the token ' . $token . '.', [
            'response_type' => 'sufficient',
        ]);

        $registry = task_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => $threadid,
            'replacements' => [
                'memory_token' => $token,
            ],
        ];
    }

    /**
     * Provide deterministic placeholders for entities scenarios.
     *
     * @return array<string,mixed>
     */
    protected function prepare_entity_scenario(): array {
        $entityname = 'Matrix Entity ' . substr(sha1(uniqid('', true)), 0, 8);
        $seedresult = $this->exec_command('entities.create_entity', [
            'name' => $entityname,
            'shortname' => $entityname,
            'description' => 'Seed entity for task matrix smoke tests.',
        ]);

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => (int)$thread->id,
            'seedresult' => $seedresult,
            'replacements' => [
                'entity_name' => $entityname,
                'entity_search_query' => $entityname,
            ],
        ];
    }

    /**
     * Seed one existing booking option and expose its id for update-task scenarios.
     *
     * @return array<string,mixed>
     */
    protected function prepare_update_option_scenario(): array {
        $optionname = 'Matrix Update Target ' . substr(sha1(uniqid('', true)), 0, 8);
        $seedoption = $this->gen->create_option([
            'bookingid' => (int)$this->booking->id,
            'text' => $optionname,
            'maxanswers' => 7,
            'type' => 0,
        ]);
        if (empty($seedoption->id)) {
            throw new \coding_exception('prepare_update_option_scenario failed: seed option was not created.');
        }

        $store = new conversation_store();
        $registry = task_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => (int)$thread->id,
            'replacements' => [
                'existing_option_id' => (string)$seedoption->id,
                'existing_option_name' => $optionname,
            ],
        ];
    }

    /**
     * Skip scenarios that depend on optional booking rules service when unavailable.
     *
     * @return array<string,mixed>
     */
    protected function prepare_booking_rules_service_scenario(): array {
        $candidates = [
            '\\mod_booking\\local\\wbagent\\options\\support\\booking_rules_agent_service',
            '\\bookingextension_agent\\local\\wbagent\\booking\\support\\booking_rules_agent_service',
        ];

        foreach ($candidates as $classname) {
            if (!class_exists($classname)) {
                continue;
            }
            try {
                $service = new $classname();
                if (is_object($service)) {
                    return [];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $this->markTestSkipped('Booking rules service is unavailable in this installation.');
    }

    /**
     * Evaluate the scenario-specific assertion contract.
     *
     * @param array<string,mixed> $scenario
     * @param array<string,string> $replacements
     * @param array<string,mixed> $chatresult
     * @param array<string,mixed> $finalresult
     * @param int $threadid
     * @return void
     */
    protected function assert_scenario_assertions(
        array $scenario,
        array $replacements,
        array $chatresult,
        array $finalresult,
        int $threadid
    ): void {
        foreach ((array)($scenario['assertions'] ?? []) as $assertion) {
            if (!is_array($assertion)) {
                continue;
            }

            $target = (string)($assertion['target'] ?? 'final');
            $payload = $target === 'chat' ? $chatresult : $finalresult;
            $type = (string)($assertion['type'] ?? '');
            $value = $this->render_assertion_value((string)($assertion['value'] ?? ''), $replacements);
            $field = (string)($assertion['field'] ?? '');

            switch ($type) {
                case 'field_equals':
                    $actual = $this->payload_field_value($payload, $field);
                    $this->assertSame(
                        $value,
                        $this->stringify_assertion_value($actual),
                        'Scenario assertion failed for field_equals on ' . $field . ' in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'field_contains':
                    $actual = $this->stringify_assertion_value($this->payload_field_value($payload, $field));
                    $this->assertStringContainsString(
                        $value,
                        $actual,
                        'Scenario assertion failed for field_contains on ' . $field . ' in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'field_count_gte':
                    $this->assertGreaterThanOrEqual(
                        (int)$value,
                        $this->payload_field_count($payload, $field),
                        'Scenario assertion failed for field_count_gte on ' . $field . ' in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'field_count_equals':
                    $this->assertSame(
                        (int)$value,
                        $this->payload_field_count($payload, $field),
                        'Scenario assertion failed for field_count_equals on ' . $field . ' in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'step_count_gte':
                    $this->assertGreaterThanOrEqual(
                        (int)$value,
                        $this->payload_step_count($payload),
                        'Scenario assertion failed for step_count_gte in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'step_count_equals':
                    $this->assertSame(
                        (int)$value,
                        $this->payload_step_count($payload),
                        'Scenario assertion failed for step_count_equals in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'response_type_equals':
                    $this->assertSame(
                        $value,
                        (string)($payload['response_type'] ?? ''),
                        'Scenario assertion failed for response_type_equals in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'debug_source_contains':
                    $source = $this->get_latest_debug_source($threadid);
                    $this->assertStringContainsString(
                        $value,
                        $source,
                        'Scenario assertion failed for debug_source_contains in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                case 'result_contains':
                    $this->assertStringContainsString(
                        $value,
                        $this->payload_text($payload),
                        'Scenario assertion failed for result_contains in ' . (string)($scenario['task'] ?? '')
                    );
                    break;

                default:
                    $this->fail('Unknown scenario assertion type: ' . $type . ' for ' . (string)($scenario['task'] ?? ''));
            }
        }
    }

    /**
     * Flatten a payload into assertion-friendly text.
     *
     * @param array<string,mixed> $payload
     * @return string
     */
    protected function payload_text(array $payload): string {
        $chunks = [
            (string)($payload['message'] ?? ''),
            (string)($payload['displaymessage'] ?? ''),
            (string)($payload['detail'] ?? ''),
            (string)($payload['usermessage'] ?? ''),
            (string)($payload['observation_full'] ?? ''),
            (string)($payload['memory_observation_text'] ?? ''),
            (string)($payload['debugmessage'] ?? ''),
            (string)($payload['resultsjson'] ?? ''),
            (string)($payload['commands'] ?? ''),
            json_encode($payload['results'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return "\n" . implode("\n", $chunks) . "\n";
    }

    /**
     * Resolve a dotted field path from a payload.
     *
     * @param array<string,mixed> $payload
     * @param string $field
     * @return mixed
     */
    protected function payload_field_value(array $payload, string $field) {
        if ($field === '') {
            return null;
        }

        $value = $payload;
        foreach (explode('.', $field) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * Count a resolved payload field when possible.
     *
     * @param array<string,mixed> $payload
     * @param string $field
     * @return int
     */
    protected function payload_field_count(array $payload, string $field): int {
        $value = $this->payload_field_value($payload, $field);
        if (is_array($value) || $value instanceof \Countable) {
            return count($value);
        }

        if ($value === null || $value === '') {
            return 0;
        }

        return 1;
    }

    /**
     * Determine the best available step count from a response payload.
     *
     * @param array<string,mixed> $payload
     * @return int
     */
    protected function payload_step_count(array $payload): int {
        if (isset($payload['loop_step']) && is_numeric($payload['loop_step'])) {
            return (int)$payload['loop_step'];
        }

        $loopresults = (array)($payload['loop_results'] ?? []);
        if (!empty($loopresults)) {
            return count($loopresults);
        }

        return 0;
    }

    /**
     * Return the latest debug source for the given thread.
     *
     * @param int $threadid
     * @return string
     */
    protected function get_latest_debug_source(int $threadid): string {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT source FROM {local_wbagent_ai_llm_debug} WHERE threadid = ? ORDER BY id DESC',
            [$threadid],
            IGNORE_MULTIPLE
        );

        if (!$record) {
            return '';
        }

        return (string)($record->source ?? '');
    }

    /**
     * Render a placeholder-bearing assertion value.
     *
     * @param string $value
     * @param array<string,string> $replacements
     * @return string
     */
    protected function render_assertion_value(string $value, array $replacements): string {
        return $this->render_scenario_template($value, $replacements);
    }

    /**
     * Convert a payload value to a stable string for assertions.
     *
     * @param mixed $value
     * @return string
     */
    protected function stringify_assertion_value($value): string {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '[complex]';
    }

    /**
     * Normalize a response payload to the concrete task result when it is wrapped in an execution response.
     *
     * @param array<string,mixed> $payload
     * @param string $taskname
     * @return array<string,mixed>|null
     */
    protected function resolve_task_result_payload(array $payload, string $taskname): ?array {
        $direct = $this->extract_task_result($payload, $taskname);
        if (is_array($direct)) {
            return $direct;
        }

        foreach ($this->task_result_candidate_names($taskname) as $candidatename) {
            if ($candidatename === $taskname) {
                continue;
            }
            $direct = $this->extract_task_result($payload, $candidatename);
            if (is_array($direct)) {
                return $direct;
            }
        }

        $resultsjson = (string)($payload['resultsjson'] ?? '');
        if ($resultsjson === '') {
            return null;
        }

        $decoded = json_decode($resultsjson, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($taskname !== '' && (string)($entry['task'] ?? '') === $taskname) {
                return $entry;
            }

            if ($taskname !== '') {
                foreach ($this->task_result_candidate_names($taskname) as $candidatename) {
                    if ($candidatename !== '' && (string)($entry['task'] ?? '') === $candidatename) {
                        return $entry;
                    }
                }
            }

            if ($taskname === '' && !empty($entry)) {
                return $entry;
            }
        }

        foreach ($decoded as $entry) {
            if (is_array($entry) && !empty($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Render {{placeholder}} tokens inside scenario prompts.
     *
     * @param string $template
     * @param array<string,string> $replacements
     * @return string
     */
    protected function render_scenario_template(string $template, array $replacements): string {
        $rendered = $template;
        foreach ($replacements as $key => $value) {
            $rendered = str_replace('{{' . $key . '}}', (string)$value, $rendered);
        }
        return $rendered;
    }

    /**
     * Create a deterministic fallback prompt that narrows routing to one task.
     *
     * @param array<string,mixed> $scenario
     * @param string $prompt
     * @return string
     */
    protected function build_fallback_prompt(array $scenario, string $prompt): string {
        $taskname = (string)$scenario['task'];
        if ((string)$scenario['mode'] === 'mutating') {
            return 'Prepare exactly one ' . $taskname . ' confirmation_request for this request. '
                . 'Do not execute yet. Request: ' . $prompt;
        }

        return 'Use exactly one ' . $taskname . ' task and execute it now. Request: ' . $prompt;
    }

    /**
     * Determine whether the current result already matched the expected task.
     *
     * @param array<string,mixed> $result
     * @param string $taskname
     * @param string $mode
     * @return bool
     */
    protected function scenario_matched_expected_task(array $result, string $taskname, string $mode): bool {
        if ($mode === 'mutating') {
            return $this->extract_command($result, $taskname) !== null;
        }

        return $this->find_task_result_entry($result, $taskname) !== null;
    }

    /**
     * Find the first result entry for a task in the final payload.
     *
     * @param array<string,mixed> $payload
     * @param string $taskname
     * @return array<string,mixed>|null
     */
    protected function find_task_result_entry(array $payload, string $taskname): ?array {
        foreach ((array)($payload['results'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string)($entry['task'] ?? '') === $taskname) {
                return $entry;
            }

            foreach ($this->task_result_candidate_names($taskname) as $candidatename) {
                if ($candidatename !== '' && (string)($entry['task'] ?? '') === $candidatename) {
                    return $entry;
                }
            }
        }

        foreach ((array)($payload['loop_results'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string)($entry['task'] ?? '') === $taskname) {
                return $entry;
            }
            foreach ($this->task_result_candidate_names($taskname) as $candidatename) {
                if ($candidatename !== '' && (string)($entry['task'] ?? '') === $candidatename) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * Return task-name candidates for legacy alias/canonical mappings.
     *
     * @param string $taskname
     * @return array<int,string>
     */
    protected function task_result_candidate_names(string $taskname): array {
        $candidates = [$taskname];

        $aliasmap = [
            'mod_booking.create_selflearning_option' => [
                'mod_booking.create_selflearning_option',
                'mod_booking.create_option',
            ],
            'mod_booking.create_slotbooking_option' => [
                'mod_booking.create_slotbooking_option',
                'mod_booking.create_option',
            ],
            'mod_booking.create_selflearning_option' => [
                'mod_booking.create_selflearning_option',
                'mod_booking.create_option',
            ],
            'mod_booking.create_slotbooking_option' => [
                'mod_booking.create_slotbooking_option',
                'mod_booking.create_option',
            ],
        ];

        if (isset($aliasmap[$taskname])) {
            $candidates = array_merge($candidates, $aliasmap[$taskname]);
        }

        return array_values(array_unique(array_filter($candidates, static fn($value): bool => is_string($value) && $value !== '')));
    }
}
