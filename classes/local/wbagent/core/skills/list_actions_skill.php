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

namespace bookingextension_agent\local\wbagent\core\skills;

use context_module;
use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\interfaces\skill_interface;
use bookingextension_agent\local\wbagent\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wbagent\skill_contract_validator;
use bookingextension_agent\local\wbagent\skill_executability_evaluator;
use bookingextension_agent\local\wbagent\skill_registry_factory;

/**
 * Skill definition for core.list_actions.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_actions_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'core.list_actions';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'List the AI agent capabilities and skill names that this booking agent supports.'
                . ' Use this ONLY when the user asks what the agent CAN DO or which agent skills/commands exist.'
                . ' Do NOT use for regular entity listing requests; use the appropriate search/list skill instead. ',
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
     * Return example input for planner contract rendering.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'scope' => 'all',
            'question' => 'Was kannst du?',
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.list_actions_request',
                'description' => 'User asks which actions/skills the booking agent can perform.',
            ],
            [
                'id' => 'core.list_actions_scope_filter',
                'description' => 'User asks for only readonly or only mutating actions.',
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $issuecodes = [];
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $allowed = ['all', 'readonly', 'mutating'];
        if (!in_array($scope, $allowed, true)) {
            $errors[] = get_string('agent_booking_list_actions_scope_invalid', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => array_values(array_unique($issuecodes)),
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
                    '- If user asks which actions/skills are supported, use this introspection skill.',
                    '- If user asks for concrete entities (users, courses, options, etc.), route to a dedicated search/list skill.',
                    '- Keep introspection questions separate from entity lookup questions.',
                ],
            ],
        ];
    }

    /**
     * Execute skill.
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
        $unavailableactions = [];
        $registry = skill_registry_factory::get_default();
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        foreach ($registry->get_skill_names_for_context($evaluator, $userid, $contextid, true) as $name) {
            $skill = $registry->get_skill($name);
            if (!$skill) {
                continue;
            }

            $schema = $skill->get_schema();
            $evaluation = $evaluator->evaluate_skill($name, $userid, $contextid);
            $isallowed = (string)($evaluation['executable_state'] ?? '') === 'allow';

            if ($isallowed) {
                if ($scope === 'readonly' && !$registry->is_read_only_skill($name)) {
                    continue;
                }
                if ($scope === 'mutating' && $registry->is_read_only_skill($name)) {
                    continue;
                }

                $actions[] = [
                    'skill' => $name,
                    'label' => $name,
                    'description' => (string)($schema['description'] ?? ''),
                    'readonly' => $skill->is_read_only(),
                    'provider' => (string)($registry->get_skill_contract($name)['component'] ?? 'unknown'),
                ];
                continue;
            }

            if ($scope !== 'all' && $scope !== 'readonly' && $scope !== 'mutating') {
                continue;
            }

            $unavailableactions[] = [
                'skill' => $name,
                'label' => $name,
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => $skill->is_read_only(),
                'provider' => (string)($registry->get_skill_contract($name)['component'] ?? 'unknown'),
                'deny_reason' => (string)($evaluation['deny_reason'] ?? ''),
                'deny_reason_label' => $this->describe_deny_reason((string)($evaluation['deny_reason'] ?? '')),
                'diagnostics' => (array)($evaluation['diagnostics'] ?? []),
            ];
        }

        $capabilities = $this->build_user_capabilities($actions);

        $summary = $this->build_user_summary($scope, $capabilities, $unavailableactions);

        $debugmessage = $this->build_debug_summary($scope, $actions, $capabilities, $unavailableactions);

        // ...Observation_full: vollständige, ungekürzte Liste aller Skills mit Beschreibungen.
        $observation = $this->build_observation_full($actions, $outputlang);
        return [
            'status' => 'executed',
            'detail' => $summary,
            'resultid' => null,
            'usermessage' => $summary,
            'debugmessage' => $debugmessage,
            'capabilities' => $capabilities,
            'actions' => $actions,
            'unavailable_actions' => $unavailableactions,
            'observation_full' => $observation,
        ];
    }

    /**
     * Baue eine vollständige, formatierte Liste aller Skills und Beschreibungen.
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
    private function build_debug_summary(
        string $scope,
        array $actions,
        array $capabilities,
        array $unavailableactions = []
    ): string {
        $lines = [
            'Skill: ' . self::SKILL_NAME,
            'Scope: ' . $scope,
            'Returned actions: ' . count($actions),
            'Provider groups: ' . count($capabilities),
            'Unavailable actions: ' . count($unavailableactions),
        ];
        return implode("\n", $lines);
    }

    /**
     * Build a user-facing summary sentence for the selected scope.
     *
     * @param string $scope
     * @param array<int,array<string,mixed>> $capabilities
     * @param array<int,array<string,mixed>> $unavailableactions
     * @return string
     */
    private function build_user_summary(string $scope, array $capabilities, array $unavailableactions = []): string {
        if (empty($capabilities)) {
            $summary = get_string('ai_list_actions_summary_none', 'bookingextension_agent');
        } else if ($scope === 'readonly') {
            $summary = get_string('ai_list_actions_summary_readonly', 'bookingextension_agent');
        } else if ($scope === 'mutating') {
            $summary = get_string('ai_list_actions_summary_mutating', 'bookingextension_agent');
        } else {
            $summary = get_string('ai_list_actions_summary_all', 'bookingextension_agent');
        }

        $lines = [$summary];
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
                    $skilllabel = trim((string)($capability['label'] ?? ''));
                    $description = trim((string)($capability['description'] ?? ''));
                    $skillname = trim((string)($capability['skill'] ?? $capability['skill'] ?? ''));

                    $line = '    - ';
                    if ($skilllabel !== '') {
                        $line .= $skilllabel;
                    } else if ($skillname !== '') {
                        $line .= $skillname;
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

        if (!empty($unavailableactions)) {
            $lines[] = '';
            $lines[] = get_string('ai_list_actions_summary_unavailable_heading', 'bookingextension_agent');
            foreach ($unavailableactions as $action) {
                $skillname = trim((string)($action['skill'] ?? $action['skill'] ?? ''));
                $reason = trim((string)($action['deny_reason_label'] ?? ''));
                $reasoncode = trim((string)($action['deny_reason'] ?? ''));
                $detail = $this->build_unavailable_action_detail((array)$action);
                $line = '  - ' . ($skillname !== '' ? $skillname : 'unknown skill');
                if ($reason !== '') {
                    $line .= ': ' . $reason;
                } else if ($reasoncode !== '') {
                    $line .= ': ' . $reasoncode;
                }
                if ($detail !== '') {
                    $line .= ' (' . $detail . ')';
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Return a short human-readable description for a deny reason.
     *
     * @param string $reason
     * @return string
     */
    private function describe_deny_reason(string $reason): string {
        return match ($reason) {
            skill_contract_validator::DENY_RUNTIME_DISABLED => get_string(
                'ai_list_actions_unavailable_runtime_disabled',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_INACTIVE => get_string(
                'ai_list_actions_unavailable_inactive',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_MISSING_CAPABILITY => get_string(
                'ai_list_actions_unavailable_missing_capability',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_CONTEXT_INVALID => get_string(
                'ai_list_actions_unavailable_context_invalid',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_SKILL_VERSION_UNSUPPORTED => get_string(
                'ai_list_actions_unavailable_version_unsupported',
                'bookingextension_agent'
            ),
            default => get_string('ai_list_actions_unavailable_unknown', 'bookingextension_agent'),
        };
    }

    /**
     * Build a compact technical detail string for an unavailable action.
     *
     * @param array<string,mixed> $action
     * @return string
     */
    private function build_unavailable_action_detail(array $action): string {
        $diagnostics = (array)($action['diagnostics'] ?? []);
        $details = [];

        $requiredcapabilities = array_values(array_filter(array_map(
            'strval',
            (array)($diagnostics['required_capabilities'] ?? [])
        )));
        if (!empty($requiredcapabilities)) {
            $details[] = 'required=' . implode(', ', $requiredcapabilities);
        }

        if (array_key_exists('active', $diagnostics) && $diagnostics['active'] === false) {
            $details[] = 'platform disabled';
        }

        if (array_key_exists('contextid', $diagnostics)) {
            $details[] = 'contextid=' . (string)$diagnostics['contextid'];
        }

        return implode('; ', $details);
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
                'skill' => (string)($action['skill'] ?? $action['skill'] ?? ''),
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
                        $leftskill = trim((string)($left['skill'] ?? $left['skill'] ?? ''));
                        $rightskill = trim((string)($right['skill'] ?? $right['skill'] ?? ''));

                        $leftkey = $leftlabel !== '' ? $leftlabel : $leftskill;
                        $rightkey = $rightlabel !== '' ? $rightlabel : $rightskill;

                        return strcmp($leftkey, $rightkey);
                    }
                );
            }
        }
        unset($providerblock);

        return array_values($grouped);
    }
}
