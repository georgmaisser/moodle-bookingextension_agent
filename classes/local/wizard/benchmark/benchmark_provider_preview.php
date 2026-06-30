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

namespace bookingextension_agent\local\wizard\benchmark;

use bookingextension_agent\local\wizard\wb_action_names;
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Describes which provider/model/key values a benchmark run will actually use, so the
 * interface can show them next to the "run benchmark" button and the provider-instance picker.
 *
 * A run targets a chosen core_ai provider INSTANCE — any provider type, enabled or not (a key use is
 * to benchmark a not-yet-live instance). Its key/model/endpoint are extracted and applied as the
 * BOOKING_TEST_AI_* override onto the working provider, so the agent's wunderbyte actions run with
 * the chosen instance's credentials. The BOOKING_TEST_AI_* env vars themselves still override for CLI
 * runs; the web/cron path never sees them, hence explicit instance selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_provider_preview {
    /**
     * Per-role candidate action classes, tried in order against an instance's actionconfig. Covers
     * both the wunderbyte provider (agent actions) and generic providers (core_ai text/embeddings).
     *
     * @var array<string, string[]>
     */
    private const ROLE_ACTIONS = [
        'planner' => [
            wb_action_names::PLANNER_DECIDE,
            wb_action_names::GENERATE_AGENT_REPLY,
            'core_ai\\aiactions\\generate_text',
        ],
        'reply' => [
            wb_action_names::GENERATE_AGENT_REPLY,
            'core_ai\\aiactions\\generate_text',
        ],
        'embed' => [
            wb_action_names::GENERATE_EMBEDDINGS,
            'core_ai\\aiactions\\generate_embeddings',
        ],
    ];

    /**
     * All configured provider instances (any type, enabled or not), id => provider object.
     *
     * @return array
     */
    private function provider_instances(): array {
        global $DB;
        $out = [];
        try {
            $manager = new \core_ai\manager($DB);
            foreach ($manager->get_sorted_providers() as $provider) {
                $out[(int)$provider->id] = $provider;
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        return $out;
    }

    /**
     * Extract the override values (key / per-role model / endpoint) from a provider instance,
     * provider-type agnostically: for each role pick the first candidate action present that carries
     * a model, and read that action's model + endpoint from its settings.
     *
     * @param \core_ai\provider $provider
     * @return array{key: string, planner: string, reply: string, embed: string, endpoint: string}
     */
    public static function extract_overrides(\core_ai\provider $provider): array {
        $config = (array)($provider->config ?? []);
        $ac     = (array)($provider->actionconfig ?? []);

        $modelof = static fn(string $action): string => (string)($ac[$action]['settings']['model'] ?? '');
        $endpointof = static fn(string $action): string => (string)($ac[$action]['settings']['endpoint'] ?? '');

        $pick = static function (array $actions) use ($ac, $modelof): array {
            foreach ($actions as $action) {
                if (isset($ac[$action]) && $modelof($action) !== '') {
                    return [$action, $modelof($action)];
                }
            }
            return ['', ''];
        };

        [$replyaction, $replymodel] = $pick(self::ROLE_ACTIONS['reply']);
        [, $plannermodel]           = $pick(self::ROLE_ACTIONS['planner']);
        [, $embedmodel]             = $pick(self::ROLE_ACTIONS['embed']);

        // Endpoint lives in the action settings (not config); take it from the reply action, else the
        // first action that carries one, else any config-level endpoint.
        $endpoint = $replyaction !== '' ? $endpointof($replyaction) : '';
        if ($endpoint === '') {
            foreach ($ac as $entry) {
                if (!empty($entry['settings']['endpoint'])) {
                    $endpoint = (string)$entry['settings']['endpoint'];
                    break;
                }
            }
        }
        if ($endpoint === '') {
            $endpoint = (string)($config['endpoint'] ?? ($config['apiendpoint'] ?? ''));
        }

        return [
            'key'      => (string)($config['apikey'] ?? ''),
            'planner'  => $plannermodel,
            'reply'    => $replymodel,
            'embed'    => $embedmodel,
            'endpoint' => $endpoint,
        ];
    }

    /**
     * Selectable provider instances for the run form: id => display name (disabled ones flagged).
     *
     * @return array
     */
    public function list_instances(): array {
        $out = [];
        foreach ($this->provider_instances() as $id => $provider) {
            $name = (string)$provider->name;
            // Disabled instances are listed too — a key use of interface benchmarks is to score a
            // not-yet-live instance before enabling it — but flagged so it is obvious.
            if (empty($provider->enabled)) {
                $name .= ' ' . get_string('benchmark_run_instance_disabled', 'bookingextension_agent');
            }
            $out[$id] = $name;
        }
        return $out;
    }

    /**
     * Compute the effective benchmark provider values for the chosen instance.
     *
     * @param int|null $instanceid The provider instance to describe; null = the default (first sorted).
     * @return array {
     *   env_override_active: bool, embeddings_active: bool, provider_found: bool, instance_enabled: bool,
     *   instance_id: int, instance_name: string,
     *   key: array{source: string, detail: string}, endpoint: array{source: string, value: string},
     *   actions: array<int, array{label: string, model: string, source: string, envvar: string}>
     * }
     */
    public function describe(?int $instanceid = null): array {
        $envkey        = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $envmodel      = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $envmodelmini  = trim((string)(getenv('BOOKING_TEST_AI_MODEL_MINI') ?: ''));
        $envembedmodel = trim((string)(getenv('BOOKING_TEST_AI_EMBEDDING_MODEL') ?: ''));
        $envendpoint   = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));
        $envactive     = $envkey !== '';

        // Embeddings are live for routing iff the skill catalog is current for the active variant —
        // the same freshness check skill_governance surfaces. When live, capture the embedding model.
        $embedoverride = ($envactive && $envembedmodel !== '') ? $envembedmodel : null;
        $embeddingsmodel = self::catalog_model_if_ready($embedoverride);
        $embeddingsactive = $embeddingsmodel !== '';

        // Resolve the target instance: the one requested, else the default (first sorted).
        $instances = $this->provider_instances();
        $target = null;
        if ($instanceid !== null && isset($instances[$instanceid])) {
            $target = $instances[$instanceid];
        } else if (!empty($instances)) {
            $target = reset($instances);
        }
        $providerfound = $target !== null;
        $instname      = $providerfound ? (string)$target->name : '';
        $instid        = $providerfound ? (int)$target->id : 0;
        $instenabled   = $providerfound ? !empty($target->enabled) : false;
        $ov            = $providerfound
            ? self::extract_overrides($target)
            : ['key' => '', 'planner' => '', 'reply' => '', 'embed' => '', 'endpoint' => ''];

        $resolve = static function (string $role, string $envvalue, string $envvarname) use ($envactive, $ov): array {
            if ($envactive && $envvalue !== '') {
                return ['model' => $envvalue, 'source' => 'env', 'envvar' => $envvarname];
            }
            return ['model' => $ov[$role], 'source' => 'provider', 'envvar' => $envvarname];
        };

        $plannerenv = $envmodelmini !== '' ? $envmodelmini : $envmodel;
        $plannervar = $envmodelmini !== '' ? 'BOOKING_TEST_AI_MODEL_MINI' : 'BOOKING_TEST_AI_MODEL';
        $planner = $resolve('planner', $plannerenv, $plannervar);
        $reply   = $resolve('reply', $envmodel, 'BOOKING_TEST_AI_MODEL');
        $embed   = $resolve('embed', $envembedmodel, 'BOOKING_TEST_AI_EMBEDDING_MODEL');

        // Key source: env override (CLI only), else the instance's own key, else none.
        if ($envactive) {
            $key = ['source' => 'env', 'detail' => 'BOOKING_TEST_AI_KEY'];
        } else if ($ov['key'] !== '') {
            $key = ['source' => 'provider', 'detail' => $instname];
        } else {
            $key = ['source' => 'none', 'detail' => get_string('benchmark_run_key_none', 'bookingextension_agent')];
        }

        if ($envactive && $envendpoint !== '') {
            $endpoint = ['source' => 'env', 'value' => $envendpoint];
        } else {
            $endpoint = ['source' => 'provider', 'value' => $ov['endpoint']];
        }

        return [
            'env_override_active' => $envactive,
            'embeddings_active'   => $embeddingsactive,
            'embeddings_model'    => $embeddingsmodel,
            'provider_found'      => $providerfound,
            'instance_enabled'    => $instenabled,
            'instance_id'         => $instid,
            'instance_name'       => $instname,
            'key'                 => $key,
            'endpoint'            => $endpoint,
            'actions'             => [
                ['label' => get_string('benchmark_run_action_planner', 'bookingextension_agent')] + $planner,
                ['label' => get_string('benchmark_run_action_reply', 'bookingextension_agent')] + $reply,
                ['label' => get_string('benchmark_run_action_embed', 'bookingextension_agent')] + $embed,
            ],
        ];
    }

    /**
     * The embedding model in use when the skill catalog is current for the active variant (the same
     * freshness check skill_governance surfaces), or '' when the catalog is stale/missing or no
     * embeddings provider is available — i.e. when embeddings are not live for routing.
     *
     * @param string|null $modeloverride Explicit embedding-model override (CLI env), or null.
     * @return string
     */
    public static function catalog_model_if_ready(?string $modeloverride = null): string {
        $readiness = new embeddings_readiness_service();
        if (!$readiness->is_wunderbyte_embeddings_available()) {
            return '';
        }
        $cfg = (new embeddings_action_config_resolver())->resolve_with_overrides($modeloverride, null);
        $status = $readiness->get_catalog_status(
            skill_registry_factory::get_default(),
            (string)$cfg['model'],
            (int)$cfg['dimensions']
        );
        return ((string)($status['status'] ?? '')) === 'ready' ? (string)$cfg['model'] : '';
    }
}
