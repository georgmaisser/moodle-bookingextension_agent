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

/**
 * AI manager that forces every action to resolve to a single chosen provider instance.
 *
 * This is how a benchmark run targets a specific configured AI provider (its own key,
 * models and endpoint) selected in the interface — instead of the BOOKING_TEST_AI_* env
 * vars, which the web/cron context that runs an interface benchmark never sees. The
 * provider instances themselves are configured via the standard core_ai admin UI; this
 * manager just restricts the run to the one the user picked.
 *
 * core_ai\manager::get_providers_for_actions() builds its per-action list from
 * get_sorted_providers(), so narrowing that list to the chosen instance is enough.
 *
 * Process-local DI override, no DB writes. Registered by benchmark_run_service when a
 * provider_instance_id is passed.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_instance_manager extends \core_ai\manager {
    /** @var int The m_ai_providers id to pin the run to. */
    private int $instanceid;

    /**
     * Constructor.
     *
     * @param \moodle_database $db
     * @param int $instanceid The m_ai_providers id to pin the run to.
     */
    public function __construct(\moodle_database $db, int $instanceid) {
        parent::__construct($db);
        $this->instanceid = $instanceid;
    }

    /**
     * Return only the chosen provider instance (so every action resolves to it). Falls back to
     * the full sorted list if the chosen instance no longer exists.
     *
     * @return array
     */
    public function get_sorted_providers(): array {
        $providers = parent::get_sorted_providers();
        if ($this->instanceid > 0 && isset($providers[$this->instanceid])) {
            return [$this->instanceid => $providers[$this->instanceid]];
        }
        return $providers;
    }
}
