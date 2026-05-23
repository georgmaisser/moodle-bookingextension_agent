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
 * Whole-agent webservice tests with mocked LLM responses.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../abstract_agent_testcase.php');
require_once(__DIR__ . '/../routed_ai_manager_mock.php');
require_once(__DIR__ . '/ai_send_message_mock_scenarios.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\queue\queue_manager;

/**
 * Whole-agent ai_send_message tests with scripted AI output.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 * @runTestsInSeparateProcesses
 */
final class ai_send_message_simulated_llm_test extends abstract_agent_testcase {
    /** @var \core_ai\manager|null */
    private ?\core_ai\manager $originalmanager = null;

    /** @var \core_ai\manager|null */
    private ?\core_ai\manager $scriptedmanager = null;

    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->originalmanager = \core\di::get(\core_ai\manager::class);
    }

    protected function tearDown(): void {
        if ($this->originalmanager !== null) {
            \core\di::set(\core_ai\manager::class, $this->originalmanager);
        }
        parent::tearDown();
    }

    /**
     * Install a routed fake core_ai manager that picks responses by scenario and keeps
     * returning the last scripted response for the active route.
     *
     * @param array<int,array<string,mixed>> $routes
     * @return void
     */
    private function install_routed_ai_manager(array $routes): void {
        $this->scriptedmanager = new routed_ai_manager_mock($routes);

        \core\di::set(\core_ai\manager::class, $this->scriptedmanager);
    }

    /**
     * Return all mocked scenarios for the simulated webservice suite.
     *
     * @return array<string,array{0:string}>
     */
    public static function provide_ai_send_message_mock_scenarios(): array {
        return ai_send_message_mock_scenarios::provider_rows();
    }

    /**
     * Run one mock webservice scenario.
     *
     * @param string $scenario
     * @return void
     * @dataProvider provide_ai_send_message_mock_scenarios
     */
    public function test_ai_send_message_mock_scenario(string $scenario): void {
        global $DB;

        $this->setUser($this->teacher);

        $helpers = [
            'create_option' => fn(string $title, array $overrides = []) => $this->create_option($title, $overrides),
            'create_user' => fn(array $user) => $this->getDataGenerator()->create_user($user),
            'create_course' => fn(array $course) => $this->getDataGenerator()->create_course($course),
            'enrol_user' => fn(int $userid) => $this->getDataGenerator()->enrol_user($userid, $this->course->id, 'student'),
            'teacher_id' => fn(): int => (int)$this->teacher->id,
            'enrol_user_in_course' => fn(int $userid, int $courseid) => $this->getDataGenerator()->enrol_user(
                $userid,
                $courseid,
                'student'
            ),
            'exec_command' => fn(string $task, array $input) => $this->exec_command($task, $input),
        ];
        $case = ai_send_message_mock_scenarios::build_case($scenario, $helpers);
        $this->install_routed_ai_manager($case['routes']);

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$this->booking->cmid, (string)$case['prompt']);

        $expectedresponses = array_map(
            static fn($value): string => (string)$value,
            (array)($case['expected_response_types'] ?? [$case['expected_response_type']])
        );
        $this->assertContains((string)($response['response_type'] ?? ''), $expectedresponses);

        $this->assertGreaterThan(0, (int)($response['threadid'] ?? 0));

        $entries = $DB->get_records('local_wbagent_ai_llm_debug', ['threadid' => (int)$response['threadid']], 'id ASC');
        $this->assertGreaterThanOrEqual((int)$case['min_debug_rows'], count($entries));
        if (isset($case['expected_loop_depth_min'])) {
            $this->assertGreaterThanOrEqual((int)$case['expected_loop_depth_min'], count($entries));
        } else {
            $this->assertSame((int)$case['expected_loop_depth'], count($entries));
        }
        $sources = array_map(static fn($entry): string => (string)($entry->source ?? ''), $entries);
        foreach ((array)$case['expected_debug_source_patterns'] as $pattern) {
            $this->assertNotEmpty(
                array_filter($sources, static fn(string $source): bool => strpos($source, (string)$pattern) !== false),
                'Missing expected debug source pattern: ' . (string)$pattern
            );
        }

        $firstentry = reset($entries);
        $this->assertNotEmpty((string)($firstentry->requesttext ?? ''));
        $this->assertNotEmpty((string)($firstentry->responsetext ?? ''));

        $rawtasks = [];
        $commands = json_decode((string)($response['commands'] ?? '[]'), true);
        if (is_array($commands)) {
            $rawtasks = array_merge(
                $rawtasks,
                array_map(static fn(array $command): string => (string)($command['task'] ?? ''), $commands)
            );
        }

        $results = json_decode((string)($response['resultsjson'] ?? '[]'), true);
        if (is_array($results)) {
            foreach ($results as $resultrow) {
                if (is_array($resultrow) && isset($resultrow['task']) && is_string($resultrow['task'])) {
                    $rawtasks[] = $resultrow['task'];
                }
            }
        }
        $foundtasks = array_values(array_unique(array_filter($rawtasks, static fn(string $task): bool => $task !== '')));

        foreach ((array)$case['expected_tasks'] as $taskname) {
            $this->assertContains((string)$taskname, $foundtasks);
        }
        $this->assertGreaterThanOrEqual((int)$case['expected_task_transitions'], count($foundtasks));

        switch ($scenario) {
            case 'create_option_confirmation':
                $this->assertIsArray($commands);
                $this->assertCount(1, $commands);
                $this->assertSame('booking.create_option', (string)($commands[0]['task'] ?? ''));
                $this->assertSame((string)$case['title'], (string)($commands[0]['input']['text'] ?? ''));

                $this->assertFalse(
                    $DB->record_exists(
                        'booking_options',
                        ['bookingid' => (int)$this->booking->id, 'text' => (string)$case['title']]
                    ),
                    'Confirmation_request must not auto-create the booking option.'
                );

                set_config('aiexecutionmode', 'direct', 'bookingextension_agent');
                $_POST['sesskey'] = sesskey();
                $confirm = ai_confirm_run::execute(
                    (int)$this->booking->cmid,
                    (int)$response['threadid'],
                    (string)($response['queueitemid'] ?? '')
                );

                $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));
                $this->assertGreaterThan(0, (int)($confirm['runid'] ?? 0));

                $created = $DB->get_record('booking_options', [
                    'bookingid' => (int)$this->booking->id,
                    'text' => (string)$case['title'],
                ]);
                $this->assertNotFalse($created, 'Confirmed run must create the booking option.');
                $this->assertSame(7, (int)$created->maxanswers);
                break;

            case 'create_rule_confirmation':
                $this->assertIsArray($commands);
                $this->assertCount(1, $commands);
                $this->assertSame('confirmation_request', (string)($response['response_type'] ?? ''));
                $this->assertSame('booking.create_rule_from_template', (string)($commands[0]['task'] ?? ''));
                    $this->assertSame('Automatic booking confirmation', (string)($commands[0]['input']['templatequery'] ?? ''));
                $this->assertSame(
                    'Create a booking rule from the booking confirmation template '
                    . 'named Automatic booking confirmation. No follow-up questions.',
                    (string)($commands[0]['input']['question'] ?? '')
                );
                break;

            case 'create_rule_clarification':
                $message = (string)($response['message'] ?? '');
                $this->assertNotSame('', trim($message));
                $this->assertSame('confirmation_request', (string)($response['response_type'] ?? ''));
                $this->assertCount(1, $commands);
                $this->assertSame('booking.create_rule_from_template', (string)($commands[0]['task'] ?? ''));
                break;

            case 'explain_booking_rules_docs':
                $this->assertSame('sufficient', (string)($response['response_type'] ?? ''));
                $this->assertContains('booking.explain_docs_topic', $foundtasks);
                $this->assertNotEmpty($results, 'Docs explanation must surface execution results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.explain_docs_topic');
                $this->assertNotNull($taskresult, 'Docs explanation task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''));

                $docs = (array)($taskresult['docs'] ?? []);
                $this->assertNotEmpty($docs, 'Docs explanation must return matched docs.');
                $firstdoc = (array)($docs[0] ?? []);
                $this->assertStringStartsWith(
                    (string)($case['expected_docs_prefix'] ?? ''),
                    (string)($firstdoc['path'] ?? '')
                );
                $combined = (string)($response['message'] ?? '') . ' '
                    . json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->assertStringContainsString('Booking rules', $combined);
                break;

            case 'diagnose_other_user_cannot_book':
                $this->assertSame(
                    'sufficient',
                    (string)($response['response_type'] ?? ''),
                    'Diagnose must complete as sufficient.'
                );
                $this->assertNotEmpty($results, 'Diagnose scenario must surface execution results.');
                $this->assertGreaterThanOrEqual(1, count($results), 'At least one result entry expected.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.diagnose_booking_issue');
                $this->assertNotNull($taskresult, 'Diagnose booking issue result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                // Verify diagnosis structure.
                $this->assertIsArray((array)($taskresult['diagnosis'] ?? []), 'Diagnosis must be array.');
                $this->assertSame(
                    (int)$case['blockeduser']->id,
                    (int)($taskresult['diagnosis']['userid'] ?? 0),
                    'Must diagnose correct user.'
                );
                $this->assertSame(
                    (int)$case['option']->id,
                    (int)($taskresult['diagnosis']['optionid'] ?? 0),
                    'Must diagnose correct option.'
                );

                // Verify reasons are present and meaningful.
                $reasons = (array)($taskresult['diagnosis']['reasons'] ?? []);
                $this->assertGreaterThanOrEqual(
                    (int)($case['expected_min_reasons'] ?? 1),
                    count($reasons),
                    'At least one reason must be provided.'
                );
                $this->assertSame(
                    $reasons[0],
                    'The selected booking option is set to invisible and is not visible to regular users.'
                );

                // Verify response message mentions the user or issue.
                $message = (string)($response['message'] ?? '');
                $this->assertNotEmpty($message, 'Response message must not be empty.');
                $this->assertTrue(
                    str_contains($message, (string)$case['blockeduser']->firstname)
                    || str_contains($message, 'buchen')
                    || str_contains($message, 'book'),
                    'Message should reference user or booking issue.'
                );
                break;
            case 'list_all_booking_options':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results);

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.search_options');
                $this->assertNotNull($taskresult);
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''));

                $resulttext = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->assertIsString($resulttext);
                $this->assertStringContainsString((string)$case['option1']->text, $resulttext);
                $this->assertStringContainsString((string)$case['option2']->text, $resulttext);
                break;
            case 'search_matching_booking_options':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results);

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.search_options');
                $this->assertNotNull($taskresult);
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''));

                $resulttext = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->assertIsString($resulttext);
                $this->assertStringContainsString((string)$case['prefix'], $resulttext);
                $this->assertStringContainsString((string)$case['option1']->text, $resulttext);
                $this->assertStringContainsString((string)$case['option2']->text, $resulttext);
                break;

            case 'get_option_details_for_specific_option':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results, 'Option details scenario must return results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.get_option_details');
                $this->assertNotNull($taskresult, 'get_option_details task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $optiondetails = (array)($taskresult['optiondetails'] ?? []);
                $this->assertNotEmpty($optiondetails, 'Option details must not be empty.');
                $firstdetail = (array)($optiondetails[0] ?? []);
                $this->assertSame((int)$case['option']->id, (int)($firstdetail['optionid'] ?? 0), 'Must resolve exact option id.');
                $this->assertSame((string)$case['title'], (string)($firstdetail['title'] ?? ''), 'Must return exact option title.');

                $standard = (array)($firstdetail['standard_fields'] ?? []);
                $this->assertArrayHasKey('title', $standard, 'title must be included in standard fields.');
                $this->assertSame((string)$case['title'], (string)($standard['title'] ?? ''), 'standard_fields.title must match.');
                $this->assertArrayHasKey('teachers', $standard, 'teachers must be included in standard fields.');
                $this->assertArrayHasKey('sessions', $standard, 'sessions must be included in standard fields.');
                break;

            case 'search_users_by_unique_name':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results, 'Search users scenario must return results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.search_users');
                $this->assertNotNull($taskresult, 'search_users task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $users = (array)($taskresult['users'] ?? []);
                $this->assertGreaterThanOrEqual(2, count($users), 'At least two users must be returned.');
                $jsonusers = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->assertIsString($jsonusers);
                $this->assertStringContainsString(
                    (string)$case['token'],
                    $jsonusers,
                    'Returned users must include the unique token.'
                );
                $this->assertStringContainsString(
                    (string)$case['user1']->firstname,
                    $jsonusers,
                    'First target user must be present.'
                );
                $this->assertStringContainsString(
                    (string)$case['user2']->firstname,
                    $jsonusers,
                    'Second target user must be present.'
                );
                break;

            case 'get_current_user_profile':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results, 'Get-current-user scenario must return results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.get_current_user');
                $this->assertNotNull($taskresult, 'get_current_user task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $this->assertSame(
                    (int)$this->teacher->id,
                    (int)($taskresult['userid'] ?? 0),
                    'Resolved userid must match current teacher.'
                );
                $this->assertSame(
                    (int)$this->teacher->id,
                    (int)($taskresult['resultid'] ?? 0),
                    'resultid must match current teacher.'
                );

                $preview = (array)($taskresult['previewdata'] ?? []);
                $this->assertSame(
                    (int)$this->teacher->id,
                    (int)($preview['userid'] ?? 0),
                    'previewdata.userid must match current teacher.'
                );
                $this->assertStringContainsString((string)$this->teacher->firstname, (string)($taskresult['fullname'] ?? ''));
                break;

            case 'list_agent_actions':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results, 'List actions scenario must return results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.list_actions');
                $this->assertNotNull($taskresult, 'list_actions task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $actions = (array)($taskresult['actions'] ?? []);
                $this->assertNotEmpty($actions, 'Actions list must not be empty.');
                $actiontasks = array_values(array_filter(array_map(
                    static fn(array $action): string => (string)($action['task'] ?? ''),
                    $actions
                )));
                $this->assertContains('booking.create_option', $actiontasks, 'Action list must include booking.create_option.');
                $this->assertContains('booking.search_options', $actiontasks, 'Action list must include booking.search_options.');
                $this->assertContains('booking.list_actions', $actiontasks, 'Action list must include booking.list_actions.');
                break;

            case 'list_option_properties_for_create_scope':
                $this->assertIsArray($results);
                $this->assertNotEmpty($results, 'List option properties scenario must return results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.list_option_properties');
                $this->assertNotNull($taskresult, 'list_option_properties task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $properties = (array)($taskresult['properties'] ?? []);
                $this->assertNotEmpty($properties, 'Properties list must not be empty.');

                $names = [];
                foreach ($properties as $property) {
                    $this->assertIsArray($property, 'Each property row must be structured.');
                    $this->assertNotEmpty((string)($property['name'] ?? ''), 'Property name must be set.');
                    $this->assertTrue(
                        (bool)($property['increate'] ?? false),
                        'Create scope must return create-supported properties only.'
                    );
                    $names[] = (string)$property['name'];
                }

                $this->assertContains('text', $names, 'Create scope must include property "text".');
                $this->assertContains('maxanswers', $names, 'Create scope must include property "maxanswers".');
                break;
        }
    }

    /**
     * Step 8 guard: when a pending confirmation exists, a new intent must be
     * blocked until the user confirms or discards the pending action.
     *
     * @return void
     */
    public function test_pending_confirmation_blocks_new_intent_until_resolved(): void {
        global $DB;

        $this->setUser($this->teacher);

        $title = 'Webservice Pending Guard ' . uniqid('', true);

        $this->install_routed_ai_manager([
            [
                'prompt_contains' => ['Create booking option'],
                'responses' => [
                    [
                        'response_type' => 'confirmation_request',
                        'message' => 'Please confirm creating this booking option.',
                        'commands' => [[
                            'task' => 'booking.create_option',
                            'version' => 1,
                            'input' => [
                                'text' => $title,
                                'optiontype' => 'normal',
                                'maxanswers' => 7,
                                'coursestarttime' => '2045-11-01T09:00:00',
                                'courseendtime' => '2045-11-01T11:00:00',
                                'teacherquery' => 'current',
                            ],
                        ]],
                    ],
                    [
                        'response_type' => 'task_call',
                        'commands' => [[
                            'task' => 'booking.search_options',
                            'version' => 1,
                            'input' => [
                                'query' => '',
                            ],
                        ]],
                        'used_triggers' => [],
                    ],
                ],
            ],
        ]);

        $_POST['sesskey'] = sesskey();
        $firstresponse = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Create booking option "' . $title . '" with 7 spots from 2045-11-01T09:00:00 to 2045-11-01T11:00:00.'
        );

        $this->assertSame('confirmation_request', (string)($firstresponse['response_type'] ?? ''));
        $firstcode = trim((string)($firstresponse['pendingconfirmationcode'] ?? ''));
        $this->assertNotSame('', $firstcode);

        $_POST['sesskey'] = sesskey();
        $secondresponse = ai_send_message::execute((int)$this->booking->cmid, 'zeige mir alle buchungsoptionen');

        $this->assertSame('clarification', (string)($secondresponse['response_type'] ?? ''));
        $secondmessage = (string)($secondresponse['message'] ?? '');
        $this->assertTrue(
            str_contains($secondmessage, 'pending action') || str_contains($secondmessage, 'ausstehende Aktion'),
            'Expected pending-intent clarification message in either EN or DE.'
        );
        $this->assertSame($firstcode, trim((string)($secondresponse['pendingconfirmationcode'] ?? '')));

        $secondcommands = json_decode((string)($secondresponse['commands'] ?? '[]'), true);
        $this->assertIsArray($secondcommands);
        $this->assertCount(0, $secondcommands);

        $this->assertFalse(
            $DB->record_exists('booking_options', ['bookingid' => (int)$this->booking->id, 'text' => $title]),
            'Pending confirmation must not be auto-executed while a new intent is blocked.'
        );
    }

    /**
     * Step 8 discard path: explicit discard trigger clears pending intent and
     * allows the new user intent to continue in the same turn.
     *
     * @return void
     */
    public function test_pending_confirmation_discard_allows_new_intent(): void {
        $this->setUser($this->teacher);

        $title = 'Webservice Pending Discard ' . uniqid('', true);
        $option = $this->create_option('Webservice Discard Target ' . uniqid('', true), []);

        $this->install_routed_ai_manager([
            [
                'prompt_contains' => ['Create booking option'],
                'responses' => [
                    [
                        'response_type' => 'confirmation_request',
                        'message' => 'Please confirm creating this booking option.',
                        'commands' => [[
                            'task' => 'booking.create_option',
                            'version' => 1,
                            'input' => [
                                'text' => $title,
                                'optiontype' => 'normal',
                                'maxanswers' => 7,
                                'coursestarttime' => '2045-11-01T09:00:00',
                                'courseendtime' => '2045-11-01T11:00:00',
                                'teacherquery' => 'current',
                            ],
                        ]],
                    ],
                    [
                        'response_type' => 'task_call',
                        'commands' => [[
                            'task' => 'booking.get_option_details',
                            'version' => 1,
                            'input' => [
                                'optionid' => (int)$option->id,
                            ],
                        ]],
                        'used_triggers' => ['core.discard_pending_confirmation'],
                    ],
                    [
                        'response_type' => 'sufficient',
                        'message' => 'I discarded the pending action and fetched the requested option details.',
                        'user_lang' => 'en',
                    ],
                ],
            ],
        ]);

        $_POST['sesskey'] = sesskey();
        $firstresponse = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Create booking option "' . $title . '" with 7 spots from 2045-11-01T09:00:00 to 2045-11-01T11:00:00.'
        );

        $this->assertSame('confirmation_request', (string)($firstresponse['response_type'] ?? ''));
        $this->assertNotSame('', trim((string)($firstresponse['pendingconfirmationcode'] ?? '')));

        $_POST['sesskey'] = sesskey();
        $secondresponse = ai_send_message::execute((int)$this->booking->cmid, 'discard that and show option details');

        $this->assertSame('sufficient', (string)($secondresponse['response_type'] ?? ''));
        $this->assertSame('', trim((string)($secondresponse['pendingconfirmationcode'] ?? '')));

        $results = json_decode((string)($secondresponse['resultsjson'] ?? '[]'), true);
        $this->assertIsArray($results);
        $resulttext = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($resulttext);
        $this->assertStringContainsString((string)$option->text, $resulttext);
    }

    /**
    * Confirming a single queued mutation must not create unstaged follow-up work.
     *
    * Additional mutating work must come from the already-staged queue, not from
    * an implicit post-execution planner call.
     *
     * @return void
     */
    public function test_confirm_run_does_not_continue_without_staged_queue_item(): void {
        global $DB;

        $this->setUser($this->teacher);
        set_config('aiexecutionmode', 'direct', 'bookingextension_agent');

        $title = 'Webservice Multistep Continue ' . uniqid('', true);

        $this->install_routed_ai_manager([
            [
                'prompt_contains' => ['Create booking option'],
                'responses' => [
                    [
                        'response_type' => 'confirmation_request',
                        'message' => 'Please confirm creating this booking option.',
                        'commands' => [[
                            'task' => 'booking.create_option',
                            'version' => 1,
                            'input' => [
                                'text' => $title,
                                'optiontype' => 'normal',
                                'maxanswers' => 7,
                                'coursestarttime' => '2045-11-01T09:00:00',
                                'courseendtime' => '2045-11-01T11:00:00',
                                'teacherquery' => 'current',
                            ],
                        ]],
                    ],
                    [
                        'response_type' => 'confirmation_request',
                        'message' => 'Please confirm making this option visible.',
                        'commands' => [[
                            'task' => 'booking.update_option',
                            'version' => 1,
                            'input' => [
                                'optionquery' => $title,
                                'visible' => 1,
                            ],
                        ]],
                    ],
                ],
            ],
        ]);

        $_POST['sesskey'] = sesskey();
        $firstresponse = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Create booking option "' . $title . '" with 7 spots from 2045-11-01T09:00:00 to 2045-11-01T11:00:00.'
        );

        $this->assertSame('confirmation_request', (string)($firstresponse['response_type'] ?? ''));
        $this->assertNotSame('', trim((string)($firstresponse['pendingconfirmationcode'] ?? '')));

        $_POST['sesskey'] = sesskey();
        $confirm = ai_confirm_run::execute(
            (int)$this->booking->cmid,
            (int)$firstresponse['threadid'],
            (string)($firstresponse['queueitemid'] ?? '')
        );

        $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));
        $this->assertGreaterThan(0, (int)($confirm['runid'] ?? 0));

        $created = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => $title,
        ]);
        $this->assertNotFalse($created, 'Confirmed run must create the booking option.');

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

        $store = new conversation_store();
        $pending = $store->get_pending_intent((int)$firstresponse['threadid']);
        $this->assertNull($pending);
    }

    /**
     * A confirmation containing multiple mutating commands must execute only the
     * first command and surface the next command as a follow-up confirmation.
     *
     * @return void
     */
    public function test_confirm_run_stages_multi_command_confirmation_plan(): void {
        global $DB;

        $this->setUser($this->teacher);
        set_config('aiexecutionmode', 'direct', 'bookingextension_agent');

        $title = 'Webservice Staged Confirm ' . uniqid('', true);
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Staged',
            'lastname' => 'Target',
            'email' => 'staged.target.' . uniqid('', true) . '@example.com',
        ]);

        $this->install_routed_ai_manager([
            [
                'prompt_contains' => ['Create booking option'],
                'responses' => [
                    [
                        'response_type' => 'confirmation_request',
                        'message' => 'Please confirm creating and then booking the user.',
                        'commands' => [
                            [
                                'task' => 'booking.create_option',
                                'version' => 1,
                                'input' => [
                                    'text' => $title,
                                    'optiontype' => 'normal',
                                    'maxanswers' => 7,
                                    'coursestarttime' => '2045-11-01T09:00:00',
                                    'courseendtime' => '2045-11-01T11:00:00',
                                    'teacherquery' => 'current',
                                ],
                            ],
                            [
                                'task' => 'booking.book_users',
                                'version' => 1,
                                'input' => [
                                    'optionquery' => $title,
                                    'bookusersquery' => (string)$target->email,
                                ],
                            ],
                        ],
                    ],
                    [
                        'response_type' => 'confirmation_request',
                        'message' => 'Please confirm booking the selected user for this option.',
                        'commands' => [[
                            'task' => 'booking.book_users',
                            'version' => 1,
                            'input' => [
                                'optionquery' => $title,
                                'bookusersquery' => (string)$target->email,
                            ],
                        ]],
                    ],
                ],
            ],
        ]);

        $_POST['sesskey'] = sesskey();
        $firstresponse = ai_send_message::execute(
            (int)$this->booking->cmid,
            'Create booking option "' . $title . '" and then book ' . (string)$target->email . ' into it.'
        );

        $this->assertSame('confirmation_request', (string)($firstresponse['response_type'] ?? ''));
        $commands = json_decode((string)($firstresponse['commands'] ?? '[]'), true);
        $this->assertIsArray($commands);
        $this->assertCount(1, $commands, 'Server-side staging must expose only the first mutation for confirmation.');
        $this->assertSame('booking.create_option', (string)($commands[0]['task'] ?? ''));

        $_POST['sesskey'] = sesskey();
        $confirm = ai_confirm_run::execute(
            (int)$this->booking->cmid,
            (int)$firstresponse['threadid'],
            (string)($firstresponse['queueitemid'] ?? '')
        );

        $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));

        $created = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => $title,
        ]);
        $this->assertNotFalse($created, 'First stage must create the booking option.');

        $this->assertFalse(
            $DB->record_exists('booking_answers', [
                'bookingid' => (int)$this->booking->id,
                'optionid' => (int)$created->id,
                'userid' => (int)$target->id,
            ]),
            'Second stage must NOT execute in the same confirmation run.'
        );

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

        $store = new conversation_store();
        $pending = $store->get_pending_intent((int)$firstresponse['threadid']);
        $this->assertIsArray($pending);
        $queueitemids = array_values(array_filter(array_map('strval', (array)($pending['queue_item_ids'] ?? []))));
        $this->assertCount(1, $queueitemids, 'Follow-up should expose exactly the next staged queue item.');

        $queuesvc = new queue_manager($store);
        $queueitem = $queuesvc->get_queue_item((int)$firstresponse['threadid'], (string)$queueitemids[0]);
        $this->assertIsArray($queueitem);
        $this->assertSame('booking.book_users', (string)($queueitem['task'] ?? ''));
    }
}
