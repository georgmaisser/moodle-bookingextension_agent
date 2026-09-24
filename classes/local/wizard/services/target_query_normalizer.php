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
 * Structural normalisation of the name a user typed for a target.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Two tolerances every resolver needs, and neither of them reads a word (#2453, wave 19).
 *
 * Baseline runs 25-27: "Madame wbtf_duval@example.invalid" reached the user resolvers, which test for an
 * "@" and then look the WHOLE string up as an e-mail address; and "Vorstellungs-Forum" reached the module
 * resolver, which compares the raw string with the forum named "Vorstellungsforum". Both are shape, not
 * language: an address-shaped token is an address wherever it stands, and two names that differ only in
 * hyphens, spaces or case are the same name. Nothing here knows a salutation, an article or a language.
 */
final class target_query_normalizer {
    /**
     * The one address-shaped token inside a query, or '' when there is none or more than one.
     *
     * @param string $query
     * @return string
     */
    public static function address_token(string $query): string {
        if (!preg_match_all('/[^\s@<>"\'()\[\],;]+@[^\s@<>"\'()\[\],;]+/u', $query, $matches)) {
            return '';
        }
        $tokens = array_values(array_unique($matches[0]));
        return count($tokens) === 1 ? rtrim($tokens[0], '.') : '';
    }

    /**
     * A comparison key for a name: letters and digits of any script, lower case, nothing else.
     *
     * "Vorstellungs-Forum", "Vorstellungsforum" and "vorstellungs forum" share one key.
     *
     * @param string $name
     * @return string
     */
    public static function name_key(string $name): string {
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', \core_text::strtolower($name));
        return (string)$key;
    }

    /**
     * Narrow a person query to one user by its name tokens when the whole query found nobody (wave 26).
     *
     * "Mr Okafor" (baseline runs 28-32, SVO-3) was searched as one string and matched nobody, although
     * "Okafor" is unique on the site. Every whitespace-separated token is searched on its own; a token
     * that matches nobody carries no meaning (a salutation, a title - no word list decides that), the
     * tokens that do match must agree on exactly one user. Several users → the candidates are returned
     * for the caller's ambiguity question; nothing matches → empty.
     *
     * @param string $query The whole query as the planner sent it.
     * @param callable $search fn(string $token, int $limit): array of candidates carrying 'userid'.
     * @param int $limit Candidates per token lookup.
     * @return array The candidates every matching token agrees on (one = resolved).
     */
    public static function narrow_by_tokens(string $query, callable $search, int $limit = 25): array {
        $tokens = preg_split('/\s+/u', trim($query)) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(
            static fn($token): string => trim((string)$token, " \t\n\r\0\x0B.,;:!?\"'()"),
            $tokens
        ), static fn(string $token): bool => $token !== '' && self::address_token($token) === '' && !ctype_digit($token))));
        if (count($tokens) < 2) {
            return [];
        }

        $agreed = null;
        foreach ($tokens as $token) {
            $byid = [];
            foreach ((array)$search($token, $limit) as $candidate) {
                $userid = (int)($candidate['userid'] ?? 0);
                if ($userid > 0) {
                    $byid[$userid] = $candidate;
                }
            }
            if (empty($byid)) {
                continue;
            }
            $agreed = $agreed === null ? $byid : array_intersect_key($agreed, $byid);
            if (empty($agreed)) {
                return [];
            }
        }

        return $agreed === null ? [] : array_values($agreed);
    }
}
