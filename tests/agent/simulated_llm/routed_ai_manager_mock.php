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
 * Reusable routed mock for core_ai manager in simulated LLM tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextionsion_agent;

use core_ai\aiactions\base;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_base;
use core_ai\aiactions\responses\response_generate_text;
use core_ai\aiactions\summarise_text;

/**
 * Route-aware AI manager mock.
 *
 * Routes are matched once on first prompt and then continue sequentially:
 * [
 *   [
 *     'prompt_contains' => ['needle1', 'needle2'],
 *     'responses' => [<payload>, <payload>, ...],
 *   ],
 * ]
 */
class routed_ai_manager_mock extends \core_ai\manager {
    /** @var array<int,\stdClass> */
    private array $routes;

    /** @var int|null */
    private ?int $activerouteindex = null;

    /**
     * Constructor.
     *
     * @param array<int,array<string,mixed>> $routes
     */
    public function __construct(array $routes) {
        global $DB;
        parent::__construct($DB);

        $normalizedroutes = [];
        foreach ($routes as $route) {
            $routeobject = new \stdClass();
            $routeobject->promptcontains = array_values(array_map(
                static fn(mixed $needle): string => trim((string)$needle),
                (array)($route['prompt_contains'] ?? [])
            ));
            $routeobject->responses = array_values(array_map(
                static fn(array $payload): response_generate_text => self::build_response($payload),
                (array)($route['responses'] ?? [])
            ));
            $routeobject->cursor = 0;
            $normalizedroutes[] = $routeobject;
        }

        $this->routes = $normalizedroutes;
    }

    /**
     * Return the next scripted model response for the active route.
     *
     * @param base $action
     * @return response_base
     */
    public function process_action(base $action): response_base {
        $prompttext = '';
        if (method_exists($action, 'get_configuration')) {
            $prompttext = (string)$action->get_configuration('prompttext');
        }

        $routeindex = $this->activerouteindex;
        if ($routeindex === null) {
            $routeindex = $this->find_route_index($prompttext);
            if ($routeindex === null) {
                $routeindex = 0;
            }
            $this->activerouteindex = $routeindex;
        }

        $route = $this->routes[$routeindex] ?? null;
        if (!$route instanceof \stdClass || empty($route->responses)) {
            return $this->fallback_response();
        }

        $cursor = min((int)($route->cursor ?? 0), count($route->responses) - 1);
        $response = $route->responses[$cursor] ?? null;
        if ($response instanceof response_generate_text && $cursor < count($route->responses) - 1) {
            $route->cursor = $cursor + 1;
            $this->routes[$routeindex] = $route;
            return $response;
        }

        if ($response instanceof response_generate_text) {
            return $response;
        }

        return $this->fallback_response();
    }

    /**
     * Report support for the actions used by booking tests.
     *
     * @param string $actionclass
     * @return bool
     */
    public function is_action_available(string $actionclass): bool {
        return in_array($actionclass, [generate_text::class, summarise_text::class, explain_text::class], true);
    }

    /**
     * Report all supported actions as enabled in context.
     *
     * @param \context $context
     * @param string $actionclass
     * @return bool
     */
    public function is_action_enabled_in_context(\context $context, string $actionclass): bool {
        return $this->is_action_available($actionclass);
    }

    /**
     * Return a fake provider list for requested actions.
     *
     * @param array<int,string> $actions
     * @param bool $enabledonly
     * @return array
     */
    public function get_providers_for_actions(array $actions, bool $enabledonly = false): array {
        $provider = (object) [
            'provider' => 'aiprovider_openai',
            'enabled' => 1,
            'id' => 1,
        ];

        $result = [];
        foreach ($actions as $action) {
            $result[$action] = [$provider];
        }

        return $result;
    }

    /**
     * Find first route matching current prompt.
     *
     * @param string $prompttext
     * @return int|null
     */
    private function find_route_index(string $prompttext): ?int {
        foreach ($this->routes as $index => $route) {
            if (empty($route->promptcontains)) {
                return $index;
            }

            foreach ($route->promptcontains as $needle) {
                if ($needle !== '' && stripos($prompttext, $needle) !== false) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Build one scripted response object from payload.
     *
     * @param array<string,mixed> $payload
     * @return response_generate_text
     */
    private static function build_response(array $payload): response_generate_text {
        $response = new response_generate_text(true);
        $response->set_response_data([
            'generatedcontent' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'model' => 'fake-booking-llm',
        ]);

        return $response;
    }

    /**
     * Deterministic fallback response.
     *
     * @return response_generate_text
     */
    private function fallback_response(): response_generate_text {
        return self::build_response([
            'response_type' => 'clarification',
            'message' => 'Mocked fallback response.',
        ]);
    }
}
