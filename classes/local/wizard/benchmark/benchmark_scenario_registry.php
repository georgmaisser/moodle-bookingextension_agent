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

namespace bookingextension_agent\local\wizard\benchmark;

use bookingextension_agent\local\wizard\benchmark\scenarios\create_option_basic;
use bookingextension_agent\local\wizard\benchmark\scenarios\create_option_multistep;
use bookingextension_agent\local\wizard\benchmark\scenarios\update_option_trainer;
use bookingextension_agent\local\wizard\benchmark\scenarios\book_users_single;
use bookingextension_agent\local\wizard\benchmark\scenarios\short_confirm_ja;
use bookingextension_agent\local\wizard\benchmark\scenarios\short_confirm_weiter;
use bookingextension_agent\local\wizard\benchmark\scenarios\clarification_missing_date;
use bookingextension_agent\local\wizard\benchmark\scenarios\confirmation_request_r1;
use bookingextension_agent\local\wizard\benchmark\scenarios\duplicate_prevention;
use bookingextension_agent\local\wizard\benchmark\scenarios\readonly_diagnose;
use bookingextension_agent\local\wizard\benchmark\scenarios\skill_not_in_catalog;
use bookingextension_agent\local\wizard\benchmark\scenarios\auto_confirm_session;
use bookingextension_agent\local\wizard\benchmark\scenarios\retry_preflight_recovery;
use bookingextension_agent\local\wizard\benchmark\scenarios\budget_exceeded;
use bookingextension_agent\local\wizard\benchmark\scenarios\get_current_user_readonly;

/**
 * Registry of available benchmark scenario sets.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_scenario_registry {
    /** @var array<string,array<string>> Scenario set name -> list of class names. */
    private const SETS = [
        'core_booking_v1' => [
            create_option_basic::class,
            create_option_multistep::class,
            update_option_trainer::class,
            book_users_single::class,
            short_confirm_ja::class,
            short_confirm_weiter::class,
            clarification_missing_date::class,
            confirmation_request_r1::class,
            duplicate_prevention::class,
            readonly_diagnose::class,
            skill_not_in_catalog::class,
            auto_confirm_session::class,
            retry_preflight_recovery::class,
            budget_exceeded::class,
            get_current_user_readonly::class,
        ],
    ];

    /**
     * Get all scenario instances for a named set.
     *
     * @param string $setname
     * @return benchmark_scenario_interface[]
     */
    public function get_scenarios(string $setname): array {
        $classes = self::SETS[$setname] ?? self::SETS['core_booking_v1'];
        return array_map(fn($class) => new $class(), $classes);
    }

    /**
     * Return all registered set names.
     *
     * @return string[]
     */
    public function get_set_names(): array {
        return array_keys(self::SETS);
    }
}
