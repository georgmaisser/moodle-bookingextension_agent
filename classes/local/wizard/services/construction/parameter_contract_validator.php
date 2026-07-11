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

namespace bookingextension_agent\local\wizard\services\construction;

use bookingextension_agent\local\wizard\dto\parameter_construction_result;
use bookingextension_agent\local\wizard\interfaces\skill_interface;

/**
 * Structural parameter contract validator for one selected skill.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parameter_contract_validator {
    /**
     * Validate canonical input against skill structural contract.
     *
     * @param skill_interface $skill
     * @param array $input
     * @param string $label
     * @return parameter_construction_result
     */
    public function validate(skill_interface $skill, array $input, string $label): parameter_construction_result {
        $structural = $skill->check_structure($input);
        if (($structural['valid'] ?? true) === true) {
            return new parameter_construction_result($input, true, [], []);
        }

        // F3 two-channel cause contract: a skill that supplies 'repair' guarantees its
        // 'errors' are user_cause texts — those stay label-free (the "Command #N:" label is
        // planner orientation, i.e. repair vocabulary). Legacy skills without the key keep
        // the historical labelled/mixed behaviour until they migrate.
        $migrated = array_key_exists('repair', (array)$structural);

        $errors = [];
        foreach ((array)($structural['errors'] ?? []) as $error) {
            $errors[] = $migrated ? (string)$error : $label . ': ' . $error;
        }

        $repair = [];
        foreach ((array)($structural['repair'] ?? []) as $hint) {
            $hint = trim((string)$hint);
            if ($hint !== '') {
                $repair[] = $label . ': ' . $hint;
            }
        }

        $issuecodes = [];
        foreach ((array)($structural['issue_codes'] ?? []) as $issuecode) {
            $code = trim((string)$issuecode);
            if ($code !== '') {
                $issuecodes[] = $code;
            }
        }

        return new parameter_construction_result($input, false, $errors, $issuecodes, $repair);
    }
}
