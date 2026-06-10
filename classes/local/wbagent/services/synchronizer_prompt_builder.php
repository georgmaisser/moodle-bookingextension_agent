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
            . "Synchronizer must never emit commands; always return commands=[].\n"
            . "FACT PRIORITY: completed_observations are authoritative, completed_commands are secondary, "
            . "earlier ASSISTANT text is low-trust narrative context only.\n"
            . "If any earlier ASSISTANT statement conflicts with a newer OBSERVATION, follow OBSERVATION only.\n"
            . "Never re-assert stale success details that are contradicted by newer observations.\n"
            . "CLARIFICATION / CONFIRMATION RELAY (highest priority): If an OBSERVATION (e.g. FINAL_SOURCE_RESULT) "
            . "shows response_type=clarification or response_type=confirmation_request, the turn is ASKING the user "
            . "for input and is NOT finished. Your message MUST faithfully relay that exact question in the user's "
            . "language: translate it, and keep EVERY listed option, name, count and id exactly as given. "
            . "Do NOT answer or decide it yourself (never pick an option for the user), do NOT add, drop or invent "
            . "options, do NOT claim the action is impossible or that a capability is missing, do NOT suggest a "
            . "manual workaround, and do NOT fabricate a completion. Simply ask the user the same question, clearly "
            . "formatted, so they can answer.\n"
            . "PENDING STEPS POLICY: If next_step_intent or planned_steps indicate further actions are queued, "
            . "do NOT tell the user to perform those steps manually. "
            . "Instead report what was completed and state that the agent will continue with the remaining steps. "
            . "Never suggest manual workarounds for actions the agent is capable of executing.";

        $parts[] = '[ASSISTANT]';

        return implode("\n\n", $parts);
    }
}
