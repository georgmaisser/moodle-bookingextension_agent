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
     * Build all NON-OPTIONAL policies as a single text block.
     *
     * @param string $triggerjson    JSON string of available triggers.
     * @param string $steptype       Orchestrator step type (from orchestrator.php constants).
     * @param bool $isfirstassistantturn True when no assistant output exists yet in this thread.
     * @return string
     */
    public static function build_all_policies(
        string $triggerjson,
        string $steptype = 'tool_call_parse',
        bool $hasobservations = false,
        bool $isfirstassistantturn = false
    ): string {
        $policies = [];
        $normalizedsteptype = trim(\core_text::strtolower($steptype));

        // 1. RESPONSE CONTRACT POLICY (universal, always appended).
        $policies[] = self::build_response_contract_policy($normalizedsteptype);

        // 2. TRIGGER POLICY (compact only; task catalog now carries task-specific examples and hints).
        $policies[] = self::build_trigger_policy_compact();
        if ($normalizedsteptype === 'tool_call_parse') {
            $policies[] = self::build_routing_determinism_policy();
        }

        // 3. STEP INTENT POLICY (skip for final synthesis — synthesis does not step).
        if ($normalizedsteptype !== 'final_synthesis') {
            $policies[] = self::build_step_intent_policy($normalizedsteptype);
        }

        // 4. DOCS ANSWER POLICY (only for reasoning steps with observations, not final synthesis).
        if ($normalizedsteptype === 'simple_retrieval' || $normalizedsteptype === 'final_reasoning') {
            $policies[] = self::build_docs_answer_policy();
        }

        // 5. SUFFICIENCY POLICY (different for each step type).
        // - For tool_call_parse: only if hasobservations (planner should stop if already has results).
        // - For simple_retrieval/final_reasoning: always (guidance on when observations suffice).
        // - For final_synthesis: special synthesis-only policy (always write message).
        if ($normalizedsteptype === 'tool_call_parse') {
            if ($hasobservations) {
                $policies[] = self::build_sufficiency_policy($normalizedsteptype, $hasobservations);
            }
        } else {
            // Simple_retrieval, final_reasoning, final_synthesis all get sufficiency guidance.
            $policies[] = self::build_sufficiency_policy($normalizedsteptype, $hasobservations);
        }

        return "\n\n" . implode("\n\n", $policies);
    }

    /**
     * Build NON-OPTIONAL RESPONSE CONTRACT POLICY.
     *
     * @return string
     */
    private static function build_response_contract_policy(string $steptype): string {
        if ($steptype !== 'tool_call_parse') {
            return "NON-OPTIONAL RESPONSE CONTRACT POLICY:\n"
                . "- Return valid JSON only (no markdown).\n"
                . "- Always include top-level keys: response_type, message, used_triggers, next_step_intent, lang, user_lang.\n"
                . "- message MUST be a non-empty user-facing sentence (never an empty string) "
                . "EXCEPT for response_type=sufficient (omit message or leave empty).\n"
                . "- Allowed response_type values: task_call, confirmation_request, "
                . "confirm_pending, clarification, sufficient, error.\n"
                . "- For task_call or confirmation_request, commands MUST be a non-empty array.\n"
                . "- For clarification, confirm_pending, sufficient, or error, commands MUST be [].\n"
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
            . "- Allowed response_type values: task_call, confirmation_request, confirm_pending, clarification, error.\n"
            . "- Every response MUST include: commands, used_triggers.\n"
            . "- For response_type=task_call or confirmation_request, include a non-empty commands array.\n"
            . "- For response_type=clarification, confirm_pending, or error, commands MUST be [].\n"
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
            . "- Keep used_triggers empty unless a core flow trigger is clearly grounded.\n"
            . "- Task catalog entries may include example_input and message_triggers for grounding.\n"
            . "- Return used_triggers as a JSON array (empty array if none apply).";
    }

    /**
     * Build NON-OPTIONAL ROUTING DETERMINISM POLICY (tool_call_parse only).
     *
     * Uses structured positive/negative criteria for stable routing decisions
     * without language-specific token lists.
     *
     * @return string
     */
    private static function build_routing_determinism_policy(): string {
        return "NON-OPTIONAL ROUTING DETERMINISM POLICY:\n"
            . "- Derive routing from STRUCTURED intent signals, not phrase matching.\n"
            . "- Use this intent class set: info_lookup, docs_explain, mutation, confirmation, unclear.\n"
            . "- Resolve exactly one primary intent class per response.\n"
            . "\nPOSITIVE CRITERIA:\n"
            . "- info_lookup: user asks for factual retrieval/listing/searching of existing data.\n"
            . "- docs_explain: user asks for rules/capabilities/how-it-works from docs context.\n"
            . "- mutation: user asks to create/update/delete or execute a state-changing action.\n"
            . "- confirmation: user confirms or approves an already pending action.\n"
            . "\nNEGATIVE CRITERIA:\n"
            . "- Do NOT use confirmation_request for read-only retrieval intents.\n"
            . "- Do NOT use task_call with commands=[] (contract violation).\n"
            . "- Do NOT use confirm_pending unless confirmation intent is explicit.\n"
            . "- Do NOT mix conflicting intents in one response; choose one primary class.\n"
            . "\nRESPONSE MAPPING:\n"
            . "- info_lookup/docs_explain with sufficient task grounding => response_type=task_call, commands non-empty.\n"
            . "- mutation with sufficient grounding => response_type=confirmation_request, commands non-empty.\n"
            . "- confirmation => response_type=confirm_pending, commands=[].\n"
            . "- unclear/missing required fields => response_type=clarification, commands=[].\n"
            . "\nMUTATION CLARIFICATION MINIMIZATION:\n"
            . "- Do not ask clarification for fields already explicitly provided by the user.\n"
            . "- Reuse task-catalog examples and descriptions to map explicit user phrasing to task input fields.\n"
            . "- Ask clarification only for truly missing required fields.\n"
            . "\nTRIGGER CONSISTENCY:\n"
            . "- CRITICAL: Never include 'core.is_lookup_request' in used_triggers. This trigger is ALWAYS managed server-side.\n"
            . "- Add core.is_confirmation_message only for explicit confirmation intent.\n"
            . "- Use used_triggers only for flow/state signals; do not encode task semantics there.\n"
            . "- Keep used_triggers as supporting structured evidence, never as decoration.";
    }

    /**
     * Build NON-OPTIONAL STEP INTENT POLICY.
     *
     * @param string $steptype
     * @return string
     */
    private static function build_step_intent_policy(string $steptype): string {
        if (self::is_planner_step_type($steptype)) {
            return "NON-OPTIONAL STEP INTENT POLICY:\n"
                . "- If present, keep it short and aligned with the user language.";
        }

        return "NON-OPTIONAL STEP INTENT POLICY:\n"
            . "- Every response MUST include an additional top-level JSON field \"next_step_intent\" "
            . "with one short sentence describing your immediate next action.\n"
            . "- This sentence must be model-authored (no template text) and in the same language as the user.\n"
            . "- next_step_intent must describe intention (present/future), not completed work.\n"
            . "- Avoid past-tense completion phrasing such as \"I have ...\" or \"Ich habe ...\".\n"
            . "  Good: \"I will now check the relevant settings.\"\n"
            . "  Bad: \"I already finished the explanation.\"\n"
            . "- CRITICAL language rule: next_step_intent MUST be written in the SAME language as the user message."
            . " If lang=de, next_step_intent MUST be in German."
            . " If lang=fr, next_step_intent MUST be in French."
            . " Writing next_step_intent in English when lang != en is a contract violation.\n"
            . "  Bad (lang=de): \"I will retrieve the details of the booking options.\"\n"
            . "  Good (lang=de): \"Ich rufe jetzt die Buchungsoptionen ab.\"\n"
            . "- If you answer directly without tool calls, next_step_intent should still describe that direct action.";
    }

    /**
     * Detect the planner step type used for routing prompts.
     *
     * @param string $steptype
     * @return bool
     */
    private static function is_planner_step_type(string $steptype): bool {
        return trim(
            \core_text::strtolower($steptype)
        ) === 'tool_call_parse';
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
    private static function build_sufficiency_policy(string $steptype = '', bool $hasobservations = false): string {
        $normalizedsteptype = trim(\core_text::strtolower($steptype));

        // For final synthesis, do NOT apply rule priority — synthesis must always write message.
        if ($normalizedsteptype === 'final_synthesis') {
            return "SYNTHESIS RESPONSE POLICY:\n"
                . "- You have complete information from prior observations.\n"
                . "- You MUST write a polished, final user-facing answer in the 'message' field.\n"
                . "- Never return sufficient with empty or omitted message field.\n"
                . "- Always include: response_type=sufficient, message (non-empty), commands=[], user_lang.\n";
        }

        $policy = "OBSERVATION TERMINATION RULE (ABSOLUTE):\n"
            . "- If an OBSERVATION contains information relevant to the current user request: "
            . "return response_type=sufficient and commands=[].\n"
            . "- Stop reasoning immediately after that decision; do not continue task selection or mutation checks.\n"
            . "- Re-calling tools after a relevant observation exists is a protocol violation.\n"
            . "\n"
            . "RULE PRIORITY (evaluate top-down — first matching rule wins):\n"
            . "1. SUFFICIENCY RULE: observation present AND relevant → response_type=sufficient, "
            . "commands=[], message optional (omit if SR only evaluates, no synthesis).\n"
            . "2. READ-ONLY RULE:      no observation present → response_type=task_call, commands non-empty.\n"
            . "3. MUTATIONS RULE:      mutating intent → response_type=confirmation_request, commands non-empty.\n"
            . "CRITICAL: Returning task_call when a relevant observation already exists is a CONTRACT VIOLATION.\n"
            . "\n"
            . "NON-OPTIONAL SUFFICIENCY POLICY:\n"
            . "- After executing tool calls and receiving results, evaluate whether you have SUFFICIENT information to answer.\n"
            . "- Answer directly if:\n"
            . "  * You found the requested information (booking options, documentation, user details, etc.).\n"
            . "  * You received explicit documentation or capability listing.\n"
            . "  * Multiple searches return no new results.\n"
            . "- Do NOT continue searching if you already have actionable information.\n"
            . "- Prefer stopping and answering over making redundant tool calls.\n"
            . "- When in doubt: Answer with what you found rather than searching again.";

        // CRITICAL: Apply re-call prevention ONLY to the planner (simple_retrieval), not to final synthesis.
        // This preserves the mini-model (early steps) / large-model (final synthesis) architecture.
        if ($normalizedsteptype === 'simple_retrieval' && $hasobservations) {
            $policy .= "\n- CRITICAL (reasoning step with observations): OBSERVATION blocks contain results from prior steps."
                . " When observations are available:\n"
                . "  1. FIRST check if observations contain diagnostic, search, or factual results "
                . "relevant to the user's request.\n"
                . "  2. IF YES and the user's primary intent is read-only: return response_type=sufficient "
                . "with commands=[] and optional message field. "
                . "Do NOT summarize or interpret observations. "
                . "Synthesis (next step) will compose the final user-facing answer.\n"
                . "  3. IF the user's primary intent is a mutation, a read-only or documentation observation "
                . "does not complete that mutation by itself. Use the TASK CATALOG to either return "
                . "confirmation_request for a grounded mutation command, or clarification for missing "
                . "required mutation fields.\n"
                . "  4. IF NO: return response_type=clarification with explanation of what is missing.\n"
                . "  5. NEVER re-call the same command signature "
                . "(task + normalized input) if it already exists in OBSERVATION blocks.\n"
                . "  6. Re-calling the same task with DIFFERENT grounded input may be valid.\n"
                . "  7. Re-calling a command signature whose result already exists is a PROTOCOL VIOLATION.";
        }

        return $policy;
    }

    /**
     * Build NON-OPTIONAL FOLLOW-UP STATE POLICY (FINAL_REASONING only).
     *
     * @return string
     */
    public static function build_follow_up_state_policy(): string {
        return "FOLLOW-UP STATE POLICY:\n"
            . "- Use ASSISTANT_STATE blocks as factual memory for follow-up questions.\n"
            . "- Prefer structured state facts over generic restatements.\n"
            . "- If ASSISTANT_STATE already contains diagnosis/results, "
            . "answer directly from it before proposing new tool calls.\n"
            . "- If ASSISTANT_STATE contains a 'found_results' line, those items were already found "
            . "in a previous turn — include their names/details in your response.";
    }
}
