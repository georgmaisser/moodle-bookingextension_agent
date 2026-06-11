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

namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\dto\skill_risk_class;
use bookingextension_agent\local\wbagent\dto\target_selector;
use bookingextension_agent\local\wbagent\interfaces\skill_interface;
use bookingextension_agent\local\wbagent\services\preflight_result_v2;
use bookingextension_agent\local\wbagent\services\skill_prompt_contract;

/**
 * Base class for AI skills.
 *
 * Provides default pass-through implementations for structural and preflight
 * validation without legacy validate() shims.
 *
 * Migration path for subclasses:
 *  1. Override check_structure() for pure structural checks.
 *  2. Override preflight()       for DB-dependent deep validation.
 *  3. Override execute()         to use $preparedinput from preflight().
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_skill implements skill_interface {
    /** @var bool */
    protected bool $readonly;

    /** @var string */
    protected string $riskclass;

    /**
     * Constructor.
     *
     * @param bool $readonly
     * @param string $riskclass
     */
    public function __construct(bool $readonly, string $riskclass) {
        if (!skill_risk_class::is_valid($riskclass)) {
            throw new \coding_exception('Invalid risk class declared for skill base: ' . trim($riskclass));
        }

        $this->readonly = $readonly;
        $this->riskclass = trim($riskclass);
    }

    /**
     * Return whether the skill is read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return $this->readonly;
    }

    /**
     * Return the declared risk class.
     *
     * @return string
     */
    public function get_risk_class(): string {
        return $this->riskclass;
    }

    /**
     * Minimum Moodle context level this skill needs to operate (runtime context switch).
     *
     * Default CONTEXT_MODULE = behaves exactly as today. A skill that must act on a broader
     * scope (e.g. a course question bank) overrides this with CONTEXT_COURSE / CONTEXT_SYSTEM;
     * the executor then resolves the operating context via context_resolver before execution.
     *
     * @return int A Moodle CONTEXT_* level constant.
     */
    public function get_required_context_level(): int {
        return CONTEXT_MODULE;
    }

    /**
     * Whether this skill can run against an explicitly named operating context (cross-context).
     *
     * Default false = the skill always runs in the ambient context (today's behaviour). A skill
     * opts in by returning true AND implementing {@see self::get_target_selector()}; the operating
     * context is then resolved by skill_operating_context_resolver and the skill's native
     * capability (Gate 2) is re-checked there.
     *
     * OPT-IN SAFETY RULE (cross-context blueprint §8): only return true if this skill's capability
     * check binds to the OPERATING context — either by declaring
     * {@see self::get_required_native_capabilities()} (preferred; the engine enforces it at the
     * operating context), or by an inline require_capability/has_capability that uses the PASSED
     * $contextid (the operating context), never a hardwired ambient cmid/$USER. A skill relying
     * only on the ambient-checked governance capability (Gate 1) must NOT opt in.
     *
     * @return bool
     */
    public function supports_target_context(): bool {
        return false;
    }

    /**
     * The Moodle context level a cross-context target names (defaults to the required level).
     *
     * @return int A Moodle CONTEXT_* level constant.
     */
    public function get_target_context_level(): int {
        return $this->get_required_context_level();
    }

    /**
     * Build the operating-context target selector from this command's input, or null for none.
     *
     * Only consulted when {@see self::supports_target_context()} is true. Returning null or an
     * empty selector keeps the skill in the ambient context.
     *
     * @param array $input
     * @return target_selector|null
     */
    public function get_target_selector(array $input): ?target_selector {
        return null;
    }

    /**
     * Native Moodle capabilities required for this skill's underlying core action (Gate 2).
     *
     * The agent must never grant a right the user does not natively have. Every mutating
     * skill declares the native capability(ies) of the core action it performs (e.g.
     * 'mod/booking:updatebooking', 'moodle/question:add'); preflight enforces them at the
     * operating context via require_native_capabilities(). Default empty = read-only/none.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return [];
    }

    /**
     * Enforce the skill's native Moodle capabilities at the given (operating) context.
     *
     * Call this from preflight()/execute() with the operating context so the user's own
     * Moodle rights gate the action — independent of the agent skill capability.
     *
     * @param \context $operatingcontext
     * @param int $userid
     * @return void
     */
    protected function require_native_capabilities(\context $operatingcontext, int $userid): void {
        foreach ($this->get_required_native_capabilities() as $capability) {
            require_capability($capability, $operatingcontext, $userid);
        }
    }

    /**
     * Default example input.
     *
     * Concrete skill families can override this to provide centralized example
     * metadata close to their skill implementations.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [];
    }

    /**
     * Default explicit prompt-contract derived from skill schema.
     *
     * @return skill_prompt_contract
     */
    public function get_prompt_contract(): skill_prompt_contract {
        $schema = (array)$this->get_schema();
        $promptmeta = (array)($schema['prompt_meta'] ?? []);
        $skillname = trim($this->get_name());
        $namespace = '';
        if ($skillname !== '' && strpos($skillname, '.') !== false) {
            $namespace = (string)substr($skillname, 0, (int)strpos($skillname, '.'));
        }

        return new skill_prompt_contract([
            'intent' => trim((string)($promptmeta['intent'] ?? '')),
            'anchors' => array_values(array_filter((array)($promptmeta['anchor_fields'] ?? []), 'is_string')),
            'minimal_input' => array_values(array_filter((array)($promptmeta['input_fields_for_prompt'] ?? []), 'is_string')),
            'example_input' => $this->get_example_input(),
            'namespace' => $namespace,
            'version' => max(1, (int)($schema['version'] ?? 1)),
            'capabilities' => array_values(array_filter((array)($promptmeta['capabilities'] ?? []), 'is_string')),
            'context_scopes' => array_values(array_filter((array)($promptmeta['context_scopes'] ?? ['module']), 'is_string')),
            'risk_class' => skill_risk_class::is_valid($this->riskclass) ? $this->riskclass : '',
        ]);
    }

    /**
     * Default structural validation — always passes.
     *
     * Override in concrete skills to check required fields without DB access.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Default deep preflight keeps input unchanged after structure checks pass.
     *
     * @param  array $input
     * @param  int   $contextid
     * @param  int   $userid
     * @return preflight_result_v2
     */
    public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? true)) {
            $issues = [];
            foreach (array_merge((array)($structure['errors'] ?? []), (array)($structure['ambiguities'] ?? [])) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return preflight_result_v2::invalid($issues);
        }

        $confirmableissues = array_values(array_filter(
            (array)($structure['issues'] ?? []),
            static fn($issue): bool => is_array($issue) && (string)($issue['severity'] ?? '') === 'needs_confirmation'
        ));
        if (!empty($confirmableissues)) {
            return preflight_result_v2::confirmable($input, $confirmableissues);
        }

        return preflight_result_v2::ok($input);
    }
}
