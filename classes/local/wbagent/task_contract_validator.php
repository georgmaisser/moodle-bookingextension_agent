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
 * Task governance contract validator.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use core_text;
use bookingextension_agent\local\wbagent\interfaces\task_interface;

/**
 * Validates and normalizes governance metadata for task registration.
 */
class task_contract_validator {
    /** Deny reason: task was not registered. */
    public const DENY_NOT_REGISTERED = 'not_registered';

    /** Deny reason: task is inactive. */
    public const DENY_INACTIVE = 'inactive';

    /** Deny reason: user misses required capability. */
    public const DENY_MISSING_CAPABILITY = 'missing_capability';

    /** Deny reason: context is invalid. */
    public const DENY_CONTEXT_INVALID = 'context_invalid';

    /** Deny reason: runtime is globally disabled. */
    public const DENY_RUNTIME_DISABLED = 'runtime_disabled';

    /** Deny reason: requested task version is unsupported. */
    public const DENY_TASK_VERSION_UNSUPPORTED = 'task_version_unsupported';

    /** Issue code for unsupported task versions. */
    public const ISSUE_TASK_VERSION_UNSUPPORTED = 'TASK_VERSION_UNSUPPORTED';

    /** Issue code for deprecated task versions. */
    public const ISSUE_TASK_VERSION_DEPRECATED = 'TASK_VERSION_DEPRECATED';

    /**
     * Build normalized governance metadata for one task.
     *
     * @param task_interface $task
     * @param string $component
     * @return array<string,mixed>
     */
    public static function build_task_metadata(task_interface $task, string $component): array {
        $schema = (array)$task->get_schema();
        $governance = (array)($schema['governance'] ?? []);
        $taskname = trim($task->get_name());
        $capabilities = [];
        $defaultcapability = self::build_task_capability_name($component, $taskname);
        if ($defaultcapability !== '') {
            $capabilities[] = $defaultcapability;
        }

        return [
            'taskname' => $taskname,
            'version' => (int)($schema['version'] ?? 1),
            'component' => trim($component),
            'capabilities' => $capabilities,
            'active' => array_key_exists('active', $governance) ? (bool)$governance['active'] : true,
            'alias_of' => trim((string)($governance['alias_of'] ?? '')),
            'deprecated_since' => trim((string)($governance['deprecated_since'] ?? '')),
            'readonly' => (bool)$task->is_read_only(),
        ];
    }

    /**
     * Build a deterministic task capability name for component/task combination.
     *
     * @param string $component
     * @param string $taskname
     * @return string
     */
    public static function build_task_capability_name(string $component, string $taskname): string {
        $component = trim(core_text::strtolower($component));
        $taskname = trim(core_text::strtolower($taskname));
        if ($component === '' || $taskname === '') {
            return '';
        }

        $normalizedtaskname = preg_replace('/[^a-z0-9]+/', '_', $taskname);
        $normalizedtaskname = trim((string)$normalizedtaskname, '_');
        if ($normalizedtaskname === '') {
            return '';
        }

        return $component . ':task_' . $normalizedtaskname;
    }

    /**
     * Validate one normalized metadata record.
     *
     * @param array<string,mixed> $taskmeta
     * @return array{valid:bool,errors:array<int,string>}
     */
    public static function validate_task_metadata(array $taskmeta): array {
        $errors = [];

        if (trim((string)($taskmeta['taskname'] ?? '')) === '') {
            $errors[] = 'Missing required field: taskname.';
        }

        $version = $taskmeta['version'] ?? null;
        if (!is_int($version) || $version <= 0) {
            $errors[] = 'Invalid required field: version must be an integer > 0.';
        }

        if (!array_key_exists('active', $taskmeta) || !is_bool($taskmeta['active'])) {
            $errors[] = 'Invalid required field: active must be a boolean.';
        }

        if (!array_key_exists('capabilities', $taskmeta) || !is_array($taskmeta['capabilities'])) {
            $errors[] = 'Invalid required field: capabilities must be a string array.';
        } else {
            foreach ($taskmeta['capabilities'] as $capability) {
                if (!is_string($capability) || trim($capability) === '') {
                    $errors[] = 'Invalid capability entry: expected non-empty string.';
                    break;
                }
            }
        }

        $aliasof = trim((string)($taskmeta['alias_of'] ?? ''));
        if ($aliasof !== '' && $aliasof === trim((string)($taskmeta['taskname'] ?? ''))) {
            $errors[] = 'Invalid alias_of: alias cannot target itself.';
        }

        if (!is_string((string)($taskmeta['deprecated_since'] ?? ''))) {
            $errors[] = 'Invalid optional field: deprecated_since must be string.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate registry-wide metadata conflicts (duplicates, broken aliases).
     *
     * @param array<string,array<string,mixed>> $taskcontracts
     * @return array<int,string>
     */
    public static function validate_registry_contracts(array $taskcontracts): array {
        $errors = [];
        $seenidentities = [];

        foreach ($taskcontracts as $taskname => $meta) {
            if (isset($seenidentities[$taskname])) {
                $errors[] = 'Duplicate task identity detected: ' . $taskname;
                continue;
            }
            $seenidentities[$taskname] = true;

            $aliasof = trim((string)($meta['alias_of'] ?? ''));
            if ($aliasof !== '' && !isset($taskcontracts[$aliasof])) {
                $errors[] = 'Alias target not found for task ' . $taskname . ': ' . $aliasof;
            }
        }

        return $errors;
    }

    /**
     * Return standardized deny reasons in priority order.
     *
     * @return array<int,string>
     */
    public static function get_deny_reason_priority(): array {
        return [
            self::DENY_RUNTIME_DISABLED,
            self::DENY_INACTIVE,
            self::DENY_MISSING_CAPABILITY,
            self::DENY_CONTEXT_INVALID,
            self::DENY_TASK_VERSION_UNSUPPORTED,
        ];
    }
}
