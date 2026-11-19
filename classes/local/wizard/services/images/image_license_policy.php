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
 * Which licences let an image be stored in a course, and how it is credited.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

/**
 * Licence rules of the image services. Works on the machine code of the library, never on a text.
 */
class image_license_policy {
    /**
     * Whether an image under this licence may be copied into a course and shown there.
     *
     * Allowed: public domain, CC0, CC BY and CC BY-SA in any version. Everything else (no code, non-commercial,
     * no-derivatives, other free licences with terms the credit line does not cover) is left out.
     *
     * @param string $licensecode Machine code, e.g. "cc-by-sa-4.0", "cc0", "pd".
     * @return bool
     */
    public static function is_reusable(string $licensecode): bool {
        $code = \core_text::strtolower(trim($licensecode));
        if ($code === '') {
            return false;
        }
        if ($code === 'pd' || $code === 'cc0' || $code === 'cc-zero' || strpos($code, 'pd-') === 0 || strpos($code, 'cc0-') === 0) {
            return true;
        }
        if (strpos($code, 'cc-by') !== 0) {
            return false;
        }
        $parts = explode('-', $code);
        return !in_array('nc', $parts, true) && !in_array('nd', $parts, true);
    }

    /**
     * The Moodle licence short name for the stored file, when Moodle has the exact licence.
     *
     * @param string $licensecode
     * @return string
     */
    public static function moodle_license(string $licensecode): string {
        $code = \core_text::strtolower(trim($licensecode));
        if ($code === 'pd' || $code === 'cc0' || $code === 'cc-zero' || strpos($code, 'pd-') === 0 || strpos($code, 'cc0-') === 0) {
            return 'public';
        }
        if ($code === 'cc-by-4.0') {
            return 'cc-4.0';
        }
        if ($code === 'cc-by-sa-4.0') {
            return 'cc-sa-4.0';
        }
        return 'unknown';
    }

    /**
     * The credit line of a candidate as plain text: title, author, licence, library.
     *
     * @param array $candidate
     * @return string
     */
    public static function credit_text(array $candidate): string {
        $parts = [];
        $title = trim((string)($candidate['title'] ?? ''));
        if ($title !== '') {
            $parts[] = '"' . $title . '"';
        }
        $author = trim((string)($candidate['author'] ?? ''));
        if ($author !== '') {
            $parts[] = $author;
        }
        $license = trim((string)($candidate['license'] ?? ''));
        if ($license !== '') {
            $parts[] = $license;
        }
        $source = trim((string)($candidate['sourcename'] ?? ''));
        if ($source !== '') {
            $parts[] = $source;
        }
        return implode(', ', $parts);
    }
}
