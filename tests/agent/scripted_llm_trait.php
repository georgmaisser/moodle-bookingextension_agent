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
 * Scripted-LLM test harness: drive run_loop deterministically without a live provider.
 *
 * Installs a scripted responder on llm_call_service so the discovery/selector/constructor/
 * synchronizer phases return pre-programmed content. This closes the biggest test-fidelity gap
 * (the real chat entry ai_send_message -> run_loop had NO deterministic driver, so the whole
 * mutating chat turn and the confirm re-entrancy were only exercisable with a stochastic,
 * CI-skipped real LLM). See DAY_AUDIT_2026-07-09_COMMITS_VS_THREADS.md, test-fidelity section.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\wb_action_names;

/**
 * Reusable scripted-planner installer for deterministic run_loop tests.
 */
trait scripted_llm_trait {
    /** @var string[] FIFO of raw planner_decide responses (selector, constructor, selector, ...). */
    private array $scriptedplannerqueue = [];

    /** @var string[] Every planner_decide prompt, in call order (for prompt-contract assertions). */
    protected array $scriptedplannerprompts = [];

    /**
     * Install a scripted planner. Planner (planner_decide) calls consume $plannerscript in order;
     * once exhausted they fall back to a terminal 'sufficient' so the loop always converges. The
     * synchronizer (generate_agent_reply) and any generate_text call return a 'sufficient' reply.
     * Discovery embeddings return a fixed vector (inert — the selector output is scripted).
     *
     * @param string[] $plannerscript Raw JSON strings, one per planner_decide call, in call order.
     * @param string $finalmessage User-facing message returned for terminal/synchronizer calls.
     * @return void
     */
    protected function install_scripted_planner(array $plannerscript, string $finalmessage = 'Done.'): void {
        $this->scriptedplannerqueue = array_values(array_map('strval', $plannerscript));

        $sufficient = json_encode([
            'response_type' => 'sufficient',
            'message' => $finalmessage,
            'commands' => [],
            'user_lang' => 'en',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        llm_call_service::set_test_responder(function (string $actionclass, string $prompt) use ($sufficient): string {
            if ($actionclass === wb_action_names::PLANNER_DECIDE) {
                // Record the prompt so tests can assert prompt contracts (e.g. the
                // pending-step block the selector was shown — thread 589 regression).
                $this->scriptedplannerprompts[] = $prompt;
                if (!empty($this->scriptedplannerqueue)) {
                    return (string)array_shift($this->scriptedplannerqueue);
                }
                return $sufficient;
            }
            // The generate_agent_reply (synchronizer), generate_text and summarise_text calls.
            return $sufficient;
        });

        // A tiny fixed vector; discovery still runs, but the scripted selector ignores its result.
        llm_call_service::set_test_embedding(array_fill(0, 8, 0.01));
    }

    /**
     * Remove the scripted planner (call in tearDown, always safe even if never installed).
     *
     * @return void
     */
    protected function clear_scripted_planner(): void {
        llm_call_service::set_test_responder(null);
        llm_call_service::set_test_embedding(null);
        $this->scriptedplannerqueue = [];
        $this->scriptedplannerprompts = [];
    }

    /**
     * Convenience: a selector 'skill_call' for one skill (constructor stage follows).
     *
     * @param string $skill
     * @param array $plannedsteps Optional planned-step intents for a multi-step series.
     * @param string $nextstepintent
     * @return string
     */
    protected function selector_skill_call(
        string $skill,
        array $plannedsteps = [],
        string $nextstepintent = 'next'
    ): string {
        return json_encode([
            'response_type' => 'skill_call',
            'commands' => [['skill' => $skill, 'input' => []]],
            'planned_steps' => array_map(static fn($i): array => ['intent' => (string)$i], $plannedsteps),
            'next_step_intent' => $nextstepintent,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convenience: a constructor 'confirmation_request' carrying one mutating command.
     *
     * @param string $skill
     * @param array $parameters
     * @param string $message
     * @return string
     */
    protected function constructor_confirmation_request(
        string $skill,
        array $parameters,
        string $message = 'Please confirm.'
    ): string {
        return json_encode([
            'response_type' => 'confirmation_request',
            'message' => $message,
            'next_step_intent' => '',
            'lang' => 'en',
            'user_lang' => 'en',
            'commands' => [['skill' => $skill, 'version' => 1, 'parameters' => $parameters]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
