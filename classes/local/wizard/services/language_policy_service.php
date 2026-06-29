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

namespace bookingextension_agent\local\wizard\services;

use core_text;

/**
 * Central language authority for runtime/decision framework responses.
 *
 * The turn language is determined by the selector LLM call (it emits user_lang, derived from the
 * latest user message) and carried in the planner result — it is NOT persisted as thread metadata.
 *
 * Policy order:
 * 1) selector-emitted user_lang (from the latest user request)
 * 2) model lang
 * 3) current UI language
 * 4) technical fallback en
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class language_policy_service {
    /** @var string */
    private const TECHNICAL_FALLBACK_LANG = 'en';

    /**
     * Normalize a language value to ISO-639-1 lowercase or empty string.
     *
     * @param string $value
     * @return string
     */
    public function normalize_iso_language(string $value): string {
        $value = trim(core_text::strtolower($value));
        if ($value === '') {
            return '';
        }

        $value = substr($value, 0, 2);
        return preg_match('/^[a-z]{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Resolve output language via the shared authority order.
     *
     * @param array $result Planner/selection result carrying the selector's user_lang.
     * @return string
     */
    public function resolve_output_language(array $result): string {
        $candidates = [
            $this->normalize_iso_language((string)($result['user_lang'] ?? '')),
            $this->normalize_iso_language((string)($result['lang'] ?? '')),
            $this->normalize_iso_language((string)current_language()),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return self::TECHNICAL_FALLBACK_LANG;
    }

    /**
     * Resolve framework fallback string id by response type.
     *
     * @param string $responsetype
     * @return string
     */
    public function fallback_string_id_for_response_type(string $responsetype): string {
        $responsetype = trim($responsetype);
        if ($responsetype === 'error') {
            return 'ai_fallback_error';
        }
        if ($responsetype === 'confirmation_request') {
            return 'ai_fallback_confirmation_request';
        }
        if ($responsetype === 'skill_call') {
            return 'ai_fallback_skill_call';
        }

        return 'ai_fallback_summary';
    }

    /**
     * String id for deterministic preflight retry hint text.
     *
     * @return string
     */
    public function preflight_retry_hint_string_id(): string {
        return 'ai_preflight_retry_hint';
    }
}
