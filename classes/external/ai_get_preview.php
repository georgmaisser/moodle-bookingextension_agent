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
 * External service: get generic AI preview content.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_module;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wbagent\services\security\authorization_service;
use bookingextension_agent\local\wbagent\skill_registry_factory;

/**
 * Render visual preview for AI commands dynamically using registered preview types.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_get_preview extends external_api {
    /**
     * Describe parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'type' => new external_value(PARAM_TEXT, 'Preview type registered key.'),
            'payload_json' => new external_value(PARAM_RAW, 'JSON encoded preview payload.'),
        ]);
    }

    /**
     * Get and render the preview.
     *
     * @param int $contextid
     * @param string $type
     * @param string $payload_json
     * @return array
     */
    public static function execute(int $contextid, string $type, string $payload_json): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'type' => $type,
            'payload_json' => $payload_json,
        ]);

        $authz = new authorization_service();
        try {
            $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
            if (!($context instanceof context_module)) {
                throw new \coding_exception('Invalid module context id.');
            }
            self::validate_context($context);
            $authz->require_agent_usage($context);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'html' => 'Access denied: ' . $e->getMessage(),
                'js_module' => null,
                'js_data_json' => '{}',
            ];
        }

        $registry = skill_registry_factory::get_default();
        $previewregistry = $registry->get_preview_type_registry();
        $renderer = $previewregistry->get_renderer($params['type']);
        $jsmodule = $previewregistry->get_js_module($params['type']);

        $payload = json_decode($params['payload_json'], true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $html = '';
        $success = false;

        if ($renderer !== null) {
            try {
                $html = $renderer->render($payload, (int)$context->id, (int)$USER->id);
                $success = true;
            } catch (\Throwable $e) {
                $html = 'Error rendering preview: ' . $e->getMessage();
            }
        } else if ($jsmodule !== null) {
            // Client-rendered only, but server acknowledges JS module exists.
            $success = true;
        }

        return [
            'success' => $success,
            'html' => $html,
            'js_module' => $jsmodule,
            'js_data_json' => json_encode($payload),
        ];
    }

    /**
     * Describe return shape.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether preview could be rendered.'),
            'html' => new external_value(PARAM_RAW, 'Rendered preview HTML.'),
            'js_module' => new external_value(PARAM_TEXT, 'JS module name if applicable.', VALUE_OPTIONAL),
            'js_data_json' => new external_value(PARAM_RAW, 'JSON payload for client-side JS.', VALUE_OPTIONAL),
        ]);
    }
}
