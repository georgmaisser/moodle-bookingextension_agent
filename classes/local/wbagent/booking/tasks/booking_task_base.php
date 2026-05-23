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

use bookingextension_agent\local\wbagent\base_task;
use bookingextension_agent\local\wbagent\booking\booking_task_mutation_execute_service;
use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;

/**
 * Base task delegating schema, validation and execution to booking support logic.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class booking_task_base extends base_task {
    /** @var booking_task_support|null */
    private static ?booking_task_support $sharedsupport = null;

    /** @var booking_task_support */
    protected booking_task_support $support;

    /**
     * Prompt metadata map for all booking tasks.
     *
     * Maps task names to their input_fields_for_prompt and anchor_fields.
     * This allows task_registry to use these fields for prompt generation
     * instead of relying on hardcoded fallback logic.
     *
     * @var array<string,array<string,array<int,string>>>
     */
    protected static array $promptmeta = [
        'booking.create_option' => [
            'input_fields_for_prompt' => ['text'],
            'anchor_fields' => ['option'],
        ],
        'booking.create_slotbooking_option' => [
            'input_fields_for_prompt' => [
                'text',
                'slot_opening_time',
                'slot_closing_time',
                'slot_duration_minutes',
                'slot_valid_from',
                'slot_valid_until',
            ],
            'anchor_fields' => ['option'],
        ],
        'booking.create_selflearning_option' => [
            'input_fields_for_prompt' => ['text'],
            'anchor_fields' => ['option'],
        ],
        'booking.create_user' => [
            'input_fields_for_prompt' => ['userquery'],
            'anchor_fields' => ['user'],
        ],
        'booking.update_option' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'booking.bulk_update_options' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'booking.search_options' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'booking.get_option_details' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option'],
        ],
        'booking.search_users' => [
            'input_fields_for_prompt' => ['query'],
            'anchor_fields' => [],
        ],
        'booking.search_courses' => [
            'input_fields_for_prompt' => ['query'],
            'anchor_fields' => [],
        ],
        'booking.add_price_category' => [
            'input_fields_for_prompt' => ['name'],
            'anchor_fields' => [],
        ],
        'booking.list_option_properties' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'booking.list_actions' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'booking.get_current_user' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'booking.recreate_task_catalog' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => [],
        ],
        'booking.recall_memory' => [
            'input_fields_for_prompt' => ['mode', 'date_hint', 'query'],
            'anchor_fields' => [],
        ],
        'booking.explain_docs_topic' => [
            'input_fields_for_prompt' => ['question'],
            'anchor_fields' => [],
        ],
        'booking.diagnose_booking_issue' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option', 'user'],
        ],
        'booking.diagnose_cancellation_issue' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['option', 'user'],
        ],
        'booking.book_users' => [
            'input_fields_for_prompt' => ['bookusersquery'],
            'anchor_fields' => ['option', 'user'],
        ],
        'booking.core_get_user_profile' => [
            'input_fields_for_prompt' => ['userquery'],
            'anchor_fields' => ['user'],
        ],
        'booking.core_get_user_preferences' => [
            'input_fields_for_prompt' => ['userquery', 'prefkeys'],
            'anchor_fields' => ['user'],
        ],
        'booking.core_set_user_preference' => [
            'input_fields_for_prompt' => ['name', 'value'],
            'anchor_fields' => ['user'],
        ],
        'booking.core_get_user_enrolments' => [
            'input_fields_for_prompt' => ['userquery'],
            'anchor_fields' => ['user', 'course'],
        ],
        'booking.core_get_current_user' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['user'],
        ],
        'booking.core_enrol_user_manual' => [
            'input_fields_for_prompt' => ['userquery', 'coursequery', 'role'],
            'anchor_fields' => ['user', 'course'],
        ],
        'booking.core_unenrol_user_manual' => [
            'input_fields_for_prompt' => ['userquery', 'coursequery'],
            'anchor_fields' => ['user', 'course'],
        ],
        'booking.core_list_course_participants' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course', 'user'],
        ],
        'booking.core_get_user_roles_in_course' => [
            'input_fields_for_prompt' => ['coursequery', 'userquery'],
            'anchor_fields' => ['course', 'user'],
        ],
        'booking.core_search_course_enrolments' => [
            'input_fields_for_prompt' => ['coursequery', 'query'],
            'anchor_fields' => ['course', 'user'],
        ],
        'booking.core_list_course_groups' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course', 'group'],
        ],
        'booking.core_get_group_members' => [
            'input_fields_for_prompt' => ['coursequery', 'groupquery'],
            'anchor_fields' => ['course', 'group', 'user'],
        ],
        'booking.core_create_group' => [
            'input_fields_for_prompt' => ['coursequery', 'name'],
            'anchor_fields' => ['course', 'group'],
        ],
        'booking.core_update_group' => [
            'input_fields_for_prompt' => ['coursequery', 'groupquery'],
            'anchor_fields' => ['course', 'group'],
        ],
        'booking.core_delete_group' => [
            'input_fields_for_prompt' => ['coursequery', 'groupquery'],
            'anchor_fields' => ['course', 'group'],
        ],
        'booking.core_get_course_overview' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course'],
        ],
        'booking.core_list_course_sections' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course'],
        ],
        'booking.core_list_course_modules' => [
            'input_fields_for_prompt' => ['coursequery', 'section'],
            'anchor_fields' => ['course', 'module'],
        ],
        'booking.core_get_module_details' => [
            'input_fields_for_prompt' => ['cmid', 'coursequery', 'modulequery'],
            'anchor_fields' => ['course', 'module'],
        ],
        'booking.core_get_activity_completion_status' => [
            'input_fields_for_prompt' => ['coursequery', 'cmid', 'userquery'],
            'anchor_fields' => ['course', 'module', 'user'],
        ],
        'booking.core_get_user_completion_report' => [
            'input_fields_for_prompt' => ['coursequery', 'userquery'],
            'anchor_fields' => ['course', 'user'],
        ],
        'booking.core_list_course_calendar_events' => [
            'input_fields_for_prompt' => ['coursequery', 'timestart', 'timeend'],
            'anchor_fields' => ['course', 'event'],
        ],
        'booking.core_list_user_calendar_events' => [
            'input_fields_for_prompt' => ['userquery', 'timestart', 'timeend'],
            'anchor_fields' => ['user', 'event'],
        ],
        'booking.core_create_calendar_event' => [
            'input_fields_for_prompt' => ['title', 'timestart', 'timeend', 'coursequery'],
            'anchor_fields' => ['course', 'event'],
        ],
        'booking.core_update_calendar_event' => [
            'input_fields_for_prompt' => ['eventid'],
            'anchor_fields' => ['event'],
        ],
        'booking.core_delete_calendar_event' => [
            'input_fields_for_prompt' => ['eventid'],
            'anchor_fields' => ['event'],
        ],
        'booking.core_list_grade_items' => [
            'input_fields_for_prompt' => ['coursequery'],
            'anchor_fields' => ['course', 'grade'],
        ],
        'booking.core_get_user_grades_for_course' => [
            'input_fields_for_prompt' => ['coursequery', 'userquery'],
            'anchor_fields' => ['course', 'user', 'grade'],
        ],
        'booking.core_send_user_message' => [
            'input_fields_for_prompt' => ['recipient', 'message'],
            'anchor_fields' => ['user'],
        ],
        'booking.core_get_site_summary' => [
            'input_fields_for_prompt' => [],
            'anchor_fields' => ['site'],
        ],
    ];

    /**
     * Example input map for booking tasks.
     *
     * @var array<string,array<string,mixed>>
     */
    protected static array $exampleinput = [
        'booking.add_price_category' => [
            'identifier' => 'student',
            'name' => 'Student',
        ],
        'booking.analyze_rules' => [
            'query' => 'booking confirmation',
            'active_only' => true,
        ],
        'booking.book_users' => [
            'optionquery' => 'Geburtstag ANON_USER_1',
            'bookusersquery' => 'ANON_USER_1',
        ],
        'booking.bulk_update_options' => [
            'optionquery' => 'Geburtstag',
            'changes' => [['field' => 'text', 'value' => 'Updated title']],
        ],
        'booking.configure_booking_instance' => [
            'action' => 'update',
            'changes' => [['field' => 'limitanswers', 'value' => '1']],
        ],
        'booking.create_option' => [
            'text' => 'Geburtstag ANON_USER_1',
            'maxanswers' => 30,
            'coursestarttime' => '2026-12-12T20:00:00',
            'courseendtime' => '2026-12-12T22:00:00',
        ],
        'booking.create_slotbooking_option' => [
            'text' => 'Tennisplatz Slots Juli',
            'slot_opening_time' => '10:00',
            'slot_closing_time' => '18:00',
            'slot_duration_minutes' => 60,
            'slot_valid_from' => '2026-07-01',
            'slot_valid_until' => '2026-07-31',
            'slot_day_1' => true,
            'slot_day_2' => true,
            'slot_day_3' => true,
            'slot_day_4' => true,
            'slot_day_5' => true,
            'slot_day_6' => false,
            'slot_day_7' => false,
        ],
        'booking.create_selflearning_option' => [
            'text' => 'Selbstlernkurs ANON_USER_1',
            'maxanswers' => 30,
            'duration' => 14400,
            'teacherquery' => 'ANON_USER_1',
        ],
        'booking.create_rule_from_template' => [
            'templatequery' => 'booking confirmation',
            'rulename' => 'Birthday reminder',
        ],
        'booking.create_user' => [
            'userquery' => 'Anna Example',
        ],
        'booking.diagnose_booking_issue' => [
            'question' => 'Why can ANON_USER_1 not book Geburtstag ANON_USER_1?',
            'optionquery' => 'Geburtstag ANON_USER_1',
            'userquery' => 'ANON_USER_1',
        ],
        'booking.diagnose_cancellation_issue' => [
            'question' => 'Why can I not cancel my booking?',
            'optionquery' => 'Geburtstag ANON_USER_1',
        ],
        'booking.explain_docs_topic' => [
            'question' => 'How do I create a booking option?',
            'search_queries' => ['booking option create'],
        ],
        'booking.get_current_user' => [],
        'booking.get_option_details' => [
            'optionquery' => 'Geburtstag ANON_USER_1',
        ],
        'booking.list_actions' => [
            'scope' => 'booking',
        ],
        'booking.list_option_properties' => [
            'scope' => 'booking.create_option',
        ],
        'booking.recreate_task_catalog' => [
            'force' => true,
        ],
        'booking.recall_memory' => [
            'mode' => 'date_window',
            'date_hint' => 'last friday',
            'query' => 'document',
            'include_structured' => true,
        ],
        'booking.search_courses' => [
            'query' => 'Mathematik',
        ],
        'booking.search_users' => [
            'query' => 'ANON_USER_1',
        ],
        'booking.search_options' => [
            'query' => 'Geburtstag',
        ],
        'booking.update_option' => [
            'optionquery' => 'Geburtstag ANON_USER_1',
            'text' => 'Geburtstag ANON_USER_1',
        ],
        'booking.update_rule_from_template' => [
            'rulequery' => 'Birthday reminder',
            'rulename' => 'Updated reminder',
        ],
        'booking.core_get_user_profile' => [
            'userquery' => 'current',
        ],
        'booking.core_get_user_preferences' => [
            'userquery' => 'current',
            'prefkeys' => ['bookanyone'],
        ],
        'booking.core_set_user_preference' => [
            'name' => 'bookanyone',
            'value' => '1',
            'confirmed' => true,
        ],
        'booking.core_get_user_enrolments' => [
            'userquery' => 'current',
        ],
        'booking.core_get_current_user' => [],
        'booking.core_enrol_user_manual' => [
            'userquery' => 'ANON_USER_1',
            'coursequery' => 'Mathematik',
            'role' => 'student',
            'confirmed' => true,
        ],
        'booking.core_unenrol_user_manual' => [
            'userquery' => 'ANON_USER_1',
            'coursequery' => 'Mathematik',
            'confirmed' => true,
        ],
        'booking.core_list_course_participants' => [
            'coursequery' => 'Mathematik',
        ],
        'booking.core_get_user_roles_in_course' => [
            'coursequery' => 'Mathematik',
            'userquery' => 'ANON_USER_1',
        ],
        'booking.core_search_course_enrolments' => [
            'coursequery' => 'Mathematik',
            'query' => 'anon',
        ],
        'booking.core_list_course_groups' => [
            'coursequery' => 'Mathematik',
        ],
        'booking.core_get_group_members' => [
            'coursequery' => 'Mathematik',
            'groupquery' => 'Gruppe A',
        ],
        'booking.core_create_group' => [
            'coursequery' => 'Mathematik',
            'name' => 'Gruppe A',
            'confirmed' => true,
        ],
        'booking.core_update_group' => [
            'coursequery' => 'Mathematik',
            'groupquery' => 'Gruppe A',
            'name' => 'Gruppe B',
            'confirmed' => true,
        ],
        'booking.core_delete_group' => [
            'coursequery' => 'Mathematik',
            'groupquery' => 'Gruppe B',
            'confirmed' => true,
        ],
        'booking.core_get_course_overview' => [
            'coursequery' => 'Mathematik',
        ],
        'booking.core_list_course_sections' => [
            'coursequery' => 'Mathematik',
        ],
        'booking.core_list_course_modules' => [
            'coursequery' => 'Mathematik',
            'section' => 1,
        ],
        'booking.core_get_module_details' => [
            'cmid' => 1,
        ],
        'booking.core_get_activity_completion_status' => [
            'coursequery' => 'Mathematik',
            'cmid' => 1,
            'userquery' => 'current',
        ],
        'booking.core_get_user_completion_report' => [
            'coursequery' => 'Mathematik',
            'userquery' => 'current',
        ],
        'booking.core_list_course_calendar_events' => [
            'coursequery' => 'Mathematik',
        ],
        'booking.core_list_user_calendar_events' => [
            'userquery' => 'current',
        ],
        'booking.core_create_calendar_event' => [
            'title' => 'Team Meeting',
            'timestart' => 1767225600,
            'timeend' => 1767229200,
            'confirmed' => true,
        ],
        'booking.core_update_calendar_event' => [
            'eventid' => 1,
            'title' => 'Updated Team Meeting',
            'confirmed' => true,
        ],
        'booking.core_delete_calendar_event' => [
            'eventid' => 1,
            'confirmed' => true,
        ],
        'booking.core_list_grade_items' => [
            'coursequery' => 'Mathematik',
        ],
        'booking.core_get_user_grades_for_course' => [
            'coursequery' => 'Mathematik',
            'userquery' => 'current',
        ],
        'booking.core_send_user_message' => [
            'recipient' => 'ANON_USER_1',
            'message' => 'Hallo aus dem Agenten',
            'confirmed' => true,
        ],
        'booking.core_get_site_summary' => [],
    ];

    /**
     * Constructor.
     *
     * @param bool $readonly
     */
    public function __construct(bool $readonly = false) {
        parent::__construct($readonly);
        if (self::$sharedsupport === null) {
            self::$sharedsupport = new booking_task_support();
        }
        $this->support = self::$sharedsupport;
    }

    /**
     * Return the task name.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Return the schema for this task.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => '',
            'readonly' => $this->is_read_only(),
            'properties' => [],
        ];
    }

    /**
     * Return task-owned example input for prompt routing.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        $taskname = $this->get_name();
        return self::$exampleinput[$taskname] ?? [];
    }

    /**
     * Optionally enrich a schema with prompt_meta if declared in $promptmeta.
     *
     * Subclasses can call this helper at the end of get_schema() to automatically
     * inject input_fields_for_prompt and anchor_fields without manual duplication:
     *
     *   public function get_schema(): array {
     *       $schema = [ ... ];
     *       return $this->enrich_schema_with_prompt_meta($schema);
     *   }
     *
     * If the task is not in $promptmeta, returns schema unchanged.
     * If schema already has prompt_meta, does not override it.
     *
     * @param  array $schema
     * @return array Enriched schema (or unchanged if no metadata found).
     */
    protected function enrich_schema_with_prompt_meta(array $schema): array {
        if (!empty($schema['prompt_meta'])) {
            return $schema;
        }

        $taskname = $this->get_name();
        if (!isset(self::$promptmeta[$taskname])) {
            return $schema;
        }

        $schema['prompt_meta'] = self::$promptmeta[$taskname];
        return $schema;
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $structure = $this->check_structure($input);
        return [
            'valid' => (bool)($structure['valid'] ?? false),
            'errors' => array_values(array_unique(array_map('strval', (array)($structure['errors'] ?? [])))),
            'ambiguities' => [],
        ];
    }

    /**
     * Execute the task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        return $this->support->execute($this->get_name(), $input, $cmid, $userid);
    }

    /**
     * Return optional contextual prompt packs for this task.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [];
    }

    /**
     * Verify that requested values are visible in persisted option settings.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return [];
    }

    /**
     * Run service-level preflight validation and return an enriched preflight_result_v2.
     *
     * Centralises the repeated pattern across mutation tasks:
     *  1. Call booking_task_mutation_execute_service::preflight_validate().
     *  2. On errors/ambiguities: append them to $existingissues and return invalid().
     *  3. On success: apply normalized_input and return ok().
     *
     * @param  string $taskname       Fully-qualified task name (e.g. booking.update_option).
     * @param  array  $preparedinput  Input with local resolution already applied.
     * @param  int    $cmid
     * @param  int    $userid
     * @param  array  $existingissues Issues already collected before calling this helper.
     * @param  string $lang           Output language code (may be empty).
     * @return preflight_result_v2
     */
    protected function apply_service_preflight(
        string $taskname,
        array $preparedinput,
        int $cmid,
        int $userid,
        array $existingissues = [],
        string $lang = ''
    ): preflight_result_v2 {
        $service = new booking_task_mutation_execute_service();
        $servicepreflight = $service->preflight_validate($taskname, $preparedinput, $cmid, $userid);

        $issues = $existingissues;
        if (!empty($servicepreflight['errors']) || !empty($servicepreflight['ambiguities'])) {
            $serviceissuecodes = array_values(array_filter(array_map('strval', (array)($servicepreflight['issue_codes'] ?? []))));
            foreach ((array)($servicepreflight['errors'] ?? []) as $idx => $err) {
                $issues[] = [
                    'code'     => (string)($serviceissuecodes[$idx] ?? 'PREFLIGHT_ERROR'),
                    'severity' => 'needs_clarification',
                    'message'  => (string)$err,
                ];
            }
            foreach ((array)($servicepreflight['ambiguities'] ?? []) as $amb) {
                $issues[] = [
                    'code'     => 'PREFLIGHT_AMBIGUITY',
                    'severity' => 'needs_clarification',
                    'message'  => (string)$amb,
                ];
            }
            return preflight_result_v2::invalid($issues);
        }

        if (is_array($servicepreflight['normalized_input'] ?? null)) {
            $preparedinput = (array)$servicepreflight['normalized_input'];
        }

        return preflight_result_v2::ok($preparedinput);
    }

    /**
     * Build a brief technical debug message for a task execution.
     *
     * @param string $taskname
     * @param array $input
     * @param array $extra Optional extra lines (e.g. result summary).
     * @return string
     */
    protected function build_task_debug_message(string $taskname, array $input, array $extra = []): string {
        $parts = [];

        // Recursively flatten complex nested arrays for display.
        $flatten = static function ($item) use (&$flatten) {
            if (is_array($item)) {
                $subsliced = array_slice($item, 0, 5);
                return '[' . implode(', ', array_map($flatten, $subsliced)) . ']';
            }
            return (string)$item;
        };

        foreach ($input as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $sliced = array_slice($value, 0, 5);
                $parts[] = $key . '=' . $flatten($sliced);
            } else {
                $parts[] = $key . '=' . $value;
            }
        }
        $lines = ['Task: ' . $taskname];
        if (!empty($parts)) {
            $lines[] = 'Params: ' . implode(', ', $parts);
        }
        foreach ($extra as $line) {
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Resolve preferred output language from task input.
     *
     * @param array $input
     * @return string
     */
    protected function get_output_language(array $input): string {
        return trim((string)($input['outputlang'] ?? ''));
    }

    /**
     * Read a localized string, optionally forcing a specific output language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    protected function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $targetlang = trim($lang);
        if ($targetlang === '') {
            return get_string($identifier, 'bookingextension_agent', $a);
        }

        return get_string_manager()->get_string($identifier, 'bookingextension_agent', $a, $targetlang);
    }

    /**
     * Enforce a hard maximum character length on a string.
     *
     * @param string $text
     * @param int $maxchars
     * @return string
     */
    protected function enforce_max_chars(string $text, int $maxchars): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($normalized === '' || $maxchars <= 0) {
            return '';
        }

        if (\core_text::strlen($normalized) <= $maxchars) {
            return $normalized;
        }

        $ellipsis = '...';
        $available = max(1, $maxchars - \core_text::strlen($ellipsis));
        $trimmed = trim(\core_text::substr($normalized, 0, $available));
        return $trimmed . $ellipsis;
    }
}
