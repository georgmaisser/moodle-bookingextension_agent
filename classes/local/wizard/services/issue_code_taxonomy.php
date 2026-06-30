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
 * Canonical home for issue-code semantics.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

use core_text;

/**
 * The single place that maps issue codes to meaning.
 *
 * Two views over the same vocabulary previously lived in two files that could drift (audit
 * C3-F02): `preflight_error_classifier` derives a display **error_class**, and
 * `retry_policy_service` derives a **retry category** (retry vs terminal). They are NOT the
 * same function — different outputs AND a deliberately different match precedence (e.g. a code
 * containing both PERMISSION and TIMEOUT classifies as `provider_timeout` for the error_class
 * but as DOMAIN for the retry category). So both rule sets are kept here verbatim, each as its
 * own method, so adding/changing an issue code touches exactly one file without forcing a
 * single precedence onto both consumers. Behaviour is identical to the original methods.
 */
class issue_code_taxonomy {
    /** @var string Retryable technical failure (timeout, transient IO, parse, guard, …). */
    public const CATEGORY_TECHNICAL = 'TECHNICAL';

    /** @var string Non-retryable domain failure (validation, conflict, permission, …). */
    public const CATEGORY_DOMAIN = 'DOMAIN';

    /** @var string Retryable external-dependency failure (auth, quota, rate-limit, provider). */
    public const CATEGORY_EXTERNAL_DEPENDENCY = 'EXTERNAL_DEPENDENCY';

    /** @var string No category could be resolved. */
    public const CATEGORY_UNDEFINED = 'UNDEFINED';

    /**
     * Derive the display error_class from issue codes (first match wins, in this order).
     *
     * @param array $issuecodes
     * @return string The error class, or '' when nothing matches.
     */
    public static function error_class_for(array $issuecodes): string {
        foreach ($issuecodes as $code) {
            $upper = core_text::strtoupper(trim((string)$code));
            if ($upper === '') {
                continue;
            }
            if (str_contains($upper, 'TIMEOUT')) {
                return 'provider_timeout';
            }
            if (str_contains($upper, 'TRANSIENT_IO') || str_contains($upper, 'IO_TRANSIENT')) {
                return 'transient_io';
            }
            if (str_contains($upper, 'PERMISSION')) {
                return 'permission_error';
            }
            if (str_contains($upper, 'CONFLICT')) {
                return 'domain_conflict';
            }
            if (str_contains($upper, 'VALIDATION') || str_contains($upper, 'MISSING_')) {
                return 'validation_error';
            }
        }

        return '';
    }

    /**
     * Derive the retry category from issue codes, with an error-class and layer fallback.
     *
     * @param string $errorclass
     * @param array $issuecodes
     * @param string $layer
     * @return string One of the CATEGORY_* constants.
     */
    public static function retry_category_for(string $errorclass, array $issuecodes, string $layer = ''): string {
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
}
