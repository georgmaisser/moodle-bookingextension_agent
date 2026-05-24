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

use bookingextension_agent\local\wbagent\booking\booking_task_support;
use bookingextension_agent\local\wbagent\interfaces\task_trigger_provider_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use mod_booking\singleton_service;

/**
 * Task definition for booking.get_option_details.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_option_details_task extends booking_task_base implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.get_option_details';

    /** Default fields returned on the first detail lookup. */
    private const DEFAULT_STANDARD_FIELDS = [
        'title',
        'teachers',
        'sessions',
        'price',
        'currency',
    ];

    /** All supported standard fields for targeted follow-up lookups. */
    private const SUPPORTED_STANDARD_FIELDS = [
        'title',
        'description',
        'price',
        'currency',
        'teachers',
        'sessions',
        'imageurl',
        'canceluntil',
        'coursestarttime',
        'courseendtime',
        'costcenter',
    ];

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
        $schema = [
            'version' => 1,
            'description' => 'Get detailed information for one or more booking options via booking option APIs.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Single booking option id to inspect.',
                    'required' => false,
                ],
                'optionids' => [
                    'type' => 'array',
                    'description' => 'Optional list of booking option ids for batch details. Keep short.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Option title/query to resolve when optionid is unknown.',
                    'required' => false,
                ],
                'includesessions' => [
                    'type' => 'boolean',
                    'description' => 'Whether session details should be included (default true).',
                    'required' => false,
                ],
                'requested_fields' => [
                    'type' => 'array',
                    'description' => 'Optional targeted standard fields (e.g. description, price, teachers). '
                        . 'If omitted, returns a compact default set and capability hints.',
                    'required' => false,
                ],
                'include_customfields' => [
                    'type' => 'boolean',
                    'description' => 'Include custom field values in the response (default false).',
                    'required' => false,
                ],
                'customfield_keys' => [
                    'type' => 'array',
                    'description' => 'Optional custom field shortnames to return. Only used when include_customfields=true.',
                    'required' => false,
                ],
                'maxitems' => [
                    'type' => 'integer',
                    'description' => 'Safety limit for batch lookups (default 3, max 5).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.get_option_details_request',
                'description' => 'User asks for specific details of an already identified booking option.',
                'examples' => [
                    'Wer ist Trainerin bei "Lesung mit Georg"?',
                    'Show details for option 73',
                    'Welche Sessions hat Option X?',
                ],
            ],
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
                'id' => 'booking.get_option_details',
                'triggers' => [
                    'trainer', 'trainerin', 'teacher', 'dozent', 'referent',
                    'option details', 'option detail', 'sessions der option',
                ],
                'guidance' => [
                    '- Use booking.get_option_details when the user asks for specific fields of an option',
                    '  (e.g. teachers, sessions, times, image, price context).',
                    '- Prefer optionid when already known; otherwise resolve via optionquery first.',
                    '- First call can be compact to learn available detail fields; follow-up calls can target',
                    '  requested_fields and optional customfield_keys for precise details.',
                    '- Keep batch usage small and intentional (max a few options).',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $hasoptionid = !empty((int)($input['optionid'] ?? 0));
        $hasoptionids = !empty($input['optionids']) && is_array($input['optionids']);
        $hasquery = trim((string)($input['optionquery'] ?? '')) !== '';

        if (!$hasoptionid && !$hasoptionids && !$hasquery) {
            $errors[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang);
        }

        if (isset($input['optionids']) && !is_array($input['optionids'])) {
            $errors[] = 'optionids must be an array.';
        }

        if (isset($input['requested_fields']) && !is_array($input['requested_fields'])) {
            $errors[] = 'requested_fields must be an array.';
        }

        if (isset($input['customfield_keys']) && !is_array($input['customfield_keys'])) {
            $errors[] = 'customfield_keys must be an array.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Explicit preflight for readonly task — validates structure and passes input unchanged.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $cmid, int $userid): preflight_result_v2 {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return preflight_result_v2::invalid($issues);
        }
        return preflight_result_v2::ok($input);
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
        $outputlang = $this->get_output_language($input);
        $includesessions = !array_key_exists('includesessions', $input) || !empty($input['includesessions']);
        $includecustomfields = !empty($input['include_customfields']);
        $maxitems = isset($input['maxitems']) ? max(1, min(5, (int)$input['maxitems'])) : 3;
        $requestedfields = $this->normalize_requested_fields((array)($input['requested_fields'] ?? []));
        $customfieldkeys = $this->normalize_customfield_keys((array)($input['customfield_keys'] ?? []));

        if (empty($requestedfields)) {
            $requestedfields = self::DEFAULT_STANDARD_FIELDS;
        }

        $resolvedids = $this->resolve_target_option_ids($input, $cmid, $userid, $maxitems);
        if (empty($resolvedids)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang),
                'resultid' => null,
                'optiondetails' => [],
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Resolved ids: none']),
            ];
        }

        $details = [];
        $availablecustomfields = [];
        foreach ($resolvedids as $optionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings((int)$optionid);
            if (!$settings) {
                continue;
            }

            $info = $settings->return_booking_option_information(null, $includesessions);
            if (!is_array($info)) {
                continue;
            }

            $capability = $this->build_option_capability_snapshot($settings);
            foreach ((array)($capability['available_customfields'] ?? []) as $cf) {
                if (!is_array($cf)) {
                    continue;
                }
                $key = trim((string)($cf['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                if (!isset($availablecustomfields[$key])) {
                    $availablecustomfields[$key] = $cf;
                }
            }

            $selectedstandard = $this->select_standard_fields($info, $requestedfields, $includesessions);
            $selectedcustomfields = $this->select_custom_fields($settings, $includecustomfields, $customfieldkeys);

            $details[] = [
                'optionid' => (int)($info['itemid'] ?? $optionid),
                'title' => (string)($info['title'] ?? ''),
                'requested_fields' => $requestedfields,
                'standard_fields' => $selectedstandard,
                'customfields' => $selectedcustomfields,
                'capabilities' => $capability,
            ];
        }

        if (empty($details)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang),
                'resultid' => null,
                'optiondetails' => [],
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Resolved ids: none with data']),
            ];
        }

        $count = count($details);
        $firstid = (int)($details[0]['optionid'] ?? 0);
        $titles = array_values(array_filter(array_map(
            static fn(array $d): string => trim((string)($d['title'] ?? '')),
            $details
        )));
        $detailmessage = 'Found details for ' . $count . ' booking option(s)';
        if (!empty($titles)) {
            $detailmessage .= ': ' . implode(', ', array_slice($titles, 0, 3));
        }
        $detailmessage .= '.';

        $detailcapabilities = [
            'supported_standard_fields' => self::SUPPORTED_STANDARD_FIELDS,
            'default_standard_fields' => self::DEFAULT_STANDARD_FIELDS,
            'available_customfields' => array_values($availablecustomfields),
        ];

        return [
            'status' => 'executed',
            'detail' => $detailmessage,
            'usermessage' => $detailmessage,
            'resultid' => $firstid > 0 ? $firstid : null,
            'previewoptionids' => array_values(array_map(
                static fn(array $d): int => (int)($d['optionid'] ?? 0),
                $details
            )),
            'optiondetails' => $details,
            'detail_capabilities' => $detailcapabilities,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Resolved ids: ' . implode(', ', $resolvedids),
                    'Details returned: ' . $count,
                    'Requested fields: ' . implode(', ', $requestedfields),
                    'Custom fields included: ' . ($includecustomfields ? 'yes' : 'no'),
                ]
            ),
        ];
    }

    /**
     * Normalize requested standard fields.
     *
     * @param array $fields
     * @return array<int,string>
     */
    private function normalize_requested_fields(array $fields): array {
        $normalized = [];
        foreach ($fields as $field) {
            $key = strtolower(trim((string)$field));
            if ($key === '') {
                continue;
            }
            if ($key === 'all_standard') {
                return self::SUPPORTED_STANDARD_FIELDS;
            }
            if (in_array($key, self::SUPPORTED_STANDARD_FIELDS, true)) {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize custom field key filters.
     *
     * @param array $keys
     * @return array<int,string>
     */
    private function normalize_customfield_keys(array $keys): array {
        $normalized = [];
        foreach ($keys as $key) {
            $shortname = trim((string)$key);
            if ($shortname !== '') {
                $normalized[] = $shortname;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Select requested standard fields from booking option information.
     *
     * @param array $info
     * @param array $requestedfields
     * @param bool $includesessions
     * @return array<string,mixed>
     */
    private function select_standard_fields(array $info, array $requestedfields, bool $includesessions): array {
        $selected = [];
        foreach ($requestedfields as $field) {
            switch ($field) {
                case 'title':
                    $selected['title'] = (string)($info['title'] ?? '');
                    break;
                case 'description':
                    $selected['description'] = (string)($info['description'] ?? '');
                    break;
                case 'price':
                    $selected['price'] = $info['price'] ?? null;
                    break;
                case 'currency':
                    $selected['currency'] = (string)($info['currency'] ?? '');
                    break;
                case 'teachers':
                    $selected['teachers'] = (array)($info['teachers'] ?? []);
                    break;
                case 'sessions':
                    $selected['sessions'] = $includesessions ? (array)($info['sessions'] ?? []) : [];
                    break;
                case 'imageurl':
                    $selected['imageurl'] = (string)($info['imageurl'] ?? '');
                    break;
                case 'canceluntil':
                    $selected['canceluntil'] = (int)($info['canceluntil'] ?? 0);
                    break;
                case 'coursestarttime':
                    $selected['coursestarttime'] = (int)($info['coursestarttime'] ?? 0);
                    break;
                case 'courseendtime':
                    $selected['courseendtime'] = (int)($info['courseendtime'] ?? 0);
                    break;
                case 'costcenter':
                    $selected['costcenter'] = (string)($info['costcenter'] ?? '');
                    break;
            }
        }

        return $selected;
    }

    /**
     * Select custom field values from singleton-loaded option settings.
     *
     * @param object $settings
     * @param bool $includecustomfields
     * @param array $customfieldkeys
     * @return array<string,mixed>
     */
    private function select_custom_fields(object $settings, bool $includecustomfields, array $customfieldkeys): array {
        if (!$includecustomfields) {
            return [];
        }

        // Use customfieldsfortemplates for processed/readable values (resolves select option labels etc.).
        $templates = (array)($settings->customfieldsfortemplates ?? []);

        // Build case-insensitive lookup map: lowercase_key => [key, value].
        $lookup = [];
        foreach ($templates as $shortname => $field) {
            if (!is_array($field)) {
                continue;
            }
            $val = $field['value'] ?? '';
            $lookup[strtolower((string)$shortname)] = [
                'key' => (string)$shortname,
                'value' => $val,
            ];
        }

        if (empty($customfieldkeys)) {
            // Return all processed values.
            $selected = [];
            foreach ($lookup as $entry) {
                $selected[$entry['key']] = $entry['value'];
            }
            return $selected;
        }

        $selected = [];
        foreach ($customfieldkeys as $key) {
            $lkey = strtolower(trim((string)$key));
            if (isset($lookup[$lkey])) {
                $entry = $lookup[$lkey];
                $selected[$entry['key']] = $entry['value'];
            }
        }

        return $selected;
    }

    /**
     * Build compact capability metadata for follow-up detail queries.
     *
     * @param object $settings
     * @return array<string,mixed>
     */
    private function build_option_capability_snapshot(object $settings): array {
        $availablecustomfields = [];
        foreach ((array)($settings->customfieldsfortemplates ?? []) as $shortname => $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string)($field['key'] ?? $shortname));
            if ($key === '') {
                continue;
            }
            $availablecustomfields[] = [
                'key' => $key,
                'label' => trim((string)($field['label'] ?? $key)),
                'type' => trim((string)($field['type'] ?? 'mixed')),
            ];
        }

        return [
            'supported_standard_fields' => self::SUPPORTED_STANDARD_FIELDS,
            'available_customfields' => $availablecustomfields,
        ];
    }

    /**
     * Resolve target option ids from input.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param int $maxitems
     * @return array<int,int>
     */
    private function resolve_target_option_ids(array $input, int $cmid, int $userid, int $maxitems): array {
        $ids = [];

        $optionid = (int)($input['optionid'] ?? 0);
        if ($optionid > 0) {
            $ids[] = $optionid;
        }

        $optionids = is_array($input['optionids'] ?? null) ? (array)$input['optionids'] : [];
        foreach ($optionids as $id) {
            $intid = (int)$id;
            if ($intid > 0) {
                $ids[] = $intid;
            }
        }

        $query = trim((string)($input['optionquery'] ?? ''));
        if ($query !== '') {
            if (booking_task_support::is_last_option_reference($query)) {
                $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
                foreach ($previewids as $id) {
                    $intid = (int)$id;
                    if ($intid > 0) {
                        $ids[] = $intid;
                    }
                }
            } else {
                $resolved = booking_task_support::resolve_single_option($cmid, $query, '');
                if (($resolved['status'] ?? '') === 'ok') {
                    $rid = (int)($resolved['optionid'] ?? 0);
                    if ($rid > 0) {
                        $ids[] = $rid;
                    }
                }
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return array_slice($ids, 0, $maxitems);
    }
}
