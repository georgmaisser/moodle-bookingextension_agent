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

namespace bookingextension_agent\local\wizard\report\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\services\reportbuilder\report_cards_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use context_system;
use core_reportbuilder\permission;

/**
 * Shared base of the report.* family (Moodle Report Builder skills).
 *
 * Custom reports live in the system context, so every skill of the family operates there. Access
 * is decided exactly like core does it (core_reportbuilder\permission: custom reports enabled and
 * any of the reportbuilder capabilities), for the acting user, never as admin. Read-only skills
 * receive the raw planner input in the chat channel (no preflight there), so the same checks run
 * in run_preflight() and in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class report_skill_base extends core_skill_base {
    /** Issue code: the acting user may not use the Report Builder at all. */
    public const CODE_PERMISSION_DENIED = 'REPORT_PERMISSION_DENIED';

    /** Issue code: the named report source does not exist on this site. */
    public const CODE_SOURCE_VALIDATION_ERROR = 'REPORT_SOURCE_VALIDATION_ERROR';

    /** Issue code: no source named although one is needed. */
    public const CODE_MISSING_SOURCE = 'MISSING_REPORT_SOURCE';

    /** Preview type of the source list (sources A and C). */
    public const PREVIEW_TYPE_SOURCES = 'report_sources';

    /** @var report_source_catalog_service|null Lazily created catalog. */
    private ?report_source_catalog_service $catalog = null;

    /**
     * Custom reports are a system-context feature.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * The catalog service (one per skill instance; the service caches per request).
     *
     * @return report_source_catalog_service
     */
    protected function catalog(): report_source_catalog_service {
        if ($this->catalog === null) {
            $this->catalog = new report_source_catalog_service();
        }
        return $this->catalog;
    }

    /**
     * Whether the acting user may author custom reports: the Report Builder is enabled and the user
     * holds an editing capability in the system context.
     *
     * Deliberately the authoring rule, not core's viewing rule: moodle/reportbuilder:view is granted
     * to every authenticated user (reports shared via audiences), while the sources, columns and
     * operators a datasource offers only matter to someone who may build or change a report. Unlike
     * permission::can_create_report() this ignores the site's report limit, which must not hide the
     * catalog.
     *
     * @param int $userid
     * @return bool
     */
    protected function can_author_reports(int $userid): bool {
        global $CFG;
        return !empty($CFG->enablecustomreports) && has_any_capability(
            ['moodle/reportbuilder:edit', 'moodle/reportbuilder:editall'],
            context_system::instance(),
            $userid
        );
    }

    /**
     * Whether the acting user may view custom reports at all (core's own rule).
     *
     * @param int $userid
     * @return bool
     */
    protected function can_use_report_builder(int $userid): bool {
        return permission::can_view_reports_list($userid, context_system::instance());
    }

    /**
     * Preflight issue for a user who may not use the Report Builder.
     *
     * Not user-fixable, but not a technical error either: it ends the turn as a clarification in
     * the user's language (an admin has to grant the permission), never as an `error`.
     *
     * @param string $lang
     * @return array
     */
    protected function permission_denied_preflight(string $lang): array {
        return $this->invalid([[
            'code' => self::CODE_PERMISSION_DENIED,
            'severity' => 'needs_clarification',
            'message' => $this->localized_string('agent_report_no_access', null, $lang),
        ]]);
    }

    /**
     * Honest error result for the chat read-only path (no preflight) when the user lacks access.
     *
     * @param string $lang
     * @param string $debugmessage
     * @return array
     */
    protected function permission_denied_result(string $lang, string $debugmessage): array {
        $message = $this->localized_string('agent_report_no_access', null, $lang);
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
            'debugmessage' => $debugmessage,
        ];
    }

    /**
     * Clarification asking which report source is meant, with the candidate list as options and
     * as a side-panel card list (preview source C).
     *
     * @param string $lang
     * @param string $reference What the planner sent ('' when nothing).
     * @return array
     */
    protected function source_clarification(string $lang, string $reference): array {
        $options = $this->catalog()->source_options();
        $message = $reference === ''
            ? $this->localized_string('agent_report_source_missing', null, $lang)
            : $this->localized_string('agent_report_source_not_found', $reference, $lang);
        $issue = [
            'code' => $reference === '' ? self::CODE_MISSING_SOURCE : self::CODE_SOURCE_VALIDATION_ERROR,
            'severity' => 'needs_clarification',
            'message' => $message,
            'options' => $options,
        ];
        $html = (new report_cards_renderer($lang))->render_sources($this->catalog()->list_sources(false));
        if ($html !== '') {
            $issue['preview'] = ['type' => self::PREVIEW_TYPE_SOURCES, 'html' => $html];
        }
        return $this->invalid([$issue]);
    }

    /**
     * Construction grounding shared by the skills that take a `source`: the sources that exist here.
     *
     * Runs after the skill is chosen, so the constructor can copy an exact source value instead of
     * guessing one. Bounded so the prompt stays affordable on sites with many plugins.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        try {
            $sources = $this->catalog()->list_sources(false);
        } catch (\Throwable $e) {
            return [];
        }
        if (empty($sources)) {
            return [];
        }
        $pairs = [];
        foreach (array_slice($sources, 0, 40) as $source) {
            $pairs[] = $source['name'] . ' → ' . $source['source'];
        }
        return [
            'guidance' => [
                '- The source field takes one of these EXACT source identifiers (shown as "name → identifier"): '
                    . implode('; ', $pairs) . '. Copy the identifier verbatim; never invent one.',
            ],
            'example_parameters' => ['source' => $sources[0]['source']],
        ];
    }
}
