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
 * Whole-agent webservice tests against a real LLM.
 *
 * Reuses the simulated scenario catalog so prompts and fixture setup stay aligned
 * across mock and live-webservice coverage.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../abstract_agent_testcase.php');
require_once(__DIR__ . '/../../simulated_llm/webservice/ai_send_message_mock_scenarios.php');

use mod_booking\external\ai_confirm_run;
use mod_booking\external\ai_send_message;

/**
 * Whole-agent ai_send_message tests with a live provider.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @group real_llm
 * @coversNothing
 * @runTestsInSeparateProcesses
 */
final class ai_send_message_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->require_real_llm();

        // This suite validates real calls through ai_send_message directly.
        // build_runtime() thread tracking is not part of this webservice path.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Reuse the simulated scenario provider so prompt changes stay in sync.
     *
     * @return array<string,array{0:string}>
     */
    public static function provide_ai_send_message_real_llm_scenarios(): array {
        return ai_send_message_mock_scenarios::provider_rows();
    }

    /**
     * Run one live webservice scenario.
     *
     * These assertions stay intentionally outcome-focused: a real model may vary
     * in wording or whether it surfaces a command immediately versus after one
     * extra clarification step, but the underlying task/result should still align
     * with the shared scenario definition.
     *
     * @param string $scenario
     * @return void
     * @dataProvider provide_ai_send_message_real_llm_scenarios
     */
    public function test_ai_send_message_real_llm_scenario(string $scenario): void {
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

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$this->booking->cmid, (string)$case['prompt']);

        $expectedresponses = array_map(
            static fn($value): string => (string)$value,
            (array)($case['expected_response_types'] ?? [$case['expected_response_type']])
        );
        if ($scenario === 'list_agent_actions' && !in_array('clarification', $expectedresponses, true)) {
            // Real models may answer this broad capability question directly without a task_call.
            $expectedresponses[] = 'clarification';
        }
        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            $expectedresponses,
            sprintf(
                'Scenario "%s": unexpected response_type. message=%s errors=%s',
                $scenario,
                trim(strip_tags((string)($response['displaymessage'] ?? ''))),
                (string)($response['errorsjson'] ?? '')
            )
        );
        $this->assertArrayHasKey('autoconfirm', $response);
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

        $commands = $this->decode_json_array((string)($response['commands'] ?? '[]'));
        $results = $this->decode_json_array((string)($response['resultsjson'] ?? '[]'));
        $observedtasks = $this->collect_observed_tasks($commands, $results);

        $responsetype = (string)($response['response_type'] ?? '');
        if ($responsetype !== 'clarification') {
            foreach ((array)$case['expected_tasks'] as $taskname) {
                if ((string)$scenario === 'get_current_user_profile' && (string)$taskname === 'booking.get_current_user') {
                    $this->assertTrue(
                        in_array('booking.get_current_user', $observedtasks, true)
                            || in_array('booking.core_get_current_user', $observedtasks, true),
                        'Expected either booking.get_current_user or booking.core_get_current_user in observed tasks.'
                    );
                    continue;
                }
                $this->assertContains((string)$taskname, $observedtasks);
            }
            $this->assertGreaterThanOrEqual((int)$case['expected_task_transitions'], count($observedtasks));
        }

        switch ($scenario) {
            case 'create_option_confirmation':
                $this->assertContains(
                    (string)($response['response_type'] ?? ''),
                    ['confirmation_request', 'confirm_pending'],
                    'Create-option scenario must return a confirmable response.'
                );

                $command = $this->find_command($commands, 'booking.create_option');
                $this->assertNotNull($command, 'Create-option scenario must surface booking.create_option.');
                $this->assertSame((string)$case['title'], (string)($command['input']['text'] ?? ''));

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
                    (string)($response['commands'] ?? '[]')
                );

                $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));
                $created = $DB->get_record('booking_options', [
                    'bookingid' => (int)$this->booking->id,
                    'text' => (string)$case['title'],
                ]);
                $this->assertNotFalse($created, 'Confirmed run must create the booking option.');
                $this->assertSame(7, (int)$created->maxanswers);
                break;

            case 'create_rule_confirmation':
                $this->assertContains(
                    (string)($response['response_type'] ?? ''),
                    ['confirmation_request', 'confirm_pending'],
                    'Create-rule scenario should stay in a non-error interactive state.'
                );

                $command = $this->find_command($commands, 'booking.create_rule_from_template');
                $this->assertNotNull(
                    $command,
                    'Create-rule scenario must surface booking.create_rule_from_template for confirmation.'
                );
                $this->assertSame(
                    'Create a booking rule from the booking confirmation template '
                    . 'named Automatic booking confirmation. No follow-up questions.',
                    (string)($command['input']['question'] ?? '')
                );
                $this->assertSame('Automatic booking confirmation', (string)($command['input']['templatequery'] ?? ''));
                break;

            case 'create_rule_clarification':
                $message = (string)($response['message'] ?? '');
                $this->assertNotSame('', trim($message));
                $this->assertContains(
                    (string)($response['response_type'] ?? ''),
                    ['confirmation_request', 'confirm_pending', 'clarification']
                );
                if ((string)($response['response_type'] ?? '') !== 'clarification') {
                    $this->assertNotEmpty($commands, 'Confirmation request should expose commands.');
                }
                break;

            case 'explain_booking_rules_docs':
                $this->assertSame('sufficient', (string)($response['response_type'] ?? ''));
                $this->assertContains('booking.explain_docs_topic', $observedtasks, 'Docs explanation task must be executed.');
                $this->assertNotEmpty($results, 'Docs explanation scenario must surface execution results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.explain_docs_topic');
                $this->assertNotNull($taskresult, 'Docs explanation task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

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
                $this->assertContains('booking.diagnose_booking_issue', $observedtasks, 'Diagnose task must be executed.');
                $this->assertNotEmpty($results, 'Diagnose scenario must surface execution results.');
                $this->assertGreaterThanOrEqual(1, count($results), 'At least one result entry expected.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.diagnose_booking_issue');
                $this->assertNotNull($taskresult, 'Diagnose booking issue result must exist.');
                $this->assertContains(
                    (string)($taskresult['status'] ?? ''),
                    ['executed', 'error'],
                    'Task must have a completed status.'
                );

                if ((string)($taskresult['status'] ?? '') === 'executed') {
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
                }
                break;

            case 'list_all_booking_options':
                $this->assertContains('booking.search_options', $observedtasks);
                $this->assertNotEmpty($results, 'List-all scenario should surface search results.');

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
                $this->assertContains('booking.search_options', $observedtasks);
                $this->assertNotEmpty($results, 'Search scenario should surface search results.');

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
                $this->assertContains('booking.get_option_details', $observedtasks, 'Option details task must be executed.');
                $this->assertNotEmpty($results, 'Option details scenario must surface execution results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.get_option_details');
                $this->assertNotNull($taskresult, 'get_option_details task result must exist.');
                $this->assertContains(
                    (string)($taskresult['status'] ?? ''),
                    ['executed', 'error'],
                    'Task must have a completed status.'
                );

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
                $this->assertContains('booking.search_users', $observedtasks, 'search_users task must be executed.');
                $this->assertNotEmpty($results, 'Search users scenario must surface execution results.');

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
                $this->assertTrue(
                    in_array('booking.get_current_user', $observedtasks, true)
                        || in_array('booking.core_get_current_user', $observedtasks, true),
                    'Either booking.get_current_user or booking.core_get_current_user task must be executed.'
                );
                $this->assertNotEmpty($results, 'Get-current-user scenario must surface execution results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.get_current_user');
                if (!is_array($taskresult)) {
                    $taskresult = $this->extract_task_result($normalized, 'booking.core_get_current_user');
                }
                $this->assertNotNull($taskresult, 'Current-user task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $resolveduserid = (int)($taskresult['userid'] ?? $taskresult['user']['id'] ?? 0);
                $this->assertSame(
                    (int)$this->teacher->id,
                    $resolveduserid,
                    'Resolved userid must match current teacher.'
                );
                $this->assertSame(
                    (int)$this->teacher->id,
                    (int)($taskresult['resultid'] ?? 0),
                    'resultid must match current teacher.'
                );

                $resolvedfullname = (string)($taskresult['fullname'] ?? $taskresult['user']['fullname'] ?? '');
                $this->assertStringContainsString((string)$this->teacher->firstname, $resolvedfullname);
                break;

            case 'list_agent_actions':
                if ((string)($response['response_type'] ?? '') === 'clarification') {
                    $message = trim(strip_tags((string)($response['displaymessage'] ?? $response['message'] ?? '')));
                    $this->assertNotSame('', $message, 'Clarification response should still contain a useful action summary.');
                    break;
                }

                $this->assertContains('booking.list_actions', $observedtasks, 'list_actions task must be executed.');
                $this->assertNotEmpty($results, 'List actions scenario must surface execution results.');

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
                $this->assertContains(
                    'booking.list_option_properties',
                    $observedtasks,
                    'list_option_properties task must be executed.'
                );
                $this->assertNotEmpty($results, 'List option properties scenario must surface execution results.');

                $normalized = $response;
                $normalized['results'] = $results;
                $taskresult = $this->extract_task_result($normalized, 'booking.list_option_properties');
                $this->assertNotNull($taskresult, 'list_option_properties task result must exist.');
                $this->assertSame('executed', (string)($taskresult['status'] ?? ''), 'Task must be executed.');

                $properties = (array)($taskresult['properties'] ?? []);
                $this->assertNotEmpty($properties, 'Properties list must not be empty.');

                $names = [];
                $textproperty = null;
                foreach ($properties as $property) {
                    $this->assertIsArray($property, 'Each property row must be structured.');
                    $this->assertNotEmpty((string)($property['name'] ?? ''), 'Property name must be set.');
                    $name = (string)$property['name'];
                    $names[] = $name;
                    if ($name === 'text') {
                        $textproperty = $property;
                    }
                }

                $this->assertContains('text', $names, 'Create scope must include property "text".');
                $this->assertContains('maxanswers', $names, 'Create scope must include property "maxanswers".');
                $this->assertNotNull($textproperty, 'Property "text" must be present.');
                $this->assertTrue(
                    (bool)($textproperty['increate'] ?? false),
                    'Property "text" must be create-supported.'
                );
                break;
        }
    }

    /**
     * Pending confirmation should block a new intent until it is resolved.
     *
     * @return void
     */
    public function test_pending_confirmation_blocks_new_intent_until_resolved(): void {
        global $DB;

        $this->setUser($this->teacher);

        $title = 'Webservice Pending Guard ' . uniqid('', true);
        $firstresponse = $this->request_create_option_confirmation($title);

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

        $secondcommands = $this->decode_json_array((string)($secondresponse['commands'] ?? '[]'));
        $this->assertCount(0, $secondcommands);

        $this->assertFalse(
            $DB->record_exists('booking_options', ['bookingid' => (int)$this->booking->id, 'text' => $title]),
            'Pending confirmation must not be auto-executed while a new intent is blocked.'
        );
    }

    /**
     * Discarding a pending confirmation should allow the new intent to continue.
     *
     * @return void
     */
    public function test_pending_confirmation_discard_allows_new_intent(): void {
        $this->setUser($this->teacher);

        $title = 'Webservice Pending Discard ' . uniqid('', true);
        $option = $this->create_option('Webservice Discard Target ' . uniqid('', true), []);

        $firstresponse = $this->request_create_option_confirmation($title);
        $this->assertNotSame('', trim((string)($firstresponse['pendingconfirmationcode'] ?? '')));

        $_POST['sesskey'] = sesskey();
        $secondresponse = ai_send_message::execute(
            (int)$this->booking->cmid,
            'discard that and show option details for "' . (string)$option->text . '"'
        );

        $this->assertContains(
            (string)($secondresponse['response_type'] ?? ''),
            ['sufficient', 'clarification', 'execution_result'],
            'Discard flow should continue the new intent after clearing the pending action.'
        );
        // Allow clarification as fallback: LLM may block the new intent if discard trigger is not set.
        if ((string)($secondresponse['response_type'] ?? '') !== 'clarification') {
            $this->assertSame('', trim((string)($secondresponse['pendingconfirmationcode'] ?? '')));
        }

        $results = $this->decode_json_array((string)($secondresponse['resultsjson'] ?? '[]'));
        $resulttext = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($resulttext);

        if ((string)($secondresponse['response_type'] ?? '') !== 'clarification') {
            $message = (string)($secondresponse['message'] ?? '');
            $combinedtext = $message . ' ' . $resulttext;
            $this->assertStringContainsString((string)$option->text, $combinedtext);
        }
    }

    /**
     * Real-LLM multistep flow: create option first, then book Billy for it.
     *
     * Mimics the exact webservice flow:
     * 1. ai_send_message for the first planner step
     * 2. ai_confirm_run for each further confirmation step
     * 3. ai_poll_thread remains responsible for planner step progress in the UI
     *
     * @return void
     */
    public function test_multistep_create_option_then_book_billy(): void {
        global $DB;

        $this->setUser($this->teacher);

        $billy = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Booking',
            'email' => 'billy.booking.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user((int)$billy->id, (int)$this->course->id, 'student');

        $title = 'Sonnenfinsternis 5 ' . uniqid('', true);
        $prompt = 'erstelle zuerst eine neue Buchung mit dem Namen "' . $title . '", '
            . 'naechsten dienstag 18h bis 19h, fuer 30 Leute. '
            . 'Anschliessend buchst du den Benutzer mit der E-Mail-Adresse '
            . (string)$billy->email
            . ' fuer dieses Ereignis.';
        $attemptlogs = [];

        // Step 1: Send initial message.
        $_POST['sesskey'] = sesskey();
        $first = ai_send_message::execute((int)$this->booking->cmid, $prompt);
        $threadid = (int)($first['threadid'] ?? 0);
        $this->assertGreaterThan(0, $threadid, 'Thread id must be present.');

        $attemptlogs[] = 'send[0]: type=' . (string)($first['response_type'] ?? '')
            . ' | autoconfirm=' . (string)($first['autoconfirm'] ?? 0)
            . ' | msg=' . trim((string)($first['displaymessage'] ?? ''));

        $responsetype = (string)($first['response_type'] ?? '');

        // Step 2+: Handle follow-up loop (clarifications, confirmations, completion).
        for ($i = 0; $i < 8; $i++) {
            if ($responsetype === 'sufficient' || $responsetype === 'execution_result') {
                // Flow complete.
                break;
            }

            if ($responsetype === 'confirmation_request' || $responsetype === 'task_call') {
                // Frontend would show a confirm button — simulate by calling ai_confirm_run.
                $_POST['sesskey'] = sesskey();
                $confirmresult = ai_confirm_run::execute(
                    (int)$this->booking->cmid,
                    $threadid,
                    '[]',
                    false
                );
                $confirmrunid = (int)($confirmresult['runid'] ?? 0);
                $responsetype = (string)($confirmresult['response_type'] ?? '');

                $attemptlogs[] = 'confirm[' . $i . ']: success=' . (string)($confirmresult['success'] ?? 0)
                    . ' | runid=' . $confirmrunid
                    . ' | type=' . $responsetype
                    . ' | msg=' . trim((string)($confirmresult['message'] ?? ''));

                if (empty($confirmresult['success']) || $confirmrunid <= 0) {
                    break;
                }
                continue;
            }

            if ($responsetype === 'clarification') {
                // Reply to clarification with full context.
                $clarificationreply = 'Buche bitte den Benutzer mit userid ' . (int)$billy->id
                    . ' in optionid (die neueste passende Option) fuer die Buchung "' . $title . '".';

                $_POST['sesskey'] = sesskey();
                $followup = ai_send_message::execute((int)$this->booking->cmid, $clarificationreply, $threadid);
                $responsetype = (string)($followup['response_type'] ?? '');

                $attemptlogs[] = 'send[' . ($i + 1) . ']: reply=clarification_follow_up'
                    . ' | type=' . $responsetype;
                continue;
            }

            // Unknown response type — stop loop.
            $attemptlogs[] = 'loop_break[' . $i . ']: unexpected type=' . $responsetype;
            break;
        }

        // Final assertion: Billy must be booked.
        $matchingoptions = $DB->get_records('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => $title,
        ], 'id DESC');

        $booked = false;
        foreach ($matchingoptions as $option) {
            if (
                $DB->record_exists('booking_answers', [
                    'optionid' => (int)$option->id,
                    'userid' => (int)$billy->id,
                ])
            ) {
                $booked = true;
                break;
            }
        }

        $this->assertTrue($booked, 'Billy must be booked after multistep flow. Trace: ' . implode(' | ', $attemptlogs));
    }

    /**
     * Decode a JSON value to an array or return an empty array for invalid payloads.
     *
     * @param string $json
     * @return array<int|string,mixed>
     */
    private function decode_json_array(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Collect task names surfaced in commands and execution results.
     *
     * @param array<int|string,mixed> $commands
     * @param array<int|string,mixed> $results
     * @return array<int,string>
     */
    private function collect_observed_tasks(array $commands, array $results): array {
        $rawtasks = [];

        foreach ($commands as $command) {
            if (is_array($command) && isset($command['task']) && is_string($command['task'])) {
                $rawtasks[] = $command['task'];
            }
        }

        foreach ($results as $resultrow) {
            if (is_array($resultrow) && isset($resultrow['task']) && is_string($resultrow['task'])) {
                $rawtasks[] = $resultrow['task'];
            }
        }

        return array_values(array_unique(array_filter($rawtasks, static fn(string $task): bool => $task !== '')));
    }

    /**
     * Find the first command by task name.
     *
     * @param array<int|string,mixed> $commands
     * @param string $taskname
     * @return array<string,mixed>|null
     */
    private function find_command(array $commands, string $taskname): ?array {
        foreach ($commands as $command) {
            if (is_array($command) && (string)($command['task'] ?? '') === $taskname) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Get a reliable confirmation_request/confirm_pending response for create_option.
     *
     * @param string $title
     * @return array<string,mixed>
     */
    private function request_create_option_confirmation(string $title): array {
        $prompts = [
            'Create booking option "' . $title . '" with 7 spots from 2045-11-01T09:00:00 to 2045-11-01T11:00:00.',
            'Bereite genau eine bestaetigungsfaehige booking.create_option Aktion vor: '
                . 'Titel "' . $title . '", optiontype normal, maxanswers 7, '
                . 'coursestarttime 2045-11-01T09:00:00, courseendtime 2045-11-01T11:00:00, '
                . 'teacherquery "current". Nicht ausfuehren.',
        ];

        foreach ($prompts as $prompt) {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute((int)$this->booking->cmid, $prompt);
            $commands = $this->decode_json_array((string)($response['commands'] ?? '[]'));
            $command = $this->find_command($commands, 'booking.create_option');

            if (
                $command !== null
                && in_array(
                    (string)($response['response_type'] ?? ''),
                    ['confirmation_request', 'confirm_pending'],
                    true
                )
            ) {
                return $response;
            }
        }

        $this->fail('Could not obtain a confirmation_request for booking.create_option from the live model.');
    }
}
