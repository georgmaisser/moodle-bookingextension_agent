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

/**
 * Describes which provider/model/key values a benchmark run will actually use,
 * so the interface can show them next to the "run benchmark" button.
 *
 * Mirrors {@see benchmark_envkey_manager}: when BOOKING_TEST_AI_KEY is set the
 * BOOKING_TEST_AI_* env vars override the configured provider; otherwise the
 * configured wunderbyte provider is used exactly as in production.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_provider_preview {
    /** @var string Wunderbyte provider class. */
    private const WB_PROVIDER_CLASS = 'aiprovider_wunderbyte\\provider';

    /** @var string Action: planner/selector. */
    private const ACTION_PLANNER = 'aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** @var string Action: agent reply. */
    private const ACTION_REPLY = 'aiprovider_wunderbyte\\aiactions\\generate_agent_reply';

    /** @var string Action: embeddings. */
    private const ACTION_EMBED = 'aiprovider_wunderbyte\\aiactions\\generate_embeddings';

    /**
     * Compute the effective benchmark provider values.
     *
     * @return array {
     *   env_override_active: bool,
     *   provider_found: bool,
     *   key: array{source: string, detail: string},
     *   endpoint: array{source: string, value: string},
     *   actions: array<int, array{label: string, model: string, source: string, envvar: string}>
     * }
     */
    public function describe(): array {
        global $DB;

        $envkey        = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $envmodel      = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $envmodelmini  = trim((string)(getenv('BOOKING_TEST_AI_MODEL_MINI') ?: ''));
        $envembedmodel = trim((string)(getenv('BOOKING_TEST_AI_EMBEDDING_MODEL') ?: ''));
        $envendpoint   = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));
        $envactive     = $envkey !== '';

        // Read the configured wunderbyte provider straight from the standard manager (no override),
        // exactly the instance a non-env run would use.
        $config = [];
        $actionconfig = [];
        $providerfound = false;
        try {
            $manager   = new \core_ai\manager($DB);
            $providers = $manager->get_sorted_providers();
            $wbclass   = self::WB_PROVIDER_CLASS;
            foreach ($providers as $provider) {
                if ($provider instanceof $wbclass) {
                    $config       = (array)($provider->config ?? []);
                    $actionconfig = (array)($provider->actionconfig ?? []);
                    $providerfound = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $providerfound = false;
        }

        $providermodel = static function (string $action) use ($actionconfig): string {
            return (string)($actionconfig[$action]['settings']['model'] ?? '');
        };

        // Resolve one action's effective model + where it comes from.
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

        // Key source.
        if ($envactive) {
            $key = ['source' => 'env', 'detail' => 'BOOKING_TEST_AI_KEY'];
        } else if (!empty($config['apikey'])) {
            $key = ['source' => 'provider', 'detail' => get_string('benchmark_run_key_provider', 'bookingextension_agent')];
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
            'provider_found'      => $providerfound,
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
