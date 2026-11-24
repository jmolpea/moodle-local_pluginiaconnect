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
 * Token endpoint: authorization code exchange and refresh. Nothing else.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_endpoint {
    /** @var int Failed client authentications allowed per minute for one client identifier from one address. */
    public const CLIENT_LIMIT = 20;
    /** @var int Attempts allowed per minute from one address. */
    public const IP_LIMIT = 120;

    /**
     * Handles a token request.
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
        // Before authentication only the caller's own address is limited. Failed client authentications
        // are then counted per client and address, so nobody can lock a client out by naming it in junk
        // requests. A grant that is refused (invalid_grant) is not counted: codes and refresh tokens carry
        // 256 random bits, so nothing can be guessed, and in gateway mode every user of the site shares one
        // client and one address, so counting them would let any third party who feeds junk codes through
        // the gateway stop the token exchange of the whole site.
        $failures = self::failure_key($request, $form);
        if (
            !rate_limiter::hit('token_ip', $request->ip(), self::IP_LIMIT, MINSECS, true)
            || rate_limiter::current('token_fail', $failures, MINSECS) >= self::CLIENT_LIMIT
        ) {
            return response::oauth_error(429, 'temporarily_unavailable', 'Too many requests. Try again in a minute.', [
                'Retry-After' => (string) rate_limiter::retry_after(MINSECS),
            ]);
        }

        token_service::sweep_grace_data();

        try {
            $client = client_authenticator::authenticate($request, $form);
            $granttype = (string) ($form['grant_type'] ?? '');
            if ($granttype === 'authorization_code') {
                foreach (['code', 'redirect_uri', 'code_verifier', 'resource'] as $name) {
                    if (!isset($form[$name]) || $form[$name] === '') {
                        throw new oauth_exception('invalid_request', "The '{$name}' parameter is required.");
                    }
                }
                $body = code_service::exchange(
                    $form['code'],
                    $client,
                    $form['redirect_uri'],
                    $form['resource'],
                    $form['code_verifier'],
                    isset($form['pc_actor_id']) ? (string) $form['pc_actor_id'] : null
                );
            } else if ($granttype === 'refresh_token') {
                if (!isset($form['refresh_token']) || $form['refresh_token'] === '') {
                    throw new oauth_exception('invalid_request', "The 'refresh_token' parameter is required.");
                }
                $resource = isset($form['resource']) && $form['resource'] !== '' ? $form['resource'] : null;
                $body = token_service::refresh($form['refresh_token'], $client, $resource);
            } else {
                throw new oauth_exception(
                    'unsupported_grant_type',
                    'Only the authorization_code and refresh_token grant types are supported.'
                );
            }
        } catch (oauth_exception $e) {
            if ($e->error === 'invalid_client') {
                rate_limiter::hit('token_fail', $failures, self::CLIENT_LIMIT, MINSECS, true);
            }
            return response::oauth_error($e->status, $e->error, $e->description, $e->headers);
        }
        return response::json(200, $body);
    }

    /**
     * Key of the failed-attempt counter of a request: the claimed client and the caller's address.
     *
     * @param request $request Request.
     * @param array $form Decoded form body.
     * @return string
     */
    public static function failure_key(request $request, array $form): string {
        return hash('sha256', client_authenticator::claimed_id($request, $form) . '|' . $request->ip());
    }
}
