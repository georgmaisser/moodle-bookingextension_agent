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

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * L43 ED-1, thread 13343 (George 2026-09-26, plan C2): reading on in a documentation page must work, and a long page
 * must be readable to its end. The first read shows 40 lines; the waiting-list page answers "reduced seats, why are all
 * still booked?" from line 41 on. The skill contradicted itself: the field was line_start, the observation said
 * "read on (next_line_start) BEFORE answering", the guidance said "offer to read more". The construction dropped the
 * value and the same excerpt ran twice. Now the observation states as facts which sections are missing and where to
 * read on (doc_path + line_start), the field descriptions say where their value comes from - the same pattern as the
 * CHOICES hand-over - and a continuation reads a large window so the longest page fits the loop budget.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\wizard\skills\explain_docs_skill
 * @covers \bookingextension_agent\local\wizard\services\lookup\docs_lookup_service

 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class explain_docs_read_on_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The waiting-list page (83 lines incl. the final line break; "## Reducing the limits" starts at line 41). */
    private const WAITINGLIST = 'booking-option/09-waitinglist.md';

    /** The longest page of the corpus (429 lines). */
    private const LONGEST = 'CSV_IMPORT_USER_GUIDE.md';

    /**
     * Provider, capabilities, all skills active.
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
        set_config('aiskillenableall', 1, 'bookingextension_agent');
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Read one window of a documentation page.
     *
     * @param array $input
     * @return array
     */
    private function read(array $input): array {
        $this->setAdminUser();
        return (new explain_docs_skill())->execute(
            array_merge(['question' => 'How does it work?'], $input),
            (int)\context_system::instance()->id,
            (int)get_admin()->id
        );
    }

    /**
     * The first read states which sections it does not contain and where to read on - facts, no contradicting rules.
     */
    public function test_the_first_read_names_the_missing_sections_and_where_to_read_on(): void {
        $result = $this->read(['doc_path' => self::WAITINGLIST]);
        $observation = (string)$result['observation_full'];

        $this->assertStringContainsString('Lines 1–40 of 83.', $observation);
        $this->assertStringContainsString('"## Reducing the limits: where do users go?" (line 41)', $observation);
        $this->assertStringContainsString('To read on: doc_path=' . self::WAITINGLIST, $observation);
        $this->assertStringContainsString('line_start=41', $observation);
        $this->assertStringNotContainsString('next_line_start', $observation);
        $this->assertStringNotContainsString(
            'Reducing the limits: where do users go?' . "\n",
            $observation,
            'the section itself is not in the first window'
        );
    }

    /**
     * A continuation reads a large window: the waiting-list page is complete after the second read.
     */
    public function test_a_continuation_reads_the_rest_of_the_page(): void {
        $result = $this->read(['doc_path' => self::WAITINGLIST, 'line_start' => 41]);
        $observation = (string)$result['observation_full'];

        $this->assertStringContainsString('## Reducing the limits: where do users go?', $observation);
        $this->assertStringContainsString('keepusersbookedonreducingmaxanswers', $observation);
        $this->assertStringContainsString('Lines 41–83 of 83.', $observation);
        $this->assertStringNotContainsString('To read on:', $observation, 'the page is complete');
    }

    /**
     * The longest page is read to its end by following "To read on", within the loop budget.
     */
    public function test_the_longest_page_is_read_to_its_end_within_the_loop_budget(): void {
        $input = ['doc_path' => self::LONGEST];
        $reads = 0;
        $seen = '';
        do {
            $result = $this->read($input);
            $reads++;
            $observation = (string)$result['observation_full'];
            $seen .= $observation;
            $more = preg_match('/To read on: doc_path=(\S+), line_start=(\d+)/', $observation, $m) === 1;
            if ($more) {
                $this->assertSame(self::LONGEST, $m[1]);
                $input = ['doc_path' => $m[1], 'line_start' => (int)$m[2]];
            }
        } while ($more && $reads < 10);

        $this->assertLessThanOrEqual(4, $reads, 'first window plus large continuation windows');
        $this->assertLessThan(\bookingextension_agent\local\wizard\agent_runtime::MAX_LOOP_STEPS, $reads + 1);
        $this->assertStringContainsString('## 19. Example files', $seen);
        $this->assertStringContainsString('Save your CSV as UTF-8', $seen);
    }

    /**
     * Field descriptions, guidance and observation say the same thing, and the descriptions fit the card window.
     */
    public function test_the_contract_is_consistent_and_fits_the_card(): void {
        $skill = new explain_docs_skill();
        $properties = (array)($skill->get_schema()['properties'] ?? []);
        // The window size is the skill's decision: no line_count for the model to set.
        $this->assertArrayNotHasKey('line_count', $properties);
        foreach (['line_start', 'doc_path', 'corpus_id', 'doc_path_candidates'] as $field) {
            $this->assertLessThanOrEqual(159, mb_strlen((string)$properties[$field]['description']), $field);
        }
        $this->assertStringContainsString('To read on', (string)$properties['line_start']['description']);
        $this->assertStringContainsString('To read on', (string)$properties['doc_path']['description']);

        $guidance = implode("\n", (array)($skill->get_contextual_prompt_packs()[0]['guidance'] ?? []));
        $this->assertStringContainsString('To read on', $guidance);
        $this->assertStringNotContainsString('offer to read more', $guidance);
        $this->assertStringNotContainsString('next_line_start', $guidance);
    }

    /**
     * Thread 13343 replayed: the second construction carries doc_path and line_start from the first result, the
     * second run reads the missing section, and the turn ends.
     */
    public function test_the_second_read_continues_where_the_first_stopped(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $question = 'Ich habe die Plätze einer Option von 20 auf 10 reduziert, aber alle 20 sind noch gebucht. Warum?';
        $this->install_phase_scripted_planner(
            [
                $this->selector_skill_call('wizard.explain_docs'),
                $this->selector_skill_call('wizard.explain_docs'),
                $this->planner_sufficient(''),
            ],
            [
                $this->constructor_skill_call('wizard.explain_docs', ['question' => $question, 'doc_path' => self::WAITINGLIST]),
                $this->constructor_skill_call('wizard.explain_docs', [
                    'question' => $question,
                    'doc_path' => self::WAITINGLIST,
                    'line_start' => 41,
                ]),
            ]
        );

        $this->chat($question, (int)$threadid, $store, $runtime);

        $inputs = [];
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid], 'id ASC') as $run) {
            foreach ((array)json_decode((string)$run->commandsjson, true) as $command) {
                if ((string)($command['skill'] ?? '') === 'wizard.explain_docs') {
                    $inputs[] = (array)($command['input'] ?? $command['parameters'] ?? []);
                }
            }
        }
        $this->assertCount(2, $inputs, $this->scripted_phase_sequence());
        $this->assertSame(41, (int)($inputs[1]['line_start'] ?? 0));
        $constructorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') !== false
        ));
        $this->assertStringContainsString('To read on: doc_path=' . self::WAITINGLIST . ', line_start=41', $constructorprompts[1]);
        $sync = implode("\n", $this->scriptedsyncprompts);
        $this->assertStringContainsString('keepusersbookedonreducingmaxanswers', $sync, 'the synchronizer sees the section');
    }
}
