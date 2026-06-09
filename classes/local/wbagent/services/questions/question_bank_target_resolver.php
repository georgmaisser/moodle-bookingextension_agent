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

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\questions;

use cm_info;
use context;
use context_module;
use core_question\local\bank\question_bank_helper;
use moodle_exception;
use stdClass;

/**
 * Resolves the target module question bank for generated questions.
 *
 * In Moodle 5.x question banks are module activities (mod_qbank). Given the ambient context
 * the agent runs in (e.g. a booking module), this resolves the enclosing course and returns
 * that course's default question bank activity, creating it if it does not exist yet
 * (idempotent get-or-create via core's question_bank_helper).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_bank_target_resolver {
    /**
     * Resolve the course question bank module context for an ambient context.
     *
     * @param context $ambient The context the agent is running in.
     * @return array{context:context_module,course:stdClass,cm:cm_info}
     * @throws moodle_exception When the context is not within a course or no bank can be resolved.
     */
    public function resolve_for_context(context $ambient): array {
        $coursecontext = $ambient->get_course_context(false);
        if (!$coursecontext) {
            throw new moodle_exception('error', 'moodle', '', null,
                'Questions can only be generated within a course context.');
        }

        $course = get_course((int)$coursecontext->instanceid);

        $cm = question_bank_helper::get_default_open_instance_system_type($course, true);
        if (!$cm) {
            throw new moodle_exception('error', 'moodle', '', null,
                'Could not resolve or create a question bank for this course.');
        }

        return [
            'context' => context_module::instance($cm->id),
            'course' => $course,
            'cm' => $cm,
        ];
    }
}
