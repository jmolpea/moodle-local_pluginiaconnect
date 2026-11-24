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
 * Hourly budget of records about other people that one connection may receive.
 *
 * Each call may be legitimate on its own; the budget slows down bulk extraction
 * made of many such calls. It counts records, not calls. When the counter
 * cannot be read or written the budget counts as used up: it fails closed.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exposure_budget {
    /** @var int Length of the window in seconds. */
    public const WINDOW = HOURSECS;
    /** @var int Seconds to wait for the counter lock. */
    private const LOCK_TIMEOUT = 2;
    /** @var int Seconds a call waits for another call of the same connection that is reading records. */
    public const CALL_LOCK_TIMEOUT = 10;

    /**
     * Takes the lock that puts in a row the calls of one connection that return records about other people.
     *
     * The budget is checked before a tool runs and charged after it, because only the result says how many
     * records it holds. Without this lock, calls sent at the same time would all pass the check before any
     * of them was charged. The caller holds the lock from the check to the charge and releases it.
     *
     * @param int $grantid Grant id.
     * @return \core\lock\lock|null The lock; null when it could not be taken, and the call must then be refused.
     */
    public static function lock(int $grantid): ?\core\lock\lock {
        try {
            $lock = rate_limiter::lock_factory()->get_lock(sha1('exposurecall|' . $grantid), self::CALL_LOCK_TIMEOUT);
            return $lock ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the connection has already received its budget in the current hour.
     *
     * @param int $grantid Grant id.
     * @return bool
     */
    public static function exhausted(int $grantid): bool {
        try {
            $count = (int) \cache::make(config::COMPONENT, 'ratelimit')->get(self::key($grantid));
            return $count >= config::int('exposurebudgethour');
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Records returned so far in the current hour.
     *
     * @param int $grantid Grant id.
     * @return int
     */
    public static function used(int $grantid): int {
        try {
            return (int) \cache::make(config::COMPONENT, 'ratelimit')->get(self::key($grantid));
        } catch (\Throwable $e) {
            return config::int('exposurebudgethour');
        }
    }

    /**
     * Adds the records of one result to the counter.
     *
     * @param int $grantid Grant id.
     * @param int $records Records about other people in the result.
     * @return bool True when this result took the connection up to or over its budget for the first time.
     * @throws \moodle_exception When the counter cannot be updated; the caller must then return no data.
     */
    public static function add(int $grantid, int $records): bool {
        if ($records <= 0) {
            return false;
        }
        $key = self::key($grantid);
        $lock = rate_limiter::lock_factory()->get_lock($key, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('error');
        }
        try {
            $cache = \cache::make(config::COMPONENT, 'ratelimit');
            $before = (int) $cache->get($key);
            if (!$cache->set($key, $before + $records)) {
                throw new \moodle_exception('error');
            }
            $budget = config::int('exposurebudgethour');
            return $before < $budget && $before + $records >= $budget;
        } finally {
            $lock->release();
        }
    }

    /**
     * Seconds until the current window ends.
     *
     * @return int
     */
    public static function retry_after(): int {
        return max(1, self::WINDOW - (time() % self::WINDOW));
    }

    /**
     * Cache key of the counter of a connection in the current window.
     *
     * @param int $grantid Grant id.
     * @return string
     */
    private static function key(int $grantid): string {
        return sha1('exposure|' . $grantid . '|' . intdiv(time(), self::WINDOW));
    }
}
