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
 * Readiness checks for connecting an external AI client (Claude) to this site over the MCP server
 * provided by the separate tool_oauthmcp plugin.
 *
 * The same MCP endpoint (/admin/tool/oauthmcp/server.php) can be reached two DIFFERENT ways, and
 * this class reports each separately so the UI can explain both clearly:
 *
 *  - OAuth 2.1 (browser login): the client is given the server URL, a browser window opens the
 *    Moodle login, the user consents and the client stores the grant. Used by claude.ai custom
 *    connectors and hosted Claude. Needs authmode oauth|both plus a working OAuth discovery chain.
 *  - Web-service token (Bearer): the client is configured with the server URL and a Moodle web
 *    service token. Used by Claude Code / Claude Desktop. Needs authmode wstoken|both.
 *
 * The MCP tools Claude sees come from tool_oauthmcp's tool_registry — the exposed web-service
 * functions AND the tools plugins contribute through the collect_tool_providers hook (the Booking
 * Wizard contributes its skills that way), so the tool count is read from the registry, not from
 * the exposedservices setting alone.
 *
 * The class never hard-depends on tool_oauthmcp: it only touches its classes behind class_exists,
 * building the endpoint URLs from $CFG->wwwroot itself, so the page is useful before the plugin is
 * even installed.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claude_connect_readiness {
    /** @var int Acting user id. */
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
     * Build the full readiness report: common prerequisites plus the per-method check groups.
     *
     * @return array<string,mixed> ['common', 'oauth', 'token' => check rows; 'oauth_ready',
     *                             'token_ready' => bool; 'server_url', 'token_manage_url' => string].
     */
    public function get_report(): array {
        global $CFG;

        $installed = \core_component::get_plugin_directory('tool', 'oauthmcp') !== null;
        $enabled = $installed && (bool)get_config('tool_oauthmcp', 'enabled');
        $authmode = (string)get_config('tool_oauthmcp', 'authmode');
        $oauthon = $installed && in_array($authmode, ['oauth', 'both'], true);
        $tokenon = $installed && in_array($authmode, ['wstoken', 'both'], true);
        $https = is_https();
        $toolcount = $this->count_tools();
        $canconnect = has_capability('tool/oauthmcp:connect', context_system::instance(), $this->userid);

        $settingsurl = (new moodle_url('/admin/settings.php', ['section' => 'tool_oauthmcp_settings']))->out(false);
        $canprobe = $enabled && $https;

        // Common prerequisites (needed for either connection method).
        $common = [];
        $common[] = $this->build_check(
            $installed,
            get_string('claudeconnect_check_installed', 'bookingextension_agent'),
            $installed
                ? get_string('claudeconnect_check_installed_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_installed_todo', 'bookingextension_agent'),
            $installed ? null : 'https://moodle.org/plugins/tool_oauthmcp'
        );
        $common[] = $this->build_check(
            $enabled,
            get_string('claudeconnect_check_enabled', 'bookingextension_agent'),
            $enabled
                ? get_string('claudeconnect_check_enabled_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_enabled_todo', 'bookingextension_agent'),
            $installed && !$enabled ? $settingsurl : null
        );
        $common[] = $this->build_check(
            $https,
            get_string('claudeconnect_check_https', 'bookingextension_agent'),
            $https
                ? get_string('claudeconnect_check_https_done', 'bookingextension_agent', $CFG->wwwroot)
                : get_string('claudeconnect_check_https_todo', 'bookingextension_agent', $CFG->wwwroot)
        );
        $common[] = $this->build_check(
            $toolcount > 0,
            get_string('claudeconnect_check_tools', 'bookingextension_agent'),
            $toolcount > 0
                ? get_string('claudeconnect_check_tools_done', 'bookingextension_agent', $toolcount)
                : get_string('claudeconnect_check_tools_todo', 'bookingextension_agent'),
            $installed && $toolcount === 0 ? $settingsurl : null
        );
        $common[] = $this->build_check(
            $canconnect,
            get_string('claudeconnect_check_capability', 'bookingextension_agent'),
            $canconnect
                ? get_string('claudeconnect_check_capability_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_capability_todo', 'bookingextension_agent'),
            !$canconnect ? (new moodle_url('/admin/roles/manage.php'))->out(false) : null
        );

        // Method 1: OAuth 2.1 (browser login).
        $oauth = [];
        $oauth[] = $this->build_check(
            $oauthon,
            get_string('claudeconnect_check_oauthmode', 'bookingextension_agent'),
            $oauthon
                ? get_string('claudeconnect_check_oauthmode_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_oauthmode_todo', 'bookingextension_agent'),
            $installed && !$oauthon ? $settingsurl : null
        );
        [$asok, $asdetail] = $this->probe_wellknown('oauth-authorization-server', 'issuer', $canprobe);
        $oauth[] = $this->build_check(
            $asok,
            get_string('claudeconnect_check_asmeta', 'bookingextension_agent'),
            $asdetail
        );
        [$prmok, $prmdetail] = $this->probe_wellknown('oauth-protected-resource', 'resource', $canprobe);
        $oauth[] = $this->build_check(
            $prmok,
            get_string('claudeconnect_check_prm', 'bookingextension_agent'),
            $prmdetail
        );
        [$serverok, $serverdetail] = $this->probe_mcp_server($canprobe);
        $oauth[] = $this->build_check(
            $serverok,
            get_string('claudeconnect_check_server', 'bookingextension_agent'),
            $serverdetail
        );

        // Method 2: web-service token (Bearer).
        $token = [];
        $token[] = $this->build_check(
            $tokenon,
            get_string('claudeconnect_check_tokenmode', 'bookingextension_agent'),
            $tokenon
                ? get_string('claudeconnect_check_tokenmode_done', 'bookingextension_agent')
                : get_string('claudeconnect_check_tokenmode_todo', 'bookingextension_agent'),
            $installed && !$tokenon ? $settingsurl : null
        );

        return [
            'common' => $common,
            'oauth' => $oauth,
            'token' => $token,
            'oauth_ready' => $this->all_done($common) && $this->all_done($oauth),
            'token_ready' => $this->all_done($common) && $this->all_done($token),
            'server_url' => $this->server_url(),
            'token_manage_url' => (new \moodle_url('/admin/webservice/tokens.php'))->out(false),
        ];
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
     * Count the MCP tools that would actually be exposed, across every source (exposed web-service
     * functions AND hook-contributed tools such as the Booking Wizard skills).
     *
     * @return int
     */
    private function count_tools(): int {
        if (!class_exists('\\tool_oauthmcp\\local\\registry\\tool_registry')) {
            return 0;
        }
        try {
            $inventory = \tool_oauthmcp\local\registry\tool_registry::create()
                ->get_inventory($this->userid, context_system::instance()->id);
            return count($inventory);
        } catch (\Throwable $e) {
            debugging('claude_connect_readiness: tool inventory failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Whether every row in a group passed.
     *
     * @param array $checks
     * @return bool
     */
    private function all_done(array $checks): bool {
        foreach ($checks as $check) {
            if (empty($check['done'])) {
                return false;
            }
        }
        return true;
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
     * template idiom renders both). A failed row shows a red cross.
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
