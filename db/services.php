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
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_privacy_precheck' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_privacy_precheck',
        'methodname'  => 'execute',
        'description' => 'Run privacy anonymization precheck on user text before forwarding to AI.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_confirm_run' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_confirm_run',
        'methodname'  => 'execute',
        'description' => 'Confirm a proposed AI run and enqueue asynchronous execution.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_discard_pending' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_discard_pending',
        'methodname'  => 'execute',
        'description' => 'Discard the current pending confirmation and skip its queue items.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_poll_thread' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_poll_thread',
        'methodname'  => 'execute',
        'description' => 'Return all messages in an AI conversation thread.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_get_thread_debug_logs' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_get_thread_debug_logs',
        'methodname'  => 'execute',
        'description' => 'Fetch raw LLM debug logs for a conversation thread (debug mode only).',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_get_doc_content' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_get_doc_content',
        'methodname'  => 'execute',
        'description' => 'Load a booking/docs markdown file and return it as rendered HTML for the AI preview pane.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_request_trial_key' => [
        'classname'   => '\\bookingextension_agent\\external\\request_trial_key',
        'methodname'  => 'execute',
        'description' => 'Create a short-lived trial challenge nonce and return trial onboarding status.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_activate_trial_context' => [
        'classname'   => '\\bookingextension_agent\\external\\activate_trial_context',
        'methodname'  => 'execute',
        'description' => 'Enable AI tools for this course and booking module after trial onboarding.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_configure_provider_from_existing' => [
        'classname'   => '\\bookingextension_agent\\external\\configure_provider_from_existing',
        'methodname'  => 'execute',
        'description' => 'Configure the Wunderbyte provider from an existing third-party provider\'s credentials.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_ai_upload_attachment' => [
        'classname'   => '\\bookingextension_agent\\external\\ai_upload_attachment',
        'methodname'  => 'execute',
        'description' => 'Upload a file attachment (image or PDF) for use in an AI agent conversation.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:useaiinstructions',
        'ajax'        => 1,
    ],
    'bookingextension_agent_store_provider_apikey' => [
        'classname'   => '\\bookingextension_agent\\external\\store_provider_apikey',
        'methodname'  => 'execute',
        'description' => 'Store a purchased Wunderbyte API key on the provider instance.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:requesttrial',
        'ajax'        => 1,
    ],
    'bookingextension_agent_set_debug_mode' => [
        'classname'   => '\\bookingextension_agent\\external\\set_debug_mode',
        'methodname'  => 'execute',
        'description' => 'Toggle the site-wide agent debug mode.',
        'type'        => 'write',
        'capabilities' => 'moodle/site:config',
        'ajax'        => 1,
    ],
    // MCP facade: token-capable endpoints (NO sesskey) for external MCP clients such as
    // Claude. They live in their own restricted service below so chat tokens and MCP
    // tokens never unlock each other's surface.
    'bookingextension_agent_mcp_list_tools' => [
        'classname'   => '\\bookingextension_agent\\external\\mcp_list_tools',
        'methodname'  => 'execute',
        'description' => 'List agent skills executable by the current user as MCP tool definitions.',
        'type'        => 'read',
        'capabilities' => 'bookingextension/agent:mcpaccess',
        'ajax'        => 1,
    ],
    'bookingextension_agent_mcp_call_tool' => [
        'classname'   => '\\bookingextension_agent\\external\\mcp_call_tool',
        'methodname'  => 'execute',
        'description' => 'Execute an agent skill as an MCP tool call (mutating skills return a confirmation preview).',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:mcpaccess',
        'ajax'        => 1,
    ],
    'bookingextension_agent_mcp_confirm_tool' => [
        'classname'   => '\\bookingextension_agent\\external\\mcp_confirm_tool',
        'methodname'  => 'execute',
        'description' => 'Confirm and execute a pending MCP mutation using the confirmation code from the preview.',
        'type'        => 'write',
        'capabilities' => 'bookingextension/agent:mcpaccess',
        'ajax'        => 1,
    ],
];

$services = [
    'Booking AI Agent' => [
        'functions' => [
            'bookingextension_agent_ai_send_message',
            'bookingextension_agent_ai_upload_attachment',
            'bookingextension_agent_ai_privacy_precheck',
            'bookingextension_agent_ai_confirm_run',
            'bookingextension_agent_ai_discard_pending',
            'bookingextension_agent_ai_poll_thread',
            'bookingextension_agent_ai_get_thread_debug_logs',
            'bookingextension_agent_ai_get_doc_content',
            'bookingextension_agent_request_trial_key',
            'bookingextension_agent_activate_trial_context',
            'bookingextension_agent_configure_provider_from_existing',
            'bookingextension_agent_store_provider_apikey',
            'bookingextension_agent_set_debug_mode',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        // Site-unique: both engine plugins register their service side by side, so the
        // shortname carries the component (the wizard generator maps it) and the display
        // name is rewritten by the generator as well.
        'shortname' => 'bookingextension_agent',
    ],
    // Separate, restricted service for the MCP facade: disabled by default (opt-in),
    // and the admin must explicitly authorise each user before a token works. Kept
    // apart from the chat service so a chat token never reaches the MCP surface and
    // an MCP token never reaches the chat/provider-config functions.
    'Booking AI Agent MCP' => [
        'functions' => [
            'bookingextension_agent_mcp_list_tools',
            'bookingextension_agent_mcp_call_tool',
            'bookingextension_agent_mcp_confirm_tool',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
        'shortname' => 'bookingextension_agent_mcp',
    ],
];
