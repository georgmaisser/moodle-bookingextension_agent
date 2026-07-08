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
 * MCP headless skill execution service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\mcp;

use bookingextension_agent\event\mcp_tool_called;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\executor;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use core\context;

/**
 * Executes agent skills for an external MCP client (Claude) without the LLM engine.
 *
 * The MCP client is its own planner: it supplies {tool, args} directly, so this
 * service drives only the deterministic tail of the engine — structural check,
 * preflight pipeline, run bookkeeping and the executor. Every security gate the
 * chat path has (governance evaluator, licence gate, native capability guard,
 * guard tokens for mutations) is enforced inside that shared tail; this service
 * adds no privileged shortcut.
 *
 * All runs live on a dedicated per-user 'mcp' channel thread so the chat thread
 * (whose metadata carries the chat queue) is never touched.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_execution_service {
    /** @var string Channel name for MCP-owned threads. */
    public const CHANNEL = 'mcp';

    /** @var string[] Result keys never echoed into structuredContent (already in content / internal). */
    private const STRUCTURED_CONTENT_DROPPED_KEYS = ['observation_full', 'usermessage', 'preview'];

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var skill_executability_evaluator */
    private skill_executability_evaluator $evaluator;

    /** @var mcp_tool_catalog_service */
    private mcp_tool_catalog_service $catalog;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param conversation_store $store
     * @param authorization_service $authz
     */
    public function __construct(skill_registry $registry, conversation_store $store, authorization_service $authz) {
        $this->registry = $registry;
        $this->store = $store;
        $this->authz = $authz;
        $this->evaluator = new skill_executability_evaluator($registry, $authz);
        $this->catalog = new mcp_tool_catalog_service($registry, $this->evaluator);
    }

    /**
     * List the MCP tool definitions available to this user in this context.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function list_tools(int $contextid, int $userid): array {
        return $this->catalog->get_tools($userid, $contextid);
    }

    /**
     * Execute one MCP tool call and return an MCP-shaped result.
     *
     * The returned array uses the MCP tool-result field names verbatim
     * (content / structuredContent / isError) so transports can pass it through.
     *
     * @param string $toolname MCP tool name (or canonical skill name).
     * @param array $args Tool arguments as decoded by the transport.
     * @param int $contextid Ambient context id.
     * @param int $userid Acting user id.
     * @param string $idempotencykey Client-supplied per-request key; retries reuse it.
     * @return array
     */
    public function call_tool(string $toolname, array $args, int $contextid, int $userid, string $idempotencykey): array {
        $skillname = $this->catalog->skill_for_tool_name($toolname);
        if ($skillname === null) {
            return $this->error_result(
                get_string('mcp_error_unknown_tool', 'bookingextension_agent', clean_param($toolname, PARAM_ALPHANUMEXT)),
                ['MCP_UNKNOWN_TOOL']
            );
        }

        $evaluation = $this->evaluator->evaluate_skill($skillname, $userid, $contextid);
        if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
            $denyreason = trim((string)($evaluation['deny_reason'] ?? ''));
            return $this->error_result(
                get_string('mcp_error_skill_denied', 'bookingextension_agent', $denyreason),
                ['MCP_SKILL_DENIED', $denyreason]
            );
        }

        $skill = $this->registry->get_skill($skillname);
        $structural = $skill->check_structure($args);
        if (!($structural['valid'] ?? true)) {
            return $this->error_result(
                get_string(
                    'mcp_error_invalid_input',
                    'bookingextension_agent',
                    implode('; ', (array)($structural['errors'] ?? []))
                ),
                ['MCP_INVALID_INPUT']
            );
        }

        if (!$skill->is_read_only()) {
            return $this->call_mutating_tool($skill, $skillname, $args, $contextid, $userid, $idempotencykey);
        }

        return $this->execute_now($skillname, $args, $contextid, $userid, $idempotencykey);
    }

    /**
     * Entry point for mutating skills — completed by the phase-2 confirm flow.
     *
     * @param object $skill
     * @param string $skillname
     * @param array $args
     * @param int $contextid
     * @param int $userid
     * @param string $idempotencykey
     * @return array
     */
    private function call_mutating_tool(
        object $skill,
        string $skillname,
        array $args,
        int $contextid,
        int $userid,
        string $idempotencykey
    ): array {
        return $this->error_result(
            get_string('mcp_error_mutations_not_available', 'bookingextension_agent'),
            ['MCP_MUTATIONS_NOT_AVAILABLE']
        );
    }

    /**
     * Run the deterministic execution tail for a read-only command.
     *
     * @param string $skillname
     * @param array $args
     * @param int $contextid
     * @param int $userid
     * @param string $idempotencykey
     * @return array
     */
    private function execute_now(string $skillname, array $args, int $contextid, int $userid, string $idempotencykey): array {
        if ($idempotencykey !== '' && $this->store->run_exists($idempotencykey)) {
            // A retry of a request that already ran: acknowledge instead of re-executing.
            // The runs table has a unique index on the key, so this also guards the insert.
            return $this->error_result(
                get_string('mcp_error_duplicate_request', 'bookingextension_agent'),
                ['MCP_DUPLICATE_REQUEST']
            );
        }
        if ($idempotencykey === '') {
            $idempotencykey = bin2hex(random_bytes(32));
        }

        $thread = $this->store->get_or_create_channel_thread($userid, $contextid, self::CHANNEL);
        $threadid = (int)$thread->id;

        $schema = (array)$this->registry->get_skill($skillname)->get_schema();
        $skillversion = (int)($schema['version'] ?? 1);
        $pipeline = new preflight_pipeline($this->registry, $this->store);
        $preflight = $pipeline->run(
            [['skill' => $skillname, 'version' => $skillversion, 'input' => $args]],
            $threadid,
            $contextid,
            $userid
        );
        $preparedcommands = (array)($preflight['prepared_commands'] ?? []);
        if ((string)($preflight['status'] ?? '') !== 'pass' || empty($preparedcommands)) {
            return $this->error_result(
                get_string(
                    'mcp_error_preflight_blocked',
                    'bookingextension_agent',
                    implode(' ', (array)($preflight['errors'] ?? []))
                ),
                array_merge(['MCP_PREFLIGHT_BLOCKED'], (array)($preflight['issue_codes'] ?? []))
            );
        }

        $runid = $this->store->create_run($threadid, $userid, $contextid, $idempotencykey, $preparedcommands);
        $this->store->update_run_status($runid, 'running');

        $exec = new executor($this->registry, $this->store, $this->authz);
        $results = $exec->execute_commands($preparedcommands, $contextid, $userid, $idempotencykey, $runid);
        $this->store->update_run_status($runid, 'completed', $results);

        $result = is_array($results[0] ?? null) ? (array)$results[0] : [];
        $this->trigger_tool_event($skillname, (string)($result['status'] ?? ''), $contextid, $userid, $runid);

        return $this->build_mcp_result($result);
    }

    /**
     * Map one executor result entry to the MCP tool-result shape.
     *
     * The human/model-facing text goes into content; the structured payload
     * (minus the text channels) into structuredContent.
     *
     * @param array $result
     * @return array
     */
    private function build_mcp_result(array $result): array {
        $status = trim((string)($result['status'] ?? ''));
        $text = trim((string)($result['usermessage'] ?? ''));
        if ($text === '') {
            $text = trim((string)($result['observation_full'] ?? ''));
        }
        if ($text === '') {
            $text = trim((string)($result['detail'] ?? ''));
        }

        $structured = array_diff_key($result, array_fill_keys(self::STRUCTURED_CONTENT_DROPPED_KEYS, true));

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $structured,
            'isError' => !in_array($status, ['executed', 'skipped'], true),
        ];
    }

    /**
     * Build an MCP error tool-result.
     *
     * @param string $message
     * @param string[] $issuecodes
     * @param array $extra Additional structuredContent fields.
     * @return array
     */
    private function error_result(string $message, array $issuecodes, array $extra = []): array {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'structuredContent' => array_merge([
                'issue_codes' => array_values(array_unique(array_filter(array_map('strval', $issuecodes)))),
            ], $extra),
            'isError' => true,
        ];
    }

    /**
     * Trigger the audit event for an MCP tool call.
     *
     * @param string $skillname
     * @param string $status
     * @param int $contextid
     * @param int $userid
     * @param int $runid
     * @return void
     */
    private function trigger_tool_event(string $skillname, string $status, int $contextid, int $userid, int $runid): void {
        $event = mcp_tool_called::create([
            'context' => context::instance_by_id($contextid),
            'userid' => $userid,
            'other' => [
                'skill' => $skillname,
                'status' => $status,
                'runid' => $runid,
            ],
        ]);
        $event->trigger();
    }
}
