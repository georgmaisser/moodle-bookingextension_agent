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
 * Preview type registry.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent;

use bookingextension_agent\local\wbagent\interfaces\skill_preview_renderer_interface;

/**
 * Maps preview type strings to their respective server-side and client-side handlers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_type_registry {
    /** @var array<string,skill_preview_renderer_interface> */
    private array $renderers = [];

    /** @var array<string,string> */
    private array $jsmodules = [];

    /**
     * Register a preview type with its handlers.
     *
     * @param string $type
     * @param skill_preview_renderer_interface|null $renderer
     * @param string|null $jsmodule
     * @return void
     */
    public function register(string $type, ?skill_preview_renderer_interface $renderer, ?string $jsmodule): void {
        if ($renderer !== null) {
            $this->renderers[$type] = $renderer;
        }
        if ($jsmodule !== null) {
            $this->jsmodules[$type] = $jsmodule;
        }
    }

    /**
     * Get the server-side renderer for a preview type.
     *
     * @param string $type
     * @return skill_preview_renderer_interface|null
     */
    public function get_renderer(string $type): ?skill_preview_renderer_interface {
        return $this->renderers[$type] ?? null;
    }

    /**
     * Get the JS module name for a preview type.
     *
     * @param string $type
     * @return string|null
     */
    public function get_js_module(string $type): ?string {
        return $this->jsmodules[$type] ?? null;
    }

    /**
     * Get all registered JS modules mapped by type.
     *
     * @return array<string,string>
     */
    public function get_all_js_modules(): array {
        return $this->jsmodules;
    }
}
