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

namespace local_pluginiaconnect\local\mcp;

use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\response;
use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\urls;
use local_pluginiaconnect\local\user_session;

/**
 * The MCP server: stateless Streamable HTTP, one JSON-RPC message per POST, JSON responses only.
 *
 * Every request authenticates by itself with a bearer token issued by this
 * plugin for this endpoint. Moodle web service tokens are never accepted.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class server {
    /** @var int Requests one address may send per minute, whatever their kind. */
    public const IP_LIMIT = 600;

    /**
     * Handles one HTTP request to the MCP endpoint.
     *
     * @param request $request Incoming request.
     * @param callable|null $authenticator For tests: function(string $token, ?string $ip): ?grant_context.
     * @param string|null $requestid Request identifier; generated when null.
     * @return response
     */
    public static function handle(request $request, ?callable $authenticator = null, ?string $requestid = null): response {
        if ($request->method() !== 'POST') {
            return response::json(405, [
                'error' => 'method_not_allowed',
                'error_description' => 'This endpoint only accepts POST requests.',
            ], ['Allow' => 'POST']);
        }
        if (!site_policy::is_enabled()) {
            return response::json(503, [
                'error' => 'site_unavailable',
                'error_description' => 'The connector is switched off on this site.',
            ]);
        }
        if ($request->body_too_large()) {
            return response::json(413, [
                'error' => 'payload_too_large',
                'error_description' => 'The request body is larger than 256 KB.',
            ]);
        }

        // A generous limit per address, before authentication, against floods of any kind of request.
        // Counted under a lock: requests sent at once are counted one by one.
        if (!rate_limiter::hit('mcp_ip', $request->ip(), self::IP_LIMIT, MINSECS, true)) {
            return response::json(429, [
                'error' => 'rate_limited',
                'error_description' => 'Too many requests from this address. Try again in a minute.',
            ], ['Retry-After' => (string) rate_limiter::retry_after(MINSECS)]);
        }

        // Authentication comes before anything is read from the body.
        $token = $request->bearer_token();
        if ($token === null) {
            return self::unauthorized(false);
        }
        $grant = self::authenticate($token, $request->ip(), $authenticator);
        if ($grant === null || !urls::is_own_resource($grant->resource)) {
            return self::unauthorized(true);
        }
        $user = user_session::load($grant);
        if ($user === null) {
            return self::unauthorized(true);
        }

        if ($request->content_type() !== 'application/json') {
            return jsonrpc::error(null, jsonrpc::INVALID_REQUEST, 'The request body must be application/json.', 415);
        }
        $message = $request->json();
        if ($message === null) {
            return jsonrpc::error(null, jsonrpc::PARSE_ERROR, 'The request body is not valid UTF-8 JSON.', 400);
        }
        if (!is_array($message) || array_is_list($message)) {
            return jsonrpc::error(
                null,
                jsonrpc::INVALID_REQUEST,
                'A single JSON-RPC request object is expected. Batches are not supported.',
                400
            );
        }
        $hasid = array_key_exists('id', $message);
        $id = $hasid ? $message['id'] : null;
        if (
            ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)
            || ($hasid && !jsonrpc::is_valid_id($id))
        ) {
            return jsonrpc::error(null, jsonrpc::INVALID_REQUEST, 'Not a valid JSON-RPC 2.0 request.', 400);
        }
        $method = $message['method'];
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return jsonrpc::error($id, jsonrpc::INVALID_PARAMS, "'params' must be an object.", 400);
        }
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $requestid = $requestid ?? \core\uuid::generate();

        if (array_key_exists(protocol::META_VERSION, $meta)) {
            return self::modern($request, $grant, $user, $method, $params, $meta, $hasid, $id, $requestid);
        }
        return self::legacy($request, $grant, $user, $method, $params, $hasid, $id, $requestid);
    }

    /**
     * Legacy era: revisions that open with "initialize".
     *
     * @param request $request Incoming request.
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user Current user.
     * @param string $method JSON-RPC method.
     * @param array $params Parameters.
     * @param bool $hasid Whether the message has an id (it is a request, not a notification).
     * @param mixed $id Request id.
     * @param string $requestid Request identifier.
     * @return response
     */
    private static function legacy(
        request $request,
        grant_context $grant,
        \stdClass $user,
        string $method,
        array $params,
        bool $hasid,
        $id,
        string $requestid
    ): response {
        if ($method !== 'initialize') {
            $version = $request->header('mcp-protocol-version');
            if ($version === null || !in_array($version, protocol::LEGACY, true)) {
                return jsonrpc::error(
                    $id,
                    $version === protocol::MODERN ? jsonrpc::INVALID_PARAMS : jsonrpc::INVALID_REQUEST,
                    'Missing or unsupported MCP-Protocol-Version. Supported versions: '
                        . implode(', ', protocol::supported()) . '.',
                    400,
                    ['supported' => protocol::supported(), 'requested' => (string) $version]
                );
            }
        }
        if (!$hasid) {
            // Notifications ("notifications/initialized" and any other) are accepted and ignored.
            return response::no_content(202);
        }
        switch ($method) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? null;
                return jsonrpc::result($id, [
                    'protocolVersion' => in_array($requested, protocol::LEGACY, true) ? $requested : protocol::LEGACY[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => protocol::server_info(),
                ]);
            case 'ping':
                return jsonrpc::result($id, new \stdClass());
            case 'tools/list':
                return jsonrpc::result($id, ['tools' => self::tools($grant)]);
            case 'tools/call':
                return self::call($request, $grant, $user, $params, $id, $requestid, false);
            default:
                return jsonrpc::error($id, jsonrpc::METHOD_NOT_FOUND, 'Method not found.');
        }
    }

    /**
     * Modern era: revision 2026-07-28, every request self-described in "_meta" and in headers.
     *
     * @param request $request Incoming request.
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user Current user.
     * @param string $method JSON-RPC method.
     * @param array $params Parameters.
     * @param array $meta The "_meta" member of the parameters.
     * @param bool $hasid Whether the message has an id.
     * @param mixed $id Request id.
     * @param string $requestid Request identifier.
     * @return response
     */
    private static function modern(
        request $request,
        grant_context $grant,
        \stdClass $user,
        string $method,
        array $params,
        array $meta,
        bool $hasid,
        $id,
        string $requestid
    ): response {
        $version = $meta[protocol::META_VERSION];
        if ($version !== protocol::MODERN) {
            return jsonrpc::error($id, jsonrpc::UNSUPPORTED_VERSION, 'Unsupported protocol version', 400, [
                'supported' => protocol::supported(),
                'requested' => is_string($version) ? substr($version, 0, 40) : '',
            ]);
        }
        if ($request->header('mcp-protocol-version') !== $version) {
            return jsonrpc::error(
                $id,
                jsonrpc::HEADER_MISMATCH,
                'Header mismatch: MCP-Protocol-Version is missing or does not match the body.',
                400
            );
        }
        $headermethod = $request->header('mcp-method');
        if ($headermethod === null || protocol::decode_header($headermethod) !== $method) {
            return jsonrpc::error(
                $id,
                jsonrpc::HEADER_MISMATCH,
                'Header mismatch: Mcp-Method is missing or does not match the body.',
                400
            );
        }
        $capabilities = $meta[protocol::META_CAPABILITIES] ?? null;
        if (!is_array($capabilities) || ($capabilities !== [] && array_is_list($capabilities))) {
            return jsonrpc::error($id, jsonrpc::INVALID_PARAMS, 'Missing required _meta field: client capabilities.', 400);
        }
        if (!$hasid) {
            return jsonrpc::error(null, jsonrpc::INVALID_REQUEST, 'Notifications are not accepted over HTTP.', 400);
        }
        $servermeta = [protocol::META_SERVERINFO => protocol::server_info()];
        switch ($method) {
            case 'server/discover':
                return jsonrpc::result($id, [
                    'resultType' => 'complete',
                    'supportedVersions' => protocol::supported(),
                    'capabilities' => ['tools' => new \stdClass()],
                    'ttlMs' => protocol::DISCOVER_TTL_MS,
                    'cacheScope' => 'private',
                    '_meta' => $servermeta,
                ]);
            case 'tools/list':
                return jsonrpc::result($id, [
                    'resultType' => 'complete',
                    'tools' => self::tools($grant),
                    'ttlMs' => protocol::TOOLS_TTL_MS,
                    'cacheScope' => 'private',
                    '_meta' => $servermeta,
                ]);
            case 'tools/call':
                $headername = $request->header('mcp-name');
                $name = $params['name'] ?? null;
                if (!is_string($name) || $headername === null || protocol::decode_header($headername) !== $name) {
                    return jsonrpc::error(
                        $id,
                        jsonrpc::HEADER_MISMATCH,
                        'Header mismatch: Mcp-Name is missing or does not match the body.',
                        400
                    );
                }
                return self::call($request, $grant, $user, $params, $id, $requestid, true);
            default:
                return jsonrpc::error($id, jsonrpc::METHOD_NOT_FOUND, 'Method not found.', 404);
        }
    }

    /**
     * tools/call in either era.
     *
     * @param request $request Incoming request.
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user Current user.
     * @param array $params Parameters.
     * @param mixed $id Request id.
     * @param string $requestid Request identifier.
     * @param bool $modern Whether the request belongs to revision 2026-07-28.
     * @return response
     */
    private static function call(
        request $request,
        grant_context $grant,
        \stdClass $user,
        array $params,
        $id,
        string $requestid,
        bool $modern
    ): response {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return jsonrpc::error($id, jsonrpc::INVALID_PARAMS, "'name' is required.");
        }
        $outcome = call_pipeline::run($grant, $user, $name, $params['arguments'] ?? [], $requestid, $request->ip());
        if ($outcome->unknowntool) {
            return jsonrpc::error($id, jsonrpc::INVALID_PARAMS, 'Unknown tool.');
        }
        $result = result_envelope::call_result($outcome->envelope, $outcome->iserror);
        if ($modern) {
            $result = ['resultType' => 'complete'] + $result
                + ['_meta' => [protocol::META_SERVERINFO => protocol::server_info()]];
        }
        if ($outcome->missingscope !== null) {
            // Scope step-up: the HTTP challenge is what Claude reads, the result metadata is what ChatGPT reads.
            $challenge = 'Bearer error="insufficient_scope", scope="' . $outcome->missingscope . '", resource_metadata="'
                . urls::resource_metadata() . '", error_description="This action needs the ' . $outcome->missingscope
                . ' scope."';
            $result['_meta'] = ($result['_meta'] ?? []) + ['mcp/www_authenticate' => $challenge];
            return jsonrpc::result($id, $result, 403, ['WWW-Authenticate' => $challenge]);
        }
        return jsonrpc::result($id, $result);
    }

    /**
     * Tool definitions available to a grant: catalogue ∩ profile ∩ scopes ∩ site policy.
     *
     * @param grant_context $grant Grant behind the token.
     * @return array
     */
    private static function tools(grant_context $grant): array {
        if (!site_policy::mode_active_for($grant->clienttype)) {
            return [];
        }
        $tools = [];
        foreach (site_policy::usable_tools($grant->profile, $grant->scopes, $grant->actorid) as $class) {
            $tools[] = tool_registry::describe($class);
        }
        return $tools;
    }

    /**
     * Resolves a bearer token to a grant.
     *
     * @param string $token Bearer token.
     * @param string|null $ip Remote address.
     * @param callable|null $authenticator Replacement used by tests.
     * @return grant_context|null
     */
    private static function authenticate(string $token, ?string $ip, ?callable $authenticator): ?grant_context {
        try {
            if ($authenticator !== null) {
                return $authenticator($token, $ip);
            }
            return \local_pluginiaconnect\local\oauth\token_service::authenticate($token, $ip);
        } catch (\Throwable $e) {
            // Any failure while checking a token means no access.
            return null;
        }
    }

    /**
     * 401 with the OAuth challenge that points to the protected resource metadata.
     *
     * @param bool $tokensent Whether a token was sent and rejected.
     * @return response
     */
    private static function unauthorized(bool $tokensent): response {
        $challenge = 'Bearer resource_metadata="' . urls::resource_metadata() . '", scope="'
            . implode(' ', scope::BASELINE) . '"';
        if ($tokensent) {
            $challenge .= ', error="invalid_token"';
        }
        return response::json(401, [
            'error' => $tokensent ? 'invalid_token' : 'unauthorized',
            'error_description' => $tokensent
                ? 'The access token is invalid, expired or revoked.'
                : 'Authorization is required.',
        ], ['WWW-Authenticate' => $challenge]);
    }
}
