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

namespace bookingextension_agent\local\wbagent\services\security;

use bookingextension_agent\local\wbagent\dto\context_target_resolution;
use moodle_exception;

/**
 * Thrown when an explicit operating-context target cannot be resolved to exactly one context.
 *
 * Carries the {@see context_target_resolution} so the caller can turn an ambiguous / not-found /
 * unsupported outcome into the right user-facing clarification instead of silently falling back
 * to the ambient context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_target_unresolved_exception extends moodle_exception {
    /** @var context_target_resolution The non-resolved outcome. */
    private context_target_resolution $resolution;

    /**
     * Constructor.
     *
     * @param context_target_resolution $resolution
     */
    public function __construct(context_target_resolution $resolution) {
        $this->resolution = $resolution;
        // Phase 0: no user-facing string yet — the caller turns get_resolution() into a proper
        // clarification (candidate list etc.) when this is wired in. A core fallback message keeps
        // this exception self-contained without a dangling lang string. The dedicated
        // 'error_context_target_unresolved' string + clarification UX land with that wiring.
        parent::__construct(
            'error',
            'moodle',
            '',
            null,
            'context target unresolved: ' . $resolution->status()
        );
    }

    /**
     * Return the resolution outcome (status + any candidates).
     *
     * @return context_target_resolution
     */
    public function get_resolution(): context_target_resolution {
        return $this->resolution;
    }
}
