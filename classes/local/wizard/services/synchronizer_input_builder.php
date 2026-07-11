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

use bookingextension_agent\local\wizard\agent_state;

/**
 * Builds synchronizer input from runtime result and loop state.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_input_builder {
    /**
     * Build the observation list for synchronizer finalization.
     *
     * @param array $result
     * @param agent_state|null $state
     * @return array
     */
    public function build_observations(array $result, ?agent_state $state = null): array {
        $observations = [];

        if ($state !== null && $state->has_observations()) {
            $observations = $state->get_observations();
        } else {
            $loopresults = (array)($result['loop_results'] ?? []);
            foreach ($loopresults as $step) {
                if (!is_array($step)) {
                    continue;
                }

                $observation = trim((string)($step['observation'] ?? ''));
                if ($observation !== '') {
                    $observations[] = $observation;
                }
            }
        }

        $sourceobservation = $this->build_source_observation($result);
        if ($sourceobservation !== '') {
            $observations[] = $sourceobservation;
        }

        $phasetraceobservation = $this->build_phase_trace_observation($result);
        if ($phasetraceobservation !== '') {
            $observations[] = $phasetraceobservation;
        }

        $executionfeedbackobservation = $this->build_execution_feedback_observation($result);
        if ($executionfeedbackobservation !== '') {
            $observations[] = $executionfeedbackobservation;
        }

        $errorobservation = $this->build_error_observation($result);
        if ($errorobservation !== '') {
            $observations[] = $errorobservation;
        }

        return $observations;
    }

    /**
     * Build the structured error observation for error presentation.
     *
     * The synchronizer presents errors like any other answer (user language,
     * conversational, sensible next step) — provided it knows the actual cause.
     * This block carries the classified cause (error_class, issue codes, raw
     * errors, failed result details) plus a non-negotiable presentation
     * instruction: never blame the AI provider for non-provider causes, never
     * invent causes, never claim success.
     *
     * @param array $result
     * @return string empty when the result is not an error
     */
    private function build_error_observation(array $result): string {
        if (trim((string)($result['response_type'] ?? '')) !== 'error') {
            return '';
        }

        $issuecodes = array_values(array_filter(array_map('strval', (array)($result['issue_codes'] ?? []))));
        $errorclass = trim((string)($result['error_class'] ?? ''));

        // F3 user_cause channel: causes are the only cause text the synchronizer may explain
        // to the user, so planner vocabulary stays out — the "Command #N:" label is planner
        // orientation and is stripped here (it lives on in the phase trace and retry
        // observations), and failed execute rows prefer their usermessage over the internal
        // detail. repair_hints never enter this block by construction.
        $causes = [];
        foreach ((array)($result['errors'] ?? []) as $error) {
            $error = trim((string)preg_replace('/^\s*Command\s*#\d+\s*:\s*/i', '', (string)$error));
            if ($error !== '') {
                $causes[] = $error;
            }
        }
        foreach ((array)($result['results'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = trim((string)($entry['status'] ?? ''));
            $usercause = trim((string)($entry['usermessage'] ?? ''));
            if ($usercause === '') {
                $usercause = trim((string)($entry['detail'] ?? ''));
            }
            if (in_array($status, ['error', 'failed'], true) && $usercause !== '') {
                $causes[] = $usercause;
            }
        }

        // A task blocked because it needs a PRO license or an active Wunderbyte subscription is NOT a
        // malfunction. State the neutral fact only — how to present the upgrade (link, CTA wording) is
        // owned by the synchronizer's conditional PRO_LICENSE_POLICY, which fires only without full access.
        if (!empty($issuecodes) && in_array('REQUIRES_PRO', $issuecodes, true)) {
            return '[UPGRADE_REQUIRED] This task is not available because it requires the Wunderbyte PRO '
                . 'license or an active Wunderbyte subscription. This is NOT an error, bug or malfunction.';
        }

        // A pure governance availability denial is NOT a malfunction: the skill is either not
        // enabled on this system or the user lacks the capability for it. It still travels as
        // response_type=error (so the finalization_classifier humanizes it via the synchronizer,
        // per the flowchart's "safe domain error" path), but the observation must frame it as a
        // neutral availability notice — otherwise the reply calls it an internal error.
        if (!empty($issuecodes) && array_values(array_unique($issuecodes)) === ['SKILL_DENIED']) {
            $lines = [];
            $lines[] = '[UNAVAILABLE] The requested capability is not available to this user in this '
                . 'session. This is NOT an error, bug or malfunction — the skill is either not enabled '
                . 'on this system or the user lacks permission for it.';
            if (!empty($causes)) {
                $lines[] = 'reason: ' . implode(' | ', array_unique($causes));
            }
            $lines[] = 'Rules: State plainly and neutrally, in the user\'s language, that this capability '
                . 'is currently not available to them (use the reason above: not enabled, or missing '
                . 'permission). Do NOT call it an internal error, do NOT apologize for a malfunction, do '
                . 'NOT suggest reloading or waiting, do NOT invent other causes. Keep it short and factual.';
            return implode("\n", $lines);
        }

        $lines = [];
        $lines[] = '[ERROR] The request FAILED. Compose an honest error reply in the user\'s language:';
        if ($errorclass !== '') {
            $lines[] = 'error_class: ' . $errorclass;
        }
        if (!empty($issuecodes)) {
            $lines[] = 'issue_codes: ' . implode(', ', $issuecodes);
        }
        if (!empty($causes)) {
            $lines[] = 'causes: ' . implode(' | ', array_unique($causes));
        }

        $lines[] = 'Rules: explain the cause above and a sensible next step. Do NOT blame the AI provider '
            . 'unless error_class names it. Do NOT invent other causes. Do NOT claim the request succeeded. '
            . 'Do NOT announce that you will now perform, retry or continue the action — nothing more runs '
            . 'after this reply. Speak only about what already happened and what the USER can do next.';

        return implode("\n", $lines);
    }

    /**
     * Build a normalized phase trace observation for synchronization context.
     *
     * @param array $result
     * @return string
     */
    private function build_phase_trace_observation(array $result): string {
        $phasetrace = (array)($result['phase_trace'] ?? []);
        if (empty($phasetrace)) {
            return '';
        }

        $payload = [
            'discovery' => $this->sanitize_phase_trace_snapshot((array)($phasetrace['discovery'] ?? [])),
            'selection' => $this->sanitize_phase_trace_snapshot((array)($phasetrace['selection'] ?? [])),
            'parameter_construction' => $this->sanitize_phase_trace_snapshot(
                (array)($phasetrace['parameter_construction'] ?? [])
            ),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        return 'PHASE_TRACE' . "\n" . $json;
    }

    /**
     * Keep only minimal phase telemetry and exclude skill-discovery payloads.
     *
     * @param array $snapshot
     * @return array
     */
    private function sanitize_phase_trace_snapshot(array $snapshot): array {
        return [
            'phase' => trim((string)($snapshot['phase'] ?? '')),
            'response_type' => trim((string)($snapshot['response_type'] ?? '')),
            'issue_codes' => issue_code_normalizer::normalize((array)($snapshot['issue_codes'] ?? [])),
            'errors' => $this->normalize_nonempty_string_list((array)($snapshot['errors'] ?? [])),
        ];
    }

    /**
     * Build compact execution feedback observation for synchronizer prompts.
     *
     * @param array $result
     * @return string
     */
    private function build_execution_feedback_observation(array $result): string {
        $results = (array)($result['results'] ?? []);
        if (empty($results)) {
            return '';
        }

        $statuscounts = [];
        $skills = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = trim((string)($row['status'] ?? 'unknown'));
            if ($status === '') {
                $status = 'unknown';
            }
            $statuscounts[$status] = (int)($statuscounts[$status] ?? 0) + 1;

            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill !== '') {
                $skills[] = $skill;
            }
        }

        if (empty($statuscounts) && empty($skills)) {
            return '';
        }

        $payload = [
            'result_count' => count($results),
            'status_counts' => $statuscounts,
            'skills' => array_values(array_unique($skills)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        return 'EXECUTION_FEEDBACK' . "\n" . $json;
    }

    /**
     * Build a compact source result observation for finalization.
     *
     * @param array $result
     * @return string
     */
    private function build_source_observation(array $result): string {
        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            return '';
        }

        $responsetype = trim((string)($result['response_type'] ?? ''));
        $issuecodes = issue_code_normalizer::normalize((array)($result['issue_codes'] ?? []));
        $attemptedskills = $this->normalize_nonempty_string_list((array)($result['attempted_skills'] ?? []));

        $lines = ['FINAL_SOURCE_RESULT'];
        if ($responsetype !== '') {
            $lines[] = 'response_type=' . $responsetype;
        }
        if (!empty($issuecodes)) {
            $lines[] = 'issue_codes=' . implode(',', array_slice($issuecodes, 0, 8));
        }
        if (!empty($attemptedskills)) {
            $lines[] = 'attempted_skills=' . implode(',', array_slice($attemptedskills, 0, 8));
        }

        $normalizedmessage = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $lines[] = 'message=' . substr($normalizedmessage, 0, 600);

        return implode("\n", $lines);
    }

    /**
     * Normalize a list to non-empty strings.
     *
     * @param array $values
     * @return array
     */
    private function normalize_nonempty_string_list(array $values): array {
        $normalized = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values(array_unique($normalized));
    }
}
