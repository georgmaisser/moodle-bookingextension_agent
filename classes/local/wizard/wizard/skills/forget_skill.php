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

namespace bookingextension_agent\local\wizard\wizard\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\user_memory_service;
use bookingextension_agent\local\wizard\services\preview_support;

/**
 * Skill definition for wizard.forget — delete a stored user-stated memory.
 *
 * Always destructive (R2): the resolution is list → confirm → delete-by-id and
 * always goes through explicit confirmation. A query that matches zero or several
 * memories never deletes; it asks the user to clarify which id to forget.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forget_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.forget';

    /**
     * Constructor — broad/destructive write, always explicit confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Human-readable preview of the memory deletion (tier-3).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = preview_support::lang($input);
        $label = preview_support::str('previewlabel_target', $lang);
        $rows = [];
        if (preview_support::truthy($input['all'] ?? null)) {
            preview_support::push($rows, $label, preview_support::str('previewvalue_allmemories', $lang));
        } else {
            preview_support::push($rows, $label, preview_support::text($input['query'] ?? null));
            preview_support::push($rows, $label, preview_support::posint($input['id'] ?? null));
        }
        return [
            'title' => preview_support::str('previewtitle_forget', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Delete a previously stored user-stated memory/preference (e.g. "forget that I prefer '
                . 'morning bookings"). Resolves by query or explicit id and always asks for confirmation before '
                . 'deleting. This manages stored facts the user told the agent — it is NOT for previous '
                . 'conversation. User isolation is strict; userid is never taken from input.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'agent_memory_forget_confirm',
            'fallback_skillcall_string_key' => 'agent_memory_forget_skillcall',
            'example_utterances' => [
                'forget that I prefer morning bookings',
                'delete the preference about room B',
                'stop remembering my employee id',
                'forget everything you know about me',
                'remove that saved fact',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text to locate ONE memory to delete. Provide this OR id OR all.',
                    'required' => false,
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'Exact id of the memory to delete (e.g. from wizard.list_memories). '
                        . 'Provide this OR query OR all.',
                    'required' => false,
                ],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'Set true when the user wants to forget EVERYTHING stored about them '
                        . '(e.g. "forget everything", "forget all my preferences"). '
                        . 'Do NOT invent a query for such requests — use this flag instead.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'intent' => 'Delete stored user memories: one (by query or explicit id) or all of them '
                    . '(all=true), always confirmed.',
                'input_fields_for_prompt' => ['query', 'all'],
                'anchor_fields' => ['query'],
                'capabilities' => ['user_memory_delete'],
                // Affected scope is the USER's global memory store, not the hosting context.
                'context_scopes' => ['user'],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'query' => 'morning bookings',
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.forget_request',
                'description' => 'User asks the agent to forget/delete a previously stored fact or preference.',
                'examples' => [
                    'forget: I prefer bookings in the morning',
                    'forget that my employee id is 12345',
                    'delete the stored preference about room B',
                    'forget everything you have remembered about me',
                ],
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $query = trim((string)($input['query'] ?? ''));
        $id = (int)($input['id'] ?? 0);
        $all = !empty($input['all']);
        if ($query === '' && $id <= 0 && !$all) {
            $errors[] = get_string('agent_memory_forget_need_query', 'bookingextension_agent');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => empty($errors) ? [] : ['RECOVERABLE_INPUT_ERROR'],
        ];
    }

    /**
     * Resolve the target memory and gate destructive execution.
     *
     * Zero or multiple matches return a clarification (never deletes). Exactly one
     * match (or an owned explicit id) prepares the delete, which the decision
     * service then promotes to an explicit confirmation (R2).
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            return $this->invalid($this->clarification_issues((array)($structure['errors'] ?? [])));
        }

        $service = new user_memory_service();
        $id = (int)($input['id'] ?? 0);
        $query = trim((string)($input['query'] ?? ''));

        // Forget-everything path: confirm with the full list, delete all on confirm.
        if (!empty($input['all'])) {
            $records = $service->get_all($userid);
            if (empty($records)) {
                return $this->invalid($this->clarification_issues([
                    get_string('agent_memory_forget_none_stored', 'bookingextension_agent'),
                ]));
            }

            return $this->pass([
                'all' => true,
                'ids' => array_map(static fn($record): int => (int)$record->id, $records),
                'memory' => $this->format_candidates($records),
            ]);
        }

        // Explicit id path: ownership-checked.
        if ($id > 0) {
            $owned = null;
            foreach ($service->get_all($userid) as $record) {
                if ((int)$record->id === $id) {
                    $owned = $record;
                    break;
                }
            }
            if ($owned === null) {
                return $this->invalid($this->clarification_issues([
                    get_string('agent_memory_forget_id_not_found', 'bookingextension_agent', $id),
                ]));
            }

            return $this->pass([
                'id' => $id,
                'memory' => (string)$owned->memory,
            ]);
        }

        // Query path: find candidates and propose, never silent multi-delete.
        $matches = $service->find($userid, $query);
        if (empty($matches)) {
            // Distinguish "nothing stored at all" from "query matched nothing":
            // listing what IS stored lets the user (and the planner retry) pick the
            // right entry instead of wrongly concluding the memory is empty.
            $stored = $service->get_all($userid);
            if (empty($stored)) {
                return $this->invalid($this->clarification_issues([
                    get_string('agent_memory_forget_none_stored', 'bookingextension_agent'),
                ]));
            }

            return $this->invalid($this->clarification_issues([
                get_string('agent_memory_forget_no_match_with_list', 'bookingextension_agent', (object)[
                    'query' => s($query),
                    'candidates' => $this->format_candidates($stored),
                ]),
            ]));
        }

        if (count($matches) > 1) {
            return $this->invalid($this->clarification_issues([
                get_string('agent_memory_forget_multi_match', 'bookingextension_agent', $this->format_candidates($matches)),
            ]));
        }

        $match = reset($matches);
        return $this->pass([
            'id' => (int)$match->id,
            'memory' => (string)$match->memory,
        ]);
    }

    /**
     * Execute the confirmed deletion.
     *
     * @param array $input prepared input from preflight (resolved id + memory)
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $service = new user_memory_service();
        $id = (int)($input['id'] ?? 0);
        $memory = (string)($input['memory'] ?? '');

        // Confirmed forget-everything path.
        if (!empty($input['all'])) {
            $deletedcount = 0;
            foreach ((array)($input['ids'] ?? []) as $deleteid) {
                if ($service->delete($userid, (int)$deleteid)) {
                    $deletedcount++;
                }
            }

            return [
                'status' => 'executed',
                'detail' => get_string('agent_memory_forget_all_ok', 'bookingextension_agent', $deletedcount),
                'observation_full' => '[USER_MEMORY] forgot ALL stored memories (' . $deletedcount . ') :: ' . $memory,
            ];
        }

        $deleted = $service->delete($userid, $id);
        if (!$deleted) {
            return [
                'status' => 'executed',
                'detail' => get_string('agent_memory_forget_id_not_found', 'bookingextension_agent', $id),
                'observation_full' => '[USER_MEMORY] forget failed: id=' . $id . ' not found for user.',
            ];
        }

        return [
            'status' => 'executed',
            'detail' => get_string('agent_memory_forget_ok', 'bookingextension_agent', $memory),
            'resultid' => $id,
            'observation_full' => '[USER_MEMORY] forgot id=' . $id . ' :: ' . $memory,
        ];
    }

    /**
     * Wrap messages as needs_clarification issues so the decision service routes
     * them to a clarification (not a terminal hard failure).
     *
     * @param string[] $messages
     * @return array[]
     */
    private function clarification_issues(array $messages): array {
        $issues = [];
        foreach ($messages as $message) {
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            $issues[] = [
                'code' => 'RECOVERABLE_INPUT_ERROR',
                'severity' => 'needs_clarification',
                'message' => $message,
            ];
        }
        return $issues;
    }

    /**
     * Render candidate memories (id + text) for a disambiguation clarification.
     *
     * @param \stdClass[] $matches
     * @return string
     */
    private function format_candidates(array $matches): string {
        $parts = [];
        foreach ($matches as $record) {
            $parts[] = '(id=' . (int)$record->id . ') ' . (string)$record->memory;
        }
        return implode('; ', $parts);
    }
}
