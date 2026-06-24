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

use core\di;
use core_ai\manager as ai_manager;

/**
 * Moodle-version-agnostic view over core_ai provider configuration.
 *
 * Moodle 5.0 introduced multi-instance AI providers (the `ai_providers` table plus
 * manager methods `get_provider_instances()` / `create_/update_/enable_provider_instance()`).
 * Moodle 4.5 — supported until October 2027 — has the older single-instance model: one
 * config block per aiprovider plugin in `config_plugins`, with no instance table and no
 * instance methods on the manager (it exposes only `process_action()` + static helpers).
 *
 * This service hides that difference behind one shape. On 5.x it returns the real provider
 * instances; on 4.5 it synthesises one instance-shaped object per configured aiprovider
 * plugin from the plugin's flat config keys, so every read-site can keep iterating
 * `->provider`, `->enabled`, `->config[...]`, `->actionconfig[...]` unchanged.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_compat {
    /**
     * Whether the running Moodle exposes the 5.0+ multi-instance provider API.
     *
     * @return bool True on Moodle 5.0+, false on the 4.5 single-instance model.
     */
    public static function supports_provider_instances(): bool {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return false;
        }
        return method_exists($manager, 'get_provider_instances');
    }

    /**
     * Version-agnostic list of provider "views".
     *
     * Each returned object exposes the same fields a 5.x core_ai provider instance does and
     * that the agent's read-sites rely on: `->provider` (provider class name), `->enabled`,
     * `->name`, `->id`, `->config` (incl. `apikey`, `name`) and `->actionconfig` keyed by
     * action class name with `['enabled' => bool, 'settings' => ['endpoint','model',...]]`.
     *
     * @return array<int,object> Provider instances (5.x) or synthesised views (4.5).
     */
    public static function get_provider_views(): array {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return [];
        }

        if (method_exists($manager, 'get_provider_instances')) {
            return array_values((array)$manager->get_provider_instances());
        }

        return self::synthesise_legacy_views();
    }

    /**
     * Build instance-shaped views from 4.5 flat plugin config.
     *
     * Mirrors 5.x semantics where only *created* instances are returned: a plugin is only
     * surfaced when it is actually configured (`is_provider_configured()`), regardless of
     * whether it is currently enabled — disabled-but-configured must stay visible so the
     * trial "activate" path can still find it.
     *
     * @return array<int,object>
     */
    private static function synthesise_legacy_views(): array {
        $views = [];
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('aiprovider');
        foreach ($plugins as $plugin) {
            $component = (string)$plugin->component;            // e.g. 'aiprovider_openai'
            $providerclass = $component . '\\provider';
            if (!class_exists($providerclass)) {
                continue;
            }

            try {
                // 4.5 providers have a no-arg constructor (see core_ai\manager::get_providers_for_actions).
                $provider = new $providerclass();
                if (!$provider->is_provider_configured()) {
                    // Not configured -> there is no equivalent "instance" to report.
                    continue;
                }
                $actionlist = (array)$provider->get_action_list();
            } catch (\Throwable $e) {
                continue;
            }

            $views[] = (object)[
                'id' => null,
                'provider' => $providerclass,
                'name' => (string)($plugin->displayname ?? $component),
                'enabled' => (bool)$plugin->is_enabled(),
                'config' => [
                    'apikey' => (string)get_config($component, 'apikey'),
                    'name' => (string)($plugin->displayname ?? $component),
                ],
                'actionconfig' => self::legacy_actionconfig($component, $actionlist),
            ];
        }

        return $views;
    }

    /**
     * Assemble a 5.x-shaped actionconfig map from 4.5 per-action flat config keys.
     *
     * @param string $component The aiprovider component (e.g. 'aiprovider_openai').
     * @param array<int,string> $actionlist Action class names the provider supports.
     * @return array<string,array{enabled:bool,settings:array<string,mixed>}>
     */
    private static function legacy_actionconfig(string $component, array $actionlist): array {
        $actionconfig = [];
        foreach ($actionlist as $actionclass) {
            if (!is_string($actionclass) || !class_exists($actionclass)) {
                continue;
            }
            $basename = $actionclass::get_basename();

            $settings = [];
            $endpoint = (string)get_config($component, "action_{$basename}_endpoint");
            $model = (string)get_config($component, "action_{$basename}_model");
            $systeminstruction = (string)get_config($component, "action_{$basename}_systeminstruction");
            if ($endpoint !== '') {
                $settings['endpoint'] = $endpoint;
            }
            if ($model !== '') {
                $settings['model'] = $model;
            }
            if ($systeminstruction !== '') {
                $settings['systeminstruction'] = $systeminstruction;
            }

            $enabled = true;
            if (method_exists(ai_manager::class, 'is_action_enabled')) {
                $enabled = (bool)ai_manager::is_action_enabled($component, $actionclass);
            }

            $actionconfig[$actionclass] = [
                'enabled' => $enabled,
                'settings' => $settings,
            ];
        }

        return $actionconfig;
    }
}
