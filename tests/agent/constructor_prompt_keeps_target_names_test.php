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
 * The constructor is told to carry a target name exactly as the user wrote it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\orchestrator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Wave 18 (baseline runs 25/26). The constructor changed target names before any resolver saw them:
 * "cours de statistiques" became coursequery "statistics" although the course is "Statistiques avancées"
 * (EU-3), "eine Bestätigung" became templatequery "booking confirmation" (CRT-2), "Madame Duvernay" became
 * userquery "Madame <e-mail>" (DUA-3), "Der Buchdruck-Kurs" travelled with its article (SCC-1). None of
 * these are search defects: the resolver never got the name the user used. The constructor prompt carried
 * no rule about it. This is a prompt instruction, which the HARD RULE allows; no lexical detection is
 * involved.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\orchestrator
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class constructor_prompt_keeps_target_names_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * The default constructor template states the rule; the selector template does not need it.
     */
    public function test_the_default_constructor_template_states_the_rule(): void {
        $template = orchestrator::get_default_constructor_prompt_template();
        $this->assertStringContainsString('TARGET NAMES', $template);
        $this->assertStringContainsString('exactly as the user wrote it', $template);
    }

    /**
     * The live constructor prompt carries the rule, the live selector prompt does not.
     */
    public function test_the_live_constructor_prompt_carries_the_rule(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Name Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.update_option'),
            $this->constructor_confirmation_request('mod_booking.update_option', [
                'optionquery' => 'Name Target',
                'text' => 'Renamed Target',
            ]),
        ]);

        $this->chat('Benenn die Option "Name Target" auf "Renamed Target" um.', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedplannerprompts);
        $this->assertStringNotContainsString('TARGET NAMES', (string)$this->scriptedplannerprompts[0]);
        $this->assertStringContainsString('TARGET NAMES', (string)$this->scriptedplannerprompts[1]);
    }
}
