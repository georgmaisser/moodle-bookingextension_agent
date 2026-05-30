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

use context_module;
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
            'contextid'     => new external_value(PARAM_INT, 'Module context id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id (0 = auto-resolve for current user).'),
        ]);
    }

    /**
     * Return thread messages.
     *
     * @param int $contextid
     * @param int $threadid
     * @return array
     */
    public static function execute(int $contextid, int $threadid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid, 'threadid' => $threadid]);

        $authz = new authorization_service();
        try {
            $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
            if (!($context instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
        } catch (\Throwable $e) {
            $context = context_module::instance((int)$params['contextid'], MUST_EXIST);
        }
        $cmid = (int)$context->instanceid;
        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, (int)$context->id);

        $store = new conversation_store();

        if ($params['threadid'] > 0) {
            $tid = $params['threadid'];
        } else {
            $cm     = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
            $thread = $store->get_or_create_thread((int)$USER->id, (int)$params['contextid'], (int)$cm->instance);
            $tid    = $thread->id;
        }

        $messages = $store->get_messages($tid);
        $result   = [];

        foreach ($messages as $msg) {
            $content = (string)($msg->content ?? '');
            if ((string)($msg->role ?? '') === 'assistant') {
                $content = ws_message_formatter::format_ws_message($content, $context);
            }

            $result[] = [
                'id'             => (int)$msg->id,
                'role'           => $msg->role,
                'content'        => $content,
                'structuredjson' => $msg->structuredjson ?? '',
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
