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
 * One targeted repair round for a confirmation that arrived without commands.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Decide whether a construction result is worth one more round, and how to ask for it.
 *
 * Baseline run 15 showed the pattern in four threads: the constructor writes a complete confirmation sentence
 * ("Ich werde Rooftop Yoga in … umbenennen und einen Termin am 31. Oktober hinzufügen.") and returns an empty
 * `commands` array. The engine then downgrades the turn to a clarification, so the user is asked to confirm
 * something the engine has not staged — there is no pending action and no confirm channel.
 *
 * The trigger is engine state (the issue code raised by the interpreter), never the wording of the message.
 * The repair instruction is a prompt instruction, which is allowed; lexical DETECTION is not used anywhere here.
 */
class constructor_command_repair {
    /** Issue code the interpreter raises when it turns a command-less confirmation into a question. */
    public const DOWNGRADE_CODE = 'CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION';

    /**
     * Whether this construction result should get one more round.
     *
     * @param array $interpreted Interpreted construction output.
     * @param string[] $requiredfields Fields the selected skill's schema really requires.
     * @return bool
     */
    public static function is_repairable(array $interpreted, array $requiredfields = []): bool {
        $codes = array_map('strval', (array)($interpreted['issue_codes'] ?? []));
        if (in_array(self::DOWNGRADE_CODE, $codes, true)) {
            return true;
        }

        // Second case (baseline run 16: AA-1, SCC-3, UQ-4, TSA-4, DMD-2): the constructor asks for input
        // although the skill declares no required field at all. Its own guidance usually says the skill asks
        // for what is missing. Engine state decides: empty required list + "input required" issue code.
        return $requiredfields === []
            && in_array('CONSTRUCTION_INPUT_REQUIRED', $codes, true)
            && trim((string)($interpreted['response_type'] ?? '')) === 'clarification';
    }

    /**
     * The instruction appended for the repair round.
     *
     * It offers both ways out on purpose. A bare retry hint used to push the model into inventing command keys
     * (see interpreter::interpret), so the alternative "say that you need input instead" is stated explicitly.
     *
     * @param string $selectedskill Skill the construction phase is building for.
     * @param string[] $requiredfields Fields the selected skill's schema really requires.
     * @return string
     */
    public static function instruction(string $selectedskill, array $requiredfields = []): string {
        $requirement = $requiredfields === []
            ? "This skill declares NO required field: everything it needs it resolves or asks for itself.\n"
            : 'Required fields of this skill: ' . implode(', ', $requiredfields) . ".\n";

        return "\n\nREPAIR ROUND (the previous answer broke the contract):\n"
            . $requirement
            . "Your last answer asked the user instead of staging the action. Answer again, and choose exactly "
            . "one of these two:\n"
            . "1) Emit the action you described as a command: response_type=confirmation_request with commands "
            . "containing exactly one object for {\"skill\":\"" . $selectedskill . "\"} and the parameters you "
            . "already worked out.\n"
            . "2) If a value is genuinely missing and only the user can supply it, answer with "
            . "response_type=clarification and ask for that one value.\n"
            . "Do not repeat the previous answer.";
    }

    /**
     * Whether a repaired result may replace the first one.
     *
     * @param array $repaired Interpreted output of the repair round.
     * @param string $selectedskill
     * @return bool
     */
    public static function accept(array $repaired, string $selectedskill): bool {
        $commands = (array)($repaired['commands'] ?? []);
        if (empty($commands)) {
            return false;
        }
        foreach ($commands as $command) {
            if (!is_array($command) || trim((string)($command['skill'] ?? '')) !== trim($selectedskill)) {
                return false;
            }
        }

        return true;
    }
}
