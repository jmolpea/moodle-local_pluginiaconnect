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

namespace local_pluginiaconnect\local;

/**
 * Canonical URLs of the plugin endpoints.
 *
 * The issuer and the resource are exact strings: they are used identically in
 * every metadata document, in the "iss" response parameter and in audience checks.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class urls {
    /** @var string Redirect URI of the Pluginia Connect gateway. */
    public const GATEWAY_REDIRECT = 'https://connect.pluginia.es/oauth/site/callback';

    /** @var string Client identifier of the Pluginia Connect gateway. */
    public const GATEWAY_CLIENT_ID = 'https://connect.pluginia.es/oauth/client.json';

    /**
     * @var string Redirect address shared by every Microsoft 365 Copilot plugin of every publisher.
     *      It does not identify a client, so it is never accepted in direct mode.
     */
    public const TEAMS_REDIRECT = 'https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect';

    /** @var string Public documentation. */
    public const DOCUMENTATION = 'https://pluginia.es/connect/docs';

    /**
     * @var string Configuration manual for site administrators. This is the only place the address
     *      is written: every link to the manual reads this constant.
     */
    public const MANUAL = 'https://claude.ai/artifact/XSFJRPaBcavfAJyFBo7K2Y';

    /**
     * Base URL of the plugin, without trailing slash.
     *
     * @return string
     */
    public static function base(): string {
        global $CFG;
        return rtrim($CFG->wwwroot, '/') . '/local/pluginiaconnect';
    }

    /**
     * OAuth issuer identifier.
     *
     * @return string
     */
    public static function issuer(): string {
        return self::base() . '/oauth.php';
    }

    /**
     * Canonical URL of the MCP endpoint. It is the OAuth resource (audience).
     *
     * @return string
     */
    public static function resource(): string {
        return self::base() . '/mcp.php';
    }

    /**
     * URL of the protected resource metadata document.
     *
     * @return string
     */
    public static function resource_metadata(): string {
        return self::issuer() . '/resource-metadata';
    }

    /**
     * Authorization endpoint.
     *
     * @return string
     */
    public static function authorize(): string {
        return self::base() . '/oauth/authorize.php';
    }

    /**
     * Token endpoint.
     *
     * @return string
     */
    public static function token(): string {
        return self::base() . '/oauth/token.php';
    }

    /**
     * Dynamic client registration endpoint.
     *
     * @return string
     */
    public static function register(): string {
        return self::base() . '/oauth/register.php';
    }

    /**
     * Revocation endpoint.
     *
     * @return string
     */
    public static function revoke(): string {
        return self::base() . '/oauth/revoke.php';
    }

    /**
     * Canonical form of a resource URL: lower-case scheme and host, no default
     * port, no trailing slash, no fragment. Returns an empty string when the
     * value is not an absolute http(s) URL.
     *
     * @param string $url URL to normalise.
     * @return string
     */
    public static function canonical(string $url): string {
        $parts = parse_url($url);
        if (
            $parts === false || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user'])
                || isset($parts['pass'])
        ) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return '';
        }
        $host = strtolower($parts['host']);
        $port = '';
        if (
            isset($parts['port']) && !(($scheme === 'https' && $parts['port'] == 443)
            || ($scheme === 'http' && $parts['port'] == 80))
        ) {
            $port = ':' . $parts['port'];
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * Whether a resource indicator received from a client designates this site's MCP endpoint.
     *
     * @param string $resource Value of the "resource" parameter.
     * @return bool
     */
    public static function is_own_resource(string $resource): bool {
        $canonical = self::canonical($resource);
        return $canonical !== '' && hash_equals(self::canonical(self::resource()), $canonical);
    }
}
