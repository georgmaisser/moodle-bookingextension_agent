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
 * Simulated embedded-catalog retrieval tests.
 *
 * @package    mod_booking
 * @category   test
 * @group      simulated_llm
 * @group      embedded_llm
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../simulated_llm/abstract_simulated_llm_testcase.php');

use bookingextension_agent\local\wbagent\embeddings_retrieval_service;

/**
 * Deterministic checks for embedded top-k vector retrieval.
 *
 * @coversNothing
 */
final class embeddings_retrieval_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Search must return the five closest catalog rows.
     */
    public function test_search_top_k_returns_five_best_rows(): void {
        $service = new embeddings_retrieval_service();

        $rows = [
            $this->row('booking.task_a', [1.0, 0.0, 0.0]),
            $this->row('booking.task_b', [0.9, 0.1, 0.0]),
            $this->row('booking.task_c', [0.8, 0.2, 0.0]),
            $this->row('booking.task_d', [0.2, 0.8, 0.0]),
            $this->row('booking.task_e', [0.1, 0.9, 0.0]),
            $this->row('booking.task_f', [0.0, 1.0, 0.0]),
        ];

        $top = $service->search_top_k([1.0, 0.0, 0.0], $rows, 5);
        $subset = $service->build_planner_catalog_subset($top);

        $this->assertCount(5, $top, 'Top-k retrieval must keep exactly five rows.');
        $this->assertCount(5, $subset, 'Planner subset must contain five task contracts.');

        $tasks = array_values(array_map(static fn(array $row): string => (string)$row['task'], $subset));
        $this->assertSame('booking.task_a', $tasks[0]);
        $this->assertContains('booking.task_b', $tasks);
        $this->assertContains('booking.task_c', $tasks);
        $this->assertNotContains('booking.task_f', $tasks);
    }

    /**
     * Build one synthetic catalog row.
     *
     * @param string $task
     * @param array<int,float> $embedding
     * @return array<string,string>
     */
    private function row(string $task, array $embedding): array {
        return [
            'task' => $task,
            'intent' => 'Synthetic intent',
            'readonly' => '1',
            'description' => 'Synthetic description for ' . $task,
            'minimal_input_json' => '[]',
            'example_input_json' => '[]',
            'message_triggers_json' => '[]',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => '3',
            'content_hash' => sha1($task),
            'embedding_json' => json_encode($embedding, JSON_UNESCAPED_UNICODE),
        ];
    }
}
