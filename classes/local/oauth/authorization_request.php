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

use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\urls;

/**
 * A validated authorization request.
 *
 * Validation order matters. Client and redirect URI come first: if either is
 * wrong the user is shown an error page and is never redirected. Every later
 * error is reported by redirecting to the validated redirect URI.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class authorization_request {
    /** @var string[] Assistant identifiers the gateway may declare. */
    public const ACTOR_IDS = ['claude', 'chatgpt', 'copilot-cowork'];
    /** @var string Identifier of Microsoft 365 Copilot Cowork. */
    public const ACTOR_COWORK = 'copilot-cowork';
    /** @var int Maximum length of the state parameter. */
    public const MAX_STATE = 2000;
    /** @var string[] Names of the request parameters. */
    public const PARAMS = [
        'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method',
        'resource', 'scope', 'state', 'pc_actor', 'pc_actor_id',
    ];

    /** @var client Client that asks for access. */
    public readonly client $client;
    /** @var string Redirect URI, exactly as received. */
    public readonly string $redirecturi;
    /** @var string State, returned unmodified. */
    public readonly string $state;
    /** @var string PKCE code challenge (S256). */
    public readonly string $codechallenge;
    /** @var string[]|null Requested scopes; null when the request had no scope parameter. */
    public readonly ?array $scopes;
    /** @var string|null Assistant name declared by the gateway. Display only. */
    public readonly ?string $actor;
    /** @var string|null Closed assistant identifier declared by the gateway. */
    public readonly ?string $actorid;

    /**
     * Constructor.
     *
     * @param client $client Client.
     * @param string $redirecturi Redirect URI.
     * @param string $state State.
     * @param string $codechallenge Code challenge.
     * @param string[]|null $scopes Requested scopes.
     * @param string|null $actor Assistant name.
     * @param string|null $actorid Assistant identifier.
     */
    private function __construct(
        client $client,
        string $redirecturi,
        string $state,
        string $codechallenge,
        ?array $scopes,
        ?string $actor,
        ?string $actorid
    ) {
        $this->client = $client;
        $this->redirecturi = $redirecturi;
        $this->state = $state;
        $this->codechallenge = $codechallenge;
        $this->scopes = $scopes;
        $this->actor = $actor;
        $this->actorid = $actorid;
    }

    /**
     * Validates the parameters of an authorization request.
     *
     * @param array $params Parameter name => string value; absent parameters are missing or null.
     * @param string|null $ip Address of the caller, to limit the metadata downloads it can cause.
     * @return self
     * @throws oauth_exception Redirectable or not, see {@see oauth_exception::is_redirectable()}.
     */
    public static function from_params(array $params, ?string $ip = null): self {
        $get = static fn(string $name): ?string => isset($params[$name]) && is_string($params[$name]) ? $params[$name] : null;

        // 1. Client and redirect URI. Failures here are never redirected.
        $clientid = $get('client_id');
        $client = $clientid === null ? null : client_repository::find($clientid, true, $ip);
        if (
            !$client || !$client->active || !site_policy::mode_active_for($client->type)
            || !client_repository::is_still_accepted($client)
        ) {
            throw new oauth_exception('invalid_client', 'This application is not accepted by this site.', 400);
        }
        $redirecturi = $get('redirect_uri');
        if ($redirecturi === null || !redirect_policy::is_allowed($redirecturi, $client)) {
            throw new oauth_exception('invalid_request', 'The return address of this application is not accepted by this site.');
        }

        // 2. Everything else is reported to the client through the redirect URI.
        $state = $get('state') ?? '';
        if (strlen($state) > self::MAX_STATE) {
            throw (new oauth_exception('invalid_request', 'The state parameter is too long.'))->via_redirect($redirecturi, '');
        }
        $fail = static fn(string $error, string $description): oauth_exception =>
            (new oauth_exception($error, $description))->via_redirect($redirecturi, $state);

        if ($get('response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Only the authorization code flow is supported.');
        }
        $challenge = $get('code_challenge');
        if ($challenge === null || $get('code_challenge_method') !== pkce::METHOD || !pkce::is_valid_challenge($challenge)) {
            throw $fail('invalid_request', 'PKCE with the S256 method is required.');
        }

        // 3. The resource must be exactly this site's MCP endpoint.
        $resource = $get('resource');
        if ($resource === null || !urls::is_own_resource($resource)) {
            throw $fail('invalid_target', 'The resource parameter must be the MCP endpoint of this site.');
        }

        $scopes = null;
        $scopeparam = $get('scope');
        if ($scopeparam !== null && trim($scopeparam) !== '') {
            $scopes = scope::parse($scopeparam);
            if (!$scopes) {
                throw $fail('invalid_scope', 'None of the requested scopes is known to this site.');
            }
        }

        // The assistant is declared by the gateway only; other clients cannot set it.
        $actor = null;
        $actorid = null;
        if ($client->type === client::TYPE_GATEWAY) {
            $declared = $get('pc_actor');
            if ($declared !== null) {
                $declared = trim(clean_param($declared, PARAM_TEXT));
                $actor = $declared === '' ? null : \core_text::substr($declared, 0, 255);
            }
            $declaredid = $get('pc_actor_id');
            if ($declaredid !== null && in_array($declaredid, self::ACTOR_IDS, true)) {
                $actorid = $declaredid;
            }
        }

        return new self($client, $redirecturi, $state, $challenge, $scopes, $actor, $actorid);
    }

    /**
     * URL that returns an authorization code to the client.
     *
     * @param string $code Authorization code.
     * @return string
     */
    public function success_url(string $code): string {
        return self::redirect_url($this->redirecturi, ['code' => $code, 'state' => $this->state]);
    }

    /**
     * URL that returns an error to the client.
     *
     * @param string $error OAuth error code.
     * @param string $description Description.
     * @return string
     */
    public function error_url(string $error, string $description): string {
        return self::redirect_url($this->redirecturi, [
            'error' => $error,
            'error_description' => $description,
            'state' => $this->state,
        ]);
    }

    /**
     * URL for a redirectable error raised during validation.
     *
     * @param oauth_exception $e Redirectable exception.
     * @return string
     */
    public static function exception_url(oauth_exception $e): string {
        return self::redirect_url((string) $e->redirecturi, [
            'error' => $e->error,
            'error_description' => $e->description,
            'state' => $e->state,
        ]);
    }

    /**
     * Builds an authorization response URL. The issuer identifier is always added (RFC 9207).
     *
     * @param string $redirecturi Validated redirect URI.
     * @param array $params Response parameters.
     * @return string
     */
    private static function redirect_url(string $redirecturi, array $params): string {
        return redirect_policy::build($redirecturi, $params + ['iss' => urls::issuer()]);
    }
}
