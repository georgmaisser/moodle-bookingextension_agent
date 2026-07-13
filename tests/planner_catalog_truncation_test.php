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

namespace bookingextension_agent;

use advanced_testcase;
use core_text;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Slim-catalog cards must never be cut off mid-sentence (thread 589 / C1).
 *
 * The planner's slim catalog compacts every skill description via
 * planner_catalog_service::compact_catalog_description(). A hard character cap that slices inside
 * a sentence can invert the card's meaning: the live course.create_course card was cut right after
 * "The system asks which course category to use unless…", so the surviving fragment reads as an
 * instruction to ASK instead of ACT. This guard asserts that whenever a card was shortened, the
 * shortened text ends at a sentence boundary of the original description — which holds both when
 * descriptions are authored under the cap and when the truncation becomes sentence-aware.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::compact_catalog_description
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::slim_prompt_catalog_for_planner
 */
final class planner_catalog_truncation_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Every compacted (shortened) catalog card ends at a sentence boundary of its original description.
     *
     * The invariant is applied to ALL skills of the real registry so it doubles as a drift guard for
     * newly added skills; it is trivially satisfied by cards that were not shortened at all.
     */
    public function test_compacted_descriptions_end_at_sentence_boundary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();
        $this->assertNotEmpty($contracts, 'The default skill registry must expose prompt contracts.');

        // Original (un-compacted) description per skill, exactly as the contracts carry it.
        $originals = [];
        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }
            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill !== '') {
                $originals[$skill] = (string)($contract['description'] ?? '');
            }
        }

        // Build the slim planner catalog exactly like the discovery phase does.
        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $catalog = $service->slim_prompt_catalog_for_planner($contracts);
        $this->assertNotEmpty($catalog, 'The slim planner catalog must not be empty.');

        // The two cards of the live defect must actually be covered by this guard.
        $skillnames = array_map(static fn(array $entry): string => (string)$entry['skill'], $catalog);
        $this->assertContains('course.create_course', $skillnames);
        $this->assertContains('course.scaffold_course_content', $skillnames);

        $violations = [];
        foreach ($catalog as $entry) {
            $skill = (string)$entry['skill'];
            $compact = (string)($entry['description'] ?? '');
            $normalized = trim((string)preg_replace('/\s+/', ' ', (string)($originals[$skill] ?? '')));

            // Not shortened (only whitespace-normalized) → the invariant holds trivially.
            if ($compact === '' || $compact === $normalized) {
                continue;
            }

            if (!$this->ends_at_sentence_boundary($compact, $normalized)) {
                $violations[] = $skill . ': card is cut mid-sentence — "' . $compact . '"';
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Slim catalog cards were truncated mid-sentence (shorten the description or truncate at a "
                . "sentence boundary):\n" . implode("\n", $violations)
        );
    }

    /**
     * Whether a shortened card ends exactly where a sentence of the original description ends.
     *
     * An optional trailing ellipsis marker ("..." or "…") is ignored; the remaining text must be a
     * prefix of the (whitespace-normalized) original AND end in a sentence terminator (. ! ?),
     * optionally followed by a closing quote/bracket.
     *
     * @param string $compact the compacted card text
     * @param string $normalized the whitespace-normalized original description
     * @return bool
     */
    private function ends_at_sentence_boundary(string $compact, string $normalized): bool {
        $stripped = rtrim($compact);
        if (str_ends_with($stripped, '…')) {
            $stripped = rtrim(core_text::substr($stripped, 0, core_text::strlen($stripped) - 1));
        } else if (str_ends_with($stripped, '...')) {
            $stripped = rtrim(core_text::substr($stripped, 0, core_text::strlen($stripped) - 3));
        }

        if ($stripped === '' || !str_starts_with($normalized, $stripped)) {
            return false;
        }

        return (bool)preg_match('/[.!?]["\'\)\]]*$/u', $stripped);
    }
}
