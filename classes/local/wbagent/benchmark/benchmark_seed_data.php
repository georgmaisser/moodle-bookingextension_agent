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

namespace bookingextension_agent\local\wbagent\benchmark;

/**
 * Reproducible seed data for benchmark scenarios.
 *
 * All IDs and values are fixed so runs are comparable across environments
 * and over time. Names are anonymized (no real personal data).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_seed_data {
    // Fixed booking option IDs used across scenarios.
    public const OPTION_ID_A = 1001;
    public const OPTION_ID_B = 1002;
    public const OPTION_ID_TRAINER = 1003;

    // Fixed user IDs (anonymized).
    public const USER_ID_TRAINER = 2001;
    public const USER_ID_PARTICIPANT = 2002;
    public const USER_ID_ADMIN = 2003;

    // Fixed context/cmid.
    public const CMID = 25;
    public const CONTEXT_ID = 6168;
    public const BOOKING_ID = 12;

    // Fixed thread ID for replay scenarios.
    public const THREAD_ID = 9001;

    // Reproducible timestamps (anchored to 2026-06-09T10:00:00 Europe/Vienna).
    public const OPTION_STARTTIME_TUE = 1780992000;
    public const OPTION_ENDTIME_TUE   = 1780999200;
    public const OPTION_STARTTIME_WED = 1781078400;
    public const OPTION_ENDTIME_WED   = 1781085600;

    /**
     * Return a standard context block for scenario prompts.
     *
     * @return array<string,mixed>
     */
    public static function system_runtime_context(): array {
        return [
            'booking_name' => 'BenchmarkBooking',
            'timezone'     => 'Europe/Vienna',
            'cmid'         => self::CMID,
            'contextid'    => self::CONTEXT_ID,
            'now_iso'      => '2026-06-04T10:00:00+02:00',
        ];
    }

    /**
     * Return a completed_commands entry for a created option.
     *
     * @param string $title
     * @param int $optionid
     * @return array<string,mixed>
     */
    public static function completed_create_option(string $title, int $optionid): array {
        return [
            'task'   => 'mod_booking.create_option',
            'status' => 'executed',
            'input'  => ['text' => $title, 'maxanswers' => 9],
            'result' => "Booking option created (title=\"{$title}\", id={$optionid})",
        ];
    }

    /**
     * Return a prior assistant message listing pending steps (used in follow-up scenarios).
     *
     * @param string[] $pending  List of pending step descriptions.
     * @return string
     */
    public static function pending_steps_assistant_message(array $pending): string {
        $lines = implode("\n", array_map(fn($p, $i) => ($i + 1) . ". {$p}", $pending, array_keys($pending)));
        return "## Noch ausstehend\n\n{$lines}\n\nMoechtest du, dass ich weitermache?";
    }

    /**
     * Return all seed data as an associative array for documentation/export.
     *
     * @return array<string,mixed>
     */
    public static function all(): array {
        return [
            'option_ids'     => [self::OPTION_ID_A, self::OPTION_ID_B, self::OPTION_ID_TRAINER],
            'user_ids'       => [self::USER_ID_TRAINER, self::USER_ID_PARTICIPANT, self::USER_ID_ADMIN],
            'cmid'           => self::CMID,
            'context_id'     => self::CONTEXT_ID,
            'booking_id'     => self::BOOKING_ID,
            'thread_id'      => self::THREAD_ID,
            'timestamps'     => [
                'tuesday_start' => self::OPTION_STARTTIME_TUE,
                'tuesday_end'   => self::OPTION_ENDTIME_TUE,
                'wednesday_start' => self::OPTION_STARTTIME_WED,
                'wednesday_end'   => self::OPTION_ENDTIME_WED,
            ],
        ];
    }
}
