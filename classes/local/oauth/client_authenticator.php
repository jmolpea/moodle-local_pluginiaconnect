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

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\policy\site_policy;

/**
 * Client authentication at the token and revocation endpoints.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client_authenticator {
    /**
     * Client identifier a request claims, before any verification. Used to key rate limits.
     *
     * @param request $request Request.
     * @param array $form Decoded form body.
     * @return string Empty when none was sent.
     */
    public static function claimed_id(request $request, array $form): string {
        $basic = $request->basic_credentials();
        return $basic !== null ? $basic[0] : (string) ($form['client_id'] ?? '');
    }

    /**
     * Identifies and authenticates the client of a request.
     *
     * A public client sends only its identifier and must not send a secret. A
     * confidential client must use the method it registered. Metadata document
     * clients are resolved from the stored copy, never from the network.
     *
     * @param request $request Request.
     * @param array $form Decoded form body.
     * @return client
     * @throws oauth_exception invalid_client (401) or invalid_request.
     */
    public static function authenticate(request $request, array $form): client {
        $basic = $request->basic_credentials();
        $postsecret = isset($form['client_secret']) && $form['client_secret'] !== '' ? (string) $form['client_secret'] : null;
        if ($basic !== null && $postsecret !== null) {
            throw new oauth_exception('invalid_request', 'Use only one client authentication method.');
        }
        $failure = new oauth_exception(
            'invalid_client',
            'Client authentication failed.',
            401,
            $basic !== null ? ['WWW-Authenticate' => 'Basic realm="token"'] : []
        );

        $clientid = $basic !== null ? $basic[0] : (string) ($form['client_id'] ?? '');
        if ($basic !== null && isset($form['client_id']) && !hash_equals($basic[0], (string) $form['client_id'])) {
            throw $failure;
        }
        $client = $clientid === '' ? null : client_repository::find($clientid);
        if (
            !$client || !$client->active || !site_policy::mode_active_for($client->type)
            || !client_repository::is_still_accepted($client)
        ) {
            throw $failure;
        }

        if (!$client->is_confidential()) {
            if ($basic !== null || $postsecret !== null) {
                throw $failure;
            }
            return $client;
        }

        $secret = null;
        if ($client->authmethod === client::AUTH_BASIC && $basic !== null) {
            $secret = $basic[1];
        } else if ($client->authmethod === client::AUTH_POST && $basic === null) {
            $secret = $postsecret;
        }
        if ($secret === null || $secret === '' || $client->secrethash === null || !password_verify($secret, $client->secrethash)) {
            throw $failure;
        }
        return $client;
    }
}
