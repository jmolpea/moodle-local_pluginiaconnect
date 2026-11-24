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

/**
 * Connection profiles. They are cumulative: each one includes the tools of the lower ones.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class profile {
    /** @var string Student profile. */
    public const STUDENT = 'student';
    /** @var string Teacher profile. */
    public const TEACHER = 'teacher';
    /** @var string Manager profile. */
    public const MANAGER = 'manager';
    /** @var string Administrator profile. */
    public const ADMIN = 'admin';

    /** @var array Rank of each profile. */
    private const RANKS = [self::STUDENT => 1, self::TEACHER => 2, self::MANAGER => 3, self::ADMIN => 4];

    /**
     * All profiles, lowest first.
     *
     * @return string[]
     */
    public static function all(): array {
        return array_keys(self::RANKS);
    }

    /**
     * Whether a value is a known profile.
     *
     * @param string $profile Value to check.
     * @return bool
     */
    public static function is_valid(string $profile): bool {
        return isset(self::RANKS[$profile]);
    }

    /**
     * Rank of a profile; 0 for an unknown value, so that it never passes a comparison.
     *
     * @param string $profile Profile name.
     * @return int
     */
    public static function rank(string $profile): int {
        return self::RANKS[$profile] ?? 0;
    }

    /**
     * Whether a profile reaches a required minimum.
     *
     * @param string $profile Profile held.
     * @param string $minimum Profile required.
     * @return bool
     */
    public static function at_least(string $profile, string $minimum): bool {
        return self::rank($profile) > 0 && self::rank($minimum) > 0 && self::rank($profile) >= self::rank($minimum);
    }

    /**
     * Name of the site setting that allows a profile.
     *
     * @param string $profile Profile name.
     * @return string
     */
    public static function setting(string $profile): string {
        return 'allow' . $profile . 'profile';
    }
}
