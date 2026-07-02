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
use bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_migration_service;

/**
 * P3: CSV → DB migration copies vectors without re-embedding.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\embeddings_store_migration_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_store_migration_test extends advanced_testcase {
    /** Test embedding model. */
    private const MODEL = 'test-model';

    /** Test embedding dimensions. */
    private const DIMS = 4;

    /** Area under test. */
    private const AREA = docs_row_mapper::AREA;

    /**
     * A CSV store over the registered mappers.
     *
     * @return csv_embeddings_store
     */
    private function csv_store(): csv_embeddings_store {
        return new csv_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * A DB store over the registered mappers.
     *
     * @return db_embeddings_store
     */
    private function db_store(): db_embeddings_store {
        return new db_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * Seed a two-row committed CSV index (exactly-representable vectors) plus a fingerprint.
     *
     * @return void
     */
    private function seed_csv(): void {
        $csv = $this->csv_store();
        $gen = $csv->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $csv->upsert(self::AREA, $gen, new embedding_row(
            self::AREA,
            'mod_booking',
            'a.md',
            1,
            'A',
            self::MODEL,
            self::DIMS,
            'h1',
            [0.5, -0.25, 0.125, -1.0],
            10
        ));
        $csv->upsert(self::AREA, $gen, new embedding_row(
            self::AREA,
            'mod_booking',
            'b.md',
            1,
            'B',
            self::MODEL,
            self::DIMS,
            'h2',
            [0.0, 1.0, 0.0, 0.0],
            10
        ));
        $csv->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);
        $csv->set_fingerprint(self::AREA, self::MODEL, self::DIMS, 'fp-csv');
    }

    /**
     * Migration copies every CSV row (vectors + hashes + fingerprint) into the DB backend.
     */
    public function test_migrate_copies_csv_to_db(): void {
        $this->resetAfterTest();
        $this->seed_csv();

        $result = (new embeddings_store_migration_service())->migrate_csv_to_db(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['migrated']);

        $db = $this->db_store();
        $this->assertTrue($db->exists(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame(2, $db->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame('fp-csv', $db->fingerprint(self::AREA, self::MODEL, self::DIMS));

        $bykey = [];
        foreach ($db->stream_rows(self::AREA, self::MODEL, self::DIMS) as $row) {
            $bykey[$row->refkey] = $row;
        }
        $this->assertSame([0.5, -0.25, 0.125, -1.0], $bykey['a.md']->embedding);
        $this->assertSame('h1', $bykey['a.md']->contenthash);
        $this->assertSame(10, $bykey['a.md']->endindex);

        // The migrated identity is queryable for reuse (proves identityhash was written on upsert).
        $reused = $db->reuse_existing(self::AREA, self::MODEL, self::DIMS, 'mod_booking|a.md|1');
        $this->assertNotNull($reused);
        $this->assertSame('h1', $reused->contenthash);
    }

    /**
     * The if-needed variant does not clobber an already-populated DB index.
     */
    public function test_migrate_if_needed_skips_when_db_populated(): void {
        $this->resetAfterTest();
        $this->seed_csv();

        $svc = new embeddings_store_migration_service();
        $svc->migrate_csv_to_db(self::AREA, self::MODEL, self::DIMS);

        $again = $svc->migrate_csv_to_db_if_needed(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame('skipped', $again['status']);
        $this->assertSame('db_already_populated', $again['reason']);
    }

    /**
     * With no CSV index present, migration is a no-op (the rebuild fallback handles population).
     */
    public function test_migrate_skips_when_no_csv(): void {
        $this->resetAfterTest();

        $result = (new embeddings_store_migration_service())->migrate_csv_to_db(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_csv_index', $result['reason']);
    }
}
