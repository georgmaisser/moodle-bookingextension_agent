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
 * Tests for the structural target-query normalisation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Wave 19 (#2453): the two tolerances the resolvers were missing in baseline runs 25-27.
 *
 * @covers \bookingextension_agent\local\wizard\services\target_query_normalizer
 */
final class target_query_normalizer_test extends \basic_testcase {
    /**
     * A salutation, a name or punctuation around an address does not hide the address.
     */
    public function test_the_address_token_is_found_wherever_it_stands(): void {
        $this->assertSame(
            'wbtf_duval@example.invalid',
            target_query_normalizer::address_token('Madame wbtf_duval@example.invalid')
        );
        $this->assertSame('h.reisinger@firma.at', target_query_normalizer::address_token('Herr h.reisinger@firma.at.'));
        $this->assertSame('tom@example.org', target_query_normalizer::address_token('tom@example.org'));
        $this->assertSame('tom@example.org', target_query_normalizer::address_token('Tom (tom@example.org), please'));
    }

    /**
     * No address, or more than one, is no address.
     */
    public function test_none_or_several_addresses_yield_nothing(): void {
        $this->assertSame('', target_query_normalizer::address_token('Madame Duvernay'));
        $this->assertSame('', target_query_normalizer::address_token('a@x.org and b@x.org'));
        $this->assertSame('', target_query_normalizer::address_token(''));
    }

    /**
     * Hyphens, spaces and case do not make two names different.
     */
    public function test_a_name_key_ignores_hyphens_spaces_and_case(): void {
        $forum = target_query_normalizer::name_key('Vorstellungsforum');
        $this->assertSame($forum, target_query_normalizer::name_key('Vorstellungs-Forum'));
        $this->assertSame($forum, target_query_normalizer::name_key('vorstellungs forum'));
        $this->assertSame('übungsdaten', target_query_normalizer::name_key('Übungs-Daten'));
        $this->assertNotSame($forum, target_query_normalizer::name_key('Vorstellungsrunde'));
    }
}
