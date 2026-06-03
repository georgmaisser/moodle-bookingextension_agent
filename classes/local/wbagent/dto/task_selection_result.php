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

use bookingextension_agent\local\wbagent\interfaces\task_interface;

/**
 * DTO for selected task resolution.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_selection_result {
    /** @var string */
    public readonly string $taskname;

    /** @var int */
    public readonly int $version;

    /** @var task_interface|null */
    public readonly ?task_interface $task;

    /** @var bool */
    public readonly bool $valid;

    /** @var array<int,string> */
    public readonly array $errors;

    /**
     * Constructor.
     *
     * @param string $taskname
     * @param int $version
     * @param task_interface|null $task
     * @param bool $valid
     * @param array<int,string> $errors
     */
    public function __construct(
        string $taskname,
        int $version,
        ?task_interface $task,
        bool $valid,
        array $errors = []
    ) {
        $this->taskname = $taskname;
        $this->version = max(1, $version);
        $this->task = $task;
        $this->valid = $valid;
        $this->errors = array_values($errors);
    }
}
