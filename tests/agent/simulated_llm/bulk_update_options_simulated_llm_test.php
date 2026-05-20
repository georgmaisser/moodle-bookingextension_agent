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
 * Simulated-LLM conversation tests for booking.bulk_update_options.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_simulated_llm_testcase.php');

/**
 * Deterministic bulk-update tests with scripted LLM output.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class bulk_update_options_simulated_llm_test extends abstract_simulated_llm_testcase {
    /**
     * Simulated happy path: bulk update command confirmed and executed.
     */
    public function test_simulated_bulk_update_options_happy_path(): void {
        $this->setUser($this->teacher);

        $options = [];
        for ($i = 1; $i <= 3; $i++) {
            $options[] = $this->create_option('Sim Bulk ' . $i . ' ' . uniqid('', true), ['maxanswers' => 3]);
        }
        $optionids = array_map(static fn($o): int => (int)$o->id, $options);

        $response = self::confirmation_response(
            'booking.bulk_update_options',
            [
                'optionids' => $optionids,
                'maxanswers' => 11,
            ],
            'Please confirm the bulk update.'
        );

        [$store, $runtime, $threadid] = $this->build_scripted_runtime([$response]);
        $result = $this->chat('Bulk update request', $threadid, $store, $runtime);

        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''));

        $command = $this->extract_command($result, 'booking.bulk_update_options');
        $this->assertNotNull($command);

        $execresult = $this->execute_command($command);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        foreach ($options as $option) {
            $updated = $this->get_option_from_db((int)$option->id);
            $this->assertSame(11, (int)$updated->maxanswers);
        }
    }
}
