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
 * Adhoc task to rebuild documentation chunk embeddings CSV.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\task;

use bookingextension_agent\local\wbagent\services\lookup\docs_embeddings_index_service;

/**
 * Rebuilds embeddings for the registered documentation corpora.
 */
class rebuild_docs_embeddings_adhoc extends \core\task\adhoc_task {
    /**
     * Execute task.
     *
     * @return void
     */
    public function execute(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            mtrace('bookingextension_agent docs embeddings rebuild: skipped (embeddings provider unavailable)');
            return;
        }

        $customdata = (array)$this->get_custom_data();
        $service = new docs_embeddings_index_service();
        $summary = $service->rebuild(
            isset($customdata['model']) ? (string)$customdata['model'] : null,
            isset($customdata['dimensions']) ? (int)$customdata['dimensions'] : null,
            !empty($customdata['force'])
        );

        mtrace('bookingextension_agent docs embeddings rebuild status: ' . (string)($summary['status'] ?? 'unknown'));
        mtrace('bookingextension_agent docs embeddings rebuild:'
            . ' embedded=' . (int)($summary['embedded'] ?? 0)
            . ', reused=' . (int)($summary['reused'] ?? 0)
            . ', deleted=' . (int)($summary['deleted'] ?? 0)
            . ', written=' . (int)($summary['written'] ?? 0));
    }
}
