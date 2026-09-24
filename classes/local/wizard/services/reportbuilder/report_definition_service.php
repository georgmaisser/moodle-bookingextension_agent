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

namespace bookingextension_agent\local\wizard\services\reportbuilder;

use core_reportbuilder\datasource;
use core_reportbuilder\local\audiences\base as audience_base;
use core_reportbuilder\local\helpers\report as report_helper;
use core_reportbuilder\local\helpers\schedule as schedule_helper;
use core_reportbuilder\local\models\audience as audience_model;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;
use core_reportbuilder\manager;

/**
 * The definition of one custom report as core stores it: a deterministic snapshot.
 *
 * The snapshot is the truth channel of the report family: the observation after every read and
 * every mutation is what the database holds (columns, conditions with their stored values,
 * filters, audiences, schedules), never what was asked for. Write operations join this class in
 * the authoring work package; reading lives here first so the read skills and the preview share it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_definition_service {
    /** @var report_resolver */
    private report_resolver $resolver;

    /** @var report_source_catalog_service */
    private report_source_catalog_service $catalog;

    /**
     * Constructor.
     *
     * @param report_resolver|null $resolver
     * @param report_source_catalog_service|null $catalog
     */
    public function __construct(?report_resolver $resolver = null, ?report_source_catalog_service $catalog = null) {
        $this->resolver = $resolver ?? new report_resolver();
        $this->catalog = $catalog ?? new report_source_catalog_service();
    }

    /**
     * Full structure of a report as stored.
     *
     * @param report $persistent
     * @param int $userid Acting user (for the can-edit flag).
     * @param bool $withrowcount Also count the rows (runs the report's count query).
     * @return array
     */
    public function snapshot(report $persistent, int $userid, bool $withrowcount = false): array {
        $snapshot = $this->resolver->summarize($persistent, $userid);
        $reportid = (int)$persistent->get('id');

        /** @var datasource $instance */
        $instance = manager::get_report_from_persistent($persistent);

        $snapshot['columns'] = [];
        foreach ($instance->get_active_columns() as $column) {
            $model = $column->get_persistent();
            $aggregation = $column->get_aggregation();
            $snapshot['columns'][] = [
                'id' => (int)$model->get('id'),
                'identifier' => $column->get_unique_identifier(),
                'title' => $column->get_title(),
                'heading' => (string)$model->get('heading'),
                'entity' => $column->get_entity_name(),
                'aggregation' => $aggregation !== null ? $aggregation::get_class_name() : '',
                'order' => (int)$model->get('columnorder'),
                'sortenabled' => (bool)$model->get('sortenabled'),
                'sortdirection' => (int)$model->get('sortdirection') === SORT_DESC ? 'desc' : 'asc',
                'sortorder' => (int)$model->get('sortorder'),
            ];
        }

        $conditionvalues = $instance->get_condition_values();
        $snapshot['conditions'] = [];
        foreach ($instance->get_active_conditions() as $condition) {
            $model = $condition->get_persistent();
            $identifier = $condition->get_unique_identifier();
            $snapshot['conditions'][] = [
                'id' => $model !== null ? (int)$model->get('id') : 0,
                'identifier' => $identifier,
                'title' => $condition->get_header(),
                'entity' => $condition->get_entity_name(),
                'filterclass' => $this->short_class($condition->get_filter_class()),
                'values' => $this->condition_values_for($identifier, $condition->get_filter_class(), $conditionvalues),
            ];
        }

        $snapshot['filters'] = [];
        foreach ($instance->get_active_filters() as $filter) {
            $model = $filter->get_persistent();
            $snapshot['filters'][] = [
                'id' => $model !== null ? (int)$model->get('id') : 0,
                'identifier' => $filter->get_unique_identifier(),
                'title' => $filter->get_header(),
                'entity' => $filter->get_entity_name(),
                'filterclass' => $this->short_class($filter->get_filter_class()),
            ];
        }

        $snapshot['audiences'] = [];
        foreach (audience_model::get_records(['reportid' => $reportid], 'id') as $audiencemodel) {
            $entry = [
                'id' => (int)$audiencemodel->get('id'),
                'type' => $this->short_class((string)$audiencemodel->get('classname')),
                'classname' => (string)$audiencemodel->get('classname'),
                'heading' => (string)$audiencemodel->get('heading'),
                'name' => '',
                'description' => '',
                'available' => false,
            ];
            $audience = audience_base::instance((int)$audiencemodel->get('id'));
            if ($audience !== null) {
                try {
                    $entry['name'] = (string)$audience->get_name();
                    $entry['description'] = (string)$audience->get_description();
                    $entry['available'] = $audience->is_available();
                } catch (\Throwable $e) {
                    $entry['available'] = false;
                }
            }
            $snapshot['audiences'][] = $entry;
        }

        $recurrences = schedule_helper::get_recurrence_options();
        $viewas = schedule_helper::get_viewas_options();
        $snapshot['schedules'] = [];
        foreach (schedule_model::get_records(['reportid' => $reportid], 'id') as $schedule) {
            $audienceids = json_decode((string)$schedule->get('audiences'), true);
            $snapshot['schedules'][] = [
                'id' => (int)$schedule->get('id'),
                'name' => (string)$schedule->get('name'),
                'enabled' => (bool)$schedule->get('enabled'),
                'format' => (string)$schedule->get('format'),
                'recurrence' => (int)$schedule->get('recurrence'),
                'recurrencename' => (string)($recurrences[(int)$schedule->get('recurrence')] ?? ''),
                'userviewas' => (int)$schedule->get('userviewas'),
                'userviewasname' => (string)($viewas[(int)$schedule->get('userviewas')] ?? ''),
                'timescheduled' => (int)$schedule->get('timescheduled'),
                'timenextsend' => (int)$schedule->get('timenextsend'),
                'timelastsent' => (int)$schedule->get('timelastsent'),
                'audiences' => is_array($audienceids) ? array_map('intval', $audienceids) : [],
                'type' => $this->short_class((string)$schedule->get('classname')),
            ];
        }

        $snapshot['rowcount'] = null;
        if ($withrowcount) {
            try {
                $snapshot['rowcount'] = report_helper::get_report_row_count($reportid);
            } catch (\Throwable $e) {
                $snapshot['rowcount'] = null;
            }
        }

        return $snapshot;
    }

    /**
     * Stored condition values of one condition, decoded from core's flat form keys.
     *
     * Core stores conditions as `<identifier>_operator`, `<identifier>_value`, `_from`, `_to`,
     * `_unit`, … in the report's conditiondata. The operator is reported both as the stored
     * integer and as the filter class' constant name.
     *
     * @param string $identifier
     * @param string $filterclass
     * @param array $conditionvalues
     * @return array
     */
    private function condition_values_for(string $identifier, string $filterclass, array $conditionvalues): array {
        $values = [];
        $prefix = $identifier . '_';
        foreach ($conditionvalues as $key => $value) {
            if (strncmp((string)$key, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $suffix = substr((string)$key, strlen($prefix));
            $values[$suffix] = is_scalar($value) || $value === null ? $value : (array)$value;
        }
        if (array_key_exists('operator', $values) && is_numeric($values['operator'])) {
            $values['operator_key'] = $this->catalog->operator_name($filterclass, (int)$values['operator']);
        }
        return $values;
    }

    /**
     * Short class name.
     *
     * @param string $class
     * @return string
     */
    private function short_class(string $class): string {
        $class = ltrim($class, '\\');
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : (string)substr($class, $pos + 1);
    }
}
