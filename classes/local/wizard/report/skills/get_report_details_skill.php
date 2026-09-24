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

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use core_reportbuilder\local\models\report;

/**
 * Skill report.get_report_details: one existing custom report as it is configured.
 *
 * The observation is the stored definition (columns with aggregation and sorting, conditions
 * with their stored values, filters, audiences, schedules, optional row count) — the truth channel
 * the authoring skills verify against. The side panel shows the live report view.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_report_details_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.get_report_details';

    /**
     * Constructor: read-only (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Show one existing custom report of the Moodle Report Builder as it is configured: source, columns '
                . 'with aggregation and sorting, conditions with their values, filters, audiences (who may see it), schedules '
                . '(when it is sent) and the row count. Read-only.',
            'is' => 'The configuration and visibility of one existing report.',
            'not' => 'Finding a report by name (search_reports); what a source could offer (describe_report_source).',
            'readonly' => true,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_get_report_details',
            'example_utterances' => [
                'what does the completion report contain',
                'which columns and conditions does report 12 have',
                'who can see the booking answers report',
                'when is the weekly report sent and to whom',
                'how many rows does the certificates report currently have',
                'show me the structure of that report',
            ],
            'properties' => [
                'reportid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the report when already known (e.g. from an earlier lookup). Never guess.',
                    'required' => false,
                ],
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name exactly as the user wrote it, when no id is known. The system resolves '
                        . 'it and asks when several reports match.',
                    'required' => false,
                ],
                'include_row_count' => [
                    'type' => 'boolean',
                    'description' => 'Whether to count the rows the report currently returns (default true).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['reportquery', 'reportid'],
                'anchor_fields' => ['reportquery'],
                'context_scopes' => ['system'],
                'required_groups' => [['reportid', 'reportquery']],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['reportquery' => 'Course completions', 'include_row_count' => true];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.get_report_details_request',
                'description' => 'User asks what an existing report contains, who can see it, when and to whom it is sent, '
                    . 'or how many rows it has.',
            ],
        ];
    }

    /**
     * Nothing to ground: the target is the user's own wording or a known id.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        return [];
    }

    /**
     * Shape: an id or a name must be present.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((int)($input['reportid'] ?? 0) <= 0 && trim((string)($input['reportquery'] ?? '')) === '') {
            $errors[] = get_string('agent_report_report_missing', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight (MCP channel): access, then report resolution with candidates on a miss.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!$this->can_use_report_builder($userid)) {
            return $this->permission_denied_preflight($lang);
        }
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_clarification($resolved, $userid, $lang);
        }
        $prepared = $input;
        $prepared['reportid'] = (int)$resolved['report']->get('id');
        return $this->pass($prepared);
    }

    /**
     * Execute.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        if (!$this->can_use_report_builder($userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: no report builder access");
        }

        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_error($resolved, $userid, $lang, $debugbase . "\nUnresolved: " . $resolved['reason']);
        }
        /** @var report $persistent */
        $persistent = $resolved['report'];

        $withrows = !array_key_exists('include_row_count', $input) || !empty($input['include_row_count']);
        $snapshot = (new report_definition_service($this->resolver(), $this->catalog()))->snapshot($persistent, $userid, $withrows);

        $usermessage = $this->localized_string('agent_report_details_summary', (object)[
            'name' => $snapshot['name'],
            'columns' => count($snapshot['columns']),
            'conditions' => count($snapshot['conditions']),
            'filters' => count($snapshot['filters']),
            'audiences' => count($snapshot['audiences']),
            'schedules' => count($snapshot['schedules']),
        ], $lang);

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$snapshot['id'],
            'report' => $snapshot,
            'observation_full' => $this->build_observation($snapshot, $usermessage),
            'debugmessage' => $debugbase . "\nReport: " . $snapshot['id'],
        ];
    }

    /**
     * Live report view with a structure header.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $snapshot = (array)($resultentry['report'] ?? []);
        $reportid = (int)($snapshot['id'] ?? 0);
        if ($reportid <= 0) {
            return null;
        }
        $persistent = report::get_record(['id' => $reportid]);
        if (!$persistent) {
            return null;
        }
        return (new report_preview_renderer())->build($persistent, $userid, $snapshot);
    }

    /**
     * Deterministic observation lines from the snapshot.
     *
     * @param array $snapshot
     * @param string $usermessage
     * @return string
     */
    private function build_observation(array $snapshot, string $usermessage): string {
        $lines = [$usermessage . ' [id ' . $snapshot['id'] . ']'];
        $lines[] = 'source: ' . $snapshot['sourcename'] . ' [' . $snapshot['source'] . '] | plugin: ' . $snapshot['component']
            . ' | unique rows: ' . ($snapshot['uniquerows'] ? 'yes' : 'no')
            . ' | can edit: ' . ($snapshot['canedit'] ? 'yes' : 'no')
            . ' | created by me: ' . ($snapshot['created_by_me'] ? 'yes' : 'no')
            . ' | ' . $snapshot['url'];
        if ($snapshot['rowcount'] !== null) {
            $lines[] = 'rows: ' . (int)$snapshot['rowcount'];
        }

        $lines[] = 'COLUMNS (' . count($snapshot['columns']) . '):';
        foreach ($snapshot['columns'] as $column) {
            $line = '- ' . $column['identifier'] . ' | ' . ($column['heading'] !== '' ? $column['heading'] : $column['title']);
            if ($column['aggregation'] !== '') {
                $line .= ' | aggregation: ' . $column['aggregation'];
            }
            if ($column['sortenabled']) {
                $line .= ' | sort: ' . $column['sortdirection'] . ' (' . $column['sortorder'] . ')';
            }
            $lines[] = $line;
        }

        $lines[] = 'CONDITIONS (' . count($snapshot['conditions']) . '):';
        foreach ($snapshot['conditions'] as $condition) {
            $values = [];
            foreach ((array)$condition['values'] as $key => $value) {
                $values[] = $key . '=' . (is_array($value) ? json_encode($value) : (string)$value);
            }
            $lines[] = '- ' . $condition['identifier'] . ' | ' . $condition['title'] . ' | ' . $condition['filterclass']
                . (empty($values) ? ' | no value set' : ' | ' . implode(', ', $values));
        }

        $lines[] = 'FILTERS (' . count($snapshot['filters']) . '):';
        foreach ($snapshot['filters'] as $filter) {
            $lines[] = '- ' . $filter['identifier'] . ' | ' . $filter['title'] . ' | ' . $filter['filterclass'];
        }

        $lines[] = 'AUDIENCES (' . count($snapshot['audiences']) . '):';
        foreach ($snapshot['audiences'] as $audience) {
            $lines[] = '- id ' . $audience['id'] . ' | ' . $audience['type'] . ' | ' . $audience['name']
                . ($audience['description'] !== '' ? ' | ' . $audience['description'] : '')
                . ($audience['available'] ? '' : ' | unavailable');
        }

        $lines[] = 'SCHEDULES (' . count($snapshot['schedules']) . '):';
        foreach ($snapshot['schedules'] as $schedule) {
            $lines[] = '- id ' . $schedule['id'] . ' | ' . $schedule['name']
                . ' | ' . ($schedule['enabled'] ? 'enabled' : 'disabled')
                . ' | format: ' . $schedule['format']
                . ' | recurrence: ' . $schedule['recurrencename']
                . ' | view as: ' . $schedule['userviewasname']
                . ' | next: ' . ($schedule['timenextsend'] > 0 ? userdate($schedule['timenextsend']) : '-')
                . ' | last: ' . ($schedule['timelastsent'] > 0 ? userdate($schedule['timelastsent']) : '-')
                . ' | audiences: ' . (empty($schedule['audiences']) ? '-' : implode(',', $schedule['audiences']));
        }

        return implode("\n", $lines);
    }
}
