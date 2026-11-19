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
 * HTTP GET for the image services.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\images;

/**
 * One GET request through Moodle's curl (proxy and blocked-host settings of the site apply).
 *
 * A test replaces the transport, so no test reaches an image library.
 */
class image_http_client {
    /** @var int Largest response body the image services accept (bytes). */
    public const MAX_BYTES = 8388608;

    /** @var callable|null TEST-ONLY transport: fn(string $url, string $useragent): array{code:int, body:string}. */
    private static $testtransport = null;

    /**
     * Install (or clear with null) the test transport.
     *
     * @param callable|null $transport
     * @return void
     */
    public static function set_test_transport(?callable $transport): void {
        self::$testtransport = $transport;
    }

    /**
     * Fetch a URL.
     *
     * @param string $url
     * @param int $timeout Seconds.
     * @return array{code:int, body:string} code 0 when the request did not complete or the body is too large.
     */
    public function get(string $url, int $timeout = 30): array {
        $useragent = self::user_agent();
        if (self::$testtransport !== null) {
            $response = (self::$testtransport)($url, $useragent);
            return ['code' => (int)($response['code'] ?? 0), 'body' => (string)($response['body'] ?? '')];
        }
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader('User-Agent: ' . $useragent);
        $body = $curl->get($url, [], ['CURLOPT_TIMEOUT' => $timeout, 'CURLOPT_FOLLOWLOCATION' => true, 'CURLOPT_MAXREDIRS' => 3]);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || !is_string($body) || strlen($body) > self::MAX_BYTES) {
            return ['code' => 0, 'body' => ''];
        }
        return ['code' => $code, 'body' => $body];
    }

    /**
     * The User-Agent the image libraries see: the client name, and the contact the admin entered for them.
     *
     * Wikimedia asks for a contact and gives an anonymous client a low request limit. The contact is site data,
     * so it is only sent when the admin has entered one.
     *
     * @return string
     */
    public static function user_agent(): string {
        $contact = trim((string)get_config('bookingextension_agent', 'imagesearch_contact'));
        // Header value: one line, printable characters only.
        $contact = trim((string)preg_replace('/[^\x20-\x7E]/', '', $contact));
        return 'MoodleWizardImages/1.0' . ($contact !== '' ? ' (' . \core_text::substr($contact, 0, 120) . ')' : '');
    }
}
