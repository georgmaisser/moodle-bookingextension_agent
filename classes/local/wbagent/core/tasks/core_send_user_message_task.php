<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wbagent\core\tasks;

use context_system;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_send_user_message_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_send_user_message';

    public function __construct() {
        parent::__construct(false);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Send a Moodle user-to-user message (confirmation required).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'recipient' => ['type' => 'string', 'required' => true, 'description' => 'Recipient user query.'],
                'message' => ['type' => 'string', 'required' => true, 'description' => 'Message body text.'],
                'subject' => ['type' => 'string', 'required' => false, 'description' => 'Optional subject prefix.'],
                'confirmed' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true after explicit confirmation.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        $errors = [];
        $issues = [];
        if (trim((string)($input['recipient'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_recipient_required', 'bookingextension_agent');
        }
        if (trim((string)($input['message'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_message_required', 'bookingextension_agent');
        }
        if (empty($input['confirmed'])) {
            $issues[] = ['code' => 'CONFIRMATION_REQUIRED', 'severity' => 'needs_confirmation', 'user_question' => get_string('agent_booking_core_confirm_send_message', 'bookingextension_agent'), 'remedy_options' => ['CONFIRM', 'CANCEL']];
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => [], 'issues' => $issues];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);
        $recipientid = $this->resolve_userid(['userquery' => (string)($input['recipient'] ?? '')], $userid);
        if ($recipientid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_user_not_found', null, $lang), 'resultid' => null];
        }

        if ($recipientid !== $userid && !has_capability('moodle/site:sendmessage', context_system::instance(), $userid) && !is_siteadmin($userid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_message_permission_denied', null, $lang), 'resultid' => null];
        }

        $from = \core_user::get_user($userid, '*', MUST_EXIST);
        $to = \core_user::get_user($recipientid, '*', MUST_EXIST);

        $payload = new \core\message\message();
        $payload->component = 'moodle';
        $payload->name = 'instantmessage';
        $payload->courseid = SITEID;
        $payload->userfrom = $from;
        $payload->userto = $to;
        $payload->subject = trim((string)($input['subject'] ?? get_string('agent_booking_core_message_default_subject', 'bookingextension_agent')));
        $payload->fullmessage = trim((string)$input['message']);
        $payload->fullmessageformat = FORMAT_PLAIN;
        $payload->fullmessagehtml = format_text(trim((string)$input['message']), FORMAT_PLAIN);
        $payload->smallmessage = trim((string)$input['message']);
        $payload->notification = 0;

        $messageid = message_send($payload);
        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_message_sent', null, $lang), 'resultid' => (int)$messageid, 'messageid' => (int)$messageid, 'recipientid' => $recipientid];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_send_user_message_request',
            'description' => 'User asks to send a direct Moodle message to another user.',
            'examples' => ['Send message to user 12', 'Sende Nachricht an Max', 'Message Jane that class is moved'],
        ]];
    }
}
