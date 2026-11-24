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

/**
 * Proof Key for Code Exchange (RFC 7636). Only the S256 method exists here.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pkce {
    /** @var string The only accepted challenge method. */
    public const METHOD = 'S256';

    /**
     * Whether a value has the shape of an S256 code challenge: base64url of 32 bytes.
     *
     * @param string $challenge Value received.
     * @return bool
     */
    public static function is_valid_challenge(string $challenge): bool {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1;
    }

    /**
     * Whether a value has the shape of a code verifier.
     *
     * @param string $verifier Value received.
     * @return bool
     */
    public static function is_valid_verifier(string $verifier): bool {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) === 1;
    }

    /**
     * Challenge that corresponds to a verifier.
     *
     * @param string $verifier Code verifier.
     * @return string
     */
    public static function challenge(string $verifier): string {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Whether a verifier matches the stored challenge.
     *
     * @param string $verifier Code verifier received at the token endpoint.
     * @param string $challenge Code challenge stored with the authorization code.
     * @return bool
     */
    public static function verify(string $verifier, string $challenge): bool {
        if (!self::is_valid_verifier($verifier) || !self::is_valid_challenge($challenge)) {
            return false;
        }
        return hash_equals($challenge, self::challenge($verifier));
    }
}
