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
 * Single source of truth for the aiprovider_wunderbyte action class names.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * Fully-qualified class names of the Wunderbyte AI provider actions the agent invokes.
 *
 * These FQCNs were previously redeclared as a private const in ~9 classes (and had begun to
 * drift — e.g. one carried a leading backslash). They are defined here once so every consumer
 * derives from the same value.
 */
class wb_action_names {
    /** @var string The planner-decide (generate_text) action. */
    public const PLANNER_DECIDE = 'aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** @var string The generate-agent-reply (synchronizer) action. */
    public const GENERATE_AGENT_REPLY = 'aiprovider_wunderbyte\\aiactions\\generate_agent_reply';

    /** @var string The generate-embeddings action. */
    public const GENERATE_EMBEDDINGS = 'aiprovider_wunderbyte\\aiactions\\generate_embeddings';
}
