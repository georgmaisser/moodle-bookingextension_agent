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

namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Base class for AI tasks.
 *
 * Provides default pass-through implementations for structural and preflight
 * validation without legacy validate() shims.
 *
 * Migration path for subclasses:
 *  1. Override check_structure() for pure structural checks.
 *  2. Override preflight()       for DB-dependent deep validation.
 *  3. Override execute()         to use $preparedinput from preflight().
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_task implements task_interface {
    /** @var bool */
    protected bool $readonly;

    /**
     * Constructor.
     *
     * @param bool $readonly
     */
    public function __construct(bool $readonly = false) {
        $this->readonly = $readonly;
    }

    /**
     * Return whether the task is read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return $this->readonly;
    }

    /**
     * Default example input.
     *
     * Concrete task families can override this to provide centralized example
     * metadata close to their task implementations.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [];
    }

    /**
     * Default structural validation — always passes.
     *
     * Override in concrete tasks to check required fields without DB access.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Default deep preflight keeps input unchanged after structure checks pass.
     *
     * @param  array $input
     * @param  int   $cmid
     * @param  int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? true)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return preflight_result_v2::invalid($issues);
        }

        return preflight_result_v2::ok($input);
    }
}
