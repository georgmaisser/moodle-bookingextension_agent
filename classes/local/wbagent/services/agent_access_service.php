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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Full-access gate for the AI agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wbagent\services;

use bookingextension_agent\local\wb_license;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager as ai_manager;
use core_text;

/**
 * Decides whether the agent runs with full access or readonly-only skills.
 *
 * Full access is granted when either
 *  - the agent's LLM calls actually hit the Wunderbyte LLM gateway (trial or
 *    subscription — enforcement of the subscription itself happens server-side
 *    at that gateway), or
 *  - a valid PRO license is set (product 'wbagent' or combined 'bookingagent',
 *    see wb_license).
 *
 * Without full access only readonly skills are executable; mutating skills are
 * surfaced as UNAVAILABLE so replies can point at the upgrade path.
 */
class agent_access_service {
    /** @var string Host suffix of the Wunderbyte LLM subscription gateway. */
    private const WUNDERBYTE_LLM_HOST_SUFFIX = 'wunderbyte.at';

    /** Wunderbyte planner action class name (optional plugin, referenced by name). */
    private const WB_ACTION_PLANNER_DECIDE = 'aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** Wunderbyte final reply action class name (optional plugin, referenced by name). */
    private const WB_ACTION_GENERATE_AGENT_REPLY = 'aiprovider_wunderbyte\\aiactions\\generate_agent_reply';

    /** @var bool|null Request-scoped memoization (evaluator runs once per skill). */
    private static ?bool $fullaccess = null;

    /**
     * Whether the agent currently runs with full access (all skills).
     *
     * @return bool
     */
    public static function has_full_access(): bool {
        if (self::$fullaccess !== null) {
            return self::$fullaccess;
        }

        // The license check is local and cheap and also carries the
        // PHPUnit/Behat override, so it goes first.
        self::$fullaccess = wb_license::agent_license_is_activated() || self::runs_on_wunderbyte_llm();

        return self::$fullaccess;
    }

    /**
     * Reset the request-scoped cache (config changes, unit tests).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$fullaccess = null;
    }

    /**
     * Whether the agent's LLM calls actually go to the Wunderbyte LLM gateway.
     *
     * The provider plugin is freely configurable, so the gate must check the
     * endpoint URL that would actually be called: the primary enabled provider
     * for the agent's planner action (in routing preference order) has to point
     * at a wunderbyte.at host. A configured non-Wunderbyte endpoint decides
     * negatively — later fallback actions are only consulted when an action has
     * no provider at all.
     *
     * @return bool
     */
    public static function runs_on_wunderbyte_llm(): bool {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return false;
        }

        $actions = [
            self::WB_ACTION_PLANNER_DECIDE,
            self::WB_ACTION_GENERATE_AGENT_REPLY,
            summarise_text::class,
            generate_text::class,
        ];

        foreach ($actions as $actionclass) {
            if (!class_exists($actionclass)) {
                continue;
            }

            $endpoint = self::resolve_primary_endpoint($manager, $actionclass);
            if ($endpoint === null) {
                // No provider serves this action — consult the next fallback action.
                continue;
            }

            return self::is_wunderbyte_host($endpoint);
        }

        return false;
    }

    /**
     * Find provider instances whose action endpoints point at the Wunderbyte LLM gateway.
     *
     * Endpoint-based detection that replaces any provider-name/-class heuristic: an instance
     * counts only when it actually targets a wunderbyte.at host, so a Wunderbyte provider that
     * is pointed at a different endpoint is deliberately NOT matched. Scans every instance
     * (including disabled ones) so the trial "activate" path can find a configured-but-off trial.
     *
     * @param bool $enabledonly Only consider enabled instances.
     * @return array<int,object> Matching provider instances.
     */
    public static function find_wunderbyte_llm_instances(bool $enabledonly = false): array {
        // provider_compat::get_provider_views() returns real instances on Moodle 5.x and
        // synthesised, instance-shaped views on Moodle 4.5 (no get_provider_instances() there).
        $matches = [];
        foreach (provider_compat::get_provider_views() as $instance) {
            if ($enabledonly && empty($instance->enabled)) {
                continue;
            }
            if (self::instance_targets_wunderbyte_llm($instance)) {
                $matches[] = $instance;
            }
        }

        return array_values($matches);
    }

    /**
     * Whether a provider instance's configured action endpoints point at the Wunderbyte LLM gateway.
     *
     * Reads the instance's own actionconfig (no manager routing), so it works for disabled
     * instances too. True as soon as any configured action endpoint is a wunderbyte.at host.
     *
     * @param object $instance A core_ai provider instance.
     * @return bool
     */
    public static function instance_targets_wunderbyte_llm(object $instance): bool {
        $actionconfig = (array)($instance->actionconfig ?? []);
        foreach ($actionconfig as $cfg) {
            $settings = (array)(($cfg ?? [])['settings'] ?? []);
            $endpoint = trim((string)($settings['endpoint'] ?? $settings['apiendpoint'] ?? ''));
            if ($endpoint !== '' && self::is_wunderbyte_host($endpoint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the configured endpoint of the primary enabled provider for an action.
     *
     * @param ai_manager $manager
     * @param string $actionclass
     * @return string|null null when no provider serves the action; '' when the
     *                     provider has no endpoint setting.
     */
    private static function resolve_primary_endpoint(ai_manager $manager, string $actionclass): ?string {
        try {
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            $list = (array)($providers[$actionclass] ?? []);
            if (empty($list)) {
                return null;
            }

            $primary = reset($list);
            $actionconfig = (array)($primary->actionconfig ?? []);
            $settings = (array)(($actionconfig[$actionclass] ?? [])['settings'] ?? []);

            return trim((string)($settings['endpoint'] ?? $settings['apiendpoint'] ?? ''));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether an endpoint URL points at the Wunderbyte LLM gateway.
     *
     * @param string $endpoint
     * @return bool
     */
    private static function is_wunderbyte_host(string $endpoint): bool {
        if ($endpoint === '') {
            return false;
        }

        $host = core_text::strtolower(trim((string)parse_url($endpoint, PHP_URL_HOST)));
        if ($host === '') {
            return false;
        }

        return $host === self::WUNDERBYTE_LLM_HOST_SUFFIX
            || str_ends_with($host, '.' . self::WUNDERBYTE_LLM_HOST_SUFFIX);
    }
}
