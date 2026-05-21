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
 * Contract tests for ai_confirm_run external API schema.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use bookingextension_agent\external\ai_confirm_run;
use mod_booking\local\testing\booking_advanced_testcase;

/**
 * Keep the ai_confirm_run external contract stable.
 *
 * @runTestsInSeparateProcesses
 * @coversNothing
 *
 * @package    bookingextension_agent
 * @category   test
 */
final class ai_confirm_run_internal_test extends booking_advanced_testcase {
    /**
     * execute_parameters exposes the required input fields.
     */
    public function test_execute_parameters_exposes_required_fields(): void {
        $params = ai_confirm_run::execute_parameters();
        $this->assertNotNull($params->keys['cmid'] ?? null);
        $this->assertNotNull($params->keys['threadid'] ?? null);
        $this->assertNotNull($params->keys['commands'] ?? null);
        $this->assertNotNull($params->keys['allow_session'] ?? null);
    }

    /**
     * execute_returns keeps the compact API payload stable.
     */
    public function test_execute_returns_exposes_expected_fields(): void {
        $returns = ai_confirm_run::execute_returns();

        $expectedkeys = [
            'success',
            'runid',
            'threadid',
            'response_type',
            'message',
            'displaymessage',
            'privacyapplied',
            'autoconfirm',
            'commands',
            'resultsjson',
            'attemptedtasksjson',
            'issuecodesjson',
            'errorsjson',
            'pendingconfirmationcode',
            'previewoptionid',
        ];

        foreach ($expectedkeys as $key) {
            $this->assertNotNull($returns->keys[$key] ?? null, 'Missing return key: ' . $key);
        }
    }
}
