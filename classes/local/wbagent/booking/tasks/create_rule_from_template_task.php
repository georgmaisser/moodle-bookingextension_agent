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
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Task definition for booking.create_rule_from_template.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_rule_from_template_task extends booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.create_rule_from_template';

    /** Maximum number of template candidates shown in clarification output. */
    private const MAX_TEMPLATE_CANDIDATES_IN_CLARIFICATION = 8;

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
            'description' => 'Create a booking rule from a booking-rule template '
                . 'via the existing server-side rules form pipeline. '
                . 'Use this for requests like adding a booking confirmation, reminder, or cancellation notification rule. '
                . 'If the user explicitly asks for a Buchungsbestaetigung/booking confirmation, '
                . 'resolve templatequery directly to "booking confirmation" without asking for template type again. '
                . 'If the user says "mit dem Namen ...", map that value to rulename (not to optionquery).',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'templateid' => [
                    'type' => 'integer',
                    'description' => 'Rule template id (negative id for built-in templates).',
                    'required' => false,
                ],
                'templatequery' => [
                    'type' => 'string',
                    'description' => 'Template name fragment if templateid is unknown. '
                        . 'Use the user phrasing directly (e.g. "booking confirmation", "reminder", "cancellation").',
                    'required' => false,
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user request text used for '
                        . 'template inference when templatequery is missing.',
                    'required' => false,
                ],
                'rulename' => [
                    'type' => 'string',
                    'description' => 'Optional custom display name for the created rule.',
                    'required' => false,
                ],
                'isactive' => [
                    'type' => 'boolean',
                    'description' => 'Optional active flag for the new rule (default true).',
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
                'id' => 'booking.create_rule_from_template',
                'description' => 'User asks to create/add a new booking rule, especially notification rules '
                    . 'such as booking confirmation, reminder, cancellation or waitlist email.',
                'examples' => [
                    'Add a simple booking confirmation.',
                    'Create a confirmation email rule for bookings.',
                    'Add a reminder rule for this booking.',
                    'Create a cancellation notification rule.',
                    'Kannst du für mich eine Buchungsbestätigung erstellen?',
                    'Kannst du für mich eine Buchungsbestätigung erstellen? Mit dem Namen "Bestätigung 8".',
                    'Ich möchte, dass du mir eine Buchungsbestätigung mit dem Namen "meine neue buchungsbestätigung" erstellst.',
                    'Erstelle bitte eine Buchungsbestaetigung mit dem Namen "Bestaetigung 8".',
                    'Fuege eine einfache Buchungsbestaetigung hinzu.',
                ],
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
        // Keep structure validation permissive here.
        // Missing template selection is handled in preflight as a clarification
        // with concrete candidate templates.
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Deep preflight validation.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
        $issues = [];

        $templateid = (int)($input['templateid'] ?? 0);
        $templatequery = trim((string)($input['templatequery'] ?? ''));
        $rulename = trim((string)($input['rulename'] ?? ''));
        if ($templateid === 0 && $templatequery === '') {
            $templatequery = trim((string)($input['question'] ?? $input['userquery'] ?? ''));
        }
        if ($templateid === 0 && $templatequery === '' && $rulename !== '') {
            $templatequery = $rulename;
        }

        if ($templateid === 0 && $templatequery === '') {
            $issues[] = [
                'code' => 'TEMPLATE_SELECTION_REQUIRED',
                'severity' => 'needs_clarification',
                'message' => 'Please choose a base template by templateid, for '
                    . 'example: templateid=-1 (Template - Confirm booking).',
            ];

            $candidates = array_slice(
                $this->ruleservice->list_templates(),
                0,
                self::MAX_TEMPLATE_CANDIDATES_IN_CLARIFICATION
            );
            foreach ($candidates as $candidate) {
                $issues[] = [
                    'code' => 'TEMPLATE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'templateid=' . (int)($candidate['templateid'] ?? 0)
                        . ' name=' . (string)($candidate['name'] ?? ''),
                ];
            }

            return preflight_result_v2::invalid($issues);
        }

        $resolved = $this->ruleservice->resolve_template(
            $templateid,
            $templatequery
        );

        if (($resolved['status'] ?? '') === 'error') {
            $autoselected = $this->try_autoselect_confirmation_template(
                $templatequery,
                $rulename,
                (array)$this->ruleservice->list_templates()
            );
            if (is_array($autoselected)) {
                $prepared = $input;
                $prepared['templateid'] = (int)($autoselected['templateid'] ?? 0);
                $prepared['template_name_resolved'] = (string)($autoselected['name'] ?? '');
                return preflight_result_v2::ok($prepared);
            }

            $issues[] = [
                'code' => 'TEMPLATE_RESOLUTION_FAILED',
                'severity' => 'needs_clarification',
                'message' => (string)($resolved['message'] ?? 'Template konnte nicht aufgeloest werden.'),
            ];
            return preflight_result_v2::invalid($issues);
        }

        if (($resolved['status'] ?? '') === 'ambiguity') {
            $autoselected = $this->try_autoselect_confirmation_template(
                $templatequery,
                $rulename,
                (array)($resolved['candidates'] ?? [])
            );
            if (is_array($autoselected)) {
                $prepared = $input;
                $prepared['templateid'] = (int)($autoselected['templateid'] ?? 0);
                $prepared['template_name_resolved'] = (string)($autoselected['name'] ?? '');
                return preflight_result_v2::ok($prepared);
            }

            $issues[] = [
                'code' => 'TEMPLATE_RESOLUTION_AMBIGUOUS',
                'severity' => 'needs_clarification',
                'message' => (string)($resolved['message'] ?? 'Mehrere Templates passen.'),
            ];
            $candidates = array_slice(
                (array)($resolved['candidates'] ?? []),
                0,
                self::MAX_TEMPLATE_CANDIDATES_IN_CLARIFICATION
            );
            foreach ($candidates as $candidate) {
                $issues[] = [
                    'code' => 'TEMPLATE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'templateid=' . (int)($candidate['templateid'] ?? 0)
                        . ' name=' . (string)($candidate['name'] ?? ''),
                ];
            }
            return preflight_result_v2::invalid($issues);
        }

        $template = (array)($resolved['template'] ?? []);
        if (empty($template['templateid'])) {
            $issues[] = [
                'code' => 'TEMPLATE_MISSING',
                'severity' => 'needs_clarification',
                'message' => 'Template konnte nicht aufgeloest werden.',
            ];
            return preflight_result_v2::invalid($issues);
        }

        $prepared = $input;
        $prepared['templateid'] = (int)$template['templateid'];
        $prepared['template_name_resolved'] = (string)($template['name'] ?? '');

        return preflight_result_v2::ok($prepared);
    }

    /**
     * Task-specific ambiguity resolver for booking confirmation intents.
     *
     * Keeps generic resolver untouched and applies only to
     * booking.create_rule_from_template.
     *
     * @param string $templatequery
     * @param string $rulename
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    private function try_autoselect_confirmation_template(
        string $templatequery,
        string $rulename,
        array $candidates
    ): ?array {
        if (empty($candidates)) {
            return null;
        }

        $intenttext = $this->normalize_intent_text(trim($templatequery . ' ' . $rulename));
        if ($intenttext === '') {
            return null;
        }

        $isconfirmationintent = false;
        $confirmationneedles = [
            'buchungsbestaetigung',
            'buchungsbestatigung',
            'bestaetigung',
            'bestatigung',
            'booking confirmation',
            'confirm booking',
            'confirmation',
        ];
        foreach ($confirmationneedles as $needle) {
            if (strpos($intenttext, $needle) !== false) {
                $isconfirmationintent = true;
                break;
            }
        }

        if (!$isconfirmationintent) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $name = $this->normalize_intent_text((string)($candidate['name'] ?? ''));
            if (strpos($name, 'confirm booking') !== false || strpos($name, 'booking confirmation') !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Normalize free text for task-local intent matching.
     *
     * @param string $value
     * @return string
     */
    private function normalize_intent_text(string $value): string {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }

        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string)$value);

        return trim((string)$value);
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

        $result = $this->ruleservice->create_rule_from_template(
            $contextid,
            (int)($input['templateid'] ?? 0),
            $overrides
        );

        if (($result['status'] ?? '') !== 'ok') {
            $message = (string)($result['message'] ?? 'Regel konnte nicht erstellt werden.');
            return [
                'status' => 'failed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Create status: failed']),
            ];
        }

        $rule = (array)($result['rule'] ?? []);
        $name = (string)($rule['name'] ?? '');
        $ruleid = (int)($rule['id'] ?? 0);
        $link = $this->ruleservice->build_rules_link($cmid);

        $message = 'Regel erstellt: ' . $name . ' (ID ' . $ruleid . ').';

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => $ruleid,
            'rule' => $rule,
            'link' => $link,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Create status: ok',
                'Rule id: ' . $ruleid,
                'Rule name: ' . $name,
            ]),
        ];
    }
}
