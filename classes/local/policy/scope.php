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

namespace local_pluginiaconnect\local\policy;

use local_pluginiaconnect\local\config;

/**
 * OAuth scopes of the connector.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope {
    /** @var string Scope that allows issuing a refresh token. */
    public const OFFLINE = 'offline_access';

    /** @var array Scope => [minimum profile, is write]. Order is the order of the metadata document. */
    private const DEFINITIONS = [
        'profile' => [profile::STUDENT, false],
        'courses.read' => [profile::STUDENT, false],
        'grades.read' => [profile::STUDENT, false],
        'participants.read' => [profile::TEACHER, false],
        'content.write' => [profile::TEACHER, true],
        'grades.write' => [profile::TEACHER, true],
        'messages.write' => [profile::TEACHER, true],
        'manage.read' => [profile::MANAGER, false],
        'enrol.write' => [profile::MANAGER, true],
        'manage.write' => [profile::MANAGER, true],
        'users.read' => [profile::MANAGER, false],
        'users.write' => [profile::ADMIN, true],
        'site.read' => [profile::ADMIN, false],
        self::OFFLINE => [profile::STUDENT, false],
    ];

    /** @var string[] Minimal read set advertised by the protected resource metadata and the 401 challenge. */
    public const BASELINE = ['profile', 'courses.read', 'grades.read', self::OFFLINE];

    /**
     * All scope names.
     *
     * @return string[]
     */
    public static function all(): array {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Whether a value is a known scope.
     *
     * @param string $scope Value to check.
     * @return bool
     */
    public static function is_valid(string $scope): bool {
        return isset(self::DEFINITIONS[$scope]);
    }

    /**
     * Whether a scope allows changes.
     *
     * @param string $scope Scope name.
     * @return bool
     */
    public static function is_write(string $scope): bool {
        return self::DEFINITIONS[$scope][1] ?? false;
    }

    /**
     * Minimum profile of a scope.
     *
     * @param string $scope Scope name.
     * @return string|null
     */
    public static function minimum_profile(string $scope): ?string {
        return self::DEFINITIONS[$scope][0] ?? null;
    }

    /**
     * Every scope a profile may hold.
     *
     * @param string $profile Profile name.
     * @return string[]
     */
    public static function max_for_profile(string $profile): array {
        $scopes = [];
        foreach (self::DEFINITIONS as $name => $definition) {
            if (profile::at_least($profile, $definition[0])) {
                $scopes[] = $name;
            }
        }
        return $scopes;
    }

    /**
     * Parses a space separated scope string, dropping unknown values and duplicates.
     *
     * @param string $value Scope string.
     * @return string[]
     */
    public static function parse(string $value): array {
        $scopes = [];
        foreach (preg_split('/\s+/', trim($value)) as $name) {
            if ($name !== '' && self::is_valid($name) && !in_array($name, $scopes, true)) {
                $scopes[] = $name;
            }
        }
        return $scopes;
    }

    /**
     * Scopes to grant: requested, cut down by profile, access level and site state.
     * Every layer can only remove scopes.
     *
     * @param string[]|null $requested Scopes asked by the client; null when the request had none.
     * @param string $profile Profile chosen by the user.
     * @param bool $write Whether the user chose "read and changes".
     * @param int $userid User the grant is for.
     * @param string[]|null $activescopes Scopes with at least one enabled tool for the profile; null to skip this cut.
     * @return string[]
     */
    public static function granted(
        ?array $requested,
        string $profile,
        bool $write,
        int $userid,
        ?array $activescopes = null
    ): array {
        $max = self::max_for_profile($profile);
        $candidates = $requested === null ? $max : array_values(array_intersect($max, $requested));
        $canwrite = $write && !config::bool('readonlymode') && eligibility::can_write_somewhere($userid);
        $granted = [];
        foreach ($candidates as $name) {
            if (self::is_write($name) && !$canwrite) {
                continue;
            }
            if (
                $activescopes !== null && $name !== self::OFFLINE && $name !== 'profile'
                && !in_array($name, $activescopes, true)
            ) {
                continue;
            }
            $granted[] = $name;
        }
        return $granted;
    }
}
