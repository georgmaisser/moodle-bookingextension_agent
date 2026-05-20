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
 * Real-LLM embedded planner telemetry tests.
 *
 * @package    mod_booking
 * @category   test
 * @group      real_llm
 * @group      embedded_llm
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\local\wbagent\embeddings_csv_repository;

/**
 * Opt-in runtime checks for embeddings planner telemetry markers.
 *
 * @coversNothing
 */
final class embeddings_runtime_real_llm_test extends abstract_agent_testcase {
    /**
     * Require a live provider in this suite.
     */
    protected function setUp(): void {
        parent::setUp();

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

        $runtimechatmodel = trim((string)(getenv('BOOKING_TEST_AI_RUNTIME_MODEL') ?: 'wunderbyte-privat'));
        $runtimeminimodel = trim((string)(getenv('BOOKING_TEST_AI_RUNTIME_MODEL_MINI') ?: 'wunderbyte-privat-mini'));
        $this->configure_runtime_test_models($runtimechatmodel, $runtimeminimodel);

        // This suite validates embeddings telemetry markers, not generate_text routing.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Force runtime chat models for the temporary OpenAI test provider.
     *
     * @param string $chatmodel
     * @param string $minimodel
     * @return void
     */
    private function configure_runtime_test_models(string $chatmodel, string $minimodel): void {
        global $DB;

        $provider = $DB->get_record('ai_providers', ['name' => 'booking-test-provider'], '*', IGNORE_MISSING);
        if (!$provider) {
            return;
        }

        $actionconfig = json_decode((string)($provider->actionconfig ?? ''), true);
        if (!is_array($actionconfig)) {
            return;
        }

        $gen = \core_ai\aiactions\generate_text::class;
        $sum = \core_ai\aiactions\summarise_text::class;
        $exp = \core_ai\aiactions\explain_text::class;

        foreach ([$gen => $chatmodel, $sum => $minimodel, $exp => $minimodel] as $action => $model) {
            if (empty($actionconfig[$action]) || !is_array($actionconfig[$action])) {
                continue;
            }
            if (empty($actionconfig[$action]['settings']) || !is_array($actionconfig[$action]['settings'])) {
                $actionconfig[$action]['settings'] = [];
            }
            $actionconfig[$action]['settings']['model'] = $model;
        }

        $provider->actionconfig = json_encode($actionconfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $DB->update_record('ai_providers', $provider);
    }

    /**
     * First planner turn must emit embeddings telemetry markers in LLM debug logs.
     */
    public function test_first_turn_emits_embeddings_telemetry_markers(): void {
        global $DB;

        $this->setUser($this->teacher);

        // Force a cold-start catalog state so fallback + queue telemetry is visible.
        $repo = new embeddings_csv_repository();
        $path = $repo->get_csv_path();
        if (is_file($path)) {
            @unlink($path);
        }

        [$store, $runtime, $threadid] = $this->build_runtime();
        $result = $this->chat(
            'Prepare one booking.create_option command for title "Embedding Runtime Test", '
            . 'maxanswers 10, start 2045-12-01T09:00:00, end 2045-12-01T11:00:00.',
            $threadid,
            $store,
            $runtime
        );

        if ((string)($result['response_type'] ?? '') === 'error') {
            $result = $this->chat(
                'Prepare exactly one booking.create_option confirmation_request for title "Embedding Runtime Test", '
                . 'optiontype normal, maxanswers 10, coursestarttime 2045-12-01T09:00:00, '
                . 'courseendtime 2045-12-01T11:00:00. Do not execute.',
                $threadid,
                $store,
                $runtime
            );
        }

        $this->assertNotSame(
            'error',
            (string)($result['response_type'] ?? ''),
            'Embedded runtime call still returned error: ' . json_encode($result, JSON_UNESCAPED_UNICODE)
        );

        $entries = $DB->get_records('local_wbagent_ai_llm_debug', ['threadid' => $threadid], 'id ASC');
        $this->assertNotEmpty($entries, 'Expected local_wbagent_ai_llm_debug rows for runtime telemetry assertions.');

        $hasmarker = false;
        foreach ($entries as $entry) {
            $source = (string)($entry->source ?? '');
            if (
                strpos($source, '|cm=') !== false
                && strpos($source, '|em=') !== false
                && strpos($source, '|rq=') !== false
            ) {
                $hasmarker = true;
                break;
            }
        }

        $this->assertTrue($hasmarker, 'Expected embeddings telemetry markers (cm/em/rq) in debug source.');
    }
}
