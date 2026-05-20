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
 * Loop finalizer service.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use core_text;

/**
 * Encapsulates finalization decision/message generation for execution_result steps.
 */
class loop_finalizer {
    /**
     * Return finalized clarification payload when execution_result is sufficient.
     *
     * @param array $result
     * @param agent_state $state
     * @param int $maxloopsteps
     * @param callable $extractsteptasknames fn(array $commands, array $results): array
     * @param callable $localizedstring fn(string $identifier, string $component, ?object $a, string $lang): string
     * @param callable $buildlooprepeatsummary fn(array $results, string $currentmessage): string
     * @return array|null
     */
    public function finalize(
        array $result,
        agent_state $state,
        int $maxloopsteps,
        callable $extractsteptasknames,
        callable $localizedstring,
        callable $buildlooprepeatsummary
    ): ?array {
        if (!$this->should_finalize_after_execution_result($result, $state, $extractsteptasknames)) {
            return null;
        }

        return $this->build_sufficient_execution_result_clarification(
            $result,
            $state,
            $maxloopsteps,
            $extractsteptasknames,
            $localizedstring,
            $buildlooprepeatsummary
        );
    }

    /**
     * Decide whether current readonly execution step should finalize the loop.
     *
     * @param array $result
     * @param agent_state $state
     * @param callable $extractsteptasknames
     * @return bool
     */
    private function should_finalize_after_execution_result(
        array $result,
        agent_state $state,
        callable $extractsteptasknames
    ): bool {
        if ((string)($result['response_type'] ?? '') !== 'execution_result') {
            return false;
        }

        $results = (array)($result['results'] ?? []);
        if (empty($results)) {
            return false;
        }

        $commands = (array)($result['commands'] ?? []);
        $tasks = $extractsteptasknames($commands, $results);
        if ($state->step_count() < 2) {
            return false;
        }

        $message = trim((string)($result['message'] ?? ''));
        $enriched = $this->maybe_enrich_message_from_results($message, $results);

        if ($this->is_low_information_message($enriched)) {
            return false;
        }

        $isdocsexplain = in_array('booking.explain_docs_topic', $tasks, true);
        if ($isdocsexplain) {
            foreach ($results as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $selectedpath = trim((string)($entry['selected_doc_path'] ?? ''));
                if ($selectedpath !== '') {
                    return true;
                }
                if (!empty((array)($entry['docs'] ?? []))) {
                    return true;
                }
            }
        }

        return strlen($enriched) >= 120;
    }

    /**
     * Build deterministic clarification payload from informative execution result.
     *
     * @param array $result
     * @param agent_state $state
     * @param int $maxloopsteps
     * @param callable $extractsteptasknames
     * @param callable $localizedstring
     * @param callable $buildlooprepeatsummary
     * @return array
     */
    private function build_sufficient_execution_result_clarification(
        array $result,
        agent_state $state,
        int $maxloopsteps,
        callable $extractsteptasknames,
        callable $localizedstring,
        callable $buildlooprepeatsummary
    ): array {
        $results = (array)($result['results'] ?? []);
        $message = trim((string)($result['message'] ?? ''));
        $message = $this->maybe_enrich_message_from_results($message, $results);

        if ($message === '' || $this->is_low_information_message($message)) {
            $message = (string)$buildlooprepeatsummary($results, $message);
        }

        if ($message === '' || $this->is_low_information_message($message)) {
            $message = (string)$localizedstring('ai_run_executed', 'mod_booking', null, (string)($result['lang'] ?? ''));
            if ($message === 'ai_run_executed') {
                $message = 'I found enough information to answer your question.';
            }
        }

        $attemptedtasks = $extractsteptasknames((array)($result['commands'] ?? []), $results);

        return [
            'response_type'             => 'clarification',
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => $attemptedtasks,
            'issue_codes'               => array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['LOOP_EARLY_SUFFICIENT_CONTEXT']
            ))),
            'pending_confirmation_code' => '',
            'used_triggers'             => (array)($result['used_triggers'] ?? []),
            'runid'                     => (int)($result['runid'] ?? 0),
            'results'                   => [],
            'lang'                      => (string)($result['lang'] ?? ''),
            'loop_step'                 => $state->step_count(),
            'loop_max_steps'            => $maxloopsteps,
        ];
    }

    /**
     * Enrich short low-information messages with deterministic result summary.
     *
     * @param string $message
     * @param array $results
     * @return string
     */
    private function maybe_enrich_message_from_results(string $message, array $results): string {
        $message = trim($message);
        if ($message !== '' && (strlen($message) > 200 || str_contains($message, "\n"))) {
            return $message;
        }

        $summary = '';
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $candidate = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? $entry['summary'] ?? ''));
            if ($candidate === '') {
                $candidate = result_payload_summarizer::describe_entry($entry, 0, 'client_fallback');
            }
            if ($candidate !== '') {
                $summary = $candidate;
                break;
            }
        }

        if ($summary === '') {
            return $message;
        }

        $messagelower = core_text::strtolower($message);
        $summarylower = core_text::strtolower($summary);
        $token = core_text::substr($summarylower, 0, 20);
        if ($token !== '' && strpos($messagelower, $token) !== false) {
            return $message;
        }

        return $message !== '' ? $message . ' ' . $summary : $summary;
    }

    /**
     * Detect generic low-information status messages.
     *
     * @param string $message
     * @return bool
     */
    private function is_low_information_message(string $message): bool {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return true;
        }
        if (strlen($trimmed) > 180 || str_contains($trimmed, "\n")) {
            return false;
        }

        $normalized = core_text::strtolower($trimmed);
        $markers = [
            'i have checked',
            'i checked',
            'checked your booking situation',
            'checked the situation',
        ];
        foreach ($markers as $marker) {
            if (strpos($normalized, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
