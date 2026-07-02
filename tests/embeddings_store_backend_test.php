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
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;

/**
 * P2 wiring: the embeddingsstore flag selects the backend, and the docs services read the DB backend
 * end-to-end when it is active.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_store_backend_test extends advanced_testcase {
    /**
     * The default and the explicit csv flag resolve to the CSV backend; db resolves to the DB backend.
     */
    public function test_factory_selects_backend_by_flag(): void {
        $this->resetAfterTest();

        // Default (unset) → CSV.
        $this->assertInstanceOf(csv_embeddings_store::class, embeddings_store_factory::instance());

        set_config('embeddingsstore', 'csv', 'bookingextension_agent');
        $this->assertInstanceOf(csv_embeddings_store::class, embeddings_store_factory::instance());

        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        $this->assertInstanceOf(db_embeddings_store::class, embeddings_store_factory::instance());
    }

    /**
     * With the DB backend active, the docs readiness service reports coverage/readiness from rows
     * written to the DB store (proving the service reads through the flag, not the CSV file).
     */
    public function test_docs_readiness_reads_db_backend(): void {
        $this->resetAfterTest();

        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        set_config('embeddingsstore', 'db', 'bookingextension_agent');

        // Two resolvable, empty corpora (no .md files → a stable, reproducible source fingerprint).
        $base = make_request_directory();
        mkdir($base . '/a', 0777, true);
        mkdir($base . '/b', 0777, true);
        docs_corpus_registry::set_corpora_for_testing(['corpa' => $base . '/a', 'corpb' => $base . '/b']);

        [$model, $dims] = $this->active_variant();
        $store = new db_embeddings_store(embeddings_store_factory::mappers());

        // Baseline: nothing committed → not covered.
        $readiness = new docs_embeddings_readiness_service();
        $this->assertFalse($readiness->is_index_covered());
        $this->assertSame('index_csv_not_found', $readiness->get_status()['reason']);

        // Populate one row per corpus in the DB backend for the active variant, then stamp the source
        // fingerprint exactly as a full rebuild would.
        $gen = $store->begin_generation(docs_row_mapper::AREA, $model, $dims);
        foreach (['corpa', 'corpb'] as $cid) {
            $store->upsert(docs_row_mapper::AREA, $gen, new embedding_row(
                docs_row_mapper::AREA,
                $cid,
                'README.md',
                1,
                'Readme',
                $model,
                $dims,
                sha1($cid),
                [0.1, 0.2],
                1
            ));
        }
        $store->commit_generation(docs_row_mapper::AREA, $model, $dims, $gen);
        $store->set_fingerprint(
            docs_row_mapper::AREA,
            $model,
            $dims,
            (new docs_embeddings_index_service())->compute_source_fingerprint()
        );

        // Now the (DB-backed) readiness sees full coverage and no drift.
        $this->assertTrue($readiness->is_index_covered());
        $this->assertTrue($readiness->get_status()['ready']);

        $summary = $readiness->get_corpus_index_summary();
        $this->assertTrue($summary['indexready']);
        $this->assertSame(2, $summary['chunks']);

        // The CSV backend is a separate store: switching back finds no index (proving isolation).
        set_config('embeddingsstore', 'csv', 'bookingextension_agent');
        $this->assertSame('index_csv_not_found', (new docs_embeddings_readiness_service())->get_status()['reason']);

        docs_corpus_registry::set_corpora_for_testing(null);
    }

    /**
     * The active embeddings variant (model, dimensions).
     *
     * @return array
     */
    private function active_variant(): array {
        $resolved = (new embeddings_action_config_resolver())->resolve();
        return [(string)$resolved['model'], (int)$resolved['dimensions']];
    }
}
