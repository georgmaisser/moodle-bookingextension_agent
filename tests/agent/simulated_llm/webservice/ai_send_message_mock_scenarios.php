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

/**
 * Scenario catalog for ai_send_message simulated LLM webservice tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

/**
 * Shared scenario definitions for deterministic webservice tests.
 */
final class ai_send_message_mock_scenarios {
    /**
     * Data provider rows.
     *
     * @return array<string,array{0:string}>
     */
    public static function provider_rows(): array {
        return [
            'create booking option confirmation' => ['create_option_confirmation'],
            'explain booking rules docs' => ['explain_booking_rules_docs'],
            'ask about automatic booking confirmations' => ['create_rule_clarification'],
            'create booking rule confirmation' => ['create_rule_confirmation'],
            'diagnose another user booking issue' => ['diagnose_other_user_cannot_book'],
            'list booking options' => ['list_all_booking_options'],
            'search booking options' => ['search_matching_booking_options'],
            'get option details' => ['get_option_details_for_specific_option'],
            'search users by unique token' => ['search_users_by_unique_name'],
            'get current user profile' => ['get_current_user_profile'],
            'list agent actions' => ['list_agent_actions'],
            'list option properties' => ['list_option_properties_for_create_scope'],
        ];
    }

    /**
     * Build one scenario case.
     *
     * Required callbacks:
     * - create_option(string $title, array $overrides = []): object
     * - create_user(array $user): object
     * - enrol_user(int $userid): void
     * - exec_command(string $task, array $input): mixed
     *
     * @param string $scenario
     * @param array<string,callable> $helpers
     * @return array<string,mixed>
     */
    public static function build_case(string $scenario, array $helpers): array {
        switch ($scenario) {
            case 'create_option_confirmation':
                $title = 'Webservice Mock Create ' . uniqid('', true);
                return [
                    'prompt' => 'Create booking option "' . $title . '" with 7 spots '
                        . 'from 2045-11-01T09:00:00 to 2045-11-01T11:00:00.',
                    'routes' => [[
                        'prompt_contains' => ['Create booking option'],
                        'responses' => [[
                            'response_type' => 'confirmation_request',
                            'message' => 'Please confirm creating this booking option.',
                            'commands' => [[
                                'task' => 'booking.create_option',
                                'version' => 1,
                                'input' => [
                                    'text' => $title,
                                    'optiontype' => 'normal',
                                    'maxanswers' => 7,
                                    'coursestarttime' => '2045-11-01T09:00:00',
                                    'courseendtime' => '2045-11-01T11:00:00',
                                    'teacherquery' => 'current',
                                ],
                            ]],
                        ]],
                    ]],
                    'title' => $title,
                    'expected_response_type' => 'confirmation_request',
                    'expected_tasks' => ['booking.create_option'],
                    'min_debug_rows' => 1,
                    'expected_loop_depth' => 1,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum'],
                ];

            case 'create_rule_confirmation':
                return [
                    'prompt' => 'Create a booking rule from the booking confirmation template '
                        . 'named Automatic booking confirmation. No follow-up questions.',
                    'routes' => [[
                        'prompt_contains' => [
                            'Create a booking rule from the booking confirmation template',
                            'booking confirmation',
                        ],
                        'responses' => [[
                            'response_type' => 'confirmation_request',
                            'message' => 'Bitte bestaetige die Erstellung einer automatischen Buchungsbestaetigung.',
                            'commands' => [[
                                'task' => 'booking.create_rule_from_template',
                                'version' => 1,
                                'input' => [
                                    'templatequery' => 'Automatic booking confirmation',
                                    'question' => 'Create a booking rule from the booking confirmation template '
                                        . 'named Automatic booking confirmation. No follow-up questions.',
                                    'rulename' => 'Automatische Buchungsbestaetigung',
                                    'isactive' => true,
                                ],
                            ]],
                        ]],
                    ]],
                    'expected_response_type' => 'confirmation_request',
                    'expected_tasks' => ['booking.create_rule_from_template'],
                    'min_debug_rows' => 1,
                    'expected_loop_depth_min' => 1,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum'],
                ];

            case 'create_rule_clarification':
                return [
                    'prompt' => 'I want to send automatic booking confirmations here.',
                    'routes' => [[
                        'prompt_contains' => ['I want to send automatic booking confirmations here'],
                        'responses' => [[
                            'response_type' => 'confirmation_request',
                            'message' => 'Should I create one for you?',
                            'commands' => [[
                                'task' => 'booking.create_rule_from_template',
                                'version' => 1,
                                'input' => [
                                    'templatequery' => 'Automatic booking confirmation',
                                    'question' => 'I want to send automatic booking confirmations here.',
                                    'rulename' => 'Automatic booking confirmations',
                                    'isactive' => true,
                                ],
                            ]],
                            'user_lang' => 'en',
                        ]],
                    ]],
                    'expected_response_type' => 'confirmation_request',
                    'expected_response_types' => ['clarification', 'confirmation_request', 'confirm_pending'],
                    'expected_tasks' => ['booking.create_rule_from_template'],
                    'min_debug_rows' => 1,
                    'expected_loop_depth_min' => 1,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum'],
                ];

            case 'explain_booking_rules_docs':
                return [
                    'prompt' => 'Show me the Booking rules chapter in the documentation '
                        . 'for automatic booking confirmations.',
                    'routes' => [[
                        'prompt_contains' => [
                            'Show me the Booking rules chapter in the documentation',
                            'automatic booking confirmations',
                        ],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.explain_docs_topic',
                                    'version' => 1,
                                    'input' => [
                                        'question' => 'Show me the Booking rules chapter in the documentation '
                                            . 'for automatic booking confirmations.',
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Ich habe den Abschnitt zu Booking rules gefunden.',
                                'user_lang' => 'de',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Die Booking-rules-Dokumentation ist jetzt bereitgestellt.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.explain_docs_topic'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 4,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                    'expected_docs_prefix' => 'booking_rules/',
                ];

            case 'diagnose_other_user_cannot_book':
                $optiontitle = 'Webservice Diagnose B ' . uniqid('', true);
                $option = $helpers['create_option']($optiontitle, ['maxanswers' => 1]);

                $blockeduser = $helpers['create_user']([
                    'firstname' => 'Nutzer',
                    'lastname' => 'A',
                    'email' => 'nutzer.a.' . uniqid('', true) . '@example.com',
                ]);
                $helpers['enrol_user']((int)$blockeduser->id);

                $filleduser = $helpers['create_user']([
                    'firstname' => 'Booked',
                    'lastname' => 'User',
                    'email' => 'booked.' . uniqid('', true) . '@example.com',
                ]);
                $helpers['enrol_user']((int)$filleduser->id);

                $helpers['exec_command']('booking.book_users', [
                    'optionid' => (int)$option->id,
                    'bookusersquery' => fullname($filleduser),
                ]);

                return [
                    'prompt' => 'Warum kann Nutzer A bei Buchungsoption B nicht buchen?',
                    'routes' => [[
                        'prompt_contains' => ['Warum kann Nutzer A bei Buchungsoption B nicht buchen'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.diagnose_booking_issue',
                                    'version' => 1,
                                    'input' => [
                                        'question' => 'Warum kann Nutzer A bei Buchungsoption B nicht buchen?',
                                        'optionquery' => $optiontitle,
                                        'userquery' => fullname($blockeduser),
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Nutzer A kann die Option nicht buchen, da alle Plätze belegt sind.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'option' => $option,
                    'blockeduser' => $blockeduser,
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.diagnose_booking_issue'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                    'expected_min_reasons' => 1,
                ];

            case 'list_all_booking_options':
                $prefix = 'Webservice List ' . uniqid('', true);
                $option1 = $helpers['create_option']($prefix . ' Alpha', []);
                $option2 = $helpers['create_option']($prefix . ' Beta', []);

                return [
                    'prompt' => 'Zeig mir eine Liste aller Buchungsoptionen.',
                    'routes' => [[
                        'prompt_contains' => ['Zeig mir eine Liste aller Buchungsoptionen'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.search_options',
                                    'version' => 1,
                                    'input' => [
                                        'query' => '',
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Hier ist die Liste aller verfügbaren Buchungsoptionen.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'option1' => $option1,
                    'option2' => $option2,
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.search_options'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                ];

            case 'search_matching_booking_options':
                $prefix = 'Webservice Mock Search ' . uniqid('', true);
                $option1 = $helpers['create_option']($prefix . ' A', []);
                $option2 = $helpers['create_option']($prefix . ' B', []);

                return [
                    'prompt' => 'Show me all booking options with "' . $prefix . '" in the title.',
                    'routes' => [[
                        'prompt_contains' => ['Show me all booking options with'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.search_options',
                                    'version' => 1,
                                    'input' => [
                                        'query' => $prefix,
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'I found matching options and prepared the result summary.',
                                'user_lang' => 'en',
                            ],
                        ],
                    ]],
                    'prefix' => $prefix,
                    'option1' => $option1,
                    'option2' => $option2,
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.search_options'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac='],
                ];

            case 'get_option_details_for_specific_option':
                $title = 'Webservice Detail Option ' . uniqid('', true);
                $option = $helpers['create_option']($title, []);

                return [
                    'prompt' => 'Kannst du mir die Details zur Buchungsoption "' . $title
                        . '" zeigen? Vor allem Trainer und Termine.',
                    'routes' => [[
                        'prompt_contains' => ['Details zur Buchungsoption'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.get_option_details',
                                    'version' => 1,
                                    'input' => [
                                        'optionquery' => $title,
                                        'requested_fields' => ['title', 'teachers', 'sessions'],
                                        'includesessions' => true,
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Hier sind die Detailinformationen zur angefragten Buchungsoption.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'title' => $title,
                    'option' => $option,
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.get_option_details'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                ];

            case 'search_users_by_unique_name':
                $token = 'WebserviceUser' . uniqid();
                $user1 = $helpers['create_user']([
                    'firstname' => $token . 'Anna',
                    'lastname' => 'Agent',
                    'email' => 'user.a.' . uniqid('', true) . '@example.com',
                ]);
                $helpers['enrol_user']((int)$user1->id);

                $user2 = $helpers['create_user']([
                    'firstname' => $token . 'Ben',
                    'lastname' => 'Agent',
                    'email' => 'user.b.' . uniqid('', true) . '@example.com',
                ]);
                $helpers['enrol_user']((int)$user2->id);

                return [
                    'prompt' => 'Bitte such mal alle Nutzer, die "' . $token . '" im Namen haben.',
                    'routes' => [[
                        'prompt_contains' => ['Nutzer, die'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.search_users',
                                    'version' => 1,
                                    'input' => [
                                        'query' => $token,
                                        'limit' => 10,
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Ich habe die passenden Nutzer gefunden.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'token' => $token,
                    'user1' => $user1,
                    'user2' => $user2,
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.search_users'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                ];

            case 'get_current_user_profile':
                return [
                    'prompt' => 'Wer bin ich hier gerade in diesem Kurs? Zeig mir bitte mein Profil kurz an.',
                    'routes' => [[
                        'prompt_contains' => ['Wer bin ich hier gerade'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.get_current_user',
                                    'version' => 1,
                                    'input' => [],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Ich habe dein aktuelles Profil geladen.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.get_current_user'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                ];

            case 'list_agent_actions':
                return [
                    'prompt' => 'Welche Aktionen kannst du als Booking-Assistent gerade ausfuehren?',
                    'routes' => [[
                        'prompt_contains' => ['Welche Aktionen kannst du als Booking-Assistent'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.list_actions',
                                    'version' => 1,
                                    'input' => [
                                        'scope' => 'all',
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Ich habe dir die verfuegbaren Aktionen aufgelistet.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.list_actions'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                ];

            case 'list_option_properties_for_create_scope':
                return [
                    'prompt' => 'Welche Felder kann ich setzen, wenn ich eine neue Buchungsoption anlege?',
                    'routes' => [[
                        'prompt_contains' => ['Welche Felder kann ich setzen'],
                        'responses' => [
                            [
                                'response_type' => 'task_call',
                                'commands' => [[
                                    'task' => 'booking.list_option_properties',
                                    'version' => 1,
                                    'input' => [
                                        'scope' => 'create',
                                    ],
                                ]],
                            ],
                            [
                                'response_type' => 'sufficient',
                            ],
                            [
                                'response_type' => 'sufficient',
                                'message' => 'Ich habe die Felder fuer das Anlegen einer Option zusammengestellt.',
                                'user_lang' => 'de',
                            ],
                        ],
                    ]],
                    'expected_response_type' => 'sufficient',
                    'expected_tasks' => ['booking.list_option_properties'],
                    'min_debug_rows' => 3,
                    'expected_loop_depth' => 3,
                    'expected_task_transitions' => 1,
                    'expected_debug_source_patterns' => ['ac=sum', 'ac=gen'],
                ];

            default:
                throw new \coding_exception('Unknown mock scenario: ' . $scenario);
        }
    }
}
