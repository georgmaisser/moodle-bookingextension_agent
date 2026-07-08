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
 * Event fired when an agent skill is executed through the MCP facade.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\event;

use core\event\base;

/**
 * Audit-trail event: an external MCP client (e.g. Claude) called an agent skill.
 *
 * Triggered by {@see \bookingextension_agent\local\wizard\services\mcp\mcp_execution_service}
 * for every executed tool call, so programmatic skill usage is visible in the standard
 * Moodle log report — the chat path has its own conversation records, the MCP path has this.
 */
class mcp_tool_called extends base {
    /**
     * Initialise the event metadata.
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('mcp_event_tool_called', 'bookingextension_agent');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description(): string {
        $skill = (string)($this->other['skill'] ?? '');
        $status = (string)($this->other['status'] ?? '');
        return "The user with id '{$this->userid}' called the agent skill '{$skill}' via MCP "
            . "in the context with id '{$this->contextid}' (status: '{$status}').";
    }
}
