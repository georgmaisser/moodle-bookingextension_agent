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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\orchestrator;

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
     * Turn continuation state: nothing runs after this reply (sufficient / clarification /
     * error). The reply contract forbids announcing any automatic follow-up action.
     */
    public const CONTINUATION_NONE = 'none';

    /**
     * Turn continuation state: the turn ends as a confirmation_request — queued steps run
     * after (and only after) the user confirms.
     */
    public const CONTINUATION_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /**
     * Build synchronizer system prompt.
     *
     * @param string $actionclass
     * @return string
     */
    public function build_system_prompt(string $actionclass): string {
        $template = orchestrator::get_default_initial_prompt_template_for_action($actionclass);

        // Allow optional admin synthesis-style prefix without planner prompt reuse.
        // The setting is seeded with the default opening sentence, which the template
        // already contains — only a real admin customization is merged. When both start
        // with the expert-opening sentence, the prefix replaces the template's opening
        // line instead of duplicating it (same rule as phase_prompt_bundle_builder).
        $summaryprefix = trim((string)(get_config('bookingextension_agent', 'aiinitialprompt_summarise_text') ?? ''));
        if ($summaryprefix !== '' && !orchestrator::is_default_summary_prompt_prefix($summaryprefix)) {
            $trimmedtemplate = ltrim($template);
            $isexpertopening = static function (string $text): bool {
                return preg_match(
                    '/^You are an expert that composes polished, helpful answers/',
                    trim($text)
                ) === 1;
            };

            if ($isexpertopening($summaryprefix) && $isexpertopening($trimmedtemplate)) {
                $newlinepos = strpos($trimmedtemplate, "\n");
                $template = $newlinepos === false
                    ? $summaryprefix
                    : $summaryprefix . "\n" . ltrim(substr($trimmedtemplate, $newlinepos + 1), "\n");
            } else {
                $template = $summaryprefix . "\n\n" . $trimmedtemplate;
            }
        }

        // Keep placeholders stable across requests for better prompt-prefix caching:
        // the [SYSTEM] block stays byte-identical, real values live in the runtime blocks.
        return strtr($template, [
            '{{contextname}}' => '[SYSTEM_RUNTIME_STATE.context_name]',
            // Deprecated alias (agent is site-wide): resolves to the generic context name. Kept so
            // admin-customized templates that still use {{bookingname}} do not dangle.
            '{{bookingname}}' => '[SYSTEM_RUNTIME_STATE.context_name]',
            '{{timezonename}}' => '[SYSTEM_RUNTIME.timezone]',
            '{{nowiso}}' => '[SYSTEM_RUNTIME_STATE.now_iso]',
        ]);
    }

    /**
     * Build synchronizer prompt from history + observations.
     *
     * Cache-friendly ordering: static [SYSTEM], per-thread-stable [SYSTEM_RUNTIME],
     * append-only history/observations, then the per-request [SYSTEM_RUNTIME_STATE]
     * (now_iso, execution ledgers) so volatile content never busts the shared prefix.
     *
     * @param string $systemprompt
     * @param \stdClass[] $messages
     * @param string[] $observations
     * @param string $runtimecontext Per-thread-stable runtime facts.
     * @param string $runtimestate Per-request volatile runtime state.
     * @param string $continuation One of the CONTINUATION_* states, computed by the engine.
     * @return string
     */
    public function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations,
        string $runtimecontext = '',
        string $runtimestate = '',
        string $continuation = self::CONTINUATION_NONE
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

        if ($runtimestate !== '') {
            $parts[] = "[SYSTEM_RUNTIME_STATE]\n{$runtimestate}";
        }

        // Observations come AFTER the state ledgers, closest to [ASSISTANT]: the synchronizer's own
        // FACT PRIORITY (completed_observations authoritative > completed_commands secondary) is then
        // reinforced by recency instead of being contradicted by it.
        $observationnumber = 1;
        foreach ($observations as $observation) {
            $trimmed = trim((string)$observation);
            if ($trimmed === '') {
                continue;
            }
            $parts[] = "[OBSERVATION {$observationnumber}]\n{$trimmed}";
            $observationnumber++;
        }

        // The continuation policy is computed from ENGINE STATE (final response_type), never
        // guessed by the model: with no continuation, the contract forbids announcing any
        // automatic follow-up; awaiting confirmation, it binds follow-up to the user's confirm.
        if ($continuation === self::CONTINUATION_AWAITING_CONFIRMATION) {
            $continuationpolicy =
                "PENDING STEPS POLICY: This turn ends awaiting the user's confirmation. Queued steps run "
                . "ONLY after the user confirms — report what was completed and that the remaining steps "
                . "run after confirmation. Do NOT tell the user to perform those steps manually, and never "
                . "suggest manual workarounds for actions the agent is capable of executing.\n";
        } else {
            $continuationpolicy =
                "TURN END POLICY: This reply ends the turn — NOTHING runs automatically after it. "
                . "NEVER state or imply that the agent will create, update, delete, retry or continue "
                . "anything after this reply. If parts of the request were NOT completed (see the "
                . "observations and any UNEXECUTED PLANNED STEPS list), name them explicitly as not done "
                . "and either relay the pending question or ask the user how to proceed.\n";
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
            . "formatted, so they can answer. When this rule applies, relaying the question IS the polished, "
            . "complete answer — do not compose any findings, results or explanations beyond it.\n"
            . $continuationpolicy
            . "LINK POLICY: When you mention a course, booking option, activity, user or rule, include the URL "
            . "given for it in the observations (markdown link on the entity name). Use those URLs EXACTLY as "
            . "provided — NEVER construct, guess, shorten or modify a URL yourself, and never invent links for "
            . "entities that came without one.\n"
            . "ENTITY TYPE POLICY: Name each item by the entity type the observation gives it — a course is a "
            . "course, a booking activity is an activity, a booking option is an option. NEVER present a booking "
            . "activity or option as if it were a course. When an item is an activity or option that lives inside "
            . "a course, make the type explicit and keep the parent course distinct, e.g. "
            . "\"activity '<activity name>' (course: <course name>)\" — do NOT label it "
            . "\"in the course '<activity name>'\". The angle-bracket names are placeholders for names "
            . "taken from the observations — never treat them (or any example) as real entities. "
            . "Use each entity's own link target from the observations (an activity links to its activity view, "
            . "a course to its course view); never relabel one type's link as another type.";

        // PRO presentation policy — generic, never skill-specific. Only added WITHOUT full access
        // (no PRO license AND not running on the Wunderbyte LLM). With full access the agent runs
        // unrestricted, so this hint must not appear at all.
        if (!agent_access_service::has_full_access()) {
            $parts[] = "[PRO_LICENSE_POLICY]\n"
                . "Some tasks are only available with the Wunderbyte PRO license or an active Wunderbyte "
                . "subscription. When a request cannot be fulfilled for that reason, state plainly in the "
                . "user's language that the task is only available with a Wunderbyte PRO license or a "
                . "Wunderbyte subscription, and include this upgrade link as a markdown link labelled Get "
                . "Pro: [Get Pro](" . get_string('aitrial_pro_license_url', 'bookingextension_agent') . "). "
                . "Never reveal internal skill or function names, and never tell the user to try again "
                . "later or contact support — upgrading via the Get Pro link is the only next step.";
        }

        $parts[] = '[ASSISTANT]';

        return implode("\n\n", $parts);
    }
}
