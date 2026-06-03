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
 * External service: discard pending confirmation and clear actionable mutating queue items.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_module;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\queue\queue_manager;
use bookingextension_agent\local\wbagent\services\pending_intent_service;
use bookingextension_agent\local\wbagent\services\queue_status_policy;
use bookingextension_agent\local\wbagent\services\queue_transition_service;
use bookingextension_agent\local\wbagent\services\security\authorization_service;

/**
 * Discard pending confirmation intent and skip actionable mutating queue items in the thread.
 */
class ai_discard_pending extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
        ]);
    }

    /**
     * Discard pending confirmation intent and skip actionable mutating queue items in the thread.
     *
     * @param int $contextid
     * @param int $threadid
     * @return array<string,mixed>
     */
    public static function execute(int $contextid, int $threadid): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'threadid' => $threadid,
        ]);

        $authz = new authorization_service();
        try {
            $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
            if (!($context instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
        } catch (\Throwable $e) {
            $context = context_module::instance((int)$params['contextid'], MUST_EXIST);
        }

        $authz->require_valid_context((int)$context->id);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, (int)$context->id);

        $store = new conversation_store();
        $pendingintentsvc = new pending_intent_service($store);
        $pendingintentsvc->consume((int)$params['threadid'], (int)$USER->id, (int)$context->id);

        $queuesvc = new queue_manager($store);
        $queuetransitionsvc = new queue_transition_service();
        $discardedcount = 0;
        foreach ($queuesvc->get_queue_items((int)$params['threadid']) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $queueitemid = trim((string)($item['queue_item_id'] ?? ''));
            if ($queueitemid === '') {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            if (!queue_status_policy::is_actionable_mutating_status($status)) {
                continue;
            }

            $queuetransitionsvc->to_skipped(
                $queuesvc,
                (int)$params['threadid'],
                $queueitemid,
                'USER_DISCARDED_PENDING_CONFIRMATION',
                ['USER_DISCARDED', 'LOGICAL_SKIP'],
                'user_discarded',
                'Skipped because the user discarded the pending confirmation.'
            );
            $discardedcount++;
        }

        $message = $discardedcount > 0
            ? 'Pending confirmation and active queue items were discarded.'
            : 'No actionable mutating queue items to discard.';

        return [
            'success' => true,
            'discardedcount' => $discardedcount,
            'threadid' => (int)$params['threadid'],
            'message' => $message,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether discard request was processed.'),
            'discardedcount' => new external_value(PARAM_INT, 'Number of queue items skipped by discard.'),
            'threadid' => new external_value(PARAM_INT, 'Thread id.'),
            'message' => new external_value(PARAM_TEXT, 'Status message.'),
        ]);
    }
}
