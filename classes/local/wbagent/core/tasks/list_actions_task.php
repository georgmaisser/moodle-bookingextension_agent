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

namespace bookingextension_agent\local\wbagent\core\tasks;

use context_module;
use bookingextension_agent\local\wbagent\authorization_service;
use bookingextension_agent\local\wbagent\interfaces\task_interface;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\task_executability_evaluator;
use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Task definition for core.list_actions.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_actions_task extends core_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'core.list_actions';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'List the AI agent capabilities and task names that this booking agent supports.'
                . ' Use this ONLY when the user asks what the agent CAN DO or which agent tasks/commands exist.'
                . ' Do NOT use for regular entity listing requests; use the appropriate search/list task instead.',
            'readonly' => true,
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user question for language detection and phrasing.',
                    'required' => false,
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Filter scope: all (default), readonly, or mutating.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.list_actions_request',
                'description' => 'User asks which actions/tasks the booking agent can perform.',
            ],
            [
                'id' => 'core.list_actions_scope_filter',
                'description' => 'User asks for only readonly or only mutating actions.',
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $allowed = ['all', 'readonly', 'mutating'];
        if (!in_array($scope, $allowed, true)) {
            $errors[] = get_string('agent_booking_list_actions_scope_invalid', 'bookingextension_agent');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.introspection',
                'triggers' => [
                    'list properties', 'editable fields', 'which fields', 'which settings', 'list actions',
                    'what can you do', 'liste aller einstellungen', 'welche einstellungen',
                    'welche felder', 'welche aktionen', 'was kannst du',
                ],
                'guidance' => [
                    '- If user asks which actions/tasks are supported, use this introspection task.',
                    '- If user asks for concrete entities (users, courses, options, etc.), route to a dedicated search/list task.',
                    '- Keep introspection questions separate from entity lookup questions.',
                ],
            ],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = trim((string)($input['outputlang'] ?? ''));
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $actions = [];
        $registry = task_registry_factory::get_default();
        $evaluator = new task_executability_evaluator($registry, new authorization_service());
        foreach ($registry->get_task_names_for_context($evaluator, $userid, $contextid) as $name) {
            if ($scope === 'readonly' && !$registry->is_read_only_task($name)) {
                continue;
            }
            if ($scope === 'mutating' && $registry->is_read_only_task($name)) {
                continue;
            }

            $task = $registry->get_task($name);
            if (!$task) {
                continue;
            }

            $schema = $task->get_schema();
            $actions[] = [
                'task' => $name,
                'label' => $name,
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => $task->is_read_only(),
                'provider' => (string)($registry->get_task_contract($name)['component'] ?? 'unknown'),
            ];
        }

        $capabilities = $this->build_user_capabilities($actions);

        $summary = $this->build_user_summary($scope, $capabilities);

        $debugmessage = $this->build_debug_summary($scope, $actions, $capabilities);

        // ...Observation_full: vollständige, ungekürzte Liste aller Tasks mit Beschreibungen.
        $observation = $this->build_observation_full($actions, $outputlang);
        return [
            'status' => 'executed',
            'detail' => $summary,
            'resultid' => null,
            'usermessage' => $summary,
            'debugmessage' => $debugmessage,
            'capabilities' => $capabilities,
            'actions' => $actions,
            'observation_full' => $observation,
        ];
    }

    /**
     * Baue eine vollständige, formatierte Liste aller Tasks und Beschreibungen.
     *
     * @param array $actions
     * @param string $lang
     * @return string
     */
    private function build_observation_full(array $actions, string $lang): string {
        if (empty($actions)) {
            return $this->get_localized_string('ai_list_actions_summary_none', $lang);
        }
        $lines = [];
        foreach ($actions as $action) {
            $label = trim((string)($action['label'] ?? ''));
            $desc = trim((string)($action['description'] ?? ''));
            $readonly = !empty($action['readonly']) ? ' (readonly)' : '';
            if ($label !== '' && $desc !== '') {
                $lines[] = "- {$label}{$readonly}: {$desc}";
            } else if ($label !== '') {
                $lines[] = "- {$label}{$readonly}";
            } else if ($desc !== '') {
                $lines[] = "- {$desc}";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Sprachsicheres get_string für observation_full.
     *
     * @param string $identifier
     * @param string $lang
     * @return string
     */

    /**
     * Sprachsicheres get_string für observation_full.
     *
     * @param string $identifier
     * @param string $lang
     * @return string
     */
    private function get_localized_string(string $identifier, string $lang): string {
        $targetlang = trim($lang);
        if ($targetlang === '') {
            return get_string($identifier, 'bookingextension_agent');
        }
        return get_string_manager()->get_string($identifier, 'bookingextension_agent', null, $targetlang);
    }

    /**
     * Build a technical debug summary for developers.
     *
     * @param string $scope
     * @return string
     */
    private function build_debug_summary(string $scope, array $actions, array $capabilities): string {
        $lines = [
            'Task: ' . self::TASK_NAME,
            'Scope: ' . $scope,
            'Returned actions: ' . count($actions),
            'Provider groups: ' . count($capabilities),
        ];
        return implode("\n", $lines);
    }

    /**
     * Build a user-facing summary sentence for the selected scope.
     *
     * @param string $scope
     * @return string
     */
    private function build_user_summary(string $scope, array $capabilities): string {
        $intro = '';

        if (empty($capabilities)) {
            return get_string('ai_list_actions_summary_none', 'bookingextension_agent');
        }

        if ($scope === 'readonly') {
            $intro = get_string('ai_list_actions_summary_readonly', 'bookingextension_agent');
        } else if ($scope === 'mutating') {
            $intro = get_string('ai_list_actions_summary_mutating', 'bookingextension_agent');
        } else {
            $intro = get_string('ai_list_actions_summary_all', 'bookingextension_agent');
        }

        $lines = [];
        foreach ($capabilities as $providerblock) {
            $provider = trim((string)($providerblock['provider'] ?? 'unknown'));
            $groups = (array)($providerblock['groups'] ?? []);
            if ($provider === '') {
                $provider = 'unknown';
            }

            $lines[] = $provider . ':';
            foreach (['readonly', 'write'] as $accesslevel) {
                if (!isset($groups[$accesslevel])) {
                    continue;
                }

                $lines[] = '  ' . $accesslevel . ':';
                foreach ((array)$groups[$accesslevel] as $capability) {
                    $tasklabel = trim((string)($capability['label'] ?? ''));
                    $description = trim((string)($capability['description'] ?? ''));
                    $taskname = trim((string)($capability['task'] ?? ''));

                    $line = '    - ';
                    if ($tasklabel !== '') {
                        $line .= $tasklabel;
                    } else if ($taskname !== '') {
                        $line .= $taskname;
                    } else {
                        $line .= get_string('ai_list_actions_summary_none', 'bookingextension_agent');
                    }

                    if ($description !== '') {
                        $line .= ': ' . $description;
                    }

                    $lines[] = $line;
                }
            }
        }

        if (empty($lines)) {
            return $intro;
        }

        return $intro . "\n" . implode("\n", $lines);
    }

    /**
     * Build user-friendly capability blocks grouped by provider and read/write state.
     *
     * @param array<int,array<string,mixed>> $actions
     * @return array<int,array<string,mixed>>
     */
    private function build_user_capabilities(array $actions): array {
        $grouped = [];

        foreach ($actions as $action) {
            $provider = trim((string)($action['provider'] ?? 'unknown'));
            if ($provider === '') {
                $provider = 'unknown';
            }

            $readonly = !empty($action['readonly']) ? 'readonly' : 'write';
            if (!isset($grouped[$provider])) {
                $grouped[$provider] = [
                    'provider' => $provider,
                    'groups' => [
                        'readonly' => [],
                        'write' => [],
                    ],
                ];
            }

            $grouped[$provider]['groups'][$readonly][] = [
                'task' => (string)($action['task'] ?? ''),
                'label' => (string)($action['label'] ?? ''),
                'description' => (string)($action['description'] ?? ''),
            ];
        }

        ksort($grouped);
        foreach ($grouped as &$providerblock) {
            foreach (['readonly', 'write'] as $accesslevel) {
                usort(
                    $providerblock['groups'][$accesslevel],
                    static function (array $left, array $right): int {
                        $leftlabel = trim((string)($left['label'] ?? ''));
                        $rightlabel = trim((string)($right['label'] ?? ''));
                        $lefttask = trim((string)($left['task'] ?? ''));
                        $righttask = trim((string)($right['task'] ?? ''));

                        $leftkey = $leftlabel !== '' ? $leftlabel : $lefttask;
                        $rightkey = $rightlabel !== '' ? $rightlabel : $righttask;

                        return strcmp($leftkey, $rightkey);
                    }
                );
            }
        }
        unset($providerblock);

        return array_values($grouped);
    }
}
