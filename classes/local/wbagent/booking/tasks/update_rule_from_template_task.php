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

use bookingextension_agent\local\wbagent\booking\support\booking_rules_agent_service;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\task_preflight_result;

/**
 * Task definition for booking.update_rule_from_template.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_rule_from_template_task extends booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.update_rule_from_template';

    /** @var booking_rules_agent_service */
    private booking_rules_agent_service $ruleservice;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
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
            'description' => 'Update a booking rule in the current booking context and optionally reapply a rule template.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_update_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_update_option',
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Target booking rule id.',
                    'required' => false,
                ],
                'rulequery' => [
                    'type' => 'string',
                    'description' => 'Rule name fragment when ruleid is unknown.',
                    'required' => false,
                ],
                'templateid' => [
                    'type' => 'integer',
                    'description' => 'Optional template id to reapply before saving (negative id for built-ins).',
                    'required' => false,
                ],
                'templatequery' => [
                    'type' => 'string',
                    'description' => 'Optional template search text if templateid is unknown.',
                    'required' => false,
                ],
                'rulename' => [
                    'type' => 'string',
                    'description' => 'Optional new display name for the rule.',
                    'required' => false,
                ],
                'isactive' => [
                    'type' => 'boolean',
                    'description' => 'Optional active flag override.',
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
                'id' => 'booking.update_rule_from_template',
                'description' => 'User asks to modify an existing booking rule by id/name, optionally based on a template.',
            ],
        ];
    }

    /**
     * Structural validation.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $hastargetid = !empty($input['ruleid']);
        $hastargetquery = trim((string)($input['rulequery'] ?? '')) !== '';
        if (!$hastargetid && !$hastargetquery) {
            return [
                'valid' => false,
                'errors' => ['Bitte ruleid oder rulequery angeben.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Deep preflight validation.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return task_preflight_result
     */
    public function preflight(array $input, int $cmid, int $userid): task_preflight_result {
        $issues = [];
        $contextid = $this->ruleservice->get_module_contextid($cmid);

        $ruleresolution = $this->ruleservice->resolve_rule(
            $contextid,
            (int)($input['ruleid'] ?? 0),
            trim((string)($input['rulequery'] ?? ''))
        );

        if (($ruleresolution['status'] ?? '') === 'error') {
            $issues[] = [
                'code' => 'RULE_RESOLUTION_FAILED',
                'severity' => 'needs_clarification',
                'message' => (string)($ruleresolution['message'] ?? 'Regel konnte nicht aufgeloest werden.'),
            ];
            return task_preflight_result::invalid($issues);
        }

        if (($ruleresolution['status'] ?? '') === 'ambiguity') {
            $issues[] = [
                'code' => 'RULE_RESOLUTION_AMBIGUOUS',
                'severity' => 'needs_clarification',
                'message' => (string)($ruleresolution['message'] ?? 'Mehrere Regeln passen.'),
            ];
            foreach ((array)($ruleresolution['candidates'] ?? []) as $candidate) {
                $issues[] = [
                    'code' => 'RULE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'id=' . (int)($candidate['id'] ?? 0) . ' name=' . (string)($candidate['name'] ?? ''),
                ];
            }
            return task_preflight_result::invalid($issues);
        }

        $prepared = $input;
        $rule = (array)($ruleresolution['rule'] ?? []);
        $prepared['ruleid'] = (int)($rule['id'] ?? 0);

        $hastemplateid = !empty($input['templateid']);
        $hastemplatequery = trim((string)($input['templatequery'] ?? '')) !== '';
        if ($hastemplateid || $hastemplatequery) {
            $templateresolution = $this->ruleservice->resolve_template(
                (int)($input['templateid'] ?? 0),
                trim((string)($input['templatequery'] ?? ''))
            );

            if (($templateresolution['status'] ?? '') === 'error') {
                $issues[] = [
                    'code' => 'TEMPLATE_RESOLUTION_FAILED',
                    'severity' => 'needs_clarification',
                    'message' => (string)($templateresolution['message'] ?? 'Template konnte nicht aufgeloest werden.'),
                ];
                return task_preflight_result::invalid($issues);
            }

            if (($templateresolution['status'] ?? '') === 'ambiguity') {
                $issues[] = [
                    'code' => 'TEMPLATE_RESOLUTION_AMBIGUOUS',
                    'severity' => 'needs_clarification',
                    'message' => (string)($templateresolution['message'] ?? 'Mehrere Templates passen.'),
                ];
                foreach ((array)($templateresolution['candidates'] ?? []) as $candidate) {
                    $issues[] = [
                        'code' => 'TEMPLATE_CANDIDATE',
                        'severity' => 'needs_clarification',
                        'message' => 'templateid=' . (int)($candidate['templateid'] ?? 0)
                            . ' name=' . (string)($candidate['name'] ?? ''),
                    ];
                }
                return task_preflight_result::invalid($issues);
            }

            $template = (array)($templateresolution['template'] ?? []);
            $prepared['templateid'] = (int)($template['templateid'] ?? 0);
        }

        return task_preflight_result::ok($prepared);
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
        $contextid = $this->ruleservice->get_module_contextid($cmid);
        $overrides = [];
        if (isset($input['rulename'])) {
            $overrides['rulename'] = trim((string)$input['rulename']);
        }
        if (array_key_exists('isactive', $input)) {
            $overrides['isactive'] = !empty($input['isactive']);
        }

        $result = $this->ruleservice->update_rule_from_template(
            $contextid,
            (int)($input['ruleid'] ?? 0),
            (int)($input['templateid'] ?? 0),
            $overrides
        );

        if (($result['status'] ?? '') !== 'ok') {
            $message = (string)($result['message'] ?? 'Regel konnte nicht aktualisiert werden.');
            return [
                'status' => 'failed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => (int)($input['ruleid'] ?? 0),
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Update status: failed']),
            ];
        }

        $rule = (array)($result['rule'] ?? []);
        $name = (string)($rule['name'] ?? '');
        $ruleid = (int)($rule['id'] ?? 0);
        $link = $this->ruleservice->build_rules_link($cmid);

        $message = 'Regel aktualisiert: ' . $name . ' (ID ' . $ruleid . ').';

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => $ruleid,
            'rule' => $rule,
            'link' => $link,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Update status: ok',
                'Rule id: ' . $ruleid,
                'Rule name: ' . $name,
            ]),
        ];
    }
}
