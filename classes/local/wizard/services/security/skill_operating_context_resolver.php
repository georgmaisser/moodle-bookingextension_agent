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

namespace bookingextension_agent\local\wizard\services\security;

use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_interface;

/**
 * Decides the operating context for a single skill command.
 *
 * This is the seam between a skill and {@see context_resolver}. A skill opts into cross-context
 * execution by exposing two optional, duck-typed methods (same pattern as `get_result_preview` /
 * `get_sensitive_input_fields`):
 *
 *   - `supports_target_context(): bool`
 *   - `get_target_selector(array $input): ?target_selector`
 *   - `get_target_context_level(): int`  (optional; defaults to the skill's required context level)
 *
 * A skill that exposes none of these — i.e. every skill today — always operates in its **ambient**
 * context, so wiring this resolver into the pipeline is behaviour-preserving until a skill opts in.
 *
 * Resolving a context is never a privilege grant: the caller still enforces Gate 2
 * (require_capability) at the returned operating context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_operating_context_resolver {
    /** @var context_resolver */
    private context_resolver $contextresolver;

    /**
     * Constructor.
     *
     * @param context_resolver|null $contextresolver Injectable for tests.
     */
    public function __construct(?context_resolver $contextresolver = null) {
        $this->contextresolver = $contextresolver ?? new context_resolver();
    }

    /**
     * Resolve the operating context for a skill command.
     *
     * @param skill_interface $skill   The selected skill.
     * @param array           $input   The (prepared or raw) command input.
     * @param agent_context   $ambient The context the chat/thread lives in.
     * @param int             $userid  Acting user id.
     * @return agent_context The operating context (equals the ambient context unless the skill
     *         opts into cross-context execution and names a resolvable target).
     * @throws context_target_unresolved_exception When an opted-in skill names an unresolvable target.
     */
    public function resolve(skill_interface $skill, array $input, agent_context $ambient, int $userid): agent_context {
        if (!$this->skill_opts_into_target_context($skill)) {
            return $ambient;
        }

        $selector = $skill->get_target_selector($input);
        if (!($selector instanceof target_selector)) {
            return $ambient;
        }
        // A module target stays meaningful even when empty (auto-pick the unique instance in scope);
        // only a truly empty NON-module selector falls back to the ambient context.
        if ($selector->is_empty() && !$selector->is_module_target()) {
            return $ambient;
        }

        $level = $this->resolve_target_level($skill);

        return $this->contextresolver->resolve_operating_context($ambient, $level, $selector, $userid);
    }

    /**
     * Whether the skill exposes the opt-in target-context contract.
     *
     * @param skill_interface $skill
     * @return bool
     */
    private function skill_opts_into_target_context(skill_interface $skill): bool {
        return method_exists($skill, 'supports_target_context')
            && method_exists($skill, 'get_target_selector')
            && (bool)$skill->supports_target_context();
    }

    /**
     * The context level the skill's target names (defaults to its required context level).
     *
     * @param skill_interface $skill
     * @return int
     */
    private function resolve_target_level(skill_interface $skill): int {
        if (method_exists($skill, 'get_target_context_level')) {
            $level = (int)$skill->get_target_context_level();
            if ($level > 0) {
                return $level;
            }
        }
        if (method_exists($skill, 'get_required_context_level')) {
            return (int)$skill->get_required_context_level();
        }
        return CONTEXT_MODULE;
    }
}
