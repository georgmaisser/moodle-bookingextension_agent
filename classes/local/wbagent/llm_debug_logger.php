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
 * Central helper to persist raw LLM exchanges in booking debug mode.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * LLM debug logger.
 */
class llm_debug_logger {
    /**
     * Whether LLM debug logging is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        global $CFG;

        return !empty(get_config('bookingextension_agent', 'aidebugmode'))
            || (isset($CFG->debug) && (int)$CFG->debug >= DEBUG_DEVELOPER);
    }

    /**
     * Persist one raw request/response exchange.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $source
     * @param string $requesttext
     * @param string $responsetext
     * @param bool $success
     * @param string $errormessage
     * @return void
     */
    public static function log_exchange(
        conversation_store $store,
        int $threadid,
        int $cmid,
        int $userid,
        string $source,
        string $requesttext,
        string $responsetext,
        bool $success,
        string $errormessage = '',
        bool $forcelog = false
    ): void {
        if (!$forcelog && !self::is_enabled()) {
            return;
        }

        $store->add_llm_debug_entry(
            $threadid,
            $userid,
            $cmid,
            $source,
            $requesttext,
            $responsetext,
            $success ? 1 : 0,
            $errormessage
        );
    }

    /**
     * Persist one raw request/response exchange unconditionally.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $source
     * @param string $requesttext
     * @param string $responsetext
     * @param bool $success
     * @param string $errormessage
     * @return void
     */
    public static function log_exchange_always(
        conversation_store $store,
        int $threadid,
        int $cmid,
        int $userid,
        string $source,
        string $requesttext,
        string $responsetext,
        bool $success,
        string $errormessage = ''
    ): void {
        self::log_exchange(
            $store,
            $threadid,
            $cmid,
            $userid,
            $source,
            $requesttext,
            $responsetext,
            $success,
            $errormessage,
            true
        );
    }
}
