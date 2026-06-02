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
 * Prompt profile helper for orchestrator step-type and config-key handling.
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

    /** @var string */
    private string $toolcallparse;

    /** @var string */
    private string $simpleretrieval;

    /** @var string */
    private string $wbplanneraction;

    /**
     * Constructor.
     *
     * @param string $toolcallparse
     * @param string $simpleretrieval
     * @param string $wbplanneraction
     */
    public function __construct(
        string $toolcallparse,
        string $simpleretrieval,
        string $wbplanneraction
    ) {
        $this->toolcallparse = $toolcallparse;
        $this->simpleretrieval = $simpleretrieval;
        $this->wbplanneraction = $wbplanneraction;
    }

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
     * Normalize runtime step types without collapsing phase-specific values.
     *
     * @param string $steptype
     * @return string
     */
    public function normalize_runtime_step_type(string $steptype): string {
        return trim(core_text::strtolower($steptype));
    }

    /**
     * Normalize planner step types without collapsing phase-specific values.
     *
     * @param string $steptype
     * @return string
     */
    public function normalize_planner_step_type(string $steptype): string {
        return trim(core_text::strtolower($steptype));
    }

    /**
     * Resolve the explicit planner phase for a normalized step type.
     *
     * @param string $steptype
     * @return string
     */
    public function resolve_phase_for_step_type(string $steptype): string {
        $normalized = trim(core_text::strtolower($steptype));
        if ($normalized === $this->toolcallparse) {
            return self::PHASE_DISCOVERY;
        }
        if ($normalized === $this->simpleretrieval) {
            return self::PHASE_SELECTION;
        }

        return self::PHASE_PARAMETER_CONSTRUCTION;
    }

    /**
     * Resolve admin setting key for initial prompt templates per step profile.
     *
     * @param string $steptype
     * @return string
     */
    public function get_planner_initial_prompt_config_key(string $steptype): string {
        if ($steptype === $this->simpleretrieval) {
            return 'aiinitialprompt_simple_retrieval';
        }
        return 'aiinitialprompt_tool_call_parse';
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
            return 'aiinitialprompt_tool_call_parse';
        }
        if ($normalizedphase === self::PHASE_SELECTION) {
            return 'aiinitialprompt_simple_retrieval';
        }

        return 'aiinitialprompt_summarise_text';
    }

    /**
     * Return history depth per prompt profile.
     *
     * @param string $steptype
     * @return int
     */
    public function get_history_limit_for_step(string $steptype): int {
        return PHP_INT_MAX;
    }

    /**
     * Return history depth per explicit planner phase.
     *
     * @param string $phase
     * @return int
     */
    public function get_history_limit_for_phase(string $phase): int {
        $normalizedphase = $this->normalize_phase($phase);
        $steptype = $normalizedphase === self::PHASE_DISCOVERY
            ? $this->toolcallparse
            : $this->simpleretrieval;

        return $this->get_history_limit_for_step($steptype);
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
        if ($normalized === self::PHASE_PARAMETER_CONSTRUCTION || $normalized === 'construction') {
            return self::PHASE_PARAMETER_CONSTRUCTION;
        }
        return self::PHASE_DISCOVERY;
    }
}
