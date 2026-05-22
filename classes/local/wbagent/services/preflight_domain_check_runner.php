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
 * Layer-2 domain preflight checks (read-only).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_domain_check_runner {
    /** @var int Shared timeout in milliseconds. */
    private const SHARED_TIMEOUT_MS = 500;

    /**
     * Evaluate domain-level issue codes and classify the result.
     *
     * @param array<int,string> $issuecodes
     * @param float $startmicrotime
     * @return preflight_result_v2
     */
    public function run(array $issuecodes, float $startmicrotime): preflight_result_v2 {
        $elapsedms = (int)max(0, (microtime(true) - $startmicrotime) * 1000);
        if ($elapsedms > self::SHARED_TIMEOUT_MS) {
            return new preflight_result_v2(
                'retry_hint',
                ['DOMAIN_CHECK_TIMEOUT'],
                'domain',
                500,
                0,
                $elapsedms
            );
        }

        $normalizedcodes = array_values(array_unique(array_filter(array_map('trim', $issuecodes))));
        $hardblockcodes = [
            'PERMISSION_ERROR',
            'VALIDATION_ERROR',
            'SCHEMA_ERROR',
        ];
        $softblockcodes = [
            'DOMAIN_CONFLICT',
            'DUPLICATE_TITLE_CONFIRM_REQUIRED',
            'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
        ];
        foreach ($normalizedcodes as $code) {
            $normalizedcode = strtoupper(trim($code));
            if ($normalizedcode === '') {
                continue;
            }
            if (in_array($normalizedcode, $hardblockcodes, true) || str_starts_with($normalizedcode, 'MISSING_')) {
                return new preflight_result_v2('hard_block', [$normalizedcode], 'domain', 0, 0, $elapsedms);
            }
            if (in_array($normalizedcode, $softblockcodes, true)) {
                return new preflight_result_v2('soft_block', [$normalizedcode], 'domain', 0, 0, $elapsedms);
            }
        }

        return new preflight_result_v2('pass', [], '', 0, 0, $elapsedms);
    }
}
