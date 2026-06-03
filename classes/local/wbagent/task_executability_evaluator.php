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
 * Read-only task executability evaluator.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\services\security\authorization_service;
use context;

/**
 * Central evaluator for task executability and deny diagnostics.
 */
class task_executability_evaluator {
    /** @var task_registry */
    private task_registry $registry;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     * @param authorization_service $authz
     */
    public function __construct(task_registry $registry, authorization_service $authz) {
        $this->registry = $registry;
        $this->authz = $authz;
    }

    /**
     * Evaluate one task for user and context.
     *
     * @param string $taskname
     * @param int $userid
     * @param int $contextid
     * @return array<string,mixed>
     */
    public function evaluate_task(string $taskname, int $userid, int $contextid): array {
        $taskname = trim($taskname);
        $meta = $this->registry->get_task_contract($taskname);

        if ($meta === null || $this->registry->get_task($taskname) === null) {
            return $this->deny_result($taskname, task_contract_validator::DENY_NOT_REGISTERED, [
                'registered' => false,
            ]);
        }

        if (!authorization_service::is_agent_extension_installed()) {
            return $this->deny_result($taskname, task_contract_validator::DENY_RUNTIME_DISABLED, [
                'registered' => true,
            ]);
        }

        if (!$this->registry->is_task_active($taskname)) {
            return $this->deny_result($taskname, task_contract_validator::DENY_INACTIVE, [
                'active' => false,
            ]);
        }

        if (!$this->has_required_capabilities($userid, $contextid, $taskname)) {
            return $this->deny_result($taskname, task_contract_validator::DENY_MISSING_CAPABILITY, [
                'required_capabilities' => $this->registry->get_task_capabilities($taskname),
            ]);
        }

        if (!$this->is_valid_context($contextid)) {
            return $this->deny_result($taskname, task_contract_validator::DENY_CONTEXT_INVALID, [
                'contextid' => $contextid,
            ]);
        }

        return [
            'taskname' => $taskname,
            'executable_state' => 'allow',
            'deny_reason' => '',
            'diagnostics' => [
                'registered' => true,
                'active' => true,
                'required_capabilities' => $this->registry->get_task_capabilities($taskname),
                'readonly' => (bool)($meta['readonly'] ?? false),
            ],
        ];
    }

    /**
     * Evaluate all registered tasks for user and context.
     *
     * @param int $userid
     * @param int $contextid
     * @return array<string,array<string,mixed>>
     */
    public function evaluate_all_tasks(int $userid, int $contextid): array {
        $results = [];

        foreach ($this->registry->get_task_names() as $taskname) {
            $results[$taskname] = $this->evaluate_task($taskname, $userid, $contextid);
        }

        ksort($results);
        return $results;
    }

    /**
     * Return executable task names only.
     *
     * @param int $userid
     * @param int $contextid
     * @return array<int,string>
     */
    public function get_executable_task_names(int $userid, int $contextid): array {
        $tasknames = [];

        foreach ($this->evaluate_all_tasks($userid, $contextid) as $taskname => $evaluation) {
            if ((string)($evaluation['executable_state'] ?? '') === 'allow') {
                $tasknames[] = $taskname;
            }
        }

        return $tasknames;
    }

    /**
     * Build a standardized deny result payload.
     *
     * @param string $taskname
     * @param string $reason
     * @param array<string,mixed> $diagnostics
     * @return array<string,mixed>
     */
    private function deny_result(string $taskname, string $reason, array $diagnostics = []): array {
        return [
            'taskname' => $taskname,
            'executable_state' => 'deny',
            'deny_reason' => $reason,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Check task-specific capabilities for user/context.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $taskname
     * @return bool
     */
    private function has_required_capabilities(int $userid, int $contextid, string $taskname): bool {
        $capabilities = $this->registry->get_task_capabilities($taskname);
        if (empty($capabilities)) {
            return false;
        }

        try {
            $context = context::instance_by_id($contextid, MUST_EXIST);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($capabilities as $capability) {
            if (!get_capability_info($capability)) {
                return false;
            }
            if (!has_capability($capability, $context, $userid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether context is valid for booking execution.
     *
     * @param int $contextid
     * @return bool
     */
    private function is_valid_context(int $contextid): bool {
        try {
            $this->authz->require_valid_context($contextid);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
