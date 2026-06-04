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

use bookingextension_agent\local\wbagent\dto\task_risk_class;

/**
 * Deterministic classifier for runtime finalization strategy.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalization_classifier {
    /** @var string */
    public const STRATEGY_DIRECT_FINAL = 'direct_final';

    /** @var string */
    public const STRATEGY_TEMPLATE_ONLY = 'template_only';

    /** @var string */
    public const STRATEGY_LLM_POLISH = 'llm_polish';

    /** @var string[] */
    private const DIRECT_RESPONSE_TYPES = [
        'confirmation_request',
        'confirm_pending',
        'task_call',
    ];

    /** @var string[] */
    private const DIRECT_ISSUE_CODES = [
        'SCHEMA_ERROR',
        'SCHEMA_UNAVAILABLE',
        'DEPENDENCY_CYCLE',
        'CONTRACT_INVALID_RESPONSE_TYPE',
        'CONTRACT_COMMANDS_REQUIRED',
        'CONTRACT_PHASE_RESPONSE_TYPE',
        'CONTRACT_PHASE_COMMANDS_NOT_ALLOWED',
        'CONTRACT_PHASE_SINGLE_COMMAND_REQUIRED',
        'CONTRACT_PHASE_TASK_NOT_ALLOWED',
    ];

    /** @var string[] */
    private const TEMPLATE_ISSUE_CODES = [
        'BUDGET_EXCEEDED',
        'BLOCKED_TIMEOUT',
        'RETRY_EXHAUSTED',
        'PERMISSION_ERROR',
        'VALIDATION_ERROR',
        'CONTEXT_INVALID',
        'CONTRACT_SELECTION_TASK_MISSING',
        // Synchronizer consistency gate rejections are terminal — never retry via llm_polish.
        'SYNC_FACT_CONFLICT_REJECTED',
        'SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED',
        'SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED',
        'SYNC_SOURCE_RESPONSE_ERROR_REJECTED',
        'SYNC_COMMAND_PAYLOAD_REJECTED',
        'SYNC_EMPTY_MESSAGE',
        'SYNC_RAW_EXCERPT_REJECTED',
    ];

    /** @var string[] */
    private const TEMPLATE_ERROR_CLASSES = [
        'provider_timeout',
        'transient_io',
        'auth_failed',
        'quota_exceeded',
        'runtime_disabled',
    ];

    /**
     * Classify finalization strategy from normalized result metadata.
     *
     * @param array<string,mixed> $result
     * @return string One of STRATEGY_* constants.
     */
    public function classify(array $result): string {
        $responsetype = trim((string)($result['response_type'] ?? ''));
        $hascommands = $this->has_commands($result);
        $issuecodes = $this->normalize_issue_codes($result);
        $errorclass = trim((string)($result['error_class'] ?? ''));
        $errorclass = strtolower($errorclass);
        $structuralfailure = !empty($result['structural_failure']);
        $riskclass = $this->resolve_risk_class($result);

        if ($hascommands) {
            return self::STRATEGY_DIRECT_FINAL;
        }

        if (in_array($responsetype, self::DIRECT_RESPONSE_TYPES, true)) {
            return self::STRATEGY_DIRECT_FINAL;
        }

        if ($this->contains_any($issuecodes, self::DIRECT_ISSUE_CODES)) {
            return self::STRATEGY_DIRECT_FINAL;
        }

        if ($this->contains_any($issuecodes, self::TEMPLATE_ISSUE_CODES)) {
            return self::STRATEGY_TEMPLATE_ONLY;
        }

        if ($errorclass !== '' && in_array($errorclass, self::TEMPLATE_ERROR_CLASSES, true)) {
            return self::STRATEGY_TEMPLATE_ONLY;
        }

        if ($responsetype === 'sufficient' || $responsetype === 'clarification') {
            if (in_array($riskclass, [task_risk_class::R2, task_risk_class::R3], true)) {
                return self::STRATEGY_LLM_POLISH;
            }

            return self::STRATEGY_LLM_POLISH;
        }

        if ($responsetype === 'error' && !$structuralfailure) {
            return self::STRATEGY_LLM_POLISH;
        }

        // Safe fallback: preserve deterministic and structural behavior.
        return self::STRATEGY_DIRECT_FINAL;
    }

    /**
     * Check whether the synchronizer output must include an irreversibility notice.
     *
     * @param array<string,mixed> $result
     * @return bool
     */
    public function requires_irreversibility_notice(array $result): bool {
        if (trim((string)($result['response_type'] ?? '')) !== 'sufficient') {
            return false;
        }

        return $this->resolve_explicit_risk_class($result) === task_risk_class::R3;
    }

    /**
     * Check whether the synchronizer output must include an affected-scope summary.
     *
     * @param array<string,mixed> $result
     * @return bool
     */
    public function requires_affected_scope_summary(array $result): bool {
        if (trim((string)($result['response_type'] ?? '')) !== 'sufficient') {
            return false;
        }

        return $this->resolve_explicit_risk_class($result) === task_risk_class::R2;
    }

    /**
     * Resolve explicit risk_class from the top-level payload.
     *
     * Synchronizer guard requirements should only trigger when risk_class
     * is declared explicitly by runtime output, not inferred implicitly.
     *
     * @param array<string,mixed> $result
     * @return string
     */
    private function resolve_explicit_risk_class(array $result): string {
        $riskclass = trim((string)($result['risk_class'] ?? ''));
        if (task_risk_class::is_valid($riskclass)) {
            return $riskclass;
        }

        return '';
    }

    /**
     * Determine whether the result currently carries executable commands.
     *
     * @param array<string,mixed> $result
     * @return bool
     */
    private function has_commands(array $result): bool {
        $commands = $result['commands'] ?? [];
        if (!is_array($commands)) {
            return false;
        }

        if (empty($commands)) {
            return false;
        }

        // Accept both list and single-associative command payloads.
        if (!array_is_list($commands) && isset($commands['task'])) {
            return true;
        }

        return array_is_list($commands) && !empty($commands);
    }

    /**
     * Normalize issue codes to unique uppercase values.
     *
     * @param array<string,mixed> $result
     * @return string[]
     */
    private function normalize_issue_codes(array $result): array {
        $raw = $result['issue_codes'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $code) {
            $value = strtoupper(trim((string)$code));
            if ($value !== '') {
                $codes[] = $value;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Returns true when at least one needle exists in haystack.
     *
     * @param string[] $haystack
     * @param string[] $needles
     * @return bool
     */
    private function contains_any(array $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (in_array($needle, $haystack, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the effective risk class from the result payload.
     *
     * @param array<string,mixed> $result
     * @return string
     */
    private function resolve_risk_class(array $result): string {
        $riskclass = trim((string)($result['risk_class'] ?? ''));
        if (task_risk_class::is_valid($riskclass)) {
            return $riskclass;
        }

        $commands = $result['commands'] ?? [];
        if (is_array($commands)) {
            $firstcommand = array_is_list($commands) ? (array)($commands[0] ?? []) : $commands;
            $riskclass = trim((string)($firstcommand['risk_class'] ?? ''));
            if (task_risk_class::is_valid($riskclass)) {
                return $riskclass;
            }
        }

        return task_risk_class::R3;
    }
}
