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

namespace local_pluginiaconnect\local\oauth;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\urls;

/**
 * Which redirect URIs are accepted.
 *
 * A URI must pass two independent checks: the site allows it and the client
 * registered it. There are no wildcards. Comparison is exact, character by
 * character, except for the port of loopback URIs.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class redirect_policy {
    /** @var int Maximum accepted length of a redirect URI. */
    public const MAX_LENGTH = 2000;

    /**
     * Parts of a loopback redirect URI, or null when the URI is not one.
     *
     * Only http://localhost and http://127.0.0.1 count, with an optional port.
     *
     * @param string $uri Redirect URI.
     * @return array|null [host, path, query].
     */
    private static function loopback_parts(string $uri): ?array {
        if (!preg_match('~^http://(localhost|127\.0\.0\.1)(?::\d{1,5})?(/[^#?\s]*)?(\?[^#\s]*)?$~', $uri, $matches)) {
            return null;
        }
        return [$matches[1], $matches[2] ?? '', $matches[3] ?? ''];
    }

    /**
     * Whether a URI is a loopback redirect.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    public static function is_loopback(string $uri): bool {
        return self::loopback_parts($uri) !== null;
    }

    /**
     * Basic shape: absolute https URI (or loopback http), no fragment, no credentials, sane length.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    public static function is_well_formed(string $uri): bool {
        if ($uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\s#\x00-\x1f\x7f]/', $uri)) {
            return false;
        }
        if (self::is_loopback($uri)) {
            return true;
        }
        $parts = parse_url($uri);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * Whether two loopback URIs are the same once the port is ignored.
     *
     * @param string $a First URI.
     * @param string $b Second URI.
     * @return bool
     */
    private static function same_loopback(string $a, string $b): bool {
        $partsa = self::loopback_parts($a);
        $partsb = self::loopback_parts($b);
        return $partsa !== null && $partsb !== null && $partsa === $partsb;
    }

    /**
     * Whether the site accepts a URI in client registrations (DCR and metadata documents).
     *
     * The gateway redirect is bound to the gateway client and can never be registered by anyone else.
     * The redirect shared by all Microsoft 365 Copilot plugins is refused whatever the site list says.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    public static function is_registrable(string $uri): bool {
        if (!self::is_well_formed($uri) || $uri === urls::GATEWAY_REDIRECT || self::is_shared_redirect($uri)) {
            return false;
        }
        if (self::is_loopback($uri)) {
            return config::bool('allowloopback');
        }
        return in_array($uri, config::lines('allowedredirects'), true);
    }

    /**
     * Whether a URI is the redirect address shared by all Microsoft 365 Copilot plugins, in any spelling.
     *
     * @param string $uri Redirect URI.
     * @return bool
     */
    public static function is_shared_redirect(string $uri): bool {
        $parts = parse_url($uri);
        $shared = parse_url(urls::TEAMS_REDIRECT);
        return is_array($parts) && strtolower($parts['host'] ?? '') === $shared['host']
            && strtolower(rtrim(rawurldecode($parts['path'] ?? ''), '/')) === strtolower($shared['path']);
    }

    /**
     * Whether the site accepts a URI for a given client.
     *
     * @param string $uri Redirect URI.
     * @param client $client Client that presents it.
     * @return bool
     */
    public static function site_allows(string $uri, client $client): bool {
        if ($client->type === client::TYPE_GATEWAY) {
            return $uri === urls::GATEWAY_REDIRECT && config::bool('gatewayenabled');
        }
        return self::is_registrable($uri);
    }

    /**
     * Whether a client registered a URI.
     *
     * @param string $uri Redirect URI.
     * @param client $client Client that presents it.
     * @return bool
     */
    public static function client_has(string $uri, client $client): bool {
        foreach ($client->redirecturis as $registered) {
            if ($registered === $uri || self::same_loopback($registered, $uri)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a redirect URI of an authorization request is acceptable.
     *
     * @param string $uri Redirect URI.
     * @param client $client Client that presents it.
     * @return bool
     */
    public static function is_allowed(string $uri, client $client): bool {
        return self::is_well_formed($uri) && self::site_allows($uri, $client) && self::client_has($uri, $client);
    }

    /**
     * Appends parameters to a redirect URI, keeping its own query string.
     *
     * @param string $uri Redirect URI.
     * @param array $params Parameters to add.
     * @return string
     */
    public static function build(string $uri, array $params): string {
        $params = array_filter($params, static fn($value) => $value !== null && $value !== '');
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $uri . (str_contains($uri, '?') ? '&' : '?') . $query;
    }
}
