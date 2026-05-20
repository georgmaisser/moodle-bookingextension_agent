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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Contract coverage for session-scoped confirmation allowances.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use mod_booking\local\testing\booking_advanced_testcase;
use bookingextension_agent\local\wbagent\conversation_store;

/**
 * Session allowlist contract tests.
 *
 * @coversNothing
 */
final class confirmation_session_allow_service_test extends booking_advanced_testcase {
    /**
     * The allowlist must persist per user and cmid, independent of thread id.
     */
    public function test_allowlist_persists_per_cmid_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $service = new conversation_store();
        global $USER;
        $userid = (int)$USER->id;
        $cmid = 101;
        $threadid = 202;

        $service->allow_confirmation_for_thread($userid, $cmid, $threadid, time() + 60);

        $this->assertTrue($service->is_confirmation_allowed_for_thread($userid, $cmid, $threadid));
        $this->assertTrue($service->is_confirmation_allowed_for_thread($userid, $cmid, $threadid + 1));
        $this->assertFalse($service->is_confirmation_allowed_for_thread($userid, $cmid + 1, $threadid));

        $service->clear_confirmation_allowance($userid, $cmid, $threadid);
        $this->assertFalse($service->is_confirmation_allowed_for_thread($userid, $cmid, $threadid));
    }

    /**
     * Expired allowlist entries must be ignored and pruned.
     */
    public function test_allowlist_prunes_expired_entries_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $service = new conversation_store();
        global $USER;
        $userid = (int)$USER->id;
        $cmid = 303;
        $threadid = 404;

        $service->allow_confirmation_for_thread($userid, $cmid, $threadid, time() - 1);

        $this->assertFalse($service->is_confirmation_allowed_for_thread($userid, $cmid, $threadid));
    }
}
