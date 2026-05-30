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
 * Queue command mapper.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

/**
 * Maps queue items to normalized runtime command payloads.
 */
class queue_command_mapper {
    /**
     * Build a normalized runtime command from a queue item.
     *
     * @param array<string,mixed> $item
     * @param bool $includeexecutionmetadata
     * @return array<string,mixed>|null
     */
    public static function from_queue_item(array $item, bool $includeexecutionmetadata = false): ?array {
        $task = trim((string)($item['task'] ?? ''));
        if ($task === '') {
            return null;
        }

        $input = is_array($item['prepared_input'] ?? null) && !empty($item['prepared_input'])
            ? (array)$item['prepared_input']
            : (is_array($item['input'] ?? null) ? (array)$item['input'] : []);

        $command = [
            'task' => $task,
            'version' => max(1, (int)($item['version'] ?? 1)),
            'input' => $input,
        ];

        $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
        if (!empty($dependson)) {
            $command['depends_on'] = $dependson;
        }

        if ($includeexecutionmetadata) {
            $guardtoken = trim((string)($item['guard_token'] ?? ''));
            if ($guardtoken !== '') {
                $command['guard_token'] = $guardtoken;
            }
        }

        return $command;
    }

    /**
     * Build normalized runtime commands from queue items.
     *
     * @param array<int,array<string,mixed>> $items
     * @param bool $includeexecutionmetadata
     * @return array<int,array<string,mixed>>
     */
    public static function from_queue_items(array $items, bool $includeexecutionmetadata = false): array {
        $commands = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $command = self::from_queue_item($item, $includeexecutionmetadata);
            if (is_array($command)) {
                $commands[] = $command;
            }
        }

        return $commands;
    }
}