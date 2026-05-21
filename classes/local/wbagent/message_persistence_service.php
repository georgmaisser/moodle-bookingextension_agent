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

namespace bookingextension_agent\local\wbagent;

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
        $this->store->add_message($threadid, 'assistant', $result['message'] ?? '', [
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
        ]);
    }
}
