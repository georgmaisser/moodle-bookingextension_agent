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
 * Shared base test case for AI agent tests.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use mod_booking\local\testing\booking_advanced_testcase;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use bookingextension_agent\local\wbagent\agent_runtime;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\executor;
use bookingextension_agent\local\wbagent\interpreter;
use bookingextension_agent\local\wbagent\orchestrator;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\task_registry;
use mod_booking\singleton_service;
use stdClass;

/**
 * Abstract base for AI agent PHPUnit tests.
 *
 * Provides helpers to build a course + booking instance + options and to call
 * the executor directly (without a real LLM) so that Prompt→Result tests are
 * fully deterministic.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_agent_testcase extends booking_advanced_testcase {
    /** @var stdClass Course record. */
    protected stdClass $course;

    /** @var stdClass Booking module record (has ->cmid, ->id as bookingid). */
    protected stdClass $booking;

    /** @var stdClass Teacher user with mod/booking:useaiinstructions capability. */
    protected stdClass $teacher;

    /** @var stdClass Student user without mod/booking:useaiinstructions capability. */
    protected stdClass $student;

    /** @var \mod_booking_generator */
    protected $gen;

     /**
      * Whether a real LLM provider was registered for this test run.
      * Set to true when BOOKING_TEST_AI_KEY,
      * BOOKING_TEST_AI_MODEL and BOOKING_TEST_AI_ENDPOINT
      * are fully provided.
      *
      * @var bool
      */
    protected bool $hasliveprovider = false;

    /** @var bool Enforce generate_text debug assertion in tearDown for real-LLM tests. */
    protected bool $enforcegeneratetextassertion = false;

    /** @var array<int,int> Threads used by run_loop/chat in this test. */
    protected array $trackedllmthreadids = [];

    // -------------------------------------------------------------------------
    // Life-cycle.

     /**
      * Shared setup: course, booking instance, teacher, student.
      * Also registers a live AI provider when the three environment variables
      * BOOKING_TEST_AI_KEY, BOOKING_TEST_AI_MODEL and
      * BOOKING_TEST_AI_ENDPOINT are set.
      */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course  = $this->getDataGenerator()->create_course();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course'          => $this->course->id,
            'name'            => 'Agent Test Booking',
            'eventtype'       => 'Webinar',
            'bookingmanager'  => 'admin',
        ]);

        $this->teacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        global $PAGE;
        $PAGE->set_url('/mod/booking/view.php', ['id' => (int)$this->booking->cmid]);

        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        // Test baseline: keep governance task gates open unless a test overrides it explicitly.
        set_config('aitaskenableall', 1, 'bookingextension_agent');

        $this->maybe_register_live_ai_provider();
        $this->maybe_load_embeddings_fixture();
    }

    // -------------------------------------------------------------------------
    // AI provider registration.

     /**
      * Register a live OpenAI-compatible provider when the three environment
      * variables are present:
      *
      *   BOOKING_TEST_AI_KEY
      *   BOOKING_TEST_AI_MODEL
      *   BOOKING_TEST_AI_ENDPOINT
      *
      * Endpoint values may be either a full chat-completions URL or a base URL.
      * When only a base URL is given, "/chat/completions" is appended.
      *
      * When all three are set the provider is created and enabled so that every
      * core_ai generate_text call inside the test actually hits the real API.
      * $this->hasliveprovider is set to true so individual tests can skip or
      * adjust assertions accordingly.
      *
      * If any variable is missing the method does nothing and the provider stays
      * unconfigured (tests that depend on LLM output will receive status=error
      * from the answering service – that is expected).
      */
    protected function maybe_register_live_ai_provider(): void {
        $apikey = (string)(getenv('BOOKING_TEST_AI_KEY') ?: '');
        $model = (string)(getenv('BOOKING_TEST_AI_MODEL') ?: '');
        $minimodel = (string)(getenv('BOOKING_TEST_AI_MODEL_MINI') ?: '');
        $embeddingmodel = trim((string)(getenv('BOOKING_TEST_AI_EMBEDDING_MODEL') ?: 'wunderbyte-embeddings'));
        $endpoint = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));

        if ($apikey === '' || $model === '' || $endpoint === '') {
            return;
        }

        if ($minimodel === '') {
            $minimodel = $model;
        }

        $endpoint = rtrim($endpoint, '/');
        if (!preg_match('#/chat/completions$#', $endpoint)) {
            $endpoint .= '/chat/completions';
        }

        $manager = \core\di::get(\core_ai\manager::class);
        $manager->create_provider_instance(
            classname: '\aiprovider_openai\provider',
            name: 'booking-test-provider',
            enabled: true,
            config: ['apikey' => $apikey],
            actionconfig: [
                generate_text::class => [
                    'enabled'  => true,
                    'settings' => [
                        'model'             => $model,
                        'endpoint'          => $endpoint,
                        'systeminstruction' => '',
                    ],
                ],
                summarise_text::class => [
                    'enabled'  => true,
                    'settings' => [
                        'model'             => $minimodel,
                        'endpoint'          => $endpoint,
                        'systeminstruction' => '',
                    ],
                ],
                explain_text::class => [
                    'enabled'  => true,
                    'settings' => [
                        'model'             => $minimodel,
                        'endpoint'          => $endpoint,
                        'systeminstruction' => '',
                    ],
                ],
            ],
        );

        // Keep embeddings tests stable: enforce a dedicated embeddings model on
        // existing wunderbyte provider instances when present in the test DB.
        // Chat models like minimax-m2.7 cannot be used for /v1/embeddings.
        if ($embeddingmodel !== '') {
            $this->configure_wunderbyte_embeddings_model($embeddingmodel);
        }

        $this->hasliveprovider = true;
    }

    /**
     * Update all wunderbyte provider instances to use a working embeddings model.
     *
     * @param string $embeddingmodel
     * @return void
     */
    protected function configure_wunderbyte_embeddings_model(string $embeddingmodel): void {
        global $DB;

        $providers = $DB->get_records('ai_providers', ['provider' => 'aiprovider_wunderbyte\\provider']);
        if (empty($providers)) {
            return;
        }

        $actionkey = 'aiprovider_wunderbyte\\aiactions\\generate_embeddings';
        foreach ($providers as $provider) {
            $actionconfig = json_decode((string)($provider->actionconfig ?? ''), true);
            if (!is_array($actionconfig)) {
                continue;
            }

            if (empty($actionconfig[$actionkey]) || !is_array($actionconfig[$actionkey])) {
                continue;
            }

            if (empty($actionconfig[$actionkey]['settings']) || !is_array($actionconfig[$actionkey]['settings'])) {
                $actionconfig[$actionkey]['settings'] = [];
            }

            $actionconfig[$actionkey]['settings']['model'] = $embeddingmodel;
            $provider->actionconfig = json_encode($actionconfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $DB->update_record('ai_providers', $provider);
        }
    }

    /**
     * Load embeddings CSV fixture into temp directory for tests.
     *
     * Copies the pre-generated embeddings fixture from the tests/fixtures directory
     * into the runtime temp directory so that embeddings tests can use consistent,
     * deterministic data instead of generating embeddings on every test run.
     *
     * @return void
     */
    protected function maybe_load_embeddings_fixture(): void {
        $fixturepath = __DIR__ . '/fixtures/task_catalog_embeddings.csv';
        if (!file_exists($fixturepath)) {
            return; // Fixture not available.
        }

        $runtimedir = make_temp_directory('bookingextension_agent/wbagent');
        $runtimepath = $runtimedir . '/task_catalog_embeddings.csv';

        if (!copy($fixturepath, $runtimepath)) {
            throw new \Exception('Failed to copy embeddings fixture to runtime directory');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers: data creation.

    /**
     * Create a booking option in the shared booking instance.
     *
     * @param string $name
     * @param array  $extra Optional extra fields.
     * @return stdClass Option record (with ->id).
     */
    protected function create_option(string $name, array $extra = []): stdClass {
        $result = $this->exec_command('booking.create_option', array_merge(
            [
                'text'            => $name,
                'maxanswers'      => 10,
                'coursestarttime' => '2045-03-15T09:00:00',
                'courseendtime'   => '2045-03-15T17:00:00',
                'teacherquery'    => 'current',
            ],
            $extra
        ));
        if (($result['status'] ?? '') !== 'executed' || empty($result['resultid'])) {
            throw new \coding_exception(
                'abstract_agent_testcase::create_option failed: ' . ($result['detail'] ?? 'unknown error')
            );
        }
        return $this->get_option_from_db((int)$result['resultid']);
    }

    // -------------------------------------------------------------------------
    // Helpers: executor.

    /**
     * Build a default executor instance.
     *
     * @return executor
     */
    protected function make_executor(): executor {
        $registry = task_registry::make_default();
        $store    = new conversation_store();
        $authz    = new authorization_service();
        return new executor($registry, $store, $authz);
    }

    /**
     * Run a single agent command and return the result array.
     *
     * Sets the current user to $userid before calling (required for capability
     * checks that use the global $USER inside Moodle helper functions).
     *
     * @param string   $taskname   e.g. 'booking.create_option'
     * @param array    $input      Command input fields.
     * @param int|null $cmid       Defaults to the shared booking instance cmid.
     * @param int|null $userid     Defaults to the teacher user.
     * @return array Single result entry from the executor.
     */
    protected function exec_command(
        string $taskname,
        array $input,
        ?int $cmid = null,
        ?int $userid = null
    ): array {
        $cmid   = $cmid ?? (int)$this->booking->cmid;
        $userid = $userid ?? (int)$this->teacher->id;

        $this->setUser($userid);

        $store   = new conversation_store();
        $thread  = $store->get_or_create_thread($userid, $cmid, (int)$this->booking->id);
        $key     = hash('sha256', $taskname . ':' . $userid . ':' . uniqid('', true));
        $runid   = $store->create_run($thread->id, $userid, $cmid, $key, []);

        $exec    = $this->make_executor();
        $results = $exec->execute_commands(
            [['task' => $taskname, 'version' => 1, 'input' => $input]],
            $cmid,
            $userid,
            $key,
            $runid
        );

        return $results[0];
    }

    /**
     * Load a booking option from the DB by its id.
     *
     * @param int $optionid
     * @return stdClass
     */
    protected function get_option_from_db(int $optionid): stdClass {
        global $DB;
        return $DB->get_record('local_wbagent_options', ['id' => $optionid], '*', MUST_EXIST);
    }

    /**
     * Return all booking options that belong to the shared booking instance.
     *
     * @return stdClass[]  Indexed by option id.
     */
    protected function get_all_options(): array {
        global $DB;
        return $DB->get_records('local_wbagent_options', ['bookingid' => $this->booking->id]);
    }

    // -------------------------------------------------------------------------
    // Real-LLM runtime helpers (used by per-task real-LLM test classes).

    /**
     * Skip the current test unless the real-LLM environment is fully configured.
     *
     * Required env-vars:
     *   BOOKING_TEST_AI_KEY, BOOKING_TEST_AI_MODEL, BOOKING_TEST_AI_ENDPOINT
     */
    protected function require_real_llm(): void {
        $apikey = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $model = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $endpoint = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));

        if ($apikey === '' || $model === '' || $endpoint === '') {
            $this->markTestSkipped(
                'Real-LLM tests require BOOKING_TEST_AI_KEY + BOOKING_TEST_AI_MODEL + BOOKING_TEST_AI_ENDPOINT.'
            );
        }

        if (!$this->hasliveprovider) {
            $this->fail('Real-LLM credentials exist, but provider registration is not active.');
        }

        $this->enforcegeneratetextassertion = true;
    }

    /**
     * Build a fresh AgentRuntime, conversation store and thread for the teacher user.
     *
     * Returns [store, runtime, threadid].
     *
     * @return array{0: conversation_store, 1: agent_runtime, 2: int}
     */
    protected function build_runtime(): array {
        $store    = new conversation_store();
        $registry = task_registry::make_default();
        $orc      = new orchestrator($registry, new interpreter($registry), $store);
        $authz    = new authorization_service();
        $runtime  = new agent_runtime($registry, $orc, $store, $authz);
        $thread   = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $threadid = (int)$thread->id;
        $this->trackedllmthreadids[] = $threadid;
        return [$store, $runtime, $threadid];
    }

    /**
     * Anonymize, store and process one user message through the AgentRuntime.
     *
     * This mirrors what the real HTTP endpoint does on each user turn.
     *
     * @param string             $message  Natural-language input.
     * @param int                $threadid Conversation thread.
     * @param conversation_store $store
     * @param agent_runtime      $runtime
     * @return array AgentRuntime result array.
     */
    protected function chat(
        string $message,
        int $threadid,
        conversation_store $store,
        agent_runtime $runtime
    ): array {
        $anon     = new privacy_anonymizer($store);
        $precheck = $anon->precheck_user_message($threadid, $message);
        $store->add_message($threadid, 'user', (string)($precheck['sanitizedmessage'] ?? $message));
        return $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);
    }

    /**
     * Extract the first command of a given task name from an AgentRuntime result.
     *
     * @param array  $result   AgentRuntime result.
     * @param string $taskname e.g. 'booking.create_option'.
     * @return array|null
     */
    protected function extract_command(array $result, string $taskname): ?array {
        foreach ((array)($result['commands'] ?? []) as $cmd) {
            if (is_array($cmd) && (string)($cmd['task'] ?? '') === $taskname) {
                return $cmd;
            }
        }
        return null;
    }

    /**
     * Extract the first execution-result entry by task name (execution_result responses).
     *
     * @param array  $result   AgentRuntime result.
     * @param string $taskname e.g. 'booking.diagnose_booking_issue'.
     * @return array|null
     */
    protected function extract_task_result(array $result, string $taskname): ?array {
        foreach ((array)($result['results'] ?? []) as $entry) {
            if (is_array($entry) && (string)($entry['task'] ?? '') === $taskname) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Execute a single confirmed command via the executor and return the first result.
     *
     * @param array $command Command array (must have 'task' and 'input' keys; 'version' defaults to 1).
     * @return array Executor result for this command.
     */
    protected function execute_command(array $command): array {
        if (empty($command['input']) && !empty($command['args']['input']) && is_array($command['args']['input'])) {
            $command['input'] = $command['args']['input'];
        }
        $command['version'] = $command['version'] ?? 1;
        $key     = hash('sha256', 'test:exec:' . serialize($command) . ':' . uniqid('', true));
        $results = $this->make_executor()->execute_commands(
            [$command],
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            $key,
            0
        );
        return reset($results);
    }

    /**
     * Execute all confirmed commands from an AgentRuntime result and return all executor results.
     *
     * @param array $result AgentRuntime result (confirmation_request).
     * @return array[] Array of executor results.
     */
    protected function execute_all_commands(array $result): array {
        $commands = (array)($result['commands'] ?? []);
        if (empty($commands)) {
            return [];
        }
        foreach ($commands as &$cmd) {
            if (empty($cmd['input']) && !empty($cmd['args']['input']) && is_array($cmd['args']['input'])) {
                $cmd['input'] = $cmd['args']['input'];
            }
            $cmd['version'] = $cmd['version'] ?? 1;
        }
        unset($cmd);
        $key = hash('sha256', 'test:bulk:' . uniqid('', true));
        return $this->make_executor()->execute_commands(
            $commands,
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            $key,
            0
        );
    }

    /**
     * Assert that at least one generate_text call was logged for the given thread.
     *
     * @param int $threadid
     * @return void
     */
    protected function assert_generate_text_logged_for_thread(int $threadid): void {
        global $DB;

        $entries = $DB->get_records('local_wbagent_ai_llm_debug', ['threadid' => $threadid], 'id ASC');
        $this->assertNotEmpty($entries, 'local_wbagent_ai_llm_debug must contain entries for thread ' . $threadid . '.');

        $hasgenerate = false;
        foreach ($entries as $entry) {
            $source = (string)($entry->source ?? '');
            if (strpos($source, 'ac=gen') !== false || strpos($source, 'ac=wpl') !== false) {
                $hasgenerate = true;
                break;
            }
        }

        $this->assertTrue(
            $hasgenerate,
            'Expected at least one generate_text LLM debug entry (source contains ac=gen or ac=wpl) in local_wbagent_ai_llm_debug.'
        );
    }

    /**
     * Enforce generate_text debug assertions for all tracked threads in real-LLM tests.
     *
     * @return void
     */
    protected function tearDown(): void {
        if ($this->enforcegeneratetextassertion) {
            $this->assertNotEmpty(
                $this->trackedllmthreadids,
                'Real-LLM test must create at least one thread via build_runtime() to validate LLM debug logging.'
            );

            foreach (array_values(array_unique($this->trackedllmthreadids)) as $threadid) {
                $this->assert_generate_text_logged_for_thread((int)$threadid);
            }
        }

        parent::tearDown();
    }
}
