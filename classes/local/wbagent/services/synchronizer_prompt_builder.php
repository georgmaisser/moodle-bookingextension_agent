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

use bookingextension_agent\local\wbagent\orchestrator;

/**
 * Dedicated synchronizer prompt builder.
 *
 * Keeps message-polish prompts separated from planner prompt assembly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_prompt_builder {
    /**
     * Build synchronizer system prompt.
     *
     * @param string $actionclass
     * @return string
     */
    public function build_system_prompt(string $actionclass): string {
        $template = orchestrator::get_default_initial_prompt_template_for_action($actionclass);

        // Allow optional admin synthesis-style prefix without planner prompt reuse.
        $summaryprefix = trim((string)(get_config('bookingextension_agent', 'aiinitialprompt_summarise_text') ?? ''));
        if ($summaryprefix !== '') {
            $template = $summaryprefix . "\n\n" . ltrim($template);
        }

        return $template;
    }

    /**
     * Build synchronizer prompt from history + observations.
     *
     * @param string $systemprompt
     * @param array<int,\stdClass> $messages
     * @param array<int,string> $observations
     * @param string $runtimecontext
     * @return string
     */
    public function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations,
        string $runtimecontext = ''
    ): string {
        $parts = ["[SYSTEM]\n{$systemprompt}"];

        if ($runtimecontext !== '') {
            $parts[] = "[SYSTEM_RUNTIME]\n{$runtimecontext}";
        }

        foreach ($messages as $msg) {
            $role = strtoupper((string)($msg->role ?? 'user'));
            $content = (string)($msg->content ?? '');
            $parts[] = "[{$role}]\n{$content}";
        }

        $observationnumber = 1;
        foreach ($observations as $observation) {
            $trimmed = trim((string)$observation);
            if ($trimmed === '') {
                continue;
            }
            $parts[] = "[OBSERVATION {$observationnumber}]\n{$trimmed}";
            $observationnumber++;
        }

        $parts[] = "[OUTPUT_CONTRACT]\n"
            . "Return exactly one valid JSON object and nothing else.\n"
            . "Do not output markdown, code fences, prose, or bullet lists outside JSON.\n"
            . "Use response_type='sufficient' for successful finalization.\n"
            . "Synchronizer must never emit commands; always return commands=[].";

        $parts[] = '[ASSISTANT]';

        return implode("\n\n", $parts);
    }
}
