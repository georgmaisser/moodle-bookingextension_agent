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
 * Contract of an image library the agent can search.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

/**
 * An image library: keyword search and lookup of one image by its reference.
 *
 * A candidate is an array with these keys: ref (string, "<source key>:<id>"), title, pageurl, previewurl
 * (a small rendition for the check and the preview), downloadurl (the rendition that is stored), width, height
 * (of the original), mimetype, license (name as shown), licensecode (machine code of the library), licenseurl,
 * author, attributionrequired (bool), sourcename (name of the library as shown).
 */
interface image_source_interface {
    /**
     * Short key of the source, the prefix of every reference it hands out.
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Search the library.
     *
     * @param string $query Keywords.
     * @param int $limit Largest number of hits.
     * @return array{candidates: array[], error: string} error is '' when the search ran.
     */
    public function search(string $query, int $limit): array;

    /**
     * Load one image by its reference.
     *
     * @param string $ref
     * @return array|null The candidate, null when the library does not know it.
     */
    public function get_by_ref(string $ref): ?array;
}
