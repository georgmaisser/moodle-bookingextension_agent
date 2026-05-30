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

namespace bookingextension_agent\local\wbagent\core\tasks;

use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for core.search_courses.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses_task extends core_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'core.search_courses';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Search courses and return matching course candidates '
                . 'including courseid, shortname, fullname, course URL, and active '
                . 'enrolment count. Use this first when a follow-up task needs a '
                . 'concrete course identity or link.',
            'readonly' => $this->is_read_only(),
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_courses',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text for course full name, short name or id.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
                ],
                'coursename' => [
                    'type' => 'string',
                    'description' => 'Alias for query when only a course name is provided.',
                    'required' => false,
                ],
                'course' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
                ],
                'searchterm' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of courses to return (default 10).',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.search_courses_request',
                'description' => 'User asks to find/search courses by name, shortname or id.',
            ],
            [
                'id' => 'core.search_courses_limit_request',
                'description' => 'User asks for a limited number of returned courses.',
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'core.search_courses',
                'triggers' => [
                    'search courses', 'find course', 'find courses', 'course id',
                    'suche kurs', 'suche kurse', 'finde kurs', 'kurs finden',
                ],
                'guidance' => [
                    '- Use core.search_courses as a FIRST STEP when you need a courseid to pass to',
                    '  a follow-up task and only a course name is known.',
                    '- Execute this task and wait for the observation; then use the resolved courseid.',
                    '- This task already returns the course URL, so do not ask the model to invent or compose',
                    '  a Moodle course link itself.',
                    '- This task also returns the active enrolment count, so use it before asking a second task',
                    '  only to learn how many active users are currently enrolled in the course.',
                    '- Use input.query for the search term and optionally input.limit to cap results.',
                    '- If multiple courses match, ask the user to clarify before continuing.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ($this->resolve_query($input) === '') {
            $errors[] = get_string('agent_booking_search_courses_query_required', 'bookingextension_agent');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $query = $this->resolve_query($input);
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_search_courses_query_required', 'bookingextension_agent'),
                'resultid' => null,
            ];
        }

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $courses = $this->search_course_candidates_for_preview($query, $limit);
        if (empty($courses)) {
            $usermessage = $this->localized_string('agent_booking_search_courses_no_results', null, $outputlang);
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'courses' => [],
                'observation_full' => $this->build_course_observation_full([], $outputlang),
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $usermessage = $this->localized_string(
            'agent_booking_search_courses_found',
            count($courses),
            $outputlang
        );

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)($courses[0]['courseid'] ?? 0),
            'courses' => $courses,
            'observation_full' => $this->build_course_observation_full($courses, $outputlang),
            'debugmessage' => $debugbase
                . "\nResults: " . count($courses),
        ];
    }

    /**
     * Build a user-facing course observation string.
     *
     * @param array<int,array<string,mixed>> $courses
     * @param string $lang
     * @return string
     */
    private function build_course_observation_full(array $courses, string $lang): string {
        if (empty($courses)) {
            return $this->localized_string('agent_booking_search_courses_no_results', null, $lang);
        }

        $lines = [];
        $lines[] = $this->localized_string('agent_booking_search_courses_found', count($courses), $lang);

        foreach ($courses as $course) {
            $fullname = trim((string)($course['fullname'] ?? ''));
            $shortname = trim((string)($course['shortname'] ?? ''));
            $courseurl = trim((string)($course['courseurl'] ?? ''));
            $activecount = (int)($course['activeenrolledcount'] ?? 0);

            $label = $fullname !== '' ? $fullname : $shortname;
            if ($label === '') {
                $label = (string)($course['courseid'] ?? '');
            }

            $line = '- ' . $label;
            if ($shortname !== '' && $shortname !== $label) {
                $line .= ' (' . $shortname . ')';
            }
            if ($courseurl !== '') {
                $line .= ': ' . $courseurl;
            }
            $line .= ' | active enrolled: ' . $activecount;

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the course search query from canonical and legacy alias fields.
     *
     * @param array<string,mixed> $input
     * @return string
     */
    private function resolve_query(array $input): string {
        $keys = ['query', 'coursequery', 'coursename', 'course', 'searchterm'];
        foreach ($keys as $key) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                continue;
            }

            $value = trim((string)$input[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
