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
 * Stores an image from a library as the image of a course.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

use context_course;

/**
 * Writes the course image (file area course/overviewfiles) and its credit.
 *
 * The credit is kept in two places: author, licence and source on the stored file, and a line at the end of the
 * course summary, because Moodle shows no author next to a course image and most free licences require one.
 */
class course_image_store {
    /** @var string CSS class that marks the credit line in the course summary, so a later image replaces it. */
    public const CREDIT_CLASS = 'wizard-course-image-credit';

    /**
     * Whether the course has an image.
     *
     * @param int $courseid
     * @return bool
     */
    public static function has_image(int $courseid): bool {
        $files = get_file_storage()->get_area_files(
            context_course::instance($courseid)->id,
            'course',
            'overviewfiles',
            0,
            'filename',
            false
        );
        foreach ($files as $file) {
            if ($file->is_valid_image()) {
                return true;
            }
        }
        return false;
    }

    /**
     * What the bytes are: a JPEG or PNG image and its real type, read from the data itself.
     *
     * @param string $bytes
     * @return array{mimetype:string, extension:string}|null Null when the data is not a JPEG or PNG image.
     */
    public static function identify(string $bytes): ?array {
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            return ['mimetype' => 'image/jpeg', 'extension' => 'jpg'];
        }
        if ($info[2] === IMAGETYPE_PNG) {
            return ['mimetype' => 'image/png', 'extension' => 'png'];
        }
        return null;
    }

    /**
     * Store the image and its credit.
     *
     * @param \stdClass $course
     * @param array $candidate The candidate of the library (title, author, licence, page URL).
     * @param string $bytes The image data.
     * @param int $userid The acting user.
     * @return array{success:bool, filename:string, credit:string, replaced:bool, error:string}
     */
    public function store(\stdClass $course, array $candidate, string $bytes, int $userid): array {
        global $CFG, $USER;
        $fail = static fn(string $error): array => [
            'success' => false, 'filename' => '', 'credit' => '', 'replaced' => false, 'error' => $error,
        ];
        $type = self::identify($bytes);
        if ($type === null) {
            return $fail('The downloaded file is not a JPEG or PNG image.');
        }
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/filelib.php');
        // The site decides whether a course has images and which file types they may have.
        $options = course_overviewfiles_options($course);
        $basename = clean_filename((string)preg_replace('/[^A-Za-z0-9]+/', '-', (string)($candidate['title'] ?? '')));
        $basename = trim(\core_text::substr($basename, 0, 60), '-');
        $filename = ($basename !== '' ? $basename : 'course-image') . '.' . $type['extension'];
        if (
            empty($options)
            || !(new \core_form\filetypes_util())->is_allowed_file_type($filename, $options['accepted_types'] ?? '*')
        ) {
            return $fail('This site does not allow ' . $type['extension'] . ' files as course images.');
        }

        $replaced = self::has_image((int)$course->id);
        $credit = image_license_policy::credit_text($candidate);
        // The image goes through a draft area and update_course(), as the course settings form does: the new
        // file takes the place of what the area holds, caches are cleared and the course_updated event fires.
        // The draft area belongs to the session user, who is the acting user of the run.
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance((int)$USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
            'author' => \core_text::substr((string)($candidate['author'] ?? ''), 0, 255),
            'license' => image_license_policy::moodle_license((string)($candidate['licensecode'] ?? '')),
            'source' => \core_text::substr((string)($candidate['pageurl'] ?? ''), 0, 1000),
        ], $bytes);
        update_course((object)[
            'id' => (int)$course->id,
            'overviewfiles_filemanager' => $draftitemid,
            'summary' => self::with_credit((string)$course->summary, $candidate),
            'summaryformat' => (int)$course->summaryformat,
        ]);

        return ['success' => true, 'filename' => $filename, 'credit' => $credit, 'replaced' => $replaced, 'error' => ''];
    }

    /**
     * The course summary with the credit line of this image at its end; an earlier credit line is replaced.
     *
     * @param string $summary
     * @param array $candidate
     * @return string
     */
    public static function with_credit(string $summary, array $candidate): string {
        // Our own marker: the paragraph this class wrote before.
        $summary = (string)preg_replace(
            '~\s*<p class="' . preg_quote(self::CREDIT_CLASS, '~') . '">.*?</p>~s',
            '',
            $summary
        );
        $credit = image_license_policy::credit_text($candidate);
        if ($credit === '') {
            return $summary;
        }
        $pageurl = (string)($candidate['pageurl'] ?? '');
        $text = s(get_string('imagesearch_credit', 'bookingextension_agent', $credit));
        if ($pageurl !== '' && preg_match('~^https://~', $pageurl)) {
            $text = \html_writer::link($pageurl, $text, ['rel' => 'noopener', 'target' => '_blank']);
        }
        return rtrim($summary) . "\n" . '<p class="' . self::CREDIT_CLASS . '"><small>' . $text . '</small></p>';
    }
}
