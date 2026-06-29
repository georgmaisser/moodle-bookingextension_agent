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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\construction;

use bookingextension_agent\local\wizard\dto\parameter_construction_result;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Build normalized parameter payloads after concrete skill selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parameter_constructor {
    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     */
    public function __construct(skill_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Build canonical input for one selected skill.
     *
     * @param string $skillname
     * @param array $rawinput
     * @param string $lastusermessage
     * @return parameter_construction_result
     */
    public function build(string $skillname, array $rawinput, string $lastusermessage = ''): parameter_construction_result {
        $input = $this->normalize_self_user_references($rawinput);
        $input = $this->canonicalize_command_input($skillname, $input);
        $input = $this->hydrate_question_field($skillname, $input, $lastusermessage);
        $input = $this->prune_empty_input_values($input);

        if (array_key_exists('coursestarttime', $input)) {
            $ts = $this->normalize_timestamp_value($input['coursestarttime']);
            if ($ts !== null) {
                $input['coursestarttime'] = $ts;
            }
        }
        if (array_key_exists('courseendtime', $input)) {
            $ts = $this->normalize_timestamp_value($input['courseendtime']);
            if ($ts !== null) {
                $input['courseendtime'] = $ts;
            }
        }

        return new parameter_construction_result($input, true, [], []);
    }

    /**
     * Normalize self-reference fields in raw command input.
     *
     * @param array $input
     * @return array
     */
    private function normalize_self_user_references(array $input): array {
        $fields = ['teacherquery', 'selectusersquery', 'bookusersquery'];
        foreach ($fields as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                continue;
            }

            $raw = trim($input[$field]);
            if ($raw === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $raw));
            $normalizedparts = [];
            foreach ($parts as $part) {
                if ($part !== '') {
                    $normalizedparts[] = $part;
                }
            }

            if (!empty($normalizedparts)) {
                $input[$field] = implode(', ', $normalizedparts);
            }
        }

        return $input;
    }

    /**
     * Canonicalize skill input through registry-owned normalizers.
     *
     * @param string $skillname
     * @param array $input
     * @return array
     */
    private function canonicalize_command_input(string $skillname, array $input): array {
        $input = $this->registry->normalize_skill_input($skillname, $input);

        if (isset($input['search_queries']) && is_string($input['search_queries'])) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $input['search_queries']))));
            $input['search_queries'] = $parts;
        }

        foreach ($input as $key => $value) {
            if (is_array($value) && count($value) === 0) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /**
     * Hydrate a missing question field from the last user message.
     *
     * @param string $skillname
     * @param array $input
     * @param string $lastusermessage
     * @return array
     */
    private function hydrate_question_field(string $skillname, array $input, string $lastusermessage): array {
        if ($lastusermessage === '' || trim((string)($input['question'] ?? '')) !== '') {
            return $input;
        }

        $skill = $this->registry->get_skill($skillname);
        if ($skill === null) {
            return $input;
        }

        $schema = $skill->get_schema();
        $props = $schema['properties'] ?? [];
        if (isset($props['question'])) {
            $input['question'] = $lastusermessage;
        }

        return $input;
    }

    /**
     * Remove empty placeholders from a normalized input payload.
     *
     * @param array $input
     * @return array
     */
    private function prune_empty_input_values(array $input): array {
        $cleaned = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $nested = $this->prune_empty_input_values($value);
                if (!empty($nested)) {
                    $cleaned[$key] = $nested;
                }
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    /**
     * Normalize a timestamp-like value to a unix timestamp.
     *
     * @param mixed $value
     * @return int|null
     */
    private function normalize_timestamp_value($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (ctype_digit($trimmed)) {
                $parsed = (int)$trimmed;
                return $parsed > 0 ? $parsed : null;
            }
            $parsed = strtotime($trimmed);
            return $parsed !== false ? $parsed : null;
        }
        if (is_array($value)) {
            if (isset($value['timestamp'])) {
                return $this->normalize_timestamp_value($value['timestamp']);
            }
            if (isset($value['value'])) {
                return $this->normalize_timestamp_value($value['value']);
            }
        }

        return null;
    }
}
