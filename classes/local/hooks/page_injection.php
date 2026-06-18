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

namespace bookingextension_agent\local\hooks;

use core\hook\output\before_standard_head_html_generation;

/**
 * Injects the navbar magic-wand entry point on every Moodle page.
 *
 * Gated by the inject_in_navbar admin setting (default off), a logged-in
 * non-guest user and the agent use-capability checked at the current page
 * context (NOT the system context — teachers usually hold the capability via
 * course/module roles only). No CSS is injected here: the plugin's styles.css
 * is already aggregated into the theme stylesheet on all pages. The
 * authoritative permission checks happen server-side on every agent call.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_injection {
    /**
     * Add the magic-wand bootstrap JS to the page.
     *
     * @param before_standard_head_html_generation $hook
     */
    public static function extend_head(before_standard_head_html_generation $hook): void {
        global $PAGE;

        if (empty(get_config('bookingextension_agent', 'inject_in_navbar'))) {
            return;
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        try {
            // Layouts without a navbar (or where an overlay would interfere).
            if (in_array($PAGE->pagelayout, ['embedded', 'popup', 'frametop', 'maintenance', 'print', 'redirect'], true)) {
                return;
            }

            $context = $PAGE->context;
            if (!has_capability('bookingextension/agent:useaiinstructions', $context)) {
                return;
            }

            // Keep the per-page footprint minimal: only this tiny AMD module is
            // loaded; the label travels along so the JS needs no string AJAX.
            // Modal/templates/fragment load lazily on first click.
            $PAGE->requires->js_call_amd('bookingextension_agent/navbar_magic_wand', 'init', [
                (int)$context->id,
                get_string('agent_display_name', 'bookingextension_agent'),
            ]);
        } catch (\Throwable $e) {
            // Never break page rendering for a convenience entry point
            // (e.g. $PAGE->context not initialised during install/upgrade).
            debugging('bookingextension_agent navbar injection skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
