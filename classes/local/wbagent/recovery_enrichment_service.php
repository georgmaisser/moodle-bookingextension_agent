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
 * Generic recovery enrichment service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use core_text;

/**
 * Builds normalized recovery task_call payloads for dead-end model outputs.
 */
class recovery_enrichment_service {
    /** @var task_registry */
    private task_registry $registry;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     */
    public function __construct(task_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Try to promote clarification/error payload into a normalized task_call.
     *
     * @param array $result
     * @param string $usermessage
     * @param string $outputlang
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param task_executability_evaluator $evaluator
     * @param int $contextid
     * @param callable $buildinput
     * @param callable $lookslookuprequest
     * @param callable $looksdiagnosticintent
     * @param callable $scoretask
     * @param callable $extractcontextquery
     * @return array
     */
    public function promote(
        array $result,
        string $usermessage,
        string $outputlang,
        int $threadid,
        int $cmid,
        int $userid,
        task_executability_evaluator $evaluator,
        int $contextid,
        callable $buildinput,
        callable $lookslookuprequest,
        callable $looksdiagnosticintent,
        callable $scoretask,
        callable $extractcontextquery
    ): array {
        $responsetype = (string)($result['response_type'] ?? '');
        if (!in_array($responsetype, ['clarification', 'error', 'sufficient'], true)) {
            return $result;
        }
        if (!empty((array)($result['commands'] ?? [])) || !empty((array)($result['results'] ?? []))) {
            return $result;
        }

        $usedtriggers = (array)($result['used_triggers'] ?? []);
        $nextstepintent = trim((string)($result['next_step_intent'] ?? ''));
        $candidatetasks = [];
        $allowedtasknames = array_fill_keys(
            $this->registry->get_task_names_for_context($evaluator, $userid, $contextid),
            true
        );

        if (empty($candidatetasks) && $lookslookuprequest($usermessage, $result)) {
            $taskname = 'booking.explain_docs_topic';
            if (
                isset($allowedtasknames[$taskname])
                && $this->registry->is_read_only_task($taskname)
                && $this->registry->get_task($taskname) !== null
            ) {
                $candidatetasks[$taskname] = true;
            }
        }

        if (empty($candidatetasks) && $looksdiagnosticintent($usermessage)) {
            foreach ($this->registry->get_task_names() as $taskname) {
                if (!isset($allowedtasknames[$taskname])) {
                    continue;
                }
                if (!$this->registry->is_read_only_task($taskname)) {
                    continue;
                }
                $task = $this->registry->get_task($taskname);
                if ($task === null) {
                    continue;
                }
                $schema = $task->get_schema();
                $properties = (array)($schema['properties'] ?? []);
                if (
                    isset($properties['question']) && is_array($properties['question'])
                    && (isset($properties['optionquery']) || isset($properties['optionid']))
                ) {
                    $candidatetasks[(string)$taskname] = true;
                }
            }
        }

        if (empty($candidatetasks) && $this->result_has_trigger($result, 'core.is_lookup_request')) {
            foreach ($this->registry->get_task_names() as $taskname) {
                if (!isset($allowedtasknames[$taskname])) {
                    continue;
                }
                if (!$this->registry->is_read_only_task($taskname)) {
                    continue;
                }
                $task = $this->registry->get_task($taskname);
                if ($task === null) {
                    continue;
                }
                $schema = $task->get_schema();
                $properties = (array)($schema['properties'] ?? []);
                if (!isset($properties['query']) || !is_array($properties['query'])) {
                    continue;
                }
                $candidatetasks[(string)$taskname] = true;
            }
        }

        if (empty($candidatetasks)) {
            $contextquery = trim((string)$extractcontextquery($threadid));
            if ($contextquery !== '') {
                $scored = [];
                foreach ($this->registry->get_task_names() as $taskname) {
                    if (!isset($allowedtasknames[$taskname])) {
                        continue;
                    }
                    if (!$this->registry->is_read_only_task($taskname)) {
                        continue;
                    }
                    $task = $this->registry->get_task($taskname);
                    if ($task === null) {
                        continue;
                    }
                    $schema = $task->get_schema();
                    $properties = (array)($schema['properties'] ?? []);
                    if (!isset($properties['query']) || !is_array($properties['query'])) {
                        continue;
                    }

                    $score = 0;
                    $description = core_text::strtolower(trim((string)($schema['description'] ?? '')));
                    $tasknamelower = core_text::strtolower((string)$taskname);
                    if (str_contains($description, 'option')) {
                        $score += 3;
                    }
                    if (str_contains($tasknamelower, 'option')) {
                        $score += 2;
                    }
                    if (str_contains($tasknamelower, 'search')) {
                        $score += 1;
                    }
                    $scored[] = ['task' => (string)$taskname, 'score' => $score];
                }

                usort($scored, static function (array $a, array $b): int {
                    return (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
                });
                foreach ($scored as $entry) {
                    $taskname = trim((string)($entry['task'] ?? ''));
                    if ($taskname !== '') {
                        $candidatetasks[$taskname] = true;
                    }
                }
            }
        }

        if (empty($candidatetasks)) {
            foreach ($this->registry->get_task_names() as $taskname) {
                if (!isset($allowedtasknames[$taskname])) {
                    continue;
                }
                if (!$this->registry->is_read_only_task($taskname)) {
                    continue;
                }

                $task = $this->registry->get_task($taskname);
                if ($task === null) {
                    continue;
                }

                $schema = $task->get_schema();
                $properties = (array)($schema['properties'] ?? []);
                if (!isset($properties['question']) || !is_array($properties['question'])) {
                    continue;
                }

                $optionqueryisrequired = !empty($properties['optionquery']['required'] ?? false);
                $optionidisrequired = !empty($properties['optionid']['required'] ?? false);
                if ($optionqueryisrequired || $optionidisrequired) {
                    continue;
                }

                $candidatetasks[(string)$taskname] = true;
            }
        }

        if (empty($candidatetasks)) {
            return $result;
        }

        $islookuprecovery = $this->result_has_trigger($result, 'core.is_lookup_request');
        $tasknames = array_keys($candidatetasks);
        usort($tasknames, static function (string $a, string $b) use ($islookuprecovery, $scoretask): int {
            return (int)$scoretask($b, $islookuprecovery)
                <=> (int)$scoretask($a, $islookuprecovery);
        });

        foreach ($tasknames as $taskname) {
            $input = $buildinput(
                $taskname,
                $usermessage,
                $outputlang,
                $threadid,
                $cmid,
                $userid
            );
            if ($input === null) {
                continue;
            }

            $recoverypayload = [
                'response_type'   => 'task_call',
                'message'         => get_string('ai_status_taskcall_default', 'bookingextension_agent'),
                'commands'        => [[
                    'task' => $taskname,
                    'version' => 1,
                    'input' => $input,
                ]],
                'ambiguities'     => [],
                'errors'          => [],
                'attempted_tasks' => [$taskname],
                'issue_codes'     => array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['AUTO_GENERIC_TASK_RECOVERY']
                ))),
                'used_triggers'   => $usedtriggers,
            ];

            if ($nextstepintent !== '') {
                $recoverypayload['next_step_intent'] = $nextstepintent;
            }

            return $recoverypayload;
        }

        return $result;
    }

    /**
     * Check whether result contains trigger id.
     *
     * @param array $result
     * @param string $triggerid
     * @return bool
     */
    private function result_has_trigger(array $result, string $triggerid): bool {
        $triggerid = trim($triggerid);
        if ($triggerid === '') {
            return false;
        }

        foreach ((array)($result['used_triggers'] ?? []) as $id) {
            if (trim((string)$id) === $triggerid) {
                return true;
            }
        }

        return false;
    }
}
