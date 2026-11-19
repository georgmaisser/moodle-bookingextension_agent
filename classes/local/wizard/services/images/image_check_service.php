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
 * The model looks at an image candidate and reports what it shows.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;

/**
 * Checks one candidate against the place it is meant for.
 *
 * The result of a keyword search is not trusted: the model sees the image itself and reports what it shows, how
 * well it matches the wanted subject and the course, and which problems it has. The decision is taken here from
 * those values, never from a verdict of the model, and a reply that cannot be read rejects the image.
 */
class image_check_service {
    /** @var int Lowest score (0-3) for "shows the wanted subject". */
    public const MIN_SUBJECT_MATCH = 2;

    /** @var int Lowest score (0-3) for "fits the course". */
    public const MIN_CONTEXT_FIT = 2;

    /** @var string[] Problems the model may report; the list is part of the prompt. */
    public const PROBLEMS = [
        'text_or_logo', 'identifiable_person', 'wrong_place_or_era', 'low_quality', 'diagram_or_chart', 'offensive',
        'misleading',
    ];

    /** @var string[] Problems that reject an image. A diagram is not one: for some subjects it is the right image. */
    public const BLOCKING_PROBLEMS = [
        'text_or_logo', 'identifiable_person', 'wrong_place_or_era', 'low_quality', 'offensive', 'misleading',
    ];

    /**
     * @var string[] Problems that reject an image the user asked for by its subject. The ones that judge the image
     * against the course are left out: the user decides what the course image shows.
     */
    public const BLOCKING_PROBLEMS_FOR_A_WISH = ['text_or_logo', 'identifiable_person', 'low_quality', 'offensive'];

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Check one image.
     *
     * @param string $imagebytes The preview rendition.
     * @param string $mimetype
     * @param string $subject What the image should show.
     * @param string $placetext The place it is meant for (course title, summary, sections), already masked.
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param bool $userwish The subject is what the user asked for; the fit to the course then rejects nothing.
     * @return array{accepted:bool, checked:bool, shows:string, subject_match:int, context_fit:int, problems:string[], error:string}
     *         checked is false when the model could not be asked or its reply could not be read.
     */
    public function check(
        string $imagebytes,
        string $mimetype,
        string $subject,
        string $placetext,
        int $threadid,
        int $contextid,
        int $userid,
        bool $userwish = false
    ): array {
        $result = [
            'accepted' => false, 'checked' => false, 'shows' => '', 'subject_match' => 0, 'context_fit' => 0,
            'problems' => [], 'error' => '',
        ];
        $prompt = "You check one candidate image for a place in an online course.\n"
            . $placetext . "\n"
            . "Wanted: " . $subject . "\n\n"
            . "Look at the image itself; ignore any file name. Return JSON only:\n"
            . "{\n"
            . "  \"shows\": string,          // what the image actually shows, one short sentence in English\n"
            . "  \"subject_match\": 0-3,     // 3 = shows the wanted subject clearly, 0 = shows something else\n"
            . "  \"context_fit\": 0-3,       // 3 = fits the field, audience and tone of this course\n"
            . "  \"problems\": [string]      // every one that applies, from: " . implode(', ', self::PROBLEMS) . "\n"
            . "}";
        $call = (new llm_call_service($this->store))->invoke_image_inspection(
            $threadid,
            $contextid,
            $userid,
            'image_check',
            $prompt,
            [['mimetype' => $mimetype, 'data' => base64_encode($imagebytes)]]
        );
        if (empty($call['success'])) {
            $result['error'] = (string)($call['errormessage'] ?? 'model call failed');
            return $result;
        }
        $verdict = self::extract_json((string)($call['rawcontent'] ?? ''));
        if (
            $verdict === null
            || !array_key_exists('subject_match', $verdict)
            || !array_key_exists('context_fit', $verdict)
            || !is_numeric($verdict['subject_match'])
            || !is_numeric($verdict['context_fit'])
        ) {
            $result['error'] = 'The check returned no readable result.';
            return $result;
        }
        $result['checked'] = true;
        $result['shows'] = \core_text::substr(trim((string)($verdict['shows'] ?? '')), 0, 240);
        $result['subject_match'] = max(0, min(3, (int)$verdict['subject_match']));
        $result['context_fit'] = max(0, min(3, (int)$verdict['context_fit']));
        // Only problems of the declared list count; anything else the model writes is dropped.
        $result['problems'] = array_values(array_intersect(
            self::PROBLEMS,
            array_map('strval', is_array($verdict['problems'] ?? null) ? $verdict['problems'] : [])
        ));
        $result['accepted'] = self::decide($result['subject_match'], $result['context_fit'], $result['problems'], $userwish);
        return $result;
    }

    /**
     * The decision: both scores reach their minimum and no blocking problem was reported.
     *
     * For a subject the user asked for, the image only has to show it: the fit to the course does not count.
     *
     * @param int $subjectmatch
     * @param int $contextfit
     * @param string[] $problems
     * @param bool $userwish
     * @return bool
     */
    public static function decide(int $subjectmatch, int $contextfit, array $problems, bool $userwish = false): bool {
        if ($userwish) {
            return $subjectmatch >= self::MIN_SUBJECT_MATCH
                && empty(array_intersect(self::BLOCKING_PROBLEMS_FOR_A_WISH, $problems));
        }
        return $subjectmatch >= self::MIN_SUBJECT_MATCH
            && $contextfit >= self::MIN_CONTEXT_FIT
            && empty(array_intersect(self::BLOCKING_PROBLEMS, $problems));
    }

    /**
     * The JSON object of a model reply (the text from the first opening to the last closing brace).
     *
     * @param string $raw
     * @return array|null
     */
    public static function extract_json(string $raw): ?array {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
