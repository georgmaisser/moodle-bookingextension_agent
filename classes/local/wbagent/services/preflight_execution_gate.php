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
 * Layer-3 execution gate for retry/backoff decisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_execution_gate {
    /** @var int */
    private const BASE_MS = 500;
    /** @var int */
    private const JITTER_MS = 200;
    /** @var int */
    private const MAX_RETRIES = 4;
    /** @var int */
    private const MAX_BACKOFF_MS = 4000;
    /** @var int Upper bound to keep 2^n exponentiation safe and bounded. */
    private const MAX_EXPONENT = 30;

    /**
     * Evaluate retry policy for an error class.
     *
     * @param string $errorclass
     * @param int $retrycount
     * @param array<int,string> $issuecodes
     * @return preflight_result_v2
     */
    public function evaluate(string $errorclass, int $retrycount, array $issuecodes = []): preflight_result_v2 {
        $errorclass = trim(strtolower($errorclass));
        $retrycount = max(0, $retrycount);
        $issuecodes = array_values(array_unique(array_filter(array_map('strval', $issuecodes))));

        if (!in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
            return new preflight_result_v2('hard_block', $issuecodes, 'execution_gate', 0, $retrycount, 0);
        }

        if ($retrycount >= self::MAX_RETRIES) {
            $issuecodes[] = 'MAX_RETRIES_EXCEEDED';
            return new preflight_result_v2(
                'hard_block',
                array_values(array_unique($issuecodes)),
                'execution_gate',
                0,
                $retrycount,
                0
            );
        }

        $exponent = min($retrycount, self::MAX_EXPONENT);
        $multiplier = 2 ** $exponent;
        $backoffms = (self::BASE_MS * $multiplier) + random_int(0, self::JITTER_MS);
        $backoffms = min($backoffms, self::MAX_BACKOFF_MS);
        return new preflight_result_v2(
            'retry_hint',
            $issuecodes,
            'execution_gate',
            $backoffms,
            $retrycount,
            0
        );
    }
}
