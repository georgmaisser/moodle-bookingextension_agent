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

namespace bookingextension_agent\local\wbagent\examples\tasks;

use bookingextension_agent\local\wbagent\base_task;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\services\task_prompt_contract;

/**
 * Phase-7 scenario A reference: ideal readonly task.
 *
 * This class is intentionally tiny and heavily documented so third-party
 * developers can copy it as a starting point.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phase7_readonly_example_task extends base_task {
    /** Task name used by planner/executor. */
    public const TASK_NAME = 'examples.phase7_readonly_example';

    /** Deterministic default query used when caller omits query input. */
    private const DEFAULT_QUERY = 'phase7 demo';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return stable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return minimal schema for prompt contracts and validation hints.
     *
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Example readonly lookup task for phase-7 scenario A.',
            'readonly' => true,
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional lookup token. Falls back to a deterministic default when omitted.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of synthetic rows returned (1-10).',
                    'required' => false,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Provide compact routing hint data.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'query' => self::DEFAULT_QUERY,
            'limit' => 3,
        ];
    }

    /**
     * Return explicit planner contract.
     *
     * @return task_prompt_contract
     */
    public function get_prompt_contract(): task_prompt_contract {
        return new task_prompt_contract([
            'intent' => 'Run a deterministic readonly example lookup.',
            'anchors' => [],
            'minimal_input' => [],
            'example_input' => $this->get_example_input(),
            'namespace' => 'examples',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
        ]);
    }

    /**
     * Pure structure validation: no DB access, no side effects.
     *
     * @param array<string,mixed> $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if (array_key_exists('query', $input)) {
            $query = trim((string)$input['query']);
            if ($query === '') {
                $errors[] = 'Field "query" must be a non-empty string when provided.';
            }
        }

        if (array_key_exists('limit', $input)) {
            $limit = (int)$input['limit'];
            if ($limit < 1 || $limit > 10) {
                $errors[] = 'Field "limit" must be between 1 and 10.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Deep preflight. For this demo there are no DB checks.
     *
     * @param array<string,mixed> $input
     * @param int $contextid
     * @param int $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = array_map(static fn(string $error): array => [
                'code' => 'EXAMPLE_INPUT_INVALID',
                'severity' => 'hard_block',
                'message' => $error,
            ], $structure['errors']);
            return preflight_result_v2::invalid($issues);
        }

        return preflight_result_v2::ok([
            'query' => trim((string)($input['query'] ?? self::DEFAULT_QUERY)),
            'limit' => max(1, min(10, (int)($input['limit'] ?? 3))),
            'contextid' => $contextid,
            'userid' => $userid,
        ]);
    }

    /**
     * Execute readonly example lookup.
     *
     * @param array<string,mixed> $preparedinput
     * @param int $contextid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $query = trim((string)($preparedinput['query'] ?? ''));
        $limit = max(1, min(10, (int)($preparedinput['limit'] ?? 3)));

        $rows = [];
        for ($i = 1; $i <= $limit; $i++) {
            $rows[] = [
                'row' => $i,
                'label' => $query . ' #' . $i,
            ];
        }

        return [
            'status' => 'executed',
            'detail' => '[PHASE7-A] readonly example executed',
            'usermessage' => '[PHASE7-A] Readonly scenario completed successfully.',
            'resultid' => 0,
            'rows' => $rows,
            'metadata' => [
                'contextid' => $contextid,
                'userid' => $userid,
            ],
        ];
    }
}
