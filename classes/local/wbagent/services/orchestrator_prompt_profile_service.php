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

namespace bookingextension_agent\local\wbagent\services;

use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_text;

/**
 * Prompt profile helper for explicit planner phases and config-key handling.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator_prompt_profile_service {
    /** Discovery planner phase. */
    public const PHASE_DISCOVERY = 'discovery';

    /** Selection planner phase. */
    public const PHASE_SELECTION = 'selection';

    /** Parameter construction planner phase. */
    public const PHASE_PARAMETER_CONSTRUCTION = 'parameter_construction';

    /**
     * Detect whether observations only contain framework-authored retry hints.
     *
     * @param array $observations
     * @return bool
     */
    public function observations_are_framework_retry_hints(array $observations): bool {
        $seen = false;

        foreach ($observations as $observation) {
            $text = trim((string)$observation);
            if ($text === '') {
                continue;
            }

            $seen = true;
            if (!str_starts_with($text, 'RETRY_HINT:')) {
                return false;
            }
        }

        return $seen;
    }

    /**
     * Resolve admin setting key per explicit planner phase.
     *
     * @param string $phase
     * @return string
     */
    public function get_planner_initial_prompt_config_key_for_phase(string $phase): string {
        $normalizedphase = $this->normalize_phase($phase);
        if ($normalizedphase === self::PHASE_DISCOVERY) {
            return 'aiinitialprompt_discovery';
        }
        if ($normalizedphase === self::PHASE_SELECTION) {
            return 'aiinitialprompt_selection';
        }

        return 'aiinitialprompt_parameter_construction';
    }

    /**
     * Return history depth per explicit planner phase.
     *
     * @param string $phase
     * @return int
     */
    public function get_history_limit_for_phase(string $phase): int {
        $normalizedphase = $this->normalize_phase($phase);
        $ignored = $normalizedphase;

        return PHP_INT_MAX;
    }

    /**
     * Treat empty or legacy full-template values as unset config for prompt fallback.
     *
     * @param string $template
     * @param string $legacydefault
     * @return string
     */
    public function normalize_config_prompt_template(string $template, string $legacydefault): string {
        $trimmed = trim($template);
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed === $legacydefault) {
            return '';
        }
        return $template;
    }

    /**
     * Normalize external phase labels to supported planner phases.
     *
     * @param string $phase
     * @return string
     */
    private function normalize_phase(string $phase): string {
        $normalized = trim(core_text::strtolower($phase));
        if ($normalized === self::PHASE_SELECTION) {
            return self::PHASE_SELECTION;
        }
        if ($normalized === self::PHASE_PARAMETER_CONSTRUCTION) {
            return self::PHASE_PARAMETER_CONSTRUCTION;
        }
        return self::PHASE_DISCOVERY;
    }
}
