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
 * Contract tests for ai_send_message external API schema.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../classes/local/testing/booking_advanced_testcase.php');

use mod_booking\local\testing\booking_advanced_testcase;
use mod_booking\external\ai_send_message;

/**
 * Keep the ai_send_message external contract stable.
 *
 * @runTestsInSeparateProcesses
 * @coversNothing
 *
 * @package    mod_booking
 * @category   test
 */
final class ai_send_message_internal_test extends booking_advanced_testcase {
    /**
     * execute_parameters exposes the required input fields.
     */
    public function test_execute_parameters_exposes_required_fields(): void {
        $params = ai_send_message::execute_parameters();
        $this->assertNotNull($params->keys['cmid'] ?? null);
        $this->assertNotNull($params->keys['message'] ?? null);
    }

    /**
     * execute_returns keeps all API payload keys expected by the frontend.
     */
    public function test_execute_returns_exposes_expected_fields(): void {
        $returns = ai_send_message::execute_returns();

        $expectedkeys = [
            'response_type',
            'message',
            'displaymessage',
            'privacyapplied',
            'autoconfirm',
            'commands',
            'ambiguities',
            'ambiguityoptionsjson',
            'errorsjson',
            'attemptedtasksjson',
            'issuecodesjson',
            'pendingconfirmationcode',
            'threadid',
            'runid',
            'resultsjson',
            'previewoptionid',
        ];

        foreach ($expectedkeys as $key) {
            $this->assertNotNull($returns->keys[$key] ?? null, 'Missing return key: ' . $key);
        }
    }
}
