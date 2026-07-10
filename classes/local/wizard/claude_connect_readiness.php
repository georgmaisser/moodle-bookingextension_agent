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

namespace bookingextension_agent\local\wizard;

use context_system;
use moodle_url;

/**
 * Readiness checks for connecting an external AI client (Claude) to this site over MCP + OAuth 2.1.
 *
 * The connection is provided by the separate tool_oauthmcp plugin (an OAuth 2.1 authorization server
 * plus a streamable MCP endpoint). This class probes every prerequisite Claude needs — the plugin's
 * presence and configuration, HTTPS, the live OAuth discovery documents and the MCP challenge — and
 * returns one green-tick / red-cross row per check, mirroring {@see aiready}'s readiness rows so the
 * UI renders them identically.
 *
 * It is dependency-free with respect to tool_oauthmcp: it never autoloads a tool_oauthmcp class (the
 * plugin may be absent), building the endpoint URLs from $CFG->wwwroot itself.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claude_connect_readiness {
    /** @var int Acting user id (for the per-user connect capability check). */
    private int $userid;

    /**
     * Constructor.
     *
     * @param int $userid Acting user id.
     */
    public function __construct(int $userid) {
        $this->userid = $userid;
    }

    /**
     * Build the full ordered list of readiness checks.
     *
     * @return array<int,array<string,mixed>> Check rows (done, label, detail, configureurl, ...).
     */
    public function get_checks(): array {
        global $CFG;

        $installed = \core_component::get_plugin_directory('tool', 'oauthmcp') !== null;
        $enabled = $installed && (bool)get_config('tool_oauthmcp', 'enabled');
        $authmode = (string)get_config('tool_oauthmcp', 'authmode');
        $allowsoauth = $installed && in_array($authmode, ['oauth', 'both'], true);
        $exposed = $this->has_exposed_services();
        $https = is_https();

        $settingsurl = (new moodle_url('/admin/settings.php', ['section' => 'tool_oauthmcp_settings']))->out(false);

        $checks = [];

        // 1. The MCP/OAuth plugin must be installed at all.
        $checks[] = $this->build_check(
            $installed,
            get_string('claudeconnect_check_installed', 'bookingextension_agent'),
            $installed
                ? get_string('claudeconnect_check_installed_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_installed_todo', 'bookingextension_agent'),
            $installed ? null : 'https://moodle.org/plugins/tool_oauthmcp'
        );

        // 2. The MCP server must be switched on.
        $checks[] = $this->build_check(
            $enabled,
            get_string('claudeconnect_check_enabled', 'bookingextension_agent'),
            $enabled
                ? get_string('claudeconnect_check_enabled_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_enabled_todo', 'bookingextension_agent'),
            $installed && !$enabled ? $settingsurl : null
        );

        // 3. OAuth 2.1 must be an accepted authentication mode (Claude authenticates via OAuth).
        $checks[] = $this->build_check(
            $allowsoauth,
            get_string('claudeconnect_check_oauthmode', 'bookingextension_agent'),
            $allowsoauth
                ? get_string('claudeconnect_check_oauthmode_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_oauthmode_todo', 'bookingextension_agent'),
            $installed && !$allowsoauth ? $settingsurl : null
        );

        // 4. At least one web service must be exposed, otherwise Claude connects but sees no tools.
        $checks[] = $this->build_check(
            $exposed,
            get_string('claudeconnect_check_services', 'bookingextension_agent'),
            $exposed
                ? get_string('claudeconnect_check_services_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_services_todo', 'bookingextension_agent'),
            $installed && !$exposed ? $settingsurl : null
        );

        // 5. HTTPS is mandatory for remote OAuth clients such as Claude.
        $checks[] = $this->build_check(
            $https,
            get_string('claudeconnect_check_https', 'bookingextension_agent'),
            $https
                ? get_string('claudeconnect_check_https_done', 'bookingextension_agent', $CFG->wwwroot)
                : get_string('claudeconnect_check_https_todo', 'bookingextension_agent', $CFG->wwwroot)
        );

        // 6-8. Live discovery probes — only meaningful once the plugin is enabled over HTTPS. When the
        // prerequisites above are unmet we skip the network calls and report them as blocked, so the
        // page never hangs on curl against a half-configured or plain-HTTP endpoint.
        $canprobe = $enabled && $https;
        [$asok, $asdetail] = $this->probe_wellknown('oauth-authorization-server', 'issuer', $canprobe);
        $checks[] = $this->build_check(
            $asok,
            get_string('claudeconnect_check_asmeta', 'bookingextension_agent'),
            $asdetail
        );

        [$prmok, $prmdetail] = $this->probe_wellknown('oauth-protected-resource', 'resource', $canprobe);
        $checks[] = $this->build_check(
            $prmok,
            get_string('claudeconnect_check_prm', 'bookingextension_agent'),
            $prmdetail
        );

        [$serverok, $serverdetail] = $this->probe_mcp_server($canprobe);
        $checks[] = $this->build_check(
            $serverok,
            get_string('claudeconnect_check_server', 'bookingextension_agent'),
            $serverdetail
        );

        // 9. The acting user must be allowed to connect an external client at all.
        $canconnect = has_capability('tool/oauthmcp:connect', context_system::instance(), $this->userid);
        $checks[] = $this->build_check(
            $canconnect,
            get_string('claudeconnect_check_capability', 'bookingextension_agent'),
            $canconnect
                ? get_string('claudeconnect_check_capability_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_capability_todo', 'bookingextension_agent'),
            !$canconnect ? (new moodle_url('/admin/roles/manage.php'))->out(false) : null
        );

        return $checks;
    }

    /**
     * Whether every check has passed (used to reveal the how-to block).
     *
     * @param array<int,array<string,mixed>> $checks
     * @return bool
     */
    public function all_passed(array $checks): bool {
        foreach ($checks as $check) {
            if (empty($check['done'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * The MCP endpoint URL a client is given to add the connector.
     *
     * @return string
     */
    public function server_url(): string {
        global $CFG;
        return rtrim($CFG->wwwroot, '/') . '/admin/tool/oauthmcp/server.php';
    }

    /**
     * Whether at least one web service is exposed over MCP.
     *
     * @return bool
     */
    private function has_exposed_services(): bool {
        $raw = trim((string)get_config('tool_oauthmcp', 'exposedservices'));
        if ($raw === '') {
            return false;
        }
        // The multicheckbox admin setting stores a comma-separated list of external_services ids.
        foreach (explode(',', $raw) as $id) {
            if (trim($id) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Probe a RFC 8414 / RFC 9728 well-known discovery document.
     *
     * @param string $wellknown The well-known name (oauth-authorization-server | oauth-protected-resource).
     * @param string $requiredfield The JSON field that must be present and non-empty (issuer | resource).
     * @param bool $canprobe Whether the prerequisites for probing are met.
     * @return array{0:bool,1:string} [ok, detail]
     */
    private function probe_wellknown(string $wellknown, string $requiredfield, bool $canprobe): array {
        global $CFG;

        if (!$canprobe) {
            return [false, get_string('claudeconnect_check_blocked', 'bookingextension_agent')];
        }

        $host = preg_replace('#^(https?://[^/]+).*$#', '$1', $CFG->wwwroot);
        $issuerpath = rtrim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/');
        $url = $host . '/.well-known/' . $wellknown . $issuerpath;

        $result = $this->fetch($url);
        $decoded = json_decode($result['body'], true);
        $ok = $result['code'] === 200 && is_array($decoded) && trim((string)($decoded[$requiredfield] ?? '')) !== '';

        return [$ok, $url . ' (HTTP ' . $result['code'] . ')'];
    }

    /**
     * Probe the MCP endpoint: an unauthenticated request must answer 401 with a resource-metadata
     * challenge, which is exactly how the OAuth handshake with Claude begins.
     *
     * @param bool $canprobe Whether the prerequisites for probing are met.
     * @return array{0:bool,1:string} [ok, detail]
     */
    private function probe_mcp_server(bool $canprobe): array {
        if (!$canprobe) {
            return [false, get_string('claudeconnect_check_blocked', 'bookingextension_agent')];
        }

        $result = $this->fetch($this->server_url());
        $challenge = preg_match('/WWW-Authenticate:.*resource_metadata=/i', $result['headers']) === 1;
        $ok = $result['code'] === 401 && $challenge;

        return [$ok, 'HTTP ' . $result['code']];
    }

    /**
     * Fetch a URL server-side, capturing headers, tolerating self-signed dev certificates.
     *
     * @param string $url
     * @return array{code:int,headers:string,body:string}
     */
    private function fetch(string $url): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $body = (string)$curl->get($url, [], [
            'CURLOPT_SSL_VERIFYPEER' => 0,
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_TIMEOUT' => 8,
            'CURLOPT_CONNECTTIMEOUT' => 8,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_HEADER' => 1,
        ]);
        $info = $curl->get_info();
        $headersize = (int)($info['header_size'] ?? 0);

        return [
            'code' => (int)($info['http_code'] ?? 0),
            'headers' => substr($body, 0, $headersize),
            'body' => substr($body, $headersize),
        ];
    }

    /**
     * Build a single readiness row (identical shape to {@see aiready::build_check()} so the same
     * template renders both).
     *
     * @param bool $done
     * @param string $label
     * @param string $detail
     * @param string|null $configureurl
     * @return array<string,mixed>
     */
    private function build_check(bool $done, string $label, string $detail, ?string $configureurl = null): array {
        return [
            'done' => $done,
            'label' => $label,
            'detail' => $detail,
            'configureurl' => $configureurl,
            'configurelabel' => get_string('aiready_configure_here', 'bookingextension_agent'),
            'icon' => $done
                ? '<i class="fa fa-check-square text-success" aria-hidden="true"></i>'
                : '<i class="fa fa-times text-danger" aria-hidden="true"></i>',
        ];
    }
}
