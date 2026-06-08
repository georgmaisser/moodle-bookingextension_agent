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
 * DTO for constructed and structurally validated skill parameters.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parameter_construction_result {
    /** @var array<string,mixed> */
    public readonly array $input;

    /** @var bool */
    public readonly bool $valid;

    /** @var array<int,string> */
    public readonly array $errors;

    /** @var array<int,string> */
    public readonly array $issuecodes;

    /**
     * Constructor.
     *
     * @param array<string,mixed> $input
     * @param bool $valid
     * @param array<int,string> $errors
     * @param array<int,string> $issuecodes
     */
    public function __construct(array $input, bool $valid, array $errors = [], array $issuecodes = []) {
        $this->input = $input;
        $this->valid = $valid;
        $this->errors = array_values($errors);
        $this->issuecodes = array_values($issuecodes);
    }
}
