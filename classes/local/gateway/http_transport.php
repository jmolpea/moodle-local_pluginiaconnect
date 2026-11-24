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

namespace local_pluginiaconnect\local\gateway;

use local_pluginiaconnect\local\config;

/**
 * HTTPS transport to the gateway. The only place of the plugin that calls it.
 *
 * The address is a constant. It can only be replaced from config.php, through
 * $CFG->forced_plugin_settings, for development: a value saved in the database
 * is ignored, so nobody can point a site at another gateway from the web interface.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class http_transport implements transport {
    /** @var string Address of the gateway. */
    public const BASE_URL = 'https://connect.pluginia.es';
    /** @var int Seconds allowed to connect. */
    private const CONNECT_TIMEOUT = 5;
    /** @var int Seconds allowed to a whole request. The gateway calls this site back while registering. */
    private const TIMEOUT = 30;
    /** @var int Largest answer accepted, in bytes. */
    private const MAX_BYTES = 65536;

    /**
     * Address of the gateway, without trailing slash.
     *
     * @return string
     */
    public static function base_url(): string {
        global $CFG;
        $forced = $CFG->forced_plugin_settings[config::COMPONENT]['gatewayurl'] ?? null;
        if (is_string($forced) && preg_match('~^https?://[A-Za-z0-9.-]+(:\d{1,5})?$~', $forced)) {
            return $forced;
        }
        return self::BASE_URL;
    }

    /**
     * Sends a JSON document with POST.
     *
     * @param string $path Path on the gateway, starting with a slash.
     * @param array $body Document to send.
     * @return array [HTTP status, decoded JSON body or null].
     */
    public function post_json(string $path, array $body): array {
        return $this->request($path, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Requests a path with GET.
     *
     * @param string $path Path on the gateway, starting with a slash.
     * @return array [HTTP status, decoded JSON body or null].
     */
    public function get(string $path): array {
        return $this->request($path, null);
    }

    /**
     * Performs one request with the core cURL class, with its security filter on.
     *
     * @param string $path Path on the gateway.
     * @param string|null $json Body for a POST; null for a GET.
     * @return array [HTTP status, decoded JSON body or null].
     */
    private function request(string $path, ?string $json): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if (!preg_match('~^(/[A-Za-z0-9_-][A-Za-z0-9_.-]*)+$~', $path)) {
            return [0, null];
        }
        try {
            $curl = new \curl();
            $curl->setHeader(['Accept: application/json', 'Content-Type: application/json']);
            $options = [
                'CURLOPT_FOLLOWLOCATION' => 0,
                'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
                'CURLOPT_TIMEOUT' => self::TIMEOUT,
                'CURLOPT_MAXFILESIZE' => self::MAX_BYTES,
            ];
            $url = self::base_url() . $path;
            $raw = $json === null ? $curl->get($url, null, $options) : $curl->post($url, $json, $options);
            $info = $curl->get_info();
            if ($curl->get_errno() || !is_string($raw) || strlen($raw) > self::MAX_BYTES) {
                return [0, null];
            }
            $decoded = json_decode($raw, true);
            return [(int) ($info['http_code'] ?? 0), is_array($decoded) ? $decoded : null];
        } catch (\Throwable $e) {
            return [0, null];
        }
    }
}
