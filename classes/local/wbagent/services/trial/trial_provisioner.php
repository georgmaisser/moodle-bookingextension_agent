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

namespace bookingextension_agent\local\wbagent\services\trial;

use cache;
use core\http_client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Provisions a working AI provider from a Wunderbyte trial key.
 *
 * This closes the gap the old flow left open: requesting a trial used to only
 * cache a nonce and claim success, while no LiteLLM key was ever minted and no
 * AI provider instance was created. Here we run the full chain:
 *
 *   nonce  ->  POST {base}/api/moodle-trial  ->  {apikey, endpoint, model}
 *          ->  core_ai create/enable provider instance (config + actionconfig)
 *
 * The Wunderbyte trial service verifies the request origin by calling back to
 * trial_challenge.php?token={nonce} (see that file), so the nonce must be cached
 * before the POST. Endpoint is always the LiteLLM proxy, hard-coded to
 * https://llm.wunderbyte.at (see self::BASE_URL).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trial_provisioner {
    /** @var string Hard-coded LiteLLM/trial service base URL (intentionally not an admin setting). */
    private const BASE_URL = 'https://llm.wunderbyte.at';

    /** @var string Provider instance name shared by both strategies (also the legacy OpenAI name). */
    private const INSTANCE_NAME = 'Wunderbyte';

    /** @var int Seconds to wait for the trial service (its own back-channel + LiteLLM call take a moment). */
    private const HTTP_TIMEOUT = 25;

    /**
     * Run the full trial provisioning for the given context.
     *
     * @param int $contextid Page/module context the trial was started from (used only for messaging/audit).
     * @param string|null $strategy 'wunderbyte' | 'openai'; null = auto-detect from installed providers.
     * @return array{success: bool, message: string} User-facing result.
     */
    public function provision(int $contextid, ?string $strategy = null): array {
        if (!class_exists('\\core_ai\\manager')) {
            return $this->fail(get_string('aitrial_coreai_unavailable', 'bookingextension_agent'));
        }

        $strategy = $strategy ?? $this->detect_strategy();
        if ($strategy === null) {
            // No usable provider plugin installed: point the admin at the Wunderbyte provider.
            $url = get_string('aitrial_provider_install_url', 'bookingextension_agent');
            return $this->fail(get_string('aitrial_provider_required', 'bookingextension_agent', $url));
        }

        // 1. Mint a nonce and cache it so the trial service's origin check (trial_challenge.php) succeeds.
        $nonce = random_string(32);
        $cache = cache::make('bookingextension_agent', 'trialnonce');
        $cache->set('nonce_' . $nonce, $nonce);

        // 2. Exchange the nonce for an API key at the trial endpoint.
        $exchange = $this->exchange_nonce($nonce);
        if (!$exchange['success']) {
            return $this->fail($exchange['message']);
        }

        // 3. Create (or repair) the provider instance with the returned key + endpoint.
        try {
            $this->upsert_provider_instance(
                $strategy,
                (string)$exchange['apikey'],
                (string)$exchange['endpoint'],
            );
        } catch (\Throwable $e) {
            debugging('trial_provisioner: provider instance creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->fail(get_string('aitrial_provision_failed', 'bookingextension_agent'));
        }

        return [
            'success' => true,
            'message' => get_string('aitrial_provider_created', 'bookingextension_agent'),
        ];
    }

    /**
     * Decide which provider plugin to provision against.
     *
     * Wunderbyte is preferred (full action/skill coverage incl. embeddings). The
     * OpenAI-compatible path is the documented fallback with a reduced skill set.
     *
     * @return string|null 'wunderbyte', 'openai', or null when neither is installed.
     */
    private function detect_strategy(): ?string {
        if (\core_component::get_plugin_directory('aiprovider', 'wunderbyte')) {
            return 'wunderbyte';
        }
        if (\core_component::get_plugin_directory('aiprovider', 'openai')) {
            return 'openai';
        }
        return null;
    }

    /**
     * POST the nonce to the trial endpoint and normalise the response.
     *
     * @param string $nonce
     * @return array{success: bool, message: string, apikey?: string, endpoint?: string}
     */
    private function exchange_nonce(string $nonce): array {
        global $CFG;

        $base = rtrim(self::BASE_URL, '/');
        $url = $base . '/api/moodle-trial';

        $request = new Request(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['wwwroot' => $CFG->wwwroot, 'nonce' => $nonce]),
        );

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => self::HTTP_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            // Cannot even reach the service from here (often a firewall on the Moodle side).
            debugging('trial_provisioner: trial endpoint unreachable: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->fail(get_string('aitrial_support_firewall', 'bookingextension_agent'));
        }

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);

        if ($status === 200 && is_array($body) && !empty($body['apikey'])) {
            return [
                'success' => true,
                'message' => '',
                'apikey' => (string)$body['apikey'],
                // ALWAYS use the admin-configured PUBLIC base for the provider's action
                // endpoints. The service echoes back its OWN internal LiteLLM URL (e.g.
                // http://litellm:4000) which Moodle cannot reach and curl blocks — so we
                // deliberately ignore $body['endpoint'] here.
                'endpoint' => $base,
            ];
        }

        // 403 = origin could not be verified (firewall / site not publicly reachable).
        if ($status === 403) {
            return $this->fail(get_string('aitrial_support_firewall', 'bookingextension_agent'));
        }

        // 409 = a trial was already issued for this site URL (one trial per URL).
        if ($status === 409) {
            return $this->fail(get_string('aitrial_already_exists', 'bookingextension_agent'));
        }

        // 429 = an abuse cap was hit (per-IP or global). The service sends the exact,
        // already user-facing reason in `detail` — surface it verbatim.
        if ($status === 429) {
            $detail = (is_array($body) && !empty($body['detail'])) ? (string)$body['detail'] : '';
            return $this->fail($detail !== ''
                ? $detail
                : get_string('aitrial_provision_failed', 'bookingextension_agent'));
        }

        debugging('trial_provisioner: unexpected trial response HTTP ' . $status, DEBUG_DEVELOPER);
        return $this->fail(get_string('aitrial_provision_failed', 'bookingextension_agent'));
    }

    /**
     * Create the provider instance, or update+enable an existing same-named one (idempotent).
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @param string $apikey LiteLLM virtual key returned by the trial service
     * @param string $endpoint LiteLLM base URL (e.g. https://llm.wunderbyte.at)
     */
    private function upsert_provider_instance(string $strategy, string $apikey, string $endpoint): void {
        $manager = \core\di::get(\core_ai\manager::class);

        $classname = $strategy === 'wunderbyte'
            ? 'aiprovider_wunderbyte\\provider'
            : 'aiprovider_openai\\provider';

        $config = ['apikey' => $apikey];
        $actionconfig = $this->build_actionconfig($strategy, $endpoint);

        $existing = (array)$manager->get_provider_instances([
            'name' => self::INSTANCE_NAME,
            'provider' => $classname,
        ]);

        if ($existing) {
            $instance = reset($existing);
            $instance = $manager->update_provider_instance(
                provider: $instance,
                config: $config,
                actionconfig: $actionconfig,
            );
            if (empty($instance->enabled)) {
                $manager->enable_provider_instance($instance);
            }
            return;
        }

        $manager->create_provider_instance(
            classname: $classname,
            name: self::INSTANCE_NAME,
            enabled: true,
            config: $config,
            actionconfig: $actionconfig,
        );
    }

    /**
     * Build the per-action endpoint/model map for the chosen strategy.
     *
     * Trial model aliases (granted by the minted key): wunderbyte-privat (chat),
     * wunderbyte-privat-mini (compact planner), wunderbyte-embeddings (embeddings).
     * `providerid` is intentionally omitted — core_ai owns the instance id.
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @param string $endpoint LiteLLM base URL (no trailing slash expected, but tolerated)
     * @return array<string, array<string, mixed>>
     */
    private function build_actionconfig(string $strategy, string $endpoint): array {
        $base = rtrim($endpoint, '/');
        $chat = $base . '/v1/chat/completions';
        $embeddings = $base . '/v1/embeddings';

        // generate_text is a core_ai action both providers process; it is the minimum
        // for a usable agent and therefore the whole config for the OpenAI fallback.
        $generatetext = [
            'core_ai\\aiactions\\generate_text' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat',
                    'systeminstruction' => '[[action_generate_text_instruction]]',
                ],
            ],
        ];

        if ($strategy === 'openai') {
            // OpenAI provider has no embeddings/planner/agent-reply actions -> reduced skill set (by design).
            return $generatetext;
        }

        // Full Wunderbyte trial config: embeddings + compact planner + agent reply + generate_text.
        return [
            'aiprovider_wunderbyte\\aiactions\\generate_embeddings' => [
                'enabled' => true,
                'settings' => [
                    'endpoint' => $embeddings,
                    'model' => 'wunderbyte-embeddings',
                    'dimensions' => 1536,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\planner_decide' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat-mini',
                    'systeminstruction' => 'Act as a compact planner and return a structured routing decision as plain JSON.',
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\generate_agent_reply' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat',
                    'systeminstruction' => 'Compose the final user-facing response in the requested language.',
                ],
            ],
        ] + $generatetext;
    }

    /**
     * Shorthand for a failed result.
     *
     * @param string $message
     * @return array{success: bool, message: string}
     */
    private function fail(string $message): array {
        return ['success' => false, 'message' => $message];
    }
}
