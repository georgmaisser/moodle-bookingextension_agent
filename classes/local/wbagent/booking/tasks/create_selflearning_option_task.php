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

namespace bookingextension_agent\local\wbagent\booking\tasks;

use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Task definition for self-learning booking options.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_selflearning_option_task extends create_option_task {
    /** Task name constant. */
    public const TASK_NAME = 'booking.create_selflearning_option';

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = parent::get_schema();
        $properties = is_array($schema['properties'] ?? null) ? (array)$schema['properties'] : [];

        unset($properties['optiontype'], $properties['slot_enabled']);
        foreach (array_keys($properties) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                unset($properties[$key]);
            }
        }

        $schema['description'] = 'Create a self-learning booking option in one command. '
            . 'Use this for e-learning style options where users are booked for a duration instead of fixed slot windows.';
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.create_selflearning_request',
                'description' => 'User asks for a self-learning/e-learning option with duration-based participation.',
                'examples' => [
                    'Erstelle einen Selbstlernkurs mit einer Lerndauer von 4 Stunden.',
                    'Create a self-learning booking option for 2 hours duration.',
                ],
            ],
        ];
    }

    /**
     * Deep preflight validation for self-learning-specific create flow.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
        unset($input['slot_enabled']);
        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                unset($input[$key]);
            }
        }

        $input['optiontype'] = 'selflearning';
        $input['selflearningcourse'] = true;
        return parent::preflight($input, $cmid, $userid);
    }

    /**
     * Execute task using prepared input from preflight.
     *
     * @param array $preparedinput
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array {
        unset($preparedinput['slot_enabled']);
        foreach (array_keys($preparedinput) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                unset($preparedinput[$key]);
            }
        }

        $preparedinput['optiontype'] = 'selflearning';
        $preparedinput['selflearningcourse'] = true;
        return parent::execute($preparedinput, $cmid, $userid);
    }
}
