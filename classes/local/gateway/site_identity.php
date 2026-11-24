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
 * What identifies this site to the gateway, and the proof of control of its address.
 *
 * While registering, the site keeps a random registration value and sends its
 * SHA-256 hash to the gateway. The gateway then calls gateway/verify.php with a
 * challenge and the site answers HMAC-SHA256(key, "pluginiaconnect-verify-v1:" + challenge),
 * where the key is that hash as raw bytes. The answer is only given during the
 * ten minutes after an administrator starts a registration, so the endpoint is
 * not a signing oracle the rest of the time.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_identity {
    /** @var int Seconds the verification endpoint answers after a registration starts. */
    public const WINDOW = 600;
    /** @var string Domain separation prefix of the signed message. */
    public const PREFIX = 'pluginiaconnect-verify-v1:';
    /** @var string Not registered. */
    public const STATE_NONE = 'none';
    /** @var string Registered and active. */
    public const STATE_REGISTERED = 'registered';
    /** @var string Removed locally; the gateway could not be told yet. */
    public const STATE_UNREGISTER_PENDING = 'unregisterpending';

    /**
     * Starts a registration attempt: new registration value, verification window open.
     *
     * @param int|null $now Current time, for tests.
     * @return string SHA-256 of the registration value, 64 hexadecimal characters. This is what the gateway receives.
     */
    public static function open_window(?int $now = null): string {
        $value = bin2hex(random_bytes(32));
        set_config('gateway_regvalue', $value, config::COMPONENT);
        set_config('gateway_regstarted', $now ?? time(), config::COMPONENT);
        return hash('sha256', $value);
    }

    /**
     * Ends the verification window and forgets the registration value.
     *
     * @return void
     */
    public static function close_window(): void {
        unset_config('gateway_regvalue', config::COMPONENT);
        unset_config('gateway_regstarted', config::COMPONENT);
    }

    /**
     * Whether a registration started less than ten minutes ago.
     *
     * @param int|null $now Current time, for tests.
     * @return bool
     */
    public static function window_open(?int $now = null): bool {
        $now = $now ?? time();
        $started = (int) get_config(config::COMPONENT, 'gateway_regstarted');
        $value = get_config(config::COMPONENT, 'gateway_regvalue');
        return is_string($value) && strlen($value) === 64 && $started > 0 && $started <= $now
            && $now - $started < self::WINDOW;
    }

    /**
     * Answer to a verification challenge, or null when no answer may be given.
     *
     * @param string $challenge Challenge sent by the gateway.
     * @param int|null $now Current time, for tests.
     * @return string|null 64 hexadecimal characters.
     */
    public static function answer(string $challenge, ?int $now = null): ?string {
        if (!self::window_open($now) || !preg_match('/^[0-9a-f]{64}$/', $challenge)) {
            return null;
        }
        $key = hex2bin(hash('sha256', (string) get_config(config::COMPONENT, 'gateway_regvalue')));
        return hash_hmac('sha256', self::PREFIX . $challenge, $key);
    }

    /**
     * Registration state of the site.
     *
     * @return string One of the STATE constants.
     */
    public static function state(): string {
        $state = get_config(config::COMPONENT, 'gateway_state');
        return in_array($state, [self::STATE_REGISTERED, self::STATE_UNREGISTER_PENDING], true) ? $state : self::STATE_NONE;
    }

    /**
     * Identifier the gateway gave to this site, or an empty string.
     *
     * @return string
     */
    public static function site_id(): string {
        $id = get_config(config::COMPONENT, 'gateway_siteid');
        return is_string($id) && preg_match('/^[0-9a-f-]{36}$/', $id) ? $id : '';
    }

    /**
     * Stores the outcome of a successful registration.
     *
     * The client secret is needed again to tell the gateway that the site leaves. It is kept
     * encrypted with the site key of Moodle, which lives outside the database.
     *
     * @param string $siteid Identifier given by the gateway.
     * @param string $secret Client secret of this site.
     * @return void
     */
    public static function remember(string $siteid, string $secret): void {
        set_config('gateway_siteid', $siteid, config::COMPONENT);
        set_config('gateway_secretenc', \core\encryption::encrypt($secret), config::COMPONENT);
        set_config('gateway_state', self::STATE_REGISTERED, config::COMPONENT);
        set_config('gateway_timeregistered', time(), config::COMPONENT);
    }

    /**
     * Client secret of this site, or null when it is not available.
     *
     * @return string|null
     */
    public static function secret(): ?string {
        $encrypted = get_config(config::COMPONENT, 'gateway_secretenc');
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }
        try {
            return \core\encryption::decrypt($encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Marks the site as removed locally while the gateway has not confirmed.
     *
     * @return void
     */
    public static function mark_unregister_pending(): void {
        set_config('gateway_state', self::STATE_UNREGISTER_PENDING, config::COMPONENT);
    }

    /**
     * Forgets everything about the registration.
     *
     * @return void
     */
    public static function forget(): void {
        foreach (['gateway_siteid', 'gateway_secretenc', 'gateway_state', 'gateway_timeregistered'] as $name) {
            unset_config($name, config::COMPONENT);
        }
        self::close_window();
    }

    /**
     * Time of the registration, or 0.
     *
     * @return int
     */
    public static function time_registered(): int {
        return (int) get_config(config::COMPONENT, 'gateway_timeregistered');
    }
}
