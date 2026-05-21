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
 * Builder for full task-catalog embeddings input rows.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * Builds canonical embedding rows from the full prompt catalog.
 */
class embeddings_catalog_builder_service {
    /**
     * Build embedding row payloads from full catalog contracts.
     *
     * @param task_registry $registry
     * @param string $model
     * @param int $dimensions
     * @return array<int,array<string,string>>
     */
    public function build_full_catalog_rows(task_registry $registry, string $model, int $dimensions): array {
        $rows = [];
        $contracts = $registry->get_all_prompt_contracts();

        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $task = trim((string)($contract['task'] ?? ''));
            if ($task === '') {
                continue;
            }

            $intent = trim((string)($contract['intent'] ?? ''));
            $readonly = !empty($contract['readonly']) ? '1' : '0';
            $description = trim((string)($contract['description'] ?? ''));
            $minimalinput = (array)($contract['minimal_input'] ?? []);
            $exampleinput = (array)($contract['example_input'] ?? []);
            $messagetriggers = (array)($contract['message_triggers'] ?? []);
            $contextualpromptpacks = $this->get_contextual_prompt_packs_for_task($registry, $task);

            $canonical = [
                'task' => $task,
                'intent' => $intent,
                'readonly' => $readonly,
                'description' => $description,
                'minimal_input' => $minimalinput,
                'example_input' => $exampleinput,
                'message_triggers' => $messagetriggers,
                'contextual_prompt_packs' => $contextualpromptpacks,
            ];

            $contenthash = $this->compute_content_hash($canonical, $model, $dimensions);
            $embeddinginput = $this->to_embedding_input($canonical);

            $rows[] = [
                'task' => $task,
                'intent' => $intent,
                'readonly' => $readonly,
                'description' => $description,
                'minimal_input_json' => json_encode($minimalinput, JSON_UNESCAPED_UNICODE),
                'example_input_json' => json_encode($exampleinput, JSON_UNESCAPED_UNICODE),
                'message_triggers_json' => json_encode($messagetriggers, JSON_UNESCAPED_UNICODE),
                'embedding_model' => $model,
                'embedding_dimensions' => (string)$dimensions,
                'content_hash' => $contenthash,
                'embedding_json' => '',
                '_embedding_input' => $embeddinginput,
            ];
        }

        return $rows;
    }

    /**
     * Compute stable hash for one row payload.
     *
     * @param array<string,mixed> $canonicalrow
     * @param string $model
     * @param int $dimensions
     * @return string
     */
    public function compute_content_hash(array $canonicalrow, string $model, int $dimensions): string {
        $payload = [
            'model' => $model,
            'dimensions' => $dimensions,
            'row' => $canonicalrow,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Build compact embedding input text.
     *
     * @param array<string,mixed> $canonicalrow
     * @return string
     */
    public function to_embedding_input(array $canonicalrow): string {
        $minimalinput = json_encode($canonicalrow['minimal_input'] ?? [], JSON_UNESCAPED_UNICODE);
        $exampleinput = json_encode($canonicalrow['example_input'] ?? [], JSON_UNESCAPED_UNICODE);
        $messagetriggers = json_encode($canonicalrow['message_triggers'] ?? [], JSON_UNESCAPED_UNICODE);
        $contextualpromptpacks = json_encode($canonicalrow['contextual_prompt_packs'] ?? [], JSON_UNESCAPED_UNICODE);

        return implode("\n", [
            'task: ' . (string)($canonicalrow['task'] ?? ''),
            'intent: ' . (string)($canonicalrow['intent'] ?? ''),
            'readonly: ' . (string)($canonicalrow['readonly'] ?? '0'),
            'description: ' . (string)($canonicalrow['description'] ?? ''),
            'minimal_input: ' . (string)$minimalinput,
            'example_input: ' . (string)$exampleinput,
            'message_triggers: ' . (string)$messagetriggers,
            'contextual_prompt_packs: ' . (string)$contextualpromptpacks,
        ]);
    }

    /**
     * Extract task-specific contextual prompt packs for embedding enrichment.
     *
     * @param task_registry $registry
     * @param string $taskname
     * @return array<int,array<string,mixed>>
     */
    private function get_contextual_prompt_packs_for_task(task_registry $registry, string $taskname): array {
        $task = $registry->get_task($taskname);
        if ($task === null || !method_exists($task, 'get_contextual_prompt_packs')) {
            return [];
        }

        try {
            $packs = (array)$task->get_contextual_prompt_packs();
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter($packs, static fn($pack): bool => is_array($pack)));
    }
}
