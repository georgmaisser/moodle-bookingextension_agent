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

namespace bookingextension_agent\local\wizard\tests;

use bookingextension_agent\local\wizard\services\synchronizer_output_contract;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the source-side merge rejection behind thread 58 (a correct synthesized answer
 * discarded and replaced by "Please clarify").
 *
 * Root cause, confirmed from the aidebugmode trace of the original run (llm_debug row 6344):
 * the synchronizer returned response_type 'sufficient' with the correct multi-course answer
 * (success=1) — it was NOT at fault. The output contract discarded it on the SOURCE side.
 *
 * The turn was an any-success 'sufficient' run: the planner diagnosed three courses (successes) and
 * also made one course-less diagnose call that hard-failed with a missing_course error (pre-2a). The
 * abandon guard (reclassify_abandoned_run_as_error) keeps such a run 'sufficient' precisely because
 * at least one step succeeded — "any-success stays sufficient". But source_conflict_reason() then
 * rejects the sync message replacement anyway, because latest_source_result_is_error() sees that
 * trailing error result row (SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED). The two guards disagree
 * about the same run.
 *
 * Fix 2a removes the trigger (the course-less diagnose no longer emits an error row), which is why
 * turn 2 of the same thread — the identical answer without the hard error — was accepted. Fix 1b is
 * the defence in depth: align source_conflict_reason() with the abandon guard so an any-success
 * 'sufficient' run is not rejected merely because its last result row is an error.
 *
 * test_current_* pin today's behaviour (a regression net for fix 1b). The test_target_* case is the
 * executable spec for fix 1b — skipped until source_conflict_reason() respects the any-success
 * invariant. Removing the single markTestSkipped line turns it into the pass criterion.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_reject_error_presentation_test extends TestCase {
    /** @var string A composed, user-facing answer — no option ids, no parse/contract markers. */
    private const GOOD_MESSAGE = 'Fortschritt von Maria Huber: Kurs A teilweise abgeschlossen, '
        . 'Kurs B und Kurs C ohne Abschlussverfolgung.';

    /**
     * Build an any-success 'sufficient' source: three successful diagnoses plus one trailing hard
     * error, mirroring thread-58 turn 1 (pre-2a). No option ids in the details (keeps the fact-conflict
     * guard out of the picture) and no error_presentation flag.
     *
     * @return array
     */
    private function any_success_sufficient_source_with_trailing_error(): array {
        return [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course musisprint'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course mooduell'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course booking'],
                ['status' => 'error', 'detail' => 'missing_course'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Characterisation — pins today's behaviour (regression net for fix 1b).
    // Separator.

    /**
     * Thread 58 turn 1 reproduction: a clean 'sufficient' synchronizer answer is discarded because the
     * any-success source carries a trailing error result row. This is the exact defect — the correct
     * composed message never reaches the user.
     */
    public function test_current_trailing_error_on_sufficient_turn_is_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        $sync = ['response_type' => 'sufficient', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame('failed', $result['sync_gate_status'] ?? '');
        // The good answer is rolled back to the (message-less) source, not delivered.
        $this->assertNotSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    /**
     * The mirror image: the SAME any-success source WITHOUT the trailing error (turn 2 of the thread)
     * accepts the identical answer. Proves the trailing error row is the sole differentiator.
     */
    public function test_current_no_trailing_error_accepts_same_answer(): void {
        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        // Drop the trailing error row — leave the three successes.
        array_pop($source['results']);
        $sync = ['response_type' => 'sufficient', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertNotContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    /**
     * source_conflict_reason() already honours error_presentation_requested: an error source whose
     * cause is being deliberately presented is not rejected. Documents the consistent counter-case —
     * the flag path fix 1b should leave untouched.
     */
    public function test_current_error_presentation_source_is_accepted(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'error_presentation_requested' => true,
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        $sync = ['response_type' => 'sufficient', 'message' => 'Bitte nenne den Kurs, den ich prüfen soll.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Bitte nenne den Kurs, den ich prüfen soll.', $result['message'] ?? '');
        $this->assertNotContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertNotContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
    }

    // -----------------------------------------------------------------------
    // Executable spec for fix 1b — SKIPPED until source_conflict_reason() respects any-success.
    // Remove the markTestSkipped line once fix 1b lands.
    // Separator.

    /**
     * Target 1b: an any-success 'sufficient' run must not have its synchronizer answer rejected merely
     * because the last result row is an error — the source-side guard must respect the same invariant
     * the abandon guard already encodes ("any-success stays sufficient"). The composed message is
     * delivered; no SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED.
     */
    public function test_target_any_success_sufficient_survives_trailing_error(): void {
        $this->markTestSkipped('Pending fix 1b: source_conflict_reason() must respect any-success sufficient.');

        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        $sync = ['response_type' => 'sufficient', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertNotContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Guard: fix 1b must NOT loosen genuine failure rejections.
    // Separator.

    /**
     * A run where every step failed (abandon guard flips it to response_type 'error') must stay
     * rejected — fix 1b only concerns any-success runs, never all-failed ones.
     */
    public function test_all_failed_error_source_stays_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        $sync = ['response_type' => 'sufficient', 'message' => 'Alles bestens.'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A real parse failure must stay rejected regardless of source shape — fix 1b never touches the
     * JSON/contract defect branches in reject_reason().
     */
    public function test_parse_failure_stays_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        $sync = ['message' => 'Failed to parse LLM response as JSON. Raw excerpt: {bro'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_PARSE_FAILURE_REJECTED', (array)($result['issue_codes'] ?? []));
    }
}
