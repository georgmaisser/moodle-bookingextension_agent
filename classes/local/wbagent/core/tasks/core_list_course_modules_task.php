<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wbagent\core\tasks;

use context_course;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_list_course_modules_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_list_course_modules';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'List modules in a course with availability and cmid.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'coursequery' => ['type' => 'string', 'required' => true, 'description' => 'Course id/name query.'],
                'section' => ['type' => 'integer', 'required' => false, 'description' => 'Optional section number filter.'],
                'includehidden' => ['type' => 'boolean', 'required' => false, 'description' => 'Include hidden modules.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function check_structure(array $input): array {
        $errors = [];
        if (trim((string)($input['coursequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_coursequery_required', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);
        $courseid = $this->resolve_courseid($input);
        if ($courseid <= 0) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_course_not_found', null, $lang), 'resultid' => null];
        }

        $course = get_course($courseid);
        if (!can_access_course($course, $userid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_course_permission_denied', null, $lang), 'resultid' => null];
        }

        $sectionfilter = array_key_exists('section', $input) ? (int)$input['section'] : null;
        $includehidden = !empty($input['includehidden']);
        $modules = [];
        foreach (get_fast_modinfo($course)->get_cms() as $cm) {
            if (!$includehidden && !$cm->uservisible) {
                continue;
            }
            if ($sectionfilter !== null && (int)$cm->sectionnum !== $sectionfilter) {
                continue;
            }
            $modules[] = ['cmid' => (int)$cm->id, 'name' => (string)$cm->name, 'modname' => (string)$cm->modname, 'section' => (int)$cm->sectionnum, 'available' => (bool)$cm->available, 'uservisible' => (bool)$cm->uservisible];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_modules_loaded', count($modules), $lang), 'resultid' => $courseid, 'courseid' => $courseid, 'modules' => $modules, 'count' => count($modules)];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_list_course_modules_request',
            'description' => 'User asks for module list in a course.',
            'examples' => ['List modules in course 5', 'Zeige Aktivitäten in Abschnitt 2', 'Show available modules in Biology 101'],
        ]];
    }
}
