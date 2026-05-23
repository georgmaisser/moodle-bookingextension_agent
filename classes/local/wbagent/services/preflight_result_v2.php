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
 * Preflight contract v2 DTO.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_result_v2 {
    /** @var string pass|soft_block|hard_block|retry_hint */
    public readonly string $status;

    /** @var array<int,string> */
    public readonly array $issuecodes;

    /** @var string */
    public readonly string $blockinglayer;

    /** @var int */
    public readonly int $retryafterms;

    /** @var int */
    public readonly int $retrycount;

    /** @var int */
    public readonly int $durationms;

    /**
     * @param string $status pass|soft_block|hard_block|retry_hint
     * @param array<int,string> $issuecodes
     * @param string $blockinglayer
     * @param int $retryafterms
     * @param int $retrycount
     * @param int $durationms
     */
    public function __construct(
        string $status,
        array $issuecodes = [],
        string $blockinglayer = '',
        int $retryafterms = 0,
        int $retrycount = 0,
        int $durationms = 0
    ) {
        $allowed = ['pass', 'soft_block', 'hard_block', 'retry_hint'];
        $normalizedstatus = trim($status);
        if (!in_array($normalizedstatus, $allowed, true)) {
            $normalizedstatus = 'hard_block';
        }

        $this->status = $normalizedstatus;
        $this->issuecodes = array_values(array_unique(array_filter(array_map('strval', $issuecodes))));
        $this->blockinglayer = trim($blockinglayer);
        $this->retryafterms = max(0, $retryafterms);
        $this->retrycount = max(0, $retrycount);
        $this->durationms = max(0, $durationms);
    }

    /**
     * @return array<string,mixed>
     */
    public function to_array(): array {
        return [
            'status' => $this->status,
            'issue_codes' => $this->issuecodes,
            'blocking_layer' => $this->blockinglayer,
            'retry_after_ms' => $this->retryafterms,
            'retry_count' => $this->retrycount,
            'duration_ms' => $this->durationms,
        ];
    }
}

