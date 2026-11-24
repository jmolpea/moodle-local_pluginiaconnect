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

namespace local_pluginiaconnect\local\http;

use local_pluginiaconnect\local\config;

/**
 * Origin header validation, the defence against DNS rebinding.
 *
 * Server-side clients send no Origin header and pass. A browser page does send
 * it: only the site itself is accepted and, when the administrator allows
 * loopback clients, pages served from the local machine (development tools).
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class origin_guard {
    /**
     * Origin of this site: scheme, host and non-default port.
     *
     * @return string
     */
    public static function own_origin(): string {
        global $CFG;
        $parts = parse_url($CFG->wwwroot);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $origin = $scheme . '://' . strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;
        $defaultport = ($scheme === 'https' && $port == 443) || ($scheme === 'http' && $port == 80);
        if ($port !== null && !$defaultport) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * Whether an origin is a page on the local machine and the site accepts those.
     *
     * @param string $origin Origin header value.
     * @return bool
     */
    private static function is_accepted_loopback(string $origin): bool {
        return config::bool('allowloopback')
            && preg_match('~^http://(localhost|127\.0\.0\.1)(:\d{1,5})?$~', $origin) === 1;
    }

    /**
     * Checks the Origin header of a request.
     *
     * @param request $request Request.
     * @return response|null Null when the request may continue; otherwise the 403 response to send.
     */
    public static function check(request $request): ?response {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return null;
        }
        if (hash_equals(self::own_origin(), strtolower($origin)) || self::is_accepted_loopback($origin)) {
            return null;
        }
        return response::json(403, [
            'error' => 'forbidden',
            'error_description' => 'Requests from this web origin are not accepted.',
        ]);
    }

    /**
     * CORS headers for an accepted loopback origin. Empty for every other request.
     *
     * @param request $request Request.
     * @return array Header name => value.
     */
    public static function cors_headers(request $request): array {
        $origin = (string) $request->header('origin');
        if ($origin === '' || !self::is_accepted_loopback($origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name',
            'Access-Control-Expose-Headers' => 'WWW-Authenticate',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
