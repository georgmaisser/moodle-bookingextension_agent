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
 * Adhoc task to rebuild task-catalog embeddings CSV.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use bookingextension_agent\local\wbagent\services\embeddings\family_embeddings_index_service;
use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Rebuilds embeddings for the full task catalog.
 */
class rebuild_task_catalog_embeddings_adhoc extends \core\task\adhoc_task {
    /**
     * Execute task.
     *
     * @return void
     */
    public function execute(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            return;
        }

        $customdata = (array)$this->get_custom_data();
        $registry = task_registry_factory::get_default();
        $service = new family_embeddings_index_service();
        $summary = $service->rebuild_catalog(
            $registry,
            isset($customdata['model']) ? (string)$customdata['model'] : null,
            isset($customdata['dimensions']) ? (int)$customdata['dimensions'] : null,
            !empty($customdata['force'])
        );

        mtrace('bookingextension_agent embeddings rebuild status: ' . (string)($summary['status'] ?? 'unknown'));
        mtrace('bookingextension_agent embeddings rebuild: generated=' . (int)($summary['embedded'] ?? 0)
            . ', reused=' . (int)($summary['reused'] ?? 0)
            . ', deleted=' . (int)($summary['deleted'] ?? 0)
            . ', written=' . (int)($summary['written'] ?? 0));

        $taskstates = (array)($summary['taskstates'] ?? []);
        if (!empty($taskstates)) {
            $statecounts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'untouched' => 0];
            foreach ($taskstates as $state) {
                if (isset($statecounts[$state])) {
                    $statecounts[$state]++;
                }
            }
            mtrace('bookingextension_agent embeddings rebuild states summary: '
                . 'created=' . $statecounts['created']
                . ', updated=' . $statecounts['updated']
                . ', deleted=' . $statecounts['deleted']
                . ', untouched=' . $statecounts['untouched']);
            ksort($taskstates);
            mtrace('bookingextension_agent embeddings rebuild task states:');
            foreach ($taskstates as $taskname => $state) {
                mtrace(' - ' . $state . ' ' . $taskname);
            }
        }
    }
}
