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
 * External service: poll thread messages.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\conversation_store;

/**
 * Return all messages in a conversation thread for the current user.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_poll_thread extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid'  => new external_value(PARAM_INT, 'Module context id.'),
            'threadid'   => new external_value(PARAM_INT, 'Thread id (0 = auto-resolve for current user).'),
            'lastseenid' => new external_value(PARAM_INT, 'Only fetch step messages newer than this ID.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return thread messages.
     *
     * @param int $contextid
     * @param int $threadid
     * @param int $lastseenid
     * @return array
     */
    public static function execute(int $contextid, int $threadid, int $lastseenid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid'  => $contextid,
            'threadid'   => $threadid,
            'lastseenid' => $lastseenid,
        ]);

        $authz = new authorization_service();
        if ($authz->check_use_readiness((int)$USER->id, (int)$params['contextid']) !== null) {
            // Polling stays quiet when the agent is unavailable / not permitted (no raw exception).
            return ['threadid' => 0, 'messages' => []];
        }
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);

        $store = new conversation_store();

        if ($params['threadid'] > 0) {
            $tid = $params['threadid'];
        } else {
            $thread = $store->get_or_create_thread((int)$USER->id, (int)$params['contextid']);
            $tid    = $thread->id;
        }

        // Only fetch step messages since last seen, drastically reducing payload size.
        $messages = $store->get_step_messages_since($tid, $params['lastseenid']);
        $result   = [];

        foreach ($messages as $msg) {
            $result[] = [
                'id'             => (int)$msg->id,
                'role'           => $msg->role,
                'content'        => (string)($msg->content ?? ''),
                'structuredjson' => '', // Omitted to save bandwidth.
                'timecreated'    => (int)$msg->timecreated,
            ];
        }

        return ['threadid' => $tid, 'messages' => $result];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id'             => new external_value(PARAM_INT, 'Message id.'),
                    'role'           => new external_value(PARAM_TEXT, 'Message role.'),
                    'content'        => new external_value(PARAM_RAW, 'Message content.'),
                    'structuredjson' => new external_value(PARAM_RAW, 'Structured JSON state.', VALUE_OPTIONAL),
                    'timecreated'    => new external_value(PARAM_INT, 'Creation timestamp.'),
                ])
            ),
        ]);
    }
}
