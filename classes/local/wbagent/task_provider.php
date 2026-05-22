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

namespace bookingextension_agent\local\wbagent;

require_once(__DIR__ . '/summarizer/single_object_result_summary_contributor.php');

use bookingextension_agent\local\wbagent\interfaces\result_summary_provider_interface;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_provider_interface;
use bookingextension_agent\local\wbagent\summarizer\basic_collection_result_summary_contributor;
use bookingextension_agent\local\wbagent\summarizer\diagnosis_result_summary_contributor;
use bookingextension_agent\local\wbagent\summarizer\docs_result_summary_contributor;
use bookingextension_agent\local\wbagent\summarizer\single_object_result_summary_contributor;

/**
 * bookingextension_agent task provider entrypoint.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_provider implements result_summary_provider_interface, task_provider_interface {
    /**
     * Return the component name.
     *
     * @return string
     */
    public function get_component(): string {
        return 'bookingextension/agent';
    }

    /**
     * Return concrete task instances.
     *
     * @return array
     */
    public function get_tasks(): array {
        $tasks = array_values(task_discovery::get_task_instances('bookingextension_agent'));

        usort($tasks, static fn(task_interface $a, task_interface $b): int => strcmp($a->get_name(), $b->get_name()));
        return $tasks;
    }

    /**
     * Return contextual prompt packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->get_tasks() as $task) {
            if (!method_exists($task, 'get_contextual_prompt_packs')) {
                continue;
            }

            $taskpacks = (array)$task->get_contextual_prompt_packs();
            foreach ($taskpacks as $pack) {
                if (!is_array($pack)) {
                    continue;
                }
                $id = (string)($pack['id'] ?? '');
                if ($id === '' || isset($seenids[$id])) {
                    continue;
                }
                $seenids[$id] = true;
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Return optional issue code provider for domain-specific business logic codes.
     *
     * @return \bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface|null
     */
    public function get_issue_code_provider(): ?\bookingextension_agent\local\wbagent\interfaces\issue_code_provider_interface {
        try {
            return new booking_issue_code_provider();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return optional prompt guidance (domain-specific LLM instructions).
     *
     * @return array<string,mixed>
     */
    public function get_prompt_guidance(): array {
        // For now, no custom prompt guidance beyond what orchestrator provides.
        // Plugins can override to inject domain-specific instructions.
        return [];
    }

    /**
     * Return result summary contributors for this component.
     *
     * @return array<int,\bookingextension_agent\local\wbagent\interfaces\summarizer\result_summary_contributor_interface>
     */
    public function get_result_summary_contributors(): array {
        return [
            new basic_collection_result_summary_contributor(),
            new single_object_result_summary_contributor(),
            new docs_result_summary_contributor(),
            new diagnosis_result_summary_contributor(),
        ];
    }
}
