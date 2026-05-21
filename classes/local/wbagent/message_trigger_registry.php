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
 * Message trigger catalog for robust LLM-side classification.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use core_text;

/**
 * Builds and validates the trigger catalog shared between prompt, interpreter and runtime flow.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_trigger_registry {
    /** Marker for unknown response_type values coming from model output. */
    public const UNKNOWN_RESPONSE_TYPE = 'UNKNOWN_TYPE';

    /** Allowed response_type values in runtime/decision routing. */
    private const KNOWN_RESPONSE_TYPES = [
        'task_call',
        'confirmation_request',
        'confirm_pending',
        'clarification',
        'sufficient',
        'error',
        'execution_result',
    ];

    /** @var task_registry */
    private task_registry $taskregistry;

    /** Core flow triggers understood by the server runtime. */
    private const CORE_TRIGGERS = [
        [
            'id' => 'core.is_confirmation_message',
            'description' => 'Latest user message confirms or approves execution of the pending confirmation request.',
        ],
        [
            'id' => 'core.discard_pending_confirmation',
            'description' => 'Latest user message explicitly asks to discard/cancel '
                . 'the currently pending confirmation intent before continuing.',
        ],
        [
            'id' => 'core.is_lookup_request',
            'description' => 'Latest user message asks to list/search/lookup information (read-only intent).',
        ],
        [
            'id' => 'core.is_preview_request',
            'description' => 'Latest user message asks to preview/show the latest worked booking option.',
        ],
        [
            'id' => 'core.force_new_duplicate_option',
            'description' => 'Latest user message explicitly asks to create a new option despite duplicate-title warning.',
        ],
    ];

    /**
     * Constructor.
     *
     * @param task_registry $taskregistry
     */
    public function __construct(task_registry $taskregistry) {
        $this->taskregistry = $taskregistry;
    }

    /**
     * Return all available message trigger definitions.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_available_triggers(): array {
        $all = self::CORE_TRIGGERS;
        $byid = [];

        foreach ($all as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }

            $id = trim((string)($trigger['id'] ?? ''));
            $description = trim((string)($trigger['description'] ?? ''));
            if ($id === '' || $description === '') {
                continue;
            }

            $byid[$id] = [
                'id' => $id,
                'description' => $description,
                'examples' => isset($trigger['examples']) && is_array($trigger['examples'])
                    ? array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $trigger['examples'])))
                    : [],
            ];
        }

        return array_values($byid);
    }

    /**
     * Return the allowed trigger ids.
     *
     * @return array
     */
    public function get_available_trigger_ids(): array {
        $triggers = $this->get_available_triggers();
        return array_values(array_map(static fn(array $trigger): string => (string)$trigger['id'], $triggers));
    }

    /**
     * Normalize and allow-list the trigger ids returned by the LLM.
     *
     * @param mixed $usedtriggers
     * @return array
     */
    public function normalize_used_triggers($usedtriggers): array {
        if (!is_array($usedtriggers)) {
            return [];
        }

        $allowed = array_flip($this->get_available_trigger_ids());
        $normalized = [];
        foreach ($usedtriggers as $triggerid) {
            if (!is_string($triggerid) && !is_int($triggerid)) {
                // Skip nested arrays or objects the LLM may return in malformed responses.
                continue;
            }
            $id = trim((string)$triggerid);
            if ($id === '' || !isset($allowed[$id])) {
                continue;
            }
            $normalized[$id] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Normalize response_type into an explicit known set.
     *
     * @param string $responsetype
     * @return string
     */
    public function normalize_response_type(string $responsetype): string {
        $normalized = trim(core_text::strtolower($responsetype));
        if ($normalized === '') {
            return self::UNKNOWN_RESPONSE_TYPE;
        }

        if (!in_array($normalized, self::KNOWN_RESPONSE_TYPES, true)) {
            return self::UNKNOWN_RESPONSE_TYPE;
        }

        return $normalized;
    }
}
