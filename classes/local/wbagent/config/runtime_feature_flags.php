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

namespace bookingextension_agent\local\wbagent\config;

use core_text;

/**
 * Central runtime feature flags for incremental architecture migration.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime_feature_flags {
    /** @var string Plugin config component key. */
    private const COMPONENT = 'bookingextension_agent';

    /** @var string Enables family-level discovery path integration. */
    public const FAMILY_DISCOVERY_ENABLED = 'family_discovery_enabled';

    /** @var string Enables staged discovery routing (A/B/C). */
    public const STAGED_DISCOVERY_ENABLED = 'staged_discovery_enabled';

    /** @var string Enables family-level embeddings boost in planner ranking. */
    public const FAMILY_EMBEDDINGS_ENABLED = 'family_embeddings_enabled';

    /** @var string Enables stricter synchronizer output contract behavior. */
    public const SYNCHRONIZER_STRICT_CONTRACT = 'synchronizer_strict_contract';

    /** @var string[] Known and supported runtime feature flags. */
    private const KNOWN_FLAGS = [
        self::FAMILY_DISCOVERY_ENABLED,
        self::FAMILY_EMBEDDINGS_ENABLED,
        self::STAGED_DISCOVERY_ENABLED,
        self::SYNCHRONIZER_STRICT_CONTRACT,
    ];

    /**
     * Resolve whether a known runtime feature flag is enabled.
     *
     * Unknown flag names are treated as disabled for safety.
     *
     * @param string $flag
     * @return bool
     */
    public static function is_enabled(string $flag): bool {
        if (!in_array($flag, self::KNOWN_FLAGS, true)) {
            return false;
        }

        $raw = get_config(self::COMPONENT, $flag);
        return self::normalize_bool($raw);
    }

    /**
     * Return all known runtime flags as a normalized boolean snapshot.
     *
     * @return array<string,bool>
     */
    public static function snapshot(): array {
        $snapshot = [];
        foreach (self::KNOWN_FLAGS as $flag) {
            $snapshot[$flag] = self::is_enabled($flag);
        }
        return $snapshot;
    }

    /**
     * Normalize raw config values to strict booleans.
     *
     * @param mixed $raw
     * @return bool
     */
    private static function normalize_bool($raw): bool {
        if ($raw === null || $raw === false || $raw === '') {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (int)$raw > 0;
        }

        if (!is_string($raw)) {
            return false;
        }

        $value = trim(core_text::strtolower($raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
