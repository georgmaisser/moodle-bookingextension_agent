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

use advanced_testcase;
use bookingextension_agent\local\wbagent\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wbagent\services\lookup\docs_embeddings_csv_repository;
use bookingextension_agent\local\wbagent\services\lookup\docs_embeddings_readiness_service;

/**
 * Cheap coverage check on the synchronous skill-use path (Phase B3).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wbagent\services\lookup\docs_embeddings_readiness_service
 */
final class docs_embeddings_readiness_coverage_test extends advanced_testcase {
    /**
     * Register two resolvable corpora.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }

        // Scheduling is gated on the docs skill being active (Phase E1).
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $base = make_request_directory();
        mkdir($base . '/a', 0777, true);
        mkdir($base . '/b', 0777, true);
        docs_corpus_registry::set_corpora_for_testing(['corpa' => $base . '/a', 'corpb' => $base . '/b']);
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([]);
    }

    /**
     * Restore parsing.
     */
    public function tearDown(): void {
        docs_corpus_registry::set_corpora_for_testing(null);
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([]);
        parent::tearDown();
    }

    /**
     * Build a minimal valid index row.
     *
     * @param string $corpusid
     * @return array<string,string>
     */
    private function row(string $corpusid): array {
        return [
            'corpus_id' => $corpusid, 'chunk_path' => 'README.md', 'chunk_title' => '',
            'line_start' => '1', 'line_end' => '1', 'embedding_model' => 'm',
            'embedding_dimensions' => '8', 'content_hash' => sha1($corpusid), 'embedding_json' => '[0.1]',
        ];
    }

    /**
     * An index that covers only one of two resolvable corpora is reported as not covered.
     */
    public function test_partial_coverage_is_not_ready(): void {
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([$this->row('corpa')]);

        $readiness = new docs_embeddings_readiness_service();
        $this->assertFalse($readiness->is_index_covered());
        $this->assertSame('corpora_not_covered', $readiness->get_status()['reason']);
    }

    /**
     * Full coverage of every resolvable corpus is ready.
     */
    public function test_full_coverage_is_ready(): void {
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([$this->row('corpa'), $this->row('corpb')]);

        $readiness = new docs_embeddings_readiness_service();
        $this->assertTrue($readiness->is_index_covered());
        $this->assertTrue($readiness->get_status()['ready']);
    }

    /**
     * A coverage gap schedules a rebuild task (once, thanks to the debounce).
     */
    public function test_coverage_gap_schedules_rebuild(): void {
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([$this->row('corpa')]);

        $readiness = new docs_embeddings_readiness_service();
        $this->assertTrue($readiness->ensure_rebuild_scheduled_if_needed(), 'First uncovered call queues a task.');
        $this->assertFalse($readiness->ensure_rebuild_scheduled_if_needed(), 'Debounce blocks an immediate re-queue.');
    }
}
