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

namespace bookingextension_agent\local\wbagent\dto;

/**
 * DTO for deterministic family discovery output.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery_result {
    /** @var array<int,string> */
    public readonly array $families;

    /** @var array<int,string> */
    public readonly array $contextfamilies;

    /** @var array<int,string> */
    public readonly array $corefamilies;

    /** @var array<string,mixed> */
    public readonly array $contextprior;

    /**
     * Constructor.
     *
     * @param array<int,string> $families
     * @param array<int,string> $contextfamilies
     * @param array<int,string> $corefamilies
     * @param array<string,mixed> $contextprior
     */
    public function __construct(
        array $families,
        array $contextfamilies,
        array $corefamilies,
        array $contextprior = []
    ) {
        $this->families = $families;
        $this->contextfamilies = $contextfamilies;
        $this->corefamilies = $corefamilies;
        $this->contextprior = $contextprior;
    }

    /**
     * Convert DTO to array payload.
     *
     * @return array<string,mixed>
     */
    public function to_array(): array {
        return [
            'families' => $this->families,
            'context_families' => $this->contextfamilies,
            'core_families' => $this->corefamilies,
            'context_prior' => $this->contextprior,
        ];
    }
}
