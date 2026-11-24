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

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\response;
use local_pluginiaconnect\local\policy\site_policy;

/**
 * Dynamic client registration (RFC 7591). Open, so it is rate limited by address
 * and only registers clients whose redirect URIs the site already allows.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class register_endpoint {
    /** @var int Registrations allowed per hour from one address. */
    public const IP_LIMIT = 10;
    /** @var int Maximum number of redirect URIs of one client. */
    public const MAX_REDIRECTS = 10;

    /**
     * Handles a registration request.
     *
     * @param request $request Request.
     * @return response
     */
    public static function handle(request $request): response {
        if (!config::bool('allowdcr') || !site_policy::mode_active_for(client::TYPE_DCR)) {
            return response::oauth_error(404, 'not_found', 'Client registration is not available on this site.');
        }
        if ($request->method() !== 'POST') {
            return response::oauth_error(405, 'invalid_request', 'Use POST.', ['Allow' => 'POST']);
        }
        if ($request->content_type() !== 'application/json') {
            return response::oauth_error(415, 'invalid_request', 'Use the application/json content type.');
        }
        if (!rate_limiter::hit('register_ip', $request->ip(), self::IP_LIMIT, HOURSECS, true)) {
            return response::oauth_error(429, 'temporarily_unavailable', 'Too many registrations from this address.', [
                'Retry-After' => (string) rate_limiter::retry_after(HOURSECS),
            ]);
        }
        $data = $request->json();
        if (!is_array($data) || array_is_list($data)) {
            return response::oauth_error(400, 'invalid_client_metadata', 'The body must be a JSON object.');
        }

        $uris = $data['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > self::MAX_REDIRECTS) {
            return response::oauth_error(400, 'invalid_redirect_uri', 'Between 1 and 10 redirect URIs are required.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !redirect_policy::is_registrable($uri)) {
                return response::oauth_error(400, 'invalid_redirect_uri', 'A redirect URI is not accepted by this site.');
            }
        }
        $uris = array_values(array_unique($uris));

        $method = $data['token_endpoint_auth_method'] ?? client::AUTH_BASIC;
        if (!in_array($method, [client::AUTH_NONE, client::AUTH_BASIC, client::AUTH_POST], true)) {
            return response::oauth_error(
                400,
                'invalid_client_metadata',
                'The token endpoint authentication method is not supported.'
            );
        }
        $granttypes = $data['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (
            !is_array($granttypes) || !in_array('authorization_code', $granttypes, true)
            || array_diff($granttypes, ['authorization_code', 'refresh_token'])
        ) {
            return response::oauth_error(
                400,
                'invalid_client_metadata',
                'Only authorization_code and refresh_token are supported.'
            );
        }
        $responsetypes = $data['response_types'] ?? ['code'];
        if (!is_array($responsetypes) || $responsetypes !== ['code']) {
            return response::oauth_error(400, 'invalid_client_metadata', 'Only the code response type is supported.');
        }
        $name = isset($data['client_name']) && is_string($data['client_name'])
            ? trim(clean_param($data['client_name'], PARAM_TEXT)) : '';
        $name = $name === '' ? 'Unnamed application' : \core_text::substr($name, 0, 255);

        $granttypes = array_values(array_unique($granttypes));
        [$client, $secret] = client_repository::register_dcr($name, $uris, $method, [
            'client_name' => $name,
            'token_endpoint_auth_method' => $method,
            'grant_types' => $granttypes,
            'application_type' => isset($data['application_type']) && in_array($data['application_type'], ['web', 'native'], true)
                ? $data['application_type'] : null,
        ]);

        $body = [
            'client_id' => $client->clientid,
            'client_id_issued_at' => time(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirecturis,
            'token_endpoint_auth_method' => $client->authmethod,
            'grant_types' => $granttypes,
            'response_types' => ['code'],
        ];
        if ($secret !== null) {
            $body['client_secret'] = $secret;
            $body['client_secret_expires_at'] = 0;
        }
        return response::json(201, $body);
    }
}
