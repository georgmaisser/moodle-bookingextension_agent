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

use bookingextension_agent\local\wbagent\services\embeddings\family_embeddings_retrieval_service;
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
     * Verifies that family scores can re-rank task rows.
     *
     * @covers \bookingextension_agent\local\wbagent\services\embeddings\family_embeddings_retrieval_service::boost_task_rows
     */
    public function test_boost_task_rows_uses_family_scores(): void {
        $service = new family_embeddings_retrieval_service();

        $rows = $service->boost_task_rows([
            ['task' => 'booking_create', 'score' => '0.20'],
            ['task' => 'booking_list', 'score' => '0.10'],
            ['task' => 'calendar_view', 'score' => '0.50'],
        ], [
            'booking' => 0.90,
            'calendar' => 0.10,
        ]);

        $this->assertSame('booking_create', $rows[0]['task']);
        $this->assertSame('booking', $rows[0]['family']);
        $this->assertSame(0.41, round((float)$rows[0]['score'], 2));
        $this->assertSame('calendar_view', $rows[2]['task']);
        $this->assertSame('calendar', $rows[2]['family']);
    }

    /**
     * Verifies that only requested families receive semantic scores.
     *
     * @covers \bookingextension_agent\local\wbagent\services\embeddings\family_embeddings_retrieval_service::score_families
     */
    public function test_score_families_returns_requested_families_only(): void {
        $service = new family_embeddings_retrieval_service();

        $scores = $service->score_families([
            'booking',
            'calendar',
        ], [1.0, 0.0], [
            ['task' => 'booking_create', 'embedding_json' => json_encode([1.0, 0.0])],
            ['task' => 'forum_reply', 'embedding_json' => json_encode([0.0, 1.0])],
        ]);

        $this->assertArrayHasKey('booking', $scores);
        $this->assertArrayHasKey('calendar', $scores);
        $this->assertSame(1.0, round($scores['booking'], 2));
        $this->assertSame(0.0, round($scores['calendar'], 2));
    }
}
