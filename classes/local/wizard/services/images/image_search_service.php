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
 * Finds image candidates for a course: the model writes the search, a library is searched, the model checks.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;

/**
 * The search for a course image.
 *
 * 1. The model reads the course (title, summary, section names; masked) and writes what the image should show
 *    and a few English search queries.
 * 2. The library is searched. Hits are filtered by facts: licence, file type, size, format.
 * 3. The model looks at each remaining image (image_check_service). Only accepted images are returned.
 *
 * The number of checks is capped, because each one is a model call.
 */
class image_search_service {
    /** @var int Most images returned. */
    public const MAX_RESULTS = 3;

    /** @var int Most images the model looks at in one search. */
    public const MAX_CHECKS = 8;

    /** @var int Hits requested per query. */
    private const HITS_PER_QUERY = 5;

    /** @var int Smallest width of the original (pixels). */
    public const MIN_WIDTH = 800;

    /** @var string[] File types that are stored as a course image. */
    public const MIMETYPES = ['image/jpeg', 'image/png'];

    /** @var conversation_store */
    private conversation_store $store;

    /** @var image_source_interface */
    private image_source_interface $source;

    /** @var image_http_client */
    private image_http_client $http;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     * @param image_source_interface|null $source
     * @param image_http_client|null $http
     */
    public function __construct(
        conversation_store $store,
        ?image_source_interface $source = null,
        ?image_http_client $http = null
    ) {
        $this->store = $store;
        $this->http = $http ?? new image_http_client();
        $this->source = $source ?? new wikimedia_commons_source($this->http);
    }

    /**
     * Whether the image search can run on this site: the admin switched it on and a model can look at images.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return self::is_enabled() && llm_call_service::image_inspection_available();
    }

    /**
     * Whether the admin switched the image search on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (int)get_config('bookingextension_agent', 'imagesearch_enabled') === 1;
    }

    /**
     * The text that describes a course to the model: title, summary and section names, masked for the thread.
     *
     * @param \stdClass $course
     * @param int $threadid
     * @return string
     */
    public function describe_course(\stdClass $course, int $threadid): string {
        global $DB;
        $summary = trim(html_to_text((string)$course->summary, 0, false));
        $sections = $DB->get_fieldset_select(
            'course_sections',
            'name',
            "course = :course AND name IS NOT NULL AND name <> ''",
            ['course' => (int)$course->id],
            'section ASC'
        );
        $text = "Course title: " . trim(html_to_text(format_string((string)$course->fullname), 0, false)) . "\n"
            . "Summary: " . ($summary !== '' ? \core_text::substr($summary, 0, 600) : '(none)') . "\n"
            . "Sections: " . (empty($sections) ? '(none)' : implode('; ', array_slice($sections, 0, 10)));
        if ($threadid <= 0) {
            return $text;
        }
        return (string)(new privacy_anonymizer($this->store))->anonymize_value_for_llm($threadid, $text);
    }

    /**
     * Search and check.
     *
     * @param string $placetext The course as described by {@see describe_course()}.
     * @param string $wish What the user said the image should show, '' when nothing.
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return array{subject:string, queries:string[], accepted:array[], checked:int, rejected:int, unchecked:int, error:string}
     *         Each accepted entry is a candidate with the extra keys 'check' (result of the check) and
     *         'previewbytes'. error is '' unless nothing could be searched or checked at all.
     */
    public function find(string $placetext, string $wish, int $threadid, int $contextid, int $userid): array {
        $result = [
            'subject' => '', 'queries' => [], 'accepted' => [], 'checked' => 0, 'rejected' => 0, 'unchecked' => 0,
            'error' => '',
        ];
        $plan = $this->plan_search($placetext, $wish, $threadid, $contextid, $userid);
        if ($plan['error'] !== '') {
            $result['error'] = $plan['error'];
            return $result;
        }
        $result['subject'] = $plan['subject'];
        $result['queries'] = $plan['queries'];

        $checker = new image_check_service($this->store);
        $seen = [];
        $searcherrors = 0;
        foreach ($plan['queries'] as $query) {
            $search = $this->source->search($query, self::HITS_PER_QUERY);
            if ($search['error'] !== '') {
                $searcherrors++;
                continue;
            }
            foreach ($search['candidates'] as $candidate) {
                $looked = $result['checked'] + $result['unchecked'];
                if (count($result['accepted']) >= self::MAX_RESULTS || $looked >= self::MAX_CHECKS) {
                    break 2;
                }
                $ref = (string)$candidate['ref'];
                if (isset($seen[$ref]) || !self::passes_facts($candidate)) {
                    continue;
                }
                $seen[$ref] = true;
                $image = $this->http->get((string)$candidate['previewurl']);
                if ($image['code'] !== 200 || $image['body'] === '') {
                    $result['unchecked']++;
                    continue;
                }
                $check = $checker->check(
                    $image['body'],
                    (string)$candidate['mimetype'],
                    $result['subject'],
                    $placetext,
                    $threadid,
                    $contextid,
                    $userid,
                    $wish !== ''
                );
                if (!$check['checked']) {
                    $result['unchecked']++;
                    continue;
                }
                $result['checked']++;
                if (!$check['accepted']) {
                    $result['rejected']++;
                    continue;
                }
                $candidate['check'] = $check;
                $candidate['previewbytes'] = $image['body'];
                $result['accepted'][] = $candidate;
            }
        }
        if ($searcherrors === count($plan['queries'])) {
            $result['error'] = 'The image library could not be reached.';
        } else if ($result['checked'] === 0 && $result['unchecked'] > 0) {
            $result['error'] = 'No image could be checked by the model.';
        }
        return $result;
    }

    /**
     * The checks that need no model: licence, file type, size and a landscape format.
     *
     * @param array $candidate
     * @return bool
     */
    public static function passes_facts(array $candidate): bool {
        $width = (int)($candidate['width'] ?? 0);
        $height = (int)($candidate['height'] ?? 0);
        return image_license_policy::is_reusable((string)($candidate['licensecode'] ?? ''))
            && in_array((string)($candidate['mimetype'] ?? ''), self::MIMETYPES, true)
            && $width >= self::MIN_WIDTH
            && $height > 0
            && $width >= $height;
    }

    /**
     * The model writes what the image should show and the search queries.
     *
     * @param string $placetext
     * @param string $wish
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return array{subject:string, queries:string[], error:string}
     */
    private function plan_search(string $placetext, string $wish, int $threadid, int $contextid, int $userid): array {
        $prompt = "You choose a cover image for an online course from a free image library that is searched by "
            . "English keywords.\n"
            . $placetext . "\n"
            . ($wish !== '' ? "The user asked for: " . $wish . "\n" : '')
            . "\nReturn JSON only:\n"
            . "{\n"
            . "  \"subject\": string,      // one English sentence: what the image should show\n"
            . "  \"queries\": [string]     // three different English search queries of 2 to 4 words, concrete things\n"
            . "                            // a photo can show, most specific first; no names of persons\n"
            . "}";
        $call = (new llm_call_service($this->store))
            ->invoke_for_context($threadid, $contextid, $userid, 'image_search_plan', $prompt);
        if (empty($call['success'])) {
            return ['subject' => '', 'queries' => [], 'error' => (string)($call['errormessage'] ?? 'model call failed')];
        }
        $plan = image_check_service::extract_json((string)($call['rawcontent'] ?? ''));
        $subject = trim((string)($plan['subject'] ?? ''));
        $queries = [];
        foreach ((array)($plan['queries'] ?? []) as $query) {
            $query = trim((string)$query);
            // A query leaves the site: a privacy placeholder in it means the model copied a masked name, so it is
            // not sent. The placeholder format is the anonymizer's own.
            if ($query === '' || strpos($query, 'ANON_') !== false) {
                continue;
            }
            $queries[] = \core_text::substr($query, 0, 80);
        }
        $queries = array_slice(array_values(array_unique($queries)), 0, 3);
        if ($subject === '' || empty($queries)) {
            return ['subject' => '', 'queries' => [], 'error' => 'The model returned no usable search.'];
        }
        return ['subject' => \core_text::substr($subject, 0, 300), 'queries' => $queries, 'error' => ''];
    }
}
