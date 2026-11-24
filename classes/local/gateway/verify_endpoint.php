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

use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\response;

/**
 * Domain verification endpoint (gateway/verify.php).
 *
 * It answers a challenge of the gateway only while a registration started by an
 * administrator is in progress. Outside that window, and for anything that is
 * not a well formed challenge, it answers 404 and reveals nothing.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class verify_endpoint {
    /** @var int Requests allowed per address and minute. */
    public const IP_LIMIT = 10;

    /**
     * Handles one request.
     *
     * @param request $request Request.
     * @return response
     */
    public static function handle(request $request): response {
        if ($request->method() !== 'GET') {
            return response::json(405, [
                'error' => 'invalid_request',
                'error_description' => 'Use GET.',
            ], ['Allow' => 'GET']);
        }
        if (!rate_limiter::hit('gatewayverify', $request->ip(), self::IP_LIMIT, MINSECS, true)) {
            return response::json(429, [
                'error' => 'temporarily_unavailable',
                'error_description' => 'Too many requests. Try again in a minute.',
            ], ['Retry-After' => (string) rate_limiter::retry_after(MINSECS)]);
        }
        $answer = site_identity::answer((string) $request->query('challenge'));
        if ($answer === null) {
            return response::json(404, ['error' => 'not_found', 'error_description' => 'Nothing to verify.']);
        }
        return response::json(200, ['response' => $answer]);
    }
}
