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

namespace bookingextension_agent\local\wbagent\services\activities;

use context_module;
use moodle_url;
use stdClass;

/**
 * Module-neutral creation core: persists a prepared $moduleinfo via add_moduleinfo() in a transaction.
 *
 * Knows nothing about specific module types or the agent's skills — it only turns a validated
 * $moduleinfo into a real course module, with rollback on failure. A dedicated skill (e.g. course.add_quiz)
 * can reuse this exact service for the "create the hull" step (blueprint §8).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_creation_service {
    /**
     * Create the activity from a prepared $moduleinfo.
     *
     * @param stdClass $moduleinfo Prepared, validated module info (see module_form_contract).
     * @param stdClass $course
     * @return array{cmid:int,instance:int,modname:string,name:string,url:string,coursecontextid:int}
     * @throws \Throwable On creation failure (the underlying add_moduleinfo() rolls its DB changes back).
     */
    public function create(stdClass $moduleinfo, stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $transaction = $DB->start_delegated_transaction();
        try {
            $result = add_moduleinfo($moduleinfo, $course);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        $cmid = (int)($result->coursemodule ?? 0);
        $instance = (int)($result->instance ?? 0);
        $modname = (string)($result->modulename ?? $moduleinfo->modulename ?? '');
        $name = (string)($result->name ?? $moduleinfo->name ?? $modname);

        return [
            'cmid' => $cmid,
            'instance' => $instance,
            'modname' => $modname,
            'name' => $name,
            'url' => $this->resolve_activity_url($course, $cmid, $modname),
            'coursecontextid' => (int)\context_course::instance($course->id)->id,
        ];
    }

    /**
     * Resolve a user-facing URL for the freshly created activity.
     *
     * Activities without their own view page (e.g. a label) link back to the course page.
     *
     * @param stdClass $course
     * @param int $cmid
     * @param string $modname
     * @return string
     */
    private function resolve_activity_url(stdClass $course, int $cmid, string $modname): string {
        if ($cmid > 0) {
            try {
                $cm = get_fast_modinfo($course)->get_cm($cmid);
                if ($cm->url instanceof moodle_url) {
                    return $cm->url->out(false);
                }
            } catch (\Throwable $e) {
                // Fall through to the course page.
            }
        }
        return (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
    }
}
