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
 * DB-backed conversation store.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\interfaces\agent_conversation_store;
use stdClass;

/**
 * Persists agent conversation threads, messages, and runs in the Moodle DB.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_store implements agent_conversation_store {
    /** Default pending intent TTL in seconds. */
    private const PENDING_INTENT_TTL = 900;

    /** Preference key that stores session allowlist entries. */
    private const CONFIRMATION_SESSION_ALLOWLIST_KEY = 'bookingextension_agent_ai_confirmation_session_allowlist';

    /** Default lifetime for a confirmation allowlist entry in seconds (12h). */
    private const CONFIRMATION_SESSION_ALLOWLIST_TTL = 43200;

    /**
     * Get the active thread for a user and contextid.
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass|null
     */
    public function get_active_thread(int $userid, int $contextid): ?stdClass {
        global $DB;

        $thread = $DB->get_record('local_wbagent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);

        return $thread ?: null;
    }

    /**
     * Get or create an active thread for the given user and booking context.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $bookingid
     * @return stdClass Thread record.
     */
    public function get_or_create_thread(int $userid, int $contextid, int $bookingid): stdClass {
        global $DB;

        $thread = $DB->get_record('local_wbagent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);

        if ($thread) {
            return $thread;
        }

        $now = time();
        $record = new stdClass();
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->bookingid = $bookingid;
        $record->status = 'active';
        $record->metadatajson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('local_wbagent_ai_threads', $record);

        return $record;
    }

    /**
     * Archive existing active threads for this user/context and create a fresh thread.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $bookingid
     * @return stdClass
     */
    public function create_fresh_thread(int $userid, int $contextid, int $bookingid): stdClass {
        global $DB;

        $now = time();
        $activethreads = $DB->get_records('local_wbagent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);

        foreach ($activethreads as $thread) {
            $update = new stdClass();
            $update->id = (int)$thread->id;
            $update->status = 'archived';
            $update->timemodified = $now;
            $DB->update_record('local_wbagent_ai_threads', $update);
        }

        $record = new stdClass();
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->bookingid = $bookingid;
        $record->status = 'active';
        $record->metadatajson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('local_wbagent_ai_threads', $record);

        return $record;
    }

    /**
     * Append a message to the thread.
     *
     * @param int    $threadid
     * @param string $role   'user' | 'assistant' | 'system'
     * @param string $content  Raw text content.
     * @param array  $structured Optional structured state.
     * @return int  New message id.
     */
    public function add_message(int $threadid, string $role, string $content, array $structured = []): int {
        global $DB;

        $thread = $DB->get_record('local_wbagent_ai_threads', ['id' => $threadid], 'id, userid', IGNORE_MISSING);
        if (!$thread) {
            throw new \coding_exception('Cannot add message to unknown thread id: ' . $threadid);
        }

        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = (int)$thread->userid;
        $record->role = $role;
        $record->content = $content;
        $record->structuredjson = !empty($structured) ? json_encode($structured) : null;
        $record->timecreated = time();

        return (int) $DB->insert_record('local_wbagent_ai_messages', $record);
    }

    /**
     * Write an ephemeral step-progress label to the thread.
     *
     * These messages (role='step') are visible to the polling frontend during
     * the agent loop and are filtered out of the normal conversation history.
     *
     * @param int    $threadid
     * @param int    $stepnum   1-based step counter.
     * @param string $label     Short human-readable label ("Step 1: provider.task_name").
     * @param string $task      Raw task name for frontend icon selection.
     * @return int New message id.
     */
    public function add_step_message(int $threadid, int $stepnum, string $label, string $task = ''): int {
        return $this->add_message($threadid, 'step', $label, [
            'stepnum' => $stepnum,
            'label'   => $label,
            'task'    => $task,
        ]);
    }

    /**
     * Delete all step messages for a thread.
     *
     * Called at the start of every turn so stale progress bubbles from prior
     * requests cannot reappear.
     *
     * @param int $threadid
     * @return void
     */
    public function clear_step_messages(int $threadid): void {
        global $DB;
        $DB->delete_records('local_wbagent_ai_messages', ['threadid' => $threadid, 'role' => 'step']);
    }

    /**
     * Return step messages written after a given message id.
     *
     * Used by the frontend step-polling endpoint to fetch only new steps.
     *
     * @param int $threadid
     * @param int $sinceid  Return only messages with id > $sinceid (0 = all).
     * @return stdClass[]
     */
    public function get_step_messages_since(int $threadid, int $sinceid): array {
        global $DB;
        $sql = 'SELECT * FROM {local_wbagent_ai_messages}
                 WHERE threadid = :threadid
                   AND role     = :role
                   AND id       > :sinceid
                 ORDER BY id ASC';
        return array_values($DB->get_records_sql($sql, [
            'threadid' => $threadid,
            'role'     => 'step',
            'sinceid'  => $sinceid,
        ]));
    }

    /**
     * Return all messages for a thread ordered by timecreated ASC.
     *
     * @param int $threadid
     * @return stdClass[]
     */
    public function get_messages(int $threadid): array {
        global $DB;
        return array_values($DB->get_records('local_wbagent_ai_messages', ['threadid' => $threadid], 'timecreated ASC'));
    }

    /**
     * Return one thread by id.
     *
     * @param int $threadid
     * @return stdClass|null
     */
    public function get_thread(int $threadid): ?stdClass {
        global $DB;
        $thread = $DB->get_record('local_wbagent_ai_threads', ['id' => $threadid]);
        return $thread ?: null;
    }

    /**
     * Return the most recent N messages (for prompt assembly).
     *
     * @param int $threadid
     * @param int $limit
     * @return stdClass[]
     */
    public function get_recent_messages(int $threadid, int $limit): array {
        global $DB;

        $sql = 'SELECT * FROM {local_wbagent_ai_messages}
                WHERE threadid = :threadid
                  AND role <> :steprole
                ORDER BY timecreated DESC, id DESC';
        $records = $DB->get_records_sql($sql, [
            'threadid' => $threadid,
            'steprole' => 'step',
        ], 0, $limit);
        // Return in chronological order.
        return array_reverse(array_values($records));
    }

    /**
     * Return the previous thread for a user in this booking instance.
     *
     * Preference order:
     * 1) latest archived thread
     * 2) latest non-active thread
     * 3) latest thread excluding the active one
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass|null
     */
    public function get_last_thread_for_user(int $userid, int $contextid): ?stdClass {
        global $DB;

        $activethread = $DB->get_record('local_wbagent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ], '*', IGNORE_MISSING);
        $activeid = (int)($activethread->id ?? 0);

        $sql = 'SELECT *
                  FROM {local_wbagent_ai_threads}
                 WHERE userid = :userid
                   AND contextid = :contextid
                   AND status = :status
              ORDER BY timemodified DESC, id DESC';
        $archived = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'archived',
        ], 0, 1);
        if (!empty($archived)) {
            $thread = reset($archived);
            return $thread ?: null;
        }

        $sql = 'SELECT *
                  FROM {local_wbagent_ai_threads}
                 WHERE userid = :userid
                   AND contextid = :contextid
                   AND status <> :status
              ORDER BY timemodified DESC, id DESC';
        $nonactive = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ], 0, 1);
        if (!empty($nonactive)) {
            $thread = reset($nonactive);
            return $thread ?: null;
        }

        $params = [
            'userid' => $userid,
            'contextid' => $contextid,
        ];
        $excludewhere = '';
        if ($activeid > 0) {
            $excludewhere = ' AND id <> :activeid';
            $params['activeid'] = $activeid;
        }
        $sql = 'SELECT *
                  FROM {local_wbagent_ai_threads}
                 WHERE userid = :userid
                   AND contextid = :contextid'
                . $excludewhere
                . ' ORDER BY timemodified DESC, id DESC';
        $threads = $DB->get_records_sql($sql, $params, 0, 1);
        $thread = reset($threads);
        return $thread ?: null;
    }

    /**
     * Return user-owned thread ids with messages in the given time window.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $fromtimestamp
     * @param int $totimestamp
     * @return int[]
     */
    public function get_user_threads_by_date_window(
        int $userid,
        int $contextid,
        int $fromtimestamp,
        int $totimestamp
    ): array {
        global $DB;

        $sql = 'SELECT DISTINCT t.id
                  FROM {local_wbagent_ai_threads} t
                  JOIN {local_wbagent_ai_messages} m
                    ON m.threadid = t.id
                 WHERE t.userid = :userid
                   AND m.userid = :userid2
                   AND t.contextid = :contextid
                   AND m.timecreated >= :fromts
                   AND m.timecreated <= :tots
              ORDER BY t.timemodified DESC, t.id DESC';
        $records = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'userid2' => $userid,
            'contextid' => $contextid,
            'fromts' => $fromtimestamp,
            'tots' => $totimestamp,
        ]);

        return array_values(array_map(static fn(stdClass $row): int => (int)$row->id, $records));
    }

    /**
     * Return messages for a user-owned thread with strict dual user fences.
     *
     * @param int $userid
     * @param int $threadid
     * @param int|null $fromtimestamp
     * @param int|null $totimestamp
     * @param string $query
     * @return stdClass[]
     */
    public function get_user_messages_for_thread(
        int $userid,
        int $threadid,
        ?int $fromtimestamp = null,
        ?int $totimestamp = null,
        string $query = ''
    ): array {
        global $DB;

        $thread = $DB->get_record('local_wbagent_ai_threads', ['id' => $threadid, 'userid' => $userid], 'id', IGNORE_MISSING);
        if (!$thread) {
            return [];
        }

        $params = [
            'threadid' => $threadid,
            'userid' => $userid,
            'userid2' => $userid,
            'steprole' => 'step',
        ];
        $where = 'm.threadid = :threadid
                  AND m.userid = :userid
                  AND t.userid = :userid2
                  AND m.role <> :steprole';

        if ($fromtimestamp !== null) {
            $where .= ' AND m.timecreated >= :fromts';
            $params['fromts'] = $fromtimestamp;
        }
        if ($totimestamp !== null) {
            $where .= ' AND m.timecreated <= :tots';
            $params['tots'] = $totimestamp;
        }

        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%';
            $where .= ' AND (
                ' . $DB->sql_like('LOWER(' . $DB->sql_cast_to_char('m.content') . ')', ':querylike', false) . '
                OR ' . $DB->sql_like('LOWER(' . $DB->sql_cast_to_char('m.structuredjson') . ')', ':querylikestruct', false) . '
            )';
            $params['querylike'] = $like;
            $params['querylikestruct'] = $like;
        }

        $sql = 'SELECT m.*
                  FROM {local_wbagent_ai_messages} m
                  JOIN {local_wbagent_ai_threads} t
                    ON t.id = m.threadid
                 WHERE ' . $where . '
              ORDER BY m.timecreated ASC, m.id ASC';
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Create a run record for the thread.
     *
     * @param int    $threadid
     * @param int    $userid
     * @param int    $contextid
     * @param string $idempotencykey
     * @param array  $commands   Interpreter-validated commands.
     * @return int   New run id.
     */
    public function create_run(int $threadid, int $userid, int $contextid, string $idempotencykey, array $commands): int {
        global $DB;

        $now = time();
        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->status = 'pending';
        $record->idempotencykey = $idempotencykey;
        $record->commandsjson = json_encode($commands);
        $record->resultsjson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return (int) $DB->insert_record('local_wbagent_ai_runs', $record);
    }

    /**
     * Update run status and optionally store results.
     *
     * @param int    $runid
     * @param string $status
     * @param array  $results Optional per-command results.
     * @return void
     */
    public function update_run_status(int $runid, string $status, array $results = []): void {
        global $DB;

        $record = new stdClass();
        $record->id = $runid;
        $record->status = $status;
        $record->timemodified = time();
        if (!empty($results)) {
            $record->resultsjson = json_encode($results);
        }

        $DB->update_record('local_wbagent_ai_runs', $record);
    }

    /**
     * Return a run record by id.
     *
     * @param int $runid
     * @return stdClass|null
     */
    public function get_run(int $runid): ?stdClass {
        global $DB;
        return $DB->get_record('local_wbagent_ai_runs', ['id' => $runid]) ?: null;
    }

    /**
     * Return the latest run for a thread.
     *
     * @param int $threadid
     * @return stdClass|null
     */
    public function get_latest_run(int $threadid): ?stdClass {
        global $DB;
        $records = $DB->get_records('local_wbagent_ai_runs', ['threadid' => $threadid], 'timecreated DESC', '*', 0, 1);
        $record = reset($records);
        return $record ?: null;
    }

    /**
     * Check whether a run with the given idempotency key already exists.
     *
     * @param string $idempotencykey
     * @return bool
     */
    public function run_exists(string $idempotencykey): bool {
        global $DB;
        return $DB->record_exists('local_wbagent_ai_runs', ['idempotencykey' => $idempotencykey]);
    }

    /**
     * Check whether a run with the given idempotency key exists excluding one run id.
     *
     * @param string $idempotencykey
     * @param int $runid
     * @return bool
     */
    public function run_exists_other_than(string $idempotencykey, int $runid): bool {
        global $DB;

        $sql = 'SELECT 1
                  FROM {local_wbagent_ai_runs}
                 WHERE idempotencykey = :idempotencykey
                   AND id <> :runid';

        return $DB->record_exists_sql($sql, [
            'idempotencykey' => $idempotencykey,
            'runid' => $runid,
        ]);
    }

    /**
     * Get a metadata value from a thread.
     *
     * @param int $threadid
     * @param string $key
     * @return mixed|null
     */
    public function get_thread_metadata_value(int $threadid, string $key) {
        global $DB;

        $thread = $DB->get_record('local_wbagent_ai_threads', ['id' => $threadid], 'id, metadatajson');
        if (!$thread) {
            return null;
        }

        $metadata = json_decode((string)($thread->metadatajson ?? ''), true);
        if (!is_array($metadata) || !array_key_exists($key, $metadata)) {
            return null;
        }

        return $metadata[$key];
    }

    /**
     * Set a metadata key on a thread.
     *
     * @param int $threadid
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set_thread_metadata_value(int $threadid, string $key, $value): void {
        global $DB;

        $thread = $DB->get_record('local_wbagent_ai_threads', ['id' => $threadid], 'id, metadatajson');
        if (!$thread) {
            return;
        }

        $metadata = json_decode((string)($thread->metadatajson ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata[$key] = $value;

        $update = new stdClass();
        $update->id = $threadid;
        $update->metadatajson = json_encode($metadata);
        $update->timemodified = time();
        $DB->update_record('local_wbagent_ai_threads', $update);
    }

    /**
     * Store pending confirmation intent for a thread.
     *
     * @param int $threadid
     * @param string $intentkey
     * @param int $userid
     * @param int $contextid
     * @param array $metadata
     * @param int $ttl
     * @return void
     */
    public function set_pending_intent(
        int $threadid,
        string $intentkey,
        int $userid = 0,
        int $contextid = 0,
        array $metadata = [],
        int $ttl = self::PENDING_INTENT_TTL
    ): void {
        $now = time();
        $confirmationcode = 'C' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $queueitemids = array_values(array_filter(array_map('strval', (array)($metadata['queue_item_ids'] ?? []))));
        $queueitemids = array_values(array_unique($queueitemids));

        $pendingintent = [
            'intentkey' => $intentkey,
            'checksum' => hash('sha256', json_encode($queueitemids)),
            'timestamp' => $now,
            'expiresat' => $now + max(1, $ttl),
            'state' => 'pending',
            'userid' => $userid,
            'contextid' => $contextid,
            'confirmationcode' => $confirmationcode,
            'queue_item_ids' => $queueitemids,
            'queue_authoritative' => true,
        ];

        foreach ($metadata as $key => $value) {
            $normalizedkey = trim((string)$key);
            if ($normalizedkey === '') {
                continue;
            }
            $pendingintent[$normalizedkey] = $value;
        }

        $this->set_thread_metadata_value($threadid, 'pending_intent', $pendingintent);
    }

    /**
     * Retrieve the pending intent for a thread, or null if none is stored.
     *
     * @param int $threadid
     * @return array<string,mixed>|null
     */
    public function get_pending_intent(int $threadid): ?array {
        $value = $this->get_thread_metadata_value($threadid, 'pending_intent');
        if (!is_array($value)) {
            return null;
        }

        $hasqueueitems = !empty(array_filter(array_map('strval', (array)($value['queue_item_ids'] ?? []))));
        if (!$hasqueueitems) {
            return null;
        }

        $state = (string)($value['state'] ?? 'pending');
        if ($state !== 'pending') {
            return null;
        }

        $expiresat = (int)($value['expiresat'] ?? 0);
        if ($expiresat > 0 && $expiresat < time()) {
            $this->clear_pending_intent($threadid);
            return null;
        }

        return $value;
    }

    /**
     * Consume a pending intent exactly once and clear it from thread metadata.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @return array<string,mixed>|null
     */
    public function consume_pending_intent(int $threadid, int $userid = 0, int $contextid = 0): ?array {
        $pending = $this->get_pending_intent($threadid);
        if ($pending === null) {
            return null;
        }

        if ($userid > 0 && (int)($pending['userid'] ?? 0) > 0 && (int)$pending['userid'] !== $userid) {
            return null;
        }
        if ($contextid > 0 && (int)($pending['contextid'] ?? 0) > 0 && (int)$pending['contextid'] !== $contextid) {
            return null;
        }

        $this->clear_pending_intent($threadid);
        return $pending;
    }

    /**
     * Clear the pending intent for a thread.
     *
     * Must be called after a confirmation is processed or when a new unrelated
     * message is received so that stale intents never leak into later turns.
     *
     * @param int $threadid
     * @return void
     */
    public function clear_pending_intent(int $threadid): void {
        $this->set_thread_metadata_value($threadid, 'pending_intent', null);
    }

    /**
     * Allow confirmations for a Moodle context for the current session window.
     *
     * @param int $userid
     * @param int $contextid
     * @param int|null $expiresat
     * @return void
     */
    public function allow_confirmation_for_session(int $userid, int $contextid, ?int $expiresat = null): void {
        $this->allow_confirmation_for_thread($userid, $contextid, 0, $expiresat);
    }

    /**
     * Allow confirmations for a booking context for the current session window.
     *
     * Thread id is accepted for backward compatibility but not part of the key,
     * so a page reload/new thread keeps the allowance active.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $threadid
     * @param int|null $expiresat
     * @return void
     */
    public function allow_confirmation_for_thread(int $userid, int $contextid, int $threadid, ?int $expiresat = null): void {
        $allowlist = $this->get_confirmation_session_allowlist($userid);
        $key = $this->make_confirmation_session_allowlist_key($contextid);
        $allowlist[$key] = [
            'contextid' => $contextid,
            'threadid' => $threadid,
            'expiresat' => $expiresat ?? (time() + self::CONFIRMATION_SESSION_ALLOWLIST_TTL),
        ];
        $this->save_confirmation_session_allowlist($userid, $allowlist);
    }

    /**
     * Check whether confirmations may be auto-approved for this Moodle context.
     *
     * @param int $userid
     * @param int $contextid
     * @return bool
     */
    public function is_confirmation_allowed_for_session(int $userid, int $contextid): bool {
        $allowlist = $this->get_confirmation_session_allowlist($userid);
        $key = $this->make_confirmation_session_allowlist_key($contextid);
        return !empty($allowlist[$key]);
    }

    /**
     * Check whether confirmations may be auto-approved for this booking context.
     *
     * Thread id is accepted for backward compatibility but ignored for matching.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $threadid
     * @return bool
     */
    public function is_confirmation_allowed_for_thread(int $userid, int $contextid, int $threadid): bool {
        return $this->is_confirmation_allowed_for_session($userid, $contextid);
    }

    /**
     * Remove allowance for this booking context from the confirmation allowlist.
     *
     * Thread id is accepted for backward compatibility but ignored for matching.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $threadid
     * @return void
     */
    public function clear_confirmation_allowance(int $userid, int $contextid, int $threadid): void {
        $allowlist = $this->get_confirmation_session_allowlist($userid);
        $key = $this->make_confirmation_session_allowlist_key($contextid);
        unset($allowlist[$key]);
        $this->save_confirmation_session_allowlist($userid, $allowlist);
    }

    /**
     * Build a stable preference key per booking context.
     *
     * @param int $contextid
     * @return string
     */
    private function make_confirmation_session_allowlist_key(int $contextid): string {
        return (string)$contextid;
    }

    /**
     * Load and prune the allowlist from user preferences.
     *
     * @param int $userid
     * @return array<string,array<string,int>>
     */
    private function get_confirmation_session_allowlist(int $userid): array {
        $raw = (string)get_user_preferences(self::CONFIRMATION_SESSION_ALLOWLIST_KEY, '', $userid);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $now = time();
        $allowlist = [];
        foreach ($decoded as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $contextid = (int)($entry['contextid'] ?? 0);
            // Thread id is informational only and not part of matching.
            // Keep 0 for pre-thread allowances created before first message.
            $threadid = max(0, (int)($entry['threadid'] ?? 0));
            $expiresat = (int)($entry['expiresat'] ?? 0);
            if ($contextid <= 0 || $expiresat <= $now) {
                continue;
            }

            $allowlist[(string)$key] = [
                'contextid' => $contextid,
                'threadid' => $threadid,
                'expiresat' => $expiresat,
            ];
        }

        if ($allowlist !== $decoded) {
            $this->save_confirmation_session_allowlist($userid, $allowlist);
        }

        return $allowlist;
    }

    /**
     * Persist the allowlist in user preferences.
     *
     * @param int $userid
     * @param array<string,array<string,int>> $allowlist
     * @return void
     */
    private function save_confirmation_session_allowlist(int $userid, array $allowlist): void {
        set_user_preference(self::CONFIRMATION_SESSION_ALLOWLIST_KEY, json_encode($allowlist), $userid);
    }

    /**
     * Persist one raw LLM request/response exchange for debug mode.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @param string $source
     * @param string $requesttext
     * @param string $responsetext
     * @param int $success
     * @param string $errormessage
     * @return int
     */
    public function add_llm_debug_entry(
        int $threadid,
        int $userid,
        int $contextid,
        string $source,
        string $requesttext,
        string $responsetext,
        int $success,
        string $errormessage = ''
    ): int {
        global $DB;

        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->source = trim($source);
        $record->requesttext = $requesttext;
        $record->responsetext = $responsetext;
        $record->success = $success ? 1 : 0;
        $record->errormessage = $errormessage;
        $record->timecreated = time();

        return (int)$DB->insert_record('local_wbagent_ai_llm_debug', $record);
    }

    /**
     * Return latest raw LLM exchanges for a thread.
     *
     * @param int $threadid
     * @param int $limit
     * @return array<int,stdClass>
     */
    public function get_llm_debug_entries(int $threadid, int $limit = 100): array {
        global $DB;

        $records = $DB->get_records(
            'local_wbagent_ai_llm_debug',
            ['threadid' => $threadid],
            'id DESC',
            '*',
            0,
            max(1, $limit)
        );

        return array_reverse(array_values($records));
    }
}
