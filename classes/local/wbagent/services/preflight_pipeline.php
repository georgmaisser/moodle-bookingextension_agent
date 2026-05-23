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

    /** @var preflight_schema_validator */
    private preflight_schema_validator $schemavalidator;

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
        $this->schemavalidator = new preflight_schema_validator($registry);
        $this->domainrunner = new preflight_domain_check_runner();
        $this->executiongate = new preflight_execution_gate();
        $this->auditlogger = new preflight_audit_logger($store);
    }

    /**
     * Run full preflight L1->L2->L3 for a command batch.
     *
     * @param array<int,mixed> $commands
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @return array{valid:bool,prepared_commands:array<int,array<string,mixed>>,errors:array<int,string>,attempted_tasks:array<int,string>,issue_codes:array<int,string>,v2_result:array<string,mixed>}
     */
    public function run(array $commands, int $threadid, int $cmid, int $userid): array {
        $preparedcommands = [];
        $errors = [];
        $attemptedtasks = [];
        $issuecodes = [];
        $layer1issuecodes = [];
        $anonymizer = new privacy_anonymizer($this->store);
        $startedat = microtime(true);

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

            $schemavalidation = $this->schemavalidator->validate($command);
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
                    'layer' => preflight_result_v2::BLOCKING_LAYER_SCHEMA,
                    'status' => $result->status,
                    'issue_codes' => $result->issuecodes,
                    'retry_count' => 0,
                    'duration_ms' => $result->durationms,
                    'error_class' => (string)($schemavalidation['error_class'] ?? 'schema_error'),
                ]);

                return $this->build_output(
                    false,
                    $preparedcommands,
                    array_values(array_unique(array_merge($errors, (array)($schemavalidation['errors'] ?? [])))),
                    $attemptedtasks,
                    array_values(array_unique(array_merge($issuecodes, (array)($result->issuecodes ?? [])))),
                    $result
                );
            }

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                $errors[] = $label . ': task ' . $taskname . ' is not registered.';
                $issuecodes[] = preflight_version_validator::ISSUE_TASK_NOT_REGISTERED;
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            if ($threadid > 0 && $userid > 0) {
                $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            }

            $preflightresult = $task->preflight($input, $cmid, $userid);
            foreach ($preflightresult->get_issue_codes() as $code) {
                if ($code !== '') {
                    $issuecodes[] = $code;
                }
            }

            if (!$preflightresult->isvalid) {
                foreach ($preflightresult->issues as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $errors[] = $msg;
                    }
                }
                foreach ($errors as $error) {
                    $normalizederror = core_text::strtolower(trim((string)$error));
                    if (str_contains($normalizederror, 'no user matched user query')) {
                        $issuecodes[] = 'TEACHER_USER_NOT_FOUND';
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

        $errorclass = $this->infer_error_class_from_issue_codes($combinedissuecodes);
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

        $this->auditlogger->append($threadid, 0, [
            'layer' => $result->blockinglayer !== '' ? $result->blockinglayer : 'preflight',
            'status' => $result->status,
            'issue_codes' => $result->issuecodes,
            'retry_count' => $result->retrycount,
            'duration_ms' => $result->durationms,
            'error_class' => $errorclass,
        ]);

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
     * @param preflight_result_v2 $result
     * @return array{valid:bool,prepared_commands:array<int,array<string,mixed>>,errors:array<int,string>,attempted_tasks:array<int,string>,issue_codes:array<int,string>,v2_result:array<string,mixed>}
     */
    private function build_output(
        bool $valid,
        array $preparedcommands,
        array $errors,
        array $attemptedtasks,
        array $issuecodes,
        preflight_result_v2 $result
    ): array {
        return [
            'valid' => $valid,
            'prepared_commands' => $preparedcommands,
            'errors' => array_values(array_unique(array_map('strval', $errors))),
            'attempted_tasks' => array_values(array_unique(array_map('strval', $attemptedtasks))),
            'issue_codes' => array_values(array_unique(array_map('strval', $issuecodes))),
            'v2_result' => $result->to_array(),
        ];
    }

    /**
     * Infer gate-relevant error class from issue codes.
     *
     * @param array<int,string> $issuecodes
     * @return string
     */
    private function infer_error_class_from_issue_codes(array $issuecodes): string {
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
