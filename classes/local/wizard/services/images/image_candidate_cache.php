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
 * Remembers which library images the model has checked for a course.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

/**
 * The accepted candidates of a search, kept for an hour.
 *
 * Storing an image must be quick (the preflight of a command has a short time budget) and must only take an
 * image the model has looked at for this course. Both follow from this record: the search writes it after the
 * check, and the skill that stores an image takes nothing that is not in it.
 */
class image_candidate_cache {
    /**
     * Remember an accepted candidate for a course.
     *
     * @param int $courseid
     * @param array $candidate The candidate of the library.
     * @param array $check The result of the check.
     * @return void
     */
    public static function remember(int $courseid, array $candidate, array $check): void {
        unset($candidate['previewbytes'], $candidate['check']);
        \cache::make('bookingextension_agent', 'imagecandidates')->set(
            self::key($courseid, (string)($candidate['ref'] ?? '')),
            ['candidate' => $candidate, 'check' => $check]
        );
    }

    /**
     * The remembered candidate, null when this image was not checked for this course (or too long ago).
     *
     * @param int $courseid
     * @param string $ref
     * @return array{candidate: array, check: array}|null
     */
    public static function recall(int $courseid, string $ref): ?array {
        $entry = \cache::make('bookingextension_agent', 'imagecandidates')->get(self::key($courseid, $ref));
        if (!is_array($entry) || !is_array($entry['candidate'] ?? null) || !is_array($entry['check'] ?? null)) {
            return null;
        }
        return $entry;
    }

    /**
     * Cache key of a course and a reference.
     *
     * @param int $courseid
     * @param string $ref
     * @return string
     */
    private static function key(int $courseid, string $ref): string {
        return $courseid . '_' . sha1($ref);
    }
}
