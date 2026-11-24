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
 * Typed access to the plugin settings, with factory defaults and hard limits.
 *
 * Every limit can be lowered by the administrator but never raised above the
 * maximum fixed here.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config {
    /** @var string Component name. */
    public const COMPONENT = 'local_pluginiaconnect';

    /** @var array Factory defaults. They are also used by settings.php. */
    public const DEFAULTS = [
        'enabled' => 0,
        'readonlymode' => 0,
        'directmode' => 0,
        'gatewayenabled' => 0,
        'sitenotice' => '',
        'privacyurl' => '',
        'allowstudentprofile' => 0,
        'allowteacherprofile' => 1,
        'allowmanagerprofile' => 1,
        'allowadminprofile' => 0,
        'adminprofileusers' => '',
        'excludedcohorts' => '',
        'allowedcategories' => '',
        'excludedcategories' => '',
        'excludedcourses' => '',
        'includehiddencourses' => 0,
        'profilefields' => '',
        'showemails' => 'teacher',
        'allowedredirects' => "https://claude.ai/api/mcp/auth_callback\nhttps://chatgpt.com/connector_platform_oauth_redirect",
        'allowloopback' => 0,
        'allowdcr' => 1,
        'allowcimd' => 1,
        'cimdtrusteddomains' => "claude.ai\nchatgpt.com\nconnect.pluginia.es",
        'advertisedscopes' => 'active',
        'accesstokenttl' => 3600,
        'refreshidlettl' => 2592000,
        'grantabsolutettl' => 15552000,
        'revokeonpasswordchange' => 1,
        'ratereadminute' => 60,
        'ratereadday' => 2000,
        'ratewriteminute' => 10,
        'ratewriteday' => 200,
        'exposurebudgethour' => 1000,
        'maxpagesize' => 50,
        'maxresponsechars' => 48000,
        'maxbulkusers' => 50,
        'maxrecipients' => 50,
        'auditretentiondays' => 180,
        'logip' => 1,
        'unattendedactors' => 'copilot-cowork',
        'actorpolicy_copilotcowork' => 'approval',
    ];

    /** @var array Minimum and maximum of each numeric setting: name => [min, max]. */
    public const LIMITS = [
        'accesstokenttl' => [300, 3600],
        'refreshidlettl' => [3600, 7776000],
        'grantabsolutettl' => [86400, 31536000],
        'ratereadminute' => [1, 60],
        'ratereadday' => [1, 2000],
        'ratewriteminute' => [1, 10],
        'ratewriteday' => [1, 200],
        'exposurebudgethour' => [1, 1000],
        'maxpagesize' => [1, 50],
        'maxresponsechars' => [2000, 60000],
        'maxbulkusers' => [1, 50],
        'maxrecipients' => [1, 50],
        'auditretentiondays' => [30, 730],
    ];

    /**
     * Raw setting value, or the factory default when it was never saved.
     *
     * @param string $name Setting name.
     * @return mixed
     */
    public static function get(string $name) {
        $value = get_config(self::COMPONENT, $name);
        if ($value === false || $value === null) {
            return self::DEFAULTS[$name] ?? null;
        }
        return $value;
    }

    /**
     * Boolean setting.
     *
     * @param string $name Setting name.
     * @return bool
     */
    public static function bool(string $name): bool {
        return !empty(self::get($name));
    }

    /**
     * Numeric setting clamped to its hard limits.
     *
     * @param string $name Setting name.
     * @return int
     */
    public static function int(string $name): int {
        $value = (int) self::get($name);
        if (isset(self::LIMITS[$name])) {
            [$min, $max] = self::LIMITS[$name];
            $value = max($min, min($max, $value));
        }
        return $value;
    }

    /**
     * Text setting.
     *
     * @param string $name Setting name.
     * @return string
     */
    public static function string(string $name): string {
        return trim((string) self::get($name));
    }

    /**
     * Setting that holds a list, one entry per line (commas are accepted too).
     *
     * @param string $name Setting name.
     * @return string[] Trimmed, non-empty, unique entries.
     */
    public static function lines(string $name): array {
        $parts = preg_split('/[\r\n,]+/', (string) self::get($name));
        $parts = array_filter(array_map('trim', $parts), static fn($part) => $part !== '');
        return array_values(array_unique($parts));
    }

    /**
     * Setting that holds a list of numeric identifiers.
     *
     * @param string $name Setting name.
     * @return int[]
     */
    public static function ids(string $name): array {
        $ids = [];
        foreach (self::lines($name) as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Site secret used to derive pseudonymous subject identifiers. Created on first use.
     *
     * Besides that, only the key of the audit fingerprints is derived from it (see
     * audit/logger::audit_hash()): rotating it changes the subject identifiers and makes the
     * fingerprints of later audit rows differ from earlier ones for the same arguments.
     *
     * @return string 64 hexadecimal characters.
     */
    public static function subject_secret(): string {
        $secret = get_config(self::COMPONENT, 'subjectsecret');
        if (!is_string($secret) || strlen($secret) !== 64) {
            $secret = bin2hex(random_bytes(32));
            set_config('subjectsecret', $secret, self::COMPONENT);
        }
        return $secret;
    }
}
