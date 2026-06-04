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
 * Centralized builder for NON-OPTIONAL prompt policies.
 *
 * Consolidates all dynamic policy appends that orchestrator.build_system_prompt()
 * previously scattered inline. This is the single source of truth for:
 * - LANGUAGE POLICY
 * - TRIGGER POLICY
 * - STEP INTENT POLICY
 * - DOCS ANSWER POLICY
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

/**
 * Prompt policy builder.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_policy_builder {
    /**
     * Build all NON-OPTIONAL planner policies as a single text block.
     *
     * @param bool $isfirstassistantturn True when no assistant output exists yet in this thread.
     * @return string
     */
    public static function build_planner_policies(
        string $phase = 'discovery',
        bool $hasobservations = false,
        bool $isfirstassistantturn = false
    ): string {
        $policies = [];
        $normalizedphase = self::normalize_phase($phase);

        // 1. RESPONSE CONTRACT POLICY (universal, always appended).
        $policies[] = self::build_response_contract_policy($normalizedphase);

        // 2. TRIGGER POLICY (compact only; task catalog now carries task-specific examples and hints).
        $policies[] = self::build_trigger_policy_compact();
        if ($normalizedphase === 'discovery') {
            $policies[] = self::build_routing_determinism_policy();
        }

        // 3. STEP INTENT POLICY (planner-facing guidance only).
        $policies[] = self::build_step_intent_policy();

        // 4. DOCS ANSWER POLICY (selection phase only).
        if ($normalizedphase === 'selection') {
            $policies[] = self::build_docs_answer_policy();
        }

        // 5. SUFFICIENCY POLICY (planner-facing guidance only).
        // - For discovery: only if hasobservations (planner should stop if already has results).
        // - For later phases: always (guidance on when observations suffice).
        if ($normalizedphase === 'discovery') {
            if ($hasobservations) {
                $policies[] = self::build_sufficiency_policy($normalizedphase, $hasobservations);
            }
        } else {
            $policies[] = self::build_sufficiency_policy($normalizedphase, $hasobservations);
        }

        return "\n\n" . implode("\n\n", $policies);
    }

    /**
     * Build NON-OPTIONAL RESPONSE CONTRACT POLICY.
     *
     * @return string
     */
    private static function build_response_contract_policy(string $phase): string {
        if ($phase === 'parameter_construction') {
            return "NON-OPTIONAL RESPONSE CONTRACT POLICY:\n"
                . "- Return valid JSON only (no markdown).\n"
                . "- Always include top-level keys: response_type, message, used_triggers, next_step_intent, lang, user_lang.\n"
                . "- next_step_intent MUST always be a string (never null; use empty string if no follow-up planned).\n"
                . "- Do NOT include planned_steps — this field belongs to the selector phase only.\n"
                . "- message MUST be a non-empty user-facing sentence (never an empty string) "
                . "EXCEPT for response_type=sufficient (omit message or leave empty).\n"
                . "- Allowed response_type values: task_call, confirmation_request, "
                . "confirm_pending, clarification, sufficient, error.\n"
                . "- For task_call or confirmation_request, commands MUST contain one or more command objects.\n"
                . "- For clarification, confirm_pending, sufficient, or error, commands MUST be [].\n"
                . "- This phase is constructor-only: build parameters for the selected task only.\n"
                . "- Do not perform task discovery, task routing, or task switching in this phase.\n"
                . "- Every command.task MUST match selected_task from phase handoff.\n"
                . "- Canonical constructor command shape uses only command-level keys: task, version, parameters.\n"
                . "- Do not emit non-canonical command-level keys such as params, command_id, id, or cid.\n"
                . "- Canonical command example: {\"task\":\"<selected_task>\",\"version\":1,\"parameters\":{...}}\n"
                . "- Keep JSON field types stable (arrays as arrays, numbers as numbers, strings as strings).";
        }

        if ($phase === 'selection') {
            return "NON-OPTIONAL RESPONSE CONTRACT POLICY:\n"
            . "- Return valid JSON only (no markdown).\n"
            . "- Always include top-level keys: response_type, message, used_triggers, next_step_intent, lang, user_lang, planned_steps.\n"
            . "- Allowed response_type values: task_call, clarification, confirm_pending, sufficient, error.\n"
            . "- For task_call, commands MUST contain exactly one command object that selects exactly one task and MUST NOT carry full parameter payloads.\n"
            . "- Selection must not perform parameter construction; command input should be omitted or {}.\n"
            . "- For response_type=clarification, confirm_pending, or error, message MUST be a non-empty string.\n"
            . "- For response_type=sufficient, message may be omitted or empty.\n"
            . "- For clarification, confirm_pending, sufficient, or error, commands MUST be [].\n"
            . "- This phase is a tool-selector call: it chooses exactly one task, and construction handles parameters.\n"
            . "- used_triggers MUST always be a JSON array (may be empty if no triggers apply, but field MUST exist).\n"
            . "- next_step_intent MUST always be a string (never null).\n"
            . "- planned_steps MUST always be a JSON array: [] for single-step, [{\"intent\":\"...\"},...] for multi-step.\n"
            . "- Keep JSON field types stable (arrays as arrays, numbers as numbers, strings as strings).";
        }

        if ($phase === 'discovery') {
            return "NON-OPTIONAL RESPONSE CONTRACT POLICY:\n"
                . "- Return valid JSON only (no markdown).\n"
                . "- Always include top-level keys: response_type, message, used_triggers, next_step_intent, lang, user_lang.\n"
            . "- Allowed response_type values: clarification, confirm_pending, sufficient, error.\n"
                . "- For response_type=clarification, confirm_pending, or error, message MUST be a non-empty string.\n"
                . "- For response_type=sufficient, message may be omitted or empty.\n"
                . "- commands MUST always be [] in this phase.\n"
                . "- For clarification, confirm_pending, sufficient, or error, commands MUST be [].\n"
            . "- Never emit task_call or confirmation_request in this phase.\n"
                . "- used_triggers MUST always be a JSON array (may be empty if no triggers apply, but field MUST exist).\n"
                . "- Keep JSON field types stable (arrays as arrays, numbers as numbers, strings as strings).";
        }

        return "NON-OPTIONAL RESPONSE CONTRACT POLICY:\n"
            . "- Return valid JSON only (no markdown code fences).\n"
            . "- Every response MUST include a top-level field 'response_type'.\n"
            . "- For response_type=task_call, OMIT the top-level 'message' field entirely.\n"
            . "- For response_type=confirmation_request, clarification, confirm_pending, or error, "
            . "include a top-level string field 'message'.\n"
            . "- For response_type=confirmation_request, clarification, confirm_pending, or error, "
            . "the 'message' field MUST NOT be empty.\n"
            . "- Allowed response_type values: task_call, confirmation_request, "
            . "confirm_pending, clarification, sufficient, error.\n"
            . "- Every response MUST include: commands, used_triggers.\n"
            . "- For response_type=task_call or confirmation_request, include a non-empty commands array.\n"
            . "- For response_type=clarification, confirm_pending, sufficient, or error, commands MUST be [].\n"
            . "- used_triggers MUST always be a JSON array (may be empty if no triggers apply, but field MUST exist).\n"
            . "- Preserve JSON field types exactly: arrays must be arrays, numbers must be numbers, strings must be strings.\n"
            . "- Never serialize arrays as comma-separated strings.\n"
            . "- Omit optional input fields when you do not have a grounded value; "
            . "do not send empty placeholders such as doc_path=\"\".";
    }

    /**
     * Build NON-OPTIONAL TRIGGER POLICY.
     *
     * @param string $triggerjson
     * @return string
     */
    private static function build_trigger_policy(string $triggerjson): string {
        return "NON-OPTIONAL TRIGGER POLICY:\n"
            . "- Evaluate the latest user message against the task catalog and the current conversation state.\n"
            . "- Return a JSON array field 'used_triggers' with trigger ids that apply to the latest user message.\n"
            . "- Do NOT invent trigger ids. Use only ids from the catalog.\n"
            . "- If none apply, return 'used_triggers': [].\n"
            . "- Task catalog entries may include example_input and message_triggers for grounding.\n"
            . "- CRITICAL: NEVER include 'core.is_lookup_request' in used_triggers.\n"
            . "- 'core.is_lookup_request' is server-managed from task readonly properties.\n"
            . "- All other valid core triggers (e.g. core.is_confirmation_message) should be detected normally.\n"
            . "\nREQUIRED OUTPUT FIELD:\n"
            . "- Every response MUST include used_triggers as a JSON array (field required, may be empty).";
    }

    /**
     * Build compact trigger policy for non-routing steps.
     *
     * @return string
     */
    private static function build_trigger_policy_compact(): string {
        return "NON-OPTIONAL TRIGGER POLICY:\n"
            . "- used_triggers carries only flow/state signals, not task semantics.\n"
            . "- Include a trigger only when it is explicitly grounded in the latest user turn.\n"
            . "- Never include core.is_lookup_request (server-derived from readonly execution path).\n"
            . "- Return used_triggers as a JSON array (empty array if none apply).";
    }

    /**
     * Build NON-OPTIONAL ROUTING DETERMINISM POLICY.
     *
     * Uses structured positive/negative criteria for stable routing decisions
     * without language-specific token lists.
     *
     * @return string
     */
    private static function build_routing_determinism_policy(): string {
        return "NON-OPTIONAL ROUTING DETERMINISM POLICY:\n"
            . "- Route from task catalog evidence (readonly, minimal_input, anchors, message_triggers), not phrase lists.\n"
            . "- Respect strict 2-call roles: discovery stays non-command, selection does task choice, construction does parameters.\n"
            . "- Follow one decision order: completed outcome -> sufficient, explicit pending confirmation"
            . " -> confirm_pending, missing required fields -> clarification, mutating -> confirmation_request,"
            . " read-only -> task_call.\n"
            . "\nMUTATION CLARIFICATION MINIMIZATION:\n"
            . "- Do not ask clarification for fields already explicitly provided by the user.\n"
            . "- Reuse task-catalog examples and descriptions to map explicit user phrasing to task input fields.\n"
            . "- Ask clarification only for truly missing required fields.\n"
            . "\nTRIGGER CONSISTENCY:\n"
            . "- Never include core.is_lookup_request in used_triggers; it is always server-managed.\n"
            . "- Add core.is_confirmation_message only for explicit confirmation intent.\n"
            . "- Use used_triggers only for flow/state signals; do not encode task semantics there.\n"
            . "- Keep used_triggers as supporting structured evidence, never as decoration.";
    }

    /**
     * Build NON-OPTIONAL STEP INTENT POLICY.
     *
     * @return string
     */
    private static function build_step_intent_policy(): string {
        return "NON-OPTIONAL STEP INTENT POLICY:\n"
            . "- next_step_intent: always a short string (never null) grounded in the immediate next action.\n"
            . "- planned_steps: see OUTPUT_CONTRACT — required for multi-step requests.";
    }

    /**
     * Build NON-OPTIONAL DOCS ANSWER POLICY.
     *
     * @return string
     */
    private static function build_docs_answer_policy(): string {
        return "NON-OPTIONAL DOCS ANSWER POLICY:\n"
            . "- Base documentation answers strictly on the provided documentation context.\n"
            . "- Keep links and URLs intact and clickable; do not rewrite link targets.\n"
            . "- Prefer concise, concrete explanations over generic filler text.\n"
            . "- If the user asks HOW TO perform an action and the documentation context provides actionable steps, "
            . "answer with a clearly formatted numbered list (1., 2., 3.) in the user's language.\n"
            . "- Do not invent steps; only use steps supported by the available documentation context.\n"
            . "- For documentation task inputs, prefer grounded candidate paths or topic hints over guessed root paths.\n"
            . "- If no grounded document path is known yet, omit doc_path and use the task's search or candidate fields instead.";
    }

    /**
     * Build NON-OPTIONAL SUFFICIENCY POLICY.
     *
     * Guides the LLM on when sufficient information has been gathered to provide a final answer.
     * This reduces unnecessary loop iterations by signaling when tool-calling should stop.
     *
     * @return string
     */
    private static function build_sufficiency_policy(string $phase = 'discovery', bool $hasobservations = false): string {
        $normalizedphase = self::normalize_phase($phase);

        $policy = "NON-OPTIONAL SUFFICIENCY POLICY:\n"
            . "- Use first-match priority: completed outcome evidence -> sufficient,"
            . " explicit pending confirmation -> confirm_pending,"
            . " missing required fields -> clarification, mutating -> confirmation_request, read-only -> task_call.\n"
            . "- Treat completed_commands and completed_observations as authoritative execution evidence.\n"
            . "- Do not re-emit a command when the same outcome is already completed.\n"
            . "- Prefer finishing with sufficient instead of repeating equivalent tool calls.";

        // CRITICAL: Apply re-call prevention ONLY to later planner phases, not to final synthesis.
        // This preserves the mini-model (early steps) / large-model (final synthesis) architecture.
        if ($normalizedphase !== 'discovery' && $hasobservations) {
            $policy .= "\n- For non-discovery phases with observations:"
                . " if observations already answer the current request, return sufficient with commands=[].\n"
                . "- For mutation intents, only return confirmation_request when required mutation data is grounded"
                . " and no completed outcome already exists.\n"
                . "- Avoid repeating the same command signature when its result already exists in observations.";
        }

        return $policy;
    }

    /**
     * Normalize phase labels for policy selection.
     *
     * @param string $phase
     * @return string
     */
    private static function normalize_phase(string $phase): string {
        $normalized = trim(\core_text::strtolower($phase));
        if ($normalized === 'selection') {
            return 'selection';
        }
        if ($normalized === 'parameter_construction') {
            return 'parameter_construction';
        }

        return 'discovery';
    }
}
