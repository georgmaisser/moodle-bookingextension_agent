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
 * Skill: store an image from a free image library as the image of a course.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\preflight_clarification;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\images\course_image_store;
use bookingextension_agent\local\wizard\services\images\image_candidate_cache;
use bookingextension_agent\local\wizard\services\images\image_check_service;
use bookingextension_agent\local\wizard\services\images\image_http_client;
use bookingextension_agent\local\wizard\services\images\image_license_policy;
use bookingextension_agent\local\wizard\services\images\image_search_service;
use bookingextension_agent\local\wizard\services\images\wikimedia_commons_source;
use context;
use context_course;

/**
 * Stores one image of a free image library as the course image, with its credit.
 *
 * The image is named by the reference the image search showed. Only an image that search accepted for this course
 * within the last hour is taken, so every stored image was looked at by the model, wherever the reference came from.
 */
class set_course_image_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** @var string */
    public const SKILL_NAME = 'course.set_course_image';

    /** @var string Override token: the course already has an image and the user confirmed replacing it. */
    public const OVERRIDE_REPLACE = 'replace_course_image';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R1);
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
    public function get_required_native_capabilities(): array {
        return ['moodle/course:update'];
    }

    #[\Override]
    public function describe_proposed_action(array $input): ?array {
        $rows = [
            ['label' => 'Course', 'value' => (string)($input['coursefullname'] ?? '')],
            ['label' => 'Image', 'value' => (string)($input['imagetitle'] ?? '')],
            ['label' => 'Shows', 'value' => (string)($input['imageshows'] ?? '')],
            ['label' => 'Credit', 'value' => (string)($input['imagecredit'] ?? '')],
        ];
        if (!empty($input['replacesimage'])) {
            $rows[] = ['label' => 'Current course image', 'value' => 'is replaced'];
        }
        return [
            'title' => 'Set the course image of "' . (string)($input['coursefullname'] ?? '') . '"',
            'summary' => 'The image is copied into the course and its credit is added at the end of the course summary.',
            'rows' => $rows,
        ];
    }

    /**
     * The situation in which the selector picks this skill.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.set_course_image_request',
                'description' => 'User picks one of the image candidates shown before for the course image '
                    . '("take the second one", "use image 1").',
            ],
        ];
    }

    #[\Override]
    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Store an image of the free image library as the COURSE IMAGE of a Moodle course. Needs the '
                . 'imageref of an image candidate that was shown before; it never searches itself. Copies the image '
                . 'into the course and adds the credit (author, licence) to the course summary.',
            'is' => 'Storing a chosen, already shown library image as the image of a course.',
            'not' => 'Searching or proposing images (course.find_course_images); uploading a file of the user; '
                . 'the image of a booking option.',
            'when' => 'The user picks one of the image candidates shown before ("take the second one", "use image 1") '
                . 'for the course image.',
            'readonly' => false,
            'example_utterances' => [
                'take the second image',
                'use image 1 as the course image',
                'set the first picture as the cover of this course',
                'yes, the third one for the course',
                'use that image for the course Biology',
            ],
            'properties' => [
                'imageref' => [
                    'type' => 'string',
                    'description' => 'Reference of the chosen image exactly as the image search returned it '
                        . '(imageref=...). Never invent one; without a shown candidate run the search first.',
                    'required' => true,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Name or idnumber of a course the user names, as written; a named course is never '
                        . 'the current one. The system resolves it. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id, as returned with the image candidates. Takes precedence over '
                        . 'coursequery. Leave empty for the current course; never guess.',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Explicit override tokens for confirmed exceptions (' . self::OVERRIDE_REPLACE . ').',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['imageref', 'coursequery'],
                'anchor_fields' => ['imageref'],
                'context_scopes' => ['course'],
            ],
        ]);
    }

    #[\Override]
    public function get_example_input(): array {
        return ['imageref' => 'commons:<id>'];
    }

    #[\Override]
    public function check_structure(array $input): array {
        // Two-channel cause: errors = material for the reply to the user, repair = the planner instruction.
        if (wikimedia_commons_source::pageid_of_ref((string)($input['imageref'] ?? '')) <= 0) {
            return [
                'valid' => false,
                'errors' => ['Which image should become the course image? I can first look for fitting images.'],
                'repair' => ['imageref must be a reference returned by course.find_course_images (commons:<id>). '
                    . 'When no candidates were shown yet, call course.find_course_images instead.'],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }
        return ['valid' => true, 'errors' => [], 'repair' => []];
    }

    #[\Override]
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        if (!image_search_service::is_enabled()) {
            return $this->clarify(
                'The image search is switched off on this site. An administrator can switch it on in the settings '
                . 'of the assistant.',
                'IMAGE_SEARCH_DISABLED'
            );
        }
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->clarify('A course image belongs to a course. Please open a course, or name one.', 'IMAGE_NO_COURSE');
        }
        foreach ($this->get_required_native_capabilities() as $capability) {
            if (!has_capability($capability, $coursecontext, $userid)) {
                return $this->clarify(get_string('nopermissions', 'error', $capability), 'NO_NATIVE_CAPABILITY');
            }
        }
        $course = get_course($coursecontext->instanceid);

        // Only an image the model has looked at for this course is stored: the search remembers what it accepted.
        // The preflight calls neither the library nor the model, it has a short time budget.
        $remembered = image_candidate_cache::recall((int)$course->id, (string)($input['imageref'] ?? ''));
        if ($remembered === null) {
            return $this->clarify(
                'That image is not among the images that were checked for the course "'
                . format_string((string)$course->fullname) . '" in the last hour. I can look for images again.',
                'IMAGE_NOT_CHECKED'
            );
        }
        $candidate = $remembered['candidate'];
        $check = $remembered['check'];
        if (
            !image_search_service::passes_facts($candidate)
            || empty($check['checked'])
            || !image_check_service::decide((int)$check['subject_match'], (int)$check['context_fit'], (array)$check['problems'])
        ) {
            return $this->clarify('That image cannot be used as a course image. I can look for other images.', 'IMAGE_NOT_USABLE');
        }

        $replaces = course_image_store::has_image((int)$course->id);
        $overrides = array_map('strval', is_array($input['override'] ?? null) ? $input['override'] : []);
        if ($replaces && !in_array(self::OVERRIDE_REPLACE, $overrides, true)) {
            return $this->invalid([[
                'code' => 'COURSE_IMAGE_REPLACE_CONFIRM_REQUIRED',
                'severity' => 'needs_confirmation',
                'message' => 'The course "' . format_string((string)$course->fullname) . '" already has a course image.',
                'user_question' => 'This course already has an image. Replace it with the new one?',
                'remedy_options' => ['CONFIRM_REPLACE_COURSE_IMAGE', 'KEEP_CURRENT_COURSE_IMAGE'],
            ]]);
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'coursefullname' => format_string((string)$course->fullname),
            'imageref' => (string)$candidate['ref'],
            'imagetitle' => (string)$candidate['title'],
            'imageshows' => (string)$check['shows'],
            'imagecredit' => image_license_policy::credit_text($candidate),
            'replacesimage' => $replaces,
        ]);
    }

    #[\Override]
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $imageref = (string)($preparedinput['imageref'] ?? '');
        if ($courseid <= 0 || $imageref === '') {
            return $this->build_error_result('Missing prepared course or image.');
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->build_error_result('Target course could not be loaded.');
        }
        if (!has_capability('moodle/course:update', context_course::instance($courseid), $userid)) {
            return $this->build_error_result(get_string('nopermissions', 'error', 'moodle/course:update'));
        }
        $http = new image_http_client();
        $candidate = (new wikimedia_commons_source($http))->get_by_ref($imageref);
        if ($candidate === null || !image_search_service::passes_facts($candidate)) {
            return $this->build_error_result('The image could not be loaded from the image library. Nothing was changed.');
        }
        $image = $http->get((string)$candidate['downloadurl'], 60);
        if ($image['code'] !== 200 || $image['body'] === '') {
            return $this->build_error_result('The image could not be downloaded. Nothing was changed.');
        }
        $stored = (new course_image_store())->store($course, $candidate, $image['body'], $userid);
        if (empty($stored['success'])) {
            return $this->build_error_result('The course image was not stored: ' . (string)$stored['error']);
        }
        $coursename = format_string((string)$course->fullname);
        $usermessage = 'The course image of "' . $coursename . '" is set.';
        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $courseid,
            'observation_full' => 'The image "' . (string)$candidate['title'] . '" is now the course image of "'
                . $coursename . '" (id=' . $courseid . ')' . (!empty($stored['replaced']) ? ', replacing the previous image' : '')
                . '. Credit added to the end of the course summary: ' . (string)$stored['credit'] . '. Course page: '
                . (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            // Authors of library images are not site users; the observation names no account.
            'observation_persons' => [],
            'produced_outputs' => ['courseid' => $courseid, 'imageref' => $imageref, 'filename' => (string)$stored['filename']],
        ];
    }

    /**
     * Build an error result.
     *
     * @param string $message
     * @return array
     */
    private function build_error_result(string $message): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
            'observation_persons' => [],
        ];
    }
}
