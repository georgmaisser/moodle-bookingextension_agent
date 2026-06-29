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
 * Central retry policy service for layered retry decisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Maps retry hints to categories and guard decisions.
 */
class retry_policy_service {
    /** Retry hint category: technical faults. */
    public const CATEGORY_TECHNICAL = 'TECHNICAL';

    /** Retry hint category: domain/business constraints. */
    public const CATEGORY_DOMAIN = 'DOMAIN';

    /** Retry hint category: external dependency/provider conditions. */
    public const CATEGORY_EXTERNAL_DEPENDENCY = 'EXTERNAL_DEPENDENCY';

    /** Category fallback for undefined or unknown signals. */
    public const CATEGORY_UNDEFINED = 'UNDEFINED';

    /** Issue code when retry category cannot be inferred. */
    public const ISSUE_RETRY_CATEGORY_UNDEFINED = 'RETRY_HINT_CATEGORY_UNDEFINED';

    /** Issue code when retry category is explicitly non-retryable. */
    public const ISSUE_RETRY_CATEGORY_NOT_ALLOWED = 'RETRY_CATEGORY_NOT_ALLOWED';

    /** Provider circuit breaker: authentication failure. */
    public const ISSUE_PROVIDER_CIRCUIT_OPEN_AUTH = 'PROVIDER_CIRCUIT_OPEN_AUTH';

    /** Provider circuit breaker: quota/rate limit exhausted. */
    public const ISSUE_PROVIDER_CIRCUIT_OPEN_QUOTA = 'PROVIDER_CIRCUIT_OPEN_QUOTA';

    /**
     * Resolve retry hint category from structured context.
     *
     * @param string $errorclass
     * @param string[] $issuecodes
     * @param string $layer
     * @return string
     */
    public function resolve_retry_hint_category(string $errorclass, array $issuecodes, string $layer = ''): string {
        $normalizederrorclass = trim(strtolower($errorclass));
        $upperissuecodes = array_map(
            static fn(string $code): string => strtoupper(trim($code)),
            array_values(array_unique(array_filter(array_map('strval', $issuecodes))))
        );

        foreach ($upperissuecodes as $code) {
            if (
                str_contains($code, 'VALIDATION')
                || str_contains($code, 'CONFLICT')
                || str_contains($code, 'DOMAIN')
                || str_contains($code, 'MISSING_')
                || str_contains($code, 'PERMISSION')
            ) {
                return self::CATEGORY_DOMAIN;
            }
            if (
                str_contains($code, 'TIMEOUT')
                || str_contains($code, 'TRANSIENT')
                || str_contains($code, 'CONTRACT_')
                || str_contains($code, 'PARSE')
                || str_contains($code, 'SELECTION')
                || str_contains($code, 'RETRY_WAITING')
                || str_contains($code, 'EXECUTION_GUARD')
            ) {
                return self::CATEGORY_TECHNICAL;
            }
            if (
                str_contains($code, 'AUTH')
                || str_contains($code, 'QUOTA')
                || str_contains($code, 'RATE_LIMIT')
                || str_contains($code, 'PROVIDER')
                || str_contains($code, 'EXTERNAL')
            ) {
                return self::CATEGORY_EXTERNAL_DEPENDENCY;
            }
        }

        if (in_array($normalizederrorclass, ['preflight_retry', 'provider_timeout', 'transient_io'], true)) {
            return self::CATEGORY_TECHNICAL;
        }
        if (in_array($normalizederrorclass, ['domain_conflict', 'validation_error', 'permission_error'], true)) {
            return self::CATEGORY_DOMAIN;
        }
        if (in_array($normalizederrorclass, ['provider_error', 'auth_error', 'quota_error'], true)) {
            return self::CATEGORY_EXTERNAL_DEPENDENCY;
        }

        // Execution layer without explicit signals defaults to technical fallback.
        if (trim(strtolower($layer)) === 'execution' && $normalizederrorclass !== '') {
            return self::CATEGORY_TECHNICAL;
        }

        return self::CATEGORY_UNDEFINED;
    }

    /**
     * Check whether a category is allowed to retry.
     *
     * @param string $category
     * @return bool
     */
    public function is_retryable_category(string $category): bool {
        $normalized = strtoupper(trim($category));
        return in_array($normalized, [self::CATEGORY_TECHNICAL, self::CATEGORY_EXTERNAL_DEPENDENCY], true);
    }

    /**
     * Evaluate provider circuit breaker constraints.
     *
     * @param string $errorclass
     * @param string[] $issuecodes
     * @return array{allow:bool,issue_codes:string[],terminal_reason:string}
     */
    public function evaluate_provider_circuit_breaker(string $errorclass, array $issuecodes): array {
        $normalizederrorclass = trim(strtolower($errorclass));
        $upperissuecodes = array_map(
            static fn(string $code): string => strtoupper(trim($code)),
            array_values(array_unique(array_filter(array_map('strval', $issuecodes))))
        );

        $authsignal = in_array('AUTH_ERROR', $upperissuecodes, true)
            || in_array('PROVIDER_AUTH_FAILED', $upperissuecodes, true)
            || str_contains(strtoupper($normalizederrorclass), 'AUTH');
        if ($authsignal) {
            return [
                'allow' => false,
                'issue_codes' => [self::ISSUE_PROVIDER_CIRCUIT_OPEN_AUTH],
                'terminal_reason' => 'provider_auth_failed',
            ];
        }

        $quotasignal = in_array('QUOTA_EXCEEDED', $upperissuecodes, true)
            || in_array('RATE_LIMIT_EXCEEDED', $upperissuecodes, true)
            || str_contains(strtoupper($normalizederrorclass), 'QUOTA');
        if ($quotasignal) {
            return [
                'allow' => false,
                'issue_codes' => [self::ISSUE_PROVIDER_CIRCUIT_OPEN_QUOTA],
                'terminal_reason' => 'provider_quota_exceeded',
            ];
        }

        return [
            'allow' => true,
            'issue_codes' => [],
            'terminal_reason' => '',
        ];
    }
}
