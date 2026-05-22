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
 * Web service for bookingextension_agent AI functions
 *
 * @package bookingextension_agent
 * @subpackage db
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'bookingextension_agent_ai_send_message' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_send_message',
        'methodname'  => 'execute',
        'description' => 'Send a user message to the AI booking agent and receive its response.',
        'type'        => 'write',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_privacy_precheck' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_privacy_precheck',
        'methodname'  => 'execute',
        'description' => 'Run privacy anonymization precheck on user text before forwarding to AI.',
        'type'        => 'read',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_confirm_run' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_confirm_run',
        'methodname'  => 'execute',
        'description' => 'Confirm a proposed AI run and enqueue asynchronous execution.',
        'type'        => 'write',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_poll_thread' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_poll_thread',
        'methodname'  => 'execute',
        'description' => 'Return all messages in an AI conversation thread.',
        'type'        => 'read',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_get_thread_debug_logs' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_get_thread_debug_logs',
        'methodname'  => 'execute',
        'description' => 'Fetch raw LLM debug logs for a conversation thread (debug mode only).',
        'type'        => 'read',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_list_candidate_options' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_list_candidate_options',
        'methodname'  => 'execute',
        'description' => 'Return a list of booking options for AI disambiguation.',
        'type'        => 'read',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_render_command_preview' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_render_command_preview',
        'methodname'  => 'execute',
        'description' => 'Render preview HTML for AI mutation commands using booking option row rendering.',
        'type'        => 'read',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_get_doc_content' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_get_doc_content',
        'methodname'  => 'execute',
        'description' => 'Load a booking/docs markdown file and return it as rendered HTML for the AI preview pane.',
        'type'        => 'read',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_request_trial_key' => [
        'classname'   => '\\bookingextension_agent\\external\\request_trial_key',
        'methodname'  => 'execute',
        'description' => 'Create a short-lived trial challenge nonce and return trial onboarding status.',
        'type'        => 'write',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_activate_trial_context' => [
        'classname'   => '\\bookingextension_agent\\external\\activate_trial_context',
        'methodname'  => 'execute',
        'description' => 'Enable AI tools for this course and booking module after trial onboarding.',
        'type'        => 'write',
        'capabilities' => 'mod/booking:useaiinstructions',
        'ajax'        => 1,
    ],
];

$services = [
    'Booking AI Agent' => [
        'functions' => [
            'bookingextension_agent_ai_send_message',
            'bookingextension_agent_ai_privacy_precheck',
            'bookingextension_agent_ai_confirm_run',
            'bookingextension_agent_ai_poll_thread',
            'bookingextension_agent_ai_get_thread_debug_logs',
            'bookingextension_agent_ai_list_candidate_options',
            'bookingextension_agent_ai_render_command_preview',
            'bookingextension_agent_ai_get_doc_content',
            'bookingextension_agent_request_trial_key',
            'bookingextension_agent_activate_trial_context',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];
