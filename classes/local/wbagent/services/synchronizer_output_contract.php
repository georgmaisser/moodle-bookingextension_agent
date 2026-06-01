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
 * Enforces the synchronizer output contract.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_output_contract {
    /**
     * Merge synchronizer output without allowing structural contract drift.
     *
     * @param array $source
     * @param array $sync
     * @return array
     */
    public function merge(array $source, array $sync): array {
        $syncmessage = trim((string)($sync['message'] ?? ''));
        if ($syncmessage === '') {
            return $source;
        }

        if ($this->should_reject($sync, $syncmessage)) {
            return $source;
        }

        $synccommands = $sync['commands'] ?? [];
        if (is_array($synccommands) && !empty($synccommands)) {
            return $source;
        }

        $merged = $source;
        $merged['message'] = $syncmessage;

        $synclang = trim((string)($sync['lang'] ?? ''));
        if ($synclang !== '') {
            $merged['lang'] = $synclang;
        }

        return $merged;
    }

    /**
     * Reject synchronizer outputs that indicate parse or contract failures.
     *
     * @param array $sync
     * @param string $syncmessage
     * @return bool
     */
    private function should_reject(array $sync, string $syncmessage): bool {
        $responsetype = trim((string)($sync['response_type'] ?? ''));
        if ($responsetype === 'error') {
            return true;
        }

        $issuecodes = $this->normalize_issue_codes((array)($sync['issue_codes'] ?? []));
        foreach ($issuecodes as $code) {
            if (str_starts_with($code, 'CONTRACT_')) {
                return true;
            }
        }

        if (str_starts_with($syncmessage, 'Failed to parse LLM response as JSON.')) {
            return true;
        }

        if (strpos($syncmessage, 'Raw excerpt:') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Normalize issue codes to unique uppercase entries.
     *
     * @param array $codes
     * @return array
     */
    private function normalize_issue_codes(array $codes): array {
        $normalized = [];
        foreach ($codes as $code) {
            $value = strtoupper(trim((string)$code));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
