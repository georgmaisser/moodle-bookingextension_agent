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

namespace bookingextension_agent\local\wbagent\services;

/**
 * Value object for explicit planner prompt contracts.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_prompt_contract {
    /** @var array<string,mixed> */
    private array $payload;

    /**
     * Constructor.
     *
     * @param array<string,mixed> $payload
     */
    public function __construct(array $payload = []) {
        $this->payload = $payload;
    }

    /**
     * Convert to normalized array payload.
     *
     * @return array<string,mixed>
     */
    public function to_array(): array {
        return [
            'intent' => trim((string)($this->payload['intent'] ?? '')),
            'anchors' => array_values(array_filter((array)($this->payload['anchors'] ?? []), 'is_string')),
            'minimal_input' => array_values(array_filter((array)($this->payload['minimal_input'] ?? []), 'is_string')),
            'example_input' => is_array($this->payload['example_input'] ?? null) ? (array)$this->payload['example_input'] : [],
            'namespace' => trim((string)($this->payload['namespace'] ?? '')),
            'version' => max(1, (int)($this->payload['version'] ?? 1)),
            'capabilities' => array_values(array_filter((array)($this->payload['capabilities'] ?? []), 'is_string')),
            'context_scopes' => array_values(array_filter((array)($this->payload['context_scopes'] ?? []), 'is_string')),
        ];
    }
}
