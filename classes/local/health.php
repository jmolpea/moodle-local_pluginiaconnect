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

use local_pluginiaconnect\local\gateway\http_transport;
use local_pluginiaconnect\local\gateway\site_identity;
use local_pluginiaconnect\local\http\selfcheck;

/**
 * Diagnostics of the server set-up the connector depends on.
 *
 * Every check returns a result with a status (ok, info, warning or error), a
 * message and, when something is wrong, what to do about it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class health {
    /** @var string Everything is fine. */
    public const OK = 'ok';
    /** @var string Neutral information about an optional feature. */
    public const INFO = 'info';
    /** @var string Works, with a risk or a limitation. */
    public const WARNING = 'warning';
    /** @var string The connector will not work correctly. */
    public const ERROR = 'error';

    /** @var int Seconds without cron after which the check fails. */
    public const CRON_MAX_AGE = 600;
    /** @var int Earliest plausible server time: the day this version was written. */
    private const CLOCK_MIN = 1795478400;
    /** @var int Seconds allowed to each loop-back request. */
    private const LOOPBACK_TIMEOUT = 5;

    /**
     * Runs every check.
     *
     * @return array[] List of results, see {@see self::result()}.
     */
    public static function run(): array {
        global $CFG;
        $results = [
            self::check_state(),
            self::check_https($CFG->wwwroot, (int) $CFG->debug),
            self::check_sodium(function_exists('sodium_crypto_secretbox')),
        ];
        $probe = self::probe_self();
        $results[] = self::check_authorization_header($probe);
        $results[] = self::check_pathinfo($probe);
        $results[] = self::check_wellknown(self::probe_wellknown());
        $results[] = self::check_cache(self::ratelimit_stores());
        $results[] = self::check_cron((int) get_config('tool_task', 'lastcronstart'), time());
        $results[] = self::check_clock(time());
        $results[] = self::check_proxy(!empty($CFG->proxyhost), config::bool('allowcimd'));
        $results[] = self::check_gateway(site_identity::state(), self::probe_gateway());
        $results[] = self::check_extensions(\local_pluginiaconnect\extension\loader::report());
        return $results;
    }

    /**
     * Tools declared by the whitelisted Pluginia plugins installed on the site (amendment 2, 11.2): which were
     * loaded, and every one that was refused with its reason.
     *
     * @param array $report Result of the extension loader, component => entry.
     * @return array
     */
    public static function check_extensions(array $report): array {
        $loaded = [];
        $refused = [];
        foreach ($report as $component => $entry) {
            if ($entry['status'] === 'loaded') {
                $loaded[] = $component . ' (' . count($entry['tools']) . ')';
            } else if (in_array($entry['status'], ['version_too_old', 'invalid_declaration'], true)) {
                $refused[] = $component . ': ' . $entry['status'];
            }
            foreach ($entry['rejected'] as $name => $reason) {
                $refused[] = "{$component}/{$name}: {$reason}";
            }
        }
        if ($refused) {
            return self::result(
                'extensions',
                self::WARNING,
                'health_extensions_refused',
                'health_extensions_remedy',
                implode('; ', $refused)
            );
        }
        if (!$loaded) {
            return self::result('extensions', self::INFO, 'health_extensions_none');
        }
        return self::result('extensions', self::OK, 'health_extensions_ok', null, implode(', ', $loaded));
    }

    /**
     * Whether the connector is switched on and has a connection mode.
     *
     * @return array
     */
    public static function check_state(): array {
        if (!config::bool('enabled')) {
            return self::result('state', self::WARNING, 'health_state_disabled', 'health_state_disabled_remedy');
        }
        if (!config::bool('directmode') && !config::bool('gatewayenabled')) {
            return self::result('state', self::WARNING, 'health_state_nomode', 'health_state_nomode_remedy');
        }
        return self::result('state', self::OK, 'health_state_ok');
    }

    /**
     * The site must be served over HTTPS.
     *
     * @param string $wwwroot Site address.
     * @param int $debug Debug level of the site.
     * @return array
     */
    public static function check_https(string $wwwroot, int $debug): array {
        if (str_starts_with($wwwroot, 'https://')) {
            return self::result('https', self::OK, 'health_https_ok');
        }
        $host = (string) parse_url($wwwroot, PHP_URL_HOST);
        if (in_array($host, ['localhost', '127.0.0.1'], true) && $debug >= DEBUG_DEVELOPER) {
            return self::result('https', self::WARNING, 'health_https_localdev', 'health_https_remedy');
        }
        return self::result('https', self::ERROR, 'health_https_error', 'health_https_remedy');
    }

    /**
     * The sodium extension protects the short retry window of refresh tokens.
     *
     * @param bool $available Whether the extension is loaded.
     * @return array
     */
    public static function check_sodium(bool $available): array {
        return $available
            ? self::result('sodium', self::OK, 'health_sodium_ok')
            : self::result('sodium', self::ERROR, 'health_sodium_error', 'health_sodium_remedy');
    }

    /**
     * Client metadata documents are downloaded without the site proxy, so that the address the
     * plugin has vetted is the one it connects to. Behind a mandatory proxy they cannot be used.
     *
     * @param bool $proxyset Whether the site has an outgoing proxy configured.
     * @param bool $cimdallowed Whether metadata document clients are accepted.
     * @return array
     */
    public static function check_proxy(bool $proxyset, bool $cimdallowed): array {
        if ($proxyset && $cimdallowed) {
            return self::result('proxy', self::WARNING, 'health_proxy_warning', 'health_proxy_remedy');
        }
        return self::result('proxy', self::OK, 'health_proxy_ok');
    }

    /**
     * Whether the Authorization header reaches PHP.
     *
     * @param array|null $probe Answer of the loop-back probe, or null when it could not be made.
     * @return array
     */
    public static function check_authorization_header(?array $probe): array {
        if ($probe === null) {
            return self::result('authheader', self::WARNING, 'health_loopback_failed', 'health_loopback_remedy');
        }
        return !empty($probe['authorization'])
            ? self::result('authheader', self::OK, 'health_authheader_ok')
            : self::result('authheader', self::ERROR, 'health_authheader_error', 'health_authheader_remedy');
    }

    /**
     * Whether path arguments after a script name reach PHP.
     *
     * @param array|null $probe Answer of the loop-back probe, or null when it could not be made.
     * @return array
     */
    public static function check_pathinfo(?array $probe): array {
        if ($probe === null) {
            return self::result('pathinfo', self::WARNING, 'health_loopback_failed', 'health_loopback_remedy');
        }
        return !empty($probe['pathinfo'])
            ? self::result('pathinfo', self::OK, 'health_pathinfo_ok')
            : self::result('pathinfo', self::ERROR, 'health_pathinfo_error', 'health_pathinfo_remedy');
    }

    /**
     * Optional web server rule that serves the OAuth metadata from the domain root.
     *
     * @param bool|null $active True when the rule answers with this site's metadata, false when it does
     *                          not, null when the request could not be made.
     * @return array
     */
    public static function check_wellknown(?bool $active): array {
        if ($active === true) {
            return self::result('wellknown', self::OK, 'health_wellknown_ok');
        }
        if ($active === null) {
            return self::result('wellknown', self::INFO, 'health_wellknown_unknown', 'health_wellknown_remedy');
        }
        return self::result('wellknown', self::INFO, 'health_wellknown_absent', 'health_wellknown_remedy');
    }

    /**
     * Where the rate limit counters are kept. With several web nodes the store must be shared.
     *
     * @param string[] $stores Class names of the cache stores behind the counters.
     * @return array
     */
    public static function check_cache(array $stores): array {
        $names = implode(', ', $stores);
        if (!$stores) {
            return self::result('cache', self::WARNING, 'health_cache_unknown', 'health_cache_remedy');
        }
        foreach ($stores as $store) {
            if (in_array($store, ['cachestore_file', 'cachestore_static', 'cachestore_session', 'cachestore_dummy'], true)) {
                return self::result('cache', self::WARNING, 'health_cache_local', 'health_cache_remedy', $names);
            }
        }
        return self::result('cache', self::OK, 'health_cache_ok', null, $names);
    }

    /**
     * Cron removes expired codes and tokens; it must be running.
     *
     * @param int $lastcron Time cron last started; 0 when it never ran.
     * @param int $now Current time.
     * @return array
     */
    public static function check_cron(int $lastcron, int $now): array {
        if ($lastcron <= 0) {
            return self::result('cron', self::ERROR, 'health_cron_never', 'health_cron_remedy');
        }
        if ($now - $lastcron > self::CRON_MAX_AGE) {
            return self::result('cron', self::ERROR, 'health_cron_late', 'health_cron_remedy', format_time($now - $lastcron));
        }
        return self::result('cron', self::OK, 'health_cron_ok');
    }

    /**
     * Token lifetimes depend on the server clock.
     *
     * @param int $now Current server time.
     * @return array
     */
    public static function check_clock(int $now): array {
        $text = gmdate('Y-m-d H:i:s', $now) . ' UTC';
        if ($now < self::CLOCK_MIN) {
            return self::result('clock', self::ERROR, 'health_clock_error', 'health_clock_remedy', $text);
        }
        return self::result('clock', self::OK, 'health_clock_ok', null, $text);
    }

    /**
     * Calls the loop-back probe of this site.
     *
     * The request goes to this site's own address only, which is why the outbound
     * request filter of the site is not applied: it often blocks the site itself.
     *
     * @return array|null Decoded answer, or null when the request failed.
     */
    private static function probe_self(): ?array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $value = selfcheck::arm();
        try {
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader([
                selfcheck::HEADER . ': ' . $value,
                'Authorization: Bearer ' . selfcheck::BEARER,
            ]);
            $body = $curl->get(urls::base() . '/selfcheck.php' . selfcheck::PATH, null, [
                'CURLOPT_FOLLOWLOCATION' => 0,
                'CURLOPT_TIMEOUT' => self::LOOPBACK_TIMEOUT,
                'CURLOPT_CONNECTTIMEOUT' => self::LOOPBACK_TIMEOUT,
            ]);
            $info = $curl->get_info();
            if ($curl->get_errno() || (int) ($info['http_code'] ?? 0) !== 200) {
                return null;
            }
            $decoded = json_decode((string) $body, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            selfcheck::disarm();
        }
    }

    /**
     * Asks the domain root for the OAuth metadata of this site's issuer (RFC 8414 form).
     *
     * @return bool|null True when it returns this issuer, false when it does not, null on failure.
     */
    private static function probe_wellknown(): ?bool {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $issuer = urls::issuer();
        $parts = parse_url($issuer);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        try {
            $curl = new \curl(['ignoresecurity' => true]);
            $body = $curl->get($origin . '/.well-known/oauth-authorization-server' . ($parts['path'] ?? ''), null, [
                'CURLOPT_FOLLOWLOCATION' => 0,
                'CURLOPT_TIMEOUT' => self::LOOPBACK_TIMEOUT,
                'CURLOPT_CONNECTTIMEOUT' => self::LOOPBACK_TIMEOUT,
            ]);
            if ($curl->get_errno()) {
                return null;
            }
            $decoded = json_decode((string) $body, true);
            return is_array($decoded) && isset($decoded['issuer']) && $decoded['issuer'] === $issuer;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Class names of the cache stores that back the rate limit counters.
     *
     * @return string[]
     */
    private static function ratelimit_stores(): array {
        try {
            \cache::make(config::COMPONENT, 'ratelimit');
            $factory = \cache_factory::instance();
            $definition = $factory->create_definition(config::COMPONENT, 'ratelimit');
            $names = [];
            foreach ($factory->get_store_instances_in_use($definition) as $store) {
                $parts = explode('\\', get_class($store));
                $names[] = end($parts);
            }
            return array_values(array_unique($names));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Connection with the Pluginia Connect gateway.
     *
     * @param string $state Registration state of the site.
     * @param bool|null $reachable Whether the gateway answered its health check; null when it was not asked.
     * @return array
     */
    public static function check_gateway(string $state, ?bool $reachable): array {
        if ($state === site_identity::STATE_UNREGISTER_PENDING) {
            return self::result('gateway', self::WARNING, 'health_gateway_pending', 'health_gateway_pending_remedy');
        }
        if ($state !== site_identity::STATE_REGISTERED) {
            return self::result('gateway', self::INFO, 'health_gateway_notregistered');
        }
        if (!config::bool('gatewayenabled')) {
            return self::result('gateway', self::WARNING, 'health_gateway_modeoff', 'health_gateway_modeoff_remedy');
        }
        if ($reachable !== true) {
            return self::result('gateway', self::ERROR, 'health_gateway_unreachable', 'health_gateway_unreachable_remedy');
        }
        return self::result('gateway', self::OK, 'health_gateway_ok');
    }

    /**
     * Asks the gateway for its health check. Only done when the site is registered.
     *
     * @return bool|null Null when the site is not registered.
     */
    private static function probe_gateway(): ?bool {
        if (
            site_identity::state()
            !== site_identity::STATE_REGISTERED
        ) {
            return null;
        }
        [$status] = (new http_transport())->get('/healthz');
        return $status === 200;
    }

    /**
     * Builds one result.
     *
     * @param string $id Check identifier.
     * @param string $status One of the status constants.
     * @param string $message String key of the message.
     * @param string|null $remedy String key of what to do, when there is something to do.
     * @param mixed $a Placeholder value for the message.
     * @return array With keys id, status, name, message and remedy.
     */
    private static function result(string $id, string $status, string $message, ?string $remedy = null, $a = null): array {
        return [
            'id' => $id,
            'status' => $status,
            'name' => get_string('health_check_' . $id, config::COMPONENT),
            'message' => get_string($message, config::COMPONENT, $a),
            'remedy' => $remedy === null ? '' : get_string($remedy, config::COMPONENT),
        ];
    }
}
