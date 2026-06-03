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

use bookingextension_agent\local\wbagent\result_payload_summarizer;
use bookingextension_agent\local\wbagent\task_registry;
use core_text;

/**
 * Builds assistant state summaries and contextual guidance blocks for prompts.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assistant_state_guidance_service {
    /** @var task_registry */
    private task_registry $registry;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     */
    public function __construct(task_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Build compact structured state blocks from recent assistant messages.
     *
     * @param array $messages
     * @return array<int,string>
     */
    public function build_assistant_state_blocks(array $messages): array {
        $states = [];

        foreach ($messages as $msg) {
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured) || empty($structured)) {
                continue;
            }

            $summary = $this->summarize_structured_state($structured);
            if ($summary !== '') {
                $states[] = $summary;
            }
        }

        if (count($states) > 6) {
            $states = array_slice($states, -6);
        }

        return $states;
    }

    /**
     * Build extra guidance only when specific topics appear in recent messages.
     *
     * @param array $messages
     * @return string
     */
    public function build_contextual_guidance(array $messages): string {
        $joined = '';
        foreach ($messages as $msg) {
            $joined .= "\n" . (string)($msg->content ?? '');
        }
        $joined = core_text::strtolower($joined);

        $guidancelines = [];
        $packs = $this->registry->get_contextual_prompt_packs();
        foreach ($packs as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            if (!$this->matches_contextual_pack($pack, $joined)) {
                continue;
            }

            $lines = $pack['guidance'] ?? [];
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $guidancelines[] = $line;
                }
            }
        }

        if (empty($guidancelines)) {
            return '';
        }

        return implode("\n", array_values(array_unique($guidancelines)));
    }

    /**
     * Normalize an arbitrary list into non-empty trimmed strings.
     *
     * @param array<int,mixed> $values
     * @param int $maxitems
     * @param int $maxchars
     * @return array<int,string>
     */
    public function normalize_nonempty_string_list(array $values, int $maxitems = 0, int $maxchars = 0): array {
        if ($maxitems > 0) {
            $values = array_slice($values, 0, $maxitems);
        }

        $normalized = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            if ($maxchars > 0) {
                $text = (string)core_text::substr($text, 0, $maxchars);
            }
            $normalized[] = $text;
        }

        return array_values($normalized);
    }

    /**
     * Summarize one structured assistant payload into deterministic state lines.
     *
     * @param array $structured
     * @return string
     */
    private function summarize_structured_state(array $structured): string {
        $lines = [];

        $responsetype = trim((string)($structured['response_type'] ?? ''));
        if ($responsetype !== '') {
            $lines[] = 'response_type=' . $responsetype;
        }

        $lang = trim((string)($structured['lang'] ?? ''));
        if ($lang !== '') {
            $lines[] = 'lang=' . $lang;
        }

        $issuecodes = $this->normalize_nonempty_string_list((array)($structured['issue_codes'] ?? []));
        if (!empty($issuecodes)) {
            $lines[] = 'issue_codes=' . implode(',', array_slice($issuecodes, 0, 8));
        }

        $attemptedtasks = $this->normalize_nonempty_string_list((array)($structured['attempted_tasks'] ?? []));
        if (!empty($attemptedtasks)) {
            $lines[] = 'attempted_tasks=' . implode(',', array_slice($attemptedtasks, 0, 8));
        }

        $results = (array)($structured['results'] ?? []);
        if (empty($results)) {
            $results = (array)($structured['loop_results'] ?? []);
        }
        foreach ($this->extract_result_facts($results) as $fact) {
            $lines[] = $fact;
        }

        return implode("\n", array_slice($lines, 0, 12));
    }

    /**
     * Extract compact factual lines from structured task results.
     *
     * @param array $results
     * @return array<int,string>
     */
    private function extract_result_facts(array $results): array {
        $facts = [];
        if (empty($results)) {
            return $facts;
        }

        for ($i = count($results) - 1; $i >= 0; $i--) {
            $entry = $results[$i] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            $task = trim((string)($entry['task'] ?? ''));
            $status = trim((string)($entry['status'] ?? ''));
            if ($task !== '' || $status !== '') {
                $facts[] = trim('result=' . $task . ' status=' . $status);
            }

            $diagnosis = $entry['diagnosis'] ?? null;
            if (is_array($diagnosis)) {
                $option = trim((string)($diagnosis['optionname'] ?? ''));
                $userstatus = trim((string)($diagnosis['userstatus'] ?? ''));
                $facts[] = trim('diagnosis option=' . $option . ' user_status=' . $userstatus);

                $reasons = $this->normalize_nonempty_string_list((array)($diagnosis['reasons'] ?? []));
                if (!empty($reasons)) {
                    $facts[] = 'diagnosis_reasons=' . implode(' | ', array_slice($reasons, 0, 3));
                }
            }

            $resultsummary = result_payload_summarizer::describe_result_for_state($entry);
            if ($resultsummary !== '') {
                $facts[] = 'found_results=' . $resultsummary;
            }

            $usermessage = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
            if ($usermessage !== '') {
                $usermessage = trim(preg_replace('/\s+/', ' ', $usermessage) ?? $usermessage);
                $facts[] = 'result_message=' . core_text::substr($usermessage, 0, 220);
            }

            if (count($facts) >= 12) {
                break;
            }
        }

        return array_slice(array_values(array_unique(array_filter($facts))), 0, 12);
    }

    /**
     * Check whether a contextual prompt pack matches current message context.
     *
     * @param array $pack
     * @param string $joined
     * @return bool
     */
    private function matches_contextual_pack(array $pack, string $joined): bool {
        $triggers = $pack['triggers'] ?? [];
        if (!is_array($triggers) || empty($triggers)) {
            return false;
        }

        foreach ($triggers as $trigger) {
            $needle = core_text::strtolower(trim((string)$trigger));
            if ($needle === '') {
                continue;
            }

            if (preg_match('/[\s_\-]/', $needle)) {
                if (strpos($joined, $needle) !== false) {
                    return true;
                }
                continue;
            }

            $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
            if ((bool)preg_match($pattern, $joined)) {
                return true;
            }
        }

        return false;
    }
}
