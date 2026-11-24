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
use local_pluginiaconnect\local\guardrail\rate_limiter;

/**
 * Downloads and validates client ID metadata documents.
 *
 * The download is a server-side request to a URL chosen by a third party, so it
 * is locked down here and does not rely on the site's cURL security settings,
 * which an administrator may have relaxed: HTTPS only, port 443, trusted
 * domains, public addresses only (checked on the resolved addresses, to which
 * the connection is then pinned), no redirects, 5 seconds and 64 KB at most.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cimd_fetcher {
    /** @var int Maximum document size in bytes. */
    public const MAX_BYTES = 65536;
    /** @var int Timeout in seconds. */
    public const TIMEOUT = 5;
    /** @var int Maximum number of redirect URIs kept from a document. */
    public const MAX_REDIRECTS = 20;
    /** @var int Seconds a failed download is remembered, so that it is not repeated on every request. */
    public const NEGATIVE_TTL = 300;
    /** @var int Downloads one address may cause per minute. */
    public const IP_LIMIT = 10;

    /** @var callable|null Test hook: fn(string $url, string[] $ips): array [int status, string body]. */
    private static $fetcher = null;
    /** @var callable|null Test hook: fn(string $host): string[] IP addresses. */
    private static $resolver = null;

    /**
     * Replaces the network functions. For unit tests only; pass nulls to restore.
     *
     * @param callable|null $fetcher Receives the URL and the vetted addresses, returns [status, body].
     * @param callable|null $resolver Receives a host name, returns its IP addresses.
     * @return void
     */
    public static function set_test_hooks(?callable $fetcher, ?callable $resolver): void {
        self::$fetcher = $fetcher;
        self::$resolver = $resolver;
    }

    /**
     * Whether a client identifier has the shape of a metadata document URL:
     * https, a host name (not an IP literal), a path, no credentials, no fragment.
     *
     * @param string $clientid Client identifier.
     * @return bool
     */
    public static function is_candidate(string $clientid): bool {
        if (strlen($clientid) > 2000 || preg_match('/[\s#\x00-\x1f\x7f]/', $clientid)) {
            return false;
        }
        $parts = parse_url($clientid);
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            return false;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/' || str_contains($path, '/../') || str_contains($path, '/./')) {
            return false;
        }
        $host = $parts['host'];
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false
            && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host) === 1 && str_contains($host, '.');
    }

    /**
     * Whether an address is publicly routable.
     *
     * @param string $ip IPv4 or IPv6 address.
     * @return bool
     */
    public static function is_public_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            // Ranges the PHP filter does not cover: carrier-grade NAT, IETF protocol assignments,
            // documentation networks, benchmarking and multicast.
            $blocked = [
                ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15],
                ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4],
            ];
            foreach ($blocked as [$network, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($network) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        // Unique local fc00::/7, link local fe80::/10, multicast ff00::/8, IPv4-mapped and
        // IPv4-compatible, NAT64 64:ff9b::/96, 6to4 2002::/16, Teredo 2001::/32, documentation 2001:db8::/32.
        if (($first & 0xfe) === 0xfc || ($first === 0xfe && ($second & 0xc0) === 0x80) || $first === 0xff) {
            return false;
        }
        $hex = bin2hex($packed);
        foreach (['00000000000000000000', '0064ff9b', '2002', '20010000', '20010db8'] as $prefix) {
            if (str_starts_with($hex, $prefix)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a host is accepted by the trusted domain list (exact host or a subdomain).
     *
     * @param string $host Host name.
     * @return bool
     */
    public static function is_trusted_host(string $host): bool {
        $trusted = config::lines('cimdtrusteddomains');
        if (!$trusted) {
            return true;
        }
        $host = strtolower($host);
        foreach ($trusted as $domain) {
            $domain = strtolower(trim($domain, '. '));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the validated document of a client, from cache or from the network.
     *
     * @param string $url Client identifier (document URL).
     * @param string|null $ip Address of the caller that triggers the download, to limit how many it can cause.
     * @return array ['name' => string, 'redirecturis' => string[], 'metadata' => array].
     * @throws oauth_exception When the document cannot be used.
     */
    public static function fetch(string $url, ?string $ip = null): array {
        if (!config::bool('allowcimd') || !self::is_candidate($url)) {
            throw new oauth_exception('invalid_client', 'Client metadata documents are not accepted for this client.', 401);
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        if ((isset($parts['port']) && (int) $parts['port'] !== 443) || !self::is_trusted_host($host)) {
            throw new oauth_exception('invalid_client', 'The client domain is not accepted by this site.', 401);
        }

        $cache = \cache::make(config::COMPONENT, 'cimd');
        $key = sha1($url);
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $failure = new oauth_exception('invalid_client', 'The client metadata document could not be used.', 401);
        $failed = $cache->get('neg' . $key);
        if (is_int($failed) && $failed > time()) {
            throw $failure;
        }
        if ($ip !== null && !rate_limiter::hit('cimd_ip', $ip, self::IP_LIMIT, MINSECS, true)) {
            throw $failure;
        }
        try {
            $document = self::fetch_uncached($url, $host);
        } catch (oauth_exception $e) {
            $cache->set('neg' . $key, time() + self::NEGATIVE_TTL);
            throw $e;
        }
        $cache->set($key, $document);
        return $document;
    }

    /**
     * Resolves, downloads and validates a document. No cache involved.
     *
     * @param string $url Document URL, already checked by {@see self::fetch()}.
     * @param string $host Lower-case host of the URL.
     * @return array ['name' => string, 'redirecturis' => string[], 'metadata' => array].
     * @throws oauth_exception When the document cannot be used.
     */
    private static function fetch_uncached(string $url, string $host): array {
        $ips = self::$resolver ? (self::$resolver)($host) : self::resolve($host);
        if (!$ips) {
            throw new oauth_exception('invalid_client', 'The client metadata host could not be resolved.', 401);
        }
        foreach ($ips as $ip) {
            if (!is_string($ip) || !self::is_public_ip($ip)) {
                throw new oauth_exception('invalid_client', 'The client metadata host is not a public address.', 401);
            }
        }

        [$status, $body] = self::$fetcher ? (self::$fetcher)($url, $ips) : self::download($url, $host, $ips);
        if ((int) $status !== 200 || !is_string($body) || $body === '') {
            throw new oauth_exception('invalid_client', 'The client metadata document could not be downloaded.', 401);
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new oauth_exception('invalid_client', 'The client metadata document is too large.', 401);
        }
        return self::validate($url, $body);
    }

    /**
     * Validates the content of a document.
     *
     * @param string $url URL it was downloaded from.
     * @param string $body Raw body.
     * @return array ['name' => string, 'redirecturis' => string[], 'metadata' => array].
     * @throws oauth_exception When a required field is missing or wrong.
     */
    private static function validate(string $url, string $body): array {
        $data = mb_check_encoding($body, 'UTF-8') ? json_decode($body, true, 16) : null;
        if (
            !is_array($data) || !isset($data['client_id']) || !is_string($data['client_id'])
                || !hash_equals($url, $data['client_id'])
        ) {
            throw new oauth_exception('invalid_client', 'The client metadata document does not match its address.', 401);
        }
        $name = isset($data['client_name']) && is_string($data['client_name'])
            ? trim(clean_param($data['client_name'], PARAM_TEXT)) : '';
        $uris = [];
        foreach ((isset($data['redirect_uris']) && is_array($data['redirect_uris'])) ? $data['redirect_uris'] : [] as $uri) {
            if (is_string($uri) && redirect_policy::is_well_formed($uri) && count($uris) < self::MAX_REDIRECTS) {
                $uris[] = $uri;
            }
        }
        if ($name === '' || !$uris) {
            throw new oauth_exception('invalid_client', 'The client metadata document lacks a name or redirect URIs.', 401);
        }
        return [
            'name' => \core_text::substr($name, 0, 255),
            'redirecturis' => array_values(array_unique($uris)),
            'metadata' => ['client_id' => $url, 'client_name' => \core_text::substr($name, 0, 255)],
        ];
    }

    /**
     * Resolves a host name to its IPv4 and IPv6 addresses.
     *
     * @param string $host Host name.
     * @return string[]
     */
    private static function resolve(string $host): array {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            } else if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            $list = @gethostbynamel($host);
            $ips = is_array($list) ? $list : [];
        }
        return array_values(array_unique($ips));
    }

    /**
     * Downloads the document, connecting only to the addresses already vetted.
     *
     * @param string $url Document URL.
     * @param string $host Host name of the URL.
     * @param string[] $ips Vetted addresses of the host.
     * @return array [int status, string body].
     */
    private static function download(string $url, string $host, array $ips): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $pinned = array_map(static fn($ip) => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips);
        // The site proxy is never used here: a proxy resolves the host name again by itself, which would
        // undo the address pinning below. A site that can only reach the internet through its proxy
        // cannot accept metadata document clients; the diagnostics page says so.
        $curl = new \curl(['ignoresecurity' => false, 'proxy' => false]);
        $max = self::MAX_BYTES;
        $curl->setHeader(['Accept: application/json']);
        $body = $curl->get($url, null, [
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
            'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT,
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_MAXFILESIZE' => self::MAX_BYTES,
            'CURLOPT_PROXY' => '',
            'CURLOPT_NOPROXY' => '*',
            // CURLOPT_MAXFILESIZE is ignored for chunked responses: stop the transfer as soon as it grows too big.
            'CURLOPT_NOPROGRESS' => 0,
            'CURLOPT_XFERINFOFUNCTION' => static function ($handle, $dltotal, $dlnow) use ($max) {
                return ($dlnow > $max || $dltotal > $max) ? 1 : 0;
            },
            'CURLOPT_RESOLVE' => [$host . ':443:' . implode(',', $pinned)],
        ]);
        if ($curl->get_errno()) {
            return [0, ''];
        }
        $info = $curl->get_info();
        return [(int) ($info['http_code'] ?? 0), is_string($body) ? $body : ''];
    }
}
