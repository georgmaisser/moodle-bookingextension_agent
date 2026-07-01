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
 * Contract tests for the reject_reason / source_conflict_reason error-presentation asymmetry
 * behind thread 58 (a correct synthesized answer discarded and replaced by "Please clarify").
 *
 * Two guards decide whether the synchronizer's message is accepted:
 *   - reject_reason()          — inspects the SYNC output. Throws SYNC_RESPONSE_TYPE_ERROR_REJECTED
 *                                whenever $sync['response_type'] === 'error'. It does NOT consult
 *                                $source['error_presentation_requested'].
 *   - source_conflict_reason() — inspects the SOURCE. It DOES honour
 *                                $source['error_presentation_requested'] before rejecting.
 *
 * That asymmetry is the thread-58 defect: when the source is a deliberate error presentation (or an
 * any-success 'sufficient' turn), a sync response_type of 'error' still trips the hard reject in
 * reject_reason(), the good message is rolled back to the source, and the user gets the template
 * "please clarify" fallback instead of the composed answer.
 *
 * The test_current_* cases pin TODAY's behaviour (a regression net for the eventual fix 1b).
 * The test_target_* cases are the executable spec for fix 1b — they are skipped until fix 1b lands
 * and reject_reason() is made consistent with source_conflict_reason(). Removing the single
 * markTestSkipped line in each turns them into the pass criteria.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_reject_error_presentation_test extends TestCase {
    /** @var string A composed, user-facing answer — no parse/contract defect markers. */
    private const GOOD_MESSAGE = 'Maria Huber ist in drei Kursen eingeschrieben. Fortschritt: Kurs A 80%, '
        . 'Kurs B 45%, Kurs C 100%.';

    // -----------------------------------------------------------------------
    // Characterisation — pins today's behaviour (regression net for fix 1b).
    // Separator.

    /**
     * source_conflict_reason() already honours error_presentation_requested: a source whose latest
     * result row is an error is NOT rejected when the flag is set and the sync output is otherwise
     * clean. This is the consistent side of the pair.
     */
    public function test_current_source_conflict_honours_error_presentation(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'error_presentation_requested' => true,
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        // Sync composes the wording without echoing an 'error' response_type.
        $sync = ['message' => 'Bitte nenne den Kurs, den ich prüfen soll.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Bitte nenne den Kurs, den ich prüfen soll.', $result['message']);
        $this->assertNotContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertNotContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * reject_reason() does NOT honour error_presentation_requested: even for a deliberate error
     * presentation, a sync response_type of 'error' is hard-rejected. This is the inconsistent side
     * of the pair — the asymmetry fix 1b closes.
     */
    public function test_current_reject_reason_ignores_error_presentation(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'error_presentation_requested' => true,
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        // The sync correctly presents the error it was fed → response_type 'error'.
        $sync = ['response_type' => 'error', 'message' => 'Ich konnte den Kurs nicht bestimmen — bitte nenne ihn.'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_RESPONSE_TYPE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame('failed', $result['sync_gate_status'] ?? '');
        // Rolled back to source — the composed wording is discarded.
        $this->assertNotSame('Ich konnte den Kurs nicht bestimmen — bitte nenne ihn.', $result['message'] ?? '');
    }

    /**
     * Thread 58 core: an any-success 'sufficient' turn (kept 'sufficient' by the abandon guard
     * because at least one skill succeeded) whose synchronizer output arrives — wrongly — as
     * response_type 'error' is currently hard-rejected, discarding the correct multi-course answer.
     */
    public function test_current_thread58_successful_source_with_sync_error_is_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course A'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course B'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course C'],
            ],
        ];
        $sync = ['response_type' => 'error', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_RESPONSE_TYPE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertNotSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Executable spec for fix 1b — SKIPPED until reject_reason() honours the flag.
    // Remove the markTestSkipped line in each once fix 1b lands.
    // Separator.

    /**
     * Target 1b: a deliberate error presentation (source flag set) whose sync correctly answers with
     * response_type 'error' must be ACCEPTED — the composed wording survives, consistent with
     * source_conflict_reason(). No SYNC_RESPONSE_TYPE_ERROR_REJECTED.
     */
    public function test_target_reject_reason_honours_error_presentation(): void {
        $this->markTestSkipped('Pending fix 1b: reject_reason() must honour error_presentation_requested.');

        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'error_presentation_requested' => true,
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        $message = 'Ich konnte den Kurs nicht bestimmen — bitte nenne ihn.';
        $sync = ['response_type' => 'error', 'message' => $message];

        $result = $contract->merge($source, $sync);

        $this->assertNotContains('SYNC_RESPONSE_TYPE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame($message, $result['message'] ?? '');
    }

    /**
     * Target 1b (thread 58): an any-success 'sufficient' source must not be discarded because the
     * synchronizer output was mislabelled response_type 'error'. The composed, non-defective message
     * (no parse/contract markers) must be accepted. This is the riskier half of 1b — it changes how
     * much the contract trusts a sync 'error' label over a successful source, and needs George's call
     * plus the aidebugmode runtime trace confirming why the interpreter set response_type 'error'.
     */
    public function test_target_thread58_successful_source_sync_error_passes(): void {
        $this->markTestSkipped('Pending fix 1b decision: trust successful source over a sync error label.');

        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course A'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course B'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course C'],
            ],
        ];
        $sync = ['response_type' => 'error', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertNotContains('SYNC_RESPONSE_TYPE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Guard: fix 1b must NOT loosen genuine parse/contract rejections.
    // Separator.

    /**
     * A real parse failure must stay rejected regardless of any error-presentation flag — fix 1b
     * only concerns the response_type 'error' branch, never the JSON/contract defect branches.
     */
    public function test_parse_failure_stays_rejected_even_with_error_presentation(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'error_presentation_requested' => true,
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'x']],
        ];
        $sync = ['message' => 'Failed to parse LLM response as JSON. Raw excerpt: {bro'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_PARSE_FAILURE_REJECTED', (array)($result['issue_codes'] ?? []));
    }
}
