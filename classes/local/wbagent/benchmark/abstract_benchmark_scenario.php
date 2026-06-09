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
 * Base scenario with sensible defaults.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_benchmark_scenario implements benchmark_scenario_interface {
    /**
     * Get prior messages for the scenario.
     *
     * @return array
     */
    public function get_prior_messages(): array {
        return [];
    }

    /**
     * Whether the scenario expects planned steps.
     *
     * @return bool
     */
    public function expects_planned_steps(): bool {
        return false;
    }

    /**
     * Whether the scenario requires a live LLM execution.
     *
     * @return bool
     */
    public function requires_live_llm(): bool {
        return false;
    }

    /**
     * Get stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '';
    }

    /**
     * Perform additional assertions on results.
     *
     * @param array $result Results to assert.
     * @return array Validation errors/issues if any.
     */
    public function assert_additional(array $result): array {
        return [];
    }
}
