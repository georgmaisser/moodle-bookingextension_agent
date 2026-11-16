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
use bookingextension_agent\task\cleanup_old_llm_debug_task;

/**
 * The LLM debug cleanup task follows the llm_debug_retention_days setting: 0 keeps the entries forever,
 * an unset setting falls back to 30 days (Wunderbyte-GmbH/Wunderbyte-GmbH#2585).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\task\cleanup_old_llm_debug_task
 * @covers     \bookingextension_agent\local\wizard\config\retention_setting
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_old_llm_debug_task_test extends advanced_testcase {
    /**
     * Insert one debug row that is $agedays old and return its id.
     *
     * @param int $agedays
     * @return int
     */
    private function insert_debug_row(int $agedays): int {
        global $DB;

        return (int)$DB->insert_record('bx_agent_ai_llm_debug', (object)[
            'threadid' => 0,
            'userid' => 2,
            'contextid' => \context_system::instance()->id,
            'source' => 'test',
            'requesttext' => 'request',
            'responsetext' => 'response',
            'success' => 1,
            'timecreated' => time() - ($agedays * DAYSECS),
        ]);
    }

    /**
     * Run the task and discard its log output.
     */
    private function run_task(): void {
        ob_start();
        (new cleanup_old_llm_debug_task())->execute();
        ob_end_clean();
    }

    /**
     * A retention of 0 keeps every entry, also very old ones.
     */
    public function test_zero_keeps_entries_forever(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('llm_debug_retention_days', '0', 'bookingextension_agent');
        $old = $this->insert_debug_row(400);

        $this->run_task();

        $this->assertTrue($DB->record_exists('bx_agent_ai_llm_debug', ['id' => $old]));
    }

    /**
     * An unset setting uses the default of 30 days.
     */
    public function test_unset_setting_uses_default(): void {
        global $DB;
        $this->resetAfterTest();

        unset_config('llm_debug_retention_days', 'bookingextension_agent');
        $old = $this->insert_debug_row(40);
        $recent = $this->insert_debug_row(10);

        $this->run_task();

        $this->assertFalse($DB->record_exists('bx_agent_ai_llm_debug', ['id' => $old]));
        $this->assertTrue($DB->record_exists('bx_agent_ai_llm_debug', ['id' => $recent]));
    }

    /**
     * A positive retention deletes only the entries older than it.
     */
    public function test_positive_retention_deletes_only_older_entries(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('llm_debug_retention_days', '5', 'bookingextension_agent');
        $old = $this->insert_debug_row(10);
        $recent = $this->insert_debug_row(2);

        $this->run_task();

        $this->assertFalse($DB->record_exists('bx_agent_ai_llm_debug', ['id' => $old]));
        $this->assertTrue($DB->record_exists('bx_agent_ai_llm_debug', ['id' => $recent]));
    }
}
