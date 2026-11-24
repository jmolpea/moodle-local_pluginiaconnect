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

namespace local_pluginiaconnect\local\http;

use local_pluginiaconnect\local\config;

/**
 * Loop-back probe used by the diagnostics page.
 *
 * The diagnostics page asks the site to call itself to learn two things that
 * only a real HTTP request can show: whether the web server passes the
 * Authorization header to PHP and whether path arguments after a script name
 * (PATH_INFO) work. The probe answers with two booleans and nothing else, and
 * only to a request that carries a one-time value created less than a minute
 * before by an administrator.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfcheck {
    /** @var string Header that carries the one-time value. */
    public const HEADER = 'X-Pluginia-Selfcheck';
    /** @var string Bearer value sent by the diagnostics page. It is not a credential. */
    public const BEARER = 'pc_selfcheck_probe';
    /** @var string Path argument sent by the diagnostics page. */
    public const PATH = '/probe/path';
    /** @var int Seconds the one-time value is valid. */
    public const TTL = 60;
    /** @var string Name of the config entry that holds the hash and the expiry. */
    private const CONFIG = 'selfcheck';

    /**
     * Creates the one-time value and stores its hash.
     *
     * @return string The value to send in the probe request.
     */
    public static function arm(): string {
        $value = bin2hex(random_bytes(24));
        set_config(self::CONFIG, hash('sha256', $value) . ':' . (time() + self::TTL), config::COMPONENT);
        return $value;
    }

    /**
     * Removes the one-time value.
     *
     * @return void
     */
    public static function disarm(): void {
        unset_config(self::CONFIG, config::COMPONENT);
    }

    /**
     * Answers a probe request.
     *
     * @param request $request Incoming request.
     * @param string $pathargument Path after the script name, as Moodle extracted it.
     * @return response
     */
    public static function handle(request $request, string $pathargument): response {
        $stored = (string) get_config(config::COMPONENT, self::CONFIG);
        $presented = (string) $request->header(self::HEADER);
        $parts = explode(':', $stored);
        $valid = count($parts) === 2 && $presented !== '' && (int) $parts[1] >= time()
            && hash_equals($parts[0], hash('sha256', $presented));
        if (!$valid) {
            // Indistinguishable from a missing script for anyone without the one-time value.
            return response::json(404, ['error' => 'not_found']);
        }
        self::disarm();
        return response::json(200, [
            'authorization' => $request->bearer_token() === self::BEARER,
            'pathinfo' => $pathargument === self::PATH,
        ]);
    }
}
