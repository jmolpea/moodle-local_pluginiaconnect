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

namespace local_pluginiaconnect\local\guardrail;

use local_pluginiaconnect\local\config;

/**
 * Fixed-window counters kept in the application cache.
 *
 * Two modes. The strict mode updates the counter under a lock, so requests
 * sent at the same time are counted one by one; every limit of the plugin
 * uses it, the ones of read tools and the one per address included. The
 * relaxed mode reads and writes the counter without locking, so concurrent
 * requests may be counted as one; nothing that enforces a limit may use it.
 *
 * Both modes fail closed: when the cache (or, in strict mode, the lock) is
 * unavailable the hit is refused. A limit that cannot be counted cannot be
 * enforced, and an unlimited connector is worse than one that is briefly
 * unavailable. This is deliberately stricter than the first design, which let
 * reads through at a reduced rate that nothing could measure.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limiter {
    /** @var int Seconds to wait for the counter lock in strict mode. */
    private const LOCK_TIMEOUT = 2;

    /** @var bool Test hook: behave as if the cache were unavailable. */
    private static bool $simulatefailure = false;

    /** @var \core\lock\lock_factory|null Lock factory of the counters, created once per request. */
    private static ?\core\lock\lock_factory $factory = null;

    /**
     * Lock factory of the counters. One call counts several times (per address, per minute, per day),
     * so the factory is created once and used again.
     *
     * @return \core\lock\lock_factory
     */
    public static function lock_factory(): \core\lock\lock_factory {
        if (self::$factory === null) {
            self::$factory = \core\lock\lock_config::get_lock_factory('local_pluginiaconnect_ratelimit');
        }
        return self::$factory;
    }

    /**
     * Makes the cache look unavailable. For unit tests only.
     *
     * @param bool $fail Whether cache access must fail.
     * @return void
     */
    public static function simulate_cache_failure(bool $fail): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('simulate_cache_failure() is only available in unit tests.');
        }
        self::$simulatefailure = $fail;
    }

    /**
     * Counts one hit and tells whether it is within the limit.
     *
     * @param string $bucket Counter family, e.g. 'token' or 'read_minute'.
     * @param string $subject Who is being limited: a grant id, a client id or an IP address.
     * @param int $limit Hits allowed per window.
     * @param int $window Window length in seconds.
     * @param bool $strict Count under a lock.
     * @return bool True when the hit is allowed.
     */
    public static function hit(string $bucket, string $subject, int $limit, int $window, bool $strict = false): bool {
        $key = self::key($bucket, $subject, $window);
        $lock = null;
        try {
            if ($strict) {
                $lock = self::lock_factory()->get_lock($key, self::LOCK_TIMEOUT);
                if (!$lock) {
                    return false;
                }
            }
            $cache = self::cache();
            $count = (int) $cache->get($key) + 1;
            if (!$cache->set($key, $count)) {
                throw new \coding_exception('The rate limit counter could not be stored.');
            }
            return $count <= $limit;
        } catch (\Throwable $e) {
            // Without a counter there is no limit to apply, so the hit is refused.
            debugging('Pluginia Connect: the rate limit counter is unavailable; the request was refused.', DEBUG_NORMAL);
            return false;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Seconds until the current window of a counter ends.
     *
     * @param int $window Window length in seconds.
     * @return int
     */
    public static function retry_after(int $window): int {
        return max(1, $window - (time() % $window));
    }

    /**
     * Current count of a counter, without adding a hit.
     *
     * @param string $bucket Counter family.
     * @param string $subject Who is being limited.
     * @param int $window Window length in seconds.
     * @return int The count; PHP_INT_MAX when the counter cannot be read, so that callers refuse.
     */
    public static function current(string $bucket, string $subject, int $window): int {
        try {
            return (int) self::cache()->get(self::key($bucket, $subject, $window));
        } catch (\Throwable $e) {
            return PHP_INT_MAX;
        }
    }

    /**
     * The counter cache.
     *
     * @return \cache
     */
    private static function cache(): \cache {
        if (self::$simulatefailure) {
            throw new \coding_exception('Simulated cache failure.');
        }
        return \cache::make(config::COMPONENT, 'ratelimit');
    }

    /**
     * Cache key of a counter in the current window.
     *
     * @param string $bucket Counter family.
     * @param string $subject Who is being limited.
     * @param int $window Window length in seconds.
     * @return string
     */
    private static function key(string $bucket, string $subject, int $window): string {
        return sha1($bucket . '|' . $subject . '|' . $window . '|' . intdiv(time(), $window));
    }
}
