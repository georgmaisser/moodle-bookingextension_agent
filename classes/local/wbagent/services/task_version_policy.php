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

/**
 * Task version policy evaluator for layer-1 preflight checks.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_version_policy {
    /** @var string Version is supported and can execute. */
    public const STATUS_SUPPORTED = 'supported';

    /** @var string Version is accepted but should be surfaced as deprecated. */
    public const STATUS_DEPRECATED = 'deprecated';

    /** @var string Version is not supported and must hard-block. */
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** @var string Standard issue code for unsupported task version. */
    public const ISSUE_UNSUPPORTED = 'TASK_VERSION_UNSUPPORTED';

    /** @var string Standard issue code for deprecated task version. */
    public const ISSUE_DEPRECATED = 'TASK_VERSION_DEPRECATED';

    /**
     * Evaluate a concrete task version against normalized task metadata.
     *
     * @param array<string,mixed> $taskcontract
     * @param int $requestedversion
     * @return array{status:string,issue_codes:array<int,string>,supported_version:int,min_supported_version:int}
     */
    public function evaluate(array $taskcontract, int $requestedversion): array {
        $supportedversion = max(1, (int)($taskcontract['version'] ?? 1));
        $minsupportedversion = max(1, (int)($taskcontract['min_supported_version'] ?? $supportedversion));

        if ($requestedversion < $minsupportedversion || $requestedversion > $supportedversion) {
            return [
                'status' => self::STATUS_UNSUPPORTED,
                'issue_codes' => [self::ISSUE_UNSUPPORTED],
                'supported_version' => $supportedversion,
                'min_supported_version' => $minsupportedversion,
            ];
        }

        if ($this->is_deprecated($taskcontract, $requestedversion)) {
            return [
                'status' => self::STATUS_DEPRECATED,
                'issue_codes' => [self::ISSUE_DEPRECATED],
                'supported_version' => $supportedversion,
                'min_supported_version' => $minsupportedversion,
            ];
        }

        return [
            'status' => self::STATUS_SUPPORTED,
            'issue_codes' => [],
            'supported_version' => $supportedversion,
            'min_supported_version' => $minsupportedversion,
        ];
    }

    /**
     * Determine whether a requested version should be marked deprecated.
     *
     * @param array<string,mixed> $taskcontract
     * @param int $requestedversion
     * @return bool
     */
    private function is_deprecated(array $taskcontract, int $requestedversion): bool {
        $deprecatedversions = [];
        if (is_array($taskcontract['deprecated_versions'] ?? null)) {
            foreach ((array)$taskcontract['deprecated_versions'] as $version) {
                $candidate = (int)$version;
                if ($candidate > 0) {
                    $deprecatedversions[] = $candidate;
                }
            }
        }

        if (in_array($requestedversion, $deprecatedversions, true)) {
            return true;
        }

        return trim((string)($taskcontract['deprecated_since'] ?? '')) !== '';
    }
}
