<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Scenario catalog_gap_bulk_cancel.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);
namespace bookingextension_agent\local\wizard\benchmark\scenarios;
use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Scenario: "delete all bookings for a course" — no such skill exists, agent must return error.
 *
 * There is no bulk-cancel-all-bookings skill in the catalog. The correct selector
 * response is error, not skill_call. This verifies the agent does not hallucinate a skill.
 *
 * @package bookingextension_agent
 */
class catalog_gap_bulk_cancel extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'catalog_gap_bulk_cancel';
    }
    /**
     * Get the scenario class.
     *
     * @return string
     */
    public function get_class(): string {
        return 'catalog_gap';
    }
    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'No bulk-cancel skill in catalog — agent must return error, not hallucinate a skill_call';
    }
    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Loesche alle Buchungen fuer den Kurs "Yoga Intensiv".';
    }
    /**
     * Get the expected response type.
     *
     * @return string
     */
    public function get_expected_response_type(): string {
        return 'error';
    }

    /**
     * Catalog gap is model-dependent routing (Tier 2): two outcomes are equally correct — a clean
     * `error` ("I can't do that") OR routing to `wizard.search_skills` to look for the capability.
     * Both prove the agent did NOT hallucinate a non-existent bulk-cancel skill (enforced in
     * assert_additional). See docs/Blueprints/BENCHMARK_REDESIGN.md §8.3.
     *
     * @return string[]
     */
    public function get_acceptable_response_types(): array {
        return ['error', 'skill_call'];
    }

    /**
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return '';
    }

    /**
     * If the agent emitted a skill_call on this catalog gap, the ONLY non-hallucinated choice is
     * wizard.search_skills. Any concrete booking skill = a hallucinated capability = fail.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        if (($result['response_type'] ?? '') !== 'skill_call') {
            return [];
        }
        $skill = (string)($result['commands'][0]['skill'] ?? '');
        return [
            [
                'label'  => 'catalog-gap skill_call must be wizard.search_skills (no hallucinated skill)',
                'passed' => $skill === 'wizard.search_skills',
                'detail' => 'skill: ' . $skill,
            ],
        ];
    }

    /**

     * Get the stub selector response.

     *

     * @return string

     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"error","commands":[],'
            . '"planned_steps":[],"next_step_intent":"",'
            . '"message":"Das Loeschen aller Buchungen fuer einen Kurs ist mit den aktuellen Aufgaben nicht moeglich.",'
            . '"used_triggers":[],"lang":"de","user_lang":"de"}';
    }
}
