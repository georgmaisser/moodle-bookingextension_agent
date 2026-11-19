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
 * Skill: find image candidates for a course in a free image library.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\images\image_candidate_cache;
use bookingextension_agent\local\wizard\services\images\image_candidates_preview;
use bookingextension_agent\local\wizard\services\images\image_check_service;
use bookingextension_agent\local\wizard\services\images\image_license_policy;
use bookingextension_agent\local\wizard\services\images\image_search_service;
use context_course;

/**
 * Searches a free image library for a course image and lets the model check every hit.
 *
 * Read-only: it shows up to three checked candidates with their reference. course.set_course_image stores one.
 */
class find_course_images_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;

    /** @var string */
    public const SKILL_NAME = 'course.find_course_images';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    #[\Override]
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    #[\Override]
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }

    #[\Override]
    public function get_target_context_level(): int {
        return CONTEXT_COURSE;
    }

    #[\Override]
    public function is_read_only(): bool {
        return true;
    }

    /**
     * The situation in which the selector picks this skill.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.find_course_images_request',
                'description' => 'User wants a course image, cover picture or title image for a course, or asks for image '
                    . 'suggestions for it.',
            ],
        ];
    }

    #[\Override]
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Find freely usable IMAGES for a Moodle course in an online image library: proposes up to three '
                . 'photos for the course image, each checked against the course content, with author and licence. '
                . 'Shows candidates only and stores nothing.',
            'is' => 'Searching a free image library for a fitting course image and showing checked candidates.',
            'not' => 'Storing an image (course.set_course_image); generating an image with AI; images for a booking option.',
            'when' => 'The user wants a course image, cover picture or title image for a course, or asks for image '
                . 'suggestions for it.',
            'readonly' => true,
            'example_utterances' => [
                'find a cover image for this course',
                'this course needs a course image',
                'suggest some pictures for the course Biology',
                'look for a fitting title image for my course',
                'add a picture to this course',
                'show me image suggestions with a bee on a flower for this course',
            ],
            'properties' => [
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Name or idnumber of a course the user names, as written; a named course is never '
                        . 'the current one. The system resolves it. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Takes precedence over coursequery. Leave empty '
                        . 'for the current course; never guess.',
                    'required' => false,
                ],
                'wish' => [
                    'type' => 'string',
                    'description' => 'What the image should show, only when the user said it. Leave empty otherwise: '
                        . 'the subject is then taken from the course content.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['coursequery', 'wish'],
                'anchor_fields' => ['coursequery'],
                'context_scopes' => ['course'],
            ],
        ];
    }

    #[\Override]
    public function get_example_input(): array {
        return ['coursequery' => 'Biology'];
    }

    #[\Override]
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    #[\Override]
    public function execute(array $input, int $contextid, int $userid): array {
        if (!image_search_service::is_enabled()) {
            return $this->error_result(
                'The image search is switched off on this site. An administrator can switch it on in the settings '
                . 'of the assistant.',
                'feature_disabled'
            );
        }
        if (!image_search_service::is_available()) {
            return $this->error_result(
                'The image search needs an AI provider that can look at images, and none is configured on this '
                . 'site. No image is proposed without that check.',
                'feature_unavailable'
            );
        }
        $courseid = $this->resolve_readonly_course_context_id($input, $contextid);
        if ($courseid <= 0) {
            return $this->error_result(
                'I could not find a single course matching that name. Please check the course name.',
                'course_not_found'
            );
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->error_result('That course could not be found.', 'course_not_found');
        }
        // The candidates are for the course image, so the search is for those who may change the course.
        if (!has_capability('moodle/course:update', context_course::instance($courseid), $userid)) {
            return $this->error_result(get_string('nopermissions', 'error', 'moodle/course:update'), 'permission_denied');
        }

        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $contextid);
        $threadid = $thread ? (int)$thread->id : 0;
        $service = new image_search_service($store);
        $wish = trim((string)($input['wish'] ?? ''));
        if ($wish !== '' && $threadid > 0) {
            // The executor resolved the placeholders of the input; the model gets them masked again.
            $wish = (string)(new privacy_anonymizer($store))->anonymize_value_for_llm($threadid, $wish);
        }
        $found = $service->find($service->describe_course($course, $threadid), $wish, $threadid, $contextid, $userid);
        if ($found['error'] !== '') {
            return $this->error_result('No image was searched: ' . $found['error'], 'image_search_failed');
        }

        $coursename = format_string((string)$course->fullname);
        $candidates = [];
        $previewcards = [];
        $lines = [];
        $offtopics = 0;
        foreach ($found['accepted'] as $index => $candidate) {
            $number = $index + 1;
            // What was checked for this course is what may be stored for it.
            image_candidate_cache::remember($courseid, $candidate, (array)$candidate['check']);
            $credit = image_license_policy::credit_text($candidate);
            $shows = (string)$candidate['check']['shows'];
            $candidates[] = [
                'number' => $number,
                'imageref' => (string)$candidate['ref'],
                'title' => (string)$candidate['title'],
                'shows' => $shows,
                'credit' => $credit,
                'pageurl' => (string)$candidate['pageurl'],
            ];
            $previewcards[] = [
                'number' => $number,
                'title' => (string)$candidate['title'],
                'shows' => $shows,
                'credit' => $credit,
                'pageurl' => (string)$candidate['pageurl'],
                'thumb' => image_candidates_preview::thumbnail_data_uri((string)$candidate['previewbytes']),
            ];
            $offtopic = (int)$candidate['check']['context_fit'] < image_check_service::MIN_CONTEXT_FIT;
            $offtopics += $offtopic ? 1 : 0;
            $lines[] = 'Image ' . $number . ': shows: ' . $shows . ' Credit: ' . $credit . '.'
                . ($offtopic ? ' Shows what the user asked for, but has no visible relation to the course topic.' : '')
                . ' [imageref=' . (string)$candidate['ref'] . ']';
        }
        $stats = $found['checked'] . ' image(s) were checked by the vision model, ' . $found['rejected']
            . ' rejected' . ($found['unchecked'] > 0 ? ', ' . $found['unchecked'] . ' could not be checked' : '') . '.';
        if (empty($candidates)) {
            $usermessage = 'No fitting image was found for "' . $coursename . '".';
            $observation = 'Image search for the course "' . $coursename . '" (id=' . $courseid . '): '
                . ($found['checked'] + $found['unchecked'] === 0
                    ? 'the image library returned no usable image for this subject. '
                    : 'the image library returned images, but none of them shows the wanted subject well enough. ')
                . $stats . ' Wanted subject: ' . $found['subject'] . ' Nothing was stored. '
                . 'The user can describe what the image should show and search again.';
        } else {
            $usermessage = 'Found ' . count($candidates) . ' image candidate(s) for "' . $coursename . '".';
            $observation = 'Image candidates for the course "' . $coursename . '" (id=' . $courseid . '), shown to '
                . "the user as numbered pictures:\n" . implode("\n", $lines) . "\n" . $stats
                . ' Nothing was stored. The user sees the pictures next to the chat: refer to them by their number '
                . 'and ask which one to use. The imageref is internal, never show it to the user; to store an image '
                . 'call course.set_course_image with courseid=' . $courseid . ' and the imageref of the chosen image.'
                . ($offtopics > 0
                    ? ' The user asked for this subject, so the images are offered although they do not relate to the '
                        . 'course topic: say that in one short sentence and still ask which one to use.'
                    : '');
        }
        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $courseid,
            'imagecandidates' => $previewcards,
            'observation_full' => $observation,
            // Authors of library images are not site users; the observation names no account.
            'observation_persons' => [],
            'produced_outputs' => ['courseid' => $courseid, 'candidates' => $candidates],
        ];
    }

    /**
     * The candidates as numbered pictures.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        return (new image_candidates_preview())->render((array)($resultentry['imagecandidates'] ?? []));
    }

    /**
     * Build an error result.
     *
     * @param string $message
     * @param string $errorclass
     * @return array
     */
    private function error_result(string $message, string $errorclass): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'error_class' => $errorclass,
            'observation_full' => 'The image search could not run: ' . $message,
            'observation_persons' => [],
        ];
    }
}
