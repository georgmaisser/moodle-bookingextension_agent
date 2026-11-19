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
 * Preview of checked image candidates in the chat.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

/**
 * Renders the candidates as numbered cards with a small picture, what the picture shows and its credit.
 *
 * The pictures are embedded in the preview itself (a small JPEG made from the checked rendition), so the
 * browser of the user contacts no image library.
 */
class image_candidates_preview {
    /** @var string */
    public const PREVIEW_TYPE = 'image_candidates';

    /** @var int Width of the embedded picture (pixels). */
    private const THUMB_WIDTH = 280;

    /**
     * Build the preview.
     *
     * @param array[] $candidates Each with 'number', 'title', 'shows', 'credit', 'pageurl' and 'thumb' (data URI or '').
     * @return array|null
     */
    public function render(array $candidates): ?array {
        if (empty($candidates)) {
            return null;
        }
        $cards = '';
        foreach ($candidates as $candidate) {
            $picture = (string)($candidate['thumb'] ?? '') !== ''
                ? \html_writer::empty_tag('img', [
                    'src' => (string)$candidate['thumb'],
                    'alt' => (string)($candidate['shows'] ?? ''),
                    'class' => 'img-fluid rounded mb-2',
                ])
                : '';
            $number = \html_writer::tag(
                'div',
                s(get_string('imagesearch_preview_number', 'bookingextension_agent', (int)($candidate['number'] ?? 0))),
                ['class' => 'fw-bold']
            );
            $shows = \html_writer::tag('div', s((string)($candidate['shows'] ?? '')), ['class' => 'small']);
            $credit = s((string)($candidate['credit'] ?? ''));
            $pageurl = (string)($candidate['pageurl'] ?? '');
            if ($pageurl !== '' && preg_match('~^https://~', $pageurl)) {
                $credit = \html_writer::link($pageurl, $credit, ['rel' => 'noopener', 'target' => '_blank']);
            }
            $cards .= \html_writer::tag(
                'div',
                $picture . $number . $shows . \html_writer::tag('div', $credit, ['class' => 'text-muted small']),
                ['class' => 'wizard-image-candidate card card-body mb-2', 'style' => 'max-width: 320px;']
            );
        }
        return [
            'type' => self::PREVIEW_TYPE,
            'html' => \html_writer::tag('div', $cards, ['class' => 'wizard-image-candidates d-flex flex-wrap gap-2']),
            'payload' => ['count' => count($candidates)],
        ];
    }

    /**
     * A small JPEG of an image as a data URI, '' when the image cannot be read.
     *
     * @param string $bytes
     * @return string
     */
    public static function thumbnail_data_uri(string $bytes): string {
        if ($bytes === '' || !function_exists('imagecreatefromstring')) {
            return '';
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return '';
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            return '';
        }
        $targetwidth = min(self::THUMB_WIDTH, $width);
        $targetheight = max(1, (int)round($height * $targetwidth / $width));
        $thumb = imagecreatetruecolor($targetwidth, $targetheight);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetwidth, $targetheight, $width, $height);
        ob_start();
        imagejpeg($thumb, null, 72);
        $jpeg = (string)ob_get_clean();
        return $jpeg !== '' ? 'data:image/jpeg;base64,' . base64_encode($jpeg) : '';
    }
}
