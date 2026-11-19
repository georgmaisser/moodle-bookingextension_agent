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
 * Wikimedia Commons as an image library.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

/**
 * Searches Wikimedia Commons through the MediaWiki Action API. No key is needed; every file carries its own
 * licence and author, which the API returns with the hit.
 */
class wikimedia_commons_source implements image_source_interface {
    /** @var string */
    public const KEY = 'commons';

    /** @var string */
    private const API = 'https://commons.wikimedia.org/w/api.php';

    /** @var int Width of the rendition the model looks at and the preview shows. */
    private const PREVIEW_WIDTH = 512;

    /** @var int Width of the rendition that is stored in the course. */
    private const DOWNLOAD_WIDTH = 1600;

    /** @var image_http_client */
    private image_http_client $http;

    /**
     * Constructor.
     *
     * @param image_http_client|null $http
     */
    public function __construct(?image_http_client $http = null) {
        $this->http = $http ?? new image_http_client();
    }

    #[\Override]
    public function get_key(): string {
        return self::KEY;
    }

    #[\Override]
    public function search(string $query, int $limit): array {
        $query = trim($query);
        if ($query === '') {
            return ['candidates' => [], 'error' => ''];
        }
        $pages = $this->query([
            'generator' => 'search',
            // The filetype filter keeps audio, video, PDF and vector files out of the hits.
            'gsrsearch' => $query . ' filetype:bitmap',
            'gsrnamespace' => 6,
            'gsrlimit' => max(1, min(20, $limit)),
        ], self::PREVIEW_WIDTH);
        if ($pages === null) {
            return ['candidates' => [], 'error' => 'The image library did not answer.'];
        }
        // The API returns the pages keyed by id; "index" is the rank of the hit.
        usort($pages, static fn(array $a, array $b): int => ((int)($a['index'] ?? 0)) <=> ((int)($b['index'] ?? 0)));
        $candidates = [];
        foreach ($pages as $page) {
            $candidate = $this->to_candidate($page);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }
        return ['candidates' => $candidates, 'error' => ''];
    }

    #[\Override]
    public function get_by_ref(string $ref): ?array {
        $pageid = self::pageid_of_ref($ref);
        if ($pageid <= 0) {
            return null;
        }
        // Two renditions of the same file: the preview for the check, the larger one to store.
        $preview = $this->query(['pageids' => $pageid], self::PREVIEW_WIDTH);
        $download = $this->query(['pageids' => $pageid], self::DOWNLOAD_WIDTH);
        if (empty($preview) || empty($download)) {
            return null;
        }
        $candidate = $this->to_candidate(reset($preview));
        $large = $this->to_candidate(reset($download));
        if ($candidate === null || $large === null) {
            return null;
        }
        $candidate['downloadurl'] = $large['previewurl'];
        return $candidate;
    }

    /**
     * The page id inside a reference of this source, 0 when the reference is not one of ours.
     *
     * @param string $ref
     * @return int
     */
    public static function pageid_of_ref(string $ref): int {
        $parts = explode(':', trim($ref), 2);
        if (count($parts) !== 2 || $parts[0] !== self::KEY || !ctype_digit($parts[1])) {
            return 0;
        }
        return (int)$parts[1];
    }

    /**
     * Run one imageinfo query.
     *
     * @param array $params Generator or page selection.
     * @param int $width Width of the rendition whose URL is returned.
     * @return array[]|null The pages, null when the request failed.
     */
    private function query(array $params, int $width): ?array {
        $params = [
            'action' => 'query',
            'format' => 'json',
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|mime|extmetadata',
            'iiurlwidth' => $width,
            'iiextmetadatafilter' => 'License|LicenseShortName|LicenseUrl|Artist|AttributionRequired|ObjectName',
        ] + $params;
        // Moodle sets arg_separator.output to "&amp;"; an API URL needs the plain separator.
        $response = $this->http->get(self::API . '?' . http_build_query($params, '', '&'));
        if ($response['code'] !== 200) {
            return null;
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return null;
        }
        return array_values((array)($decoded['query']['pages'] ?? []));
    }

    /**
     * Turn one API page into a candidate.
     *
     * @param array $page
     * @return array|null Null when the page has no usable image data.
     */
    private function to_candidate(array $page): ?array {
        $pageid = (int)($page['pageid'] ?? 0);
        $info = (array)(((array)($page['imageinfo'] ?? []))[0] ?? []);
        $previewurl = (string)($info['thumburl'] ?? '');
        if ($pageid <= 0 || $previewurl === '') {
            return null;
        }
        $meta = (array)($info['extmetadata'] ?? []);
        $value = static function (string $key) use ($meta): string {
            // The fields are HTML fragments of the file page.
            $text = html_entity_decode(strip_tags((string)($meta[$key]['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim((string)preg_replace('/\s+/u', ' ', $text));
        };
        $title = $value('ObjectName');
        if ($title === '') {
            // File page title: "File:Name.jpg".
            $title = (string)preg_replace('/^[^:]+:/', '', (string)($page['title'] ?? ''));
            $title = (string)preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $title);
        }
        return [
            'ref' => self::KEY . ':' . $pageid,
            'title' => \core_text::substr($title, 0, 120),
            'pageurl' => (string)($info['descriptionurl'] ?? ''),
            'previewurl' => $previewurl,
            'downloadurl' => $previewurl,
            'width' => (int)($info['width'] ?? 0),
            'height' => (int)($info['height'] ?? 0),
            'mimetype' => (string)($info['mime'] ?? ''),
            'license' => $value('LicenseShortName'),
            'licensecode' => $value('License'),
            'licenseurl' => $value('LicenseUrl'),
            'author' => \core_text::substr($value('Artist'), 0, 120),
            'attributionrequired' => \core_text::strtolower($value('AttributionRequired')) !== 'false',
            'sourcename' => 'Wikimedia Commons',
        ];
    }
}
