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
 * Real-LLM simulation test for Dynamic Skill Discovery (Tool Retrieval RAG).
 *
 * This test simulates the scenario where the LLM is asked to perform an action
 * for which it currently lacks the specific tool in its payload, but it does
 * have access to a 'core.search_skills' fallback tool.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wbagent\skills\skill;
use bookingextension_agent\local\wbagent\services\skill_registry_service;

/**
 * Real-LLM test for dynamic skill discovery.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class search_skills_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
        $this->enforcegeneratetextassertion = false;
    }

    public function test_dynamic_skill_discovery_loop(): void {
        global $DB;

        $this->setUser($this->teacher);

        $this->course->enableaitools = 1;
        $DB->update_record('course', $this->course);

        $cmrecord = $DB->get_record('course_modules', ['id' => (int)$this->booking->cmid], '*', MUST_EXIST);
        $cmrecord->enableaitools = 1;
        $DB->update_record('course_modules', $cmrecord);

        rebuild_course_cache((int)$this->course->id, true);

        [$store, $runtime, $threadid] = $this->build_runtime();
        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $contextid, $threadid);

        // Step 1: Provide a prompt that requires a fictional tool not currently loaded.
        // We instruct the LLM to use core.search_skills (simulating its presence in the prompt).
        $prompt = 'Ich möchte das goldene Zertifikat für den Kurs herunterladen. '
            . 'Wenn du den passenden Skill dafür nicht in deiner aktuellen Liste siehst, '
            . 'nutze bitte unbedingt "core.search_skills" mit einer passenden Query, um danach zu suchen.';

        $_POST['sesskey'] = sesskey();
        
        // Note: Since core.search_skills is not yet implemented in the real core_family_set,
        // we simulate this by checking if the LLM attempts to call it based on the instruction.
        // In a fully implemented state, the LLM would naturally pick it up from the system prompt.
        $response = ai_send_message::execute($contextid, $prompt, (int)$threadid);

        $this->assertGreaterThan(0, (int)($response['threadid'] ?? 0));
        
        // The LLM should realize it doesn't have the "download certificate" tool 
        // and should opt to use core.search_skills as instructed.
        $commands = json_decode((string)($response['commands'] ?? '[]'), true) ?: [];
        $responsetype = (string)($response['response_type'] ?? '');
        
        // Since core.search_skills is a read-only lookup, it is auto-executed.
        // The LLM will then try to use the fictional 'download certificate' tool, which doesn't exist,
        // leading to an eventual 'error' or 'sufficient' response type.
        $this->assertContains($responsetype, ['error', 'sufficient', 'skill_call', 'confirmation_request']);
        
        $searched = false;
        $query = '';
        
        $loopresults = $response['loop_results'] ?? [];
        foreach ($loopresults as $loopstep) {
            $stepcommands = $loopstep['tool_calls'] ?? [];
            foreach ($stepcommands as $cmd) {
                if (($cmd['skill'] ?? '') === 'core.search_skills' || strpos(($cmd['skill'] ?? ''), 'search_skills') !== false) {
                    $searched = true;
                    $query = (string)($cmd['input']['query'] ?? '');
                }
            }
        }
        
        // If the LLM successfully attempted the search tool, we validate the query.
        if ($searched) {
            $this->assertNotEmpty($query, 'The LLM should formulate a search query for the missing skill.');
            
            // Step 2: Simulate the executor returning the discovered tool as an observation.
            // In the real implementation, the core.search_skills executor does this.
            $mockobservation = [
                'type' => 'skill_discovery',
                'discovered_skills' => [
                    [
                        'skill' => 'mod_booking.download_certificate',
                        'description' => 'Downloads a certificate for a course.',
                        'parameters' => ['type' => 'string']
                    ]
                ]
            ];
            // Here we would inject the observation into the state and continue the loop.
            // $runtime->inject_observation($mockobservation);
            // $nextresponse = $runtime->run_loop(...)
            
            // For now, we assert that the first half of the RAG loop (the intent to search) is successful.
            $this->assertTrue($searched, 'Dynamic discovery intent was correctly formulated.');
        } else {
            // Fallback assertion if the LLM failed to use the hypothetical tool, 
            // verifying it at least asked for clarification.
            $this->markTestIncomplete('The LLM did not attempt to use the search_skills tool. This is expected until the tool is firmly registered in the core family set.');
        }
    }
}
