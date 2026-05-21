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

use bookingextension_agent\local\wbagent\task_preflight_result;

/**
 * Task definition for slot-based appointment options.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_slotbooking_option_task extends create_option_task {
    /** Task name constant. */
    public const TASK_NAME = 'booking.create_slotbooking_option';

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
        unset($properties['selflearningcourse'], $properties['duration'], $properties['disablecancel']);

        $schema['description'] = 'Create a slot-based booking option (appointment scheduling) in one command. '
            . 'Use this for recurring weekday windows with slot duration, validity range, and per-slot capacity. '
            . 'Do not split into many commands; provide one complete slot configuration.';
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
                'id' => 'booking.create_slotbooking_request',
                'description' => 'User asks for slot/appointment booking with recurring weekday availability and slot duration.',
                'examples' => [
                    'Mein Tennisplatz soll jeden Wochentag von 10 bis 18 Uhr buchbar sein, in 1h-Slots.',
                    'Create appointment slots Monday to Friday from 09:00 to 17:00 for August.',
                ],
            ],
        ];
    }

    /**
     * Deep preflight validation for slotbooking-specific create flow.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return task_preflight_result
     */
    public function preflight(array $input, int $cmid, int $userid): task_preflight_result {
        unset($input['selflearningcourse'], $input['duration'], $input['disablecancel']);
        $input['optiontype'] = 'slotbooking';
        $input['slot_enabled'] = true;
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
        unset($preparedinput['selflearningcourse'], $preparedinput['duration'], $preparedinput['disablecancel']);
        $preparedinput['optiontype'] = 'slotbooking';
        $preparedinput['slot_enabled'] = true;
        return parent::execute($preparedinput, $cmid, $userid);
    }
}
