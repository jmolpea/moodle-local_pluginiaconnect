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

use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\response;

/**
 * Token revocation (RFC 7009). Revoking any token of a connection ends the whole connection.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class revoke_endpoint {
    /**
     * Handles a revocation request.
     *
     * @param request $request Request.
     * @return response
     */
    public static function handle(request $request): response {
        if ($request->method() !== 'POST') {
            return response::oauth_error(405, 'invalid_request', 'Use POST.', ['Allow' => 'POST']);
        }
        if ($request->content_type() !== 'application/x-www-form-urlencoded') {
            return response::oauth_error(415, 'invalid_request', 'Use the application/x-www-form-urlencoded content type.');
        }
        $form = $request->form();
        $failures = token_endpoint::failure_key($request, $form);
        if (
            !rate_limiter::hit('token_ip', $request->ip(), token_endpoint::IP_LIMIT, MINSECS, true)
            || rate_limiter::current('token_fail', $failures, MINSECS) >= token_endpoint::CLIENT_LIMIT
        ) {
            return response::oauth_error(429, 'temporarily_unavailable', 'Too many requests. Try again in a minute.', [
                'Retry-After' => (string) rate_limiter::retry_after(MINSECS),
            ]);
        }
        try {
            $client = client_authenticator::authenticate($request, $form);
        } catch (oauth_exception $e) {
            if ($e->error === 'invalid_client') {
                rate_limiter::hit('token_fail', $failures, token_endpoint::CLIENT_LIMIT, MINSECS, true);
            }
            return response::oauth_error($e->status, $e->error, $e->description, $e->headers);
        }
        if (!isset($form['token']) || $form['token'] === '') {
            return response::oauth_error(400, 'invalid_request', "The 'token' parameter is required.");
        }
        // The answer is the same whether or not the token existed, as the RFC requires.
        token_service::revoke_by_token($form['token'], $client);
        return response::no_content(200);
    }
}
