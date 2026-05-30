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
    /** @var string */
    private string $toolcallparse;

    /** @var string */
    private string $simpleretrieval;

    /** @var string */
    private string $finalreasoning;

    /** @var string */
    private string $finalsynthesis;

    /** @var string */
    private string $wbplanneraction;

    /** @var string */
    private string $wbreplyaction;

    /**
     * Constructor.
     *
     * @param string $toolcallparse
     * @param string $simpleretrieval
     * @param string $finalreasoning
     * @param string $finalsynthesis
     * @param string $wbplanneraction
     * @param string $wbreplyaction
     */
    public function __construct(
        string $toolcallparse,
        string $simpleretrieval,
        string $finalreasoning,
        string $finalsynthesis,
        string $wbplanneraction,
        string $wbreplyaction
    ) {
        $this->toolcallparse = $toolcallparse;
        $this->simpleretrieval = $simpleretrieval;
        $this->finalreasoning = $finalreasoning;
        $this->finalsynthesis = $finalsynthesis;
        $this->wbplanneraction = $wbplanneraction;
        $this->wbreplyaction = $wbreplyaction;
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
     * Normalize orchestrator step type values to supported profiles.
     *
     * @param string $steptype
     * @return string
     */
    public function normalize_step_type(string $steptype): string {
        $normalized = trim(core_text::strtolower($steptype));
        if ($normalized === $this->finalreasoning) {
            return $this->finalreasoning;
        }
        if ($normalized === $this->finalsynthesis) {
            return $this->finalsynthesis;
        }
        if ($normalized === $this->simpleretrieval) {
            return $this->simpleretrieval;
        }
        return $this->toolcallparse;
    }

    /**
     * Resolve admin setting key for initial prompt templates per step profile.
     *
     * @param string $steptype
     * @return string
     */
    public function get_initial_prompt_config_key(string $steptype): string {
        if ($steptype === $this->finalreasoning) {
            return 'aiinitialprompt_final_reasoning';
        }
        if ($steptype === $this->finalsynthesis) {
            return 'aiinitialprompt_final_synthesis';
        }
        if ($steptype === $this->simpleretrieval) {
            return 'aiinitialprompt_simple_retrieval';
        }
        return 'aiinitialprompt_tool_call_parse';
    }

    /**
     * Resolve the admin config key for action-specific initial prompts.
     *
     * @param string $actionclass
     * @return string
     */
    public function get_action_initial_prompt_config_key(string $actionclass): string {
        if ($actionclass === summarise_text::class || $actionclass === $this->wbplanneraction) {
            return 'aiinitialprompt_summarise_text';
        }
        if ($actionclass === explain_text::class) {
            return 'aiinitialprompt_explain_text';
        }
        if ($actionclass === generate_text::class || $actionclass === $this->wbreplyaction) {
            return 'aiinitialprompt_generate_text';
        }
        return '';
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
}
