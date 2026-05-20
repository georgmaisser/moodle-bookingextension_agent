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

use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\booking\tasks\add_price_category_task;
use bookingextension_agent\local\wbagent\booking\tasks\bulk_update_options_task;
use bookingextension_agent\local\wbagent\booking\tasks\create_option_task;
use bookingextension_agent\local\wbagent\booking\tasks\list_option_properties_task;
use bookingextension_agent\local\wbagent\booking\tasks\search_options_task;
use bookingextension_agent\local\wbagent\booking\tasks\update_option_task;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\task_registry_factory;

/**
 * Task definition for booking.list_actions.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_actions_task extends \bookingextension_agent\local\wbagent\booking\tasks\booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.list_actions';

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
                . ' Do NOT use for listing bookable options or courses — use booking.search_options for that.',
            'readonly' => $this->is_read_only(),
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
                'id' => 'booking.list_actions_request',
                'description' => 'User asks which actions/tasks the booking agent can perform.',
            ],
            [
                'id' => 'booking.list_actions_scope_filter',
                'description' => 'User asks for only readonly or only mutating actions.',
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
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
                    '- If user asks which booking option properties can be created/updated, use booking.list_option_properties.',
                    '- If user asks which actions/tasks are supported, use booking.list_actions.',
                    '- Do not map these capability/introspection questions to booking.search_options.',
                ],
            ],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $actions = [];
        $selectedtasknames = [];
        $registry = task_registry_factory::get_default();
        foreach ($registry->get_task_names() as $name) {
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
            $selectedtasknames[] = $name;
            $actions[] = [
                'task' => $name,
                'label' => booking_task_support::get_localized_action_label_for_output($name),
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => $task->is_read_only(),
            ];
        }

        $available = array_fill_keys($selectedtasknames, true);
        $capabilities = $this->build_user_capabilities($available);

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
     * @param array $actions
     * @param array $capabilities
     * @return string
     */
    private function build_debug_summary(string $scope, array $actions, array $capabilities): string {
        $lines = [
            'Task: ' . self::TASK_NAME,
            'Scope: ' . $scope,
            'Returned actions: ' . count($actions),
            'Derived capabilities: ' . count($capabilities),
        ];
        return implode("\n", $lines);
    }

    /**
     * Build a user-facing summary sentence for the selected scope.
     *
     * @param string $scope
     * @param array $capabilities
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

        $lines = array_map(static function (array $capability): string {
            $title = trim((string)($capability['title'] ?? ''));
            $description = trim((string)($capability['description'] ?? ''));

            if ($title !== '' && $description !== '') {
                return '- ' . $title . ': ' . $description;
            }

            if ($title !== '') {
                return '- ' . $title;
            }

            if ($description !== '') {
                return '- ' . $description;
            }

            return '';
        }, $capabilities);

        $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
        if (empty($lines)) {
            return $intro;
        }

        return $intro . "\n" . implode("\n", $lines);
    }

    /**
     * Build user-friendly capability blocks from the currently selected task set.
     *
     * @param array $available
     * @return array<int,array<string,string>>
     */
    private function build_user_capabilities(array $available): array {
        $capabilities = [];

        if (
            isset($available[create_option_task::TASK_NAME])
            || isset($available[update_option_task::TASK_NAME])
            || isset($available[bulk_update_options_task::TASK_NAME])
        ) {
            $capabilities[] = [
                'title' => get_string('ai_capability_manage_options_title', 'bookingextension_agent'),
                'description' => get_string('ai_capability_manage_options_desc', 'bookingextension_agent'),
            ];
        }

        if (isset($available[search_options_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_search_options_title', 'bookingextension_agent'),
                'description' => get_string('ai_capability_search_options_desc', 'bookingextension_agent'),
            ];
        }

        if (isset($available[search_users_task::TASK_NAME]) || isset($available[search_courses_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_search_people_courses_title', 'bookingextension_agent'),
                'description' => get_string('ai_capability_search_people_courses_desc', 'bookingextension_agent'),
            ];
        }

        if (
            isset($available[list_option_properties_task::TASK_NAME])
            || isset($available[explain_task_schema_task::TASK_NAME])
            || isset($available[self::TASK_NAME])
        ) {
            $capabilities[] = [
                'title' => get_string('ai_capability_explain_setup_title', 'bookingextension_agent'),
                'description' => get_string('ai_capability_explain_setup_desc', 'bookingextension_agent'),
            ];
        }

        if (isset($available[add_price_category_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_pricing_title', 'bookingextension_agent'),
                'description' => get_string('ai_capability_pricing_desc', 'bookingextension_agent'),
            ];
        }

        if (isset($available[get_current_user_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_user_context_title', 'bookingextension_agent'),
                'description' => get_string('ai_capability_user_context_desc', 'bookingextension_agent'),
            ];
        }

        return $capabilities;
    }
}
