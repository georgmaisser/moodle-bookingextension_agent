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

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use core_text;
use core\context;
use context_module;
use bookingextension_agent\local\wbagent\conversation_store;
use bookingextension_agent\local\wbagent\privacy_anonymizer;
use bookingextension_agent\local\wbagent\task_registry;

/**
 * Unified preflight pipeline for mutating command batches.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_pipeline {
    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var preflight_contract_validator */
    private preflight_contract_validator $contractvalidator;

    /** @var preflight_domain_check_runner */
    private preflight_domain_check_runner $domainrunner;

    /** @var preflight_execution_gate */
    private preflight_execution_gate $executiongate;

    /** @var preflight_audit_logger */
    private preflight_audit_logger $auditlogger;

    /**
     * Constructor.
     *
     * @param task_registry $registry
     * @param conversation_store $store
     */
    public function __construct(task_registry $registry, conversation_store $store) {
        $this->registry = $registry;
        $this->store = $store;
        $this->contractvalidator = new preflight_contract_validator($registry);
        $this->domainrunner = new preflight_domain_check_runner();
        $this->executiongate = new preflight_execution_gate();
        $this->auditlogger = new preflight_audit_logger($store);
    }

    /**
     * Run full preflight L1->L2->L3 for a command batch.
     *
     * @param array<int,mixed> $commands
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,issue_codes:array<int,string>,blocking_layer:string,retry_after_ms:int,retry_count:int,duration_ms:int,prepared_commands:array<int,array<string,mixed>>,errors:array<int,string>,attempted_tasks:array<int,string>,issues:array<int,array<string,mixed>>}
     */
    public function run(array $commands, int $threadid, int $contextid, int $userid): array {
        $preparedcommands = [];
        $errors = [];
        $attemptedtasks = [];
        $issuecodes = [];
                $issues = [];
        $layer1issuecodes = [];
        $anonymizer = new privacy_anonymizer($this->store);
        $startedat = microtime(true);
        try {
            $context = context::instance_by_id($contextid, MUST_EXIST);
            if (!($context instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
        } catch (\Throwable $e) {
            $context = context_module::instance($contextid, MUST_EXIST);
        }
                $cmid = (int)$context->instanceid;

        foreach ($commands as $idx => $command) {
            $label = 'Command #' . ($idx + 1);
            if (!is_array($command)) {
                $errors[] = $label . ': malformed command payload.';
                $issuecodes[] = 'SCHEMA_ERROR';
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                $errors[] = $label . ': missing task.';
                $issuecodes[] = 'SCHEMA_ERROR';
                continue;
            }
            $attemptedtasks[] = $taskname;

            $schemavalidation = $this->contractvalidator->validate($command);
            $layer1issuecodes = array_values(array_unique(array_merge(
                $layer1issuecodes,
                (array)($schemavalidation['issue_codes'] ?? [])
            )));
            if (($schemavalidation['valid'] ?? false) !== true) {
                $result = new preflight_result_v2(
                    'hard_block',
                    !empty($schemavalidation['issue_codes'])
                        ? (array)$schemavalidation['issue_codes']
                        : ['SCHEMA_ERROR'],
                    preflight_result_v2::BLOCKING_LAYER_SCHEMA,
                    0,
                    0,
                    (int)max(0, (microtime(true) - $startedat) * 1000)
                );

                $this->auditlogger->append($threadid, 0, [
                    'contextid' => $contextid,
                    'taskname' => $taskname,
                    'task_version' => max(1, (int)($command['version'] ?? 1)),
                    'layer' => preflight_result_v2::BLOCKING_LAYER_SCHEMA,
                    'status' => $result->status,
                    'issue_codes' => $result->issuecodes,
                    'retry_count' => 0,
                    'retry_after_ms' => 0,
                    'duration_ms' => $result->durationms,
                    'error_class' => (string)($schemavalidation['error_class'] ?? 'schema_error'),
                ]);

                return $this->build_output(
                    false,
                    $preparedcommands,
                    array_values(array_unique(array_merge($errors, (array)($schemavalidation['errors'] ?? [])))),
                    $attemptedtasks,
                    array_values(array_unique(array_merge($issuecodes, (array)($result->issuecodes ?? [])))),
                    $issues,
                    $result
                );
            }

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                $errors[] = $label . ': task ' . $taskname . ' is not registered.';
                $issuecodes[] = preflight_contract_validator::ISSUE_TASK_NOT_REGISTERED;
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            if ($threadid > 0 && $userid > 0) {
                $input = $anonymizer->deanonymize_command_input_for_active_user($contextid, $userid, $input);
            }

            $preflightresult = $task->preflight($input, $contextid, $userid);
            foreach ($preflightresult->issuecodes as $code) {
                if ($code !== '') {
                    $issuecodes[] = $code;
                }
            }
            $issues = array_merge($issues, $preflightresult->issues);

            if ($preflightresult->status !== 'pass' && $preflightresult->status !== 'soft_block') {
                foreach ($preflightresult->issues as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $errors[] = $msg;
                    }
                }
                continue;
            }

            $updatedcommand = $command;
            $updatedcommand['input'] = $preflightresult->preparedinput;
            $preparedcommands[] = $updatedcommand;
        }

        $issuecodes = array_values(array_unique(array_filter(array_map('strval', $issuecodes))));
        $combinedissuecodes = array_values(array_unique(array_merge($issuecodes, $layer1issuecodes)));
        $legacyvalid = empty($errors);
        $domainresult = $this->domainrunner->run($combinedissuecodes, $startedat);

        if (!$legacyvalid && $domainresult->status === 'pass') {
            $domainresult = new preflight_result_v2(
                'hard_block',
                $combinedissuecodes,
                preflight_result_v2::BLOCKING_LAYER_DOMAIN,
                0,
                0,
                $domainresult->durationms
            );
        }

        $errorclass = $this->classify_error_class($combinedissuecodes);
        $result = $domainresult;
        if ($errorclass !== '' && in_array($errorclass, ['provider_timeout', 'transient_io'], true)) {
            $result = $this->executiongate->evaluate($errorclass, 0, $combinedissuecodes);
        }

        if ($result->status === 'pass' && !empty($layer1issuecodes)) {
            $result = new preflight_result_v2(
                'pass',
                $layer1issuecodes,
                '',
                $result->retryafterms,
                $result->retrycount,
                $result->durationms
            );
        }

        $this->auditlogger->append($threadid, 0, array_merge($this->build_audit_command_context($commands), [
            'contextid' => $contextid,
            'layer' => $result->blockinglayer !== '' ? $result->blockinglayer : 'preflight',
            'status' => $result->status,
            'issue_codes' => $result->issuecodes,
            'retry_count' => $result->retrycount,
            'retry_after_ms' => $result->retryafterms,
            'duration_ms' => $result->durationms,
            'error_class' => $errorclass,
        ]));

        $valid = $result->status === 'pass' && $legacyvalid;
        if ($result->status === 'retry_hint') {
            $errors[] = 'Preflight retry requested. Please retry after backoff.';
        } else if (($result->status === 'hard_block' || $result->status === 'soft_block') && empty($errors)) {
            $errors[] = $result->status === 'soft_block'
                ? 'Preflight requires clarification/confirmation before execution.'
                : 'Preflight blocked execution.';
        }

        return $this->build_output(
            $valid,
            $preparedcommands,
            array_values(array_unique($errors)),
            $attemptedtasks,
            array_values(array_unique(array_merge($combinedissuecodes, (array)$result->issuecodes))),
            $issues,
            $result
        );
    }

    /**
     * Map internal values to the public preflight batch output shape.
     *
     * @param bool $valid
     * @param array<int,array<string,mixed>> $preparedcommands
     * @param array<int,string> $errors
     * @param array<int,string> $attemptedtasks
     * @param array<int,string> $issuecodes
     * @param array<int,array<string,mixed>> $issues
     * @param preflight_result_v2 $result
     * @return array{status:string,issue_codes:array<int,string>,blocking_layer:string,retry_after_ms:int,retry_count:int,duration_ms:int,prepared_commands:array<int,array<string,mixed>>,errors:array<int,string>,attempted_tasks:array<int,string>,issues:array<int,array<string,mixed>>}
     */
    private function build_output(
        bool $valid,
        array $preparedcommands,
        array $errors,
        array $attemptedtasks,
        array $issuecodes,
        array $issues,
        preflight_result_v2 $result
    ): array {
        $v2result = $result->to_array();
        if (!$valid && ($v2result['status'] ?? '') === 'pass') {
            $v2result['status'] = 'hard_block';
            $v2result['blocking_layer'] = preflight_result_v2::BLOCKING_LAYER_DOMAIN;
        }

        return array_merge($v2result, [
            'prepared_commands' => $preparedcommands,
            'errors' => array_values(array_unique(array_map('strval', $errors))),
            'attempted_tasks' => array_values(array_unique(array_map('strval', $attemptedtasks))),
            'issue_codes' => array_values(array_unique(array_map('strval', $issuecodes))),
            'issues' => array_values(array_filter($issues, static fn($issue): bool => is_array($issue))),
        ]);
    }

    /**
     * Return unambiguous task audit fields for single-command preflight runs.
     *
     * @param array<int,array<string,mixed>> $commands
     * @return array{taskname:string,task_version:int}
     */
    private function build_audit_command_context(array $commands): array {
        if (count($commands) !== 1 || !is_array($commands[0] ?? null)) {
            return ['taskname' => '', 'task_version' => 0];
        }

        $command = (array)$commands[0];
        return [
            'taskname' => trim((string)($command['task'] ?? '')),
            'task_version' => max(1, (int)($command['version'] ?? 1)),
        ];
    }

    /**
     * Infer gate-relevant error class from issue codes.
     *
     * @param array<int,string> $issuecodes
     * @return string
     */
    private function classify_error_class(array $issuecodes): string {
        foreach ($issuecodes as $code) {
            $upper = core_text::strtoupper(trim((string)$code));
            if ($upper === '') {
                continue;
            }
            if (str_contains($upper, 'TIMEOUT')) {
                return 'provider_timeout';
            }
            if (str_contains($upper, 'TRANSIENT_IO') || str_contains($upper, 'IO_TRANSIENT')) {
                return 'transient_io';
            }
            if (str_contains($upper, 'PERMISSION')) {
                return 'permission_error';
            }
            if (str_contains($upper, 'CONFLICT')) {
                return 'domain_conflict';
            }
            if (str_contains($upper, 'VALIDATION') || str_contains($upper, 'MISSING_')) {
                return 'validation_error';
            }
        }

        return '';
    }
}
