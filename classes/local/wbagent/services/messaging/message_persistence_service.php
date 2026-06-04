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
 * Message persistence service for assistant messages.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services\messaging;

use bookingextension_agent\local\wbagent\conversation_store;

/**
 * Persists normalized assistant payloads to conversation storage.
 */
class message_persistence_service {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Persist one assistant message payload.
     *
     * @param int $threadid
     * @param array $result
     * @return void
     */
    public function persist_assistant_message(int $threadid, array $result): void {
        $structured = [
            'response_type'            => $result['response_type'],
            'runid'                    => $result['runid'] ?? 0,
            'used_triggers'            => $result['used_triggers'] ?? [],
            'commands'                 => $result['commands'] ?? [],
            'ambiguities'              => $result['ambiguities'] ?? [],
            'ambiguity_options'        => $result['ambiguity_options'] ?? [],
            'errors'                   => $result['errors'] ?? [],
            'attempted_tasks'          => $result['attempted_tasks'] ?? [],
            'issue_codes'              => $result['issue_codes'] ?? [],
            'pending_confirmation_code' => $result['pending_confirmation_code'] ?? '',
            'results'                  => $result['results'] ?? [],
            'loop_results'             => $result['loop_results'] ?? [],
            'loop_step'                => $result['loop_step'] ?? 0,
            'loop_max_steps'           => $result['loop_max_steps'] ?? 0,
            'lang'                     => $result['lang'] ?? '',
        ];

        $normalizedphasetrace = [];
        if (isset($result['phase_trace']) && is_array($result['phase_trace'])) {
            $normalizedphasetrace = $this->normalize_phase_trace((array)$result['phase_trace']);
            $structured['phase_trace'] = $normalizedphasetrace;
        }
        if (isset($result['planner_result']) && is_array($result['planner_result'])) {
            $structured['planner_result'] = $result['planner_result'];
            $plannertracehistory = (array)($result['planner_result']['planner_trace_history'] ?? []);
            $normalizedhistory = [];
            foreach ($plannertracehistory as $entry) {
                $value = trim((string)$entry);
                if ($value !== '') {
                    $normalizedhistory[] = $value;
                }
            }
            $this->store->set_planner_trace_history($threadid, $normalizedhistory);
        }
        $nextstepintent = trim((string)($result['next_step_intent'] ?? ''));
        $this->store->set_thread_metadata_value($threadid, 'next_step_intent', $nextstepintent);
        if (!empty($normalizedphasetrace)) {
            $this->store->set_phase_trace($threadid, $normalizedphasetrace);
        }

        $this->store->add_message($threadid, 'assistant', $result['message'] ?? '', $structured);
    }

    /**
     * Normalize phase-trace payload to canonical phase keys.
     *
     * @param array<string,mixed> $phasetrace
     * @return array<string,array<string,mixed>>
     */
    private function normalize_phase_trace(array $phasetrace): array {
        $normalized = [
            'discovery' => [],
            'selection' => [],
            'parameter_construction' => [],
        ];

        if (isset($phasetrace['discovery']) && is_array($phasetrace['discovery'])) {
            $normalized['discovery'] = $phasetrace['discovery'];
        }
        if (isset($phasetrace['selection']) && is_array($phasetrace['selection'])) {
            $normalized['selection'] = $phasetrace['selection'];
        }
        if (isset($phasetrace['parameter_construction']) && is_array($phasetrace['parameter_construction'])) {
            $normalized['parameter_construction'] = $phasetrace['parameter_construction'];
        }

        foreach ($phasetrace as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $phase = trim((string)($entry['phase'] ?? ''));
            if ($phase !== '' && isset($normalized[$phase]) && empty($normalized[$phase])) {
                $normalized[$phase] = $entry;
            }
        }

        return $normalized;
    }
}
