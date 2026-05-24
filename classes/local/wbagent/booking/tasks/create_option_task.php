<?php
// This file is part of Moodle - http://moodle.org/
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

use bookingextension_agent\local\wbagent\booking\booking_task_mutation_execute_service;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Task definition for booking.create_option.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_task extends booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.create_option';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
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
        $properties = array_merge([
            'text' => [
                'type' => 'string',
                'description' => 'Title of the new booking option.',
                'required' => true,
            ],
            'override' => [
                'type' => 'array',
                'description' => 'Explicit override tokens for confirmed exceptions (e.g. duplicate_title).',
                'required' => false,
            ],
            'outputlang' => [
                'type' => 'string',
                'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                'required' => false,
            ],
        ], option_schema_definition::common_properties());

        // New options are always created hidden. Visibility can be changed later via booking.update_option only.
        unset($properties['invisible'], $properties['visibility']);

        $schema = [
            'version' => 1,
            'description' => 'Create a new booking option inside the current booking instance. '
                . 'Use this task for standard options (normal type with fixed dates/times). '
                . 'For slot-based appointment booking use booking.create_slotbooking_option. '
                . 'For self-learning courses use booking.create_selflearning_option. '
                . 'New options are always created as invisible.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_create_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_create_option',
            'properties' => $properties,
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.create_booking_request',
                'description' => 'User wants to create a booking or booking option
                    and includes scheduling details like date, time, duration, or participant count.
                    Treat booking/event/course/meeting synonymously.
                    For recurring series phrased as next week without explicit weekdays,
                    plan Monday-Friday only (5 occurrences).',
                'examples' => [
                    'I want a booking next Friday at 12h for one hour and eight people.',
                    'Create a booking for next Friday, 12:00 to 13:00, with 8 seats.',
                    'Eine Buchung nächsten Freitag um 12 Uhr, eine Stunde lang, für acht Leute.',
                    'Erstelle für die nächste Woche durchlaufend nummerierte Lecture x, immer von 20:00 bis 22:00, 20 Personen, Billy ist Trainer. -> create exactly 5 options (Mon-Fri).',
                ],
            ],
            [
                'id' => 'booking.force_create_duplicate_title',
                'description' => 'User explicitly confirms creating a new option although a duplicate title exists.',
            ],
            [
                'id' => 'booking.skip_location_specification',
                'description' => 'User explicitly confirms creating a normal option without location/address.',
            ],
            [
                'id' => 'booking.create_location_first_then_option',
                'description' => 'User asks to create/resolve a missing location first and then continue with option creation.',
            ],
            [
                'id' => 'booking.select_option_type_change',
                'description' => 'User explicitly selects or confirms option type. '
                    . 'Use booking.create_option for normal options only. '
                    . 'Use booking.create_slotbooking_option for slot-based appointment booking. '
                    . 'Use booking.create_selflearning_option for self-learning courses.',
                'examples' => [
                    'My tennis court should be bookable every weekday from 10 a.m. to 6 p.m., '
                        . 'in 1-hour slots. Create the booking availability for July.',
                ],
            ],
        ];
    }

    /**
     * Normalize common LLM aliases to the canonical create_option schema.
     *
     * @param array $input
     * @param array<string,string> $appliedaliases Canonical key => alias key actually used.
     * @return array
     */
    private static function normalize_create_option_input(array $input, array &$appliedaliases = []): array {
        $aliasgroups = [
            'text' => ['title', 'name', 'optionname', 'option_title', 'identifier', 'label', 'option_name'],
            'maxanswers' => ['limit', 'limitanswers', 'spots', 'capacity', 'maxparticipants', 'max_participants'],
            'coursestarttime' => ['starttime', 'start', 'from'],
            'courseendtime' => ['endtime', 'end', 'to'],
            'teacherquery' => ['teacher', 'trainer', 'instructor', 'instructorty', 'lecturer', 'dozent'],
            'teacheremail' => ['instructoremail', 'teacher_mail', 'teacher_email'],
        ];

        foreach ($aliasgroups as $canonical => $aliases) {
            if (!self::is_placeholder_value($input[$canonical] ?? null)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if (!array_key_exists($alias, $input) || self::is_placeholder_value($input[$alias])) {
                    continue;
                }

                $input[$canonical] = $input[$alias];
                $appliedaliases[$canonical] = $alias;
                break;
            }
        }

        // Fuzzy fallback for unpredictable planner key variants (e.g. traineruserwm).
        foreach (array_keys($input) as $rawkey) {
            if (!is_string($rawkey) || $rawkey === '') {
                continue;
            }
            if ($rawkey === 'teacherquery' || $rawkey === 'teacheremail') {
                continue;
            }

            $value = $input[$rawkey] ?? null;
            if (self::is_placeholder_value($value)) {
                continue;
            }

            $keynorm = strtolower((string)preg_replace('/[^a-z0-9]+/', '', $rawkey));
            $ispeoplehint = (bool)preg_match('/teacher|trainer|instructor|lecturer|dozent/', $keynorm);
            if (!$ispeoplehint) {
                continue;
            }

            if (self::is_placeholder_value($input['teacherquery'] ?? null)) {
                $input['teacherquery'] = $value;
                $appliedaliases['teacherquery'] = $rawkey;
                unset($input[$rawkey]);
                continue;
            }

            if (
                self::is_placeholder_value($input['teacheremail'] ?? null)
                && is_string($value)
                && strpos($value, '@') !== false
            ) {
                $input['teacheremail'] = $value;
                $appliedaliases['teacheremail'] = $rawkey;
                unset($input[$rawkey]);
            }
        }

        foreach ($aliasgroups as $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $input)) {
                    unset($input[$alias]);
                }
            }
        }

        return $input;
    }

    /**
     * Remove common non-schema noise keys emitted by LLM planners.
     *
     * @param array $input
     * @return array
     */
    private static function strip_llm_noise_keys(array $input): array {
        foreach (['booking_closed', 'booking_open', 'booking_opened', 'meta', 'metadata',
            'confirmed', 'is_confirmed', 'confirm', 'acknowledged', 'approved', 'status'] as $noisekey) {
            if (array_key_exists($noisekey, $input)) {
                unset($input[$noisekey]);
            }
        }

        return $input;
    }

    /**
     * Build a compact retry message explaining canonical create-option keys.
     *
     * @param array<string,string> $appliedaliases Canonical key => alias key actually used.
     * @param array<int,string> $unknownprops
     * @param array<int,string> $missingprops
     * @return string
     */
    private function build_create_option_retry_message(
        array $appliedaliases,
        array $unknownprops = [],
        array $missingprops = [],
        bool $includeenlabelkeymap = false
    ): string {
        $parts = [
            'Task execution was not successful.',
            'Retry ' . static::TASK_NAME . ' once with corrected canonical keys.',
        ];

        if ($includeenlabelkeymap) {
            $parts[] = 'Use canonical property keys only; do not use localized labels.';
            $parts[] = 'If you need the full EN label -> key map, ask with: FULL_KEY_MAP ' . static::TASK_NAME . '.';
        }

        if (static::TASK_NAME === 'booking.create_slotbooking_option') {
            $parts[] = 'For slot booking include at least: text, slot_valid_from, slot_valid_until, '
                . 'slot_opening_time, slot_closing_time, slot_duration_minutes, '
                . 'slot_max_participants_per_slot, and one active slot_day_X=true.';
        }

        if (!empty($appliedaliases)) {
            $mapped = [];
            foreach ($appliedaliases as $canonical => $alias) {
                $mapped[] = $alias . ' -> ' . $canonical;
            }
            $parts[] = 'Applied alias mapping: ' . implode(', ', $mapped) . '.';
        }

        if (!empty($missingprops)) {
            $parts[] = 'Still missing required fields: ' . implode(', ', $missingprops) . '.';
        }

        if (!empty($unknownprops)) {
            $parts[] = 'Remove unknown keys: ' . implode(', ', $unknownprops) . '.';
        }

        $parts[] = 'Resend exactly one corrected task_call.';

        return implode(' ', $parts);
    }

    /**
     * Build a key reference from schema + localized labels.
     *
     * @param bool $withdescriptions
     * @param bool $labelstokey
     * @return string
     */
    private function build_supported_property_reference(
        bool $withdescriptions = true,
        bool $labelstokey = false
    ): string {
        $schema = $this->get_schema();
        $properties = (array)($schema['properties'] ?? []);
        if (empty($properties)) {
            return '';
        }

        $entries = [];
        foreach ($properties as $key => $definition) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $label = trim((string)booking_task_support::get_localized_property_label_for_output_in_language($key, 'en'));
            $description = trim((string)($definition['description'] ?? ''));

            if ($labelstokey) {
                $entry = ($label !== '' ? $label : $key) . ' -> ' . $key;
            } else {
                $entry = $key;
                if ($label !== '' && $label !== $key) {
                    $entry .= ' (' . $label . ')';
                }
                if ($withdescriptions && $description !== '') {
                    $entry .= ': ' . $description;
                }
            }

            $entries[] = $entry;
        }

        if (empty($entries)) {
            return '';
        }

        return implode($withdescriptions ? ' | ' : ', ', $entries);
    }

    /**
     * Structural validation - pure, no DB access.
     *
     * Checks that the required 'text' (title) field is present.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>,observation_full?:string}
     */
    public function check_structure(array $input): array {
        $rawinput = $input;
        $appliedaliases = [];
        $input = self::normalize_create_option_input($input, $appliedaliases);
        $input = self::strip_create_targeting_fields($input);
        $rawinput = self::strip_create_targeting_fields($rawinput);
        $input = self::strip_llm_noise_keys($input);
        $rawinput = self::strip_llm_noise_keys($rawinput);

        if (static::TASK_NAME === 'booking.create_slotbooking_option') {
            unset($input['optiontype'], $input['slot_enabled']);
            unset($rawinput['optiontype'], $rawinput['slot_enabled']);
        }

        $resolvedtype = self::resolve_requested_option_type($input);
        if (static::TASK_NAME === 'booking.create_slotbooking_option') {
            $resolvedtype = 'slotbooking';
        }

        $text = trim((string)($input['text'] ?? ''));
        $missingtitle = ($text === '');
        $unknownprops = $this->get_unknown_input_property_names($input);
        $retrymessage = $this->build_create_option_retry_message(
            $appliedaliases,
            $unknownprops,
            $missingtitle ? ['text'] : []
        );

        if ($missingtitle) {
            $observation = $this->build_unknown_property_observation($unknownprops, $rawinput, ['text']);
            return [
                'valid'  => false,
                'errors' => [get_string('agent_booking_create_option_missing_title', 'bookingextension_agent'), $retrymessage],
                'observation_full' => $observation,
            ];
        }

        $commonerrors = $this->validate_common_mutation_structure($input, true);
        if (!empty($commonerrors)) {
            return [
                'valid' => false,
                'errors' => $commonerrors,
            ];
        }

        if (!empty($unknownprops)) {
            $observation = $this->build_unknown_property_observation($unknownprops, $rawinput);
            return [
                'valid' => false,
                'errors' => [$observation],
                'observation_full' => $observation,
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Return unknown input keys that are not part of the create_option schema.
     *
     * @param array $input
     * @return array<int,string>
     */
    private function get_unknown_input_property_names(array $input): array {
        $supported = array_flip($this->get_supported_property_names());
        $unknown = [];

        foreach (array_keys($input) as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!isset($supported[$key])) {
                $unknown[] = $key;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Return the full create_option schema property names.
     *
     * @return array<int,string>
     */
    private function get_supported_property_names(): array {
        $schema = $this->get_schema();
        $properties = array_keys((array)($schema['properties'] ?? []));
        $properties = array_values(array_filter(array_map('strval', $properties)));
        sort($properties);
        return $properties;
    }

    /**
     * Build long-form observation text for schema-mismatch retries.
     *
     * @param array<int,string> $unknownprops
     * @param array $input
     * @param array<int,string> $missingprops
     * @return string
     */
    private function build_unknown_property_observation(
        array $unknownprops,
        array $input,
        array $missingprops = []
    ): string {
        $unknowntext = empty($unknownprops) ? '(none)' : implode(', ', $unknownprops);
        $message = 'Task execution was not successful. Create option schema mismatch. Unknown properties: '
            . $unknowntext . '.';

        $appliedaliases = [];
        $normalized = self::normalize_create_option_input($input, $appliedaliases);
        unset($normalized);

        return trim(implode(' ', array_filter([
            $message,
            $this->build_create_option_retry_message($appliedaliases, $unknownprops, $missingprops, true),
        ])));
    }

    /**
     * Build a concise preflight hint for missing/confirmable fields.
     *
     * @param array<int,string> $missingrequired
     * @param array<int,string> $confirmablewithoutfields
     * @return string
     */
    private function build_missing_fields_preflight_hint(
        array $missingrequired,
        array $confirmablewithoutfields = []
    ): string {
        $parts = ['Preflight: ' . static::TASK_NAME . ' needs additional input.'];

        if (!empty($missingrequired)) {
            $parts[] = 'Missing required fields: ' . implode(', ', array_values(array_unique($missingrequired))) . '.';
        }

        if (!empty($confirmablewithoutfields)) {
            $parts[] = 'Can continue without these if user confirms: '
                . implode(', ', array_values(array_unique($confirmablewithoutfields))) . '.';
        }

        $parts[] = 'Either provide the missing fields or confirm to proceed without the confirmable ones.';

        return implode(' ', $parts);
    }

    /**
     * Deep preflight validation — duplicate-title check, type-specific fields, slot sanity.
     *
     * Returns prepared_input ready for execute() with normalised overrides applied.
     * Does NOT perform writes.
     *
     * @param  array $input
     * @param  int   $cmid
     * @param  int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
        $lang = $this->get_output_language($input);
        $issues = [];
        $errors = [];
        $appliedaliases = [];
        $input = self::normalize_create_option_input($input, $appliedaliases);
        $input = self::strip_create_targeting_fields($input);
        $input = self::strip_llm_noise_keys($input);

        // Title is required (structural, but re-check for safety).
        if (empty($input['text'])) {
            $retrymessage = $this->build_create_option_retry_message($appliedaliases, [], ['text']);
            $issues[] = [
                'code'           => 'MISSING_TITLE',
                'severity'       => 'needs_clarification',
                'message'        => $retrymessage !== ''
                    ? $retrymessage
                    : $this->localized_string('agent_booking_create_option_missing_title', null, $lang),
                'user_question'  => $this->localized_string('agent_booking_create_option_which_title_question', null, $lang),
                'remedy_options' => ['ASK_TITLE'],
            ];
            return preflight_result_v2::invalid($issues);
        }

        $overrides = self::normalize_overrides(is_array($input['override'] ?? null) ? $input['override'] : []);

        // Duplicate-title check.
        $duplicatecheck = booking_task_support::find_existing_options_by_exact_title($cmid, (string)$input['text']);
        $allowduplicatetitle = in_array('duplicate_title', $overrides, true);
        if (!$allowduplicatetitle && ($duplicatecheck['status'] ?? '') === 'single') {
            $existingid = (int)($duplicatecheck['optionid'] ?? 0);
            $issues[] = [
                'code'           => 'DUPLICATE_TITLE_CONFIRM_REQUIRED',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string('agent_booking_create_option_exists_single', $existingid, $lang),
                'user_question'  => $this->localized_string(
                    'agent_booking_create_option_duplicate_exists_single_question',
                    null,
                    $lang
                ),
                'remedy_options' => ['CONFIRM_CREATE_WITH_DUPLICATE_TITLE', 'UPDATE_EXISTING_INSTEAD'],
            ];
            return preflight_result_v2::invalid($issues);
        } else if (!$allowduplicatetitle && ($duplicatecheck['status'] ?? '') === 'multiple') {
            $issues[] = [
                'code'           => 'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string(
                    'agent_booking_create_option_exists_multiple',
                    (string)($duplicatecheck['candidates'] ?? ''),
                    $lang
                ),
                'user_question'  => $this->localized_string(
                    'agent_booking_create_option_duplicate_exists_multiple_question',
                    null,
                    $lang
                ),
                'remedy_options' => ['CONFIRM_CREATE_WITH_DUPLICATE_TITLE', 'SELECT_EXISTING_OPTION_TO_UPDATE'],
            ];
            return preflight_result_v2::invalid($issues);
        }

        $resolvedtype = self::resolve_requested_option_type($input);
        if ($resolvedtype === 'unknown') {
            $resolvedtype = 'normal';
        }

        // Intentionally no required-field preflight beyond title.
        // Missing teacher/location/schedule/capacity must not block creation.

        // Slot-booking sanity (soft issues only).
        if ($resolvedtype === 'slotbooking') {
            $slotissues = self::validate_slotbooking_sanity($input);
            if (!empty($slotissues)) {
                $issues = array_merge($issues, $slotissues);
            }
        }

        // Placeholder value check.
        $placeholderfields = self::check_placeholder_values($input, $overrides, $resolvedtype, $lang);
        if (!empty($placeholderfields)) {
            $issues[] = [
                'code'           => 'CONFIRMATION_REQUIRED',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string('agent_booking_create_option_confirm_missing_values', null, $lang),
                'user_question'  => $this->localized_string('agent_booking_create_option_confirm_missing_values', null, $lang),
                'remedy_options' => ['ADD_OVERRIDE_AND_RETRY'],
            ];
            foreach ($placeholderfields as $err) {
                $issues[] = ['code' => 'PLACEHOLDER_VALUE', 'severity' => 'needs_clarification', 'message' => $err];
            }
        }

        // Location soft-confirmation.
        $allowemptylocation = in_array('location', $overrides, true) || in_array('address', $overrides, true);
        if (!$allowemptylocation && isset($input['location']) && trim((string)$input['location']) !== '') {
            $issues[] = [
                'code'           => 'LOCATION_NOT_FOUND_POSSIBLE',
                'severity'       => 'needs_confirmation',
                'message'        => $this->localized_string(
                    'agent_booking_create_option_location_not_found_question',
                    null,
                    $lang
                ),
                'user_question'  => $this->localized_string(
                    'agent_booking_create_option_location_not_found_question',
                    null,
                    $lang
                ),
                'remedy_options' => ['CREATE_LOCATION_THEN_CREATE_OPTION', 'ASK_FOR_DIFFERENT_LOCATION'],
            ];
        }

        // Service-level preflight (teacher resolution, date normalization, etc.).
        $service = new booking_task_mutation_execute_service();
        $servicepreflight = $service->preflight_validate(self::TASK_NAME, $input, $cmid, $userid);
        if (!empty($servicepreflight['errors']) || !empty($servicepreflight['ambiguities'])) {
            $serviceissuecodes = array_values(array_filter(array_map('strval', (array)($servicepreflight['issue_codes'] ?? []))));
            foreach ((array)($servicepreflight['errors'] ?? []) as $idx => $err) {
                $issues[] = [
                    'code' => (string)($serviceissuecodes[$idx] ?? 'PREFLIGHT_ERROR'),
                    'severity' => 'needs_clarification',
                    'message' => (string)$err,
                ];
            }
            foreach ((array)($servicepreflight['ambiguities'] ?? []) as $amb) {
                $issues[] = ['code' => 'PREFLIGHT_AMBIGUITY', 'severity' => 'needs_clarification', 'message' => (string)$amb];
            }
            return preflight_result_v2::invalid($issues);
        }

        $preparedinput = is_array($servicepreflight['normalized_input'] ?? null)
            ? (array)$servicepreflight['normalized_input']
            : $input;

        // If there are only confirmable issues (no blocking ones), use confirmable().
        $blockingissues = array_filter(
            $issues,
            static fn(array $i): bool => ($i['severity'] ?? '') === 'needs_clarification'
        );
        if (!empty($blockingissues)) {
            return preflight_result_v2::invalid($issues);
        }
        if (!empty($issues)) {
            return preflight_result_v2::confirmable($preparedinput, $issues);
        }

        return preflight_result_v2::ok($preparedinput);
    }

    /**
     * Build a structured issue payload for downstream user-facing clarification logic.
     *
     * @param string $code
     * @param string $question
     * @param array $remedies
     * @return array
     */
    private static function build_issue(string $code, string $question, array $remedies = []): array {
        return [
            'code' => $code,
            'severity' => 'needs_confirmation',
            'user_question' => $question,
            'remedy_options' => $remedies,
        ];
    }

    /**
     * Returns true when any of the given keys exists in input.
     *
     * @param array $input
     * @param array $keys
     * @return bool
     */
    private static function has_any_key(array $input, array $keys): bool {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve requested booking option type from normalized AI input.
     *
     * @param array $input
     * @return string One of normal|selflearning|slotbooking|unknown.
     */
    private static function resolve_requested_option_type(array $input): string {
        if (!empty($input['slot_enabled'])) {
            return 'slotbooking';
        }

        if (array_key_exists('optiontype', $input)) {
            $normalized = strtolower(trim((string)$input['optiontype']));
            if (in_array($normalized, ['0', 'normal', 'withdates', 'with_dates', 'default'], true)) {
                return 'normal';
            }
            if (in_array($normalized, ['1', 'selflearning', 'self-learning', 'selflearningcourse'], true)) {
                return 'selflearning';
            }
            if (in_array($normalized, ['2', 'slot', 'slotbooking', 'slot-booking'], true)) {
                return 'slotbooking';
            }
        }

        if (!empty($input['selflearningcourse'])) {
            return 'selflearning';
        }

        // Keep type resolution strictly schema-driven. Do not infer control flow from
        // free text or language-specific tokens.

        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                return 'slotbooking';
            }
        }

        return 'unknown';
    }

    /**
     * Validate missing required input depending on the selected option type.
     *
     * @param array $input
     * @param string $resolvedtype
     * @param array $overrides
     * @return array
     */
    private static function validate_type_specific_required_fields(
        array $input,
        string $resolvedtype,
        array $overrides = []
    ): array {
        $errors = [];

        if ($resolvedtype === 'normal') {
            if (!array_key_exists('maxanswers', $input)) {
                $errors[] = get_string('agent_booking_create_normal_missing_maxanswers', 'bookingextension_agent');
            }

            $hasoptiondates = self::has_any_key($input, ['optiondates']);
            $hassinglestart = self::has_any_key($input, ['coursestarttime']);

            if (!$hasoptiondates && !$hassinglestart) {
                $errors[] = get_string('agent_booking_create_normal_missing_startdate', 'bookingextension_agent');
            }

            if (!$hasoptiondates && !self::has_any_key($input, ['duration', 'courseendtime'])) {
                $errors[] = get_string('agent_booking_create_normal_missing_duration', 'bookingextension_agent');
            }

            $allowemptylocation = in_array('location', $overrides, true) || in_array('address', $overrides, true);
            if (!$allowemptylocation && !self::has_any_key($input, ['location', 'address'])) {
                $errors[] = get_string('agent_booking_create_normal_missing_location', 'bookingextension_agent');
            }

            $allowemptyteacher = in_array('teacherquery', $overrides, true) || in_array('teacheremail', $overrides, true);
            if (!$allowemptyteacher && !self::has_any_key($input, ['teacherquery', 'teacheremail'])) {
                $errors[] = get_string('agent_booking_create_normal_missing_teacher', 'bookingextension_agent');
            }

            return $errors;
        }

        if ($resolvedtype === 'selflearning') {
            if (!array_key_exists('maxanswers', $input)) {
                $errors[] = get_string('agent_booking_create_selflearning_missing_maxanswers', 'bookingextension_agent');
            }

            if (!array_key_exists('duration', $input)) {
                $errors[] = get_string('agent_booking_create_selflearning_missing_duration', 'bookingextension_agent');
            }

            if (!self::has_any_key($input, ['teacherquery', 'teacheremail'])) {
                $errors[] = get_string('agent_booking_create_selflearning_missing_teacher', 'bookingextension_agent');
            }

            return $errors;
        }

        if ($resolvedtype === 'slotbooking') {
            $slotweekdaykeys = [
                'slot_day_1', 'slot_day_2', 'slot_day_3', 'slot_day_4', 'slot_day_5', 'slot_day_6', 'slot_day_7',
            ];

            $slottype = strtolower(trim((string)($input['slot_type'] ?? 'fixed')));

            if ($slottype === 'userdefined') {
                if ((int)($input['slot_custom_max_duration'] ?? 0) <= 0) {
                    $errors[] = get_string('agent_booking_create_slotbooking_missing_custom_duration', 'bookingextension_agent');
                }
            } else {
                // Required: slot duration (how long is each slot).
                if (!array_key_exists('slot_duration_minutes', $input)) {
                    $errors[] = get_string('agent_booking_create_slotbooking_missing_duration', 'bookingextension_agent');
                }
            }

            // Required: max participants per slot.
            if (!array_key_exists('slot_max_participants_per_slot', $input)) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_participants', 'bookingextension_agent');
            }

            // Required: time window.
            if (!self::has_any_key($input, ['slot_opening_time', 'slot_closing_time'])) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_timewindow', 'bookingextension_agent');
            }

            // Required: validity date range.
            if (!self::has_any_key($input, ['slot_valid_from', 'slot_valid_until'])) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_validity', 'bookingextension_agent');
            }

            // Required: at least one weekday must be EXPLICITLY set to true.
            $hasactiveday = false;
            foreach ($slotweekdaykeys as $dk) {
                if (!empty($input[$dk])) {
                    $hasactiveday = true;
                    break;
                }
            }
            if (!$hasactiveday) {
                $errors[] = get_string('agent_booking_create_slotbooking_missing_weekday', 'bookingextension_agent');
            }

            return $errors;
        }

        return $errors;
    }

    /**
     * Smart sanity check for slotbooking configuration.
     * Only checks things that cannot be expressed as hard validation errors.
     *
     * @param array $input
     * @return array Array of issues.
     */
    private static function validate_slotbooking_sanity(array $input): array {
        $issues = [];

        // Check: slot_duration_minutes covers the entire daily window → only 1 slot per day.
        // The user likely confused the time window with the individual slot length.
        if (
            isset($input['slot_opening_time'], $input['slot_closing_time'], $input['slot_duration_minutes'])
        ) {
            $openingmins = self::parse_time_hhmm_to_minutes((string)$input['slot_opening_time']);
            $closingmins = self::parse_time_hhmm_to_minutes((string)$input['slot_closing_time']);
            $windowmins  = $closingmins - $openingmins;
            $slotmins    = (int)$input['slot_duration_minutes'];

            if ($windowmins > 0 && $slotmins >= $windowmins) {
                $issues[] = self::build_issue(
                    'SLOTBOOKING_DURATION_EQUALS_WINDOW',
                    'The slot duration (' . $slotmins . ' min) covers the entire availability window '
                        . $input['slot_opening_time'] . '-' . $input['slot_closing_time']
                        . ' (' . $windowmins . ' min), which would create only 1 slot per day. '
                        . 'Did you mean a shorter slot duration (e.g. 30 minutes), '
                        . 'or do you really want just 1 slot covering the whole window?',
                    ['SET_SHORTER_SLOT_DURATION', 'CONFIRM_SINGLE_SLOT_PER_DAY']
                );
            }
        }

        return $issues;
    }

    /**
     * Parse a HH:MM string to total minutes since midnight.
     *
     * @param string $hhmm e.g. "12:00" or "16:30"
     * @return int Minutes since midnight, or 0 on parse failure.
     */
    private static function parse_time_hhmm_to_minutes(string $hhmm): int {
        $parts = explode(':', trim($hhmm));
        if (count($parts) < 2) {
            return 0;
        }
        return (int)$parts[0] * 60 + (int)$parts[1];
    }


    /**
     * Check for placeholder values (0, empty string, null) and require override confirmation.
     *
     * @param array $input
     * @param array $overrides
     * @param string $resolvedtype
     * @param string $lang
     *
     * @return array
     *
     */
    private static function check_placeholder_values(
        array $input,
        array $overrides,
        string $resolvedtype = 'normal',
        string $lang = ''
    ): array {
        $errors = [];

        // Define field pairs where at least one should have a real value.
        $fieldpairs = [
            ['coursestarttime', 'optiondates'],
            ['duration', 'courseendtime'],
            ['location', 'address'],
            ['teacherquery', 'teacheremail'],
        ];

        if ($resolvedtype !== 'slotbooking') {
            $fieldpairs[] = 'maxanswers';
        }

        // Field labels for friendly messages.
        $labels = [
            'coursestarttime' => 'start date/time',
            'optiondates' => 'date ranges',
            'duration' => 'duration',
            'courseendtime' => 'end date/time',
            'location' => 'location',
            'address' => 'address',
            'teacherquery' => 'teacher',
            'teacheremail' => 'teacher email',
            'maxanswers' => 'max participants',
        ];

        foreach ($fieldpairs as $pair) {
            if (is_string($pair)) {
                // Single required field like 'maxanswers'.
                if (array_key_exists($pair, $input)) {
                    $val = $input[$pair];
                    if (self::is_placeholder_value($val)) {
                        if (!in_array($pair, $overrides, true)) {
                            $label = $labels[$pair] ?? $pair;
                            $errors[] = get_string_manager()->get_string(
                                'agent_booking_create_option_placeholder_override_required_single',
                                'bookingextension_agent',
                                (object)['label' => $label, 'field' => $pair],
                                $lang
                            );
                        }
                    }
                }
            } else {
                // Pair of fields - check if both are placeholders.
                $pairvalues = [];
                $pairhaspair = false;
                foreach ((array)$pair as $fieldname) {
                    if (array_key_exists($fieldname, $input)) {
                        $pairhaspair = true;
                        $val = $input[$fieldname];
                        $pairvalues[$fieldname] = self::is_placeholder_value($val);
                    }
                }

                if ($pairhaspair && count($pairvalues) > 0) {
                    // Check if any field in the pair has a non-placeholder value.
                    $hasrealvalue = false;
                    $placeholderfields = [];
                    foreach ($pairvalues as $fieldname => $isplaceholder) {
                        if ($isplaceholder) {
                            $placeholderfields[] = $fieldname;
                        } else {
                            $hasrealvalue = true;
                        }
                    }

                    // If all fields in the pair are placeholders and no real value found.
                    if (!$hasrealvalue && !empty($placeholderfields)) {
                        $needsoverride = [];
                        $labelssubset = [];
                        foreach ($placeholderfields as $fieldname) {
                            if (!in_array($fieldname, $overrides, true)) {
                                $needsoverride[] = $fieldname;
                                $labelssubset[] = $labels[$fieldname] ?? $fieldname;
                            }
                        }

                        if (!empty($needsoverride)) {
                            $desc = implode(' or ', $labelssubset);
                            $fieldlist = implode('", "', $needsoverride);
                            $errors[] = get_string_manager()->get_string(
                                'agent_booking_create_option_placeholder_override_required',
                                'bookingextension_agent',
                                (object)['labels' => $desc, 'fields' => $fieldlist],
                                $lang
                            );
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Check if a value is a placeholder (0, '0', empty string, null).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_placeholder_value($value): bool {
        return $value === 0 || $value === '0' || $value === '' || $value === null || (is_array($value) && empty($value));
    }

    /**
     * Whether a normalized field has a non-empty meaningful value.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_non_empty_value(mixed $value): bool {
        if (self::is_placeholder_value($value)) {
            return false;
        }

        return trim((string)$value) !== '';
    }

    /**
     * Normalize override tokens to lowercase, trimmed strings.
     *
     * @param array $overrides
     * @return array
     */
    private static function normalize_overrides(array $overrides): array {
        $normalized = [];
        foreach ($overrides as $override) {
            if (!is_string($override)) {
                continue;
            }

            $token = strtolower(trim($override));
            if ($token === '') {
                continue;
            }
            $normalized[] = $token;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.create_option_required_details',
                'triggers' => [
                    'create option', 'new option', 'create booking option', 'buchungsoption erstellen',
                    'buchung', 'booking', 'buchungsmöglichkeit', 'bookingsmoeglichkeit',
                    'erstelle', 'mach mir', 'make me', 'add option', 'buche',
                ],
                'guidance' => [
                    '- For mutating intent, prepare booking.create_option and use confirmation_request first.',
                    '- booking.create_option always creates options as invisible.',
                    '- If the user explicitly wants the option visible, first create it, then run booking.update_option '
                        . 'to set visibility/invisible.',
                    '- For create requests, set optiontype explicitly whenever possible: normal|selflearning|slotbooking.',
                    '- Use this task for normal options. For slotbooking use booking.create_slotbooking_option.',
                    '- For self-learning use booking.create_selflearning_option.',
                    '- Do not ask end users for internal type names; infer the best type from intent and phrasing.',
                    '- If type is still unclear, ask behavior-focused questions (e.g. fixed dates, self-paced, or bookable slots),'
                        . ' not technical labels.',
                    '- Do not ask whether to create or update when the user asks to create a booking possibility.',
                    '- If validation asks for confirmation, do not invent new wording; follow the issue question.',
                    '- To proceed after explicit user confirmation of exceptions, retry with matching override tokens.',
                    '- Known override tokens in create flow include: duplicate_title, coursestarttime, duration,',
                    '  location, address, teacherquery, teacheremail, maxanswers.',
                    '- Prefer concise clarification questions; avoid technical text in user-facing message.',
                ],
            ],
            [
                'id' => 'booking.course_teacher',
                'triggers' => ['course', 'kurs', 'teacher', 'dozent', 'trainer', 'lehrer'],
                'guidance' => [
                    '- Use coursequery to connect an option to a Moodle course.',
                    '- If you know only the course name (not its id), call booking.search_courses FIRST,',
                    '  wait for the observation with the resolved courseid, then proceed with create_option.',
                    '- Use teacherquery or teacheremail to assign responsible teacher.',
                    '- If the user says to assign themselves as teacher (e.g. "me as teacher", "mich als Lehrer"),'
                        . ' set teacherquery to the current user/self-reference instead of asking for an e-mail address.',
                    '- Only ask for teacheremail when no resolvable teacher name or self-reference was provided.',
                ],
            ],
            [
                'id' => 'booking.availability_conditions',
                'triggers' => [
                    'availability', 'verfugbarkeit', 'verfügbarkeit', 'enrolled', 'cohort', 'competency',
                    'previously booked', 'overlapping', 'profile field', 'condition', 'einschrankung', 'einschränkung',
                ],
                'guidance' => [
                    '- Use enrolledincoursequery (+ optional enrolledincourseoperator) for enrolled-in-course condition.',
                    '- Use enrolledincohortquery (+ optional enrolledincohortoperator) for cohort condition.',
                    '- Use hascompetencyquery (+ optional hascompetencyoperator) for competency condition.',
                    '- Use previouslybookedquery (+ optional previouslybookedrequirecompletion) for prerequisites.',
                    '- Use selectusersquery for explicit allowlist condition.',
                    '- Use nooverlappingmode with "block" or "warn".',
                    '- Use allowedtobookininstance (+ optional allowedtobookininstancecapabilitynotneeded).',
                    '- Use userprofilestandard* and userprofilecustom* fields for profile-based conditions.',
                ],
            ],
            [
                'id' => 'booking.selflearning_cancel',
                'triggers' => ['self-learning', 'selflearning', 'duration', 'hours', 'cancel', 'storno', 'stornieren'],
                'guidance' => [
                    '- For self-learning options use selflearningcourse=true with duration in seconds (e.g. 4h = 14400).',
                    '- To allow self-cancellation, keep disablecancel absent or false.',
                    '- Set disablecancel=true to prevent participants from cancelling themselves.',
                ],
            ],
            [
                'id' => 'booking.bookusers',
                'triggers' => ['book user', 'book users', 'buche', 'teilnehmer buchen', 'assign user', 'enrol user'],
                'guidance' => [
                    '- To book users directly to an option, use bookusersquery in booking.create_option'
                        . ' or booking.update_option.',
                    '- If the user already provided a person name (e.g. "Billy Teachy"), pass it directly as bookusersquery.',
                    '- For utterances like "buche <person> in die option <option>", map <person> -> bookusersquery and'
                        . ' <option> -> optionquery.',
                    '- Do not ask for user id or e-mail when a name query is already present.',
                    '- Ask for a more specific user identifier only after a real ambiguity (multiple matched users).',
                    '- Optional fields: bookuserstimebooked, bookuserscompleted, bookusersupdateexisting.',
                    '- For pure booking in booking.update_option, do not include'
                        . ' additional option-update fields in the same command.',
                ],
            ],
            [
                'id' => 'booking.datetime',
                'triggers' => ['date', 'time', 'datum', 'uhrzeit', 'tomorrow', 'today', 'next', 'morgen', 'heute'],
                'guidance' => [
                    '- Resolve relative dates against current Moodle timezone and current datetime from system prompt.',
                    '- Prefer ISO 8601 for date/time fields in command input.',
                    '- Never use old hardcoded timestamps from examples.',
                    '- For recurring series phrased as "next week" without explicit weekday constraints, default to Monday-Friday (5 occurrences).',
                    '- If a resolved date appears in the past and user did not ask for past dates, ask clarification.',
                ],
            ],
            [
                'id' => 'booking.customform',
                'triggers' => [
                    'custom form', 'customform', 'formular', 'form element', 'formelement', 'bo_cond',
                    'checkbox', 'dropdown', 'select element',
                ],
                'guidance' => [
                    '- For custom form conditions, use "customformelements" with one item per row.',
                    '- Supported formtype values: advcheckbox, static, shorttext, select, url, mail,'
                        . ' deleteinfoscheckboxuser, enrolusersaction.',
                    '- Each customformelements item can include: label, value, required, enroluserstowaitinglist.',
                    '- For formtype "select", value must be a multiline string with one option per line.',
                    '- Select line formats: key => Display name; key => Display name => Max bookings => Price => Allowed user IDs.',
                    '- Key must not contain spaces or special characters.',
                ],
            ],
        ];
    }

    /**
     * Verify that relevant fields were persisted as requested.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }

    /**
     * Execute task using prepared_input from preflight().
     *
     * @param  array $preparedinput  Resolved input from preflight().
     * @param  int   $cmid
     * @param  int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array {
        $preparedinput = self::strip_create_targeting_fields($preparedinput);
        $service = new booking_task_mutation_execute_service();
        $result = $service->execute(self::TASK_NAME, $preparedinput, $cmid, $userid, $this->support);
        if (is_array($result)) {
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $preparedinput,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => $this->localized_string('agent_booking_unknown_task', self::TASK_NAME),
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $preparedinput, ['Status: error']),
        ];
    }

    /**
     * Drop targeting fields that belong to update/bulk flows.
     *
     * create_option always creates a fresh option and must never resolve an
     * existing option by query/id in preflight or execute.
     *
     * @param array $input
     * @return array
     */
    private static function strip_create_targeting_fields(array $input): array {
        unset($input['optionquery'], $input['optionid'], $input['optionwhen']);
        return $input;
    }
}
