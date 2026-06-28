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
use bookingextension_agent\local\wizard\benchmark\scenarios\catalog_gap_bulk_cancel;
use bookingextension_agent\local\wizard\benchmark\scenarios\duplicate_prevention;
use bookingextension_agent\local\wizard\benchmark\scenarios\readonly_diagnose;
use bookingextension_agent\local\wizard\benchmark\scenarios\skill_not_in_catalog;
use bookingextension_agent\local\wizard\benchmark\scenarios\auto_confirm_session;
use bookingextension_agent\local\wizard\benchmark\scenarios\retry_preflight_recovery;
use bookingextension_agent\local\wizard\benchmark\scenarios\ambiguous_request_no_hallucination;
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
            catalog_gap_bulk_cancel::class,
            duplicate_prevention::class,
            readonly_diagnose::class,
            skill_not_in_catalog::class,
            auto_confirm_session::class,
            retry_preflight_recovery::class,
            ambiguous_request_no_hallucination::class,
            get_current_user_readonly::class,
        ],
    ];

    /**
     * The curated DECISION set (default): the smallest list that still prepares the decisions we care
     * about, runnable live in ~1 minute. Exhaustive per-skill coverage is NOT the goal here — that
     * lives in the PHPUnit skill matrix. This set is deliberately a SUBSET, picked for decision value:
     *  - the resolve-then-act regression guard (book_users, not search_*),
     *  - the hardest disambiguation pair (course access vs enrolment),
     *  - the 3-way create family (the two non-trivial arms),
     *  - search target disambiguation (option vs course),
     *  - the two no-skill cases (the known model weak spot: route to wizard.search_skills, §6.3),
     *  - one de/en pair to read the cross-language bridge directly.
     * A set that scores a stable 100% measures nothing, so it deliberately INCLUDES the known hard
     * case (skill_not_in_catalog, which failed ~34/35 live) — otherwise the lean set has no signal.
     * Referenced by KEY (not ::class) so it can mix curated scenarios and route_* cluster scenarios.
     *
     * @var string[]
     */
    private const DECISION_CORE = [
        'book_users_single',
        'route_diagnose_access_de',
        'route_diagnose_enrolment_de',
        'route_create_selflearning_de',
        'route_create_slotbooking_de',
        'route_search_options_de',
        'route_search_courses_de',
        'route_search_options_en',
        'catalog_gap_bulk_cancel',
        'skill_not_in_catalog_no_hallucination',
    ];

    /**
     * Get all scenario instances for a named set.
     *
     * Sets:
     *  - 'decision_core' (default): the curated ~1-minute decision subset (see DECISION_CORE).
     *  - 'core_booking_v1' / anything else: the broad set — curated classes PLUS every confusable
     *    cluster (scenarios/route_*.php), for an occasional deep run.
     *
     * @param string $setname
     * @return benchmark_scenario_interface[]
     */
    public function get_scenarios(string $setname): array {
        $all = $this->build_full_universe();
        if ($setname === 'decision_core') {
            $bykey = [];
            foreach ($all as $scenario) {
                $bykey[$scenario->get_key()] = $scenario;
            }
            $out = [];
            foreach (self::DECISION_CORE as $key) {
                if (isset($bykey[$key])) {
                    $out[] = $bykey[$key];
                }
            }
            return $out;
        }
        return $all;
    }

    /**
     * Build the full universe of scenarios: the curated classes plus every auto-discovered confusable
     * cluster (scenarios/route_*.php, sorted for harness determinism). A new cluster file is picked up
     * here without touching the registry.
     *
     * @return benchmark_scenario_interface[]
     */
    private function build_full_universe(): array {
        $scenarios = array_map(fn($class) => new $class(), self::SETS['core_booking_v1']);
        $routefiles = glob(__DIR__ . '/scenarios/route_*.php') ?: [];
        sort($routefiles);
        foreach ($routefiles as $file) {
            $class = __NAMESPACE__ . '\\scenarios\\' . basename($file, '.php');
            if (class_exists($class)) {
                $scenarios[] = new $class();
            }
        }
        return $scenarios;
    }
}
