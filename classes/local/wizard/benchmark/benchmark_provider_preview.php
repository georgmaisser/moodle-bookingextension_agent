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
use bookingextension_agent\local\wizard\config\runtime_feature_flags;

/**
 * Describes which provider/model/key values a benchmark run will actually use, so the
 * interface can show them next to the "run benchmark" button and the provider-instance picker.
 *
 * A run targets a chosen core_ai provider INSTANCE (its own key/model/endpoint, configured in the
 * standard AI admin UI). The BOOKING_TEST_AI_* env vars still override when present, but only for
 * CLI runs launched in a shell that exports them — the web/cron context never sees them, which is
 * why the interface uses explicit instance selection rather than env vars.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_provider_preview {
    /** @var string Wunderbyte provider class. */
    private const WB_PROVIDER_CLASS = 'aiprovider_wunderbyte\\provider';

    /** @var string Action: planner/selector. */
    private const ACTION_PLANNER = wb_action_names::PLANNER_DECIDE;

    /** @var string Action: agent reply. */
    private const ACTION_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /** @var string Action: embeddings. */
    private const ACTION_EMBED = wb_action_names::GENERATE_EMBEDDINGS;

    /**
     * The configured wunderbyte provider instances, id => provider object.
     *
     * @return array
     */
    private function wunderbyte_instances(): array {
        global $DB;
        $out = [];
        try {
            $manager = new \core_ai\manager($DB);
            $wbclass = self::WB_PROVIDER_CLASS;
            foreach ($manager->get_sorted_providers() as $provider) {
                if ($provider instanceof $wbclass) {
                    $out[(int)$provider->id] = $provider;
                }
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        return $out;
    }

    /**
     * Selectable provider instances for the run form: id => display name.
     *
     * @return array
     */
    public function list_instances(): array {
        $out = [];
        foreach ($this->wunderbyte_instances() as $id => $provider) {
            $out[$id] = (string)$provider->name;
        }
        return $out;
    }

    /**
     * Compute the effective benchmark provider values for the chosen instance.
     *
     * @param int|null $instanceid The provider instance to describe; null = the default (first sorted).
     * @return array {
     *   env_override_active: bool, embeddings_active: bool, provider_found: bool,
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

        // Whether family/skill embeddings are currently live — the routing mode a run would use.
        $embeddingsactive = runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED);

        // Resolve the target instance: the one requested, else the default (first sorted).
        $instances = $this->wunderbyte_instances();
        $target = null;
        if ($instanceid !== null && isset($instances[$instanceid])) {
            $target = $instances[$instanceid];
        } else if (!empty($instances)) {
            $target = reset($instances);
        }
        $providerfound = $target !== null;
        $config        = $providerfound ? (array)($target->config ?? []) : [];
        $actionconfig  = $providerfound ? (array)($target->actionconfig ?? []) : [];
        $instname      = $providerfound ? (string)$target->name : '';
        $instid        = $providerfound ? (int)$target->id : 0;

        $providermodel = static function (string $action) use ($actionconfig): string {
            return (string)($actionconfig[$action]['settings']['model'] ?? '');
        };
        $resolve = static function (string $action, string $envvalue, string $envvarname) use ($envactive, $providermodel): array {
            if ($envactive && $envvalue !== '') {
                return ['model' => $envvalue, 'source' => 'env', 'envvar' => $envvarname];
            }
            return ['model' => $providermodel($action), 'source' => 'provider', 'envvar' => $envvarname];
        };

        // Planner/mini falls back to BOOKING_TEST_AI_MODEL when MINI is unset (same as the manager).
        $plannerenv = $envmodelmini !== '' ? $envmodelmini : $envmodel;
        $plannervar = $envmodelmini !== '' ? 'BOOKING_TEST_AI_MODEL_MINI' : 'BOOKING_TEST_AI_MODEL';
        $planner = $resolve(self::ACTION_PLANNER, $plannerenv, $plannervar);
        $reply   = $resolve(self::ACTION_REPLY, $envmodel, 'BOOKING_TEST_AI_MODEL');
        $embed   = $resolve(self::ACTION_EMBED, $envembedmodel, 'BOOKING_TEST_AI_EMBEDDING_MODEL');

        // Key source: env override (CLI only), else the instance's own key, else none.
        if ($envactive) {
            $key = ['source' => 'env', 'detail' => 'BOOKING_TEST_AI_KEY'];
        } else if (!empty($config['apikey'])) {
            $key = ['source' => 'provider', 'detail' => $instname];
        } else {
            $key = ['source' => 'none', 'detail' => get_string('benchmark_run_key_none', 'bookingextension_agent')];
        }

        // Endpoint source.
        if ($envactive && $envendpoint !== '') {
            $endpoint = ['source' => 'env', 'value' => $envendpoint];
        } else {
            $endpoint = ['source' => 'provider', 'value' => (string)($config['endpoint'] ?? ($config['apiendpoint'] ?? ''))];
        }

        return [
            'env_override_active' => $envactive,
            'embeddings_active'   => $embeddingsactive,
            'provider_found'      => $providerfound,
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
}
