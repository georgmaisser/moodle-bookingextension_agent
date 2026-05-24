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

use context_module;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

class core_get_module_details_task extends core_task_base implements task_trigger_provider_interface {
    public const TASK_NAME = 'booking.core_get_module_details';

    public function __construct() {
        parent::__construct(true);
    }
    public function get_name(): string {
        return self::TASK_NAME;
    }

    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Get metadata details for one course module via cmid or modulequery.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'cmid' => ['type' => 'integer', 'required' => false, 'description' => 'Course module id.'],
                'coursequery' => ['type' => 'string', 'required' => false, 'description' => 'Course query for modulequery lookup.'],
                'modulequery' => ['type' => 'string', 'required' => false, 'description' => 'Module name query.'],
                'outputlang' => ['type' => 'string', 'required' => false, 'description' => 'Optional language code.'],
            ],
        ]);
    }

    public function validate(array $input, int $cmid): array {
        $errors = [];
        if (empty($input['cmid']) && trim((string)($input['modulequery'] ?? '')) === '') {
            $errors[] = get_string('agent_booking_core_module_reference_required', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    public function execute(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);
        $cm = null;

        if (!empty($input['cmid'])) {
            $cm = get_coursemodule_from_id('', (int)$input['cmid'], 0, false, IGNORE_MISSING);
        } else {
            $courseid = $this->resolve_courseid($input);
            $modulequery = trim((string)($input['modulequery'] ?? ''));
            if ($courseid > 0 && $modulequery !== '') {
                $modinfo = get_fast_modinfo($courseid);
                $matches = [];
                foreach ($modinfo->get_cms() as $candidate) {
                    if (stripos((string)$candidate->name, $modulequery) !== false) {
                        $matches[] = $candidate;
                    }
                }
                if (count($matches) === 1) {
                    $cm = $matches[0];
                } else if (count($matches) > 1) {
                    return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_module_ambiguous', null, $lang), 'resultid' => null];
                }
            }
        }

        if (!$cm) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_module_not_found', null, $lang), 'resultid' => null];
        }

        $context = context_module::instance((int)$cm->id);
        if (!can_access_course(get_course((int)$cm->course), $userid)) {
            return ['status' => 'error', 'detail' => $this->localized_string('agent_booking_core_module_permission_denied', null, $lang), 'resultid' => null];
        }

        return ['status' => 'executed', 'detail' => $this->localized_string('agent_booking_core_module_loaded', null, $lang), 'resultid' => (int)$cm->id, 'module' => ['cmid' => (int)$cm->id, 'courseid' => (int)$cm->course, 'modname' => (string)$cm->modname, 'name' => (string)$cm->name, 'section' => (int)$cm->sectionnum, 'available' => (bool)$cm->available, 'uservisible' => (bool)$cm->uservisible, 'url' => (string)($cm->url ?? '')]];
    }

    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.core_get_module_details_request',
            'description' => 'User asks for metadata of one module/activity.',
            'examples' => ['Get details for cmid 42', 'Zeige Details zu Aktivität Quiz 1', 'Module details for Assignment 3'],
        ]];
    }
}
