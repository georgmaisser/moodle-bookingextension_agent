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

declare(strict_types=1);

namespace bookingextension_agent\tests\agent\contracts;

use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service;
use advanced_testcase;

/**
 * Tests for family embeddings retrieval helper.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class family_embeddings_retrieval_service_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Verifies that family scores can re-rank skill rows.
     *
     * @covers \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service::boost_skill_rows
     */
    public function test_boost_skill_rows_uses_family_scores(): void {
        $service = new family_embeddings_retrieval_service();

        $rows = $service->boost_skill_rows([
            ['skill' => 'mod_booking.create_option', 'score' => '0.20'],
            ['skill' => 'mod_booking.list_options', 'score' => '0.10'],
            ['skill' => 'core.get_current_user', 'score' => '0.50'],
        ], [
            'mod_booking.general' => 0.90,
            'core.general' => 0.10,
        ]);

        $this->assertSame('mod_booking.create_option', $rows[0]['skill']);
        $this->assertSame('mod_booking.general', $rows[0]['family']);
        $this->assertSame(0.41, round((float)$rows[0]['score'], 2));
        $families = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['family'], $rows)));
        $this->assertContains('mod_booking.general', $families);
        $this->assertContains('core.general', $families);
    }

    /**
     * Verifies that only requested families receive semantic scores.
     *
     * @covers \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service::score_families
     */
    public function test_score_families_returns_requested_families_only(): void {
        $service = new family_embeddings_retrieval_service();

        $scores = $service->score_families([
            'mod_booking.general',
            'local_entities.general',
        ], [1.0, 0.0], [
            ['skill' => 'mod_booking.create_option', 'embedding_json' => json_encode([1.0, 0.0])],
            ['skill' => 'forum_reply', 'embedding_json' => json_encode([0.0, 1.0])],
        ]);

        $this->assertArrayHasKey('mod_booking.general', $scores);
        $this->assertArrayHasKey('local_entities.general', $scores);
        $this->assertSame(1.0, round($scores['mod_booking.general'], 2));
        $this->assertSame(0.0, round($scores['local_entities.general'], 2));
    }
}
