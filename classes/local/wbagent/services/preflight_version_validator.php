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

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wbagent\task_registry;

/**
 * Layer-1 task-version validator.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_version_validator {
    /** @var string Task registration issue code. */
    public const ISSUE_TASK_NOT_REGISTERED = 'TASK_NOT_REGISTERED';

    /** @var task_registry */
    private task_registry $registry;

    /** @var task_version_policy */
    private task_version_policy $policy;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     * @param task_version_policy|null $policy
     */
    public function __construct(task_registry $registry, ?task_version_policy $policy = null) {
        $this->registry = $registry;
        $this->policy = $policy ?? new task_version_policy();
    }

    /**
     * Validate task registration + task version for one command.
     *
     * @param array<string,mixed> $command
     * @return array{valid:bool,error_class:string,issue_codes:array<int,string>,errors:array<int,string>}
     */
    public function validate(array $command): array {
        $taskname = trim((string)($command['task'] ?? ''));
        if ($taskname === '') {
            return [
                'valid' => true,
                'error_class' => '',
                'issue_codes' => [],
                'errors' => [],
            ];
        }

        $contract = $this->registry->get_task_contract($taskname);
        if ($contract === null) {
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => [self::ISSUE_TASK_NOT_REGISTERED],
                'errors' => ['Task "' . $taskname . '" is not registered.'],
            ];
        }

        $requestedversion = $this->resolve_requested_version($command, $contract);
        if ($requestedversion <= 0) {
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => [task_version_policy::ISSUE_UNSUPPORTED],
                'errors' => ['Field "task_version" must be an integer > 0 when provided.'],
            ];
        }

        $evaluation = $this->policy->evaluate($contract, $requestedversion);
        if (($evaluation['status'] ?? '') === task_version_policy::STATUS_UNSUPPORTED) {
            $supportedversion = (int)($evaluation['supported_version'] ?? 1);
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => array_values((array)($evaluation['issue_codes'] ?? [task_version_policy::ISSUE_UNSUPPORTED])),
                'errors' => [
                    'Unsupported task version "' . $requestedversion . '" for task "' . $taskname
                    . '". Supported version is "' . $supportedversion . '".',
                ],
            ];
        }

        if (($evaluation['status'] ?? '') === task_version_policy::STATUS_DEPRECATED) {
            return [
                'valid' => true,
                'error_class' => '',
                'issue_codes' => array_values((array)($evaluation['issue_codes'] ?? [task_version_policy::ISSUE_DEPRECATED])),
                'errors' => [],
            ];
        }

        return [
            'valid' => true,
            'error_class' => '',
            'issue_codes' => [],
            'errors' => [],
        ];
    }

    /**
     * Resolve requested version from command or fallback to contract version.
     *
     * @param array<string,mixed> $command
     * @param array<string,mixed> $contract
     * @return int
     */
    private function resolve_requested_version(array $command, array $contract): int {
        if (!array_key_exists('task_version', $command)) {
            return (int)($contract['version'] ?? 1);
        }

        $value = $command['task_version'];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
