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

namespace bookingextension_agent\local\wbagent\booking\tasks;

use core_text;
use bookingextension_agent\local\wbagent\booking\support\booking_rules_agent_service;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.analyze_rules.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_rules_task extends booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.analyze_rules';

    /** @var booking_rules_agent_service */
    private booking_rules_agent_service $ruleservice;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
        $this->ruleservice = new booking_rules_agent_service();
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
            'description' => 'Inspect booking rules and notification behavior in this booking context (read-only).',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_search_options',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_options',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional keyword filter applied to rule name, rule type, condition or action. '
                        . 'Pass a short keyword (e.g. "cancellation", "reminder") NOT the full user question. '
                        . 'Omit or leave empty when the user asks a general listing question.',
                    'required' => false,
                ],
                'active_only' => [
                    'type' => 'boolean',
                    'description' => 'When true only active rules are returned. Default is false (show all rules). '
                        . 'Set to true when the user says "currently", "active", "aktuell", "gerade aktiv", '
                        . '"welche werden geschickt", "what is being sent", "which are active" '
                        . 'or otherwise implies they only want rules that are switched on right now.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of matching rules to return (default 25).',
                    'required' => false,
                ],
                'include_templates' => [
                    'type' => 'boolean',
                    'description' => 'Also include available rule templates in the output.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for user-facing wrapper strings, e.g. de or en.',
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
                'id' => 'booking.analyze_rules',
                'description' => 'User asks to inspect, understand, list or summarize booking rules, '
                    . 'automated notifications, e-mails or messages that are sent by the booking instance, '
                    . 'or wants to know which rules are active / configured. '
                    . 'This also covers read-only capability questions about booking confirmations, '
                    . 'reminders or mails triggered after a booking.',
                'examples' => [
                    'Which messages are currently being sent here?',
                    'What notifications does this booking send?',
                    'Show me all active booking rules.',
                    'What emails are triggered when someone books?',
                    'Kann ich in booking eine Buchungsbestätigung schicken?',
                    'Welche Regel verschickt eine Buchungsbestätigung, wenn jemand gebucht hat?',
                    'Gibt es eine Buchungsbestätigung, wenn jemand gebucht hat?',
                    'Are there any rules configured for cancellations?',
                    'List all rules in this booking.',
                    'What automated actions are set up?',
                    'Which rule sends reminder emails?',
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
        $query = trim((string)($input['query'] ?? ''));
        $needle = core_text::strtolower($query);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 25;

        // Rules in the context path of the current booking instance.
        $activeonly = !empty($input['active_only']);
        $allrules = $this->ruleservice->list_rules_for_context($cmid, $activeonly);
        $filtered = [];
        $usedfallback = false;

        foreach ($allrules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if ($needle === '') {
                $filtered[] = $rule;
                continue;
            }

            $haystack = core_text::strtolower(implode(' ', [
                (string)($rule['name'] ?? ''),
                (string)($rule['rulename'] ?? ''),
                (string)($rule['localizedrulename'] ?? ''),
                (string)($rule['conditionname'] ?? ''),
                (string)($rule['localizedconditionname'] ?? ''),
                (string)($rule['actionname'] ?? ''),
                (string)($rule['localizedactionname'] ?? ''),
                (string)($rule['eventname'] ?? ''),
            ]));

            if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                $filtered[] = $rule;
            }
        }

        // Fallback: wenn Suchbegriff vorhanden, aber kein Match – alle Regeln zurückgeben.
        if ($needle !== '' && count($filtered) === 0) {
            $filtered = $allrules;
            $usedfallback = true;
        }

        $filtered = array_slice($filtered, 0, $limit);

        $templates = [];
        if (!empty($input['include_templates'])) {
            $templates = $this->ruleservice->list_templates();
            if ($needle !== '') {
                $templates = array_values(array_filter($templates, static function (array $item) use ($needle): bool {
                    $name = core_text::strtolower((string)($item['name'] ?? ''));
                    return $name !== '' && strpos($name, $needle) !== false;
                }));
            }
        }

        $suffix = $activeonly ? ' (active only)' : '';
        $summary = count($filtered) . ' booking rule(s) in the current context' . $suffix . '.';
        if ($usedfallback) {
            $summary .= ' (No rules matched the search term — showing all rules.)';
        }
        if (!empty($templates)) {
            $summary .= ' Templates: ' . count($templates) . '.';
        }

        $ruleslink = (string)$this->ruleservice->build_rules_link($cmid);

        // Serialize rules inline so the generic observation handler sees them.
        $rulelines = [];
        foreach ($filtered as $rule) {
            $status   = !empty($rule['isactive']) ? '[active]' : '[inactive]';
            $name     = (string)($rule['localizedrulename'] ?? $rule['name'] ?? $rule['rulename'] ?? '');
            $scope    = (string)($rule['context_scope'] ?? '');
            $event    = (string)($rule['eventname'] ?? '');
            $cond     = (string)($rule['localizedconditionname'] ?? $rule['conditionname'] ?? '');
            $action   = (string)($rule['localizedactionname'] ?? $rule['actionname'] ?? '');
            $editlink = (string)($rule['editlink'] ?? '');
            $line = "{$status} {$name}";
            if ($scope !== '' && $scope !== 'current') {
                $line .= " [context: {$scope}]";
            }
            if ($event !== '') {
                $line .= " | event: {$event}";
            }
            if ($cond !== '') {
                $line .= " | condition: {$cond}";
            }
            if ($action !== '') {
                $line .= " | action: {$action}";
            }
            if ($editlink !== '') {
                $line .= " | edit: {$editlink}";
            }
            $rulelines[] = $line;
        }
        if (!empty($rulelines)) {
            $summary .= "\n" . implode("\n", $rulelines);
        }

        // Mandatory guidance line for follow-up mutation flows in observation context.
        if ($ruleslink !== '') {
            $summary .= "\nYou can add or edit messages here: {$ruleslink}";
        } else {
            $summary .= "\nYou can add or edit messages here.";
        }

        return [
            'status' => 'executed',
            'detail' => $summary,
            'usermessage' => $summary,
            'observation_full' => $summary,
            'resultid' => null,
            'rules' => $filtered,
            'templates' => $templates,
            'link' => $ruleslink,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'cmid: ' . $cmid,
                'active_only: ' . ($activeonly ? 'yes' : 'no'),
                'Rules in context: ' . count($allrules),
                'Returned rules: ' . count($filtered),
                'Used fallback: ' . ($usedfallback ? 'yes' : 'no'),
                'Returned templates: ' . count($templates),
            ]),
        ];
    }
}
