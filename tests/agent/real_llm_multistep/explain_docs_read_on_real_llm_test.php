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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Real-LLM proof for reading on in a documentation page (George 2026-09-26, plan C2; L43 ED-1 thread 13343).
 *
 * The scripted test pins the engine path; this test proves the model follows the skill's hand-over: after a first read
 * of 40 lines it calls explain_docs again with doc_path and line_start from "To read on", and a long page can be read
 * to its end - by the model when the answer lies further down, and on request ("Lies weiter") turn by turn. The user
 * names the page, so the test measures the reading, not the document search.
 *
 * Run it three times; only 3 of 3 counts (Arbeitsmethode ab Welle 32).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing

 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class explain_docs_read_on_real_llm_test extends abstract_agent_testcase {
    /**
     * Real provider and all skills active.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        $this->grant_agent_capabilities_to_editingteacher();
    }

    /**
     * Inputs and observations of every explain_docs run in the thread, in order.
     *
     * @param int $threadid
     * @return array{0: array[], 1: string}
     */
    private function doc_reads(int $threadid): array {
        global $DB;
        $inputs = [];
        $observations = '';
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid], 'id ASC') as $run) {
            $commands = (array)json_decode((string)$run->commandsjson, true);
            $results = (array)json_decode((string)$run->resultsjson, true);
            foreach ($commands as $index => $command) {
                if ((string)($command['skill'] ?? '') !== 'wizard.explain_docs') {
                    continue;
                }
                $inputs[] = (array)($command['input'] ?? $command['parameters'] ?? []);
                $observations .= "\n" . (string)($results[$index]['observation_full'] ?? '');
            }
        }
        return [$inputs, $observations];
    }

    /**
     * The answer stands after line 40: the model reads on with doc_path and line_start from the first result.
     */
    public function test_the_model_reads_on_when_the_answer_is_further_down(): void {
        $this->setUser($this->teacher);
        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Schau in der Doku booking-option/09-waitinglist.md nach: Ich habe bei einer Buchungsoption die '
                . 'maximale Teilnehmerzahl von 20 auf 10 reduziert, aber alle 20 sind weiterhin gebucht. Warum?',
            (int)$threadid,
            $store,
            $runtime
        );

        [$inputs, $observations] = $this->doc_reads((int)$threadid);
        $this->assertGreaterThanOrEqual(2, count($inputs), 'a second read: ' . $this->payload_text($result));
        $continued = array_filter($inputs, static fn(array $in): bool => (int)($in['line_start'] ?? 1) > 1);
        $this->assertNotEmpty($continued, 'a read with line_start > 1: ' . json_encode($inputs));
        $this->assertStringContainsString('keepusersbookedonreducingmaxanswers', $observations, 'the section was read');
        $this->assertNotSame('error', (string)($result['response_type'] ?? ''), $this->payload_text($result));
    }

    /**
     * The answer lies beyond the first window of the longest page (429 lines): the model reads the section that holds
     * it. The page answers twice - section 2 (line 51, "Encoding: UTF-8 (important for special characters)") and
     * section 18 (line 416, "Save your CSV as UTF-8"); either is a correct read (real-LLM run 1, 2026-09-26, jumped to
     * section 2).
     */
    public function test_the_model_reads_the_section_the_answer_needs(): void {
        $this->setUser($this->teacher);
        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Lies in der Doku CSV_IMPORT_USER_GUIDE.md nach: Was soll ich tun, wenn Sonderzeichen nach dem '
                . 'CSV-Import falsch aussehen?',
            (int)$threadid,
            $store,
            $runtime
        );

        [$inputs, $observations] = $this->doc_reads((int)$threadid);
        $continued = array_filter($inputs, static fn(array $in): bool => (int)($in['line_start'] ?? 1) > 40);
        $this->assertNotEmpty($continued, 'a read beyond the first window: ' . json_encode($inputs));
        $this->assertMatchesRegularExpression(
            '/Encoding: \*\*UTF-8\*\* \(important for special characters\)|Save your CSV as UTF-8/',
            $observations,
            json_encode($inputs)
        );
        $this->assertStringContainsString('UTF-8', $this->payload_text($result));
    }

    /**
     * "Read on" on request, turn by turn: every continuation starts exactly where the previous read stopped, and the
     * longest page is read to its last line.
     */
    public function test_read_on_turn_by_turn_reaches_the_last_line(): void {
        $this->setUser($this->teacher);
        [$store, $runtime, $threadid] = $this->build_runtime();

        $messages = ['Zeig mir die Doku CSV_IMPORT_USER_GUIDE.md.'];
        $observations = '';
        for ($turn = 0; $turn < 6; $turn++) {
            $this->chat($messages[$turn] ?? 'Lies weiter.', (int)$threadid, $store, $runtime);
            [, $observations] = $this->doc_reads((int)$threadid);
            $last = substr($observations, (int)strrpos($observations, 'DOCUMENTATION GROUNDING CONTRACT'));
            if ($observations !== '' && strpos($last, 'To read on:') === false) {
                break;
            }
        }

        [$inputs] = $this->doc_reads((int)$threadid);
        preg_match_all('/Lines (\d+)–(\d+) of 430\./', $observations, $windows, PREG_SET_ORDER);
        $this->assertNotEmpty($windows, json_encode($inputs));
        $this->assertSame(1, (int)$windows[0][1], 'the first read starts at line 1: ' . json_encode($inputs));
        for ($i = 1; $i < count($windows); $i++) {
            $this->assertSame(
                (int)$windows[$i - 1][2] + 1,
                (int)$windows[$i][1],
                'each continuation starts where the previous read stopped: ' . json_encode($inputs)
            );
        }
        $this->assertSame(430, (int)end($windows)[2], 'the last read reaches the last line: ' . json_encode($inputs));
    }
}
